<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileMikrotikPendingAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileMikrotikPendingActions extends Command
{
    protected $signature = 'app:reconcile-mikrotik-pending-actions';

    protected $description = 'Queue pending MikroTik actions when their routers are connected.';

    public function handle(): int
    {
        $actions = DB::table('mikrotik_pending_actions as p')
            ->join('router_lists as r', function ($join) {
                $join->on('r.router_name', '=', 'p.router_name')
                    ->where('r.action', '=', 'connected');
            })
            ->where('p.status', 'pending')
            ->orderBy('p.id')
            ->limit(100)
            ->pluck('p.id');

        foreach ($actions as $id) {
            ReconcileMikrotikPendingAction::dispatch((int) $id)->afterCommit();
        }

        if ($actions->isNotEmpty()) {
            $this->info('Queued '.$actions->count().' pending MikroTik action(s).');
        }

        return self::SUCCESS;
    }
}
