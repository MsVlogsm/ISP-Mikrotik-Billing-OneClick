<?php

namespace Tests\Feature;

use App\Livewire\OpticalAudit;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkOpticalAudit;
use App\Models\OltOnuCustomerMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OpticalAuditTest extends TestCase
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

    public function test_optical_audit_saves_a_read_only_snapshot_of_real_mapping_fields(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt', 'name' => 'Audit OLT', 'ip_address' => '192.0.2.80',
            'status' => 'unknown', 'health_status' => 'unknown',
        ]);
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'OPTICAL-CUST-01', 'customer_name' => 'Optical Test Customer',
            'status' => 'active', 'disable_count' => 0,
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customer->id,
            'onu_id' => '1', 'pon_port' => '0/1', 'onu_serial' => 'VSOL123456',
            'onu_mac' => '00:11:22:33:44:55', 'status' => 'online',
            'rx_power' => -20.5, 'tx_power' => 2.1, 'last_seen_at' => now(),
        ]);

        Livewire::test(OpticalAudit::class)
            ->assertSee('Optical Test Customer')
            ->set('auditName', 'Morning POP snapshot')
            ->set('auditNotes', 'Read-only test snapshot')
            ->call('saveSnapshot')
            ->assertSee('snapshot saved');

        $audit = NetworkOpticalAudit::firstOrFail();
        $this->assertSame(1, $audit->reading_count);
        $this->assertSame('VSOL123456', $audit->readings[0]['onu_serial']);
        $this->assertSame(-20.5, (float) $audit->readings[0]['rx_power']);

        Livewire::test(OpticalAudit::class)
            ->call('viewAudit', $audit->id)
            ->assertSee('Morning POP snapshot')
            ->assertSee('VSOL123456');
    }
}
