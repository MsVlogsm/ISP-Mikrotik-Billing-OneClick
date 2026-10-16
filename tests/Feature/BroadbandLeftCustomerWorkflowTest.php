<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BroadbandLeftCustomerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    private function leftCustomer(string $id, array $overrides = []): CustomersInfo
    {
        return CustomersInfo::create(array_merge([
            'customer_unique_id' => $id,
            'customer_name' => 'Left '.$id,
            'mobile' => '01700000000',
            'address' => 'Test Road',
            'status' => 'disable',
            'zone_name' => 'North Zone',
            'left_date' => now()->subDay(),
            'left_equipment' => 'ONU, Router',
            'recovery_status' => 'pending',
            'server_label' => 'Test Router',
            'left_reason' => 'Service discontinued',
        ], $overrides));
    }

    public function test_left_customer_register_renders_summary_and_recovery_fields(): void
    {
        $this->leftCustomer('LEFT-PENDING');
        $this->leftCustomer('LEFT-RECOVERED', [
            'recovery_status' => 'recovered',
            'left_equipment' => 'ONU recovered',
        ]);

        $this->get(route('broadband-inactive-customers'))
            ->assertOk()
            ->assertSee('Left Customers')
            ->assertSee('Left Customer Register')
            ->assertSee('Total Left Customers')
            ->assertSee('Pending / Partial Recovery')
            ->assertSee('Recovery Issues')
            ->assertSee('C-Code')
            ->assertSee('Zone')
            ->assertSee('Left Date')
            ->assertSee('Products')
            ->assertSee('Recovery')
            ->assertSee('LEFT-PENDING')
            ->assertSee('LEFT-RECOVERED')
            ->assertSee('ONU recovered');
    }

    public function test_left_customer_search_status_zone_and_departure_date_filters_apply(): void
    {
        $match = $this->leftCustomer('LEFT-NORTH', [
            'zone_name' => 'North Zone',
            'left_date' => now()->subDays(2),
            'recovery_status' => 'pending',
        ]);
        $this->leftCustomer('LEFT-SOUTH', [
            'zone_name' => 'South Zone',
            'left_date' => now()->subDays(2),
            'recovery_status' => 'pending',
        ]);
        $this->leftCustomer('LEFT-OLD', [
            'zone_name' => 'North Zone',
            'left_date' => now()->subDays(60),
            'recovery_status' => 'pending',
        ]);

        $this->get(route('broadband-inactive-customers', [
            'q' => 'LEFT-NORTH',
            'zone_name' => 'North',
            'recovery_status' => 'pending',
            'left_from' => now()->subDays(7)->toDateString(),
            'left_to' => now()->toDateString(),
        ]))
            ->assertOk()
            ->assertSee($match->customer_unique_id)
            ->assertDontSee('LEFT-SOUTH')
            ->assertDontSee('LEFT-OLD');
    }

    public function test_left_recovery_details_update_persists_validated_values(): void
    {
        $customer = $this->leftCustomer('LEFT-UPDATE');

        $this->from(route('broadband-inactive-customers'))
            ->patch(route('broadband-customer-left-details', encrypt($customer->customer_unique_id)), [
                'zone_name' => 'Central',
                'left_equipment' => 'ONU recovered; adapter pending',
                'recovery_status' => 'partial',
                'left_reason' => 'Moved to another provider',
                'server_label' => 'OLT POP-2',
                'left_date' => '2026-10-10',
            ])
            ->assertRedirect(route('broadband-inactive-customers'))
            ->assertSessionHas('broadband_message', 'Left customer details updated successfully.');

        $customer->refresh();
        $this->assertSame('Central', $customer->zone_name);
        $this->assertSame('ONU recovered; adapter pending', $customer->left_equipment);
        $this->assertSame('partial', $customer->recovery_status);
        $this->assertSame('Moved to another provider', $customer->left_reason);
        $this->assertSame('OLT POP-2', $customer->server_label);
        $this->assertSame('2026-10-10', $customer->left_date->toDateString());
    }

    public function test_invalid_left_recovery_status_is_rejected(): void
    {
        $customer = $this->leftCustomer('LEFT-INVALID');

        $this->from(route('broadband-inactive-customers'))
            ->patch(route('broadband-customer-left-details', encrypt($customer->customer_unique_id)), [
                'recovery_status' => 'complete-ish',
            ])
            ->assertRedirect(route('broadband-inactive-customers'))
            ->assertSessionHasErrors('recovery_status');

        $this->assertSame('pending', $customer->fresh()->recovery_status);
    }

    public function test_disconnected_router_disable_records_left_details_and_queues_remote_action(): void
    {
        $router = RouterList::create([
            'router_name' => 'left-test-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test-user',
            'password' => 'test-pass',
            'action' => 'disconnected',
        ]);
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'left-test-ppp',
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'LEFT-DISABLE',
            'customer_name' => 'Disable workflow test',
            'status' => 'active',
            'ppp_user_id' => $ppp->id,
        ]);

        $this->from(route('broadband-customers'))
            ->post(route('broadband-customer-disable', encrypt($customer->customer_unique_id)), [
                'left_reason' => 'Customer requested closure',
            ])
            ->assertRedirect(route('broadband-customers'))
            ->assertSessionHas('broadband_message');

        $customer->refresh();
        $this->assertSame('disable', $customer->status);
        $this->assertSame('Customer requested closure', $customer->left_reason);
        $this->assertSame('pending', $customer->recovery_status);
        $this->assertSame($router->router_name, $customer->server_label);
        $this->assertNotNull($customer->left_date);
        $this->assertDatabaseHas('p_p_p_secrets', ['id' => $ppp->id, 'status' => 'disable']);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => 'LEFT-DISABLE',
            'router_name' => $router->router_name,
            'username' => 'left-test-ppp',
            'action' => 'disable',
            'status' => 'pending',
        ]);
    }

    public function test_disconnected_router_delete_queues_removal_and_deletes_local_records(): void
    {
        $router = RouterList::create([
            'router_name' => 'left-delete-router',
            'ip_address' => '127.0.0.1',
            'username' => 'test-user',
            'password' => 'test-pass',
            'action' => 'disconnected',
        ]);
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'left-delete-ppp',
            'service' => 'pppoe',
            'status' => 'disable',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'LEFT-DELETE',
            'customer_name' => 'Delete workflow test',
            'status' => 'disable',
            'ppp_user_id' => $ppp->id,
        ]);

        $this->delete(route('broadband-customer-destroy', encrypt($customer->customer_unique_id)))
            ->assertRedirect(route('broadband-customers'))
            ->assertSessionHas('broadband_message');

        $this->assertSoftDeleted('customers_infos', ['id' => $customer->id]);
        $this->assertDatabaseMissing('p_p_p_secrets', ['id' => $ppp->id]);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'router_name' => $router->router_name,
            'username' => 'left-delete-ppp',
            'action' => 'remove',
            'status' => 'pending',
        ]);
    }
}
