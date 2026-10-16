<?php

namespace Tests\Feature;

use App\Models\NetworkInventoryDevice;
use App\Models\OltProvisioningAudit;
use App\Models\User;
use App\Services\Olt\VsolCliProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use App\Services\Olt\VsolModelDetector;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OltProvisioningSafetyTest extends TestCase
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

    public function test_provisioning_ui_shows_safety_state_and_disables_execution_without_exact_model(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Unidentified VSOL', 'vendor' => 'VSOL',
            'ip_address' => '192.0.2.253', 'model' => null,
            'status' => 'unknown', 'health_status' => 'unknown',
        ]);

        $this->get(route('network-inventory.olt.provisioning', $olt))
            ->assertOk()
            ->assertSee('Safety lock')
            ->assertSee('NOT SET')
            ->assertSee('id="executeProvisioning" class="btn btn-primary" disabled', false);
    }

    public function test_exact_model_mismatch_is_audited_and_never_connects_to_the_olt(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Unidentified VSOL', 'vendor' => 'VSOL',
            'ip_address' => '192.0.2.254', 'model' => null,
            'status' => 'unknown', 'health_status' => 'unknown',
            'adapter_config' => json_encode(['vsol_model_profile' => 'v1600d_ep_series_v1_2']),
        ]);

        $this->from(route('network-inventory.olt.provisioning', $olt))
            ->post(route('network-inventory.olt.provision', $olt), [
                'profile' => 'v1600d_ep_series_v1_2',
                'action' => 'authorize_mac',
                'pon' => '0/1',
                'mac' => '00:11:22:33:44:55',
            ])
            ->assertRedirect(route('network-inventory.olt.provisioning', $olt))
            ->assertSessionHasErrors('provisioning');

        $audit = OltProvisioningAudit::firstOrFail();
        $this->assertSame('failed', $audit->status);
        $this->assertStringContainsString('exactly match', $audit->error);
        $this->assertSame('00:11:22:33:44:55', $audit->request_payload['mac']);
        $this->assertSame(0, (int) $audit->duration_ms);
    }

    public function test_olt_credentials_are_encrypted_and_cli_flags_are_saved(): void
    {
        $response = $this->post(route('network-inventory.olt.store'), [
            'name' => 'Provisioning Test OLT',
            'host' => '192.0.2.250',
            'ip_address' => '192.0.2.250',
            'vendor' => 'VSOL',
            'model' => 'V1600D',
            'username' => 'test-admin',
            'password' => 'test-secret',
            'ssh_port' => 2222,
            'ssh_enabled' => 1,
            'provisioning_enabled' => 1,
            'status' => 'unknown',
        ]);

        $response->assertRedirect(route('network-inventory.olt'));
        $olt = NetworkInventoryDevice::where('name', 'Provisioning Test OLT')->firstOrFail();
        $this->assertNotSame('test-admin', $olt->getRawOriginal('username'));
        $this->assertNotSame('test-secret', $olt->getRawOriginal('password'));
        $this->assertSame('test-admin', Crypt::decryptString($olt->getRawOriginal('username')));
        $this->assertSame('test-secret', Crypt::decryptString($olt->getRawOriginal('password')));
        $this->assertSame(2222, (int) $olt->ssh_port);
        $this->assertTrue($olt->ssh_enabled);
        $this->assertTrue($olt->provisioning_enabled);
    }

    public function test_olt_form_exposes_snmp_configuration(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type'=>'olt','name'=>'SNMP Form OLT','ip_address'=>'192.0.2.240',
            'status'=>'unknown','health_status'=>'unknown','snmp_version'=>'2C',
        ]);
        $this->get(route('network-inventory.olt.edit',$olt))
            ->assertOk()
            ->assertSee('SNMP Version')
            ->assertSee('SNMP Community')
            ->assertSee('name="snmp_community"', false);
    }

    public function test_vsol_model_detector_selects_exact_v1600d_profile(): void
    {
        $result = (new VsolModelDetector())->detect('VSOL V1600D EPON OLT Software Version 1.2');
        $this->assertSame('detected', $result['status']);
        $this->assertSame('V1600D', $result['model']);
        $this->assertSame('v1600d_ep_series_v1_2', $result['profile']);
    }

    public function test_vsol_model_detector_does_not_guess_unknown_hardware(): void
    {
        $result = (new VsolModelDetector())->detect('VSOL EPON OLT Software Version 9.9');
        $this->assertSame('unknown', $result['status']);
    }

    public function test_discovery_route_is_available_without_enabling_provisioning(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Discovery OLT', 'vendor' => 'VSOL',
            'ip_address' => '192.0.2.252', 'model' => null,
            'status' => 'unknown', 'health_status' => 'unknown',
            'ssh_enabled' => false, 'provisioning_enabled' => false,
        ]);

        $this->get(route('network-inventory.olt.provisioning', $olt))
            ->assertOk()
            ->assertSee('Read-only: Discover VSOL version')
            ->assertSee('show version');
    }

    public function test_configured_multi_line_commands_are_not_truncated_to_the_first_line(): void
    {
        $service = new VsolCliProvisioningService();
        $reflection = new ReflectionClass($service);
        $property = $reflection->getProperty('config');
        $property->setValue($service, [
            'commands' => [
                'authorize_mac' => [
                    'configure terminal',
                    'interface epon {{pon}}',
                    'onu-auth mode mac',
                    'onu mac-auth add {{mac}}',
                    'exit',
                    'exit',
                ],
            ],
        ]);
        $method = $reflection->getMethod('command');
        $command = $method->invoke($service, 'authorize_mac', [
            'pon' => '0/1',
            'mac' => '00:11:22:33:44:55',
        ]);

        $this->assertSame(6, substr_count($command, "\n") + 1);
        $this->assertStringContainsString('onu mac-auth add 00:11:22:33:44:55', $command);
        $this->assertStringEndsWith("exit\nexit", $command);
    }
}
