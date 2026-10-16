<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\DeviceManager;
use App\Models\NetworkEvent;
use App\Models\NetworkInventoryDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceManagerWorkspaceTest extends TestCase
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

    public function test_device_manager_shows_saved_inventory_and_open_critical_alert_count(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'Core Switch Test', 'ip_address' => '192.0.2.5',
            'health_port' => 80, 'status' => 'offline', 'health_status' => 'failed',
            'monitor_enabled' => true,
        ]);
        NetworkEvent::create([
            'device_id' => $device->id, 'source_type' => 'test', 'event_type' => 'device_down',
            'severity' => 'critical', 'title' => 'Core Switch Test down', 'message' => 'test',
            'status' => 'open', 'fingerprint' => 'device-down:'.$device->id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        Livewire::test(DeviceManager::class)
            ->assertSee('Core Switch Test')
            ->assertSee('Critical Alerts')
            ->assertSee('192.0.2.5')
            ->assertSee('1');
    }

    public function test_device_manager_can_add_a_switch_without_probing_it(): void
    {
        Livewire::test(DeviceManager::class)
            ->set('showAddDevice', true)
            ->set('newDeviceType', 'switch')
            ->set('newDeviceName', 'New Test Switch')
            ->set('newDeviceIp', '192.0.2.20')
            ->set('newDeviceVendor', 'Test Vendor')
            ->set('newDeviceModel', 'SW-24')
            ->set('newDevicePort', '443')
            ->set('newDeviceLocation', 'Test POP')
            ->set('newDeviceMonitorEnabled', true)
            ->call('createDevice')
            ->assertSet('showAddDevice', false)
            ->assertSee('New Test Switch');

        $this->assertDatabaseHas('network_inventory_devices', [
            'name' => 'New Test Switch', 'type' => 'switch',
            'ip_address' => '192.0.2.20', 'health_port' => 443,
            'monitor_enabled' => 1, 'status' => 'unknown',
        ]);
    }

    public function test_device_detail_only_shows_alerts_for_the_opened_device(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'Detail Switch', 'ip_address' => '192.0.2.30',
            'health_port' => 80, 'status' => 'unknown', 'health_status' => 'unknown',
        ]);
        $other = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Other OLT', 'ip_address' => '192.0.2.31',
            'health_port' => 23, 'status' => 'unknown', 'health_status' => 'unknown',
        ]);
        foreach ([$device, $other] as $target) {
            NetworkEvent::create([
                'device_id' => $target->id, 'source_type' => 'test', 'event_type' => 'device_down',
                'severity' => 'critical', 'title' => 'Alert '.$target->name, 'message' => 'test',
                'status' => 'open', 'fingerprint' => 'device-down:'.$target->id,
                'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        Livewire::test(DeviceDetail::class, ['kind' => 'device', 'device' => $device->id])
            ->assertSee('Alert Detail Switch')
            ->assertDontSee('Alert Other OLT');
    }

    public function test_single_device_probe_records_health_and_opens_an_alert_on_failure(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'Probe Failure Switch', 'ip_address' => '127.0.0.1',
            'health_port' => 1, 'port' => 1, 'status' => 'unknown', 'health_status' => 'unknown',
        ]);

        Livewire::test(DeviceManager::class)->call('probeDevice', $device->id);

        $this->assertDatabaseHas('network_inventory_health_checks', [
            'network_inventory_device_id' => $device->id,
            'status' => 'down',
        ]);
        $this->assertDatabaseHas('network_events', [
            'device_id' => $device->id,
            'fingerprint' => 'device-down:'.$device->id,
            'severity' => 'critical',
            'status' => 'open',
        ]);
    }

    public function test_alert_acknowledge_and_resolve_record_the_actor_and_timestamp(): void
    {
        $device = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'Alert Workflow Switch', 'ip_address' => '192.0.2.40',
            'health_port' => 80, 'status' => 'offline', 'health_status' => 'failed',
        ]);
        $event = NetworkEvent::create([
            'device_id' => $device->id, 'source_type' => 'test', 'event_type' => 'device_down',
            'severity' => 'critical', 'title' => 'Workflow alert', 'message' => 'test',
            'status' => 'open', 'fingerprint' => 'device-down:'.$device->id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        Livewire::test(\App\Livewire\NetworkEvents::class)
            ->call('acknowledge', $event->id);

        $this->assertDatabaseHas('network_events', [
            'id' => $event->id, 'status' => 'acknowledged', 'acknowledged_by' => auth()->id(),
        ]);

        Livewire::test(\App\Livewire\NetworkEvents::class)
            ->set('status', 'acknowledged')
            ->call('resolve', $event->id);

        $this->assertDatabaseHas('network_events', [
            'id' => $event->id, 'status' => 'resolved', 'resolved_by' => auth()->id(),
        ]);
        $this->assertNotNull($event->fresh()->resolved_at);
    }

}
