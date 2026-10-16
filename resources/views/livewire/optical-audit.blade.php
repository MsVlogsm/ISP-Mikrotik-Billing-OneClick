<div class="container-fluid px-1 pb-4">
<style>
.oa-card{background:#fff;border:1px solid #e6ebf2;border-radius:14px;box-shadow:0 5px 20px rgba(31,41,55,.05)}.oa-kpi{padding:18px}.oa-kpi .num{font-size:28px;font-weight:800}.oa-muted{font-size:.84rem;color:#64748b}.oa-table th,.oa-table td{padding:10px 12px;border-bottom:1px solid #edf1f5;vertical-align:middle;font-size:.84rem}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><span class="text-uppercase small text-muted fw-semibold">Network Centre</span><h3 class="mb-0">Virtual OTDR / Optical Audit</h3><small class="oa-muted">Read-only snapshots of stored OLT/ONU optical readings and customer mapping.</small></div><div class="d-flex gap-2"><a href="{{ route('device-manager') }}" class="btn btn-outline-secondary"><i class="bi bi-hdd-network me-1"></i>Device Manager</a><a href="{{ route('network-topology.live') }}" class="btn btn-outline-primary"><i class="bi bi-diagram-3 me-1"></i>Live Topology</a></div></div>
<div class="alert alert-warning py-2"><strong>Measurement limitation:</strong> this module saves available OLT/ONU RX/TX readings. It does not connect to physical OTDR hardware and does not generate a physical fiber trace or estimated distance/loss.</div>
@if($message)<div class="alert alert-success py-2">{{ $message }}</div>@endif
<div class="row g-3 mb-3">
 <div class="col-6 col-xl-3"><div class="oa-card oa-kpi"><div class="oa-muted">ONU mappings</div><div class="num">{{ number_format($total) }}</div></div></div>
 <div class="col-6 col-xl-3"><div class="oa-card oa-kpi"><div class="oa-muted">With RX reading</div><div class="num">{{ number_format($withRx) }}</div></div></div>
 <div class="col-6 col-xl-3"><div class="oa-card oa-kpi"><div class="oa-muted">Online ONU</div><div class="num text-success">{{ number_format($online) }}</div></div></div>
 <div class="col-6 col-xl-3"><div class="oa-card oa-kpi"><div class="oa-muted">Stale / missing last-seen</div><div class="num {{ $stale ? 'text-warning' : 'text-success' }}">{{ number_format($stale) }}</div><small class="oa-muted">Older than 24 hours</small></div></div>
</div>
<div class="oa-card mb-3"><div class="p-3 border-bottom"><strong>Current Optical Readings</strong><div class="oa-muted">Filters apply to the table and the snapshot capture.</div></div>
<div class="p-3"><div class="row g-2 align-items-end">
 <div class="col-lg-5"><label class="form-label">Search ONU / customer / serial / MAC / IP</label><input wire:model.live.debounce.300ms="search" class="form-control" placeholder="Search..."></div>
 <div class="col-lg-3"><label class="form-label">OLT</label><select wire:model.live="oltFilter" class="form-select"><option value="">All OLTs</option>@foreach($olts as $olt)<option value="{{ $olt->id }}">{{ $olt->name }} · {{ $olt->ip_address }}</option>@endforeach</select></div>
 <div class="col-lg-4"><label class="form-label">Snapshot name</label><input wire:model="auditName" class="form-control" placeholder="e.g. POP-1 morning optical snapshot">@error('auditName')<small class="text-danger">{{ $message }}</small>@enderror</div>
 <div class="col-12"><label class="form-label">Notes (optional)</label><input wire:model="auditNotes" class="form-control" placeholder="Maintenance window, POP, observation..."></div>
 <div class="col-12"><button wire:click="saveSnapshot" wire:loading.attr="disabled" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save filtered snapshot</button></div>
</div></div>
<div class="table-responsive"><table class="table oa-table mb-0"><thead><tr><th>OLT</th><th>Customer</th><th>PON / ONU</th><th>Serial / MAC</th><th>Status</th><th>RX / TX</th><th>IP</th><th>Last seen</th></tr></thead><tbody>
@forelse($readings as $m)<tr>
<td><a href="{{ route('device-manager.detail',['kind'=>'olt','device'=>$m->olt_device_id]) }}">{{ $m->olt?->name ?: 'OLT #'.$m->olt_device_id }}</a></td>
<td>@if($m->customer?->customer_unique_id)<a href="{{ route('customer.details',encrypt($m->customer->customer_unique_id)) }}">{{ $m->customer->customer_name ?: $m->customer->customer_unique_id }}</a>@else{{ 'Unmapped' }}@endif<div class="oa-muted">{{ $m->pppUser?->username ?: '—' }}</div></td>
<td>{{ $m->pon_port ?: '—' }}<div class="oa-muted">ONU {{ $m->onu_id ?: '—' }}</div></td><td>{{ $m->onu_serial ?: '—' }}<div class="oa-muted">{{ $m->onu_mac ?: '—' }}</div></td>
<td><span class="badge {{ $m->status==='online'?'bg-success':($m->status==='offline'?'bg-danger':'bg-secondary') }}">{{ ucfirst($m->status) }}</span></td>
<td>{{ $m->rx_power !== null ? $m->rx_power.' dBm' : '—' }} / {{ $m->tx_power !== null ? $m->tx_power.' dBm' : '—' }}</td><td>{{ $m->onu_ip ?: '—' }}</td><td>{{ $m->last_seen_at?->diffForHumans() ?: '—' }}</td>
</tr>@empty<tr><td colspan="8" class="text-center text-muted py-5">No ONU optical readings match these filters.</td></tr>@endforelse
</tbody></table></div><div class="p-3">{{ $readings->links() }}</div></div>
<div class="oa-card mb-3"><div class="p-3 border-bottom"><strong>Saved Optical Snapshots</strong><span class="oa-muted float-end">{{ $audits->count() }} recent snapshots</span></div><div class="table-responsive"><table class="table oa-table mb-0"><thead><tr><th>Snapshot</th><th>OLT scope</th><th>Readings</th><th>Created by</th><th>Created</th><th></th></tr></thead><tbody>
@forelse($audits as $audit)<tr><td><strong>{{ $audit->name }}</strong><div class="oa-muted">{{ $audit->notes ?: 'No notes' }}</div></td><td>{{ $audit->olt?->name ?: 'All OLTs' }}</td><td>{{ $audit->reading_count }}</td><td>{{ $audit->createdBy?->name ?: 'System' }}</td><td>{{ $audit->created_at?->format('d M Y H:i') }}</td><td><button wire:click="viewAudit({{ $audit->id }})" class="btn btn-sm btn-outline-primary">View</button></td></tr>
@empty<tr><td colspan="6" class="text-center text-muted py-4">No saved snapshots yet.</td></tr>@endforelse
</tbody></table></div></div>
@if($selectedAudit)<div class="oa-card"><div class="p-3 border-bottom d-flex justify-content-between"><div><strong>{{ $selectedAudit->name }}</strong><div class="oa-muted">Saved snapshot · {{ $selectedAudit->reading_count }} records · {{ $selectedAudit->created_at?->format('d M Y H:i') }}</div></div><button wire:click="clearAudit" class="btn btn-sm btn-outline-secondary">Close</button></div><div class="table-responsive"><table class="table oa-table mb-0"><thead><tr><th>OLT</th><th>Customer</th><th>PON / ONU</th><th>Serial / MAC</th><th>Status</th><th>RX / TX</th><th>Last seen</th></tr></thead><tbody>
@foreach(($selectedAudit->readings ?? []) as $reading)<tr><td>{{ $reading['olt_name'] ?? '—' }}</td><td>{{ $reading['customer_name'] ?? 'Unmapped' }}</td><td>{{ $reading['pon_port'] ?? '—' }} / {{ $reading['onu_id'] ?? '—' }}</td><td>{{ $reading['onu_serial'] ?? '—' }}<div class="oa-muted">{{ $reading['onu_mac'] ?? '—' }}</div></td><td>{{ ucfirst($reading['status'] ?? 'unknown') }}</td><td>{{ isset($reading['rx_power']) ? $reading['rx_power'].' dBm' : '—' }} / {{ isset($reading['tx_power']) ? $reading['tx_power'].' dBm' : '—' }}</td><td>{{ $reading['last_seen_at'] ?? '—' }}</td></tr>@endforeach
</tbody></table></div></div>@endif
</div>
