<?php

namespace Tests\Feature;

use App\Livewire\NetworkTopology;
use App\Livewire\FiberMapping;
use App\Http\Controllers\PublicNetworkMapController;
use App\Models\NetworkMapFeature;
use Illuminate\Support\Facades\DB;
use App\Livewire\NetworkMap;
use App\Models\NetworkInventoryDevice;
use App\Models\MainSiteData;
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

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])->call('verifyLink', $link->id)->call('publish', $link->id);
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
            ->call('verifyNode', $node->id)->call('publishNode', $node->id);
        $node->refresh();
        $this->assertTrue($node->is_published);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('source_key', 'router:'.$router->id)
            ->set('target_key', 'topology:'.$node->id)
            ->set('target_port', 'IN-1')
            ->set('connection_type', 'fiber_core')
            ->set('source_core', '1')
            ->set('target_core', '1')
            ->call('saveLink')
            ->assertHasNoErrors();

        $link = NetworkTopologyLink::firstOrFail();
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])->call('verifyLink', $link->id)->call('publish', $link->id);
        $this->assertTrue($link->fresh()->is_published);
    }


    public function test_outside_plant_assets_accept_validated_coordinates_and_appear_on_network_map(): void
    {
        $mapId = (int) NetworkTopologyMap::where('is_default', true)->value('id');

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('newNodeType', 'cabinet')
            ->set('newNodeName', 'CAB-MIRPUR-TEST')
            ->set('newNodeLocation', 'Mirpur')
            ->set('newNodeLatitude', '23.810300')
            ->set('newNodeLongitude', '90.412500')
            ->call('saveNode')
            ->assertHasNoErrors();

        $node = NetworkTopologyNode::where('name', 'CAB-MIRPUR-TEST')->firstOrFail();
        $this->assertSame('cabinet', $node->type);
        $this->assertEqualsWithDelta(23.8103, (float) $node->latitude, 0.000001);
        $this->assertEqualsWithDelta(90.4125, (float) $node->longitude, 0.000001);
        $this->assertSame($mapId, (int) $node->map_id);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('verifyNode', $node->id)->call('publishNode', $node->id);
        $this->assertTrue($node->fresh()->is_published);

        $mapComponent = app(NetworkMap::class);
        $mapComponent->mount();
        $mapView = $mapComponent->render();
        $mappedNodes = collect($mapView->getData()['nodes']);
        $mappedAsset = $mappedNodes->firstWhere('map_key', 'topology:'.$node->id);
        $this->assertNotNull($mappedAsset);
        $this->assertSame('cabinet', $mappedAsset['kind']);
        $this->assertSame('unknown', $mappedAsset['status']);
        $this->assertEqualsWithDelta(23.8103, $mappedAsset['lat'], 0.000001);
        $this->assertEqualsWithDelta(90.4125, $mappedAsset['lng'], 0.000001);
    }


    public function test_pending_topology_assets_cannot_be_published_until_verified(): void
    {
        $mapId = (int) NetworkTopologyMap::where('is_default', true)->value('id');
        $node = NetworkTopologyNode::create([
            'map_id' => $mapId, 'type' => 'splitter', 'name' => 'SPL-PENDING-TEST',
        ]);
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('publishNode', $node->id)
            ->assertSee('Verify the real asset details before publishing this node.');
        $this->assertFalse($node->fresh()->is_published);
        $this->assertSame('pending', $node->fresh()->verification_status);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('verifyNode', $node->id)
            ->call('publishNode', $node->id);
        $this->assertTrue($node->fresh()->is_published);
        $this->assertSame('verified', $node->fresh()->verification_status);
        $this->assertNotNull($node->fresh()->verified_at);
    }

    public function test_topology_and_optical_audit_routes_render_for_network_admin(): void
    {
        $this->get(route('network-topology.designer'))->assertOk()->assertSee('Topology Designer');
        $this->get(route('network-topology.live'))->assertOk()->assertSee('Live Topology');
        $this->get(route('optical-audit'))->assertOk()->assertSee('Virtual OTDR / Optical Audit');
    }

    public function test_network_map_uses_saved_admin_default_map_settings(): void
    {
        MainSiteData::setValue('ipilot_gis_default_latitude', '24.9000');
        MainSiteData::setValue('ipilot_gis_default_longitude', '91.8000');
        MainSiteData::setValue('ipilot_gis_default_zoom', 14);
        MainSiteData::setValue('ipilot_gis_default_basemap', 'satellite');

        $component = app(NetworkMap::class);
        $component->mount();
        $view = $component->render();
        $mapConfig = $view->getData()['mapConfig'];

        $this->assertSame([91.8, 24.9], $mapConfig['center']);
        $this->assertSame(14, $mapConfig['zoom']);
        $this->assertSame('satellite', $mapConfig['basemap']);
    }

    public function test_geoman_drawn_fiber_routes_are_saved_edited_verified_and_deleted_with_snapshots(): void
    {
        $component = Livewire::test(NetworkMap::class)
            ->call('saveDrawnFeature', [
                'feature_type' => 'fiber_route',
                'name' => 'Test Fiber Route',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[90.4125, 23.8103], [90.4225, 23.8203], [90.4325, 23.8303]],
                ],
            ])
            ->assertHasNoErrors();

        $feature = NetworkMapFeature::where('name', 'Test Fiber Route')->firstOrFail();
        $this->assertSame('pending', $feature->status);
        $this->assertSame('LineString', $feature->geometry['type']);
        $this->assertDatabaseHas('network_topology_map_snapshots', ['map_id' => $feature->map_id]);

        $component->call('verifyDrawnFeature', $feature->id);
        $this->assertSame('published', $feature->fresh()->status);

        $component->call('updateDrawnFeature', $feature->id, [
            'type' => 'LineString',
            'coordinates' => [[90.4125, 23.8103], [90.4425, 23.8403]],
        ]);
        $this->assertSame('pending', $feature->fresh()->status);
        $this->assertCount(2, $feature->fresh()->geometry['coordinates']);

        $component->call('deleteDrawnFeature', $feature->id);
        $this->assertDatabaseMissing('network_map_features', ['id' => $feature->id]);
        $this->assertGreaterThanOrEqual(3, DB::table('network_topology_map_snapshots')->where('map_id', $feature->map_id)->count());
    }

    public function test_drawn_map_feature_rejects_invalid_coordinates(): void
    {
        Livewire::test(NetworkMap::class)->call('saveDrawnFeature', [
            'feature_type' => 'fiber_route',
            'name' => 'Invalid Route',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[220, 95], [90.4, 23.8]]],
        ])->assertStatus(422);
    }

    public function test_topology_node_can_be_edited_and_requires_reverification(): void
    {
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('newNodeType', 'splitter')
            ->set('newNodeName', 'SPL-EDIT-OLD')
            ->set('splitter_ratio', '8')
            ->set('newNodeLatitude', '23.8103')
            ->set('newNodeLongitude', '90.4125')
            ->call('saveNode')
            ->assertHasNoErrors();

        $node = NetworkTopologyNode::where('name', 'SPL-EDIT-OLD')->firstOrFail();
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('editNode', $node->id)
            ->set('newNodeName', 'SPL-EDIT-NEW')
            ->set('splitter_ratio', '16')
            ->call('saveNode')
            ->assertHasNoErrors();

        $node->refresh();
        $this->assertSame('SPL-EDIT-NEW', $node->name);
        $this->assertSame(16, (int) $node->splitter_ratio);
        $this->assertSame('pending', $node->verification_status);
        $this->assertFalse($node->is_published);
    }

    public function test_topology_link_can_be_edited_and_requires_reverification(): void
    {
        $router = RouterList::create([
            'router_name' => 'Edit Router', 'ip_address' => '192.0.2.10',
            'username' => 'test', 'password' => 'test', 'action' => 'connected',
        ]);
        $switch = NetworkInventoryDevice::create([
            'type' => 'switch', 'name' => 'Edit Switch', 'ip_address' => '192.0.2.11',
            'status' => 'unknown', 'health_status' => 'unknown',
        ]);

        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->set('source_key', 'router:'.$router->id)
            ->set('target_key', 'device:'.$switch->id)
            ->set('connection_type', 'uplink')
            ->set('label', 'Before edit')
            ->call('saveLink')
            ->assertHasNoErrors();

        $link = NetworkTopologyLink::firstOrFail();
        Livewire::test(NetworkTopology::class, ['mode' => 'designer'])
            ->call('editLink', $link->id)
            ->set('label', 'After edit')
            ->set('capacity_mbps', '1000')
            ->call('saveLink')
            ->assertHasNoErrors();

        $link->refresh();
        $this->assertSame('After edit', $link->label);
        $this->assertSame(1000, (int) $link->capacity_mbps);
        $this->assertSame('pending', $link->verification_status);
        $this->assertFalse($link->is_published);
    }

    public function test_verified_fiber_registry_assets_and_surveyed_cable_routes_appear_on_network_map(): void
    {
        $splitterId = DB::table('fiber_splitters')->insertGetId([
            'splitter_id' => 'SPL-FIBER-MAP-TEST',
            'location' => 'Verified site',
            'latitude' => 23.8103,
            'longitude' => 90.4125,
            'ratio' => '1:8',
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fiber_cables')->insert([
            'cable_id' => 'CBL-FIBER-MAP-VERIFIED',
            'route_name' => 'Surveyed verified route',
            'start_latitude' => 23.8103,
            'start_longitude' => 90.4125,
            'end_latitude' => 23.8203,
            'end_longitude' => 90.4225,
            'core_count' => 12,
            'color_standard' => 'TIA-598',
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nextSplitterId = DB::table('fiber_splitters')->insertGetId([
            'splitter_id' => 'SPL-FIBER-MAP-NEXT',
            'location' => 'Verified downstream site',
            'latitude' => 23.8203,
            'longitude' => 90.4225,
            'ratio' => '1:8',
            'input_cable_id' => DB::table('fiber_cables')->where('cable_id', 'CBL-FIBER-MAP-VERIFIED')->value('id'),
            'input_core' => 4,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fiber_splitter_ports')->insert([
            'splitter_id' => $splitterId,
            'output_port' => 2,
            'next_splitter_id' => $nextSplitterId,
            'output_cable_id' => DB::table('fiber_cables')->where('cable_id', 'CBL-FIBER-MAP-VERIFIED')->value('id'),
            'output_core' => 4,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fiber_cables')->insert([
            'cable_id' => 'CBL-FIBER-MAP-PENDING',
            'route_name' => 'Pending route hidden',
            'start_latitude' => 23.8000,
            'start_longitude' => 90.4000,
            'end_latitude' => 23.8300,
            'end_longitude' => 90.4300,
            'core_count' => 12,
            'color_standard' => 'TIA-598',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = app(NetworkMap::class);
        $component->mount();
        $data = $component->render()->getData();
        $nodes = collect($data['nodes']);
        $edges = collect($data['mapEdges']);

        $this->assertNotNull($nodes->firstWhere('map_key', 'fiber-splitter:'.$splitterId));
        $verifiedRoute = $edges->first(fn ($edge) => str_contains($edge['label'] ?? '', 'CBL-FIBER-MAP-VERIFIED'));
        $this->assertNotNull($verifiedRoute);
        $this->assertSame('verified', $verifiedRoute['status']);
        $chain = $edges->first(fn ($edge) => str_contains($edge['label'] ?? '', 'SPL-FIBER-MAP-TEST OUT-2'));
        $this->assertNotNull($chain);
        $this->assertSame(4, (int) $chain['source_core']);
        $this->assertSame(4, (int) $chain['target_core']);
        $this->assertSame('Brown', $chain['source_core_color']);
        $this->assertNull($edges->first(fn ($edge) => str_contains($edge['label'] ?? '', 'CBL-FIBER-MAP-PENDING')));
    }

    public function test_public_network_map_exposes_verified_fiber_assets_without_customer_records(): void
    {
        $map = NetworkTopologyMap::create([
            'name' => 'Public Fiber Map Test',
            'slug' => 'public-fiber-map-test',
            'is_default' => false,
            'created_by' => auth()->id(),
            'owner_user_id' => auth()->id(),
        ]);
        $token = str_repeat('P', 40);
        $map->update([
            'public_token_hash' => hash('sha256', $token),
            'public_link_active' => true,
        ]);

        $splitterId = DB::table('fiber_splitters')->insertGetId([
            'splitter_id' => 'SPL-PUBLIC-MAP-TEST',
            'location' => 'Public infrastructure point',
            'latitude' => 23.8103,
            'longitude' => 90.4125,
            'ratio' => '1:8',
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fiber_cables')->insert([
            'cable_id' => 'CBL-PUBLIC-MAP-TEST',
            'route_name' => 'Verified public cable route',
            'start_latitude' => 23.8103,
            'start_longitude' => 90.4125,
            'end_latitude' => 23.8203,
            'end_longitude' => 90.4225,
            'core_count' => 12,
            'color_standard' => 'TIA-598',
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $view = app(PublicNetworkMapController::class)->show($token);
        $data = $view->getData();
        $nodes = collect($data['nodePayload']);
        $edges = collect($data['edges']);

        $this->assertNotNull($nodes->firstWhere('id', 'fiber-splitter-'.$splitterId));
        $this->assertNull($nodes->first(fn ($node) => ($node['kind'] ?? null) === 'customer'));
        $this->assertNotNull($edges->first(fn ($edge) => str_contains($edge['label'] ?? '', 'CBL-PUBLIC-MAP-TEST')));
    }

    public function test_splitter_port_can_record_cable_core_and_next_splitter_before_verification(): void
    {
        $cableId = DB::table('fiber_cables')->insertGetId([
            'cable_id' => 'CBL-SPL-CHAIN',
            'route_name' => 'Splitter chain',
            'core_count' => 12,
            'color_standard' => 'TIA-598',
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceId = DB::table('fiber_splitters')->insertGetId([
            'splitter_id' => 'SPL-CHAIN-A', 'ratio' => '1:8', 'status' => 'verified',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $targetId = DB::table('fiber_splitters')->insertGetId([
            'splitter_id' => 'SPL-CHAIN-B', 'ratio' => '1:8',
            'input_cable_id' => $cableId, 'input_core' => 4, 'status' => 'verified',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test(FiberMapping::class)
            ->set('splitter_ref', (string) $sourceId)
            ->set('output_port', '3')
            ->set('next_splitter_id', (string) $targetId)
            ->set('output_cable_id', (string) $cableId)
            ->set('output_core', '4')
            ->call('saveSplitterPort')
            ->assertHasNoErrors();

        $port = DB::table('fiber_splitter_ports')->where('splitter_id', $sourceId)->where('output_port', 3)->first();
        $this->assertNotNull($port);
        $this->assertSame($targetId, (int) $port->next_splitter_id);
        $this->assertSame($cableId, (int) $port->output_cable_id);
        $this->assertSame(4, (int) $port->output_core);

        Livewire::test(FiberMapping::class)->call('verify', 'splitter_port', $port->id);
        $this->assertSame('verified', DB::table('fiber_splitter_ports')->find($port->id)->status);
    }

}
