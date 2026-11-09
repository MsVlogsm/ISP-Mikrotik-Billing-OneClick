<?php

namespace App\Console\Commands;

use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Console\Command;
use Throwable;

class DiscoverOltOnus extends Command
{
    protected $signature = 'olt:discover-onus
                            {--device= : Discover one OLT device ID only}
                            {--dry-run : Read OLT inventory without inserting new ONU inventory rows}';

    protected $description = 'Discover read-only OLT ONU inventory without changing existing Customer or PPPoE mappings';

    public function handle(OltReadOnlyAdapterManager $manager): int
    {
        $query = NetworkInventoryDevice::query()
            ->where('type', 'olt')
            ->where('is_active', true);

        if ($this->option('device')) {
            $query->whereKey((int) $this->option('device'));
        }

        $devices = $query->orderBy('id')->get();
        if ($devices->isEmpty()) {
            $this->warn('No active OLT devices matched; no database changes were made.');
            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($devices as $device) {
            try {
                $result = $manager->read($device);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("OLT #{$device->id}: read-only discovery failed: {$exception->getMessage()}");
                continue;
            }

            $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            $rows = is_array($result['onus'] ?? null) ? $result['onus'] : [];

            // Fail closed: never write from a partial, unverified, or empty SNMP poll.
            if (($result['ok'] ?? false) !== true
                || ($result['status'] ?? null) !== 'ok'
                || ($meta['read_only'] ?? false) !== true
                || !array_key_exists('writes_performed', $meta)
                || $meta['writes_performed'] !== false
                || $rows === []
                || (isset($meta['onu_count']) && (int) $meta['onu_count'] !== count($rows))) {
                $failed++;
                $failedOids = implode(',', array_slice(array_map('strval', (array) ($meta['failed_oids'] ?? [])), 0, 8));
                $this->error("OLT #{$device->id}: poll rejected; status=".($result['status'] ?? 'unknown')
                    .", ok=".((($result['ok'] ?? false) === true) ? 'yes' : 'no')
                    .", rows=".count($rows)."/".(int) ($meta['onu_count'] ?? 0)
                    .", read_only=".((($meta['read_only'] ?? false) === true) ? 'yes' : 'no')
                    .", writes_performed=".var_export($meta['writes_performed'] ?? null, true)
                    .", failed_oids=".($failedOids !== '' ? $failedOids : 'none')
                    .", message=".($result['message'] ?? 'no message')
                    ."; no ONU rows were inserted.");
                continue;
            }

            $normalized = [];
            $identityCounts = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $failed++;
                    $normalized = [];
                    $this->error("OLT #{$device->id}: malformed ONU row found; refusing the entire poll to avoid partial inventory writes.");
                    break;
                }

                $onuId = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? ''));
                $ponPort = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));

                if ($onuId === '' || $ponPort === '') {
                    $failed++;
                    $normalized = [];
                    $this->error("OLT #{$device->id}: ONU identity is missing PON or ONU ID; refusing the entire poll.");
                    break;
                }

                $key = strtolower($ponPort).'|'.strtolower($onuId);
                $identityCounts[$key] = ($identityCounts[$key] ?? 0) + 1;

                $mac = $this->normalizeMac($row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null);
                $serial = trim((string) ($row['onu_serial'] ?? $row['serial'] ?? $row['onuSerial'] ?? ''));

                $normalized[$key] = [
                    'pon_port' => $ponPort,
                    'onu_id' => $onuId,
                    'onu_mac' => $mac,
                    'onu_serial' => $serial !== '' ? $serial : null,
                    'onu_type' => $row['onu_type'] ?? $row['type'] ?? $row['onu_model'] ?? null,
                    'status' => $this->normalizeStatus($row),
                    'rx_power' => is_numeric($row['rx_power'] ?? $row['opticalRx'] ?? null) ? (float) ($row['rx_power'] ?? $row['opticalRx']) : null,
                    'tx_power' => is_numeric($row['tx_power'] ?? $row['opticalTx'] ?? null) ? (float) ($row['tx_power'] ?? $row['opticalTx']) : null,
                    'distance_m' => is_numeric($row['distance_m'] ?? $row['distance'] ?? null) ? (float) ($row['distance_m'] ?? $row['distance']) : null,
                    'onu_ip' => $row['onu_ip'] ?? $row['ip'] ?? $row['onuIp'] ?? null,
                ];
            }

            if ($normalized === [] || count(array_filter($identityCounts, static fn ($count) => $count !== 1)) > 0) {
                $failed++;
                $this->error("OLT #{$device->id}: duplicate or invalid PON+ONU identities found; no inventory rows were inserted.");
                continue;
            }

            $macCounts = [];
            foreach ($normalized as $onu) {
                if ($onu['onu_mac']) {
                    $macCounts[$onu['onu_mac']] = ($macCounts[$onu['onu_mac']] ?? 0) + 1;
                }
            }
            $duplicateMacGroups = count(array_filter($macCounts, static fn ($count) => $count > 1));

            $created = 0;
            $existingCount = 0;
            $conflicts = 0;

            foreach ($normalized as $onu) {
                $identity = OltOnuCustomerMapping::query()
                    ->where('olt_device_id', $device->id)
                    ->where('pon_port', $onu['pon_port'])
                    ->where('onu_id', $onu['onu_id'])
                    ->first();

                if ($identity) {
                    $macConflict = $identity->onu_mac && $onu['onu_mac']
                        && $this->normalizeMac($identity->onu_mac) !== $onu['onu_mac'];
                    $serialConflict = $identity->onu_serial && $onu['onu_serial']
                        && strcasecmp(trim((string) $identity->onu_serial), $onu['onu_serial']) !== 0;

                    if ($macConflict || $serialConflict) {
                        $conflicts++;
                        $this->warn("PON {$onu['pon_port']} / ONU {$onu['onu_id']}: stored MAC/Serial differs from live identity; existing row and Customer mapping were left untouched.");
                    } else {
                        $existingCount++;
                    }
                    continue;
                }

                if ($this->option('dry-run')) {
                    $created++;
                    $this->line("Would add PON {$onu['pon_port']} / ONU {$onu['onu_id']} / ".($onu['onu_mac'] ?: 'MAC unavailable'));
                    continue;
                }

                // Insert-only: the unique OLT+PON+ONU key makes repeat runs idempotent.
                // Customer and PPPoE foreign keys are explicitly null for newly discovered inventory.
                $newRow = OltOnuCustomerMapping::query()->firstOrCreate(
                    [
                        'olt_device_id' => $device->id,
                        'pon_port' => $onu['pon_port'],
                        'onu_id' => $onu['onu_id'],
                    ],
                    [
                        'customer_id' => null,
                        'ppp_user_id' => null,
                        'onu_mac' => $onu['onu_mac'],
                        'onu_serial' => $onu['onu_serial'],
                        'onu_type' => $onu['onu_type'],
                        'status' => $onu['status'],
                        'rx_power' => $onu['rx_power'],
                        'tx_power' => $onu['tx_power'],
                        'distance_m' => $onu['distance_m'],
                        'onu_ip' => $onu['onu_ip'],
                        'last_seen_at' => $onu['status'] === 'online' ? now() : null,
                        'notes' => 'Automatically discovered from verified read-only OLT polling. Customer/PPPoE mapping is separate and was not assigned.',
                    ]
                );

                if ($newRow->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existingCount++;
                }
            }

            $mode = $this->option('dry-run') ? 'DRY-RUN' : 'INSERT-ONLY';
            $this->info("OLT #{$device->id}: {$mode}; discovered ".count($normalized)." ONU identities; added {$created}; already present {$existingCount}; identity conflicts {$conflicts}; duplicate live-MAC groups {$duplicateMacGroups}.");

            if ($duplicateMacGroups > 0) {
                $this->warn("OLT #{$device->id}: duplicate live MAC groups were kept as separate PON+ONU inventory entries; no Customer was auto-mapped.");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function normalizeMac(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $hex = preg_replace('/[^a-fA-F0-9]/', '', $value) ?? '';
        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split(strtolower($hex), 2));
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
