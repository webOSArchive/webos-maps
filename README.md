# webOS Maps Revival

Maps on legacy webOS have been broken since Bing Maps APIs were retired. This repo contains three codebases plus the new proxy:

- **com.palm.app.maps** — original HP/Palm maps app (Enyo 0.10 framework), patched to point at `bing-proxy`
- **bing-proxy** — server-side shim that impersonates Bing Maps endpoints; uses Leaflet + OSM + Nominatim + OSRM

## How it works

`com.palm.app.maps` is minimally patched in to redirect its hardcoded Bing URLs to `bing-proxy`. The proxy impersonates the full Bing Maps API surface:

| What the app requests | What the proxy does |
|-----------------------|---------------------|
| Bing Maps v7.0 JS SDK | Serves a `Microsoft.Maps` compatibility shim backed by Leaflet |
| Bing Locations API (geocoding) | Forwards to Nominatim, reformats as Bing JSON |
| Bing Phonebook API (POI search) | Forwards to Nominatim, reformats as Bing Phonebook JSON |
| Bing Routes API (directions) | Geocodes waypoints via Nominatim, routes via OSRM, reformats as Bing JSON |

The app's 34 source files — search UI, routing display, bookmarks, Enyo components — are untouched.

---

## bing-proxy setup

### 1. Download Leaflet

Download **Leaflet 1.9.x** from [leafletjs.com/download.html](https://leafletjs.com/download.html) and place the two files here:

```
bing-proxy/shim/leaflet.js
bing-proxy/shim/leaflet.css
```

### 2. Configure the proxy

Copy and edit the config:

```bash
cp bing-proxy/config.php bing-proxy/config.local.php   # optional; edit config.php directly
```

Open `bing-proxy/config.php` and set:

- **`proxyBaseUrl`** — the public URL where you're hosting `bing-proxy/`, with trailing slash.  
  Example: `http://maps.webosarchive.org/`
- **`nominatimUserAgent`** — identify your deployment per Nominatim's [usage policy](https://operations.osmfoundation.org/policies/tiles/).  
  Example: `webOS-Maps/1.0 (yourdomain.org)`
- **`osrmUrl`** — defaults to the public OSRM demo (`http://router.project-osrm.org`). Override if you're running a self-hosted instance.
- **`osmTileUrl`** — defaults to `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`. webOS 2.x devices require a Squid ssl-bump proxy on-device to handle HTTPS; tiles will be blank without it.

### 3. Configure your web server

#### nginx

`bing-proxy` is intended as the root of its own virtual server. This assumes the repo's `bing-proxy/` directory is deployed to `/var/www/bing-proxy/` and PHP is handled via php-fpm.

```nginx
server {
    listen 80;
    server_name maps.webosarchive.org;

    root /var/www/bing-proxy;
    index index.php;

    # Serve static shim assets (leaflet.js, leaflet.css) directly
    location /shim/ {
        try_files $uri =404;
        expires 1d;
        add_header Cache-Control "public";
    }

    # Bing Maps SDK endpoint → shim server
    location = /mapcontrol.ashx {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/mapcontrol.php;
        include fastcgi_params;
    }

    # Geocoding
    location ~ ^/REST/v1/Locations {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/locations.php;
        include fastcgi_params;
    }

    # Routing
    location ~ ^/REST/v1/Routes {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/routes.php;
        include fastcgi_params;
    }

    # POI / Phonebook search
    location = /json.aspx {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/places.php;
        include fastcgi_params;
    }

}
```

Adjust `fastcgi_pass` to match your php-fpm socket or TCP address (e.g. `127.0.0.1:9000`).

#### Apache

`.htaccess` is included in `bing-proxy/`. Enable `mod_rewrite` and ensure `AllowOverride All` is set for the directory:

```apache
<Directory /var/www/maps/bing-proxy>
    AllowOverride All
</Directory>
```

### 4. Deploy

Copy the `bing-proxy/` directory to your server at the path matching `proxyBaseUrl`. Ensure the web server user can read all files. No writable directories are needed.

### 5. Verify

Test each endpoint with curl before putting the app on a device:

```bash
BASE=http://maps.webosarchive.org

# Shim JS loads (should return JavaScript defining Microsoft.Maps)
curl -s "$BASE/mapcontrol.ashx?v=7.0&mkt=en-us" | head -5

# Geocoding
curl -s "$BASE/REST/v1/Locations/Seattle,WA?output=json" | python3 -m json.tool | head -20

# Reverse geocode
curl -s "$BASE/REST/v1/Locations/47.6062,-122.3321?output=json" | python3 -m json.tool | head -20

# POI search
curl -s "$BASE/json.aspx?Query=coffee&Latitude=47.6062&Longitude=-122.3321&Phonebook.Count=5" | python3 -m json.tool | head -20

# Routing (geocodes waypoints then calls OSRM — takes a few seconds)
curl -s "$BASE/REST/v1/Routes/Driving?wp.0=Seattle,WA&wp.1=Portland,OR&distanceUnit=km&output=json" | python3 -m json.tool | head -30

```

### 6. Install the patched app

The four patched files in `com.palm.app.maps/` point to `maps.webosarchive.org`. If you're hosting at a different hostname, update these before packaging:

- `index.html` line 15 — SDK URL
- `source/service/LocationSearchService.js` line 4 — geocoding base URL
- `source/service/PlacesSearchService.js` line 4 — POI search base URL
- `source/Route.js` line 65 — routing base URL

Package with the standard webOS SDK tools (`palm-package`) and install via `palm-install`.

---

## Dependencies and usage policies

| Service | Usage policy |
|---------|-------------|
| [OpenStreetMap tiles](https://operations.osmfoundation.org/policies/tiles/) | Free, low-volume; must cache, must set User-Agent; consider self-hosting for heavier use |
| [Nominatim](https://nominatim.org/release-docs/latest/api/Overview/) | Free; max 1 req/sec; must set descriptive User-Agent; no bulk geocoding |
| [OSRM public demo](https://project-osrm.org/) | Free demo; no uptime guarantee; self-host for production |
| [Leaflet](https://leafletjs.com/) | BSD 2-clause; include attribution |
| Esri World Imagery (aerial tiles) | Free for non-commercial use; attribution required |

---

## Background: why everything was broken

The original app and community-made Map Lite both depended entirely on Bing Maps:
- `com.palm.app.maps` loads the Microsoft.Maps v7.0 JS SDK from Bing's CDN — not just tiles, the entire rendering engine
- `maplite-service` calls Bing Locations, Bing Imagery, and Bing Routes APIs

None are available since Microsoft restructured Bing Maps in 2024.

GPS and cellular location on-device are also dead. The proxy doesn't address this directly; the app attempts IP-based geo-location with web service call, falling back to manual address entry.