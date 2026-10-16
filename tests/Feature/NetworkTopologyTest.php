<?php

namespace Tests\Feature;

use App\Livewire\NetworkTopology;
use App\Models\NetworkInventoryDevice;
use App\Models\NetworkTopologyLink;
use App\Models\NetworkTopologyNode;
use App\Models\NetworkTopologyMap;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NetworkTopologyTest extends TestCase
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

    public function test_designer_saves_and_publishes_a_connection_between_inventory_nodes(): void
    {
        $router = RouterList::create([
            'router_name' => 'Core Router', 'ip_address' => '192.0.2.1',
            'username' => 'test', 'password' => 'test', 'action' => 'connected',
        ]);
        $switch = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'POP Switch', 'ip_address' => '192.0.2.2',
            'status' => 'unknown', 'health_status' => 'unknown',
        ]);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('source_key', 'router:'.$router->id)
            ->set('target_key', 'device:'.$switch->id)
            ->set('connection_type', 'uplink')
            ->set('label', 'Core uplink')
            ->call('saveLink')
            ->assertSee('Core uplink');

        $link = NetworkTopologyLink::firstOrFail();
        $this->assertFalse($link->is_published);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])->call('publish', $link->id);
        $this->assertDatabaseHas('network_topology_links', ['id' => $link->id, 'is_published' => 1, 'status' => 'active']);
    }

    public function test_published_live_topology_nodes_can_be_used_in_connections(): void
    {
        $router = RouterList::create([
            'router_name' => 'Access Router', 'ip_address' => '192.0.2.3',
            'username' => 'test', 'password' => 'test', 'action' => 'connected',
        ]);
        $mapId = (int) NetworkTopologyMap::where('is_default', true)->value('id');
        $node = NetworkTopologyNode::create([
            'map_id' => $mapId, 'type' => 'splitter', 'name' => 'SPL-POP-01', 'is_published' => false,
        ]);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('publishNode', $node->id);
        $node->refresh();
        $this->assertTrue($node->is_published);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('source_key', 'router:'.$router->id)
            ->set('target_key', 'topology:'.$node->id)
            ->set('connection_type', 'fiber_core')
            ->call('saveLink');

        $link = NetworkTopologyLink::firstOrFail();
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])->call('publish', $link->id);
        $this->assertTrue($link->fresh()->is_published);
    }

    public function test_topology_and_optical_audit_routes_render_for_network_admin(): void
    {
        $this->get(route('network-topology.designer'))->assertOk()->assertSee('Topology Designer');
        $this->get(route('network-topology.live'))->assertOk()->assertSee('Live Topology');
        $this->get(route('optical-audit'))->assertOk()->assertSee('Virtual OTDR / Optical Audit');
    }

}
