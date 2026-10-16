<x-app-layout>
    <x-slot name="header">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div><div class="text-uppercase small text-muted fw-bold">X-Link LTD</div><h2 class="h4 mb-0">{{ __('MAC Reseller Dashboard') }}</h2></div>
            <a href="{{ route('mac-reseller.customers.create') }}" class="btn btn-primary rounded-3"><i class="bi bi-person-plus me-1"></i>{{ __('Add Client') }}</a>
        </div>
    </x-slot>
    <div class="container-fluid py-3">
        <div class="mb-3"><div class="fw-semibold">{{ $reseller->company ?: auth()->user()->name }}</div><div class="text-muted small">{{ __('Independent client management panel. Your account can only access its own clients.') }}</div></div>
        <div class="row g-3 mb-4">
            @foreach ([['Total Clients', $totalCustomers, 'bi-people-fill', 'primary'], ['Active', $activeCustomers, 'bi-check-circle-fill', 'success'], ['Disabled', $disabledCustomers, 'bi-slash-circle-fill', 'danger'], ['Pending', $pendingCustomers, 'bi-hourglass-split', 'warning']] as [$label, $count, $icon, $tone])
                <div class="col-6 col-xl-3"><div class="card h-100 border-0 shadow-sm rounded-4"><div class="card-body d-flex align-items-center justify-content-between"><div><div class="small text-muted">{{ __($label) }}</div><div class="fs-3 fw-bold">{{ number_format($count) }}</div></div><span class="rounded-circle bg-{{ $tone }} bg-opacity-10 text-{{ $tone }} p-3"><i class="bi {{ $icon }} fs-4"></i></span></div></div></div>
            @endforeach
        </div>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden"><div class="card-header bg-white d-flex align-items-center justify-content-between py-3"><h3 class="h6 mb-0">{{ __('Recently Added Clients') }}</h3><a href="{{ route('mac-reseller.customers.index') }}" class="btn btn-sm btn-outline-primary">{{ __('View All') }}</a></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>{{ __('Client ID') }}</th><th>{{ __('Name') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Status') }}</th><th>{{ __('Added') }}</th></tr></thead><tbody>@forelse ($recentCustomers as $client)<tr><td class="fw-semibold">{{ $client->customer_unique_id }}</td><td>{{ $client->customer_name }}</td><td>{{ $client->mobile ?: '—' }}</td><td><span class="badge text-bg-{{ $client->status === 'active' ? 'success' : ($client->status === 'disable' ? 'danger' : 'warning') }}">{{ ucfirst($client->status ?: 'pending') }}</span></td><td>{{ optional($client->created_at)->format('d M Y') }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-5">{{ __('No clients have been added yet.') }}</td></tr>@endforelse</tbody></table></div></div>
    </div>
</x-app-layout>
