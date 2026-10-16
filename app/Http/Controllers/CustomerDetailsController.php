<?php

namespace App\Http\Controllers;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\OltOnuCustomerMapping;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

class CustomerDetailsController extends Controller
{
    public function show(string $id)
    {
        if (! hasAccess(['Super Admin'], ['view-customer', 'all-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $uniqueId = $this->resolveCustomerId($id);
        $customer = CustomersInfo::withTrashed()
            ->with(['pppUser', 'package', 'billing', 'official', 'customerAddress'])
            ->where('customer_unique_id', $uniqueId)
            ->first();

        // After CID reuse, historical customers retain a private archive key.
        // Resolve their old human-readable CID only when no current CID matches.
        if (! $customer) {
            $customer = CustomersInfo::withTrashed()
                ->with(['pppUser', 'package', 'billing', 'official', 'customerAddress'])
                ->where('deleted_original_customer_unique_id', $uniqueId)
                ->firstOrFail();
        }

        $customerStorageKey = (string) $customer->customer_unique_id;

        $mapping = Schema::hasTable('olt_onu_customer_mappings')
            ? OltOnuCustomerMapping::with('olt')
                ->where('customer_id', $customer->id)
                ->latest('id')->first()
            : null;

        $activities = class_exists(Activity::class)
            ? Activity::with('causer')->where(function ($q) use ($customerStorageKey, $customer) {
                $q->where('properties->customer_unique_id', $customerStorageKey)
                  ->orWhere('properties->customer_id', $customer->id);
            })->latest()->limit(8)->get()
            : collect();

        // PPPoE remote IP may be assigned dynamically from a MikroTik pool.
        // Prefer the stored secret value; when it is empty, read the live
        // /ppp active session for this username and use its current address.
        $remoteIp = $customer->pppUser?->ppp_remote_ip ?: null;
        if (! $remoteIp
            && strtolower((string) $customer->pppUser?->service) === 'pppoe'
            && $customer->pppUser?->router_name
            && $customer->pppUser?->username) {
            try {
                $sessions = app(MikrotikController::class)->getActivePppSessions($customer->pppUser->router_name);
                foreach ($sessions as $session) {
                    if (($session['name'] ?? '') === $customer->pppUser->username) {
                        $remoteIp = $session['address'] ?? $session['remote-address'] ?? null;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return view('customers.show', compact('customer', 'mapping', 'activities', 'remoteIp'));
    }

    private function resolveCustomerId(string $id): string
    {
        try {
            $decrypted = decrypt($id);
            if (is_string($decrypted) && $decrypted !== '') {
                return $decrypted;
            }
        } catch (\Throwable) {
            // The public customer URL may use the manual Customer Unique ID.
        }

        return $id;
    }
}
