@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<link rel="stylesheet" href="{{ asset('xlink-network-monitoring/network-monitoring-polish.css') }}">
<link rel="stylesheet" href="{{ asset('xlink-network-monitoring/network-map-polish.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<style>#network-map{min-height:650px;height:650px;border-radius:12px;overflow:hidden}.network-map-leaflet-status{min-height:650px;display:flex;align-items:center;justify-content:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px}.xlink-map-marker{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3);color:#fff;font-size:12px;font-weight:700}.xlink-map-marker.router{background:#2563eb}.xlink-map-marker.olt{background:#7c3aed}.xlink-map-marker.onu{background:#059669}.xlink-map-marker.wifi-router{background:#ea580c}.xlink-map-marker.access-point{background:#0891b2}.xlink-map-marker.switch{background:#475569}.xlink-map-marker.customer{background:#dc2626}</style>
@endpush
<div class="container-fluid py-3 network-map-page">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h3 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>Network Map</h3><small class="text-muted">Geographic view of routers, OLT/ONU devices, access points and mapped customers</small></div><div class="d-flex align-items-center gap-2"><span class="badge bg-success">{{ $routers->where('action','connected')->count() }} routers online</span><a class="btn btn-sm btn-outline-primary" href="{{ route('network-topology.live') }}"><i class="bi bi-diagram-3 me-1"></i>Live Topology</a></div></div>
<div class="row g-3 mb-3">
<div class="col-md-3"><div class="card map-stat-card"><div class="card-body"><small>Active Customers</small><h4>{{ number_format($customers) }}</h4><span class="text-muted small">Billing accounts</span></div></div></div>
<div class="col-md-3"><div class="card map-stat-card"><div class="card-body"><small>Mapped Nodes</small><h4>{{ number_format($nodes->count()) }}</h4><span class="text-muted small">With valid coordinates</span></div></div></div>
<div class="col-md-6"><div class="card map-stat-card"><div class="card-body"><div class="map-device-legend"><span><i class="map-legend-dot router"></i>MikroTik</span><span><i class="map-legend-dot olt"></i>OLT</span><span><i class="map-legend-dot onu"></i>ONU</span><span><i class="map-legend-dot wifi-router"></i>WiFi</span><span><i class="map-legend-dot access-point"></i>AP</span><span><i class="map-legend-dot customer"></i>Customer</span></div></div></div></div>
</div>
<div class="card"><div class="card-body">
<div class="row g-2 mb-3">
<div class="col-lg-3 col-md-6"><label class="form-label">Device / Network Type</label><select id="map-type-filter" class="form-select"><option value="">All Devices</option><option value="router">MikroTik Router</option><option value="olt">OLT</option><option value="onu">ONU</option><option value="wifi-router">WiFi Router</option><option value="access-point">Access Point</option><option value="switch">Switch</option><option value="customer">Customer</option></select></div>
<div class="col-lg-3 col-md-6"><label class="form-label">Router</label><select id="map-router-filter" class="form-select"><option value="">All Routers</option>@foreach($routers as $r)<option value="{{ $r->router_name }}">{{ $r->router_name }}</option>@endforeach</select></div>
<div class="col-lg-2 col-md-6"><label class="form-label">Status</label><select id="map-status-filter" class="form-select"><option value="">All Status</option><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Unknown</option></select></div>
<div class="col-lg-4 col-md-6"><label class="form-label">Search</label><div class="input-group"><input id="map-customer-search" class="form-control" placeholder="Name, IP, ID or location"><button id="map-fit" class="btn btn-outline-secondary" type="button" title="Fit visible nodes"><i class="bi bi-bounding-box"></i></button><button id="map-reset" class="btn btn-outline-secondary" type="button" title="Reset filters"><i class="bi bi-arrow-counterclockwise"></i></button></div></div>
</div>
<div id="network-map" class="network-map-canvas" wire:ignore aria-label="Interactive Leaflet network map"></div>
<div class="network-map-footer"><span><i class="bi bi-info-circle me-1"></i>Markers are based on stored coordinates. Connections appear only when both endpoints have coordinates.</span><span id="map-result-count"></span></div>
</div></div>
</div>
@push('scripts')
<script>
(() => {
    const nodes = @json($nodes);
    const edges = @json($mapEdges);

    const meta = {
        router: ["MikroTik Router", "router"],
        olt: ["OLT", "olt"],
        onu: ["ONU", "onu"],
        "wifi-router": ["WiFi Router", "wifi-router"],
        "access-point": ["Access Point", "access-point"],
        switch: ["Switch", "switch"],
        customer: ["Customer", "customer"]
    };

    const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    }[char]));

    const statusIcon = (kind, status) => {
        const cls = kind || "device";
        const letter = (meta[kind]?.[0] || "D").charAt(0);

        return L.divIcon({
            className: "",
            html: '<div class="xlink-map-marker ' + cls + '" title="' +
                esc(status || "unknown") + '">' + letter + "</div>",
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -16]
        });
    };

    window.initNetworkMap = () => {
        const el = document.getElementById("network-map");

        if (!el || el._leafletMap) {
            return;
        }

        if (!window.L) {
            el.innerHTML =
                '<div class="network-map-leaflet-status"><div class="text-center p-4">' +
                "<h5>Network Map is initializing…</h5>" +
                '<p class="text-muted mb-0">Leaflet asset is still loading.</p>' +
                "</div></div>";
            return;
        }

        try {
            el.innerHTML = "";

            const map = L.map(el, {
                zoomControl: true,
                preferCanvas: true
            }).setView([23.8103, 90.4125], 11);

            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(map);

            el._leafletMap = map;

            let markers = [];
            let lines = [];

            const clear = () => {
                markers.forEach((marker) => marker.remove());
                lines.forEach((line) => line.remove());
                markers = [];
                lines = [];
            };

            const fit = (points) => {
                if (!points.length) {
                    map.setView([23.8103, 90.4125], 11);
                    return;
                }

                if (points.length === 1) {
                    map.setView(points[0], 15);
                    return;
                }

                map.fitBounds(L.latLngBounds(points), {
                    padding: [40, 40]
                });
            };

            const render = () => {
                clear();

                const typeFilter =
                    document.getElementById("map-type-filter")?.value || "";
                const routerFilter =
                    document.getElementById("map-router-filter")?.value || "";
                const statusFilter =
                    document.getElementById("map-status-filter")?.value || "";
                const query =
                    (document.getElementById("map-customer-search")?.value || "")
                        .toLowerCase()
                        .trim();

                const filtered = nodes.filter((node) => {
                    const matchesType =
                        !typeFilter || node.kind === typeFilter;
                    const matchesRouter =
                        !routerFilter || node.router === routerFilter;
                    const matchesStatus =
                        !statusFilter || node.status === statusFilter;
                    const matchesQuery =
                        !query ||
                        [node.id, node.label, node.customer_uid, node.ip, node.location, node.kind]
                            .some((value) =>
                                String(value ?? "")
                                    .toLowerCase()
                                    .includes(query)
                            );

                    return (
                        matchesType &&
                        matchesRouter &&
                        matchesStatus &&
                        matchesQuery
                    );
                });

                const visibleKeys = new Set(
                    filtered.map((node) =>
                        (node.kind === "router" ? "router:" : "device:") +
                        node.id
                    )
                );

                edges.forEach((edge) => {
                    if (
                        edge.fromKey &&
                        edge.toKey &&
                        (!visibleKeys.has(edge.fromKey) ||
                            !visibleKeys.has(edge.toKey))
                    ) {
                        return;
                    }

                    const isLogical = edge.type === "logical_service";

                    const line = L.polyline(
                        [
                            [edge.from[0], edge.from[1]],
                            [edge.to[0], edge.to[1]]
                        ],
                        {
                            color: isLogical ? "#2563eb" : "#16a34a",
                            weight: isLogical ? 3 : 4,
                            opacity: 0.75,
                            dashArray: isLogical ? "10 8" : null
                        }
                    ).addTo(map);

                    line.bindPopup(
                        "<strong>" +
                        esc(edge.label) +
                        "</strong><div>" +
                        esc(edge.type) +
                        "</div>"
                    );

                    lines.push(line);
                });

                const points = [];

                filtered.forEach((node) => {
                    const deviceMeta =
                        meta[node.kind] || ["Device", "device"];

                    const marker = L.marker(
                        [node.lat, node.lng],
                        {
                            icon: statusIcon(node.kind, node.status),
                            title: node.label || deviceMeta[0]
                        }
                    ).addTo(map);

                    marker.bindPopup(
                        '<div style="min-width:190px">' +
                        "<strong>" + esc(deviceMeta[0]) + "</strong>" +
                        "<div><strong>" + esc(node.label || node.id) + "</strong></div>" +
                        (node.customer_uid ? "<small>Customer ID: " + esc(node.customer_uid) + "</small><br>" : "") +
                        "<small>IP: " + esc(node.ip || "—") + "</small><br>" +
                        "<small>Status: " +
                        esc(node.status || "unknown") +
                        "</small><br>" +
                        "<small>" +
                        esc(node.location || "") +
                        "</small>" +
                        (node.kind === "customer" && node.customer_url
                            ? '<div class="mt-2"><a class="btn btn-sm btn-primary" href="' + esc(node.customer_url) + '">View Customer</a></div>'
                            : "") +
                        "</div>"
                    );

                    markers.push(marker);
                    points.push([node.lat, node.lng]);
                });

                const resultCount =
                    document.getElementById("map-result-count");

                if (resultCount) {
                    resultCount.textContent =
                        filtered.length +
                        " node" +
                        (filtered.length === 1 ? "" : "s") +
                        " shown";
                }

                fit(points);
                setTimeout(() => map.invalidateSize(), 50);
            };

            ["map-type-filter", "map-router-filter", "map-status-filter"]
                .forEach((id) => {
                    document.getElementById(id)?.addEventListener(
                        "change",
                        render
                    );
                });

            document.getElementById("map-customer-search")
                ?.addEventListener("input", render);

            document.getElementById("map-fit")
                ?.addEventListener("click", render);

            document.getElementById("map-reset")
                ?.addEventListener("click", () => {
                    ["map-type-filter", "map-router-filter", "map-status-filter"]
                        .forEach((id) => {
                            const element = document.getElementById(id);
                            if (element) {
                                element.value = "";
                            }
                        });

                    const search =
                        document.getElementById("map-customer-search");

                    if (search) {
                        search.value = "";
                    }

                    render();
                });

            render();
        } catch (error) {
            console.error("[X-Link Network Map] Leaflet:", error);

            el.innerHTML =
                '<div class="network-map-leaflet-status"><div class="text-center p-4">' +
                '<i class="bi bi-exclamation-triangle fs-1 text-warning"></i>' +
                '<h5 class="mt-3">Network Map could not load</h5>' +
                '<p class="text-muted mb-0">' +
                esc(error?.message || "Leaflet map error") +
                "</p></div></div>";
        }
    };

    const boot = () => {
        setTimeout(() => window.initNetworkMap?.(), 100);
    };

    document.addEventListener("livewire:navigated", boot);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
</script>
@endpush
