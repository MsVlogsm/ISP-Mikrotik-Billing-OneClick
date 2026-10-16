<?php

namespace App\Livewire;

use App\Http\Controllers\MikrotikController;
use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\OfficialInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MikrotikClients extends Component
{
    use WithPagination;

    public string $router = '';
    public string $protocol = 'PPPOE';
    public string $profile = '';
    public string $userType = 'Unique';
    public string $search = '';
    public int $perPage = 100;

    public function render()
    {
        abort_unless(hasAccess(['Super Admin'], ['all-customer']), 403);

        // Import From MikroTik must only expose routers that are explicitly connected.
        // A disconnected/disabled router may still have historical PPPSecrets in the local DB,
        // but those records must never appear as an import source.
        $routers = RouterList::query()->where('action', 'connected')->orderBy('router_name')->get();
        $profiles = PPPSecrets::query()
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('router_lists')
                    ->whereColumn('router_lists.router_name', 'p_p_p_secrets.router_name')
                    ->where('router_lists.action', 'connected');
            })
            ->when($this->router, fn ($q) => $q->where('router_name', $this->router))
            ->whereNotNull('profile')->where('profile', '!=', '')->distinct()->orderBy('profile')->pluck('profile');

        $clients = PPPSecrets::query()->with('customer')
            // Manual Client List export: once a PPP secret is exported and linked,
            // remove it from this pending MikroTik import/sync list. Never delete the PPP secret.
            ->whereDoesntHave('customer')
            // Hard gate: local PPP records are importable only while their router is connected.
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('router_lists')
                    ->whereColumn('router_lists.router_name', 'p_p_p_secrets.router_name')
                    ->where('router_lists.action', 'connected');
            })
            ->when($this->router, fn ($q) => $q->where('router_name', $this->router))
            ->when($this->protocol, fn ($q) => $q->whereRaw('upper(service) = ?', [strtoupper($this->protocol)]))
            ->when($this->profile, fn ($q) => $q->where('profile', $this->profile))
            ->when($this->userType === 'Unique', fn ($q) => $q->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', ['duplicate', 'disabled_duplicate']);
            }))
            ->when($this->search, function ($q) {
                $s = '%'.addcslashes($this->search, '%_').'%';
                $q->where(function ($q) use ($s) {
                    $q->where('username', 'like', $s)->orWhere('caller_id', 'like', $s)->orWhere('router_name', 'like', $s);
                });
            })
            ->orderBy('username')->paginate($this->perPage);

        return view('livewire.mikrotik-clients', compact('routers', 'profiles', 'clients'))->layout('layouts.app');
    }

    public function updated($property): void
    {
        if (in_array($property, ['router', 'protocol', 'profile', 'userType', 'search', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['router', 'profile', 'search']);
        $this->protocol = 'PPPOE';
        $this->userType = 'Unique';
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        abort_unless(hasAccess(['Super Admin'], ['enable-pending-customer']), 403);
        $secret = PPPSecrets::findOrFail($id);
        $router = RouterList::where('router_name', $secret->router_name)->first();
        if (! $router || $router->action !== 'connected') {
            flash()->warning("Router {$secret->router_name} is disabled/disconnected. Network action blocked.");
            return;
        }
        $disabled = in_array(strtolower((string) $secret->status), ['disabled', 'inactive'], true);
        app(MikrotikController::class)->togglePPPSecret($secret->customer?->customer_unique_id ?? $secret->username, $secret->router_name, $secret->username, $disabled ? 'enable' : 'disable');
        $secret->status = $disabled ? 'enabled' : 'disabled';
        $secret->save();
    }

    public function exportExcel()
    {
        $rows = $this->exportRows();
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $headers = ['Name','Password','Service','Profile','Caller ID','Server Name','Logout Time','User Status','Branch'];
        $ws->fromArray([$headers, ...$rows], null, 'A1');
        foreach (range('A', 'I') as $col) $ws->getColumnDimension($col)->setAutoSize(true);
        $file = 'mikrotik-clients-'.now()->format('Ymd-His').'.xlsx';
        return response()->streamDownload(function () use ($sheet) { (new Xlsx($sheet))->save('php://output'); }, $file);
    }

    public function exportToClientList(?int $id = null): void
    {
        abort_unless(hasAccess(['Super Admin'], ['all-customer']), 403);

        if ($id !== null) {
            $secret = PPPSecrets::with('customer')->findOrFail($id);
            $router = RouterList::where('router_name', $secret->router_name)->first();
            if (! $router || $router->action !== 'connected') {
                flash()->warning("Router {$secret->router_name} is disabled/disconnected. Import is unavailable.");
                return;
            }
            if ($secret->customer) {
                flash()->info("{$secret->username} is already in Client List.");
                return;
            }
            $this->redirectRoute('new-customer', ['mikrotik_client' => $secret->id]);
            return;
        }

        // Customer Unique Id is manual-only. Bulk export must never invent an ID.
        // Use the single-client flow so the operator can enter the Customer Unique Id manually.
        $secrets = $this->filteredSecretsQuery()->with('customer')->get();
        $created = 0;
        $skipped = 0;
        $manualRequired = 0;

        foreach ($secrets as $secret) {
            if ($secret->customer) {
                $skipped++;
                continue;
            }
            $manualRequired++;
        }

        if ($manualRequired > 0) {
            flash()->warning("{$manualRequired} client(s) require a manually entered Customer Unique Id. No automatic Customer Id was generated and no customer records were created by bulk export.");
        } else {
            flash()->info("No new clients required export. {$skipped} existing clients were skipped.");
        }
        $this->dispatch('customer-list-updated');
    }

    public function exportCsv()
    {
        return $this->csvDownload('client-list');
    }

    public function exportMacReseller()
    {
        return $this->csvDownload('macreseller');
    }

    protected function filteredSecretsQuery()
    {
        return PPPSecrets::query()
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('router_lists')
                    ->whereColumn('router_lists.router_name', 'p_p_p_secrets.router_name')
                    ->where('router_lists.action', 'connected');
            })
            ->when($this->router, fn ($q) => $q->where('router_name', $this->router))
            ->when($this->protocol, fn ($q) => $q->whereRaw('upper(service) = ?', [strtoupper($this->protocol)]))
            ->when($this->profile, fn ($q) => $q->where('profile', $this->profile))
            ->when($this->userType === 'Unique', fn ($q) => $q->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', ['duplicate', 'disabled_duplicate']);
            }))
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $s = '%'.addcslashes($this->search, '%_').'%';
                $q->where('username', 'like', $s)->orWhere('caller_id', 'like', $s)->orWhere('router_name', 'like', $s);
            }))
            ->orderBy('username');
    }

    protected function exportRows(): array
    {
        $query = $this->filteredSecretsQuery()->with('customer');

        return $query->get()->map(function ($c) {
            $logout = $c->last_logged_out;
            $logoutTime = $logout ? (is_object($logout) && method_exists($logout, 'format') ? $logout->format('d/m/Y h:i A') : date('d/m/Y h:i A', strtotime((string) $logout))) : 'N/A';
            return [$c->username, $c->password, $c->service, $c->profile, $c->caller_id ?: 'N/A', $c->router_name, $logoutTime, $c->status ?: 'Unknown', $c->customer?->branch ?: 'N/A'];
        })->all();
    }

    protected function csvDownload(string $type)
    {
        $rows = $this->exportRows();
        $file = "mikrotik-{$type}-".now()->format('Ymd-His').'.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name','Password','Service','Profile','Caller ID','Server Name','Logout Time','User Status','Branch']);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
