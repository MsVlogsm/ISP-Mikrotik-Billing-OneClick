<x-app-layout>
<div class="container-fluid px-1">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h3 class="mb-1"><i class="bi bi-gear me-2"></i>System Settings</h3><div class="text-muted">Central control hub for application, site, router and messaging configuration.</div></div>
        <span class="badge bg-success-subtle text-success">Online</span>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">Environment</small><div class="fs-4 fw-bold mt-2">{{ $environment }}</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">Debug</small><div class="fs-4 fw-bold mt-2">{{ $debug }}</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">Application</small><div class="fs-4 fw-bold mt-2">{{ $siteName }}</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">Backup Files</small><div class="fs-4 fw-bold mt-2">{{ $backups }}</div></div></div></div>
    </div>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><h5 class="fw-bold">Company Settings</h5><p class="text-muted small">Open the central hub for company identity, service, billing, email and payment configuration.</p><a class="btn btn-primary btn-sm" href="{{ route('company-settings') }}">Open Company Settings</a></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><h5 class="fw-bold">MikroTik Setup</h5><p class="text-muted small">Router synchronization and network configuration.</p><a class="btn btn-outline-primary btn-sm" href="{{ route('mikrotik-sync') }}">Open MikroTik Setup</a></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><h5 class="fw-bold">MikroTik Backup</h5><p class="text-muted small">Existing router backup management.</p><a class="btn btn-outline-secondary btn-sm" href="{{ route('mikrotik-server-backup') }}">Open Backup</a></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><h5 class="fw-bold">MikroTik Logs</h5><p class="text-muted small">Router log inspection and diagnostics.</p><a class="btn btn-outline-secondary btn-sm" href="{{ route('mikrotik-log-viewer') }}">Open Logs</a></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><h5 class="fw-bold">Application Notifications</h5><p class="text-muted small">Review system notifications and alerts.</p><a class="btn btn-outline-secondary btn-sm" href="{{ route('notifications') }}">Open Notifications</a></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body"><h5 class="fw-bold">System Status</h5><p class="text-muted small">Current production runtime: {{ strtoupper($environment) }}.</p><span class="badge bg-success">Operational</span></div></div></div>
    </div>
</div>
</x-app-layout>
