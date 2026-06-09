<?php
return [
    // Public URL where this proxy is hosted, with trailing slash.
    // The shim JS uses this to load Leaflet assets.
    // When hosted as the root of its own virtual server this is just the origin.
    'proxyBaseUrl' => 'http://maps.webosarchive.org/',

    // Nominatim geocoding service. The public instance is free for low-volume use;
    // must include a valid User-Agent and stay under 1 req/sec.
    // Self-host: https://nominatim.org/release-docs/latest/admin/Installation/
    'nominatimUrl' => 'https://nominatim.openstreetmap.org',

    // Sent to Nominatim as the HTTP User-Agent. Policy requires identifying your app.
    'nominatimUserAgent' => 'webOS-Maps-Proxy/1.0 (maps.webosarchive.org)',

    // OSRM routing service. The public demo instance has no uptime guarantee.
    // Self-host: https://github.com/Project-OSRM/osrm-backend
    'osrmUrl' => 'http://router.project-osrm.org',

    // OSM tile URL template for road maps. Use {s} for subdomain, {z}/{x}/{y} for coords.
    'osmTileUrl' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    'osmTileSubdomains' => 'abc',
    'osmAttribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',

    // Aerial/satellite tile URL. Esri World Imagery is free for non-commercial use.
    // No {s} subdomain for Esri. Note: {y} and {x} order is reversed from OSM.
    'aerialTileUrl' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    'aerialAttribution' => 'Tiles &copy; Esri',
];
