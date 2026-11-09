<?php

namespace App\Services\Olt;

use InvalidArgumentException;

class OnuMappingIdentityReconciler
{
    public function plan(array $liveOnus, iterable $mappings): array
    {
        $liveRowsByMac = [];
        $liveById = [];
        foreach ($liveOnus as $row) {
            $onuId = trim((string) ($row['onu_id'] ?? ''));
            $mac = self::normalizeMac($row['onu_mac'] ?? null);
            $pon = trim((string) ($row['pon_port'] ?? ''));
            if ($onuId === '' || $mac === null || $pon === '') {
                throw new InvalidArgumentException('Live ONU data is missing a valid ONU ID, MAC, or PON port.');
            }
            $identityKey = $pon.'|'.$onuId;
            if (isset($liveById[$identityKey])) throw new InvalidArgumentException("Live SNMP returned duplicate PON/ONU identity {$identityKey}.");
            $row['onu_id'] = $onuId;
            $row['pon_port'] = $pon;
            $row['onu_mac'] = $mac;
            $status = strtolower(trim((string) ($row['status'] ?? 'unknown')));
            $row['status'] = in_array($status, ['online', 'offline'], true) ? $status : 'unknown';
            $liveRowsByMac[$mac][] = $row;
            $liveById[$identityKey] = true;
        }
        $liveByMac = [];
        $ambiguousLive = [];
        foreach ($liveRowsByMac as $mac => $group) {
            if (count($group) > 1) {
                $ambiguousLive[$mac] = $group;
                continue;
            }
            $liveByMac[$mac] = $group[0];
        }
        if (!$liveByMac && !$ambiguousLive) throw new InvalidArgumentException('Live SNMP returned no valid ONU identities.');

        $mappingByMac = [];
        $mappingBySlot = [];
        $stale = [];
        $archived = [];
        $ambiguous = [];
        $ambiguousNew = [];
        $blockers = [];
        foreach ($mappings as $mapping) {
            $data = is_array($mapping) ? $mapping : $mapping->getAttributes();
            $mac = self::normalizeMac($data['onu_mac'] ?? null);
            $rowId = (int) ($data['id'] ?? 0);
            if ($rowId <= 0) throw new InvalidArgumentException('Stored ONU mapping has no primary key.');
            $slotKey = trim((string) ($data['pon_port'] ?? '')).'|'.trim((string) ($data['onu_id'] ?? ''));
            if ($slotKey !== '|') {
                if (isset($mappingBySlot[$slotKey])) {
                    throw new InvalidArgumentException("Stored duplicate PON/ONU identity {$slotKey}.");
                }
                $mappingBySlot[$slotKey] = $data;
            }
            if (str_starts_with((string) ($data['onu_id'] ?? ''), '__stale_')) {
                $archived[] = [
                    'mapping_id' => $rowId,
                    'current_pon_port' => (string) ($data['pon_port'] ?? ''),
                    'current_onu_id' => (string) ($data['onu_id'] ?? ''),
                    'onu_mac' => $mac,
                    'linked' => !empty($data['customer_id']) || !empty($data['ppp_user_id']),
                ];
                continue;
            }
            if ($mac !== null && isset($ambiguousLive[$mac])) {
                $ambiguous[] = [
                    'mapping_id' => $rowId,
                    'current_pon_port' => (string) ($data['pon_port'] ?? ''),
                    'current_onu_id' => (string) ($data['onu_id'] ?? ''),
                    'onu_mac' => $mac,
                    'linked' => !empty($data['customer_id']) || !empty($data['ppp_user_id']),
                    'live_locations' => array_map(
                        static fn ($row) => ['pon_port' => $row['pon_port'], 'onu_id' => $row['onu_id'], 'status' => $row['status']],
                        $ambiguousLive[$mac]
                    ),
                ];
                continue;
            }
            if ($mac !== null && isset($liveByMac[$mac])) {
                if (isset($mappingByMac[$mac])) throw new InvalidArgumentException("Stored ONU MAC {$mac} occurs in multiple mapping rows.");
                $mappingByMac[$mac] = $data;
                continue;
            }
            $linked = !empty($data['customer_id']) || !empty($data['ppp_user_id']);
            $stale[] = ['mapping_id' => $rowId, 'current_onu_id' => (string) ($data['onu_id'] ?? ''), 'onu_mac' => $mac, 'linked' => $linked];
            // Never archive/rekey a stale identity automatically while a customer
            // or PPPoE account is attached; manual identity review is required.
            if ($linked) {
                $blockers[] = "Stale mapping row {$rowId} has a customer/PPPoE link; verify its live identity before applying reconciliation.";
            }
        }

        foreach ($ambiguousLive as $mac => $group) {
            foreach ($group as $live) {
                $slotKey = $live['pon_port'].'|'.$live['onu_id'];
                $storedAtSlot = $mappingBySlot[$slotKey] ?? null;
                if ($storedAtSlot) {
                    $storedMac = self::normalizeMac($storedAtSlot['onu_mac'] ?? null);
                    if ($storedMac !== $mac) {
                        $blockers[] = "Live ambiguous MAC {$mac} targets {$slotKey}, but a different stored ONU identity occupies that slot.";
                    }
                    continue;
                }
                $ambiguousNew[] = [
                    'onu_id' => $live['onu_id'],
                    'onu_mac' => $mac,
                    'pon_port' => $live['pon_port'],
                    'status' => $live['status'],
                ];
            }
        }

        $matched = [];
        $new = [];
        foreach ($liveByMac as $mac => $live) {
            if (isset($mappingByMac[$mac])) {
                $stored = $mappingByMac[$mac];
                $currentMac = self::normalizeMac($stored['onu_mac'] ?? null);
                $currentId = (string) ($stored['onu_id'] ?? '');
                $currentPon = trim((string) ($stored['pon_port'] ?? ''));
                $currentStatus = strtolower(trim((string) ($stored['status'] ?? '')));
                $matched[] = [
                    'mapping_id' => (int) $stored['id'],
                    'current_onu_id' => $currentId,
                    'target_onu_id' => $live['onu_id'],
                    'onu_mac' => $mac,
                    'pon_port' => $live['pon_port'],
                    'status' => $live['status'],
                    'changed' => $currentId !== $live['onu_id'] || $currentMac !== $mac || $currentPon !== $live['pon_port'] || $currentStatus !== $live['status'],
                ];
            } else {
                $new[] = ['onu_id' => $live['onu_id'], 'onu_mac' => $mac, 'pon_port' => $live['pon_port'], 'status' => $live['status']];
            }
        }
        $targetIds = [];
        $targetRows = array_merge(
            array_map(static fn ($r) => [(string) $r['pon_port'], (string) $r['target_onu_id']], $matched),
            array_map(static fn ($r) => [(string) $r['pon_port'], (string) $r['onu_id']], $new)
        );
        foreach ($targetRows as [$pon, $id]) {
            $identityKey = trim($pon).'|'.trim($id);
            if (isset($targetIds[$identityKey])) throw new InvalidArgumentException("Duplicate live target PON/ONU identity {$identityKey}.");
            $targetIds[$identityKey] = true;
        }
        foreach ($ambiguous as $row) {
            $heldKey = trim($row['current_pon_port']).'|'.trim($row['current_onu_id']);
            if (isset($targetIds[$heldKey])) {
                $blockers[] = "Ambiguous MAC {$row['onu_mac']} is still stored at {$heldKey}, which is also a live target; no changes can be applied until this slot is manually resolved.";
            }
        }
        foreach ($ambiguousNew as $row) {
            $identityKey = trim($row['pon_port']).'|'.trim($row['onu_id']);
            if (isset($targetIds[$identityKey])) {
                $blockers[] = "Ambiguous inventory target {$identityKey} conflicts with a unique-MAC target.";
            }
            $targetIds[$identityKey] = true;
        }
        return [
            'matched' => $matched,
            'stale' => $stale,
            'archived' => $archived,
            'new' => $new,
            'ambiguous' => $ambiguous,
            'ambiguous_new' => $ambiguousNew,
            'blockers' => $blockers,
        ];
    }

    public static function normalizeMac(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) return null;
        $hex = preg_replace('/[^0-9a-f]/i', '', (string) $value);
        if (!is_string($hex) || strlen($hex) !== 12 || !ctype_xdigit($hex)) return null;
        return strtolower(implode(':', str_split($hex, 2)));
    }
}
