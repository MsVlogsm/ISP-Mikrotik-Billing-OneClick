<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoMapOltCustomersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RouterList::create([
            'router_name' => 'AutoMapRouter',
            'ip_address' => '127.0.0.1',
            'username' => 'automap-user',
            'password' => 'automap-password',
            'action' => 'disconnected',
        ]);
    }

    private function olt(): NetworkInventoryDevice
    {
        return NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'VSOL Auto Mapping Test',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);
    }

    private function customer(string $cid, string $username, string $callerMac, string $pppStatus = 'active'): array
    {
        $ppp = PPPSecrets::create([
            'router_name' => 'AutoMapRouter',
            'username' => $username,
            'password' => 'test-secret',
            'service' => 'pppoe',
            'profile' => 'Home',
            'status' => $pppStatus,
            'caller_id' => '',
            'last_caller_id' => $callerMac,
        ]);

        $customer = CustomersInfo::create([
            'customer_unique_id' => $cid,
            'customer_name' => 'Customer '.$cid,
            'status' => 'active',
            'ppp_user_id' => $ppp->id,
        ]);

        return [$customer, $ppp];
    }

    private function mapping(NetworkInventoryDevice $olt, string $onuMac = '00:11:46:95:1c:c5'): OltOnuCustomerMapping
    {
        return OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'pon_port' => '1',
            'onu_id' => '25',
            'onu_mac' => $onuMac,
            'status' => 'online',
            'notes' => 'Automatically discovered from verified read-only OLT polling.',
        ]);
    }

    private function fakeAdapter(NetworkInventoryDevice $olt, array $onus, string $status = 'ok'): void
    {
        $manager = \Mockery::mock(OltReadOnlyAdapterManager::class);
        $manager->shouldReceive('read')->once()
            ->with(\Mockery::on(fn ($device) => $device instanceof NetworkInventoryDevice && $device->id === $olt->id))
            ->andReturn([
                'ok' => $status === 'ok',
                'status' => $status,
                'message' => 'test read-only response',
                'meta' => [
                    'read_only' => true,
                    'writes_performed' => false,
                    'onu_count' => count($onus),
                ],
                'onus' => $onus,
            ]);

        $this->app->instance(OltReadOnlyAdapterManager::class, $manager);
    }

    private function liveOnu(string $onuMac, array $clientMacs, string $onuId = '25', string $pon = '1', string $status = 'online'): array
    {
        return [
            'pon_port' => $pon,
            'onu_id' => $onuId,
            'onu_mac' => $onuMac,
            'client_macs' => $clientMacs,
            'status' => $status,
        ];
    }

    public function test_dry_run_reports_unique_exact_caller_mac_without_writing_mapping(): void
    {
        $olt = $this->olt();
        [$customer] = $this->customer('AUTO-DRY-01', 'auto.dry.user', 'a4:b1:c1:22:33:44');
        $mapping = $this->mapping($olt);
        $this->fakeAdapter($olt, [$this->liveOnu('00:11:46:95:1c:c5', ['a4:b1:c1:22:33:44'])]);

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id])
            ->expectsOutputToContain('WOULD MAP PON 1 / ONU 25')
            ->assertExitCode(0);

        $this->assertNull($mapping->fresh()->customer_id);
        $this->assertNull($mapping->fresh()->ppp_user_id);
        $this->assertDatabaseCount('fiber_mapping_audits', 0);
    }

    public function test_apply_maps_only_unique_active_exact_fdb_to_pppoe_mac_and_writes_audit(): void
    {
        $olt = $this->olt();
        [$customer, $ppp] = $this->customer('AUTO-APPLY-01', 'auto.apply.user', 'a4:b1:c1:22:33:45');
        $mapping = $this->mapping($olt);
        $this->fakeAdapter($olt, [$this->liveOnu('00:11:46:95:1c:c5', ['a4:b1:c1:22:33:45'])]);

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id, '--apply' => true])
            ->expectsOutputToContain('Mapped PON 1 / ONU 25')
            ->assertExitCode(0);

        $this->assertSame($customer->id, $mapping->fresh()->customer_id);
        $this->assertSame($ppp->id, $mapping->fresh()->ppp_user_id);
        $this->assertDatabaseHas('fiber_mapping_audits', [
            'entity_type' => 'onu_mapping',
            'entity_id' => $mapping->id,
            'action' => 'auto_customer_mapped',
            'operator_id' => null,
        ]);
    }

    public function test_identity_conflict_is_never_auto_mapped_even_with_exact_client_mac(): void
    {
        $olt = $this->olt();
        $this->customer('AUTO-CONFLICT-01', 'auto.conflict.user', 'a4:b1:c1:22:33:46');
        $mapping = $this->mapping($olt, 'aa:bb:cc:dd:ee:01');
        $this->fakeAdapter($olt, [$this->liveOnu('aa:bb:cc:dd:ee:02', ['a4:b1:c1:22:33:46'])]);

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id, '--apply' => true])
            ->expectsOutputToContain('BLOCKED identity conflict')
            ->assertExitCode(0);

        $this->assertNull($mapping->fresh()->customer_id);
        $this->assertNull($mapping->fresh()->ppp_user_id);
        $this->assertDatabaseCount('fiber_mapping_audits', 0);
    }

    public function test_same_client_mac_visible_behind_two_onus_is_ambiguous_and_never_mapped(): void
    {
        $olt = $this->olt();
        $this->customer('AUTO-DUP-FDB-01', 'auto.duplicate.fdb.user', 'a4:b1:c1:22:33:47');
        $first = $this->mapping($olt, '00:11:46:95:1c:c5');
        $second = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'pon_port' => '2',
            'onu_id' => '25',
            'onu_mac' => '00:11:46:95:1c:c6',
            'status' => 'online',
        ]);
        $sharedClientMac = 'a4:b1:c1:22:33:47';
        $this->fakeAdapter($olt, [
            $this->liveOnu('00:11:46:95:1c:c5', [$sharedClientMac], '25', '1'),
            $this->liveOnu('00:11:46:95:1c:c6', [$sharedClientMac], '25', '2'),
        ]);

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertNull($first->fresh()->customer_id);
        $this->assertNull($second->fresh()->customer_id);
        $this->assertDatabaseCount('fiber_mapping_audits', 0);
    }

    public function test_multiple_active_customer_ppp_pairs_with_same_caller_mac_are_ambiguous(): void
    {
        $olt = $this->olt();
        $clientMac = 'a4:b1:c1:22:33:48';
        $this->customer('AUTO-DUP-PPP-01', 'auto.duplicate.one', $clientMac);
        $this->customer('AUTO-DUP-PPP-02', 'auto.duplicate.two', $clientMac);
        $mapping = $this->mapping($olt);
        $this->fakeAdapter($olt, [$this->liveOnu('00:11:46:95:1c:c5', [$clientMac])]);

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id, '--apply' => true])
            ->expectsOutputToContain('BLOCKED ambiguous PPPoE caller MACs')
            ->assertExitCode(0);

        $this->assertNull($mapping->fresh()->customer_id);
        $this->assertNull($mapping->fresh()->ppp_user_id);
    }

    public function test_offline_onu_is_not_automatically_mapped(): void
    {
        $olt = $this->olt();
        $this->customer('AUTO-OFFLINE-01', 'auto.offline.user', 'a4:b1:c1:22:33:49');
        $mapping = $this->mapping($olt);
        $this->fakeAdapter($olt, [$this->liveOnu('00:11:46:95:1c:c5', ['a4:b1:c1:22:33:49'], '25', '1', 'offline')]);

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertNull($mapping->fresh()->customer_id);
        $this->assertNull($mapping->fresh()->ppp_user_id);
    }

    public function test_partial_poll_cannot_trigger_customer_mapping(): void
    {
        $olt = $this->olt();
        $this->customer('AUTO-PARTIAL-01', 'auto.partial.user', 'a4:b1:c1:22:33:50');
        $mapping = $this->mapping($olt);
        $this->fakeAdapter($olt, [$this->liveOnu('00:11:46:95:1c:c5', ['a4:b1:c1:22:33:50'])], 'partial');

        $this->artisan('olt:auto-map-customers', ['--device' => $olt->id, '--apply' => true])
            ->expectsOutputToContain('incomplete/unverified poll')
            ->assertExitCode(1);

        $this->assertNull($mapping->fresh()->customer_id);
        $this->assertNull($mapping->fresh()->ppp_user_id);
    }
}
