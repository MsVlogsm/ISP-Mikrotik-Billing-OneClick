<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\NetworkInventoryDevice;
use App\Models\StockInventoryProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationalModulePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findByName($roleName, 'web'));
        $this->actingAs($user);
        return $user;
    }

    private function loginWithPermission(string $permission): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        $this->actingAs($user);
        return $user;
    }

    public function test_mobile_banking_routes_enforce_their_matching_action_permissions(): void
    {
        $this->loginWithPermission('payment-setup');
        $this->get('/mobile-banking/gateways')->assertOk();
        $this->get('/mobile-banking/methods')->assertOk();
        $this->get('/mobile-banking/settings')->assertForbidden();

        $this->loginWithPermission('site-settings');
        $this->get('/mobile-banking/settings')->assertOk();
        $this->get('/mobile-banking/gateways')->assertForbidden();

        $this->loginWithPermission('payment-history');
        $this->get('/mobile-banking/logs')->assertOk();
        $this->get('/mobile-banking')->assertForbidden();

        $this->loginWithPermission('payment-collection');
        $this->get('/mobile-banking')->assertOk();
        $this->get('/mobile-banking/logs')->assertForbidden();
    }

    public function test_broadband_customer_data_and_package_routes_are_permission_gated(): void
    {
        $this->loginWithPermission('payment-collection');
        foreach ([
            '/broadband/customers',
            '/broadband/customer-search',
            '/broadband/online-customers',
            '/broadband/due-customers',
            '/broadband/paid-customers',
            '/broadband/inactive-customers',
            '/broadband/new-customers',
            '/broadband/unverified-customers',
            '/broadband/packages',
            '/broadband/customer-package-import',
        ] as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->loginWithPermission('view-customer');
        $this->get('/broadband/customers')->assertOk();
        $this->get('/broadband/customer-search')->assertOk();
        $this->get('/broadband/due-customers')->assertOk();

        $this->loginWithPermission('package-setup');
        $this->get('/broadband/packages')->assertOk();

        $this->loginWithPermission('create-customer');
        $this->get('/broadband/customer-package-import')->assertOk();
    }

    public function test_network_inventory_permission_is_read_only_for_olt_and_does_not_grant_mikrotik_setup(): void
    {
        $this->loginWithPermission('network-inventory');
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'RBAC Read Only OLT',
            'vendor' => 'VSOL',
            'ip_address' => '192.0.2.248',
            'model' => 'V1600D',
            'status' => 'unknown',
            'health_status' => 'unknown',
        ]);

        $this->get('/network-inventory/olt/'.$olt->id.'/dashboard')->assertOk();
        $this->get('/network-inventory/olt/'.$olt->id.'/provisioning')->assertOk();
        $this->get('/network-inventory/mikrotik-management')->assertOk();

        // A network-inventory viewer cannot trigger device polling, discovery,
        // provisioning, ONU reboot, or open the separate MikroTik setup panel.
        $this->post('/network-inventory/olt/'.$olt->id.'/dashboard/sync')->assertForbidden();
        $this->post('/network-inventory/olt/'.$olt->id.'/dashboard/metrics')->assertForbidden();
        $this->post('/network-inventory/olt/'.$olt->id.'/provisioning/discover')->assertForbidden();
        $this->post('/network-inventory/olt/'.$olt->id.'/provisioning/execute', [
            'profile' => 'v1600d_ep_series_v1_2',
            'action' => 'disable_onu',
            'pon' => '0/1',
            'onu' => 1,
        ])->assertForbidden();
        $this->get('/mikrotik')->assertForbidden();
    }

    public function test_manager_can_view_stock_inventory_but_cannot_manage_products_or_receive_stock(): void
    {
        $this->loginAsRole('Manager');

        $this->get('/stock-inventory')->assertOk();
        $this->get('/stock-inventory/products')->assertOk();
        $this->get('/stock-inventory/reports')->assertOk();
        $this->get('/stock-inventory/purchases')->assertForbidden();

        $this->post('/stock-inventory/products', [
            'sku' => 'RBAC-TEST-PRODUCT',
            'name' => 'RBAC Product',
            'unit' => 'piece',
            'quantity' => 0,
            'reorder_level' => 1,
            'unit_cost' => 10,
        ])->assertForbidden();

        $this->post('/stock-inventory/movements', [
            'product_id' => 1,
            'movement_type' => 'stock-in',
            'quantity' => 5,
        ])->assertForbidden();
    }

    public function test_manager_can_record_issue_movement_but_only_allowed_movement_types(): void
    {
        $this->loginAsRole('Manager');
        $product = StockInventoryProduct::create([
            'sku' => 'RBAC-MANAGER-STOCK',
            'name' => 'Manager Issue Test',
            'unit' => 'piece',
            'quantity' => 3,
            'reorder_level' => 1,
            'unit_cost' => 10,
            'status' => 'active',
        ]);

        $this->post('/stock-inventory/movements', [
            'product_id' => $product->id,
            'movement_type' => 'issue',
            'quantity' => 1,
            'reference' => 'RBAC-ISSUE-TEST',
            'source' => 'Stock room',
            'destination' => 'Field team',
        ])->assertSessionHas('inventory_message', 'Stock movement recorded successfully.');

        $this->assertDatabaseHas('stock_inventory_products', ['id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseHas('stock_inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'issue',
            'quantity' => 1,
            'reference' => 'RBAC-ISSUE-TEST',
        ]);
    }

    public function test_admin_can_manage_stock_inventory(): void
    {
        $this->loginAsRole('Admin');

        $this->get('/stock-inventory/purchases')->assertOk();

        $this->post('/stock-inventory/products', [
            'sku' => 'RBAC-ADMIN-PRODUCT',
            'name' => 'RBAC Admin Product',
            'unit' => 'piece',
            'quantity' => 3,
            'reorder_level' => 1,
            'unit_cost' => 10,
        ])->assertSessionHas('inventory_message', 'Product added.');

        $this->assertDatabaseHas('stock_inventory_products', [
            'sku' => 'RBAC-ADMIN-PRODUCT',
            'quantity' => 3,
        ]);
    }

    public function test_manager_cannot_access_network_configuration_or_collection_edit(): void
    {
        $this->loginAsRole('Manager');

        $this->get('/network-inventory/olt-management')->assertForbidden();
        $this->post(route('network-inventory.olt.sync'))->assertForbidden();
        $this->get('/payment-collection-edit')->assertForbidden();
        $this->post('/monthly-bill-form')->assertForbidden();

        // Collection and read-only financial reports are allowed; billing writes/history edits are not.
        $this->get('/payment-collection')->assertOk();
        $this->get('/income-summary')->assertOk();
        $this->get('/ledger-summary')->assertOk();
    }

    public function test_manager_cannot_execute_olt_provisioning_or_change_onu_customer_mapping(): void
    {
        $this->loginAsRole('Manager');
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'RBAC Test OLT',
            'vendor' => 'VSOL',
            'ip_address' => '192.0.2.249',
            'model' => 'V1600D',
            'status' => 'unknown',
            'health_status' => 'unknown',
        ]);

        $this->get('/network-inventory/olt/'.$olt->id.'/provisioning')->assertForbidden();
        $this->post('/network-inventory/olt/'.$olt->id.'/provision', [
            'profile' => 'v1600d_ep_series_v1_2',
            'action' => 'disable_onu',
            'pon' => '0/1',
            'onu' => 1,
        ])->assertForbidden();
        $this->post('/network-inventory/olt/'.$olt->id.'/customers/mapping', [
            'onu_id' => '1',
            'customer_id' => 999999,
        ])->assertForbidden();
    }

    public function test_manager_and_admin_cannot_change_global_communication_gateway_or_backup_settings(): void
    {
        foreach (['Manager', 'Admin'] as $roleName) {
            $this->loginAsRole($roleName);
            $this->get('/communication-center')->assertOk();
            $this->get('/communication-center/settings')->assertForbidden();
            $this->post('/communication-center/settings', [
                'whatsapp' => '01999999999',
                'notification_email' => 'attacker@example.com',
                'notification_url' => 'https://example.com/notify',
            ])->assertForbidden();
            $this->get('/support-center/settings')->assertForbidden();
            $this->post('/support-center/settings', ['site_name' => 'Unauthorized update'])->assertForbidden();
            $this->get('/mobile-banking/settings')->assertForbidden();
            $this->post('/mobile-banking/settings', ['gateway' => 'disabled'])->assertForbidden();
            $this->get('/system/db-backup/download/does-not-exist.sql')->assertForbidden();
            $this->get('/site-settings')->assertForbidden();
            $this->get('/sms')->assertForbidden();
        }
    }

    public function test_super_admin_retains_global_settings_access(): void
    {
        $this->loginAsRole('Super Admin');
        $this->get('/communication-center/settings')->assertOk();
        $this->get('/support-center/settings')->assertOk();
        $this->get('/site-settings')->assertOk();
        $this->get('/sms')->assertOk();
    }

    public function test_admin_cannot_access_network_configuration_and_cannot_edit_roles(): void
    {
        $this->loginAsRole('Admin');

        $this->get('/network-inventory/olt-management')->assertForbidden();
        $this->get('/admin-roles')->assertForbidden();
    }
}
