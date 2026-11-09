import { Map as MapLibreMap, NavigationControl, FullscreenControl, ScaleControl, Popup, LngLatBounds } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';

const data = window.XLINK_PUBLIC_NETWORK_MAP || {};
const nodes = Array.isArray(data.nodes) ? data.nodes : [];
const edges = Array.isArray(data.edges) ? data.edges : [];
const drawnFeatures = Array.isArray(data.features) ? data.features : [];
const safe = value => String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;',
}[c]));
const pointFeatures = nodes.map(node => ({
    type:'Feature',
    properties:{
        kind:node.kind,label:node.label,location:node.location || '',
        ports:node.port_reference || '',ratio:node.splitter_ratio || '',
        cores:node.fiber_core_count || '',notes:node.notes || '',
    },
    geometry:{type:'Point',coordinates:[Number(node.lng),Number(node.lat)]},
}));
const lineFeatures = edges.map(edge => ({
    type:'Feature',
    properties:{
        type:edge.type,label:edge.label,source:edge.fromKey,target:edge.toKey,
        source_port:edge.source_port || '',target_port:edge.target_port || '',
        core:edge.fiber_core || '',core_color:edge.fiber_core_color || '',
        core_hex:edge.fiber_core_color_hex || '',
    },
    geometry:{type:'LineString',coordinates:[
        [Number(edge.from[1]),Number(edge.from[0])],
        [Number(edge.to[1]),Number(edge.to[0])],
    ]},
}));

const map = new MapLibreMap({
    container:'network-map-public',
    style:'https://tiles.openfreemap.org/styles/liberty',
    center:data.center || [90.4125,23.8103],
    zoom:11,minZoom:3,maxZoom:20,
});
map.addControl(new NavigationControl({visualizePitch:true}),'top-right');
map.addControl(new FullscreenControl(),'top-right');
map.addControl(new ScaleControl({unit:'metric'}),'bottom-left');

map.on('load',()=>{
    map.addSource('public-nodes',{type:'geojson',data:{type:'FeatureCollection',features:pointFeatures}});
    map.addSource('public-edges',{type:'geojson',data:{type:'FeatureCollection',features:lineFeatures}});
    const publicFeatureRows = drawnFeatures.map(feature => ({
        type:'Feature', id:feature.id,
        properties:{mapFeatureId:feature.id,feature_type:feature.feature_type,name:feature.name,status:feature.status},
        geometry:feature.geometry,
    }));
    map.addSource('public-map-features',{type:'geojson',data:{type:'FeatureCollection',features:publicFeatureRows}});
    map.addLayer({
        id:'public-edges-line',type:'line',source:'public-edges',
        paint:{'line-color':['coalesce',['get','core_hex'],'#1d9a8a'],'line-width':3,'line-opacity':.85},
    });
    map.addLayer({
        id:'public-map-features-fill',type:'fill',source:'public-map-features',filter:['==',['geometry-type'],'Polygon'],
        paint:{'fill-color':'#d97706','fill-opacity':.12},
    });
    map.addLayer({
        id:'public-map-features-line',type:'line',source:'public-map-features',
        filter:['any',['==',['geometry-type'],'LineString'],['==',['geometry-type'],'Polygon']],
        paint:{'line-color':['match',['get','feature_type'],'fiber_route','#16a34a','cable_route','#2563eb','#d97706'],'line-width':3.5,'line-dasharray':[2,1.5]},
    });
    map.addLayer({
        id:'public-map-features-points',type:'circle',source:'public-map-features',filter:['==',['geometry-type'],'Point'],
        paint:{'circle-radius':7,'circle-color':'#d97706','circle-stroke-color':'#fff','circle-stroke-width':2},
    });
    map.addLayer({
        id:'public-nodes-circle',type:'circle',source:'public-nodes',
        paint:{'circle-radius':7,'circle-color':['match',['get','kind'],'olt','#7c3aed','splitter','#d97706','onu','#0891b2','router','#2563eb','#64748b'],'circle-stroke-width':2,'circle-stroke-color':'#fff'},
    });
    map.addLayer({
        id:'public-nodes-label',type:'symbol',source:'public-nodes',minzoom:12,
        layout:{'text-field':['get','label'],'text-size':11,'text-offset':[0,1.2],'text-anchor':'top'},
        paint:{'text-color':'#24364b','text-halo-color':'#fff','text-halo-width':1.5},
    });
    const bounds=new LngLatBounds();
    pointFeatures.forEach(feature=>bounds.extend(feature.geometry.coordinates));
    if(!bounds.isEmpty()) map.fitBounds(bounds,{padding:70,maxZoom:15,duration:0});

    map.on('click','public-nodes-circle',event=>{
        const p=event.features?.[0]?.properties || {};
        const html='<section style="min-width:200px;color:#172b43"><small>'+safe(p.kind)+'</small><h3 style="font-size:16px;margin:5px 0">'+safe(p.label)+'</h3>'
            +(p.location?'<p>'+safe(p.location)+'</p>':'')
            +(p.ports?'<p>Ports: '+safe(p.ports)+'</p>':'')
            +(p.ratio?'<p>Splitter ratio: 1:'+safe(p.ratio)+'</p>':'')
            +(p.cores?'<p>Fiber cores: '+safe(p.cores)+'</p>':'')
            +(p.notes?'<p>'+safe(p.notes)+'</p>':'')+'</section>';
        new Popup({maxWidth:'320px'}).setLngLat(event.lngLat).setHTML(html).addTo(map);
    });
    map.on('click','public-map-features-line',event=>{
        const p=event.features?.[0]?.properties || {};
        const html='<section style="min-width:200px;color:#172b43"><small>Verified map feature</small><h3 style="font-size:16px;margin:5px 0">'+safe(p.name)+'</h3><p>'+safe(p.feature_type)+'</p></section>';
        new Popup({maxWidth:'320px'}).setLngLat(event.lngLat).setHTML(html).addTo(map);
    });
    map.on('click','public-map-features-points',event=>{
        const p=event.features?.[0]?.properties || {};
        const html='<section style="min-width:200px;color:#172b43"><small>Verified map feature</small><h3 style="font-size:16px;margin:5px 0">'+safe(p.name)+'</h3><p>'+safe(p.feature_type)+'</p></section>';
        new Popup({maxWidth:'320px'}).setLngLat(event.lngLat).setHTML(html).addTo(map);
    });
    map.on('click','public-edges-line',event=>{
        const p=event.features?.[0]?.properties || {};
        const html='<section style="min-width:210px;color:#172b43"><small>Verified connection</small><h3 style="font-size:15px;margin:5px 0">'+safe(p.label)+'</h3><p>'+safe(p.source)+' → '+safe(p.target)+'</p>'
            +(p.source_port?'<p>Source port: '+safe(p.source_port)+'</p>':'')
            +(p.target_port?'<p>Target port: '+safe(p.target_port)+'</p>':'')
            +(p.core?'<p>Core: '+safe(p.core)+' · '+safe(p.core_color)+'</p>':'')+'</section>';
        new Popup({maxWidth:'320px'}).setLngLat(event.lngLat).setHTML(html).addTo(map);
    });
    ['public-nodes-circle','public-edges-line','public-map-features-line','public-map-features-points','public-map-features-fill'].forEach(layer=>{
        map.on('mouseenter',layer,()=>map.getCanvas().style.cursor='pointer');
        map.on('mouseleave',layer,()=>map.getCanvas().style.cursor='');
    });
});
