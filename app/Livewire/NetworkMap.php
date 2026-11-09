<?php
namespace App\Livewire;
use App\Models\CustomersAddress;
use App\Models\CustomersInfo;
use App\Models\RouterList;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\NetworkTopologyLink;
use App\Models\NetworkTopologyNode;
use App\Models\NetworkTopologyMap;
use App\Models\NetworkMapFeature;
use App\Models\User;
use App\Models\MainSiteData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
class NetworkMap extends Component
{
    public int $mapId = 0;
    public string $mapOwnerUserId = '';
    public string $publicShareUrl = '';
    public string $message = '';

    public function mount(): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup'])) abort(403);

        $default = NetworkTopologyMap::where('is_default', true)->first()
            ?: NetworkTopologyMap::orderBy('id')->first();
        abort_unless($default, 500, 'No network topology map is configured.');

        $requestedId = (int) request()->query('map_id', 0);
        $selected = $requestedId ? NetworkTopologyMap::find($requestedId) : null;
        $selected = $selected ?: $default;
        $this->mapId = (int) $selected->id;
        $this->mapOwnerUserId = (string) ($selected->owner_user_id ?: $selected->created_by ?: '');
    }

    private function requireMapAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
    }

    private function captureMapSnapshot(string $label): void
    {
        $map = NetworkTopologyMap::findOrFail($this->mapId);
        $nodes = DB::table('network_topology_nodes')->where('map_id', $this->mapId)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        $links = DB::table('network_topology_links')->where('map_id', $this->mapId)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        $features = NetworkMapFeature::where('map_id', $this->mapId)->orderBy('id')->get()
            ->map(fn ($row) => $row->toArray())->all();

        DB::table('network_topology_map_snapshots')->insert([
            'map_id' => $this->mapId,
            'label' => mb_substr(trim($label) ?: 'Manual map snapshot', 0, 160),
            'snapshot_data' => json_encode([
                'version' => 1,
                'map' => ['id' => $map->id, 'name' => $map->name, 'description' => $map->description],
                'nodes' => $nodes,
                'links' => $links,
                'features' => $features,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function saveMapSnapshot(): void
    {
        $this->requireMapAdmin();
        $this->captureMapSnapshot('Manual snapshot · '.now()->format('d M Y H:i'));
        $this->message = 'Map snapshot saved. Existing nodes and connections were not changed.';
    }

    public function restoreMapSnapshot(int $snapshotId): void
    {
        $this->requireMapAdmin();
        $snapshot = DB::table('network_topology_map_snapshots')
            ->where('map_id', $this->mapId)->where('id', $snapshotId)->first();
        abort_unless($snapshot, 404);
        $payload = json_decode((string) $snapshot->snapshot_data, true);
        abort_unless(is_array($payload) && isset($payload['nodes'], $payload['links'])
            && is_array($payload['nodes']) && is_array($payload['links']), 422);
        $payload['features'] = is_array($payload['features'] ?? null) ? $payload['features'] : [];
        abort_if(count($payload['nodes']) > 2000 || count($payload['links']) > 10000 || count($payload['features']) > 5000, 422);

        $this->captureMapSnapshot('Automatic safety snapshot before restore');
        DB::transaction(function () use ($payload): void {
            DB::table('network_topology_links')->where('map_id', $this->mapId)->delete();
            DB::table('network_topology_nodes')->where('map_id', $this->mapId)->delete();
            NetworkMapFeature::where('map_id', $this->mapId)->delete();

            foreach ($payload['nodes'] as $row) {
                abort_unless(is_array($row) && isset($row['id'], $row['type'], $row['name']), 422);
                $row['map_id'] = $this->mapId;
                $row['updated_at'] = now();
                DB::table('network_topology_nodes')->insert($row);
            }
            foreach ($payload['links'] as $row) {
                abort_unless(is_array($row) && isset($row['id'], $row['source_key'], $row['target_key']), 422);
                $row['map_id'] = $this->mapId;
                $row['updated_at'] = now();
                DB::table('network_topology_links')->insert($row);
            }
            foreach ($payload['features'] as $row) {
                abort_unless(is_array($row) && isset($row['id'], $row['feature_type'], $row['name'], $row['geometry']), 422);
                $row['map_id'] = $this->mapId;
                $row['updated_at'] = now();
                NetworkMapFeature::create($row);
            }
        });

        $this->message = 'Map version restored. A safety snapshot of the previous state was retained.';
    }

    public function updatedMapOwnerUserId(): void
    {
        $this->requireMapAdmin();
        $value = $this->mapOwnerUserId === '' ? null : (int) $this->mapOwnerUserId;
        if ($value !== null) {
            Validator::make(['owner_user_id' => $value], [
                'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            ])->validate();
        }
        NetworkTopologyMap::whereKey($this->mapId)->update(['owner_user_id' => $value]);
        $this->message = 'Network map owner updated.';
    }

    public function generatePublicLink(): void
    {
        $this->requireMapAdmin();
        $token = Str::random(48);
        NetworkTopologyMap::whereKey($this->mapId)->update([
            'public_token_hash' => hash('sha256', $token),
            'public_link_active' => true,
            'public_link_expires_at' => now()->addDays(30),
        ]);
        $this->publicShareUrl = route('network-map.public', ['token' => $token]);
        $this->message = 'Read-only public link created. It expires in 30 days.';
    }

    public function revokePublicLink(): void
    {
        $this->requireMapAdmin();
        NetworkTopologyMap::whereKey($this->mapId)->update([
            'public_token_hash' => null,
            'public_link_active' => false,
            'public_link_expires_at' => null,
        ]);
        $this->publicShareUrl = '';
        $this->message = 'Public map link revoked.';
    }

    #[On('network-map-save-layout')]
    public function saveMapLayout(array $positions): void
    {
        $this->requireMapAdmin();
        Validator::make(['positions' => $positions], [
            'positions' => ['required', 'array', 'min:1', 'max:100'],
            'positions.*.id' => ['required', 'integer', 'min:1'],
            'positions.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ])->validate();

        $ids = collect($positions)->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
        abort_if($ids->count() !== count($positions), 422, 'Duplicate map node IDs are not allowed.');
        $nodes = NetworkTopologyNode::query()
            ->where('map_id', $this->mapId)
            ->where('is_published', true)
            ->whereIn('id', $ids)
            ->get()->keyBy('id');
        abort_unless($nodes->count() === $ids->count(), 422, 'Only published custom topology nodes can be moved in the geographic editor.');

        $changed = collect($positions)->filter(function ($position) use ($nodes) {
            $node = $nodes->get((int) $position['id']);
            return $node && ((float) $node->latitude !== (float) $position['lat']
                || (float) $node->longitude !== (float) $position['lng']);
        })->values();
        if ($changed->isEmpty()) {
            $this->dispatch('network-map-layout-saved', message: 'No coordinate changes to save.');
            return;
        }

        $this->captureMapSnapshot('Before geographic layout edit');
        DB::transaction(function () use ($changed): void {
            foreach ($changed as $position) {
                NetworkTopologyNode::query()->where('map_id', $this->mapId)
                    ->whereKey((int) $position['id'])
                    ->update([
                        'latitude' => (float) $position['lat'],
                        'longitude' => (float) $position['lng'],
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->message = $changed->count().' topology node position(s) saved.';
        $this->dispatch('network-map-layout-saved', message: $this->message);
    }
    private function validDrawnGeometry(array $geometry): bool
    {
        $type = $geometry['type'] ?? null;
        if (! in_array($type, ['Point', 'LineString', 'Polygon'], true)) return false;
        $coordinates = $geometry['coordinates'] ?? null;
        if (! is_array($coordinates)) return false;

        $count = 0;
        $walk = function ($value) use (&$walk, &$count): bool {
            if (! is_array($value) || $value === []) return false;
            if (count($value) === 2 && array_is_list($value) && is_numeric($value[0]) && is_numeric($value[1])) {
                $lng = (float) $value[0];
                $lat = (float) $value[1];
                $count++;
                return $lng >= -180 && $lng <= 180 && $lat >= -90 && $lat <= 90 && $count <= 2000;
            }
            foreach ($value as $child) if (! $walk($child)) return false;
            return true;
        };

        if (! $walk($coordinates)) return false;
        return match ($type) {
            'Point' => $count === 1,
            'LineString' => $count >= 2,
            'Polygon' => $count >= 4,
            default => false,
        };
    }

    #[On('network-map-save-drawn-feature')]
    public function saveDrawnFeature(array $feature): void
    {
        $this->requireMapAdmin();
        $data = Validator::make($feature, [
            'feature_type' => ['required', 'in:fiber_route,cable_route,service_area,map_pin'],
            'name' => ['required', 'string', 'max:160'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:Point,LineString,Polygon'],
            'geometry.coordinates' => ['required', 'array'],
        ])->validate();
        $expected = ['fiber_route' => 'LineString', 'cable_route' => 'LineString', 'service_area' => 'Polygon', 'map_pin' => 'Point'];
        abort_unless(($expected[$data['feature_type']] ?? null) === $data['geometry']['type'] && $this->validDrawnGeometry($data['geometry']), 422, 'Invalid map geometry or coordinates.');

        $this->captureMapSnapshot('Before adding map feature');
        $record = NetworkMapFeature::create([
            'map_id' => $this->mapId,
            'feature_type' => $data['feature_type'],
            'name' => trim($data['name']),
            'geometry' => $data['geometry'],
            'properties' => ['editor' => 'leaflet-geoman'],
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
        $this->dispatch('network-map-feature-saved', id: $record->id, message: 'Map feature saved as pending verification.');
    }

    #[On('network-map-update-drawn-feature')]
    public function updateDrawnFeature(int $id, array $geometry): void
    {
        $this->requireMapAdmin();
        abort_unless($this->validDrawnGeometry($geometry), 422, 'Invalid map geometry or coordinates.');
        $record = NetworkMapFeature::where('map_id', $this->mapId)->findOrFail($id);
        $this->captureMapSnapshot('Before editing map feature');
        $record->update(['geometry' => $geometry, 'status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
        $this->dispatch('network-map-feature-saved', id: $record->id, message: 'Edited feature returned to pending verification.');
    }

    #[On('network-map-delete-drawn-feature')]
    public function deleteDrawnFeature(int $id): void
    {
        $this->requireMapAdmin();
        $record = NetworkMapFeature::where('map_id', $this->mapId)->findOrFail($id);
        $this->captureMapSnapshot('Before deleting map feature');
        $record->delete();
        $this->dispatch('network-map-feature-saved', id: $id, message: 'Map feature deleted.');
    }

    public function verifyDrawnFeature(int $id): void
    {
        $this->requireMapAdmin();
        $record = NetworkMapFeature::where('map_id', $this->mapId)->findOrFail($id);
        $this->captureMapSnapshot('Before verifying map feature');
        $record->update(['status' => 'published', 'verified_by' => auth()->id(), 'verified_at' => now()]);
        $this->message = 'Map feature verified and published.';
        $this->dispatch('network-map-feature-saved', id: $record->id, message: $this->message);
    }

    public function render()
    {
        $routers = RouterList::orderBy('router_name')->get();
        $routerNodes = $routers
            ->filter(fn ($r) => $r->latitude !== null && $r->longitude !== null)
            ->map(fn ($r) => [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'id' => $r->id,
                'label' => $r->router_name,
                'ip' => $r->ip_address,
                'location' => $r->location,
                'status' => $this->normalizeStatus($r->action === 'connected' ? 'online' : 'offline'),
                'health_status' => $r->action ?: 'unknown',
                'last_checked_at' => $r->last_checked_at ? (string) $r->last_checked_at : null,
                'last_latency_ms' => $r->last_latency_ms,
                'kind' => 'router',
                'router' => $r->router_name,
            ])->values()->toArray();

        $inventoryDevices = NetworkInventoryDevice::query()
            ->whereIn('type', ['olt', 'onu', 'switch', 'wifi-router', 'access-point'])
            ->get();
        $deviceLocations = $inventoryDevices->map(function ($d) {
            $lat = $d->latitude;
            $lng = $d->longitude;
            if (($lat === null || $lng === null) && is_string($d->location) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $d->location, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
            }
            if ($lat === null || $lng === null) {
                return null;
            }

            return [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'id' => $d->id,
                'label' => $d->name,
                'status' => $this->normalizeStatus($d->health_status ?: ($d->status ?: 'unknown')),
                'health_status' => $d->health_status ?: ($d->status ?: 'unknown'),
                'router' => null,
                'kind' => $d->type,
                'ip' => $d->ip_address ?: $d->host,
                'location' => $d->location,
                'vendor' => $d->vendor,
                'model' => $d->model,
                'firmware' => $d->firmware,
                'serial_no' => $d->serial_no,
                'last_checked_at' => $d->last_checked_at?->format('Y-m-d H:i:s'),
                'last_latency_ms' => $d->last_latency_ms,
                'onu_total' => $d->onu_total,
                'onu_online' => $d->onu_online,
                'rx_power' => $d->rx_power,
                'customer_count' => $d->customer_count,
            ];
        })->filter()->values()->toArray();

        $publishedCustomNodes = NetworkTopologyNode::query()
            ->where('map_id', $this->mapId)
            ->where('is_published', true)
            ->get();
        $topologyLocations = $publishedCustomNodes->filter(
            fn ($node) => $node->latitude !== null && $node->longitude !== null
        )->map(fn ($node) => [
            'lat' => (float) $node->latitude,
            'lng' => (float) $node->longitude,
            'id' => $node->id,
            'map_key' => 'topology:'.$node->id,
            'label' => $node->name,
            'status' => 'unknown',
            'health_status' => 'manual topology asset; live status not connected',
            'kind' => $node->type,
            'asset_type' => $node->type,
            'location' => $node->location,
            'notes' => $node->notes,
        ])->values()->toArray();

        // Verified field GPS from the physical fiber register takes precedence over
        // an older customer address point. It is never inferred or generated.
        $verifiedFiberCustomerLocations = Schema::hasTable('fiber_customer_locations')
            ? DB::table('fiber_customer_locations as l')
                ->join('customers_infos as c', 'c.id', '=', 'l.customer_id')
                ->where('l.status', 'verified')->whereNull('c.deleted_at')
                ->whereNotNull('l.latitude')->whereNotNull('l.longitude')
                ->whereBetween('l.latitude', [-90, 90])->whereBetween('l.longitude', [-180, 180])
                ->get(['l.customer_id', 'l.latitude', 'l.longitude', 'l.location_note', 'c.customer_unique_id', 'c.customer_name'])
            : collect();

        foreach ($verifiedFiberCustomerLocations as $fiberLocation) {
            $customerKey = 'fiber-customer:'.$fiberLocation->customer_id;
            $foundIndex = null;
            foreach ($nodes as $index => $existingNode) {
                if (($existingNode['kind'] ?? '') === 'customer'
                    && (string) ($existingNode['customer_uid'] ?? '') === (string) $fiberLocation->customer_unique_id) {
                    $foundIndex = $index;
                    break;
                }
            }
            $verifiedPoint = [
                'lat' => (float) $fiberLocation->latitude,
                'lng' => (float) $fiberLocation->longitude,
                'id' => $fiberLocation->customer_unique_id,
                'map_key' => $customerKey,
                'label' => $fiberLocation->customer_name ?: $fiberLocation->customer_unique_id,
                'customer_uid' => $fiberLocation->customer_unique_id,
                'status' => 'unknown',
                'health_status' => 'verified field GPS; live session status not inferred',
                'location' => $fiberLocation->location_note,
                'kind' => 'customer',
            ];
            if ($foundIndex !== null) {
                $nodes[$foundIndex] = array_merge($nodes[$foundIndex], $verifiedPoint);
                $nodes[$foundIndex]['customer_url'] = route('customers.show', ['id' => $fiberLocation->customer_unique_id]);
            } else {
                $verifiedPoint['customer_url'] = route('customers.show', ['id' => $fiberLocation->customer_unique_id]);
                $nodes[] = $verifiedPoint;
            }
        }

        $fiberSplitterLocations = Schema::hasTable('fiber_splitters')
            ? DB::table('fiber_splitters')->where('status', 'verified')
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereBetween('latitude', [-90, 90])->whereBetween('longitude', [-180, 180])
                ->orderBy('id')->get()->map(fn ($row) => [
                    'lat' => (float) $row->latitude, 'lng' => (float) $row->longitude,
                    'id' => $row->id, 'map_key' => 'fiber-splitter:'.$row->id,
                    'label' => $row->splitter_id, 'kind' => 'splitter',
                    'status' => 'unknown', 'health_status' => 'field verified; live telemetry unavailable',
                    'location' => $row->location, 'splitter_ratio' => $row->ratio,
                    'port_reference' => $row->input_port, 'fiber_registry_status' => 'verified',
                ])->values()->all()
            : [];

        $fiberClosureLocations = Schema::hasTable('fiber_splice_closures')
            ? DB::table('fiber_splice_closures')->whereExists(function ($query) {
                    $query->selectRaw('1')->from('fiber_splice_records as sr')
                        ->whereColumn('sr.closure_id', 'fiber_splice_closures.id')->where('sr.status', 'verified');
                })->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereBetween('latitude', [-90, 90])->whereBetween('longitude', [-180, 180])
                ->orderBy('id')->get()->map(fn ($row) => [
                    'lat' => (float) $row->latitude, 'lng' => (float) $row->longitude,
                    'id' => $row->id, 'map_key' => 'fiber-closure:'.$row->id,
                    'label' => $row->closure_id, 'kind' => 'splice',
                    'status' => 'unknown', 'health_status' => 'field registered closure; no live telemetry',
                    'location' => $row->location, 'port_reference' => $row->tray_reference,
                ])->values()->all()
            : [];

        $fiberCableEndpointNodes = Schema::hasTable('fiber_cables')
            ? DB::table('fiber_cables')->where('status', 'verified')
                ->whereNotNull('start_latitude')->whereNotNull('start_longitude')
                ->whereNotNull('end_latitude')->whereNotNull('end_longitude')
                ->whereBetween('start_latitude', [-90, 90])->whereBetween('start_longitude', [-180, 180])
                ->whereBetween('end_latitude', [-90, 90])->whereBetween('end_longitude', [-180, 180])
                ->get()->flatMap(fn ($cable) => [
                    [
                        'lat' => (float) $cable->start_latitude, 'lng' => (float) $cable->start_longitude,
                        'id' => 'cable-'.$cable->id.'-start', 'map_key' => 'fiber-cable:'.$cable->id.':start',
                        'label' => $cable->cable_id.' · Start', 'kind' => 'fiber_segment',
                        'status' => 'unknown', 'health_status' => 'surveyed cable endpoint; no live telemetry',
                        'location' => $cable->start_location, 'asset_type' => 'fiber_cable_endpoint',
                    ],
                    [
                        'lat' => (float) $cable->end_latitude, 'lng' => (float) $cable->end_longitude,
                        'id' => 'cable-'.$cable->id.'-end', 'map_key' => 'fiber-cable:'.$cable->id.':end',
                        'label' => $cable->cable_id.' · End', 'kind' => 'fiber_segment',
                        'status' => 'unknown', 'health_status' => 'surveyed cable endpoint; no live telemetry',
                        'location' => $cable->end_location, 'asset_type' => 'fiber_cable_endpoint',
                    ],
                ])->values()->all()
            : [];

        $customers = CustomersInfo::whereNull('deleted_at')->count();

        // Queue only customers that do not have a complete, valid Network Location.
        // Coordinates are entered by an operator in the existing Customer Edit map;
        // no approximate or generated GPS values are introduced here.
        $unlocatedCustomerRecords = CustomersInfo::query()
            ->whereNull('customers_infos.deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('customers_addresses as ca')
                    ->whereColumn('ca.customer_address_unique_id', 'customers_infos.customer_unique_id')
                    ->where('ca.label_name', 'Network Location')
                    ->whereNotNull('ca.latitude')
                    ->whereNotNull('ca.longitude')
                    ->where('ca.latitude', '!=', '')
                    ->where('ca.longitude', '!=', '')
                    ->whereBetween('ca.latitude', [-90, 90])
                    ->whereBetween('ca.longitude', [-180, 180]);
            })
            ->orderBy('customers_infos.customer_unique_id')
            ->limit(100)
            ->get([
                'customers_infos.customer_unique_id',
                'customers_infos.customer_name',
            ]);

        $nodes = CustomersAddress::query()
            ->leftJoin('customers_infos as ci', 'ci.customer_unique_id', '=', 'customers_addresses.customer_address_unique_id')
            ->leftJoin('p_p_p_secrets as ps', 'ps.id', '=', 'ci.ppp_user_id')
            ->where('customers_addresses.label_name', 'Network Location')
            ->whereNull('ci.deleted_at')
            ->whereNotNull('ci.customer_unique_id')
            ->whereNotNull('customers_addresses.latitude')
            ->whereNotNull('customers_addresses.longitude')
            ->where('customers_addresses.latitude', '!=', '')
            ->where('customers_addresses.longitude', '!=', '')
            ->whereBetween('customers_addresses.latitude', [-90, 90])
            ->whereBetween('customers_addresses.longitude', [-180, 180])
            ->limit(1000)
            ->get([
                'customers_addresses.latitude', 'customers_addresses.longitude',
                'customers_addresses.customer_address_unique_id', 'customers_addresses.label_name',
                'ci.customer_name', 'ci.status as customer_status', 'ps.router_name',
            ])
            ->unique('customer_address_unique_id')
            ->map(fn ($a) => [
                'lat' => (float) $a->latitude,
                'lng' => (float) $a->longitude,
                'id' => $a->customer_address_unique_id,
                'label' => $a->customer_name ?: $a->customer_address_unique_id,
                'customer_uid' => $a->customer_address_unique_id,
                'customer_url' => route('customers.show', ['id' => $a->customer_address_unique_id]),
                // Active billing status is not proof of a live network session.
                'status' => 'unknown',
                'account_status' => $a->customer_status,
                'router' => $a->router_name,
                'kind' => 'customer',
            ])->values()->toArray();

        // Build ONU markers from the real OLT ↔ customer mapping ledger.
        // ONU coordinates are represented by the linked customer's saved Network Location;
        // no synthetic coordinates or optical distance are generated.
        $customerLocations = collect($nodes)->where('kind', 'customer')->keyBy('customer_uid');
        $onuMappings = OltOnuCustomerMapping::with(['olt', 'customer', 'pppUser'])
            ->whereNotNull('customer_id')->latest('last_seen_at')->get();
        $onuNodes = $onuMappings->map(function ($mapping) use ($customerLocations) {
            $customerUid = $mapping->customer?->customer_unique_id;
            $customerLocation = $customerUid ? $customerLocations->get($customerUid) : null;
            if (! $customerLocation) return null;
            $olt = $mapping->olt;
            return [
                'lat' => $customerLocation['lat'], 'lng' => $customerLocation['lng'],
                'id' => $mapping->id, 'map_key' => 'onu:'.$mapping->id,
                'label' => 'ONU '.($mapping->onu_id ?: $mapping->onu_serial ?: $mapping->id),
                'status' => $this->normalizeStatus($mapping->status),
                'health_status' => $mapping->status ?: 'unknown', 'kind' => 'onu',
                'customer_uid' => $customerUid,
                'customer_url' => route('customers.show', ['id' => $customerUid]),
                'customer_name' => $mapping->customer?->customer_name,
                'router' => $mapping->pppUser?->router_name,
                'olt_id' => $mapping->olt_device_id, 'olt_name' => $olt?->name,
                'pon_port' => $mapping->pon_port, 'onu_id' => $mapping->onu_id,
                'onu_serial' => $mapping->onu_serial, 'onu_mac' => $mapping->onu_mac,
                'onu_ip' => $mapping->onu_ip, 'rx_power' => $mapping->rx_power,
                'tx_power' => $mapping->tx_power, 'last_seen_at' => $mapping->last_seen_at?->toIso8601String(),
                'location' => $customerLocation['location'] ?? null,
            ];
        })->filter()->values()->all();

        $mappedCustomers = count($nodes);
        $unmappedCustomers = max(0, $customers - $mappedCustomers);
        $unmappedRouters = max(0, $routers->count() - count($routerNodes));
        $unmappedDevices = max(0, $inventoryDevices->count() - count($deviceLocations));

        $nodes = collect(array_merge($nodes, $routerNodes, $deviceLocations, $topologyLocations, $fiberSplitterLocations, $fiberClosureLocations, $fiberCableEndpointNodes, $onuNodes));
        $coordinates = $nodes->keyBy(fn ($node) => $node['map_key'] ?? (($node['kind'] === 'router' ? 'router:' : 'device:') . $node['id']));
        $publishedConnections = NetworkTopologyLink::query()
            ->where('map_id', $this->mapId)
            ->where('is_published', true)
            ->get();
        $mapEdges = $publishedConnections->map(function ($link) use ($coordinates) {
            if (! $coordinates->has($link->source_key) || ! $coordinates->has($link->target_key)) {
                return null;
            }
            $source = $coordinates->get($link->source_key);
            $target = $coordinates->get($link->target_key);

            return [
                'fromKey' => $link->source_key,
                'toKey' => $link->target_key,
                'from' => [(float) $source['lat'], (float) $source['lng']],
                'to' => [(float) $target['lat'], (float) $target['lng']],
                'type' => $link->connection_type,
                'label' => $link->label ?: str_replace('_', ' ', $link->connection_type),
                'status' => $link->status ?: 'unknown',
                'capacity_mbps' => $link->capacity_mbps,
                'traffic_mbps' => $link->traffic_mbps,
                'latency_ms' => $link->latency_ms,
                'packet_loss' => $link->packet_loss,
                'fiber_core' => $link->fiber_core,
                'fiber_type' => $link->fiber_type,
                'source_port' => $link->source_port,
                'target_port' => $link->target_port,
            ];
        })->filter()->values()->toArray();

        // Draw only real OLT-to-ONU mappings where both endpoint coordinates exist.
        $oltOnuEdges = collect($onuNodes)->map(function ($onu) use ($coordinates) {
            $oltKey = 'device:'.$onu['olt_id'];
            if (! $coordinates->has($oltKey)) return null;
            $olt = $coordinates->get($oltKey);
            return [
                'fromKey' => $oltKey, 'toKey' => $onu['map_key'],
                'from' => [(float) $olt['lat'], (float) $olt['lng']],
                'to' => [(float) $onu['lat'], (float) $onu['lng']],
                'type' => 'fiber_core',
                'label' => trim(($onu['olt_name'] ?: 'OLT').' / PON '.($onu['pon_port'] ?: '—').' → '.$onu['label']),
                'status' => $onu['status'], 'fiber_core' => null, 'fiber_type' => 'unknown',
                'rx_power' => $onu['rx_power'], 'tx_power' => $onu['tx_power'],
                'onu_id' => $onu['onu_id'], 'onu_mac' => $onu['onu_mac'],
            ];
        })->filter()->values()->all();
        $mapEdges = array_merge($mapEdges, $oltOnuEdges);

        // Map verified physical cable paths from their two explicitly surveyed endpoints.
        // These are rendered as independent route lines: no splitter-side endpoint is guessed.
        $fiberCableEdges = Schema::hasTable('fiber_cables')
            ? DB::table('fiber_cables')->where('status', 'verified')
                ->whereNotNull('start_latitude')->whereNotNull('start_longitude')
                ->whereNotNull('end_latitude')->whereNotNull('end_longitude')
                ->whereBetween('start_latitude', [-90, 90])->whereBetween('start_longitude', [-180, 180])
                ->whereBetween('end_latitude', [-90, 90])->whereBetween('end_longitude', [-180, 180])
                ->orderBy('id')->get()->map(fn ($cable) => [
                    'fromKey' => 'fiber-cable:'.$cable->id.':start',
                    'toKey' => 'fiber-cable:'.$cable->id.':end',
                    'from' => [(float) $cable->start_latitude, (float) $cable->start_longitude],
                    'to' => [(float) $cable->end_latitude, (float) $cable->end_longitude],
                    'type' => 'fiber_core',
                    'label' => 'Cable '.$cable->cable_id.' · '.$cable->route_name.' · '.$cable->core_count.'C',
                    'status' => 'verified', 'fiber_core_count' => (int) $cable->core_count,
                    'fiber_color_standard' => $cable->color_standard,
                    'length_m' => $cable->length_m, 'source' => 'verified_fiber_registry',
                ])->values()->all()
            : [];

        // Explicit splitter OUT → next splitter links only; cable endpoint orientation
        // is intentionally not inferred from the cable register.
        $fiberSplitterPortEdges = (Schema::hasTable('fiber_splitter_ports') && Schema::hasTable('fiber_splitters'))
            ? DB::table('fiber_splitter_ports as p')
                ->join('fiber_splitters as s', 's.id', '=', 'p.splitter_id')
                ->join('fiber_splitters as n', 'n.id', '=', 'p.next_splitter_id')
                ->join('fiber_cables as c', 'c.id', '=', 'p.output_cable_id')
                ->join('fiber_cables as tc', 'tc.id', '=', 'n.input_cable_id')
                ->where('p.status', 'verified')->where('s.status', 'verified')->where('n.status', 'verified')
                ->where('c.status', 'verified')->where('tc.status', 'verified')
                ->whereNotNull('p.output_core')->whereNotNull('n.input_core')
                ->whereNotNull('s.latitude')->whereNotNull('s.longitude')
                ->whereNotNull('n.latitude')->whereNotNull('n.longitude')
                ->whereBetween('s.latitude', [-90, 90])->whereBetween('s.longitude', [-180, 180])
                ->whereBetween('n.latitude', [-90, 90])->whereBetween('n.longitude', [-180, 180])
                ->where(function ($query) {
                    $query->where(function ($same) {
                        $same->whereColumn('p.output_cable_id', 'n.input_cable_id')
                            ->whereColumn('p.output_core', 'n.input_core');
                    })->orWhereExists(function ($splice) {
                        $splice->selectRaw('1')->from('fiber_splice_records as sr')->where('sr.status', 'verified')
                            ->where(function ($mapping) {
                                $mapping->where(function ($forward) {
                                    $forward->whereColumn('sr.source_cable_id', 'p.output_cable_id')->whereColumn('sr.source_core', 'p.output_core')
                                        ->whereColumn('sr.target_cable_id', 'n.input_cable_id')->whereColumn('sr.target_core', 'n.input_core');
                                })->orWhere(function ($reverse) {
                                    $reverse->whereColumn('sr.source_cable_id', 'n.input_cable_id')->whereColumn('sr.source_core', 'n.input_core')
                                        ->whereColumn('sr.target_cable_id', 'p.output_cable_id')->whereColumn('sr.target_core', 'p.output_core');
                                });
                            });
                    });
                })
                ->get(['p.output_port', 'p.output_core', 'c.cable_id', 'c.color_standard', 'c.core_count',
                    's.id as source_id', 's.splitter_id as source_name', 's.latitude as source_lat', 's.longitude as source_lng',
                    'n.id as target_id', 'n.splitter_id as target_name', 'n.latitude as target_lat', 'n.longitude as target_lng', 'n.input_port', 'n.input_core', 'tc.color_standard as target_color_standard'])
                ->map(function ($row) {
                    $palette = [
                        ['Blue','#0070C0'], ['Orange','#ED7D31'], ['Green','#70AD47'], ['Brown','#A56A43'],
                        ['Slate','#7F7F7F'], ['White','#F2F2F2'], ['Red','#C00000'], ['Black','#262626'],
                        ['Yellow','#FFC000'], ['Violet','#7030A0'], ['Rose','#E6B8C7'], ['Aqua','#00B0F0'],
                    ];
                    $core = (int) $row->output_core;
                    $known = strtoupper((string) $row->color_standard) === 'TIA-598';
                    [$colour,$hex] = $known ? $palette[($core - 1) % 12] : ['Manufacturer chart', null];
                    $targetCore = (int) $row->input_core;
                    [$targetColour,$targetHex] = strtoupper((string) $row->target_color_standard) === 'TIA-598'
                        ? $palette[($targetCore - 1) % 12] : ['Manufacturer chart', null];
                    return [
                        'fromKey' => 'fiber-splitter:'.$row->source_id, 'toKey' => 'fiber-splitter:'.$row->target_id,
                        'from' => [(float) $row->source_lat, (float) $row->source_lng],
                        'to' => [(float) $row->target_lat, (float) $row->target_lng],
                        'type' => 'splitter',
                        'label' => $row->source_name.' OUT-'.$row->output_port.' → '.$row->target_name.' · '.$row->cable_id.' C'.$core.' '.$colour.' → C'.$targetCore.' '.$targetColour,
                        'status' => 'verified', 'source_port' => 'OUT-'.$row->output_port,
                        'target_port' => $row->input_port, 'fiber_core' => $core,
                        'fiber_core_color' => $colour, 'fiber_core_color_hex' => $hex,
                        'source_core' => $core, 'source_core_color' => $colour, 'source_core_hex' => $hex,
                        'target_core' => $targetCore, 'target_core_color' => $targetColour, 'target_core_hex' => $targetHex,
                        'source' => 'verified_fiber_registry',
                    ];
                })->values()->all()
            : [];

        // OLT/PON → splitter appears only when both ledgers point to the same
        // uplink cable and the OLT, cable mapping and splitter are verified.
        $fiberPonSplitterEdges = (Schema::hasTable('fiber_olt_pon_mappings') && Schema::hasTable('fiber_splitters'))
            ? DB::table('fiber_olt_pon_mappings as p')
                ->join('fiber_splitters as s', 's.input_cable_id', '=', 'p.uplink_cable_id')
                ->where('p.status', 'verified')->where('s.status', 'verified')
                ->whereNotNull('p.uplink_cable_id')
                ->whereNotNull('s.latitude')->whereNotNull('s.longitude')
                ->whereBetween('s.latitude', [-90, 90])->whereBetween('s.longitude', [-180, 180])
                ->get(['p.olt_device_id', 'p.pon_slot', 'p.pon_port', 's.id as splitter_id', 's.splitter_id as splitter_name', 's.latitude', 's.longitude'])
                ->map(function ($row) use ($coordinates) {
                    $oltKey = 'device:'.$row->olt_device_id;
                    if (! $coordinates->has($oltKey)) return null;
                    $olt = $coordinates->get($oltKey);
                    return [
                        'fromKey' => $oltKey, 'toKey' => 'fiber-splitter:'.$row->splitter_id,
                        'from' => [(float) $olt['lat'], (float) $olt['lng']],
                        'to' => [(float) $row->latitude, (float) $row->longitude],
                        'type' => 'fiber_core',
                        'label' => 'OLT '.$row->olt_device_id.' / Slot '.$row->pon_slot.' / PON '.$row->pon_port.' → '.$row->splitter_name,
                        'status' => 'verified', 'source' => 'verified_fiber_registry',
                    ];
                })->filter()->values()->all()
            : [];

        // Customer-to-splitter is a private operator map edge. All four linked records
        // must be verified and the customer endpoint must use the verified GPS ledger.
        $fiberCustomerMappingEdges = (Schema::hasTable('fiber_customer_mappings')
            && Schema::hasTable('fiber_customer_locations') && Schema::hasTable('fiber_splitter_ports')
            && Schema::hasTable('fiber_splitters'))
            ? DB::table('fiber_customer_mappings as m')
                ->join('fiber_customer_locations as l', 'l.customer_id', '=', 'm.customer_id')
                ->join('fiber_splitter_ports as p', 'p.id', '=', 'm.splitter_port_id')
                ->join('fiber_splitters as s', 's.id', '=', 'p.splitter_id')
                ->where('m.status', 'verified')->where('l.status', 'verified')
                ->where('p.status', 'verified')->where('s.status', 'verified')
                ->whereNotNull('l.latitude')->whereNotNull('l.longitude')
                ->whereNotNull('s.latitude')->whereNotNull('s.longitude')
                ->whereBetween('l.latitude', [-90, 90])->whereBetween('l.longitude', [-180, 180])
                ->whereBetween('s.latitude', [-90, 90])->whereBetween('s.longitude', [-180, 180])
                ->get(['m.customer_id', 'm.splitter_port_id', 'p.output_port', 's.id as splitter_id', 's.splitter_id as splitter_name',
                    'l.latitude as customer_lat', 'l.longitude as customer_lng', 's.latitude as splitter_lat', 's.longitude as splitter_lng'])
                ->map(fn ($row) => [
                    'fromKey' => 'fiber-customer:'.$row->customer_id, 'toKey' => 'fiber-splitter:'.$row->splitter_id,
                    'from' => [(float) $row->customer_lat, (float) $row->customer_lng],
                    'to' => [(float) $row->splitter_lat, (float) $row->splitter_lng],
                    'type' => 'fiber_core', 'label' => 'Verified customer mapping → '.$row->splitter_name.' OUT-'.$row->output_port,
                    'status' => 'verified', 'source' => 'verified_fiber_registry',
                ])->values()->all()
            : [];

        $mapEdges = array_merge($mapEdges, $fiberCableEdges, $fiberSplitterPortEdges, $fiberPonSplitterEdges, $fiberCustomerMappingEdges);

        $mapStats = [
            'total_nodes' => $nodes->count(),
            'online_nodes' => $nodes->where('status', 'online')->count(),
            'offline_nodes' => $nodes->where('status', 'offline')->count(),
            'unknown_nodes' => $nodes->where('status', 'unknown')->count(),
            'active_customers' => $customers,
            'located_customers' => $mappedCustomers,
            'unlocated_customers' => $unmappedCustomers,
            'customer_coverage_percent' => $customers > 0 ? min(100, (int) round(($mappedCustomers / $customers) * 100)) : 0,
            'located_routers' => count($routerNodes),
            'unlocated_routers' => $unmappedRouters,
            'located_devices' => count($deviceLocations),
            'unlocated_devices' => $unmappedDevices,
            'published_custom_nodes' => $publishedCustomNodes->count(),
            'unlocated_custom_nodes' => max(0, $publishedCustomNodes->count() - count($topologyLocations)),
            'published_connections' => $publishedConnections->count(),
            'mapped_connections' => count($mapEdges),
            'mapped_onus' => count($onuNodes),
            'olt_onu_links' => count($oltOnuEdges),
            'unmapped_connections' => max(0, $publishedConnections->count() - count($mapEdges)),
            'generated_at' => now()->format('d M Y, h:i:s A'),
        ];
        $mapKindCounts = $nodes->groupBy('kind')->map(fn ($items) => $items->count())->toArray();

        // Read-only snapshot of the dedicated field-verified fiber register.
        // These tables remain the source of truth; this page never creates or guesses mappings.
        $fiberStats = [
            'cables' => 0,
            'verified_cables' => 0,
            'splitters' => 0,
            'closures' => 0,
            'splices' => 0,
            'verified_splices' => 0,
            'customer_mappings' => 0,
            'verified_customer_mappings' => 0,
            'pending_records' => 0,
        ];

        $fiberTables = [
            'fiber_cables', 'fiber_olt_pon_mappings', 'fiber_splice_records',
            'fiber_splitters', 'fiber_splitter_ports', 'fiber_customer_locations',
            'fiber_customer_mappings',
        ];
        foreach ($fiberTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $fiberStats['pending_records'] += DB::table($table)
                ->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', '!=', 'verified');
                })->count();
        }

        if (Schema::hasTable('fiber_cables')) {
            $fiberStats['cables'] = DB::table('fiber_cables')->count();
            $fiberStats['verified_cables'] = DB::table('fiber_cables')->where('status', 'verified')->count();
        }
        if (Schema::hasTable('fiber_splitters')) {
            $fiberStats['splitters'] = DB::table('fiber_splitters')->count();
        }
        if (Schema::hasTable('fiber_splice_closures')) {
            $fiberStats['closures'] = DB::table('fiber_splice_closures')->count();
        }
        if (Schema::hasTable('fiber_splice_records')) {
            $fiberStats['splices'] = DB::table('fiber_splice_records')->count();
            $fiberStats['verified_splices'] = DB::table('fiber_splice_records')->where('status', 'verified')->count();
        }
        if (Schema::hasTable('fiber_customer_mappings')) {
            $fiberStats['customer_mappings'] = DB::table('fiber_customer_mappings')->count();
            $fiberStats['verified_customer_mappings'] = DB::table('fiber_customer_mappings')->where('status', 'verified')->count();
        }

        $configuredLatitude = MainSiteData::getValue('ipilot_gis_default_latitude', '');
        $configuredLongitude = MainSiteData::getValue('ipilot_gis_default_longitude', '');
        $hasConfiguredCenter = is_numeric($configuredLatitude) && is_numeric($configuredLongitude)
            && (float) $configuredLatitude >= -90 && (float) $configuredLatitude <= 90
            && (float) $configuredLongitude >= -180 && (float) $configuredLongitude <= 180;
        $configuredZoom = (int) MainSiteData::getValue('ipilot_gis_default_zoom', 13);
        $mapConfig = [
            'center' => $hasConfiguredCenter
                ? [(float) $configuredLongitude, (float) $configuredLatitude]
                : [90.4125, 23.8103],
            'zoom' => min(20, max(1, $configuredZoom ?: 10)),
            'basemap' => in_array(MainSiteData::getValue('ipilot_gis_default_basemap', 'auto'), ['auto', 'street', 'satellite', 'dark'], true)
                ? MainSiteData::getValue('ipilot_gis_default_basemap', 'auto')
                : 'auto',
        ];

        $maps = NetworkTopologyMap::with(['createdBy', 'ownerUser'])->orderBy('name')->get();
        $selectedMap = NetworkTopologyMap::with(['createdBy', 'ownerUser'])->findOrFail($this->mapId);
        $mapOwners = auth()->user()?->hasRole('Super Admin')
            ? User::orderBy('name')->get(['id', 'name'])
            : collect();
        $mapFeatures = NetworkMapFeature::where('map_id', $this->mapId)
            ->when(! auth()->user()?->hasRole('Super Admin'), fn ($query) => $query->where('status', 'published'))
            ->orderBy('id')->get()->map(fn ($feature) => [
                'id' => $feature->id,
                'feature_type' => $feature->feature_type,
                'name' => $feature->name,
                'geometry' => $feature->geometry,
                'status' => $feature->status,
            ])->values()->all();

        $snapshots = DB::table('network_topology_map_snapshots as snapshots')
            ->leftJoin('users as snapshot_users', 'snapshot_users.id', '=', 'snapshots.created_by')
            ->where('snapshots.map_id', $this->mapId)
            ->orderByDesc('snapshots.id')->limit(10)
            ->get(['snapshots.id', 'snapshots.label', 'snapshots.created_at', 'snapshot_users.name as created_by_name']);

        // Livewire public properties are not local PHP variables inside render().
        // Explicitly copy them into the render scope before passing them to compact().
        $mapId = $this->mapId;
        $mapOwnerUserId = $this->mapOwnerUserId;
        $publicShareUrl = $this->publicShareUrl;
        $message = $this->message;

        return view('livewire.network-map', compact(
            'routers', 'nodes', 'customers', 'mappedCustomers', 'unmappedCustomers',
            'routerNodes', 'deviceLocations', 'mapEdges', 'mapStats', 'mapKindCounts', 'mapConfig',
            'fiberStats', 'unlocatedCustomerRecords', 'maps', 'selectedMap', 'mapOwners', 'snapshots', 'mapFeatures',
            'mapId', 'mapOwnerUserId', 'publicShareUrl', 'message'
        ))->layout('layouts.app');
    }

    private function normalizeStatus(?string $status): string
    {
        $raw = strtolower(trim((string) $status));
        return in_array($raw, ['online','ready','connected','up','active','running'], true) ? 'online'
            : (in_array($raw, ['offline','failed','down','unreachable','disabled'], true) ? 'offline' : 'unknown');
    }
}