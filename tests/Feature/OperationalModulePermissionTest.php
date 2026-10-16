<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\NetworkInventoryDevice;
use App\Models\StockInventoryProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
