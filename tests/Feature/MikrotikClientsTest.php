<?php

namespace Tests\Feature;

use App\Livewire\MikrotikClients;
use App\Models\CustomersInfo;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class MikrotikClientsTest extends TestCase
{
    use RefreshDatabase;

    private function router(string $name): RouterList
    {
        return RouterList::create([
            'router_name' => $name,
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => 'test',
            'action' => 'connected',
        ]);
    }

    private function queryUsernames(MikrotikClients $component, string $methodName): array
    {
        $method = new ReflectionMethod(MikrotikClients::class, $methodName);

        return $method->invoke($component)->pluck('username')->all();
    }

    public function test_unlinked_current_ppp_secret_is_in_pending_list(): void
    {
        $router = $this->router('test-router');
        PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'pending-user',
            'service' => 'pppoe',
            'status' => 'active',
        ]);

        $component = new MikrotikClients();
        $component->router = $router->router_name;

        $this->assertSame(['pending-user'], $this->queryUsernames($component, 'pendingClientsQuery'));
    }

    public function test_linked_ppp_secret_is_not_in_pending_list(): void
    {
        $router = $this->router('linked-router');
        $secret = PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'linked-user',
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        CustomersInfo::create(['customer_unique_id' => 'TEST-LINKED', 'ppp_user_id' => $secret->id]);

        $component = new MikrotikClients();
        $component->router = $router->router_name;

        $this->assertSame([], $this->queryUsernames($component, 'pendingClientsQuery'));
    }

    public function test_removed_ppp_secret_is_not_in_pending_list_even_with_all_user_type(): void
    {
        $router = $this->router('removed-router');
        PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'removed-user',
            'service' => 'pppoe',
            'status' => 'removed',
        ]);
        PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'current-user',
            'service' => 'pppoe',
            'status' => 'active',
        ]);

        $component = new MikrotikClients();
        $component->router = $router->router_name;
        $component->userType = 'All';

        $this->assertSame(['current-user'], $this->queryUsernames($component, 'pendingClientsQuery'));
    }

    public function test_removed_ppp_secrets_are_excluded_from_csv_and_excel_export_query(): void
    {
        $router = $this->router('export-router');
        PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'current-export-user',
            'service' => 'pppoe',
            'status' => 'active',
        ]);
        PPPSecrets::create([
            'router_name' => $router->router_name,
            'username' => 'removed-export-user',
            'service' => 'pppoe',
            'status' => 'removed',
        ]);

        $component = new MikrotikClients();

        $this->assertSame(
            ['current-export-user'],
            $this->queryUsernames($component, 'filteredSecretsQuery')
        );
    }
}
