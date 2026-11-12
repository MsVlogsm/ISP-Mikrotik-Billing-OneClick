<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltMonitoringSnapshot;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\OnuMacLedger;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Services\AiEngineerDiagnosticService;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncOltOnuMappingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RouterList::create([
            'router_name' => 'MatcherRouter', 'ip_address' => '127.0.0.1',
            'username' => 'matcher-user', 'password' => 'matcher-password',
            'action' => 'disconnected',
        ]);
    }

    private function olt(): NetworkInventoryDevice
    {
        return NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'VSOL matcher test', 'vendor' => 'VSOL',
            'model' => 'V1601E04-DP', 'status' => 'online', 'health_status' => 'online',
            'is_active' => true,
        ]);
    }

    private function customer(string $cid, string $username, string $status, string $mac): array
    {
        $ppp = PPPSecrets::create([
            'router_name' => 'MatcherRouter', 'username' => $username, 'password' => 'test-secret',
            'service' => 'pppoe', 'profile' => 'Home', 'status' => $status,
            'caller_id' => '', 'last_caller_id' => $mac,
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => $cid, 'customer_name' => 'Customer '.$cid,
            'status' => 'active', 'ppp_user_id' => $ppp->id,
        ]);

        return [$customer, $ppp];
    }

    private function fakeAdapter(
        NetworkInventoryDevice $olt,
        string $mac,
        array $clientMacs = [],
        array $extraOnuFields = [],
        string $resultStatus = 'ok',
        bool $includeReadOnlyMetadata = true,
    ): void {
        $manager = \Mockery::mock(OltReadOnlyAdapterManager::class);
        $manager->shouldReceive('read')->once()->with(\Mockery::on(
            fn ($device) => $device instanceof NetworkInventoryDevice && $device->id === $olt->id
        ))->andReturn([
            'ok' => true, 'status' => $resultStatus, 'message' => 'test response',
            'meta' => $includeReadOnlyMetadata
                ? ['read_only' => true, 'writes_performed' => false]
                : ['read_only' => false, 'writes_performed' => false],
            'onus' => [array_merge([
                'onu_id' => '25', 'pon_port' => '1', 'onu_mac' => $mac,
                'client_macs' => $clientMacs,
                'status' => 'online', 'snmp_index' => '1.25',
            ], $extraOnuFields)],
        ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $manager);
    }

    private function fakeAdapterRows(NetworkInventoryDevice $olt, array $onus, string $status = 'ok'): void
    {
        $manager = \Mockery::mock(OltReadOnlyAdapterManager::class);
        $manager->shouldReceive('read')->once()
            ->with(\Mockery::on(fn ($device) => $device instanceof NetworkInventoryDevice && $device->id === $olt->id))
            ->andReturn([
                'ok' => $status === 'ok', 'status' => $status, 'message' => 'test multi-row response',
                'meta' => ['read_only' => true, 'writes_performed' => false, 'onu_count' => count($onus)],
                'onus' => $onus,
            ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $manager);
    }

    public function test_dry_run_does_not_backfill_the_mac_ledger(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c6';
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'onu_serial' => 'DRY-RUN-SERIAL',
            'onu_mac' => $mac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('onu_mac_ledgers', 0);
    }

    public function test_exact_mac_match_prefers_one_active_ppp_customer_over_removed_legacy_record(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        [$removedCustomer, $removedPpp] = $this->customer('BT0027', 'm.shakil', 'removed', $mac);
        [$activeCustomer, $activePpp] = $this->customer('BT0006', 'bt.shakil', 'active', $mac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $mac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertSame($activeCustomer->id, $mapping->customer_id);
        $this->assertSame($activePpp->id, $mapping->ppp_user_id);
        $this->assertNotSame($removedCustomer->id, $mapping->customer_id);
        $this->assertNotSame($removedPpp->id, $mapping->ppp_user_id);
    }

    public function test_unique_active_ppp_client_mac_learned_behind_onu_maps_customer(): void
    {
        $olt = $this->olt();
        $onuMac = '00:11:46:95:1c:c5';
        $clientMac = 'a4:b1:c1:22:33:44';
        [$customer, $ppp] = $this->customer('BTFDB01', 'fdb.unique.customer', 'active', $clientMac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $onuMac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $onuMac, [$clientMac]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertSame($customer->id, $mapping->customer_id);
        $this->assertSame($ppp->id, $mapping->ppp_user_id);
    }

    public function test_multiple_active_ppp_client_macs_on_same_onu_remain_unmapped(): void
    {
        $olt = $this->olt();
        $onuMac = '00:11:46:95:1c:c5';
        $clientMac = 'a4:b1:c1:22:33:55';
        $this->customer('BTFDB02', 'fdb.first.customer', 'active', $clientMac);
        $this->customer('BTFDB03', 'fdb.second.customer', 'active', $clientMac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $onuMac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $onuMac, [$clientMac]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertNull($mapping->customer_id);
        $this->assertNull($mapping->ppp_user_id);
    }

    public function test_multiple_active_ppp_accounts_with_same_onu_mac_remain_unmapped(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        $this->customer('BT0006', 'bt.shakil', 'active', $mac);
        $this->customer('BT0044', 'other.active.shakil', 'active', $mac);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $mac, 'status' => 'online',
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertNull($mapping->customer_id);
        $this->assertNull($mapping->ppp_user_id);
    }

    public function test_opm_timestamp_advances_only_when_fresh_optical_values_are_returned(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        $measuredAt = now()->subHours(2);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => $mac,
            'status' => 'online',
            'rx_power' => -26.4,
            'tx_power' => 1.7,
            'opm_source' => 'vsol-snmp-opm',
            'opm_last_seen_at' => $measuredAt,
        ]);
        $this->fakeAdapter($olt, $mac, [], [
            'rx_power' => -18.25,
            'tx_power' => 2.15,
            'opm_source' => 'vsol-snmp-opm',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertEquals(-18.25, (float) $mapping->rx_power);
        $this->assertEquals(2.15, (float) $mapping->tx_power);
        $this->assertTrue($mapping->opm_last_seen_at->greaterThan($measuredAt));
    }

    public function test_ai_optical_power_list_exposes_the_opm_measurement_timestamp(): void
    {
        $olt = $this->olt();
        $measuredAt = now()->subMinutes(20);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => '00:11:46:95:1c:c5',
            'status' => 'online',
            'rx_power' => -21.2,
            'tx_power' => 1.95,
            'opm_source' => 'test-opm',
            'opm_last_seen_at' => $measuredAt,
        ]);

        $row = app(AiEngineerDiagnosticService::class)->opticalPowerList(10)[0];

        $this->assertSame('test-opm', $row['opm_source']);
        $this->assertSame($measuredAt->toIso8601String(), $row['opm_measured_at']);
        $this->assertSame(-21.2, (float) $row['rx']);
        $this->assertSame(1.95, (float) $row['tx']);
    }

    public function test_status_only_poll_does_not_refresh_old_opm_timestamp_or_values(): void
    {
        $olt = $this->olt();
        $mac = '00:11:46:95:1c:c5';
        $measuredAt = now()->subHours(12);

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '25',
            'pon_port' => '1',
            'onu_mac' => $mac,
            'status' => 'online',
            'rx_power' => -26.4,
            'tx_power' => 1.7,
            'opm_source' => 'vsol-snmp-opm',
            'opm_last_seen_at' => $measuredAt,
        ]);
        $this->fakeAdapter($olt, $mac);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $mapping = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->where('onu_id', '25')->firstOrFail();
        $this->assertEquals(-26.4, (float) $mapping->rx_power);
        $this->assertEquals(1.7, (float) $mapping->tx_power);
        $this->assertEquals($measuredAt->toDateTimeString(), $mapping->opm_last_seen_at->toDateTimeString());
    }

    public function test_complete_read_only_poll_warns_about_absent_online_onu_without_changing_mapping(): void
    {
        $olt = $this->olt();
        $lastSeen = now()->subHours(8);
        $stale = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '62',
            'pon_port' => '1',
            'onu_mac' => '00:d5:9e:9d:34:74',
            'status' => 'online',
            'last_seen_at' => $lastSeen,
            'notes' => 'Keep historical note.',
        ]);

        $this->fakeAdapter($olt, '00:11:46:95:1c:c5');
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])
            ->expectsOutputToContain('ONU 62 / 00:d5:9e:9d:34:74 is stored as online but absent from this complete read-only poll')
            ->assertExitCode(0);

        $stale->refresh();
        $this->assertSame('online', $stale->status);
        $this->assertSame($lastSeen->toDateTimeString(), $stale->last_seen_at->toDateTimeString());
        $this->assertSame('Keep historical note.', $stale->notes);
    }

    public function test_complete_verified_read_only_poll_records_olt_dashboard_snapshot(): void
    {
        $olt = $this->olt();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -28.2,
            'tx_power' => 1.65,
            'opm_source' => 'test-opm',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 1);
        $snapshot = OltMonitoringSnapshot::where('olt_device_id', $olt->id)->firstOrFail();

        $this->assertSame(1, (int) $snapshot->onu_total);
        $this->assertSame(1, (int) $snapshot->onu_online);
        $this->assertSame(0, (int) $snapshot->onu_offline);
        $this->assertSame(1, (int) $snapshot->rx_sample_count);
        $this->assertSame(1, (int) $snapshot->signal_critical);
        $this->assertSame(0, (int) $snapshot->signal_good);
        $this->assertEquals(-28.2, (float) $snapshot->avg_rx_power);
    }

    public function test_repeated_polls_update_a_snapshot_window_without_sliding_its_timestamp(): void
    {
        $olt = $this->olt();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -18.5,
            'tx_power' => 1.7,
            'opm_source' => 'test-opm',
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);
        $first = OltMonitoringSnapshot::where('olt_device_id', $olt->id)->firstOrFail();
        $firstSampledAt = $first->sampled_at->toDateTimeString();

        // A second poll inside the configured 5-minute bucket must refresh metrics
        // but retain the first sample timestamp rather than sliding the bucket.
        $this->travel(2)->minutes();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -19.0,
            'tx_power' => 1.8,
            'opm_source' => 'test-opm',
        ]);
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 1);
        $this->assertSame($firstSampledAt, $first->fresh()->sampled_at->toDateTimeString());
        $this->assertEquals(-19.0, (float) $first->fresh()->avg_rx_power);

        // Once the window has elapsed, the next verified poll creates a new point.
        $this->travel(4)->minutes();
        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [
            'rx_power' => -20.0,
            'tx_power' => 1.9,
            'opm_source' => 'test-opm',
        ]);
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 2);
    }

    public function test_partial_or_unverified_poll_does_not_claim_an_absent_onu_is_stale(): void
    {
        $olt = $this->olt();
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id,
            'onu_id' => '62',
            'pon_port' => '1',
            'onu_mac' => '00:d5:9e:9d:34:74',
            'status' => 'online',
            'last_seen_at' => now()->subHours(8),
        ]);

        $this->fakeAdapter($olt, '00:11:46:95:1c:c5', [], [], 'partial');
        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])
            ->doesntExpectOutputToContain('ONU 62 / 00:d5:9e:9d:34:74 is stored as online')
            ->assertExitCode(0);

        $this->assertDatabaseCount('olt_monitoring_snapshots', 0);
    }

    public function test_unknown_onu_status_never_auto_assigns_customer_from_client_mac(): void
    {
        $olt = $this->olt();
        $onuMac = '00:11:46:95:1c:c5';
        $clientMac = 'a4:b1:c1:22:33:61';
        [$customer, $ppp] = $this->customer('BTFDB-UNKNOWN', 'fdb.unknown.customer', 'active', $clientMac);
        $mapping = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => $onuMac, 'status' => 'unknown',
        ]);
        $this->fakeAdapter($olt, $onuMac, [$clientMac], ['status' => 'unknown']);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertNull($mapping->fresh()->customer_id);
        $this->assertNull($mapping->fresh()->ppp_user_id);
        $this->assertSame('unknown', $mapping->fresh()->status);
        $this->assertDatabaseCount('olt_onu_customer_ppp_links', 0);
    }

    public function test_same_customer_device_mac_seen_behind_two_onus_is_never_shared_or_auto_mapped(): void
    {
        $olt = $this->olt();
        $clientMac = 'a4:b1:c1:22:33:62';
        $this->customer('BTFDB-DUP-CLIENT', 'fdb.duplicate.client', 'active', $clientMac);
        $first = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '25', 'pon_port' => '1',
            'onu_mac' => '00:11:46:95:1c:c5', 'status' => 'online',
        ]);
        $second = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'onu_id' => '26', 'pon_port' => '1',
            'onu_mac' => '00:11:46:95:1c:c6', 'status' => 'online',
        ]);
        $this->fakeAdapterRows($olt, [
            ['pon_port' => '1', 'onu_id' => '25', 'onu_mac' => '00:11:46:95:1c:c5', 'client_macs' => [$clientMac], 'status' => 'online'],
            ['pon_port' => '1', 'onu_id' => '26', 'onu_mac' => '00:11:46:95:1c:c6', 'client_macs' => [$clientMac], 'status' => 'online'],
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $this->assertNull($first->fresh()->customer_id);
        $this->assertNull($first->fresh()->ppp_user_id);
        $this->assertNull($second->fresh()->customer_id);
        $this->assertNull($second->fresh()->ppp_user_id);
        $this->assertDatabaseCount('olt_onu_customer_ppp_links', 0);
    }

    public function test_duplicate_onu_mac_slots_refresh_status_telemetry_without_changing_identity_or_subscribers(): void
    {
        $olt = $this->olt();
        [$customer, $ppp] = $this->customer('DUP-ONU-OWNER', 'dup.onu.owner', 'active', 'a4:b1:c1:22:33:63');
        $duplicateMac = 'a2:4f:a2:72:fe:c0';
        $oldSeen = now()->subHours(3);
        $offline = OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id, 'ppp_user_id' => $ppp->id,
            'pon_port' => '2', 'onu_id' => '8', 'onu_mac' => $duplicateMac,
            'status' => 'online', 'last_seen_at' => $oldSeen,
            'rx_power' => -19.5, 'tx_power' => 2.1, 'opm_source' => 'old-opm', 'opm_last_seen_at' => $oldSeen,
        ]);
        $link = OltOnuCustomerPppLink::create([
            'olt_onu_customer_mapping_id' => $offline->id, 'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id, 'client_mac' => 'a4:b1:c1:22:33:63', 'status' => 'active',
            'source' => 'legacy_primary',
        ]);
        $ledger = OnuMacLedger::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id,
            'customer_unique_id' => $customer->customer_unique_id,
            'pon_port' => '2', 'onu_id' => '8', 'current_mac' => $duplicateMac,
            'pppoe_username' => $ppp->username, 'status' => 'online',
            'rx_power' => -19.5, 'tx_power' => 2.1, 'last_seen_at' => $oldSeen,
        ]);
        $this->fakeAdapterRows($olt, [
            ['pon_port' => '2', 'onu_id' => '8', 'onu_mac' => $duplicateMac, 'client_macs' => [], 'status' => 'offline', 'rx_power' => -19.5, 'tx_power' => 2.1],
            ['pon_port' => '1', 'onu_id' => '26', 'onu_mac' => $duplicateMac, 'client_macs' => [], 'status' => 'online', 'rx_power' => -18.0, 'tx_power' => 2.0],
        ]);

        $this->artisan('olt:sync-onu-mappings', ['--device' => $olt->id])->assertExitCode(0);

        $offline->refresh();
        $this->assertSame($customer->id, $offline->customer_id);
        $this->assertSame($ppp->id, $offline->ppp_user_id);
        $this->assertSame($duplicateMac, $offline->onu_mac);
        $this->assertSame('offline', $offline->status);
        $this->assertSame($oldSeen->toDateTimeString(), $offline->last_seen_at->toDateTimeString());
        $this->assertTrue($offline->opm_last_seen_at->greaterThan($oldSeen));
        $this->assertEquals(-19.5, (float) $offline->rx_power);
        $this->assertEquals(2.1, (float) $offline->tx_power);
        $this->assertDatabaseHas('olt_onu_customer_ppp_links', [
            'id' => $link->id, 'olt_onu_customer_mapping_id' => $offline->id, 'status' => 'active',
        ]);
        $ledger->refresh();
        $this->assertSame($customer->id, $ledger->customer_id);
        $this->assertSame('offline', $ledger->status);
        $this->assertSame($offline->last_seen_at->timestamp, $ledger->last_seen_at->timestamp);
        $this->assertTrue($ledger->updated_at->greaterThan($oldSeen));
    }
}
