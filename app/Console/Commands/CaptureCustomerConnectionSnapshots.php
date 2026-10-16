<?php

namespace App\Console\Commands;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\RouterList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaptureCustomerConnectionSnapshots extends Command
{
    protected $signature = 'app:capture-customer-connection-snapshots';
    protected $description = 'Record customer PPP online/offline state transitions from connected MikroTik routers';

    public function handle(): int
    {
        $mikrotik = app(MikrotikController::class);
        $routers = RouterList::where('action','connected')->get();
        $recorded = 0;

        foreach ($routers as $router) {
            try {
                $rows = $mikrotik->singleRead($router->router_name, '/ppp/active/print', '/ppp active print without-paging terse', [], false, true);
                $confirmedEmptyActiveList = empty($rows);
                // Confirm the router is responding before interpreting an empty active-session list as offline.
                if ($confirmedEmptyActiveList) {
                    $probe = $mikrotik->singleRead($router->router_name, '/system/resource/print', '/system resource print', [], false, true);
                    if (empty($probe)) continue;
                }
                $active = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $name = (string)($row['name'] ?? $row['user'] ?? $row['username'] ?? '');
                    if ($name !== '') $active[$name] = $row;
                }
                if (!$active && !$confirmedEmptyActiveList) continue;

                $customers = CustomersInfo::query()->with('pppUser')
                    ->whereHas('pppUser', fn($q) => $q->where('router_name',$router->router_name))
                    ->get(['id','customer_unique_id','ppp_user_id']);
                foreach ($customers as $customer) {
                    $username = (string)($customer->pppUser?->username ?? '');
                    if ($username === '') continue;
                    $session = $active[$username] ?? null;
                    $state = $session ? 'online' : 'offline';
                    $latest = DB::table('customer_connection_snapshots')->where('customer_unique_id',$customer->customer_unique_id)->latest('sampled_at')->first();
                    if ($latest && $latest->state === $state) continue;
                    DB::table('customer_connection_snapshots')->insert([
                        'customer_unique_id'=>$customer->customer_unique_id,
                        'router_name'=>$router->router_name,
                        'ppp_username'=>$username,
                        'state'=>$state,
                        'ip_address'=>$session['address'] ?? $session['remote-address'] ?? null,
                        'uptime'=>$session['uptime'] ?? null,
                        'sampled_at'=>now(),
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ]);
                    $recorded++;
                }
            } catch (\Throwable $e) {
                Log::warning('Customer connection snapshot failed',['router'=>$router->router_name,'error'=>$e->getMessage()]);
            }
        }

        $this->info("Recorded {$recorded} customer connection state transition(s).");
        return self::SUCCESS;
    }
}
