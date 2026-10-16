<?php

namespace App\Console\Commands;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\PPPSecrets;
use Illuminate\Console\Command;

class ValidateVsolMapping extends Command
{
    protected $signature = 'olt:validate-onu-mapping {--device=3}';

    protected $description = 'Read-only validation of live ONU MAC matches against PPP caller IDs';

    public function handle(): int
    {
        $device = NetworkInventoryDevice::findOrFail((int) $this->option('device'));
        $result = app(\App\Services\Olt\OltReadOnlyAdapterManager::class)->read($device);
        $normalizeMac = fn ($mac) => strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $mac) ?? '');
        $macs = collect($result['onus'] ?? [])
            ->pluck('onu_mac')->filter()
            ->map($normalizeMac)->filter()->unique()->values();

        $rows = PPPSecrets::query()->whereNotNull('last_caller_id')
            ->get(['id', 'last_caller_id', 'status'])
            ->filter(fn ($row) => $macs->contains($normalizeMac($row->last_caller_id)));

        $groups = $rows->groupBy(fn ($row) => $normalizeMac($row->last_caller_id));
        $duplicateGroups = $groups->filter(fn ($group) => $group->count() > 1)->count();
        $uniqueRows = $groups->filter(fn ($group) => $group->count() === 1)->flatten(1);
        $activeRows = $rows->filter(fn ($row) => strtolower(trim((string) $row->status)) === 'active');
        $activeGroups = $activeRows->groupBy(fn ($row) => $normalizeMac($row->last_caller_id));
        $activeDuplicateGroups = $activeGroups->filter(fn ($group) => $group->count() > 1)->count();
        $uniqueActiveRows = $activeGroups->filter(fn ($group) => $group->count() === 1)->flatten(1);
        $uniqueActiveWithOneCustomer = $uniqueActiveRows->filter(fn ($row) => CustomersInfo::where('ppp_user_id', $row->id)->count() === 1)->count();
        $uniqueActiveWithoutCustomer = $uniqueActiveRows->filter(fn ($row) => CustomersInfo::where('ppp_user_id', $row->id)->count() === 0)->count();

        $this->table(['Metric', 'Value'], [
            ['Live ONU count with MAC', $macs->count()],
            ['Exact caller-ID MAC match records (including stale accounts)', $rows->count()],
            ['MACs duplicated across all PPP records', $duplicateGroups],
            ['Unique MAC-match records across all PPP statuses', $uniqueRows->count()],
            ['Active PPP records with exact ONU MAC match', $activeRows->count()],
            ['MACs still duplicated among active PPP records', $activeDuplicateGroups],
            ['Unique active matches with exactly one customer', $uniqueActiveWithOneCustomer],
            ['Unique active matches without a customer', $uniqueActiveWithoutCustomer],
            ['Saved mappings currently', \App\Models\OltOnuCustomerMapping::where('olt_device_id', $device->id)->count()],
        ]);
        $this->warn('Read-only check only: a MAC match does not by itself prove the ONU-to-customer relationship. No database writes were performed.');

        return self::SUCCESS;
    }
}
