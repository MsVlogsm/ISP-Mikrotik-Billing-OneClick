<?php

namespace App\Services\Olt;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\OnuMacLedger;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerOnuRealtimeSyncService
{
    private const LIVE_IDENTITY_SNAPSHOT_TTL_SECONDS = 300;

    /** @var array<int, array{captured_at: Carbon, onus: array<int, array>}> */
    private array $liveIdentitySnapshots = [];

    public function __construct(
        private readonly OltReadOnlyAdapterManager $adapterManager,
        private readonly VsolSshOpmCollector $vsolOpmCollector,
        private readonly MikrotikController $mikrotik,
    ) {}

    /** Refresh only one customer's live PPP identity and matching ONU telemetry. */
    public function sync(CustomersInfo $customer): array
    {
        $customer->loadMissing('pppUser');
        /** @var PPPSecrets|null $ppp */
        $ppp = $customer->pppUser;

        if (! $ppp || blank($ppp->username) || blank($ppp->router_name)) {
            throw new RuntimeException('এই customer-এর সঙ্গে active PPPoE account linked নেই।');
        }

        $routerName = trim((string) $ppp->router_name);
        $router = RouterList::query()
            ->where('router_name', $routerName)
            ->where('action', 'connected')
            ->first();

        if (! $router) {
            throw new RuntimeException("MikroTik router '{$routerName}' connected নয়; ONU sync করা যায়নি।");
        }

        $username = trim((string) $ppp->username);
        $quotedUsername = $this->mikrotik->mtQuote($username);

        $activeRows = $this->mikrotik->singleRead(
            $routerName,
            '/ppp/active/print',
            '/ppp active print where name='.$quotedUsername.' without-paging',
            ['name' => $username],
            false,
            true,
        );
        $secretRows = $this->mikrotik->singleRead(
            $routerName,
            '/ppp/secret/print',
            '/ppp secret print where name='.$quotedUsername.' without-paging',
            ['name' => $username],
            false,
            true,
        );

        $active = $this->findRouterRow($activeRows, $username);
        $secret = $this->findRouterRow($secretRows, $username);
        if (! $secret) {
            throw new RuntimeException('MikroTik-এ এই PPPoE secret পাওয়া যায়নি। আগে Router/PPPoE identity ঠিক করুন।');
        }

        $callerMac = $this->normalizeMac($active['caller-id'] ?? $active['caller_id'] ?? null);
        $identitySource = 'live_pppoe_session';
        if (! $callerMac) {
            $callerMac = $this->normalizeMac($secret['caller-id'] ?? $secret['caller_id'] ?? null)
                ?: $this->normalizeMac($ppp->last_caller_id);
            $identitySource = 'last_known_ppp_caller_id';
        }

        if (! $callerMac) {
            throw new RuntimeException('PPPoE session/secret থেকে client MAC পাওয়া যায়নি; exact ONU identity ছাড়া sync করা নিরাপদ নয়।');
        }

        // A stored ONU slot is only a hint. Even when its saved PPP link and
        // last_caller_id agree, it may be stale (a subscriber can move PON/ONU).
        // Always corroborate the current RouterOS caller MAC against the live
        // read-only OLT dynamic MAC table before accepting or moving a link.
        $matches = [];
        $oltErrors = [];
        $liveOnuRowsByOlt = [];
        $identityRevalidatedByLiveMac = false;
        $callerMacSeenOnOfflineOrUnknownOnu = false;

        $olts = NetworkInventoryDevice::query()
            ->where('type', 'olt')
            ->where('is_active', true)
            ->get();

        if ($olts->isEmpty()) {
            throw new RuntimeException('কোনো active OLT পাওয়া যায়নি।');
        }

        foreach ($olts as $olt) {
            try {
                $snapshot = $this->liveIdentitySnapshots[$olt->id] ?? null;
                $snapshotIsFresh = is_array($snapshot)
                    && ($snapshot['captured_at'] ?? null) instanceof Carbon
                    && now()->diffInSeconds($snapshot['captured_at']) <= self::LIVE_IDENTITY_SNAPSHOT_TTL_SECONDS
                    && is_array($snapshot['onus'] ?? null)
                    && $snapshot['onus'] !== [];

                if ($snapshotIsFresh) {
                    $onus = $snapshot['onus'];
                } else {
                    $source = $this->adapterManager->read($olt);
                    if (($source['ok'] ?? false) !== true
                        || ($source['meta']['read_only'] ?? false) !== true
                        || ($source['meta']['writes_performed'] ?? true) !== false
                        || ! in_array(($source['status'] ?? null), ['ok', 'partial'], true)) {
                        throw new RuntimeException($source['message'] ?? 'OLT did not return a complete verified read-only inventory.');
                    }

                    $onus = array_values(array_filter((array) ($source['onus'] ?? []), 'is_array'));
                    if ($onus === []) {
                        throw new RuntimeException('OLT returned no normalized ONU inventory.');
                    }

                    $snmpFdbReadable = ($source['meta']['client_mac_discovery_configured'] ?? false) === true
                        && ($source['meta']['client_mac_fdb_readable'] ?? false) === true;

                    if (strcasecmp((string) $olt->vendor, 'VSOL') === 0 && ! $snmpFdbReadable) {
                        // SNMP bridge-FDB is preferred when the device exposes it.
                        // SSH remains a read-only fallback on firmware/SNMP views
                        // where standard dot1d/dot1q FDB walks are unavailable.
                        $macResult = $this->vsolOpmCollector->collect($olt, $onus, null, null, true, false);
                        if (($macResult['meta']['read_only'] ?? false) !== true
                            || ($macResult['meta']['writes_performed'] ?? true) !== false
                            || ! is_array($macResult['onus'] ?? null)) {
                            throw new RuntimeException($macResult['message'] ?? 'VSOL read-only dynamic MAC table is unavailable.');
                        }
                        $onus = array_values(array_filter($macResult['onus'], 'is_array'));
                    }

                    if ($onus === []) {
                        throw new RuntimeException('OLT returned no normalized ONU rows after live MAC verification.');
                    }

                    // One short batch can verify multiple PPP accounts against the
                    // same read-only OLT FDB snapshot. RouterOS caller-id is still
                    // read fresh for each account; cache expires after five minutes.
                    $this->liveIdentitySnapshots[$olt->id] = [
                        'captured_at' => now(),
                        'onus' => $onus,
                    ];
                }

                $liveOnuRowsByOlt[$olt->id] = $onus;

                foreach ($onus as $onu) {
                    $clientMacs = collect((array) ($onu['client_macs'] ?? []))
                        ->map(fn ($mac) => $this->normalizeMac($mac))
                        ->filter()
                        ->unique();
                    if (! $clientMacs->contains($callerMac)) {
                        continue;
                    }

                    $pon = trim((string) ($onu['pon_port'] ?? $onu['pon'] ?? $onu['ponPort'] ?? ''));
                    $onuId = trim((string) ($onu['onu_id'] ?? $onu['onuId'] ?? $onu['id'] ?? ''));
                    if ($pon === '' || $onuId === '') {
                        continue;
                    }

                    if ($this->normalizeOnuStatus($onu['status'] ?? null) !== 'online') {
                        $callerMacSeenOnOfflineOrUnknownOnu = true;

                        continue;
                    }

                    $matches[$olt->id.'|'.$pon.'|'.$onuId] = [
                        'olt' => $olt,
                        'onu' => $onu,
                        'pon' => $pon,
                        'onu_id' => $onuId,
                    ];
                    $identityRevalidatedByLiveMac = true;
                }
            } catch (\Throwable $exception) {
                $oltErrors[] = $olt->name.': '.$exception->getMessage();
            }
        }

        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                throw new RuntimeException('একই PPPoE client MAC একাধিক ONU interface-এ পাওয়া গেছে; ভুল mapping এড়াতে কোনো পরিবর্তন করা হয়নি।');
            }

            $detail = $oltErrors !== [] ? ' OLT read error: '.implode(' | ', array_slice($oltErrors, 0, 2)) : '';
            if ($callerMacSeenOnOfflineOrUnknownOnu) {
                throw new RuntimeException('Live PPPoE MAC '.$callerMac.' MAC table-এ পাওয়া গেছে, কিন্তু সংশ্লিষ্ট ONU বর্তমানে Offline/Unknown; ভুল mapping এড়াতে কোনো পরিবর্তন করা হয়নি.'.$detail);
            }
            throw new RuntimeException('Live PPPoE MAC '.$callerMac.' কোনো OLT-এর Dynamic MAC table-এ পাওয়া যায়নি।'.$detail);
        }

        $match = array_values($matches)[0];
        $olt = $match['olt'];
        $onu = $match['onu'];
        $pon = $match['pon'];
        $onuId = $match['onu_id'];
        $normalizedOnuStatus = $this->normalizeOnuStatus($onu['status'] ?? null);

        $result = DB::transaction(function () use (
            $customer,
            $ppp,
            $callerMac,
            $identitySource,
            $active,
            $olt,
            $onu,
            $pon,
            $onuId,
            $normalizedOnuStatus,
            $identityRevalidatedByLiveMac,
            $liveOnuRowsByOlt
        ): array {
            $mappingRows = OltOnuCustomerMapping::query()
                ->where('olt_device_id', $olt->id)
                ->where('pon_port', $pon)
                ->where('onu_id', $onuId)
                ->lockForUpdate()
                ->get();

            if ($mappingRows->count() > 1) {
                throw new RuntimeException("OLT {$olt->name} PON {$pon} / ONU {$onuId}-এর একাধিক mapping row আছে; manual review প্রয়োজন।");
            }

            $targetMapping = $mappingRows->first();
            $activeLinks = OltOnuCustomerPppLink::query()
                ->where('ppp_user_id', $ppp->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            if ($activeLinks->count() > 1) {
                throw new RuntimeException('এই PPPoE account একাধিক active ONU link-এ আছে; manual review প্রয়োজন।');
            }

            $previousPrimaryMappings = OltOnuCustomerMapping::query()
                ->where('ppp_user_id', $ppp->id)
                ->when($targetMapping, fn ($query) => $query->where('id', '<>', $targetMapping->id))
                ->lockForUpdate()
                ->get();
            if ($previousPrimaryMappings->count() > 1) {
                throw new RuntimeException('এই PPPoE account-এর একাধিক legacy primary mapping আছে; manual review প্রয়োজন।');
            }

            $previousLink = $activeLinks->first();
            $previousLinkMapping = $previousLink && (! $targetMapping || (int) $previousLink->olt_onu_customer_mapping_id !== (int) $targetMapping->id)
                ? OltOnuCustomerMapping::query()->lockForUpdate()->find($previousLink->olt_onu_customer_mapping_id)
                : null;
            $previousPrimary = $previousPrimaryMappings->first();
            $previousMapping = $previousPrimary ?: $previousLinkMapping;

            if ($previousPrimary && $previousLinkMapping && (int) $previousPrimary->id !== (int) $previousLinkMapping->id) {
                throw new RuntimeException('Legacy primary mapping এবং active PPP link ভিন্ন ONU-তে আছে; manual review প্রয়োজন।');
            }
            $previousMappingOwnsThisPrimary = $previousPrimary !== null;
            if ($previousMapping) {
                if ((int) $previousMapping->olt_device_id !== (int) $olt->id
                    || $this->mappingHasUnresolvableIdentityConflict($previousMapping)) {
                    throw new RuntimeException('Previous ONU mapping has a conflicting physical identity or belongs to another OLT; it was not detached.');
                }

                $previousLiveRows = array_values(array_filter(
                    (array) ($liveOnuRowsByOlt[$previousMapping->olt_device_id] ?? []),
                    'is_array'
                ));
                $previousLiveOnu = collect($previousLiveRows)->first(fn (array $row): bool => trim((string) ($row['pon_port'] ?? $row['pon'] ?? $row['ponPort'] ?? '')) === (string) $previousMapping->pon_port
                    && trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? '')) === (string) $previousMapping->onu_id
                );
                $previousPhysicalIdentityMatches = is_array($previousLiveOnu)
                    && $this->mappingMatchesLivePhysicalIdentity($previousMapping, $previousLiveOnu);

                if ($this->mappingHasStableIdentityWarning($previousMapping) && ! $previousPhysicalIdentityMatches) {
                    throw new RuntimeException('Previous ONU mapping still has an unresolved stable-identity warning; live ONU MAC/Serial did not verify it, so no subscriber link was moved.');
                }

                if (is_array($previousLiveOnu)
                    && ! $previousPhysicalIdentityMatches
                    && ($this->normalizeMac($previousMapping->onu_mac) !== null || trim((string) $previousMapping->onu_serial) !== '')) {
                    throw new RuntimeException('Previous ONU slot now reports a different physical MAC/Serial; subscriber link was not detached automatically.');
                }

                if ($previousMappingOwnsThisPrimary) {
                    if (($previousMapping->customer_id && (int) $previousMapping->customer_id !== (int) $customer->id)
                        || ($previousMapping->ppp_user_id && (int) $previousMapping->ppp_user_id !== (int) $ppp->id)) {
                        throw new RuntimeException('Legacy primary mapping owner differs from the active PPP subscriber; no reassignment was made.');
                    }
                } elseif (
                    ($previousMapping->customer_id && (int) $previousMapping->customer_id === (int) $customer->id
                        && $previousMapping->ppp_user_id && (int) $previousMapping->ppp_user_id !== (int) $ppp->id)
                    || ($previousMapping->ppp_user_id && (int) $previousMapping->ppp_user_id === (int) $ppp->id
                        && $previousMapping->customer_id && (int) $previousMapping->customer_id !== (int) $customer->id)
                ) {
                    throw new RuntimeException('Previous ONU shared-link primary columns contain a split customer/PPPoE owner; manual review is required.');
                }
            }

            $allowedPreviousIds = $previousMappingOwnsThisPrimary
                ? [$previousMapping->id]
                : [];
            $mapping = $targetMapping ?: ($previousMappingOwnsThisPrimary ? $previousMapping : new OltOnuCustomerMapping([
                'olt_device_id' => $olt->id,
                'pon_port' => $pon,
                'onu_id' => $onuId,
            ]));
            $mappingId = $mapping->exists ? $mapping->id : null;

            if ($targetMapping && (
                $this->mappingHasUnresolvableIdentityConflict($targetMapping)
                || ($this->mappingHasStableIdentityWarning($targetMapping)
                    && ! $this->mappingMatchesLivePhysicalIdentity($targetMapping, $onu))
            )) {
                throw new RuntimeException('Target ONU has an unresolved duplicate-MAC or physical-identity conflict; mapping was not changed.');
            }

            $otherPrimary = OltOnuCustomerMapping::query()
                ->when($mappingId, fn ($query) => $query->where('id', '<>', $mappingId))
                ->when($allowedPreviousIds !== [], fn ($query) => $query->whereNotIn('id', $allowedPreviousIds))
                ->where(function ($query) use ($customer, $ppp): void {
                    $query->where('customer_id', $customer->id)
                        ->orWhere('ppp_user_id', $ppp->id);
                })
                ->exists();

            if ($otherPrimary) {
                throw new RuntimeException('এই customer/PPPoE account-এর অন্য ONU-তে conflicting primary mapping আছে; manual review প্রয়োজন।');
            }

            $now = now();

            if ($identityRevalidatedByLiveMac) {
                $mappingPhysicalIdentityVerified = $targetMapping
                    && $this->mappingHasStableIdentityWarning($targetMapping)
                    && $this->mappingMatchesLivePhysicalIdentity($targetMapping, $onu);
                $mapping->notes = $this->resolveManualReviewNotes(
                    $mapping->notes,
                    $callerMac,
                    (string) $olt->name,
                    $pon,
                    $onuId,
                    $now,
                    $mappingPhysicalIdentityVerified
                );
            }

            $mapping->fill([
                'pon_port' => $pon,
                'onu_id' => $onuId,
                'onu_mac' => $onu['onu_mac'] ?? $mapping->onu_mac,
                'onu_serial' => $onu['onu_serial'] ?? $mapping->onu_serial,
                'onu_type' => $onu['onu_type'] ?? $mapping->onu_type,
                'status' => $normalizedOnuStatus,
                // last_seen_at means the ONU was actually seen online; a poll of
                // an offline/unknown row must not make it look freshly present.
                'last_seen_at' => $normalizedOnuStatus === 'online' ? $now : $mapping->last_seen_at,
            ]);

            $hasFreshOpm = false;
            foreach (['rx_power', 'tx_power', 'temperature', 'supply_voltage', 'distance_m'] as $field) {
                if (isset($onu[$field]) && is_numeric($onu[$field])) {
                    $mapping->{$field} = (float) $onu[$field];
                    if (in_array($field, ['rx_power', 'tx_power'], true)) {
                        $hasFreshOpm = true;
                    }
                }
            }
            if ($hasFreshOpm) {
                $mapping->opm_source = $onu['opm_source'] ?? 'vsol-ssh-opm';
                $mapping->opm_last_seen_at = $now;
            }

            // Preserve an existing primary subscriber. Claim primary only when this
            // physical ONU has no legacy owner; otherwise add a separate PPP link.
            if (! $mapping->customer_id && ! $mapping->ppp_user_id) {
                $mapping->customer_id = $customer->id;
                $mapping->ppp_user_id = $ppp->id;
            } elseif ((int) $mapping->customer_id === (int) $customer->id
                && (! $mapping->ppp_user_id || (int) $mapping->ppp_user_id === (int) $ppp->id)) {
                $mapping->ppp_user_id = $ppp->id;
            }

            $mapping->save();

            if ($identityRevalidatedByLiveMac) {
                $ledgerRows = OnuMacLedger::query()
                    ->where('olt_device_id', $olt->id)
                    ->where('pon_port', $pon)
                    ->where('onu_id', $onuId)
                    ->lockForUpdate()
                    ->get();

                if ($ledgerRows->count() === 1) {
                    $ledger = $ledgerRows->first();
                    $ledgerPhysicalIdentityVerified = $this->ledgerMatchesLivePhysicalIdentity($ledger, $onu);
                    $previousLedgerCustomer = trim((string) $ledger->customer_unique_id);
                    $previousLedgerPppoe = trim((string) $ledger->pppoe_username);

                    if (! $ledgerPhysicalIdentityVerified) {
                        $ledger->notes = trim((string) $ledger->notes."\n".sprintf(
                            'Ledger physical identity conflict held for manual review: stored ONU MAC %s / Serial %s does not match the live ONU identity on %s PON %s / ONU %s.',
                            trim((string) $ledger->current_mac) ?: 'missing',
                            trim((string) $ledger->serial_no) ?: 'missing',
                            $olt->name,
                            $pon,
                            $onuId
                        ));
                        $ledger->save();
                    } else {
                        $activeOtherSubscribers = OltOnuCustomerPppLink::query()
                            ->where('olt_onu_customer_mapping_id', $mapping->id)
                            ->where('status', 'active')
                            ->where('ppp_user_id', '<>', $ppp->id)
                            ->exists();

                        $resolvedLedgerNotes = $this->resolveManualReviewNotes(
                            $ledger->notes,
                            $callerMac,
                            (string) $olt->name,
                            $pon,
                            $onuId,
                            $now,
                            true
                        );

                        if ($previousLedgerCustomer !== '' && $previousLedgerCustomer !== (string) $customer->customer_unique_id) {
                            $history = sprintf(
                                'Previous ONU ledger association retained for audit: %s / %s; current unique live PPPoE dynamic-MAC verification is %s / %s on %s PON %s / ONU %s.',
                                $previousLedgerCustomer,
                                $previousLedgerPppoe !== '' ? $previousLedgerPppoe : 'unknown PPPoE user',
                                $customer->customer_unique_id,
                                $ppp->username,
                                $olt->name,
                                $pon,
                                $onuId
                            );
                            $resolvedLedgerNotes = trim((string) $resolvedLedgerNotes."\n".$history);
                        }

                        if (! $activeOtherSubscribers) {
                            $ledger->customer_id = $customer->id;
                            $ledger->customer_unique_id = $customer->customer_unique_id;
                            $ledger->pppoe_username = $ppp->username;
                            $ledger->service_type = $ppp->service;
                        }
                        $ledger->status = $normalizedOnuStatus;
                        $ledger->rx_power = $mapping->rx_power ?? $ledger->rx_power;
                        $ledger->tx_power = $mapping->tx_power ?? $ledger->tx_power;
                        $ledger->last_seen_at = $mapping->last_seen_at ?? $ledger->last_seen_at;
                        $ledger->notes = $resolvedLedgerNotes;
                        $ledger->save();
                    }
                }
            }

            if ($previousMappingOwnsThisPrimary && $previousMapping && (int) $previousMapping->id !== (int) $mapping->id) {
                // Detach only this subscriber's legacy primary ownership. Other
                // active shared PPP links remain attached to the physical ONU row.
                $previousMapping->customer_id = null;
                $previousMapping->ppp_user_id = null;
                $previousMapping->save();
            }

            $link = $previousLink ?: OltOnuCustomerPppLink::query()
                ->where('olt_onu_customer_mapping_id', $mapping->id)
                ->where('ppp_user_id', $ppp->id)
                ->first();

            $linkValues = [
                'customer_id' => $customer->id,
                'client_mac' => $callerMac,
                'source' => 'customer_list_realtime_sync:'.$identitySource,
                'status' => strtolower((string) $customer->status) === 'active'
                    && strtolower((string) $ppp->status) === 'active' ? 'active' : 'inactive',
                'last_seen_at' => $now,
            ];

            if ($link) {
                $link->olt_onu_customer_mapping_id = $mapping->id;
                $link->fill($linkValues)->save();
            } else {
                OltOnuCustomerPppLink::create($linkValues + [
                    'olt_onu_customer_mapping_id' => $mapping->id,
                    'ppp_user_id' => $ppp->id,
                    'first_seen_at' => $now,
                ]);
            }

            if (is_array($active) && $identitySource === 'live_pppoe_session') {
                $ppp->last_caller_id = $callerMac;
                $ppp->save();
            }

            $subscriberCount = OltOnuCustomerPppLink::query()
                ->where('olt_onu_customer_mapping_id', $mapping->id)
                ->where('status', 'active')
                ->count();

            return [
                'mapping_id' => $mapping->id,
                'pon' => $pon,
                'onu_id' => $onuId,
                'rx_power' => is_numeric($onu['rx_power'] ?? null) ? (float) $onu['rx_power'] : null,
                'tx_power' => is_numeric($onu['tx_power'] ?? null) ? (float) $onu['tx_power'] : null,
                'opm_fresh' => $hasFreshOpm,
                'opm_status' => $hasFreshOpm ? 'fresh' : (strtolower((string) ($onu['status'] ?? '')) === 'offline' ? 'olt_reports_offline' : 'not_reported_by_olt'),
                'subscriber_count' => $subscriberCount,
                'identity_source' => $identitySource,
                'session_online' => is_array($active) && $identitySource === 'live_pppoe_session',
            ];
        }, 3);

        \Log::info('Single customer ONU realtime sync completed', [
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'olt_device_id' => $olt->id,
            'mapping_id' => $result['mapping_id'],
            'pon' => $result['pon'],
            'onu_id' => $result['onu_id'],
            'identity_source' => $result['identity_source'],
            'opm_fresh' => $result['opm_fresh'],
        ]);

        return $result + ['olt_name' => $olt->name, 'customer_code' => $customer->customer_unique_id];
    }

    /**
     * A saved PPP link is not proof of a current ONU identity when its mapping
     * carries an unresolved reconciliation/manual-review warning.
     */
    private function mappingRequiresManualReview(OltOnuCustomerMapping $mapping): bool
    {
        $notes = strtolower(trim((string) $mapping->notes));

        foreach ([
            'manual review',
            'requires operator review',
            'requires explicit review',
            'customer/pppoe link cleared',
            'does not match pppoe last_caller_id',
            'identity conflict',
            'duplicate live mac',
            'stable onu identity changed',
        ] as $marker) {
            if (str_contains($notes, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Duplicate MAC and stable hardware-identity conflicts need human review;
     * a unique live client-MAC table match cannot resolve those physical conflicts.
     */
    private function mappingHasUnresolvableIdentityConflict(OltOnuCustomerMapping $mapping): bool
    {
        $notes = strtolower(trim((string) $mapping->notes));

        foreach (['duplicate live mac', 'conflicting onu serial'] as $marker) {
            if (str_contains($notes, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function ledgerMatchesLivePhysicalIdentity(OnuMacLedger $ledger, array $onu): bool
    {
        $storedMac = $this->normalizeMac($ledger->current_mac);
        $liveMac = $this->normalizeMac($onu['onu_mac'] ?? null);
        $storedSerial = strtolower(trim((string) $ledger->serial_no));
        $liveSerial = strtolower(trim((string) ($onu['onu_serial'] ?? '')));

        if ($storedSerial !== '') {
            return $liveSerial !== '' && hash_equals($storedSerial, $liveSerial)
                && ($storedMac === null || $liveMac === null || hash_equals($storedMac, $liveMac));
        }

        return $storedMac !== null && $liveMac !== null && hash_equals($storedMac, $liveMac);
    }

    private function mappingHasStableIdentityWarning(OltOnuCustomerMapping $mapping): bool
    {
        return str_contains(strtolower(trim((string) $mapping->notes)), 'stable onu identity changed');
    }

    /**
     * A historical stable-identity warning is resolvable only when the current
     * OLT row reproduces the saved ONU hardware identity exactly. Caller MAC alone
     * is not enough to override a physical ONU identity warning.
     */
    private function mappingMatchesLivePhysicalIdentity(OltOnuCustomerMapping $mapping, array $onu): bool
    {
        $storedMac = $this->normalizeMac($mapping->onu_mac);
        $liveMac = $this->normalizeMac($onu['onu_mac'] ?? null);
        $storedSerial = strtolower(trim((string) $mapping->onu_serial));
        $liveSerial = strtolower(trim((string) ($onu['onu_serial'] ?? '')));

        if ($storedSerial !== '') {
            return $liveSerial !== '' && hash_equals($storedSerial, $liveSerial)
                && ($storedMac === null || $liveMac === null || hash_equals($storedMac, $liveMac));
        }

        return $storedMac !== null && $liveMac !== null && hash_equals($storedMac, $liveMac);
    }

    /**
     * Resolve only generic customer/PPPoE review markers after a unique live
     * dynamic-MAC match. Preserve non-review notes and retain an audit summary.
     */
    private function resolveManualReviewNotes(
        ?string $notes,
        string $callerMac,
        string $oltName,
        string $pon,
        string $onuId,
        Carbon $verifiedAt,
        bool $clearStableIdentityWarning = false
    ): ?string {
        $lines = preg_split('/\R/', trim((string) $notes)) ?: [];
        $remaining = [];

        foreach ($lines as $line) {
            $normalized = strtolower(trim($line));
            if ($normalized === '') {
                continue;
            }

            if (
                str_contains($normalized, 'duplicate live mac')
                || str_contains($normalized, 'conflicting onu serial')
            ) {
                return $notes;
            }

            if (str_contains($normalized, 'stable onu identity changed')) {
                if ($clearStableIdentityWarning) {
                    continue;
                }
                $remaining[] = trim($line);

                continue;
            }

            if (
                str_contains($normalized, 'manual review')
                || str_contains($normalized, 'requires operator review')
                || str_contains($normalized, 'requires explicit review')
                || str_contains($normalized, 'customer/pppoe link cleared')
                || str_contains($normalized, 'does not match pppoe last_caller_id')
                || str_contains($normalized, 'identity conflict')
            ) {
                continue;
            }

            $remaining[] = trim($line);
        }

        $auditMarker = sprintf(
            'Customer/PPPoE identity revalidated by unique live dynamic MAC %s on %s PON %s / ONU %s',
            $callerMac,
            $oltName,
            $pon,
            $onuId
        );

        if (str_contains(strtolower((string) $notes), strtolower($auditMarker))) {
            return $notes;
        }

        // Even a clean target row needs an audit trail when a flagged/stale
        // source mapping was bypassed and its subscriber link was moved here.
        $remaining[] = $auditMarker.' at '.$verifiedAt->toDateTimeString().'.';

        return implode("\n", $remaining);
    }

    private function normalizeOnuStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        if (in_array($status, ['1', 'online', 'up', 'active', 'registered', 'auth success'], true)) {
            return 'online';
        }

        if (in_array($status, ['0', '2', '4', 'offline', 'down', 'inactive', 'lost', 'deregistered', 'unregistered'], true)) {
            return 'offline';
        }

        return 'unknown';
    }

    private function findRouterRow(array $rows, string $username): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $username) === 0) {
                return $row;
            }
        }

        return null;
    }

    private function normalizeMac(mixed $value): ?string
    {
        $normalized = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $value) ?: '');
        if (strlen($normalized) !== 12) {
            return null;
        }

        return implode(':', str_split($normalized, 2));
    }
}
