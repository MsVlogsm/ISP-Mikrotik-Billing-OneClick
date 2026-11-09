<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\IntegrationApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function manager(array $permissions = []): User
    {
        $user = User::factory()->create([
            'email' => 'api-manager@example.test',
            'password' => Hash::make('test-password'),
            'email_verified_at' => now(),
        ]);
        $managerRole = Role::findOrCreate('Manager', 'web');
        // Keep this test role deliberately least-privileged; the production
        // Manager role may carry additional permissions from the seed data.
        $managerRole->syncPermissions([]);
        $user->assignRole($managerRole);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        return $user;
    }

    private function apiClient(User $owner, array $scopes): IntegrationApiClient
    {
        return IntegrationApiClient::create([
            'user_id' => $owner->id,
            'name' => 'API permission boundary test',
            'client_id' => 'xlc_boundary_'.bin2hex(random_bytes(6)),
            'client_secret_hash' => hash('sha256', 'boundary-secret'),
            'scopes' => $scopes,
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);
    }

    public function test_v1_staff_login_cannot_bypass_customer_module_permission(): void
    {
        $manager = $this->manager();

        $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'test-password',
        ])->assertForbidden();

        $manager->givePermissionTo(Permission::findOrCreate('view-customer', 'web'));
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'test-password',
        ])->assertOk()->assertJsonPath('abilities.0', 'api:customers:read');

        $this->app['auth']->forgetGuards();
        $token = $response->json('access_token');
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'API-V1-SCOPE-001',
            'customer_name' => 'API V1 Scope Test',
            'mobile' => '01711112222',
            'email' => 'scope@example.test',
            'address' => 'Test Address',
            'status' => 'active',
            'disable_count' => 0,
        ]);

        $this->withToken($token)->getJson('/api/v1/customers?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.customerId', $customer->customer_unique_id)
            ->assertJsonPath('data.0.mobile', null)
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.address', null)
            ->assertJsonPath('data.0.pppoe', null)
            ->assertJsonPath('data.0.package', null)
            ->assertJsonPath('data.0.billing', null);

        $manager->givePermissionTo(Permission::findOrCreate('customer-official-info', 'web'));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/customers?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.mobile', '01711112222')
            ->assertJsonPath('data.0.email', 'scope@example.test')
            ->assertJsonPath('data.0.address', 'Test Address');

        $manager->revokePermissionTo('view-customer');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_v2_api_client_cannot_mint_network_scope_without_owner_network_permission(): void
    {
        $manager = $this->manager(['view-customer']);
        $client = $this->apiClient($manager, ['network:read']);

        $this->postJson('/api/v2/auth/token', [
            'client_id' => $client->client_id,
            'client_secret' => 'boundary-secret',
        ])->assertForbidden();

        $manager->givePermissionTo(Permission::findOrCreate('network-inventory', 'web'));
        $token = $this->postJson('/api/v2/auth/token', [
            'client_id' => $client->client_id,
            'client_secret' => 'boundary-secret',
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v2/network/routers')->assertOk();
    }

    public function test_v2_contact_scope_requires_the_owners_customer_official_info_permission(): void
    {
        $manager = $this->manager(['view-customer']);
        $client = $this->apiClient($manager, ['customers:read', 'customers:contact:read']);

        $this->postJson('/api/v2/auth/token', [
            'client_id' => $client->client_id,
            'client_secret' => 'boundary-secret',
        ])->assertForbidden();

        $manager->givePermissionTo(Permission::findOrCreate('customer-official-info', 'web'));
        $this->postJson('/api/v2/auth/token', [
            'client_id' => $client->client_id,
            'client_secret' => 'boundary-secret',
        ])->assertOk()->assertJsonPath('scopes.1', 'customers:contact:read');
    }

    public function test_v2_api_token_is_revoked_immediately_when_owner_loses_scope_permission(): void
    {
        $manager = $this->manager(['network-inventory']);
        $client = $this->apiClient($manager, ['network:read']);
        $issued = $manager->createToken('api-client:'.$client->client_id, ['network:read']);

        $manager->revokePermissionTo('network-inventory');
        $this->app['auth']->forgetGuards();

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v2/network/olts')
            ->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $issued->accessToken->id]);
    }

    public function test_api_v1_and_v2_resources_require_bearer_authentication(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->getJson('/api/v2/customers')->assertUnauthorized();
        $this->getJson('/api/v2/network/olts')->assertUnauthorized();
        $this->getJson('/api/v2/network/onus')->assertUnauthorized();
        $this->getJson('/api/v2/network/routers')->assertUnauthorized();
    }
}
