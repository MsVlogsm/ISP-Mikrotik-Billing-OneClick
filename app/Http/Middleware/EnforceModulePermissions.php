<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Enforce module boundaries for normal HTTP routes, including closure-based routes. */
class EnforceModulePermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // MAC Reseller accounts use a tenant-isolated panel, not the global billing UI.
        // Keep the default dashboard redirect and account profile/logout reachable.
        if ($user->hasRole('Reseller') && $user->reseller?->isMacPanel()) {
            $macPath = trim($request->path(), '/');
            $allowed = $macPath === ''
                || $macPath === 'dashboard'
                || $macPath === 'dashboard-index'
                || $macPath === 'mac-reseller'
                || str_starts_with($macPath, 'mac-reseller/')
                || $macPath === 'logout'
                || $macPath === 'user/profile'
                || str_starts_with($macPath, 'user/');

            if (! $allowed) {
                if (in_array(strtoupper($request->method()), ['GET', 'HEAD'], true)) {
                    return redirect()->route('mac-reseller.dashboard');
                }
                abort(403, 'This account is restricted to the MAC Reseller panel.');
            }
        }

        // The platform owner remains the explicit full-access role.
        if ($user->hasRole('Super Admin')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $method = strtoupper($request->method());
        $required = $this->requiredPermission($path, $method);

        if ($required === null) {
            return $next($request);
        }

        $permissions = is_array($required) ? $required : [$required];
        abort_unless(
            method_exists($user, 'hasAnyPermission') && $user->hasAnyPermission($permissions),
            403,
            'You do not have permission to access this module or perform this action.'
        );

        // Managers may issue/sell existing stock but cannot inflate stock or adjust inventory balances.
        if ($path === 'stock-inventory/movements' && $method === 'POST'
            && ! $user->can('stock-inventory-manage')
            && ! in_array((string) $request->input('movement_type'), ['issue', 'sale'], true)) {
            abort(403, 'Manager can record only stock issue/sale movements.');
        }

        return $next($request);
    }

    private function requiredPermission(string $path, string $method): string|array|null
    {
        if ($path === 'stock-inventory' || str_starts_with($path, 'stock-inventory/')) {
            if ($method === 'GET') {
                if (str_starts_with($path, 'stock-inventory/reports')
                    || str_starts_with($path, 'stock-inventory/export/')) {
                    return 'stock-inventory-report';
                }
                // Purchase records expose supplier and unit-cost data; limit them to inventory managers.
                if (in_array($path, ['stock-inventory/settings', 'stock-inventory/purchases', 'stock-inventory/vendors', 'stock-inventory/vendor-payments'], true)) {
                    return 'stock-inventory-manage';
                }
                return 'stock-inventory-view';
            }

            if ($method === 'POST' && $path === 'stock-inventory/movements') {
                return 'stock-inventory-movement';
            }
            if ($method === 'POST' && preg_match('#^stock-inventory/products/\d+/delete$#', $path)) {
                return 'stock-inventory-delete';
            }

            return 'stock-inventory-manage';
        }

        $isInventory = $path === 'olts'
            || $path === 'devices-inventory'
            || str_starts_with($path, 'network-inventory/')
            || $path === 'network-inventory';

        if ($isInventory) {
            // This action only checks reachability/health; it is read-only apart from telemetry history.
            if ($method === 'POST' && preg_match('#^network-inventory/olt/\d+/diagnostic-check$#', $path)) {
                return ['network-inventory', 'mikrotik-setup'];
            }

            if ($method === 'GET') {
                return ['network-inventory', 'mikrotik-setup'];
            }

            // OLT provisioning/discovery/mapping/sync and device CRUD are network changes.
            return 'mikrotik-setup';
        }

        if ($path === 'device-manager' || str_starts_with($path, 'device-manager/')) {
            return ['network-inventory', 'mikrotik-setup'];
        }
        if (in_array($path, ['network-map', 'network-events'], true) || str_starts_with($path, 'network-events/')) {
            return ['network-inventory', 'mikrotik-setup'];
        }
        if ($path === 'mikrotik' || str_starts_with($path, 'mikrotik-setup')
            || str_starts_with($path, 'mikrotik-server') || $path === 'mikrotik-login-messages') {
            return 'mikrotik-setup';
        }

        if ($path === 'sms-service' || str_starts_with($path, 'sms-service/')) {
            return 'site-setup';
        }

        if ($path === 'company-settings' || str_starts_with($path, 'company-settings/')) {
            return 'site-setup';
        }

        // Global communication, gateway and server-backup settings are owner-only.
        if (in_array($path, ['site-settings', 'main-site-setup', 'sms', 'system/db-backup/download'], true)
            || str_starts_with($path, 'system/db-backup/download/')) {
            return 'site-setup';
        }
        if ($path === 'support-center/settings') {
            return 'site-setup';
        }
        if ($path === 'communication-center/settings') {
            return 'site-setup';
        }
        if ($path === 'mobile-banking' || str_starts_with($path, 'mobile-banking/')) {
            return 'site-setup';
        }
        if (in_array($path, ['communication-center', 'communication-center/chat', 'communication-center/whatsapp'], true)) {
            return ['view-tickets', 'manage-tickets'];
        }

        if ($path === 'payment-collection') {
            return 'payment-collection';
        }
        if ($path === 'payment-collection-edit') {
            return 'payment-collection-edit';
        }
        if ($path === 'payment-invoice') {
            return 'payment-collection-invoice';
        }
        // Administrative finance and oversight tools are intentionally Admin/Super Admin only.
        if ($path === 'admin/resellers' || str_starts_with($path, 'admin/resellers/')) {
            return 'manage-resellers';
        }
        if ($path === 'admin/expenses' || str_starts_with($path, 'admin/expenses/')) {
            return 'manage-expenses';
        }
        if ($path === 'admin/profit-summary') {
            return 'view-profit-summary';
        }
        if (in_array($path, ['admin/activity-logs', 'admin/login-logs', 'user-activity'], true)) {
            return 'view-audit-logs';
        }
        if ($path === 'admin/reviews') {
            return 'manage-reviews';
        }
        if ($path === 'admin/vouchers') {
            return 'view-vouchers';
        }
        if ($path === 'admin/purchase-requests') {
            return 'manage-purchase-requests';
        }
        if ($path === 'admin/system-logs') {
            return 'site-setup';
        }

        if ($path === 'collection-form') {
            return 'payment-collection';
        }
        if ($path === 'collection-report' || str_starts_with($path, 'collection-report/')) {
            return $method === 'GET' ? 'payment-collection-report' : 'payment-collection-edit';
        }
        if (in_array($path, ['billing-finance', 'income-summary', 'ledger-summary', 'extra-charges'], true)) {
            return $method === 'GET' ? 'payment-collection-report' : 'update-bill';
        }
        if ($path === 'monthly-bill-form') {
            return 'update-bill';
        }
        if ($path === 'customer-bulk-import' || str_starts_with($path, 'customer-bulk-import/')) {
            return 'create-customer';
        }
        if ($path === 'import-form') {
            return 'create-customer';
        }
        if ($path === 'import-store') {
            return 'create-customer';
        }

        return null;
    }
}
