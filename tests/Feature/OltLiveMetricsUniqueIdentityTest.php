<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Services\Olt\OltLiveMetricsService;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OltLiveMetricsUniqueIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_customer_count_excludes_duplicate_mac_and_wrong_pon_onu_slots(): void
    {
        $olt = NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => 'PON-scoped metrics test',
            'vendor' => 'VSOL',
            'model' => 'V1600D4',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);

        $customers = [];
        foreach (['LIVE-UNIQUE', 'DUPLICATE-ONE', 'DUPLICATE-TWO', 'WRONG-SLOT'] as $key) {
            $customers[$key] = CustomersInfo::create([
                'customer_unique_id' => $key,
                'customer_name' => $key,
                'status' => 'active',
            ]);
        }

        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customers['LIVE-UNIQUE']->id,
            'onu_id' => '1', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:51', 'status' => 'online',
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customers['DUPLICATE-ONE']->id,
            'onu_id' => '2', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:52', 'status' => 'online',
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customers['DUPLICATE-TWO']->id,
            'onu_id' => '3', 'pon_port' => '2', 'onu_mac' => '00:11:22:33:44:52', 'status' => 'online',
        ]);
        OltOnuCustomerMapping::create([
            'olt_device_id' => $olt->id, 'customer_id' => $customers['WRONG-SLOT']->id,
            'onu_id' => '9', 'pon_port' => '2', 'onu_mac' => '00:11:22:33:44:53', 'status' => 'online',
        ]);

        $adapter = Mockery::mock(OltReadOnlyAdapterManager::class);
        $adapter->shouldReceive('read')->once()->andReturn([
            'ok' => true,
            'status' => 'ok',
            'message' => 'verified read-only test fixture',
            'device' => ['sysDescr' => 'V1600D4', 'sysUpTime' => '100'],
            'meta' => ['read_only' => true, 'writes_performed' => false],
            'onus' => [
                ['onu_id' => '1', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:51', 'status' => 'online'],
                ['onu_id' => '2', 'pon_port' => '1', 'onu_mac' => '00:11:22:33:44:52', 'status' => 'online'],
                ['onu_id' => '3', 'pon_port' => '2', 'onu_mac' => '00:11:22:33:44:52', 'status' => 'online'],
                ['onu_id' => '10', 'pon_port' => '2', 'onu_mac' => '00:11:22:33:44:53', 'status' => 'online'],
            ],
            'interfaces' => [],
        ]);
        $this->app->instance(OltReadOnlyAdapterManager::class, $adapter);

        $snapshot = app(OltLiveMetricsService::class)->poll($olt);

        $this->assertSame(1, (int) $snapshot->mapped_customers);
        $this->assertSame(1, (int) $olt->fresh()->customer_count);
    }
}
