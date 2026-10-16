<x-app-layout>
<div class="container-fluid px-1">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><div class="text-uppercase small text-muted fw-semibold">FTTH Network</div><h3 class="mb-1">Customer Network Monitoring</h3><div class="text-muted small">Subscriber, OLT/PON and optical-power view from saved telemetry. Missing measurements are never fabricated.</div></div>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary btn-sm" href="{{ route('network-inventory.olt') }}">OLT Devices</a><a class="btn btn-outline-primary btn-sm" href="{{ route('network-inventory.onu-management') }}">ONU Management</a><a class="btn btn-outline-success btn-sm" href="{{ route('network-monitoring.export',request()->query()) }}"><i class="bi bi-download me-1"></i>Export CSV</a></div>
  </div>
  <div class="row g-3 mb-3">
    @foreach([['Total ONU',$stats['total'],'bi-diagram-3','primary'],['Online',$stats['online'],'bi-wifi','success'],['Offline',$stats['offline'],'bi-wifi-off','danger'],['Critical RX',$stats['critical'],'bi-exclamation-triangle','warning'],['Unmapped',$stats['unmapped'],'bi-person-x','secondary']] as [$label,$value,$icon,$color])
      <div class="col-6 col-md"><div class="card h-100 border-0 shadow-sm"><div class="card-body py-3"><div class="d-flex justify-content-between align-items-center"><span class="text-muted small">{{ $label }}</span><i class="bi {{ $icon }} text-{{ $color }}"></i></div><div class="fs-3 fw-bold mt-1">{{ number_format($value) }}</div></div></div></div>
    @endforeach
  </div>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3"><form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-4"><label class="form-label small">Search subscriber / ONU</label><input class="form-control" name="q" value="{{ $q }}" placeholder="Customer ID, name, mobile, PPPoE, MAC, serial, IP"></div>
      <div class="col-6 col-md-2"><label class="form-label small">OLT</label><select name="olt" class="form-select"><option value="">All OLTs</option>@foreach($olts as $o)<option value="{{ $o->id }}" @selected((string)$oltId===(string)$o->id)>{{ $o->name }}</option>@endforeach</select></div>
      <div class="col-6 col-md-1"><label class="form-label small">PON</label><select name="pon" class="form-select"><option value="">All</option>@foreach($ponPorts as $port)<option value="{{ $port }}" @selected($pon===$port)>{{ $port }}</option>@endforeach</select></div>
      <div class="col-6 col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select"><option value="">All statuses</option>@foreach(['online'=>'Online','offline'=>'Offline','unknown'=>'Unknown'] as $k=>$v)<option value="{{ $k }}" @selected($status===$k)>{{ $v }}</option>@endforeach</select></div>
      <div class="col-6 col-md-2"><label class="form-label small">Optical quality</label><select name="signal" class="form-select"><option value="">All signal</option>@foreach(['great'=>'Great (≥ -20)','good'=>'Good (-24 to -20)','poor'=>'Poor (-27 to -24)','critical'=>'Critical (< -27)'] as $k=>$v)<option value="{{ $k }}" @selected($signal===$k)>{{ $v }}</option>@endforeach</select></div>
      <div class="col-6 col-md-1"><label class="form-label small">Rows</label><select name="per_page" class="form-select"><option value="20">20</option><option value="50" @selected(request('per_page')=='50')>50</option></select></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Apply Filters</button><a class="btn btn-outline-secondary" href="{{ route('network-monitoring') }}">Reset</a></div>
    </form><div class="px-3 pb-3 d-flex justify-content-end"><form method="POST" action="{{ route('network-inventory.olt.sync') }}" class="m-0">@csrf<button class="btn btn-outline-success" onclick="return confirm('Run the configured read-only OLT/ONU synchronization now?')"><i class="bi bi-arrow-repeat me-1"></i>Sync Inventory</button></form></div></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Customer</th><th>PPPoE / IP</th><th>OLT / PON</th><th>ONU Identity</th><th>Optical RX</th><th>Uptime / Last Seen</th><th>Status</th></tr></thead><tbody>
    @forelse($mappings as $m)
      @php($rx=$m->rx_power)
      @php($rxClass=$rx===null?'secondary':($rx < -27?'danger':($rx < -24?'warning':($rx < -20?'info':'success'))))
      <tr>
        <td><div class="fw-semibold">{{ $m->customer?->customer_name ?: 'Unmapped customer' }}</div><div class="small text-muted">{{ $m->customer?->customer_unique_id ?: 'No customer ID' }} · {{ $m->customer?->mobile ?: 'No mobile' }}</div></td>
        <td><div>{{ $m->pppUser?->username ?: '—' }}</div><div class="small text-muted">{{ $m->onu_ip ?: 'No ONU IP' }}</div></td>
        <td><div class="fw-semibold">{{ $m->olt?->name ?: 'Unknown OLT' }}</div><div class="small text-muted">{{ $m->pon_port ?: 'PON unknown' }}</div></td>
        <td><div class="fw-semibold">{{ $m->onu_id ?: 'ONU ID —' }}</div><div class="small text-muted">{{ $m->onu_mac ?: $m->onu_serial ?: 'No MAC/serial' }}</div></td>
        <td>@if($rx!==null)<span class="badge text-bg-{{ $rxClass }}">{{ number_format((float)$rx,2) }} dBm</span><div class="small text-muted">TX {{ $m->tx_power!==null?number_format((float)$m->tx_power,2).' dBm':'—' }}</div>@else<span class="text-muted">No reading</span>@endif</td>
        <td><div>{{ $m->last_seen_at?->diffForHumans() ?: 'Not recorded' }}</div><div class="small text-muted">{{ $m->last_seen_at?->format('d M Y, H:i') ?: 'No timestamp' }}</div></td>
        <td><span class="badge text-bg-{{ $m->status==='online'?'success':($m->status==='offline'?'danger':'secondary') }}">{{ ucfirst($m->status ?: 'unknown') }}</span></td>
      </tr>
    @empty<tr><td colspan="7" class="text-center py-5"><i class="bi bi-activity fs-2 text-muted d-block mb-2"></i><strong>No saved ONU monitoring records</strong><div class="text-muted small mt-1">Sync a reachable OLT or create verified ONU/customer mappings to populate this view.</div></td></tr>@endforelse
    </tbody></table></div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center"><small class="text-muted">{{ $mappings->total() }} records · Data source: stored ONU telemetry</small>{{ $mappings->links() }}</div>
  </div>
</div>
</x-app-layout>
