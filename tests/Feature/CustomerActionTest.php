<?php

namespace Tests\Feature;

use App\Livewire\CustomerList;
use App\Models\CustomersInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function disconnectedRouter(string $name = 'test-router'): RouterList
    {
        return RouterList::create([
            'router_name' => $name,
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'disconnected',
        ]);
    }

    private function customerWithPpp(string $id, string $routerName = 'test-router', string $status = 'active'): array
    {
        $router = $this->disconnectedRouter($routerName);
        $ppp = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'ppp-'.$id,
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => $id,
            'customer_name' => 'Test '.$id,
            'status' => $status,
            'disable_count' => 0,
            'ppp_user_id' => $ppp->id,
        ]);

        return [$customer, $ppp];
    }

    public function test_disconnected_router_disable_updates_local_state_and_queues_remote_action(): void
    {
        [$customer, $ppp] = $this->customerWithPpp('ACTION-DISABLE');

        Livewire::test(CustomerList::class)
            ->call('disableCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'status' => 'disable',
        ]);
        $this->assertDatabaseHas('p_p_p_secrets', [
            'id' => $ppp->id,
            'status' => 'disable',
        ]);
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => 'ACTION-DISABLE',
            'router_name' => 'test-router',
            'username' => 'ppp-ACTION-DISABLE',
            'action' => 'disable',
            'status' => 'pending',
        ]);
    }

    public function test_disconnected_router_delete_removes_local_records_and_queues_remote_removal(): void
    {
        [$customer, $ppp] = $this->customerWithPpp('ACTION-DELETE', 'delete-router', 'disable');

        Livewire::test(CustomerList::class)
            ->call('deleteCustomer', encrypt($customer->customer_unique_id));

        $this->assertSoftDeleted('customers_infos', ['id' => $customer->id]);
        $this->assertDatabaseMissing('p_p_p_secrets', ['id' => $ppp->id]);
        // Deleted customer references are deliberately archived so the human-readable
        // CID can be reused without attaching old history to a new customer.
        $this->assertDatabaseHas('mikrotik_pending_actions', [
            'customer_unique_id' => '__ARCHIVED_CUSTOMER_'.$customer->id,
            'router_name' => 'delete-router',
            'username' => 'ppp-ACTION-DELETE',
            'action' => 'remove',
            'status' => 'pending',
        ]);
    }

    public function test_active_customer_cannot_be_deleted(): void
    {
        [$customer, $ppp] = $this->customerWithPpp('ACTION-ACTIVE');

        Livewire::test(CustomerList::class)
            ->call('deleteCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'status' => 'active',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('p_p_p_secrets', ['id' => $ppp->id]);
        $this->assertDatabaseMissing('mikrotik_pending_actions', [
            'customer_unique_id' => 'ACTION-ACTIVE',
            'action' => 'remove',
        ]);
    }

    public function test_pending_action_is_transactional_with_disable(): void
    {
        [$customer] = $this->customerWithPpp('ACTION-ROLLBACK');

        CustomersInfo::saving(function (CustomersInfo $model) use ($customer): void {
            if ($model->is($customer)) {
                throw new \RuntimeException('forced test rollback');
            }
        });

        Livewire::test(CustomerList::class)
            ->call('disableCustomer', encrypt($customer->customer_unique_id));

        $this->assertDatabaseHas('customers_infos', [
            'id' => $customer->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('mikrotik_pending_actions', [
            'customer_unique_id' => 'ACTION-ROLLBACK',
            'action' => 'disable',
        ]);
    }
}
