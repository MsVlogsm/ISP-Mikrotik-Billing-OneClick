<?php

namespace App\Console\Commands;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutoMapOltCustomers extends Command
{
    protected $signature = 'olt:auto-map-customers
                            {--device= : OLT device ID}
                            {--apply : Save only unique, verified Customer/PPPoE matches; default is dry-run}';

    protected $description = 'Auto-map ONU inventory to Customers only through unique exact PPPoE client-MAC evidence';

    public function handle(OltReadOnlyAdapterManager $manager): int
    {
        $customerCandidates = CustomersInfo::query()
            ->where('status', 'active')
            ->whereNotNull('ppp_user_id')
            ->with('pppUser')
            ->get()
            ->filter(fn (CustomersInfo $customer) =>
                $customer->pppUser
                && strtolower(trim((string) $customer->pppUser->status)) === 'active'
                && trim((string) $customer->customer_unique_id) !== ''
            )
            ->values();

        if ($customerCandidates->isEmpty()) {
            $this->warn('No active Customer → active PPPoE links are available. Nothing was mapped; the next scheduled run will retry.');
            return self::SUCCESS;
        }

        $pppByMac = [];
        foreach ($customerCandidates as $customer) {
            $ppp = $customer->pppUser;
            foreach ([$ppp->caller_id, $ppp->last_caller_id] as $callerMac) {
                $mac = $this->normalizeMac($callerMac);
                if ($mac === '') {
                    continue;
                }

                $pppByMac[$mac][$customer->id.':'.$ppp->id] = [
                    'customer_id' => (int) $customer->id,
                    'ppp_user_id' => (int) $ppp->id,
                    'customer_unique_id' => (string) $customer->customer_unique_id,
                    'pppoe_username' => (string) $ppp->username,
                    'customer_status' => (string) $customer->status,
                    'ppp_status' => (string) $ppp->status,
                ];
            }
        }

        if ($pppByMac === []) {
            $this->warn('Active Customer/PPPoE links exist, but none has a valid caller_id or last_caller_id MAC. Nothing was mapped.');
            return self::SUCCESS;
        }

        $usedCustomerIds = OltOnuCustomerMapping::query()
            ->whereNotNull('customer_id')->pluck('customer_id')->map(fn ($id) => (string) $id)->flip()->all();
        $usedPppIds = OltOnuCustomerMapping::query()
            ->whereNotNull('ppp_user_id')->pluck('ppp_user_id')->map(fn ($id) => (string) $id)->flip()->all();

        $devices = NetworkInventoryDevice::query()
            ->where('type', 'olt')
            ->where('is_active', true)
            ->when($this->option('device'), fn ($query) => $query->whereKey((int) $this->option('device')))
            ->orderBy('id')
            ->get();

        if ($devices->isEmpty()) {
            $this->warn('No active OLT devices matched; no mappings were changed.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $totals = ['mapped' => 0, 'would_map' => 0, 'identity_conflicts' => 0, 'duplicate_onu_macs' => 0,
            'ambiguous' => 0, 'already_assigned' => 0, 'no_exact_match' => 0, 'poll_failures' => 0];

        foreach ($devices as $device) {
            try {
                $result = $manager->read($device);
            } catch (Throwable $exception) {
                $totals['poll_failures']++;
                $this->error("OLT #{$device->id}: read-only poll failed: {$exception->getMessage()}");
                continue;
            }

            $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            $rows = is_array($result['onus'] ?? null) ? $result['onus'] : [];

            // Require a complete and explicitly read-only poll before any matching or write.
            if (($result['ok'] ?? false) !== true
                || ($result['status'] ?? null) !== 'ok'
                || ($meta['read_only'] ?? false) !== true
                || !array_key_exists('writes_performed', $meta)
                || $meta['writes_performed'] !== false
                || $rows === []
                || (isset($meta['onu_count']) && (int) $meta['onu_count'] !== count($rows))) {
                $totals['poll_failures']++;
                $this->error("OLT #{$device->id}: incomplete/unverified poll; no Customer mapping was attempted.");
                continue;
            }

            $mappingRows = OltOnuCustomerMapping::query()
                ->where('olt_device_id', $device->id)
                ->get()
                ->keyBy(fn (OltOnuCustomerMapping $mapping) => $this->identityKey($mapping->pon_port, $mapping->onu_id));

            $live = [];
            $onuMacCounts = [];
            $clientMacOnuKeys = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $live = [];
                    break;
                }

                $pon = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
                $onuId = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? ''));
                if ($pon === '' || $onuId === '') {
                    $live = [];
                    break;
                }

                $key = $this->identityKey($pon, $onuId);
                $mac = $this->normalizeMac($row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null);
                $serial = strtolower(trim((string) ($row['onu_serial'] ?? $row['serial'] ?? $row['onuSerial'] ?? '')));
                $clientMacs = collect(is_array($row['client_macs'] ?? null) ? $row['client_macs'] : [])
                    ->map(fn ($value) => $this->normalizeMac($value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $live[$key] = [
                    'key' => $key, 'pon_port' => $pon, 'onu_id' => $onuId,
                    'onu_mac' => $mac, 'onu_serial' => $serial, 'client_macs' => $clientMacs,
                    'status' => $this->normalizeStatus($row),
                ];

                if ($mac !== '') {
                    $onuMacCounts[$mac] = ($onuMacCounts[$mac] ?? 0) + 1;
                }
                foreach ($clientMacs as $clientMac) {
                    $clientMacOnuKeys[$clientMac][$key] = true;
                }
            }

            if ($live === [] || count($live) !== count($rows)) {
                $totals['poll_failures']++;
                $this->error("OLT #{$device->id}: invalid or duplicate live PON/ONU identity; no Customer mapping was attempted.");
                continue;
            }

            foreach ($live as $onu) {
                $mapping = $mappingRows->get($onu['key']);
                if (!$mapping) {
                    $totals['no_exact_match']++;
                    continue;
                }

                // Never attach a Customer to an ONU whose current physical identity conflicts
                // with the saved OLT+PON+ONU record. This protects the six known conflict rows.
                $storedMac = $this->normalizeMac($mapping->onu_mac);
                $storedSerial = strtolower(trim((string) $mapping->onu_serial));
                $identityConflict = ($storedMac !== '' && $onu['onu_mac'] !== '' && $storedMac !== $onu['onu_mac'])
                    || ($storedSerial !== '' && $onu['onu_serial'] !== '' && $storedSerial !== $onu['onu_serial'])
                    || str_contains(strtolower((string) $mapping->notes), 'manual review required')
                    || str_contains(strtolower((string) $mapping->notes), 'stable onu identity changed');

                if ($identityConflict) {
                    $totals['identity_conflicts']++;
                    $this->line("BLOCKED identity conflict: PON {$onu['pon_port']} / ONU {$onu['onu_id']} (existing mapping unchanged).");
                    continue;
                }

                if ($mapping->customer_id !== null || $mapping->ppp_user_id !== null) {
                    $totals['already_assigned']++;
                    continue;
                }

                // Only online ONUs have a current client-MAC/FDB observation suitable for matching.
                if ($onu['status'] !== 'online') {
                    $totals['no_exact_match']++;
                    continue;
                }

                // Duplicated live ONU MACs are never used as an automatic identity source.
                if ($onu['onu_mac'] !== '' && ($onuMacCounts[$onu['onu_mac']] ?? 0) > 1) {
                    $totals['duplicate_onu_macs']++;
                    continue;
                }

                $candidatePairs = [];
                foreach ($onu['client_macs'] as $clientMac) {
                    // A customer-device MAC visible behind multiple ONUs is not a unique match.
                    if (count($clientMacOnuKeys[$clientMac] ?? []) !== 1) {
                        continue;
                    }
                    foreach ($pppByMac[$clientMac] ?? [] as $pairKey => $candidate) {
                        $candidatePairs[$pairKey] = $candidate;
                    }
                }

                $candidatePairs = array_values($candidatePairs);
                if (count($candidatePairs) !== 1) {
                    if (count($candidatePairs) > 1) {
                        $totals['ambiguous']++;
                        $this->line("BLOCKED ambiguous PPPoE caller MACs: PON {$onu['pon_port']} / ONU {$onu['onu_id']}.");
                    } else {
                        $totals['no_exact_match']++;
                    }
                    continue;
                }

                $candidate = $candidatePairs[0];
                if (isset($usedCustomerIds[(string) $candidate['customer_id']])
                    || isset($usedPppIds[(string) $candidate['ppp_user_id']])) {
                    $totals['already_assigned']++;
                    continue;
                }

                if (!$apply) {
                    $totals['would_map']++;
                    $this->line("WOULD MAP PON {$onu['pon_port']} / ONU {$onu['onu_id']} → {$candidate['customer_unique_id']} ({$candidate['pppoe_username']}) by exact FDB/PPPoE caller MAC.");
                    $usedCustomerIds[(string) $candidate['customer_id']] = true;
                    $usedPppIds[(string) $candidate['ppp_user_id']] = true;
                    continue;
                }

                $mapped = $this->applyExactMapping($mapping->id, $candidate, $onu);
                if ($mapped) {
                    $totals['mapped']++;
                    $usedCustomerIds[(string) $candidate['customer_id']] = true;
                    $usedPppIds[(string) $candidate['ppp_user_id']] = true;
                    $this->info("Mapped PON {$onu['pon_port']} / ONU {$onu['onu_id']} → {$candidate['customer_unique_id']} using exact PPPoE caller MAC.");
                } else {
                    $totals['already_assigned']++;
                }
            }
        }

        $this->newLine();
        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' Customer auto-mapping: '
            .'mapped '.$totals['mapped']
            .', would map '.$totals['would_map']
            .', identity conflicts '.$totals['identity_conflicts']
            .', duplicate ONU MACs '.$totals['duplicate_onu_macs']
            .', ambiguous '.$totals['ambiguous']
            .', already assigned/reused '.$totals['already_assigned']
            .', no exact match '.$totals['no_exact_match']
            .', poll failures '.$totals['poll_failures'].'.');

        return $totals['poll_failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function applyExactMapping(int $mappingId, array $candidate, array $onu): bool
    {
        return DB::transaction(function () use ($mappingId, $candidate, $onu): bool {
            $mapping = OltOnuCustomerMapping::query()->lockForUpdate()->find($mappingId);
            if (!$mapping || $mapping->customer_id !== null || $mapping->ppp_user_id !== null) {
                return false;
            }

            $customer = CustomersInfo::query()->lockForUpdate()->find($candidate['customer_id']);
            $ppp = PPPSecrets::query()->lockForUpdate()->find($candidate['ppp_user_id']);

            $storedMac = $this->normalizeMac($mapping->onu_mac);
            $storedSerial = strtolower(trim((string) $mapping->onu_serial));
            if (($storedMac !== '' && $onu['onu_mac'] !== '' && $storedMac !== $onu['onu_mac'])
                || ($storedSerial !== '' && $onu['onu_serial'] !== '' && $storedSerial !== $onu['onu_serial'])
                || str_contains(strtolower((string) $mapping->notes), 'manual review required')
                || str_contains(strtolower((string) $mapping->notes), 'stable onu identity changed')) {
                return false;
            }

            if (!$customer || !$ppp
                || strtolower(trim((string) $customer->status)) !== 'active'
                || strtolower(trim((string) $ppp->status)) !== 'active'
                || (int) $customer->ppp_user_id !== (int) $ppp->id
                || trim((string) $customer->customer_unique_id) !== $candidate['customer_unique_id']) {
                return false;
            }

            $alreadyUsed = OltOnuCustomerMapping::query()
                ->where('id', '<>', $mapping->id)
                ->where(function ($query) use ($customer, $ppp) {
                    $query->where('customer_id', $customer->id)->orWhere('ppp_user_id', $ppp->id);
                })
                ->exists();

            if ($alreadyUsed) {
                return false;
            }

            $before = ['customer_id' => null, 'ppp_user_id' => null];
            $mapping->customer_id = $customer->id;
            $mapping->ppp_user_id = $ppp->id;

            $note = 'Auto-mapped by exact live ONU FDB client MAC ↔ PPPoE caller_id/last_caller_id match: '
                .$candidate['pppoe_username'].'; verified '.now()->toIso8601String().'.';
            $existingNotes = trim((string) $mapping->notes);
            if (!str_contains($existingNotes, $note)) {
                $mapping->notes = trim($existingNotes.($existingNotes !== '' ? "\n" : '').$note);
            }
            $mapping->save();

            DB::table('fiber_mapping_audits')->insert([
                'entity_type' => 'onu_mapping',
                'entity_id' => $mapping->id,
                'action' => 'auto_customer_mapped',
                'before_data' => json_encode($before),
                'after_data' => json_encode([
                    'customer_id' => $customer->id,
                    'customer_unique_id' => $customer->customer_unique_id,
                    'ppp_user_id' => $ppp->id,
                    'pppoe_username' => $ppp->username,
                    'identity' => ['pon_port' => $onu['pon_port'], 'onu_id' => $onu['onu_id'], 'onu_mac' => $onu['onu_mac']],
                ]),
                'note' => 'Unique exact FDB client MAC matched active PPPoE caller MAC; automatic assignment only.',
                'operator_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function identityKey(?string $pon, ?string $onuId): string
    {
        return strtolower(trim((string) $pon)).'|'.strtolower(trim((string) $onuId));
    }

    private function normalizeMac(mixed $value): string
    {
        return strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $value) ?? '');
    }

    private function normalizeStatus(array $row): string
    {
        if (array_key_exists('online', $row)) {
            return in_array($row['online'], [true, 1, '1'], true) ? 'online' : 'offline';
        }

        $status = strtolower(trim((string) ($row['status'] ?? 'unknown')));
        if (in_array($status, ['1', 'online', 'up', 'active', 'registered'], true)) {
            return 'online';
        }
        if (in_array($status, ['2', '4', 'offline', 'down', 'inactive', 'unregistered', 'deregistered', 'lost'], true)) {
            return 'offline';
        }

        return 'unknown';
    }
}
