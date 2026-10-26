<?php

namespace App\Console\Commands;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\NetworkEvent;
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

                    // Only alert on a confirmed ONLINE -> OFFLINE transition. The router
                    // itself was probed above, so an unreachable router cannot mass-alert
                    // all of its customers as individually down.
                    $fingerprint = 'customer-down:'.(string) $customer->customer_unique_id;
                    $openAlert = NetworkEvent::where('fingerprint', $fingerprint)
                        ->whereIn('status', ['open', 'acknowledged'])
                        ->latest('id')->first();

                    if ($state === 'offline') {
                        if ($latest && $latest->state === 'online' && ! $openAlert) {
                            NetworkEvent::create([
                                'source_type' => 'customer-monitoring',
                                'event_type' => 'customer_down',
                                'severity' => 'critical',
                                'title' => 'Customer offline: '.($customer->customer_name ?: $customer->customer_unique_id),
                                'message' => 'Customer ID: '.$customer->customer_unique_id
                                    .'\nName: '.($customer->customer_name ?: 'N/A')
                                    .'\nPPPoE: '.$username
                                    .'\nRouter: '.$router->router_name
                                    .'\nLast known IP: '.($session['address'] ?? $session['remote-address'] ?? 'N/A'),
                                'status' => 'open',
                                'fingerprint' => $fingerprint,
                                'occurrences' => 1,
                                'first_seen_at' => now(),
                                'last_seen_at' => now(),
                                'metadata' => [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'customer_name' => $customer->customer_name,
                                    'ppp_username' => $username,
                                    'router_name' => $router->router_name,
                                    'previous_state' => $latest->state,
                                    'current_state' => $state,
                                ],
                            ]);
                        } elseif ($openAlert) {
                            $openAlert->increment('occurrences');
                            $openAlert->update(['last_seen_at' => now()]);
                        }
                    } elseif ($state === 'online' && $openAlert) {
                        $recoveryMessage = 'Customer is UP again.\nCustomer ID: '.$customer->customer_unique_id
                            .'\nName: '.($customer->customer_name ?: 'N/A')
                            .'\nPPPoE: '.$username.'\nRouter: '.$router->router_name
                            .'\nIP: '.($session['address'] ?? $session['remote-address'] ?? 'N/A');
                        $openAlert->update([
                            'status' => 'resolved',
                            'resolved_at' => now(),
                            'resolved_by' => null,
                            'last_seen_at' => now(),
                            'message' => $recoveryMessage,
                        ]);

                        // Recovery is a separate event so the normal notification job
                        // sends a Telegram message for UP as well as DOWN transitions.
                        NetworkEvent::create([
                            'source_type' => 'customer-monitoring',
                            'event_type' => 'customer_up',
                            'severity' => 'warning',
                            'title' => 'Customer back online: '.($customer->customer_name ?: $customer->customer_unique_id),
                            'message' => $recoveryMessage,
                            'status' => 'open',
                            'fingerprint' => 'customer-up:'.(string) $customer->customer_unique_id.':'.now()->timestamp,
                            'occurrences' => 1,
                            'first_seen_at' => now(),
                            'last_seen_at' => now(),
                            'metadata' => [
                                'customer_unique_id' => $customer->customer_unique_id,
                                'customer_name' => $customer->customer_name,
                                'ppp_username' => $username,
                                'router_name' => $router->router_name,
                                'previous_state' => 'offline',
                                'current_state' => 'online',
                            ],
                        ]);
                    }
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
