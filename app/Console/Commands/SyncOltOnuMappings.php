<?php

namespace App\Console\Commands;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltMonitoringSnapshot;
use App\Models\OltOnuCustomerMapping;
use App\Models\OnuMacLedger;
use App\Services\Olt\OltReadOnlyAdapterManager;
use App\Services\Olt\VsolSshOpmCollector;
use App\Services\OnuMacLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncOltOnuMappings extends Command
{
    protected $signature = 'olt:sync-onu-mappings {--device= : OLT device ID} {--dry-run : Read and resolve mappings without writing the local database}';

    protected $description = 'Read-only sync of ONU/customer mappings from OLT read-only adapters or configured telemetry';

    public function handle(OltReadOnlyAdapterManager $manager, OnuMacLedgerService $ledgerService): int
    {
        $lockPath = storage_path('framework/onu-mapping-sync.lock');
        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle === false) {
            $this->error('Cannot open the ONU sync lock file; no sync was started.');

            return self::FAILURE;
        }

        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            $this->comment('ONU/customer sync is already running; this duplicate trigger was coalesced.');

            return self::SUCCESS;
        }

        try {
            return $this->runSync($manager, $ledgerService);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function runSync(OltReadOnlyAdapterManager $manager, OnuMacLedgerService $ledgerService): int
    {
        $query = NetworkInventoryDevice::where('type', 'olt');
        if ($this->option('device')) {
            $query->whereKey((int) $this->option('device'));
        }

        foreach ($query->get() as $olt) {
            $result = $this->readSource($olt, $manager);
            if (! $result['ok']) {
                $this->warn("OLT {$olt->id}: {$result['message']}");

                continue;
            }

            $rows = is_array($result['onus'] ?? null) ? $result['onus'] : [];
            if (! $rows) {
                $this->info("OLT {$olt->id}: no normalized ONU rows returned; no mapping writes performed.");

                continue;
            }
            $meta = $result['meta'] ?? [];
            if (($result['status'] ?? null) !== 'ok'
                || ! is_array($meta)
                || ($meta['read_only'] ?? false) !== true
                || ! array_key_exists('writes_performed', $meta)
                || $meta['writes_performed'] !== false) {
                $this->warn("OLT {$olt->id}: poll is not a complete verified read-only result; no mapping writes performed.");

                continue;
            }
            $identityKeys = collect($rows)->map(function (array $row): ?string {
                $pon = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
                $id = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? ''));

                return $pon !== '' && $id !== '' ? $pon.'|'.$id : null;
            })->filter();
            if ($identityKeys->count() !== $identityKeys->unique()->count()) {
                $this->warn("OLT {$olt->id}: duplicate live PON/ONU identity detected; no mapping writes performed.");

                continue;
            }
            $liveMacGroups = collect($rows)->filter(fn (array $row) => $this->normalizeMac($row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null) !== '')
                ->groupBy(fn (array $row) => $this->normalizeMac($row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null))
                ->filter(fn ($group) => $group->count() > 1);
            $duplicateLiveMacs = $liveMacGroups->keys()->all();

            // A customer device MAC learned behind multiple ONUs is ambiguous even
            // when the ONU hardware MACs themselves are unique. Never let row order
            // decide which ONU receives the PPP subscriber link.
            $clientMacLocations = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $pon = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
                $id = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? ''));
                if ($pon === '' || $id === '') {
                    continue;
                }
                $slot = $pon.'|'.$id;
                foreach ((array) ($row['client_macs'] ?? []) as $clientMac) {
                    $mac = $this->normalizeMac($clientMac);
                    if ($mac !== '') {
                        $clientMacLocations[$mac][$slot] = true;
                    }
                }
            }
            $duplicateClientMacs = array_keys(array_filter($clientMacLocations, static fn ($locations) => count($locations) > 1));

            $count = 0;
            $skipped = 0;
            $autoMapped = 0;
            $sharedSubscriberLinksCreated = 0;
            $sharedSubscriberLinksRefreshed = 0;
            $issues = [];
            if ($duplicateClientMacs !== []) {
                $issues[] = count($duplicateClientMacs).' customer-device MAC(s) were learned behind multiple ONU interfaces; no automatic PPP link will be created for those MACs.';
            }

            // Stable mapping identity: MAC/serial first, ONU index only as a fallback.
            // BDCOM/EPON discovery order can change between SNMP polls, so using only
            // onu_id can attach an old customer to a different physical ONU.
            $existingMappings = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->get();
            // A dry-run must remain entirely read-only, including auxiliary history tables.
            if (! $this->option('dry-run')) {
                $ledgerService->bootstrapMissingMappings($existingMappings);
            }
            $seenMappingIds = [];
            $byId = $existingMappings->keyBy(fn ($m) => trim((string) $m->pon_port).'|'.trim((string) $m->onu_id));
            $byMac = $existingMappings->filter(fn ($m) => trim((string) $m->onu_mac) !== '')
                ->groupBy(fn ($m) => $this->normalizeMac($m->onu_mac));
            $bySerial = $existingMappings->filter(fn ($m) => trim((string) $m->onu_serial) !== '')
                ->groupBy(fn ($m) => strtolower(trim((string) $m->onu_serial)));

            // Build unique read-only identifiers once so every ONU can be matched
            // without an N+1 customer query loop. Only an unambiguous exact match is applied.
            $customers = CustomersInfo::with('pppUser')->get();
            $byPppUsername = [];
            $byCallerMac = [];
            $byRemoteIp = [];
            $usedCustomerIds = [];
            $usedPppIds = [];
            foreach ($existingMappings as $mapping) {
                if ($mapping->customer_id) {
                    $usedCustomerIds[(string) $mapping->customer_id] = true;
                }
                if ($mapping->ppp_user_id) {
                    $usedPppIds[(string) $mapping->ppp_user_id] = true;
                }
            }
            foreach ($customers as $customer) {
                $ppp = $customer->pppUser;
                if (! $ppp) {
                    continue;
                }
                $usernameKey = strtolower(trim((string) $ppp->username));
                if ($usernameKey !== '') {
                    $byPppUsername[$usernameKey][] = $customer;
                }
                foreach ([$ppp->caller_id, $ppp->last_caller_id] as $mac) {
                    $macKey = $this->normalizeMac($mac);
                    if ($macKey !== '') {
                        $byCallerMac[$macKey][] = $customer;
                    }
                }
                $ipKey = trim((string) $ppp->ppp_remote_ip);
                if ($ipKey !== '') {
                    $byRemoteIp[$ipKey][] = $customer;
                }
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    $skipped++;
                    $issues[] = 'invalid ONU row was not an array';

                    continue;
                }
                $onuId = $row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? null;
                if ($onuId === null || trim((string) $onuId) === '') {
                    $skipped++;
                    $issues[] = 'missing ONU id';

                    continue;
                }
                $onuId = trim((string) $onuId);
                $ponPort = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
                if ($ponPort === '') {
                    $skipped++;
                    $issues[] = "ONU {$onuId}: missing PON port; cannot resolve a PON-scoped identity";

                    continue;
                }
                $identityKey = $ponPort.'|'.$onuId;

                $onuMac = $row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null;
                $onuSerial = $row['onu_serial'] ?? $row['serial'] ?? $row['onuSerial'] ?? null;
                $pppId = null;
                $customerId = null;

                $macKey = $this->normalizeMac($onuMac);
                $serialKey = strtolower(trim((string) $onuSerial));
                if ($macKey !== '' && in_array($macKey, $duplicateLiveMacs, true)) {
                    foreach ($byMac->get($macKey, collect()) as $ambiguousMapping) {
                        $seenMappingIds[(string) $ambiguousMapping->id] = true;
                    }
                    $issues[] = "ONU {$ponPort}/{$onuId}: duplicate live ONU MAC {$macKey}; customer/PPPoE identity remains quarantined. Slot-level status/OPM telemetry may still be refreshed.";
                    if (! $this->option('dry-run')) {
                        $this->refreshDuplicateMacSlotTelemetry($byId->get($identityKey), $row);
                    }
                    $count++;

                    continue;
                }
                $stableMatches = $macKey !== '' ? ($byMac->get($macKey, collect())) : collect();
                if ($stableMatches->count() === 0 && $serialKey !== '') {
                    $stableMatches = $bySerial->get($serialKey, collect());
                }
                $existingMapping = $stableMatches->count() === 1 ? $stableMatches->first() : null;
                $sameIdMapping = $byId->get($identityKey);
                $identityConflict = false;

                // If there is no stable match, an existing ONU ID can be reused only
                // when its stored stable identity is empty or agrees with this ONU.
                // A changed MAC/serial at the same index must not inherit the old customer.
                if (! $existingMapping && $sameIdMapping) {
                    $oldMac = $this->normalizeMac($sameIdMapping->onu_mac);
                    $oldSerial = strtolower(trim((string) $sameIdMapping->onu_serial));
                    $identityConflict = ($macKey !== '' && $oldMac !== '' && $macKey !== $oldMac)
                        || ($serialKey !== '' && $oldSerial !== '' && $serialKey !== $oldSerial);
                    if (! $identityConflict) {
                        $existingMapping = $sameIdMapping;
                    }
                }

                $status = $this->normalizeStatus($row);
                if (! in_array($status, ['online', 'offline', 'unknown'], true)) {
                    $issues[] = "ONU {$onuId}: invalid status normalized to unknown";
                    $status = 'unknown';
                }

                // Dynamic MAC/PPPoE caller identity from an offline or unknown ONU
                // is not current attachment evidence. Telemetry is still refreshed,
                // but customer assignment is only considered while the ONU is Online.
                $allowAutoMatch = ! $identityConflict
                    && (! $existingMapping || ! $existingMapping->customer_id)
                    && $status === 'online';

                if ($existingMapping && $existingMapping->customer_id && ! $identityConflict) {
                    // Synchronisation refreshes telemetry only. Existing operator mappings
                    // are preserved; new customer assignments require explicit review/save.
                    $pppId = $existingMapping->ppp_user_id;
                    $customerId = $existingMapping->customer_id;
                } elseif ($identityConflict) {
                    $issues[] = "ONU {$onuId}: stable identity changed; previous customer mapping was not reused and requires review.";
                    if ($sameIdMapping) {
                        // In a real sync, clear only the stale customer assignment. A dry-run
                        // reports the conflict but never mutates the database.
                        if (! $this->option('dry-run')) {
                            $sameIdMapping->customer_id = null;
                            $sameIdMapping->ppp_user_id = null;
                            $reviewNote = 'Stable ONU identity changed; manual review required.';
                            $existingNotes = trim((string) $sameIdMapping->notes);
                            if (! str_contains($existingNotes, $reviewNote)) {
                                $sameIdMapping->notes = trim($existingNotes.($existingNotes !== '' ? "\n" : '').$reviewNote);
                            }
                            $sameIdMapping->save();
                        }
                        $existingMapping = $sameIdMapping;
                        $allowAutoMatch = true;
                    }
                }

                // Track the persisted mapping resolved by stable MAC/serial identity,
                // or by the existing ID after the conflict checks above.
                if ($existingMapping) {
                    $seenMappingIds[(string) $existingMapping->id] = true;
                }

                if ($allowAutoMatch && ! $customerId) {
                    // Apply only an unambiguous exact match. This covers new ONU rows,
                    // existing-unmapped rows, and rows whose old index identity changed.
                    // Priority: PPPoE username, ONU MAC == PPP caller/last-caller,
                    // then ONU IP == PPP remote IP.
                    $availableCandidates = function (array $items) use (&$usedCustomerIds, &$usedPppIds): array {
                        $items = $this->preferActiveCandidates($items);

                        return array_values(array_filter($items, static function ($customer) use (&$usedCustomerIds, &$usedPppIds): bool {
                            $customerActive = strtolower(trim((string) ($customer->status ?? ''))) === 'active';
                            $pppActive = strtolower(trim((string) ($customer->pppUser?->status ?? ''))) === 'active';

                            return $customerActive
                                && $pppActive
                                && ! isset($usedCustomerIds[(string) $customer->id])
                                && ! isset($usedPppIds[(string) $customer->ppp_user_id]);
                        }));
                    };

                    $candidates = [];
                    $onuUsername = strtolower(trim((string) ($row['pppoe_username'] ?? $row['ppp_username'] ?? '')));
                    if ($onuUsername !== '') {
                        $candidates = $availableCandidates($byPppUsername[$onuUsername] ?? []);
                    }

                    // Prefer dynamic client MACs learned behind this exact EPON
                    // interface. These are different from the ONU's own MAC and
                    // allow matching the customer's active PPP caller-ID identity.
                    if (! $candidates && is_array($row['client_macs'] ?? null)) {
                        $fdbCandidates = [];
                        foreach ($row['client_macs'] as $clientMac) {
                            $clientMacKey = $this->normalizeMac($clientMac);
                            if ($clientMacKey === '') {
                                continue;
                            }
                            if (count($clientMacLocations[$clientMacKey] ?? []) !== 1) {
                                continue;
                            }
                            foreach ($availableCandidates($byCallerMac[$clientMacKey] ?? []) as $candidate) {
                                $fdbCandidates[(string) $candidate->id] = $candidate;
                            }
                        }
                        $candidates = array_values($fdbCandidates);
                    }

                    if (! $candidates) {
                        $macKey = $this->normalizeMac($onuMac);
                        $candidates = $availableCandidates($macKey !== '' ? ($byCallerMac[$macKey] ?? []) : []);
                    }

                    if (! $candidates) {
                        $ipKey = trim((string) ($row['onu_ip'] ?? $row['ip'] ?? ''));
                        $candidates = $availableCandidates($ipKey !== '' ? ($byRemoteIp[$ipKey] ?? []) : []);
                    }

                    // Use the MAC ledger only as a last exact-identity source. If the live
                    // OLT supplies a PPPoE username, that stronger source must be resolved first.
                    if (! $candidates && $onuUsername === '') {
                        $ledgerCustomer = $ledgerService->findExactCustomerMatch(
                            (int) $olt->id,
                            $onuMac === null ? null : (string) $onuMac,
                            $onuSerial === null ? null : (string) $onuSerial,
                            $usedCustomerIds,
                            $usedPppIds,
                        );
                        if ($ledgerCustomer) {
                            $candidates = [$ledgerCustomer];
                        }
                    }

                    if (count($candidates) === 1) {
                        $customerId = $candidates[0]->id;
                        $pppId = $candidates[0]->ppp_user_id;
                        // Reserve this identity for the remainder of this pass so a
                        // duplicated MAC cannot auto-assign one account to two ONUs.
                        $usedCustomerIds[(string) $customerId] = true;
                        $usedPppIds[(string) $pppId] = true;
                        $autoMapped++;
                    } elseif (count($candidates) > 1) {
                        $issues[] = "ONU {$onuId}: multiple active customer identities were learned behind this ONU; left unmapped for review.";
                    }
                }

                $lastSeen = $row['last_seen'] ?? $row['lastSeen'] ?? null;
                if ($lastSeen !== null && $lastSeen !== '' && ! strtotime((string) $lastSeen)) {
                    $issues[] = "ONU {$onuId}: invalid last_seen ignored";
                    $lastSeen = null;
                }
                // A successful live poll is itself evidence that an online ONU was seen now.
                // Preserve the previous timestamp when telemetry omits last_seen for an offline/unknown ONU.
                if (($lastSeen === null || $lastSeen === '') && $status === 'online') {
                    $lastSeen = now();
                } elseif (($lastSeen === null || $lastSeen === '') && $existingMapping?->last_seen_at) {
                    $lastSeen = $existingMapping->last_seen_at;
                }

                if ($this->option('dry-run')) {
                    $this->line("OLT {$olt->id}: ONU {$onuId} → customer=".($customerId ?? 'unmapped').', ppp='.($pppId ?? 'unmapped').", status={$status}");
                    $count++;

                    continue;
                }

                // Timestamp an OPM sample only when both optical values are present.
                // last_seen_at alone proves ONU status/identity was polled, not OPM.
                $hasFreshOpm = is_numeric($row['rx_power'] ?? $row['opticalRx'] ?? null)
                    && is_numeric($row['tx_power'] ?? $row['opticalTx'] ?? null);

                $mappingData = [
                    'customer_id' => $customerId ?? $existingMapping?->customer_id,
                    'ppp_user_id' => $pppId ?? $existingMapping?->ppp_user_id,
                    'onu_serial' => trim((string) $onuSerial) !== '' ? $onuSerial : $existingMapping?->onu_serial,
                    'onu_mac' => $this->normalizeMac($onuMac) !== '' ? $onuMac : $existingMapping?->onu_mac,
                    'pon_port' => $ponPort !== '' ? $ponPort : $existingMapping?->pon_port,
                    'distance_m' => $row['distance'] ?? $row['distance_m'] ?? $existingMapping?->distance_m,
                    'temperature' => $row['temperature'] ?? $row['temp'] ?? $existingMapping?->temperature,
                    'supply_voltage' => $row['supply_voltage'] ?? $row['voltage'] ?? $existingMapping?->supply_voltage,
                    'tx_bias_current' => $row['tx_bias_current'] ?? $row['tx_bias'] ?? $row['bias_current'] ?? $existingMapping?->tx_bias_current,
                    'onu_type' => $row['onu_type'] ?? $row['type'] ?? $existingMapping?->onu_type,
                    'status' => $status,
                    'rx_power' => $row['rx_power'] ?? $row['opticalRx'] ?? $existingMapping?->rx_power,
                    'tx_power' => $row['tx_power'] ?? $row['opticalTx'] ?? $existingMapping?->tx_power,
                    'opm_source' => $row['opm_source'] ?? $existingMapping?->opm_source,
                    'opm_last_seen_at' => $hasFreshOpm ? now() : $existingMapping?->opm_last_seen_at,
                    'onu_ip' => $row['onu_ip'] ?? $row['ip'] ?? $row['onuIp'] ?? null,
                    'last_seen_at' => $lastSeen,
                    'notes' => $existingMapping ? $existingMapping->notes : ($row['detail'] ?? 'Discovered by read-only ONU synchronisation; customer mapping requires explicit review.'),
                ];

                if ($existingMapping) {
                    // Keep the stable mapping row. Update the index only when the
                    // current index is not already occupied by another mapping.
                    $idConflict = ((string) $existingMapping->onu_id !== $onuId
                            || trim((string) $existingMapping->pon_port) !== $ponPort)
                        && OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                            ->where('pon_port', $ponPort)
                            ->where('onu_id', $onuId)
                            ->where('id', '<>', $existingMapping->id)
                            ->exists();
                    if (! $idConflict) {
                        $existingMapping->onu_id = $onuId;
                    }
                    $existingMapping->fill($mappingData);
                    $existingMapping->save();
                    $savedMapping = $existingMapping;
                    $ledgerService->recordMapping($savedMapping);
                } else {
                    $savedMapping = OltOnuCustomerMapping::create($mappingData + [
                        'olt_device_id' => $olt->id,
                        'onu_id' => (string) $onuId,
                    ]);
                    $ledgerService->recordMapping($savedMapping);
                }

                // An ONU is one physical optical endpoint but may carry several
                // independent PPPoE subscriber sessions. Record every unambiguous
                // dynamic client-MAC -> PPP last_caller_id match without replacing
                // the legacy primary customer_id / ppp_user_id columns.
                $subscriberRow = $row;
                $subscriberRow['client_macs'] = array_values(array_filter(
                    (array) ($row['client_macs'] ?? []),
                    fn ($clientMac) => count($clientMacLocations[$this->normalizeMac($clientMac)] ?? []) === 1
                ));
                [$linkedNow, $refreshedNow, $subscriberIssues] = $this->syncSharedPppSubscribers(
                    $savedMapping,
                    $subscriberRow,
                    $byCallerMac,
                );
                $sharedSubscriberLinksCreated += $linkedNow;
                $sharedSubscriberLinksRefreshed += $refreshedNow;
                foreach ($subscriberIssues as $subscriberIssue) {
                    $issues[] = $subscriberIssue;
                }
                $count++;
            }
            // Only make absence claims after a complete, healthy, explicitly read-only
            // poll. Do not infer offline status or overwrite any customer/PPPoE mapping.
            $completeReadOnlyPoll = ($result['status'] ?? null) === 'ok'
                && is_array($result['meta'] ?? null)
                && (($result['meta']['read_only'] ?? false) === true)
                && array_key_exists('writes_performed', $result['meta'])
                && ($result['meta']['writes_performed'] === false)
                && $count === count($rows)
                && $skipped === 0;

            // Keep OLT-level live counters in sync only after a complete, verified,
            // read-only poll. Capacity remains a separate PON-port property.
            if ($completeReadOnlyPoll && ! $this->option('dry-run')) {
                $liveOnuKeys = collect($rows)
                    ->map(function (array $row): ?string {
                        $pon = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
                        $id = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? ''));

                        return $pon !== '' && $id !== '' ? $pon.'|'.$id : null;
                    })
                    ->filter()
                    ->unique()
                    ->values();
                $liveTotal = count($rows);
                $liveOnline = collect($rows)
                    ->filter(fn (array $row) => $this->normalizeStatus($row) === 'online')
                    ->count();
                $liveMappings = OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                    ->get(['id', 'pon_port', 'onu_id', 'customer_id'])
                    ->filter(fn ($mapping) => $liveOnuKeys->contains(
                        trim((string) $mapping->pon_port).'|'.trim((string) $mapping->onu_id)
                    ));
                $legacyMappedCustomerIds = $liveMappings->whereNotNull('customer_id')->pluck('customer_id');
                $liveMappingIds = $liveMappings->pluck('id')->all();
                $subscriberLinks = $liveMappingIds
                    ? DB::table('olt_onu_customer_ppp_links')
                        ->whereIn('olt_onu_customer_mapping_id', $liveMappingIds)
                        ->where('status', 'active')
                        ->get(['olt_onu_customer_mapping_id', 'customer_id'])
                    : collect();
                $mappedCustomerIds = $legacyMappedCustomerIds
                    ->merge($subscriberLinks->pluck('customer_id'))
                    ->unique()
                    ->values();
                $mappedOnuIds = $liveMappings->whereNotNull('customer_id')->pluck('id')
                    ->merge($subscriberLinks->pluck('olt_onu_customer_mapping_id'))
                    ->unique();
                $mappedOnuCount = $mappedOnuIds->count();
                $mappedCustomerCount = $mappedCustomerIds->count();

                $onlineCount = collect($rows)->filter(fn (array $row) => $this->normalizeStatus($row) === 'online')->count();
                $offlineCount = collect($rows)->filter(fn (array $row) => $this->normalizeStatus($row) === 'offline')->count();
                $unknownCount = max(0, $liveTotal - $onlineCount - $offlineCount);
                $rxReadings = collect($rows)
                    ->pluck('rx_power')
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (float) $value)
                    ->values();
                $goodMinimum = (float) config('olt_dashboard.signal_good_min_dbm', -20);
                $warningMinimum = (float) config('olt_dashboard.signal_warning_min_dbm', -27);
                $signalGood = $rxReadings->filter(fn (float $value) => $value >= $goodMinimum)->count();
                $signalWarning = $rxReadings->filter(fn (float $value) => $value < $goodMinimum && $value >= $warningMinimum)->count();
                $signalCritical = $rxReadings->filter(fn (float $value) => $value < $warningMinimum)->count();

                $olt->update([
                    'onu_total' => $liveTotal,
                    'onu_online' => $onlineCount,
                    'customer_count' => $mappedCustomerCount,
                ]);

                $snapshotInterval = max(1, (int) config('olt_dashboard.snapshot_interval_minutes', 5));
                $snapshot = OltMonitoringSnapshot::where('olt_device_id', $olt->id)
                    ->latest('sampled_at')
                    ->first();

                // Keep the first sample timestamp within a window. Moving it on
                // every poll would create a sliding window and suppress new trend
                // points forever when the scheduler runs more frequently than 5 min.
                if (! $snapshot || $snapshot->sampled_at->lt(now()->subMinutes($snapshotInterval))) {
                    $snapshot = new OltMonitoringSnapshot([
                        'olt_device_id' => $olt->id,
                        'sampled_at' => now(),
                    ]);
                }

                $snapshot->fill([
                    'onu_total' => $liveTotal,
                    'onu_online' => $onlineCount,
                    'onu_offline' => $offlineCount,
                    'onu_unknown' => $unknownCount,
                    'mapped_onus' => $mappedOnuCount,
                    'mapped_customers' => $mappedCustomerCount,
                    'rx_sample_count' => $rxReadings->count(),
                    'signal_good' => $signalGood,
                    'signal_warning' => $signalWarning,
                    'signal_critical' => $signalCritical,
                    'signal_no_sample' => max(0, $liveTotal - $rxReadings->count()),
                    'avg_rx_power' => $rxReadings->isNotEmpty() ? round($rxReadings->avg(), 2) : null,
                    'min_rx_power' => $rxReadings->isNotEmpty() ? round($rxReadings->min(), 2) : null,
                    'avg_latency_ms' => is_numeric($olt->last_latency_ms) ? (int) $olt->last_latency_ms : null,
                ])->save();

                $this->line("OLT {$olt->id}: live counters reconciled — {$onlineCount}/{$liveTotal} online; {$mappedCustomerCount} linked PPP customers across {$mappedOnuCount} mapped ONUs; RX samples {$rxReadings->count()}.");
            }

            $staleCandidates = $existingMappings->filter(
                fn ($mapping) => strtolower(trim((string) $mapping->status)) === 'online'
                    && ! isset($seenMappingIds[(string) $mapping->id])
            );

            if ($completeReadOnlyPoll) {
                foreach ($staleCandidates as $staleMapping) {
                    $this->warn("OLT {$olt->id}: ONU {$staleMapping->onu_id} / ".($staleMapping->onu_mac ?: 'MAC unknown')
                        .' is stored as online but absent from this complete read-only poll; status and mapping were left unchanged pending review.');
                }
            } elseif ($staleCandidates->isNotEmpty()) {
                $issues[] = 'Some stored online ONU rows were absent, but no absence warning was trusted because the poll was partial, invalid, or not explicitly verified as read-only.';
            }

            $this->info("OLT {$olt->id}: normalized {$count} ONU rows synced; auto-mapped {$autoMapped} primary customer matches; shared-ONU PPP links added {$sharedSubscriberLinksCreated}, refreshed {$sharedSubscriberLinksRefreshed}; stale online rows not observed: ".($completeReadOnlyPoll ? $staleCandidates->count() : 'not assessed')."; skipped {$skipped} invalid rows.");
            foreach (array_slice($issues, 0, 10) as $issue) {
                $this->warn($issue);
            }

            if (! empty($result['meta']['model_profile_mismatch'])) {
                $inventoryModel = (string) ($result['meta']['inventory_model'] ?? $olt->model ?? 'unknown');
                $liveModel = (string) ($result['meta']['snmp_sys_descr'] ?? 'unknown');
                $this->warn("OLT {$olt->id}: inventory model {$inventoryModel} differs from live sysDescr {$liveModel}; the adapter selected its live-verified read profile.");
            }
            if (($result['meta']['opm_status'] ?? null) === 'not_ready') {
                $this->warn("OLT {$olt->id}: optical TX/RX readings are unavailable in this poll; historical OPM values and timestamps were preserved.");
            }
            if (($result['meta']['ssh_opm_status'] ?? null) === 'credentials_required') {
                $this->warn("OLT {$olt->id}: SSH OPM fallback was not run because the separate CLI enable password is not configured in OLT Admin settings.");
            } elseif (! empty($result['meta']['ssh_opm_message'])) {
                $this->line("OLT {$olt->id}: ".$result['meta']['ssh_opm_message']);
            }
            if (count($issues) > 10) {
                $this->warn('Additional mapping validation issues: '.(count($issues) - 10));
            }
        }

        $retentionDays = max(7, (int) config('olt_dashboard.snapshot_retention_days', 90));
        OltMonitoringSnapshot::where('sampled_at', '<', now()->subDays($retentionDays))->delete();

        return self::SUCCESS;
    }

    /**
     * Link each uniquely identified, active PPP subscriber behind the current ONU.
     * The physical mapping keeps its original primary customer while this table
     * stores additional subscribers. Ambiguous MACs and cross-ONU conflicts are skipped.
     *
     * @return array{0:int,1:int,2:array<int,string>}
     */
    /** Refresh only slot telemetry for a duplicate-ONU-MAC row; keep identity and owners quarantined. */
    private function refreshDuplicateMacSlotTelemetry(?OltOnuCustomerMapping $mapping, array $row): void
    {
        if (! $mapping) {
            return;
        }

        DB::transaction(function () use ($mapping, $row): void {
            $locked = OltOnuCustomerMapping::query()->lockForUpdate()->find($mapping->id);
            if (! $locked) {
                return;
            }

            $status = $this->normalizeStatus($row);
            if (! in_array($status, ['online', 'offline', 'unknown'], true)) {
                $status = 'unknown';
            }
            $locked->status = $status;
            if ($status === 'online') {
                $locked->last_seen_at = now();
            }

            $hasFreshOpm = is_numeric($row['rx_power'] ?? $row['opticalRx'] ?? null)
                && is_numeric($row['tx_power'] ?? $row['opticalTx'] ?? null);
            if ($hasFreshOpm) {
                $locked->rx_power = (float) ($row['rx_power'] ?? $row['opticalRx']);
                $locked->tx_power = (float) ($row['tx_power'] ?? $row['opticalTx']);
                $locked->opm_source = $row['opm_source'] ?? $locked->opm_source;
                $locked->opm_last_seen_at = now();
            }

            // Update only the slot-scoped ledger when its physical PON/ONU and MAC
            // match this quarantined row. Preserve customer ownership and history.
            $ledgerRows = OnuMacLedger::query()
                ->where('olt_device_id', $locked->olt_device_id)
                ->where('pon_port', $locked->pon_port)
                ->where('onu_id', $locked->onu_id)
                ->lockForUpdate()
                ->get();
            if ($ledgerRows->count() === 1) {
                $ledger = $ledgerRows->first();
                if ($this->normalizeMac($ledger->current_mac) === $this->normalizeMac($locked->onu_mac)) {
                    $ledger->status = $status;
                    if ($status === 'online') {
                        $ledger->last_seen_at = $locked->last_seen_at;
                    }
                    if ($hasFreshOpm) {
                        $ledger->rx_power = $locked->rx_power;
                        $ledger->tx_power = $locked->tx_power;
                    }
                    $ledger->save();
                }
            }

            // Intentionally do not change ONU MAC/Serial, customer/PPP ownership,
            // notes, or historical ledger owner while duplicate hardware identity exists.
            $locked->save();
        }, 3);
    }

    private function syncSharedPppSubscribers(OltOnuCustomerMapping $mapping, array $row, array $byCallerMac): array
    {
        if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'online') {
            return [0, 0, []];
        }

        $clientMacs = collect((array) ($row['client_macs'] ?? []))
            ->map(fn ($mac) => $this->normalizeMac($mac))
            ->filter()
            ->unique()
            ->values();

        if ($clientMacs->isEmpty()) {
            return [0, 0, []];
        }

        $created = 0;
        $refreshed = 0;
        $issues = [];

        foreach ($clientMacs as $clientMac) {
            $matches = collect($byCallerMac[$clientMac] ?? [])
                ->unique(fn ($customer) => (string) $customer->id)
                ->filter(function ($customer): bool {
                    $ppp = $customer->pppUser;

                    return $ppp
                        && (int) $customer->ppp_user_id === (int) $ppp->id
                        && strtolower(trim((string) $customer->status)) === 'active'
                        && strtolower(trim((string) $ppp->status)) === 'active';
                })
                ->values();

            if ($matches->count() !== 1) {
                if ($matches->count() > 1) {
                    $issues[] = "ONU {$mapping->pon_port}/{$mapping->onu_id}: PPP caller MAC {$clientMac} belongs to multiple active customers; no shared link was created.";
                }

                continue;
            }

            $customer = $matches->first();
            $ppp = $customer->pppUser;
            if (! $ppp || ! $customer->id || ! $ppp->id) {
                continue;
            }

            // A customer or PPP account already linked to a different ONU is a
            // physical move/conflict, not a second subscriber on this ONU.
            $otherPrimary = OltOnuCustomerMapping::query()
                ->where('id', '<>', $mapping->id)
                ->where(function ($query) use ($customer, $ppp): void {
                    $query->where('customer_id', $customer->id)
                        ->orWhere('ppp_user_id', $ppp->id);
                })
                ->exists();

            $otherSharedLink = DB::table('olt_onu_customer_ppp_links')
                ->where('ppp_user_id', $ppp->id)
                ->where('status', 'active')
                ->where('olt_onu_customer_mapping_id', '<>', $mapping->id)
                ->exists();

            $otherCustomerLink = DB::table('olt_onu_customer_ppp_links')
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->where('olt_onu_customer_mapping_id', '<>', $mapping->id)
                ->exists();

            if ($otherPrimary || $otherSharedLink || $otherCustomerLink) {
                $issues[] = "ONU {$mapping->pon_port}/{$mapping->onu_id}: customer {$customer->customer_unique_id} / PPP {$ppp->username} is already assigned to another ONU; shared link was not duplicated.";

                continue;
            }

            $result = DB::transaction(function () use ($mapping, $customer, $ppp, $clientMac): string {
                $existing = DB::table('olt_onu_customer_ppp_links')
                    ->where('olt_onu_customer_mapping_id', $mapping->id)
                    ->where('ppp_user_id', $ppp->id)
                    ->lockForUpdate()
                    ->first();

                $now = now();
                $values = [
                    'customer_id' => $customer->id,
                    'client_mac' => implode(':', str_split($clientMac, 2)),
                    'source' => 'olt_dynamic_mac+ppp_last_caller_id',
                    'status' => 'active',
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('olt_onu_customer_ppp_links')
                        ->where('id', $existing->id)
                        ->update($values);

                    return 'refreshed';
                }

                DB::table('olt_onu_customer_ppp_links')->insert($values + [
                    'olt_onu_customer_mapping_id' => $mapping->id,
                    'ppp_user_id' => $ppp->id,
                    'first_seen_at' => $now,
                    'created_at' => $now,
                ]);

                return 'created';
            }, 3);

            if ($result === 'created') {
                $created++;
            } else {
                $refreshed++;
            }
        }

        return [$created, $refreshed, $issues];
    }

    private function readSource(NetworkInventoryDevice $olt, OltReadOnlyAdapterManager $manager): array
    {
        try {
            $result = $manager->read($olt);
        } catch (\Throwable) {
            return $this->readLegacyTelemetry($olt);
        }

        if (strcasecmp((string) $olt->vendor, 'VSOL') !== 0 || empty($result['ok'])) {
            return $result;
        }

        $rows = array_values(array_filter((array) ($result['onus'] ?? []), 'is_array'));
        $missingOpm = count(array_filter($rows, static fn ($row) => ! is_numeric($row['rx_power'] ?? null) || ! is_numeric($row['tx_power'] ?? null)
        ));
        if ($missingOpm === 0) {
            return $result;
        }

        if (! filled($olt->getRawOriginal('enable_password'))) {
            $result['meta']['ssh_opm_status'] = 'credentials_required';
            $result['meta']['ssh_opm_message'] = 'SNMP OPM is partial. SSH CLI OPM fallback is ready but requires a separate CLI enable password in OLT Admin settings.';

            return $result;
        }

        $sshOpm = app(VsolSshOpmCollector::class)->collect($olt, $rows, null, null, false);
        if (! empty($sshOpm['ok'])
            && is_array($sshOpm['meta'] ?? null)
            && ($sshOpm['meta']['read_only'] ?? false) === true
            && ($sshOpm['meta']['writes_performed'] ?? true) === false) {
            $result['onus'] = $sshOpm['onus'];
            $result['meta']['ssh_opm_status'] = 'ok';
            $result['meta']['ssh_opm_message'] = $sshOpm['message'] ?? 'VSOL SSH OPM fallback completed.';
            $result['meta']['ssh_opm'] = $sshOpm['meta'];
            $onuCount = count($result['onus']);
            $rxCount = count(array_filter($result['onus'], static fn ($row) => is_numeric($row['rx_power'] ?? null)));
            $txCount = count(array_filter($result['onus'], static fn ($row) => is_numeric($row['tx_power'] ?? null)));
            $result['meta']['opm_rx_count'] = $rxCount;
            $result['meta']['opm_tx_count'] = $txCount;
            $result['meta']['opm_rx_coverage'] = $onuCount > 0 ? round(($rxCount / $onuCount) * 100, 1) : 0;
            $result['meta']['opm_tx_coverage'] = $onuCount > 0 ? round(($txCount / $onuCount) * 100, 1) : 0;
            // Keep the original inventory status separate from OPM coverage.
            // A partial optical table does not invalidate a complete, read-only ONU inventory;
            // valid per-ONU RX/TX rows must still refresh while missing rows remain untouched.
            $result['meta']['opm_status'] = ($rxCount === $onuCount && $txCount === $onuCount)
                ? 'ok' : (($rxCount + $txCount) > 0 ? 'partial' : 'not_ready');
        } else {
            $result['meta']['ssh_opm_status'] = $sshOpm['status'] ?? 'failed';
            $result['meta']['ssh_opm_message'] = $sshOpm['message'] ?? 'VSOL SSH OPM fallback did not return verified rows.';
        }

        return $result;
    }

    private function readLegacyTelemetry(NetworkInventoryDevice $olt): array
    {
        $url = trim((string) env('OLT_TELEMETRY_URL', ''));
        if ($url === '') {
            return ['ok' => false, 'status' => 'not_configured', 'message' => 'No read-only OLT adapter or OLT_TELEMETRY_URL is configured.', 'onus' => []];
        }

        $endpoint = rtrim($url, '/').'/onu/status?oltId='.urlencode((string) $olt->id);
        try {
            $request = Http::timeout(5)->acceptJson();
            $token = trim((string) env('OLT_TELEMETRY_TOKEN', ''));
            if ($token !== '') {
                $request = $request->withToken($token);
            }
            $response = $request->get($endpoint);
            if (! $response->successful()) {
                return ['ok' => false, 'status' => 'unreachable', 'message' => "Telemetry HTTP {$response->status()}.", 'onus' => []];
            }

            $rows = $response->json();
            if (isset($rows['onus'])) {
                $rows = $rows['onus'];
            }

            return ['ok' => is_array($rows), 'status' => is_array($rows) ? 'ok' : 'invalid_response', 'message' => is_array($rows) ? 'Legacy telemetry read completed.' : 'Telemetry response was not an ONU array.', 'onus' => is_array($rows) ? $rows : []];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 'unreachable', 'message' => 'Legacy OLT telemetry is unavailable; no write performed.', 'onus' => []];
        }
    }

    /**
     * When a stale/removed PPP record and one live account share a caller ID,
     * prefer the single active customer. Multiple active candidates remain
     * ambiguous and are never auto-assigned.
     */
    private function preferActiveCandidates(array $candidates): array
    {
        // Caller ID and last-caller ID may contain the same MAC for one PPP user;
        // deduplicate by actual Customer ID before deciding uniqueness.
        $unique = [];
        foreach ($candidates as $customer) {
            if ($customer && $customer->id !== null) {
                $unique[(string) $customer->id] = $customer;
            }
        }
        $candidates = array_values($unique);
        if (count($candidates) <= 1) {
            return $candidates;
        }

        $active = array_values(array_filter($candidates, static function ($customer): bool {
            return strtolower(trim((string) ($customer->pppUser?->status ?? ''))) === 'active';
        }));

        return count($active) > 0 ? $active : $candidates;
    }

    private function normalizeMac($mac): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $mac) ?? '');
    }

    private function normalizeStatus(array $row): string
    {
        if (array_key_exists('online', $row)) {
            if (in_array($row['online'], [true, 1, '1'], true)) {
                return 'online';
            }
            if (in_array($row['online'], [false, 0, '0'], true)) {
                return 'offline';
            }

            return 'unknown';
        }
        $status = strtolower(trim((string) ($row['status'] ?? 'unknown')));
        if (in_array($status, ['1', 'online', 'up', 'active', 'registered'], true)) {
            return 'online';
        }
        if (in_array($status, ['2', '4', 'offline', 'down', 'inactive', 'unregistered', 'deregistered', 'lost'], true)) {
            return 'offline';
        }
        if (in_array($status, ['0', '3', '5', 'authenticated', 'auto_config', 'standby', 'unknown'], true)) {
            return 'unknown';
        }

        return $status !== '' ? $status : 'unknown';
    }
}
