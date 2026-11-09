<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $map->name }} · Network Map</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        html,body{margin:0;min-height:100%;background:#0b1728;color:#e8f1fc;font-family:Inter,system-ui,sans-serif}
        .public-map-shell{padding:18px;max-width:1800px;margin:auto}
        .public-map-header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px}
        .public-map-header h1{font-size:clamp(20px,3vw,30px);margin:0;font-weight:750}
        .public-map-header p{margin:5px 0 0;color:#9db0c8;font-size:13px}
        .public-map-readonly{border:1px solid #31516e;background:#132b43;color:#b9e6f2;border-radius:999px;padding:7px 12px;font-size:12px;white-space:nowrap}
        #network-map-public{height:calc(100vh - 125px);min-height:480px;border:1px solid #294158;border-radius:16px;overflow:hidden;background:#10243a}
        .public-map-footer{display:flex;justify-content:space-between;gap:12px;padding:10px 4px;color:#93a9c0;font-size:11px}
        @media(max-width:640px){.public-map-shell{padding:10px}.public-map-header{align-items:flex-start;flex-direction:column}.public-map-readonly{align-self:flex-start}#network-map-public{height:calc(100vh - 150px);min-height:430px}}
    </style>
</head>
<body>
<main class="public-map-shell">
    <header class="public-map-header">
        <div><h1>{{ $map->name }}</h1><p>Verified network infrastructure and approved physical connections</p></div>
        <span class="public-map-readonly">Read-only · Shared map</span>
    </header>
    <div id="network-map-public" aria-label="Read-only network map"></div>
    <footer class="public-map-footer">
        <span>Only published, field-verified topology records are shown. Customer account details are not shared.</span>
        <span>© {{ now()->year }} X-Link LTD</span>
    </footer>
</main>
<script>
window.XLINK_PUBLIC_NETWORK_MAP = {
    nodes: @json($nodePayload),
    edges: @json($edges),
    features: @json($features),
    center: @json($center),
};
</script>
@vite('resources/js/network-map-public.js')
</body>
</html>
