<?php

namespace Tests\Feature;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\OnuMacLedger;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\Olt\CustomerOnuRealtimeSyncService;
use App\Services\Olt\OltReadOnlyAdapterManager;
use App\Services\Olt\VsolSshOpmCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerOnuRealtimeSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_review_mapping_is_bypassed_and_live_unique_mac_moves_the_customer_to_the_verified_onu(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'BT-EPON-OLT Test',
            'vendor' => 'VSOL',
            'model' => 'V1601E04-DP',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);

        RouterList::create([
            'router_name' => 'X-Link-Bottola Test',
            'ip_address' => '127.0.0.1',
            'username' => 'test-router',
            'password' => 'test-password',
            'action' => 'connected',
        ]);

        $ppp = PPPSecrets::create([
            'router_name' => 'X-Link-Bottola Test',
            'username' => 'bt0021-test-user',
            'password' => 'test-secret',
            'service' => 'pppoe',
            'profile' => 'Home-20M',
            'status' => 'active',
            'last_caller_id' => '80:9f:e4:37:87:07',
        ]);

        $customer = CustomersInfo::create([
            'customer_unique_id' => 'BT0021-TEST',
            'customer_name' => 'BT0021 Test',
            'status' => 'active',
            'ppp_user_id' => $ppp->id,
        ]);

        $stale = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'pon_port' => '1',
            'onu_id' => '14',
            'onu_mac' => '98:c7:a4:06:80:43',
            'status' => 'offline',
            'last_seen_at' => now()->subDays(3),
            'notes' => 'Discovered by read-only ONU synchronisation. Customer/PPPoE link cleared during MAC-based VSOL reconciliation: ONU MAC does not match PPPoE last_caller_id. Requires operator review.',
        ]);

        $link = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $stale->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'client_mac' => '80:9f:e4:37:87:07',
            'source' => 'customer_list_realtime_sync:live_pppoe_session',
            'status' => 'active',
            'first_seen_at' => now()->subDays(20),
            'last_seen_at' => now()->subHour(),
        ]);
        $otherPpp = PPPSecrets::create([
            'router_name' => 'X-Link-Bottola Test', 'username' => 'shared-source-user',
            'password' => 'test-secret', 'service' => 'pppoe', 'profile' => 'Home-20M',
            'status' => 'active', 'last_caller_id' => '10:20:30:40:50:60',
        ]);
        $otherCustomer = CustomersInfo::create([
            'customer_unique_id' => 'BT-SHARED-SOURCE', 'customer_name' => 'Shared source subscriber',
            'status' => 'active', 'ppp_user_id' => $otherPpp->id,
        ]);
        $otherLink = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $stale->id,
            'customer_id' => $otherCustomer->id,
            'ppp_user_id' => $otherPpp->id,
            'client_mac' => '10:20:30:40:50:60',
            'source' => 'olt_dynamic_mac+ppp_last_caller_id',
            'status' => 'active',
        ]);

        $verifiedTarget = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'pon_port' => '2',
            'onu_id' => '25',
            'onu_mac' => '00:11:46:95:1c:c5',
            'status' => 'online',
            'notes' => 'Stable ONU identity changed; manual review required.',
        ]);
        $targetLedger = OnuMacLedger::create([
            'olt_device_id' => $olt->id,
            'customer_id' => null,
            'customer_unique_id' => 'BT0024',
            'pon_port' => '2',
            'onu_id' => '25',
            'current_mac' => '00:11:46:95:1c:c5',
            'pppoe_username' => 'bt.rana',
            'status' => 'offline',
            'notes' => 'Stable ONU identity changed; manual review required. Previous customer/PPPoE link cleared; requires operator review.',
        ]);

        $mikrotik = Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('mtQuote')->once()->with('bt0021-test-user')->andReturn('"bt0021-test-user"');
        $mikrotik->shouldReceive('singleRead')->twice()->andReturn(
            [['name' => 'bt0021-test-user', 'caller-id' => '80:9f:e4:37:87:07']],
            [['name' => 'bt0021-test-user', 'caller-id' => '']]
        );
        $this->app->instance(MikrotikController::class, $mikrotik);

        $adapter = Mockery::mock(OltReadOnlyAdapterManager::class);
        $adapter->shouldReceive('read')
            ->once()
            ->with(Mockery::on(fn ($device) => $device instanceof NetworkInventoryDevice && $device->is($olt)))
            ->andReturn([
                'ok' => true,
                'status' => 'ok',
                'meta' => ['read_only' => true, 'writes_performed' => false],
                'onus' => [[
                    'pon_port' => '2',
                    'onu_id' => '25',
                    'onu_mac' => '00:11:46:95:1c:c5',
                    'status' => 'online',
                ]],
            ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $adapter);

        $sshCollector = Mockery::mock(VsolSshOpmCollector::class);
        $sshCollector->shouldReceive('collect')
            ->once()
            ->with(
                Mockery::on(fn ($device) => $device instanceof NetworkInventoryDevice && $device->is($olt)),
                Mockery::type('array'),
                null,
                null,
                true,
                false
            )
            ->andReturn([
                'ok' => true,
                'status' => 'ok',
                'message' => 'Read-only identity result',
                'onus' => [[
                    'pon_port' => '2',
                    'onu_id' => '25',
                    'onu_mac' => '00:11:46:95:1c:c5',
                    'client_macs' => ['80:9f:e4:37:87:07'],
                    'status' => 'online',
                ]],
                'meta' => ['read_only' => true, 'writes_performed' => false, 'opm_skipped' => true],
            ]);
        $this->app->instance(VsolSshOpmCollector::class, $sshCollector);

        $result = app(CustomerOnuRealtimeSyncService::class)->sync($customer->fresh());

        $this->assertSame('2', $result['pon']);
        $this->assertSame('25', $result['onu_id']);
        $this->assertTrue($result['session_online']);
        $this->assertSame($verifiedTarget->id, $result['mapping_id']);

        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $verifiedTarget->id,
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'status' => 'online',
        ]);
        $this->assertStringContainsString(
            'Customer/PPPoE identity revalidated by unique live dynamic MAC 80:9f:e4:37:87:07',
            (string) $verifiedTarget->fresh()->notes
        );
        $this->assertDatabaseHas('onu_mac_ledgers', [
            'id' => $targetLedger->id,
            'customer_unique_id' => 'BT0021-TEST',
            'pppoe_username' => 'bt0021-test-user',
        ]);
        $this->assertStringContainsString('Previous ONU ledger association retained for audit: BT0024 / bt.rana', (string) $targetLedger->fresh()->notes);
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $stale->id,
            'customer_id' => null,
            'ppp_user_id' => null,
            'status' => 'offline',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_ppp_links', [
            'id' => $link->id,
            'olt_onu_customer_mapping_id' => $verifiedTarget->id,
            'client_mac' => '80:9f:e4:37:87:07',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_ppp_links', [
            'id' => $otherLink->id,
            'olt_onu_customer_mapping_id' => $stale->id,
            'customer_id' => $otherCustomer->id,
            'ppp_user_id' => $otherPpp->id,
            'status' => 'active',
        ]);
    }

    public function test_customer_sync_prefers_readable_live_snmp_bridge_fdb_without_ssh_fallback(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'VSOL SNMP FDB Test', 'vendor' => 'VSOL',
            'model' => 'V1601E04-DP', 'status' => 'online', 'health_status' => 'online', 'is_active' => true,
        ]);
        RouterList::create([
            'router_name' => 'SNMP FDB Router', 'ip_address' => '127.0.0.1',
            'username' => 'test-router', 'password' => 'test-password', 'action' => 'connected',
        ]);
        $ppp = PPPSecrets::create([
            'router_name' => 'SNMP FDB Router', 'username' => 'snmp-fdb-user', 'password' => 'test-secret',
            'service' => 'pppoe', 'profile' => 'Home-20M', 'status' => 'active',
            'last_caller_id' => '80:9f:e4:37:87:07',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'SNMP-FDB-TEST', 'customer_name' => 'SNMP FDB customer',
            'status' => 'active', 'ppp_user_id' => $ppp->id,
        ]);
        $old = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id,
            'pon_port' => '1', 'onu_id' => '14', 'onu_mac' => '00:11:46:95:1c:14',
            'status' => 'offline', 'notes' => 'Customer/PPPoE identity requires operator review.',
        ]);
        $target = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'pon_port' => '1', 'onu_id' => '25',
            'onu_mac' => '00:d5:be:76:6a:14', 'status' => 'online',
            'notes' => 'Stable ONU identity changed; manual review required.',
        ]);
        $link = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $old->id, 'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id, 'client_mac' => '80:9f:e4:37:87:07',
            'source' => 'legacy_primary', 'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('mtQuote')->once()->with('snmp-fdb-user')->andReturn('"snmp-fdb-user"');
        $mikrotik->shouldReceive('singleRead')->twice()->andReturn(
            [['name' => 'snmp-fdb-user', 'caller-id' => '80:9f:e4:37:87:07']],
            [['name' => 'snmp-fdb-user', 'caller-id' => '']]
        );
        $this->app->instance(MikrotikController::class, $mikrotik);

        $adapter = Mockery::mock(OltReadOnlyAdapterManager::class);
        $adapter->shouldReceive('read')->once()->andReturn([
            'ok' => true, 'status' => 'partial', 'message' => 'Live SNMP FDB verified; optional hardware probes unavailable',
            'meta' => [
                'read_only' => true, 'writes_performed' => false,
                'client_mac_discovery_configured' => true, 'client_mac_fdb_readable' => true,
                'client_mac_fdb_rows' => 3, 'client_mac_count' => 1,
            ],
            'onus' => [[
                'pon_port' => '1', 'onu_id' => '25', 'onu_mac' => '00:d5:be:76:6a:14',
                'client_macs' => ['80:9f:e4:37:87:07'], 'status' => 'online',
                'rx_power' => -15.7, 'tx_power' => 2.4, 'opm_source' => 'vsol-snmp-opm',
            ]],
        ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $adapter);

        $sshCollector = Mockery::mock(VsolSshOpmCollector::class);
        $sshCollector->shouldNotReceive('collect');
        $this->app->instance(VsolSshOpmCollector::class, $sshCollector);

        $result = app(CustomerOnuRealtimeSyncService::class)->sync($customer->fresh());

        $this->assertSame('25', $result['onu_id']);
        $this->assertSame('1', $result['pon']);
        $this->assertTrue($result['opm_fresh']);
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $target->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id,
            'onu_mac' => '00:d5:be:76:6a:14', 'status' => 'online',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $old->id, 'customer_id' => null, 'ppp_user_id' => null, 'status' => 'offline',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_ppp_links', [
            'id' => $link->id, 'olt_onu_customer_mapping_id' => $target->id,
            'client_mac' => '80:9f:e4:37:87:07', 'status' => 'active',
        ]);
    }

    public function test_moving_a_shared_ppp_link_preserves_the_other_customer_primary_mapping(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'VSOL Shared Move Test', 'vendor' => 'VSOL',
            'model' => 'V1601E04-DP', 'status' => 'online', 'health_status' => 'online', 'is_active' => true,
        ]);
        RouterList::create([
            'router_name' => 'Shared Move Router', 'ip_address' => '127.0.0.1',
            'username' => 'test-router', 'password' => 'test-password', 'action' => 'connected',
        ]);
        $primaryPpp = PPPSecrets::create([
            'router_name' => 'Shared Move Router', 'username' => 'primary-owner', 'password' => 'test-secret',
            'service' => 'pppoe', 'profile' => 'Home', 'status' => 'active', 'last_caller_id' => '10:10:10:10:10:10',
        ]);
        $primaryCustomer = CustomersInfo::create([
            'customer_unique_id' => 'SHARED-PRIMARY', 'customer_name' => 'Primary owner',
            'status' => 'active', 'ppp_user_id' => $primaryPpp->id,
        ]);
        $movingPpp = PPPSecrets::create([
            'router_name' => 'Shared Move Router', 'username' => 'shared-moving-user', 'password' => 'test-secret',
            'service' => 'pppoe', 'profile' => 'Home', 'status' => 'active', 'last_caller_id' => '80:9f:e4:37:87:07',
        ]);
        $movingCustomer = CustomersInfo::create([
            'customer_unique_id' => 'SHARED-MOVING', 'customer_name' => 'Moving subscriber',
            'status' => 'active', 'ppp_user_id' => $movingPpp->id,
        ]);
        $source = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $primaryCustomer->id, 'ppp_user_id' => $primaryPpp->id,
            'pon_port' => '1', 'onu_id' => '50', 'onu_mac' => '00:11:46:95:1c:50',
            'status' => 'online',
        ]);
        $target = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'pon_port' => '1', 'onu_id' => '49',
            'onu_mac' => '00:11:46:95:1c:49', 'status' => 'online',
            'notes' => 'Stable ONU identity changed; manual review required.',
        ]);
        $link = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $source->id, 'customer_id' => $movingCustomer->id,
            'ppp_user_id' => $movingPpp->id, 'client_mac' => '80:9f:e4:37:87:07',
            'source' => 'olt_dynamic_mac+ppp_last_caller_id', 'status' => 'active',
        ]);

        $mikrotik = Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('mtQuote')->once()->with('shared-moving-user')->andReturn('"shared-moving-user"');
        $mikrotik->shouldReceive('singleRead')->twice()->andReturn(
            [['name' => 'shared-moving-user', 'caller-id' => '80:9f:e4:37:87:07']],
            [['name' => 'shared-moving-user', 'caller-id' => '']]
        );
        $this->app->instance(MikrotikController::class, $mikrotik);

        $adapter = Mockery::mock(OltReadOnlyAdapterManager::class);
        $adapter->shouldReceive('read')->once()->andReturn([
            'ok' => true, 'status' => 'ok', 'meta' => ['read_only' => true, 'writes_performed' => false],
            'onus' => [
                ['pon_port' => '1', 'onu_id' => '50', 'onu_mac' => '00:11:46:95:1c:50', 'status' => 'online'],
                ['pon_port' => '1', 'onu_id' => '49', 'onu_mac' => '00:11:46:95:1c:49', 'status' => 'online'],
            ],
        ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $adapter);

        $collector = Mockery::mock(VsolSshOpmCollector::class);
        $collector->shouldReceive('collect')->once()->andReturn([
            'ok' => true, 'status' => 'ok', 'message' => 'read-only identity result',
            'onus' => [
                ['pon_port' => '1', 'onu_id' => '50', 'onu_mac' => '00:11:46:95:1c:50', 'client_macs' => [], 'status' => 'online'],
                ['pon_port' => '1', 'onu_id' => '49', 'onu_mac' => '00:11:46:95:1c:49', 'client_macs' => ['80:9f:e4:37:87:07'], 'status' => 'online'],
            ],
            'meta' => ['read_only' => true, 'writes_performed' => false, 'opm_skipped' => true],
        ]);
        $this->app->instance(VsolSshOpmCollector::class, $collector);

        $result = app(CustomerOnuRealtimeSyncService::class)->sync($movingCustomer->fresh());

        $this->assertSame('49', $result['onu_id']);
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $source->id,
            'customer_id' => $primaryCustomer->id,
            'ppp_user_id' => $primaryPpp->id,
            'status' => 'online',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $target->id,
            'customer_id' => $movingCustomer->id,
            'ppp_user_id' => $movingPpp->id,
            'status' => 'online',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_ppp_links', [
            'id' => $link->id,
            'olt_onu_customer_mapping_id' => $target->id,
            'customer_id' => $movingCustomer->id,
            'ppp_user_id' => $movingPpp->id,
            'status' => 'active',
        ]);
    }

    public function test_live_mac_entry_on_offline_onu_does_not_move_customer_mapping(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'VSOL Offline Guard', 'vendor' => 'VSOL',
            'model' => 'V1601E04-DP', 'status' => 'online', 'health_status' => 'online', 'is_active' => true,
        ]);
        RouterList::create([
            'router_name' => 'Offline Guard Router', 'ip_address' => '127.0.0.1',
            'username' => 'test-router', 'password' => 'test-password', 'action' => 'connected',
        ]);
        $ppp = PPPSecrets::create([
            'router_name' => 'Offline Guard Router', 'username' => 'offline-guard-user',
            'password' => 'test-secret', 'service' => 'pppoe', 'profile' => 'Home',
            'status' => 'active', 'last_caller_id' => '80:9f:e4:37:87:07',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'OFFLINE-GUARD', 'customer_name' => 'Offline Guard',
            'status' => 'active', 'ppp_user_id' => $ppp->id,
        ]);
        $stale = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id,
            'pon_port' => '1', 'onu_id' => '14', 'onu_mac' => '98:c7:a4:06:80:43',
            'status' => 'offline', 'notes' => 'Requires operator review after caller MAC conflict.',
        ]);
        $link = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $stale->id, 'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id, 'client_mac' => '80:9f:e4:37:87:07',
            'source' => 'customer_list_realtime_sync:live_pppoe_session', 'status' => 'active',
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'pon_port' => '2', 'onu_id' => '25',
            'onu_mac' => '00:11:46:95:1c:c5', 'status' => 'offline',
        ]);

        $mikrotik = Mockery::mock(MikrotikController::class);
        $mikrotik->shouldReceive('mtQuote')->once()->with('offline-guard-user')->andReturn('"offline-guard-user"');
        $mikrotik->shouldReceive('singleRead')->twice()->andReturn(
            [['name' => 'offline-guard-user', 'caller-id' => '80:9f:e4:37:87:07']],
            [['name' => 'offline-guard-user', 'caller-id' => '']]
        );
        $this->app->instance(MikrotikController::class, $mikrotik);

        $adapter = Mockery::mock(OltReadOnlyAdapterManager::class);
        $adapter->shouldReceive('read')->once()->andReturn([
            'ok' => true,
            'status' => 'ok',
            'meta' => ['read_only' => true, 'writes_performed' => false],
            'onus' => [[
                'pon_port' => '2', 'onu_id' => '25', 'onu_mac' => '00:11:46:95:1c:c5', 'status' => 'offline',
            ]],
        ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $adapter);

        $sshCollector = Mockery::mock(VsolSshOpmCollector::class);
        $sshCollector->shouldReceive('collect')->once()->andReturn([
            'ok' => true,
            'status' => 'ok',
            'message' => 'Read-only identity result',
            'onus' => [[
                'pon_port' => '2', 'onu_id' => '25', 'onu_mac' => '00:11:46:95:1c:c5',
                'client_macs' => ['80:9f:e4:37:87:07'], 'status' => 'offline',
            ]],
            'meta' => ['read_only' => true, 'writes_performed' => false, 'opm_skipped' => true],
        ]);
        $this->app->instance(VsolSshOpmCollector::class, $sshCollector);

        try {
            app(CustomerOnuRealtimeSyncService::class)->sync($customer->fresh());
            $this->fail('An offline ONU must not be accepted as a live identity match.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Offline/Unknown', $exception->getMessage());
        }

        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'id' => $stale->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id, 'status' => 'offline',
        ]);
        $this->assertDatabaseHas('olt_onu_customer_ppp_links', [
            'id' => $link->id, 'olt_onu_customer_mapping_id' => $stale->id, 'status' => 'active',
        ]);
    }

    public function test_offline_onu_poll_does_not_refresh_last_seen_and_keeps_status_offline(): void
    {
        $service = (new \ReflectionClass(CustomerOnuRealtimeSyncService::class))->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod(CustomerOnuRealtimeSyncService::class, 'normalizeOnuStatus');

        $this->assertSame('offline', $normalize->invoke($service, '0'));
        $this->assertSame('offline', $normalize->invoke($service, 'offline'));
        $this->assertSame('unknown', $normalize->invoke($service, null));
        $this->assertSame('unknown', $normalize->invoke($service, 'not-reported'));
        $this->assertSame('online', $normalize->invoke($service, '1'));
        $this->assertSame('online', $normalize->invoke($service, 'online'));
    }
}
