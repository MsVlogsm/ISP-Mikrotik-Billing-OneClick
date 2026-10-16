<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OnuCustomerMappingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    private function olt(string $name = 'Mapping Workflow OLT'): NetworkInventoryDevice
    {
        return NetworkInventoryDevice::create([
            'type' => 'olt',
            'name' => $name,
            'vendor' => 'BDCOM',
            'model' => 'P3608',
            'status' => 'online',
            'health_status' => 'online',
            'is_active' => true,
        ]);
    }

    private function ppp(string $routerName, string $username): PPPSecrets
    {
        RouterList::create([
            'router_name' => $routerName,
            'ip_address' => '127.0.0.1',
            'username' => 'onu-test-user',
            'password' => 'onu-test-password',
            'action' => 'disconnected',
        ]);

        return PPPSecrets::create([
            'router_name' => $routerName,
            'username' => $username,
            'password' => 'test-secret',
            'service' => 'pppoe',
            'profile' => 'Home-20M',
            'status' => 'active',
        ]);
    }

    private function customer(string $id, ?int $pppUserId = null): CustomersInfo
    {
        return CustomersInfo::create([
            'customer_unique_id' => $id,
            'customer_name' => 'Customer '.$id,
            'status' => 'active',
            'ppp_user_id' => $pppUserId,
        ]);
    }

    private function saveMapping(NetworkInventoryDevice $olt, CustomersInfo $customer, string $onuId, string $mac, array $extra = [])
    {
        $url = route('network-inventory.olt.customers.mapping', $olt);
        $back = route('network-inventory.olt.customers', $olt);

        return $this->from($back)->post($url, array_merge([
            'onu_id' => $onuId,
            'onu_mac' => $mac,
            'onu_serial' => 'SERIAL-'.$onuId,
            'pon_port' => '1',
            'customer_id' => $customer->id,
        ], $extra));
    }

    public function test_manual_mapping_saves_customer_pppoe_and_stable_onu_identity(): void
    {
        $olt = $this->olt();
        $ppp = $this->ppp('mapping-router', 'mapping-user');
        $customer = $this->customer('MAP-CID-1', $ppp->id);

        $this->saveMapping($olt, $customer, 'ONU-1', 'AA:BB:CC:DD:EE:01')
            ->assertRedirect(route('network-inventory.olt.customers', $olt))
            ->assertSessionHas('onu_sync_success', true)
            ->assertSessionHas('inventory_message', 'ONU/customer mapping saved successfully.');

        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-1',
            'onu_mac' => 'AA:BB:CC:DD:EE:01',
            'onu_serial' => 'SERIAL-ONU-1',
            'pon_port' => '1',
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
        ]);

        $this->get(route('network-inventory.olt.customers', $olt))
            ->assertOk()
            ->assertSee('MAP-CID-1')
            ->assertSee('mapping-user')
            ->assertSee('ONU-1');
    }

    public function test_existing_onu_mapping_requires_explicit_confirmation_before_customer_replacement(): void
    {
        $olt = $this->olt('Overwrite Confirmation OLT');
        $first = $this->customer('MAP-OVERWRITE-A');
        $second = $this->customer('MAP-OVERWRITE-B');

        $this->saveMapping($olt, $first, 'ONU-22', 'AA:BB:CC:DD:22:22')
            ->assertSessionHas('onu_sync_success', true);

        $this->saveMapping($olt, $second, 'ONU-22', 'AA:BB:CC:DD:22:22')
            ->assertRedirect()
            ->assertSessionHas('onu_sync_success', false)
            ->assertSessionHas('inventory_message', 'Existing mapping found. Confirm replacement before saving.');

        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-22',
            'customer_id' => $first->id,
        ]);

        $this->saveMapping($olt, $second, 'ONU-22', 'AA:BB:CC:DD:22:22', ['confirm_overwrite' => 1])
            ->assertSessionHas('onu_sync_success', true);

        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-22',
            'customer_id' => $second->id,
        ]);
        $this->assertSame(1, OltOnuCustomerMapping::where('olt_device_id', $olt->id)->count());
    }

    public function test_pppoe_user_cannot_be_silently_reused_for_another_onu(): void
    {
        $olt = $this->olt('PPPoE Reuse Guard OLT');
        $ppp = $this->ppp('reuse-router', 'shared-pppoe-user');
        $first = $this->customer('MAP-PPP-A', $ppp->id);
        $second = $this->customer('MAP-PPP-B', $ppp->id);

        $this->saveMapping($olt, $first, 'ONU-31', 'AA:BB:CC:DD:31:31')
            ->assertSessionHas('onu_sync_success', true);

        $this->saveMapping($olt, $second, 'ONU-32', 'AA:BB:CC:DD:32:32')
            ->assertRedirect()
            ->assertSessionHas('onu_sync_success', false)
            ->assertSessionHas('inventory_message', 'This PPPoE is already mapped to another ONU. Confirm reuse before saving.');

        $this->assertSame(1, OltOnuCustomerMapping::where('olt_device_id', $olt->id)->count());

        $this->saveMapping($olt, $second, 'ONU-32', 'AA:BB:CC:DD:32:32', ['confirm_reuse' => 1])
            ->assertSessionHas('onu_sync_success', true);

        $this->assertSame(2, OltOnuCustomerMapping::where('olt_device_id', $olt->id)->count());
    }

    public function test_mapping_refuses_to_replace_an_onu_id_already_owned_by_another_mapping(): void
    {
        $olt = $this->olt('Duplicate ONU ID Guard OLT');
        $first = $this->customer('MAP-ID-GUARD-A');
        $second = $this->customer('MAP-ID-GUARD-B');
        $third = $this->customer('MAP-ID-GUARD-C');

        $this->saveMapping($olt, $first, 'ONU-51', 'AA:BB:CC:DD:51:51')
            ->assertSessionHas('onu_sync_success', true);
        $this->saveMapping($olt, $second, 'ONU-52', 'AA:BB:CC:DD:52:52')
            ->assertSessionHas('onu_sync_success', true);

        $this->saveMapping($olt, $third, 'ONU-52', 'aa-bb-cc-dd-51-51', ['confirm_overwrite' => 1])
            ->assertSessionHas('onu_sync_success', false)
            ->assertSessionHas('inventory_message', 'The requested ONU ID already belongs to another ONU on this OLT. Refresh the ONU list and select the correct device.');

        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-51',
            'customer_id' => $first->id,
        ]);
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-52',
            'customer_id' => $second->id,
        ]);
        $this->assertDatabaseMissing('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'customer_id' => $third->id,
        ]);
    }

    public function test_mapping_identity_can_be_resolved_by_normalized_mac_not_only_onu_id(): void
    {
        $olt = $this->olt('Stable MAC OLT');
        $first = $this->customer('MAP-STABLE-A');
        $second = $this->customer('MAP-STABLE-B');

        $this->saveMapping($olt, $first, 'ONU-41', 'AA:BB:CC:DD:41:41')
            ->assertSessionHas('onu_sync_success', true);

        $this->saveMapping($olt, $second, 'ONU-41-RENAMED', 'aa-bb-cc-dd-41-41', ['confirm_overwrite' => 1])
            ->assertSessionHas('onu_sync_success', true);

        $this->assertSame(1, OltOnuCustomerMapping::where('olt_device_id', $olt->id)->count());
        $this->assertDatabaseHas('olt_onu_customer_mappings', [
            'olt_device_id' => $olt->id,
            'onu_id' => 'ONU-41-RENAMED',
            'customer_id' => $second->id,
        ]);
    }
}
