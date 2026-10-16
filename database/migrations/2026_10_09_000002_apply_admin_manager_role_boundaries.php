<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ADDITIONAL_PERMISSIONS = [
        'view-user-role', 'view-tickets', 'manage-tickets', 'network-inventory',
        'site-setup', 'payment-collection-edit', 'payment-collection-invoice',
        'payment-collection-report', 'push-customers', 'create-reseller', 'create-sms',
        'address-setup-create', 'address-setup-edit', 'address-setup-delete',
        'package-setup-create', 'package-setup-edit', 'package-setup-delete',
        'manage-customer-assignment',
    ];

    /** Permissions referenced across the application; older installations have most already. */
    private const BASE_PERMISSION_NAMES = [
        'address-order', 'address-setup', 'all-customer', 'amount-collection',
        'amount-collection-edit', 'amount-collection-report', 'bandwidth-billing',
        'bandwidth-reseller', 'bandwidth-services', 'bandwidth-support',
        'collection-customer', 'collection-list', 'complain-list', 'create-customer',
        'create-product', 'create-user', 'create-user-role', 'create-web-content',
        'customer-billing-info', 'customer-official-info', 'customer-server-info',
        'delete-address', 'delete-customer', 'delete-mikrotik', 'delete-package',
        'delete-product', 'delete-sms', 'delete-user', 'delete-user-role',
        'delete-web-content', 'disable-customer', 'edit-address', 'edit-customer',
        'edit-mikrotik', 'edit-package', 'edit-product', 'edit-sms', 'edit-user',
        'edit-user-role', 'enable-customer', 'enable-pending-customer', 'free-customer',
        'inactive-customer', 'mikrotik-auto-backup', 'mikrotik-connection',
        'mikrotik-setup', 'mikrotik-user-create', 'mikrotik-user-delete',
        'mikrotik-user-edit', 'package-setup', 'partner-network', 'password-reset',
        'payment-collection', 'payment-delete', 'payment-edit', 'payment-history',
        'payment-setup', 'pending-customer', 'print-setup', 'recent-customer',
        'reseller-cashflow', 'reseller-ledger', 'search-customer', 'site-settings',
        'sms-setup', 'update-bill', 'view-customer', 'view-user', 'without-collection-list',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Admin', 'Admin', 'Manager', 'Reseller'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $configuredRolePermissions = collect(config('role_permission_matrix', []))
            ->flatMap(fn ($role) => collect($role['permissions'] ?? [])->flatten())
            ->unique();

        foreach (collect(self::BASE_PERMISSION_NAMES)
            ->merge(self::ADDITIONAL_PERMISSIONS)
            ->merge($configuredRolePermissions)
            ->unique() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['Admin', 'Manager'] as $roleName) {
            $groups = config("role_permission_matrix.{$roleName}.permissions", []);
            $names = collect($groups)->flatten()->unique()->values()->all();
            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $names)
                ->get();

            Role::findByName($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Restore the exact pre-matrix defaults observed before this migration.
        $previous = [
            'Admin' => ['create-user', 'edit-user', 'delete-user', 'create-customer', 'edit-customer', 'delete-customer', 'enable-customer', 'disable-customer', 'create-web-content', 'edit-web-content', 'delete-web-content', 'all-customer', 'enable-pending-customer', 'update-bill'],
            'Manager' => ['create-customer', 'edit-customer', 'delete-customer', 'enable-customer', 'disable-customer', 'create-web-content', 'edit-web-content', 'delete-web-content', 'all-customer', 'enable-pending-customer', 'update-bill'],
        ];

        foreach ($previous as $roleName => $names) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->syncPermissions(Permission::where('guard_name', 'web')->whereIn('name', $names)->get());
            }
        }

        // Keep any new permission that another role/user has started using.
        foreach (self::ADDITIONAL_PERMISSIONS as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();
            if (! $permission) {
                continue;
            }
            $inUseByRole = DB::table('role_has_permissions')->where('permission_id', $permission->id)->exists();
            $inUseByUser = DB::table('model_has_permissions')->where('permission_id', $permission->id)->exists();
            if (! $inUseByRole && ! $inUseByUser) {
                $permission->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
