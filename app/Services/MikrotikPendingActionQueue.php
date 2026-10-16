<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MikrotikPendingActionQueue
{
    /**
     * Mark older opposite network changes obsolete, so stale jobs cannot undo
     * a newer customer state change when the router comes back online.
     */
    public function supersedePending(string $customerId, string $routerName, string $username, string $desiredAction): void
    {
        DB::table('mikrotik_pending_actions')
            ->where('customer_unique_id', $customerId)
            ->where('router_name', $routerName)
            ->where('username', $username)
            ->where('status', 'pending')
            ->where('action', '<>', $desiredAction)
            ->update([
                'status' => 'superseded',
                'last_error' => 'Superseded by a newer '.$desiredAction.' action.',
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Queue one idempotent pending action per customer/router/user/action.
     */
    public function queue(string $customerId, string $routerName, string $username, string $action): int
    {
        $this->supersedePending($customerId, $routerName, $username, $action);

        $existingId = DB::table('mikrotik_pending_actions')
            ->where('customer_unique_id', $customerId)
            ->where('router_name', $routerName)
            ->where('username', $username)
            ->where('action', $action)
            ->where('status', 'pending')
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('mikrotik_pending_actions')->insertGetId([
            'customer_unique_id' => $customerId,
            'router_name' => $routerName,
            'username' => $username,
            'action' => $action,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
