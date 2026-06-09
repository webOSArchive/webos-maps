# webOS Maps Revival — Project Guide

## What this is

`com.palm.app.maps` is the original HP/Palm webOS Maps application (Enyo 0.10, v4.0.1). It was entirely dependent on Bing Maps APIs which are no longer available. This project revives it by:

1. Patching the app to redirect its Bing API calls to a self-hosted proxy (`bing-proxy/`)
2. The proxy impersonates Bing's endpoints using open-source alternatives (Leaflet, Nominatim, OSRM)
3. The Microsoft.Maps v7.0 JavaScript SDK is replaced by a Leaflet-based compatibility shim

The app's 34 original source files are largely untouched — only the files listed below were modified.

## Repository layout

```
com.palm.app.maps/          ← patched original app (deploy as .ipk)
com.palm.app.maps-newer/    ← HP v3.1.32 (Enyo 1.0, older era) — NOT patched, reference only
maplite-service/            ← old static-image approach — superseded, kept for reference
org.webosarchive.maplite/   ← old Mojo frontend — superseded, kept for reference
bing-proxy/                 ← PHP proxy deployed at maps.webosarchive.org
README.md                   ← deployment guide including nginx config
```

## Target platform

webOS **2.2.4 and newer**. WebKit 533/534. GPS and cellular location are dead on all devices — the app uses IP-based geolocation (ip-api.com, called directly from the device) as a fallback.

## Files modified in com.palm.app.maps

| File | What changed |
|------|-------------|
| `index.html` | SDK URL → `http://maps.webosarchive.org/mapcontrol.ashx` |
| `depends.js` | Added ParseURI.js, GoogleURL.js, Updater-Helper.js |
| `source/service/LocationSearchService.js` | Base URL → proxy |
| `source/service/PlacesSearchService.js` | Base URL → proxy |
| `source/Route.js` | Route URL → proxy |
| `source/MapsApp.js` | Location error handling, IP geolocation fallback, updater, Google Maps URL support |
| `source/util.js` | Extended `processLaunchParamsTarget` for Google Maps URLs + Yelp query stripping |

## Files added to com.palm.app.maps

| File | Purpose |
|------|---------|
| `source/ParseURI.js` | MIT URI parser (Steven Levithan), dependency of GoogleURL |
| `source/GoogleURL.js` | Parses Google Maps URLs (`maps.google.*`, sll/q/saddr/daddr params) — ported from v3.1.32 |
| `source/Updater-Helper.js` | webos-common updater helper — checks App Museum II for updates |

## Key MapsApp.js changes

**Location error handling** (`currentLocationFailure`): Error code 5 means `palm://com.palm.location/` is dead (not just disabled). The original code tried to open `LocationServicesPrompt` for unknown codes, which crashed the app. Now any non-recoverable code triggers IP geolocation instead.

**IP geolocation** (`tryIPGeolocation`): Calls `http://ip-api.com/json?fields=status,lat,lon` directly from the device. Free, no key, CORS-enabled, plain HTTP (no TLS issues on older WebKit). On success, feeds result into `currentLocationSuccess` to place the location pin normally.

**Update check**: `Helpers.Updater` component checks App Museum II for "Maps" on launch and via the App Menu. Uses `enyo.windows.addBannerMessage` (not a popup) because popups render behind the map layer.

## bing-proxy architecture

Deployed as the root of its own virtual server (`maps.webosarchive.org`). See `README.md` for the full nginx config.

| URL | PHP file | Translates to |
|-----|----------|---------------|
| `/mapcontrol.ashx` | `mapcontrol.php` | Serves Leaflet + Microsoft.Maps shim (single JS response) |
| `/REST/v1/Locations/*` | `api/locations.php` | Nominatim geocoding (forward + reverse) |
| `/REST/v1/Routes/*` | `api/routes.php` | Nominatim geocoding + OSRM routing |
| `/json.aspx` | `api/places.php` | Nominatim POI search |

### Important proxy bugs fixed

- `wp.0`/`wp.1` route waypoints: PHP converts `.` to `_` in `$_GET`. `routes.php` parses `$_SERVER['QUERY_STRING']` directly to preserve the dot-keyed param names.
- Location text queries come in as `?query=...` (not `?q=...`). `locations.php` checks both.

### config.php

Set `proxyBaseUrl` to your server's public URL with trailing slash. Nominatim and OSRM URLs are configurable; tile URLs can be switched to HTTP if webOS can't verify TLS certs.

## microsoft-maps-shim.js — key design decisions

**Renderer**: webOS 2.x WebKit has broken SVG namespace detection (`createElementNS` fails) AND broken Leaflet renderer detection (causes `stamp(null)` crash in `addLayer`). The shim avoids Leaflet's renderer pipeline entirely.

**Polyline**: Implemented as a plain `<canvas>` element injected into Leaflet's overlay pane. Does NOT use `L.Polyline`, `L.SVG`, or `L.canvas()`. Updates on `moveend`/`zoomend`/`viewreset`. Uses `latLngToLayerPoint` for coordinates.

**Pushpin visibility**: Markers are always added to the Leaflet map (opacity=0 for invisible). Never skipping `addTo()` — otherwise `removeLayer` crashes Leaflet on a layer it doesn't know about.

**EntityCollection.remove**: Only calls `_removeFromMap` when the entity is actually tracked in `_entities` (gated on `indexOf !== -1`). Prevents double-remove crashes when `clearAll` has already cleaned up.

**TileLayer** (traffic): No-op. No free real-time traffic source exists; the app handles this gracefully.

## What still needs work / known issues

- **Patch file generation**: The app currently has a different appid to install alongside the built-in app. Eventually, proper patch files should be generated for in-situ patching of the system app.
- **Aerial/satellite tiles**: Esri World Imagery is used but requires HTTPS. May not render on devices with old CA bundles. Can swap to HTTP tile provider in `config.php`.
- **Bookmarks/Recents**: These use webOS's built-in database service (`palm://com.palm.db/`). Should work unchanged since those services are still present on the device.
- **Contacts integration**: `Contacts.js` uses `palm://com.palm.contacts/` — likely still works but untested.
- **Route line redraws**: The canvas overlay redraws on `moveend`/`zoomend` only (not during live pan), so the line briefly lags before snapping. Acceptable on webOS hardware.

## Leaflet setup

Leaflet 1.9.x files (`leaflet.js` + `leaflet.css`) are NOT committed to this repo. Download from leafletjs.com and place in `bing-proxy/shim/`. The `.gitignore` in that directory documents this.

`mapcontrol.php` inlines both files into a single HTTP response so the device only makes one request for the entire SDK replacement.

## Proxy dependencies and usage policies

| Service | Notes |
|---------|-------|
| Nominatim (`nominatim.openstreetmap.org`) | Max 1 req/sec, must set User-Agent in `config.php` |
| OSRM (`router.project-osrm.org`) | Public demo, no uptime guarantee — override in `config.php` |
| OSM tiles (`tile.openstreetmap.org`) | Cache aggressively, identify app in User-Agent |
| ip-api.com | Called from device directly, 45 req/min/IP, free, no key needed |
| Esri World Imagery | Free non-commercial use |
| Leaflet | BSD 2-clause |
