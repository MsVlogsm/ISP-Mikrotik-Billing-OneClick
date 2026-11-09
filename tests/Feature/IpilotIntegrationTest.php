<?php

namespace Tests\Feature;

use App\Models\IntegrationApiClient;
use App\Models\IntegrationWebhookEndpoint;
use App\Models\User;
use App\Models\ZabbixServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IpilotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($this->admin);
    }

    public function test_ipilot_pages_render_for_an_authorized_admin(): void
    {
        $this->get(route('ipilot'))->assertOk()->assertSee('iPilot Operations Workspace');
        $this->get(route('ipilot.app-settings'))->assertOk()->assertSee('Default Map Style');
        $this->get(route('ipilot.gis.outside-plant'))->assertOk()->assertSee('Outside Plant');
        $this->get(route('ipilot.gis.operations'))->assertOk()->assertSee('Network Operations');
        $this->get(route('ipilot.gis.planning'))->assertOk()->assertSee('Planning');
        $this->get(route('network-map'))->assertOk();
        $this->get(route('network-topology.designer'))->assertOk()->assertSee('Topology Designer');
        $this->get(route('network-topology.live'))->assertOk()->assertSee('Live Topology');
        $this->get(route('ipilot.integrations'))->assertOk()->assertSee('Integration Center');
        $this->get(route('ipilot.api-docs'))->assertOk()->assertSee('X-Link API v2 Documentation');
        $this->get(route('ipilot.zabbix'))->assertOk()->assertSee('Zabbix Monitoring');
        $this->get(route('ipilot.reports'))->assertOk()->assertSee('Reports Hub');
        $this->get(route('ipilot.btrc'))->assertOk()->assertSee('BTRC Report — Draft Export');
        $this->get(route('ipilot.terminal'))->assertOk()->assertSee('iPilot Read-only Terminal');
    }

    public function test_api_client_secret_is_hashed_and_short_lived_token_can_read_approved_resources(): void
    {
        $this->post(route('ipilot.integrations.clients.store'), [
            'name' => 'Test ERP',
            'scopes' => ['customers:read', 'network:read'],
            'rate_limit_per_minute' => 60,
        ])->assertRedirect(route('ipilot.integrations'))
          ->assertSessionHas('new_api_client');

        $client = IntegrationApiClient::firstOrFail();
        $credentials = session('new_api_client');
        $this->assertNotSame($credentials['client_secret'], $client->client_secret_hash);
        $this->assertSame(hash('sha256', $credentials['client_secret']), $client->client_secret_hash);

        $response = $this->postJson('/api/v2/auth/token', [
            'client_id' => $client->client_id,
            'client_secret' => $credentials['client_secret'],
        ])->assertOk()->assertJsonPath('token_type', 'Bearer');

        $token = $response->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v2/network/routers')
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_api_v2_rejects_scopes_not_granted_to_the_client(): void
    {
        $secret = 'xsec_test_only_secret';
        $client = IntegrationApiClient::create([
            'user_id' => $this->admin->id,
            'name' => 'Customer-only Client',
            'client_id' => 'xlc_customer_only_test',
            'client_secret_hash' => hash('sha256', $secret),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);

        $token = $this->postJson('/api/v2/auth/token', [
            'client_id' => $client->client_id,
            'client_secret' => $secret,
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v2/network/routers')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_revoking_api_client_removes_its_active_bearer_tokens(): void
    {
        $client = IntegrationApiClient::create([
            'user_id' => $this->admin->id,
            'name' => 'Revocation Test',
            'client_id' => 'xlc_revoke_test',
            'client_secret_hash' => hash('sha256', 'test-secret'),
            'scopes' => ['network:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);
        $issued = $this->admin->createToken('api-client:'.$client->client_id, ['network:read']);
        $client->update(['is_active' => false]);
        $issued->accessToken->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($issued->plainTextToken)->getJson('/api/v2/network/routers')->assertUnauthorized();
    }

    public function test_webhook_secret_is_encrypted_and_test_delivery_is_queued(): void
    {
        Queue::fake();

        $this->post(route('ipilot.integrations.webhooks.store'), [
            'name' => 'CRM',
            'url' => 'https://hooks.example.test/xlink',
            'events' => ['customer.created', 'payment.received'],
        ])->assertRedirect(route('ipilot.integrations'))->assertSessionHas('new_webhook_secret');

        $endpoint = IntegrationWebhookEndpoint::firstOrFail();
        $secret = session('new_webhook_secret');

        $this->assertNotSame($secret, $endpoint->getRawOriginal('secret_encrypted'));
        $this->assertSame($secret, $endpoint->secret);
        $this->assertSame(['customer.created', 'payment.received'], $endpoint->events);

        $this->post(route('ipilot.integrations.webhooks.test', $endpoint))
            ->assertRedirect();
        Queue::assertPushed(\App\Jobs\DeliverIntegrationWebhook::class);
    }

    public function test_webhook_creation_rejects_http_and_private_ip_destinations(): void
    {
        $this->from(route('ipilot.integrations'))->post(route('ipilot.integrations.webhooks.store'), [
            'name' => 'Unsafe Endpoint',
            'url' => 'http://example.test/hook',
            'events' => ['customer.created'],
        ])->assertStatus(422);

        $this->from(route('ipilot.integrations'))->post(route('ipilot.integrations.webhooks.store'), [
            'name' => 'Private Endpoint',
            'url' => 'https://127.0.0.1/hook',
            'events' => ['customer.created'],
        ])->assertStatus(422);
    }

    public function test_zabbix_api_token_is_encrypted_and_connection_test_is_read_only(): void
    {
        $this->post(route('ipilot.zabbix.store'), [
            'name' => 'Test Zabbix',
            'api_url' => 'https://zabbix.example.test',
            'api_token' => 'zabbix-secret-token',
        ])->assertRedirect(route('ipilot.zabbix'));

        $server = ZabbixServer::firstOrFail();
        $this->assertNotSame('zabbix-secret-token', $server->getRawOriginal('api_token_encrypted'));
        $this->assertSame('zabbix-secret-token', $server->api_token);

        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'result' => '7.0.1', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'result' => [['hostid' => '1']], 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'result' => [['eventid' => '1']], 'id' => 1]);

        $this->post(route('ipilot.zabbix.test', $server))->assertRedirect();

        $this->assertSame('7.0.1', $server->fresh()->api_version);
        $this->assertSame(1, $server->fresh()->host_count);
        $this->assertSame(1, $server->fresh()->problem_count);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api_jsonrpc.php'));
    }

    public function test_terminal_refuses_device_write_commands_and_serves_read_only_help(): void
    {
        $this->postJson(route('ipilot.terminal.chat'), ['command' => '/help'])
            ->assertOk()->assertJsonPath('read_only', true);

        $this->postJson(route('ipilot.terminal.chat'), ['command' => '/reboot router-1'])
            ->assertStatus(422)
            ->assertJsonPath('read_only', true);

        $this->postJson(route('ipilot.terminal.chat'), ['command' => 'please configure vlan 20'])
            ->assertStatus(422);
    }

    public function test_btrc_draft_export_is_labelled_as_not_official_template(): void
    {
        $response = $this->get(route('ipilot.btrc.export', [
            'operator_code' => 'TEST-OP',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'status' => 'all',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('BTRC Report Draft (Not Official Template)', $response->streamedContent());
    }
    public function test_zabbix_hosts_endpoint_returns_read_only_host_inventory_without_credentials(): void
    {
        $server = new ZabbixServer([
            'created_by' => $this->admin->id,
            'name' => 'Hosts Test',
            'api_url' => 'https://zabbix.example.test',
            'is_active' => true,
        ]);
        $server->api_token = 'never-return-this-token';
        $server->save();

        Http::fake([
            'https://zabbix.example.test/api_jsonrpc.php' => Http::response([
                'jsonrpc' => '2.0',
                'result' => [[
                    'hostid' => '101',
                    'host' => 'core-olt',
                    'name' => 'Core OLT',
                    'status' => '0',
                    'interfaces' => [[
                        'ip' => '10.20.0.10',
                        'dns' => '',
                        'main' => '1',
                        'useip' => '1',
                    ]],
                    'groups' => [['name' => 'Network Devices']],
                ]],
                'id' => 1,
            ], 200),
        ]);

        $this->getJson(route('ipilot.zabbix.hosts', $server))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Core OLT')
            ->assertJsonPath('data.0.address', '10.20.0.10')
            ->assertJsonPath('data.0.groups.0', 'Network Devices')
            ->assertJsonPath('data.0.status', 'enabled')
            ->assertJsonMissing(['api_token' => 'never-return-this-token']);

        Http::assertSent(fn ($request) => ($request['method'] ?? null) === 'host.get'
            && isset($request['params']['selectInterfaces'])
            && isset($request['params']['selectGroups']));
    }
    public function test_api_client_settings_change_revokes_previously_issued_tokens(): void
    {
        $client = IntegrationApiClient::create([
            'user_id' => $this->admin->id,
            'name' => 'Scope Change Test',
            'client_id' => 'xlc_scope_change_test',
            'client_secret_hash' => hash('sha256', 'test-secret'),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);

        $issued = $this->admin->createToken('api-client:'.$client->client_id, ['customers:read']);
        $tokenId = $issued->accessToken->id;

        $this->put(route('ipilot.integrations.clients.update', $client), [
            'scopes' => ['customers:read', 'billing:read'],
            'rate_limit_per_minute' => 90,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    private function staffWithPermissions(string $roleName, array $permissions): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_admin_and_manager_only_see_scopes_granted_by_their_own_permissions(): void
    {
        $manager = $this->staffWithPermissions('Manager', ['view-customer']);
        $this->actingAs($manager);

        $this->get(route('ipilot.integrations'))
            ->assertOk()
            ->assertSee('value="customers:read"', false)
            ->assertDontSee('value="customers:contact:read"', false)
            ->assertDontSee('value="network:read"', false)
            ->assertDontSee('value="packages:read"', false);

        $this->post(route('ipilot.integrations.clients.store'), [
            'name' => 'Over-scoped Manager Client',
            'scopes' => ['customers:read', 'network:read'],
            'rate_limit_per_minute' => 60,
        ])->assertForbidden();

        $this->assertDatabaseMissing('integration_api_clients', ['name' => 'Over-scoped Manager Client']);
    }

    public function test_api_client_scope_update_rejects_permissions_the_owner_does_not_have(): void
    {
        $owner = $this->staffWithPermissions('Admin', ['view-customer']);
        $client = IntegrationApiClient::create([
            'user_id' => $owner->id,
            'name' => 'Owner Scope Guard',
            'client_id' => 'xlc_owner_scope_guard',
            'client_secret_hash' => hash('sha256', 'owner-secret'),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($owner)->put(route('ipilot.integrations.clients.update', $client), [
            'scopes' => ['network:read'],
            'rate_limit_per_minute' => 90,
            'is_active' => '1',
        ])->assertForbidden();

        $this->assertSame(['customers:read'], $client->fresh()->scopes);
        $this->assertSame(60, $client->fresh()->rate_limit_per_minute);
    }

    public function test_admin_or_manager_cannot_view_or_manage_another_users_integrations(): void
    {
        $actor = $this->staffWithPermissions('Admin', ['view-customer']);
        $owner = $this->staffWithPermissions('Admin', ['view-customer']);
        $client = IntegrationApiClient::create([
            'user_id' => $owner->id,
            'name' => 'Private Owner Client',
            'client_id' => 'xlc_private_owner_test',
            'client_secret_hash' => hash('sha256', 'private-secret'),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);
        $endpoint = new IntegrationWebhookEndpoint([
            'user_id' => $owner->id,
            'name' => 'Private Owner Webhook',
            'url' => 'https://hooks.example.test/private',
            'events' => ['customer.created'],
            'is_active' => true,
        ]);
        $endpoint->secret = 'private-webhook-secret';
        $endpoint->save();

        $this->actingAs($actor)
            ->get(route('ipilot.integrations'))
            ->assertOk()
            ->assertDontSee('Private Owner Client')
            ->assertDontSee('Private Owner Webhook');

        $this->delete(route('ipilot.integrations.clients.delete', $client))->assertForbidden();
        $this->post(route('ipilot.integrations.webhooks.test', $endpoint))->assertForbidden();
        $this->patch(route('ipilot.integrations.webhooks.toggle', $endpoint))->assertForbidden();
    }

    public function test_api_customer_mobile_requires_the_explicit_contact_scope(): void
    {
        $customer = \App\Models\CustomersInfo::create([
            'customer_unique_id' => 'API-PII-001',
            'customer_name' => 'API Contact Scope Test',
            'mobile' => '01712345678',
            'status' => 'active',
            'disable_count' => 0,
        ]);

        $basicClient = IntegrationApiClient::create([
            'user_id' => $this->admin->id,
            'name' => 'Basic Customer Read',
            'client_id' => 'xlc_basic_customer_read',
            'client_secret_hash' => hash('sha256', 'basic-secret'),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);
        $basicToken = $this->admin->createToken('api-client:'.$basicClient->client_id, ['customers:read'])->plainTextToken;

        $contactClient = IntegrationApiClient::create([
            'user_id' => $this->admin->id,
            'name' => 'Customer Contact Read',
            'client_id' => 'xlc_customer_contact_read',
            'client_secret_hash' => hash('sha256', 'contact-secret'),
            'scopes' => ['customers:read', 'customers:contact:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);
        $contactToken = $this->admin->createToken('api-client:'.$contactClient->client_id, ['customers:read', 'customers:contact:read'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($basicToken)->getJson('/api/v2/customers?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.customer_id', $customer->customer_unique_id)
            ->assertJsonPath('data.0.mobile', null);

        $this->app['auth']->forgetGuards();
        $this->withToken($contactToken)->getJson('/api/v2/customers?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.customer_id', $customer->customer_unique_id)
            ->assertJsonPath('data.0.mobile', '01712345678');
    }
    public function test_non_super_admin_can_only_create_scopes_allowed_by_their_permissions(): void
    {
        $managerRole = Role::findOrCreate('Manager', 'web');
        $managerRole->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->assignRole($managerRole);
        $manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-customer', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        $this->get(route('ipilot.integrations'))
            ->assertOk()
            ->assertSee('customers:read')
            ->assertDontSee('network:read');

        $this->from(route('ipilot.integrations'))->post(route('ipilot.integrations.clients.store'), [
            'name' => 'Unauthorized Network Client',
            'scopes' => ['network:read'],
            'rate_limit_per_minute' => 60,
        ])->assertForbidden();

        $this->post(route('ipilot.integrations.clients.store'), [
            'name' => 'Allowed Customer Client',
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
        ])->assertRedirect(route('ipilot.integrations'));

        $this->assertDatabaseHas('integration_api_clients', [
            'name' => 'Allowed Customer Client',
            'user_id' => $manager->id,
        ]);
    }

    public function test_non_super_admin_cannot_change_or_revoke_another_users_api_client(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $client = IntegrationApiClient::create([
            'user_id' => $owner->id,
            'name' => 'Privately Owned Client',
            'client_id' => 'xlc_private_owner_test',
            'client_secret_hash' => hash('sha256', 'private-secret'),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);

        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->assignRole(Role::findOrCreate('Manager', 'web'));
        $manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-customer', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($manager);

        $this->put(route('ipilot.integrations.clients.update', $client), [
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 90,
            'is_active' => '1',
        ])->assertForbidden();

        $this->delete(route('ipilot.integrations.clients.delete', $client))->assertForbidden();
        $this->assertDatabaseHas('integration_api_clients', ['id' => $client->id, 'is_active' => true]);
    }

    public function test_client_owner_cannot_escalate_scope_after_losing_module_permission(): void
    {
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->assignRole(Role::findOrCreate('Manager', 'web'));
        $manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-customer', 'web'));
        $client = IntegrationApiClient::create([
            'user_id' => $manager->id,
            'name' => 'Owner Scope Change',
            'client_id' => 'xlc_owner_scope_test',
            'client_secret_hash' => hash('sha256', 'owner-secret'),
            'scopes' => ['customers:read'],
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);
        $this->actingAs($manager);

        $this->put(route('ipilot.integrations.clients.update', $client), [
            'scopes' => ['customers:read', 'network:read'],
            'rate_limit_per_minute' => 90,
            'is_active' => '1',
        ])->assertForbidden();

        $this->assertSame(['customers:read'], $client->fresh()->scopes);
    }

}
