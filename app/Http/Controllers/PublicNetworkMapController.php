<?php

namespace App\Http\Controllers;

use App\Models\NetworkTopologyMap;
use App\Models\NetworkTopologyNode;
use App\Models\NetworkTopologyLink;
use App\Models\NetworkMapFeature;
use Illuminate\Support\Facades\DB;

class PublicNetworkMapController extends Controller
{
    public function show(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40,80}$/', $token), 404);

        $map = NetworkTopologyMap::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->where('public_link_active', true)
            ->where(function ($query) {
                $query->whereNull('public_link_expires_at')
                    ->orWhere('public_link_expires_at', '>', now());
            })
            ->firstOrFail();

        // Share only verified custom topology assets; never expose customer or PPPoE records.
        $nodes = NetworkTopologyNode::query()
            ->where('map_id', $map->id)
            ->where('is_published', true)
            ->where('verification_status', 'verified')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180])
            ->get(['id', 'type', 'name', 'location', 'latitude', 'longitude', 'port_reference', 'splitter_ratio', 'fiber_core_count', 'fiber_color_standard', 'notes']);

        $fiberSplitterNodes = DB::table('fiber_splitters')->where('status', 'verified')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude', [-90, 90])->whereBetween('longitude', [-180, 180])
            ->get()->map(fn ($node) => (object) [
                'id' => 'fiber-splitter-'.$node->id, 'type' => 'splitter', 'name' => $node->splitter_id,
                'location' => $node->location, 'latitude' => $node->latitude, 'longitude' => $node->longitude,
                'port_reference' => $node->input_port, 'splitter_ratio' => $node->ratio,
                'fiber_core_count' => null, 'fiber_color_standard' => null, 'notes' => 'Verified fiber registry asset',
            ]);

        $fiberClosureNodes = DB::table('fiber_splice_closures')->whereExists(function ($query) {
                $query->selectRaw('1')->from('fiber_splice_records as sr')
                    ->whereColumn('sr.closure_id', 'fiber_splice_closures.id')->where('sr.status', 'verified');
            })->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude', [-90, 90])->whereBetween('longitude', [-180, 180])
            ->get()->map(fn ($node) => (object) [
                'id' => 'fiber-closure-'.$node->id, 'type' => 'splice', 'name' => $node->closure_id,
                'location' => $node->location, 'latitude' => $node->latitude, 'longitude' => $node->longitude,
                'port_reference' => $node->tray_reference, 'splitter_ratio' => null,
                'fiber_core_count' => null, 'fiber_color_standard' => null, 'notes' => 'Fiber splice closure',
            ]);

        $nodes = $nodes->concat($fiberSplitterNodes)->concat($fiberClosureNodes)->values();
        $nodeByKey = $nodes->keyBy(fn ($node) => str_starts_with((string) $node->id, 'fiber-')
            ? (string) $node->id : 'topology:'.$node->id);
        $nodePayload = $nodes->map(fn ($node) => [
            'id' => $node->id,
            'map_key' => str_starts_with((string) $node->id, 'fiber-') ? (string) $node->id : 'topology:'.$node->id,
            'kind' => $node->type,
            'label' => $node->name,
            'lat' => (float) $node->latitude,
            'lng' => (float) $node->longitude,
            'location' => $node->location,
            'port_reference' => $node->port_reference,
            'splitter_ratio' => $node->splitter_ratio,
            'fiber_core_count' => $node->fiber_core_count,
            'fiber_color_standard' => $node->fiber_color_standard,
            'notes' => $node->notes,
        ])->values();

        $edges = NetworkTopologyLink::query()
            ->where('map_id', $map->id)
            ->where('is_published', true)
            ->where('verification_status', 'verified')
            ->get()
            ->filter(function ($link) use ($nodeByKey) {
                $source = preg_replace('/^custom:/', 'topology:', (string) $link->source_key);
                $target = preg_replace('/^custom:/', 'topology:', (string) $link->target_key);
                return $nodeByKey->has($source) && $nodeByKey->has($target);
            })
            ->map(function ($link) use ($nodeByKey) {
                $sourceKey = preg_replace('/^custom:/', 'topology:', (string) $link->source_key);
                $targetKey = preg_replace('/^custom:/', 'topology:', (string) $link->target_key);
                $source = $nodeByKey->get($sourceKey);
                $target = $nodeByKey->get($targetKey);

                return [
                    'fromKey' => $sourceKey, 'toKey' => $targetKey,
                    'from' => [(float) $source->latitude, (float) $source->longitude],
                    'to' => [(float) $target->latitude, (float) $target->longitude],
                    'type' => $link->connection_type,
                    'label' => $link->label ?: str_replace('_', ' ', $link->connection_type),
                    'source_port' => $link->source_port,
                    'target_port' => $link->target_port,
                    'fiber_core' => $link->fiber_core,
                    'fiber_type' => $link->fiber_type,
                    'fiber_core_color' => $link->fiber_core_color,
                    'fiber_core_color_hex' => $link->fiber_core_color_hex,
                ];
            })->values();

        $fiberCableEdges = DB::table('fiber_cables')->where('status', 'verified')
            ->whereNotNull('start_latitude')->whereNotNull('start_longitude')
            ->whereNotNull('end_latitude')->whereNotNull('end_longitude')
            ->whereBetween('start_latitude', [-90, 90])->whereBetween('start_longitude', [-180, 180])
            ->whereBetween('end_latitude', [-90, 90])->whereBetween('end_longitude', [-180, 180])
            ->get()->map(fn ($cable) => [
                'fromKey' => 'fiber-cable:'.$cable->id.':start',
                'toKey' => 'fiber-cable:'.$cable->id.':end',
                'from' => [(float) $cable->start_latitude, (float) $cable->start_longitude],
                'to' => [(float) $cable->end_latitude, (float) $cable->end_longitude],
                'type' => 'fiber_core',
                'label' => 'Cable '.$cable->cable_id.' · '.$cable->route_name.' · '.$cable->core_count.'C',
                'fiber_core_count' => (int) $cable->core_count, 'fiber_color_standard' => $cable->color_standard,
            ]);
        $fiberSplitterPortEdges = DB::table('fiber_splitter_ports as p')
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
            ->get(['p.output_port', 'p.output_core', 'c.cable_id', 'c.color_standard',
                's.id as source_id', 's.splitter_id as source_name', 's.latitude as source_lat', 's.longitude as source_lng',
                'n.id as target_id', 'n.splitter_id as target_name', 'n.latitude as target_lat', 'n.longitude as target_lng', 'n.input_core', 'tc.color_standard as target_color_standard'])
            ->map(function ($row) {
                $palette = [
                    ['Blue','#0070C0'], ['Orange','#ED7D31'], ['Green','#70AD47'], ['Brown','#A56A43'],
                    ['Slate','#7F7F7F'], ['White','#F2F2F2'], ['Red','#C00000'], ['Black','#262626'],
                    ['Yellow','#FFC000'], ['Violet','#7030A0'], ['Rose','#E6B8C7'], ['Aqua','#00B0F0'],
                ];
                $core = (int) $row->output_core;
                [$colour,$hex] = strtoupper((string) $row->color_standard) === 'TIA-598'
                    ? $palette[($core - 1) % 12] : ['Manufacturer chart', null];
                $targetCore = (int) $row->input_core;
                [$targetColour,$targetHex] = strtoupper((string) $row->target_color_standard) === 'TIA-598'
                    ? $palette[($targetCore - 1) % 12] : ['Manufacturer chart', null];
                return [
                    'fromKey' => 'fiber-splitter-'.$row->source_id, 'toKey' => 'fiber-splitter-'.$row->target_id,
                    'from' => [(float) $row->source_lat, (float) $row->source_lng],
                    'to' => [(float) $row->target_lat, (float) $row->target_lng],
                    'type' => 'splitter', 'label' => $row->source_name.' OUT-'.$row->output_port.' → '.$row->target_name.' · '.$row->cable_id.' C'.$core.' '.$colour.' → C'.$targetCore.' '.$targetColour,
                    'fiber_core' => $core, 'fiber_core_color' => $colour, 'fiber_core_color_hex' => $hex,
                    'source_core' => $core, 'source_core_color' => $colour, 'source_core_hex' => $hex,
                    'target_core' => $targetCore, 'target_core_color' => $targetColour, 'target_core_hex' => $targetHex,
                ];
            });

        $edges = $edges->concat($fiberCableEdges)->concat($fiberSplitterPortEdges)->values();

        $features = NetworkMapFeature::query()
            ->where('map_id', $map->id)
            ->where('status', 'published')
            ->orderBy('id')
            ->get(['id', 'feature_type', 'name', 'geometry', 'status'])
            ->map(fn ($feature) => [
                'id' => $feature->id,
                'feature_type' => $feature->feature_type,
                'name' => $feature->name,
                'geometry' => $feature->geometry,
                'status' => $feature->status,
            ])->values();

        $center = $nodePayload->isNotEmpty()
            ? [$nodePayload->avg('lng'), $nodePayload->avg('lat')]
            : [90.4125, 23.8103];

        return view('network-map-public', compact('map', 'nodePayload', 'edges', 'features', 'center'));
    }
}
