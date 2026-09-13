import * as maplibregl from 'maplibre-gl';
// MapLibre 6 renders in a Web Worker it finds next to its own module file.
// Vite pre-bundles MapLibre in dev and never emits that file in a build, so the
// worker 404s silently and every map hangs on "Loading map…". Bundling the
// worker ourselves and handing MapLibre the URL fixes both.
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import installMapPicker from './map-picker';
import installRiderNav from './rider-nav';

maplibregl.setWorkerUrl(workerUrl);

/**
 * Map layer for the app, built on MapLibre GL JS against keyless OpenStreetMap
 * vector tiles. Nothing here needs an API key or a billing account.
 *
 * Two things every caller gets for free:
 *   - the correct style for the user's current light/dark theme, swapped live
 *     when they toggle it;
 *   - a raster OpenStreetMap fallback if the vector host is unreachable, so a
 *     rider mid-delivery is never left staring at a blank grey box.
 */

const settings = () => window.mapConfig ?? {};

const isDarkTheme = () =>
    document.documentElement.classList.contains('dark');

/**
 * Plain OSM raster tiles as an inline style. Inline matters: it needs no
 * second network fetch, so it still resolves when the vector style host is
 * exactly what has gone down.
 */
const rasterFallbackStyle = () => {
    const fallback = settings().rasterFallback ?? {};

    return {
        version: 8,
        sources: {
            osm: {
                type: 'raster',
                tiles: fallback.tiles ?? ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                tileSize: 256,
                maxzoom: fallback.maxZoom ?? 19,
                attribution: fallback.attribution ?? '&copy; OpenStreetMap contributors',
            },
        },
        layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
    };
};

/**
 * Kabankalan landmarks (malls, the plaza, markets, banks, churches, schools),
 * drawn from our own list rather than the base style's POI labels. Those only
 * appear from zoom 15 and cover everywhere; ours put the big places on the
 * opening view and stay inside the city.
 */
const LANDMARK_SOURCE = 'kabankalan-landmarks';

// Category → icon in the OpenFreeMap sprite.
const LANDMARK_ICONS = {
    mall: 'shop',
    market: 'grocery',
    park: 'park',
    hospital: 'hospital',
    clinic: 'doctors',
    government: 'town_hall',
    college: 'college',
    school: 'school',
    church: 'place_of_worship',
    supermarket: 'grocery',
    terminal: 'bus',
    bank: 'bank',
    pharmacy: 'pharmacy',
    food: 'restaurant',
    fuel: 'fuel',
    lodging: 'lodging',
    attraction: 'attraction',
    public: 'town_hall',
    sports: 'stadium',
    convenience: 'shop',
    shop: 'shop',
};

function addLandmarkLayer(map) {
    const url = settings().endpoints?.landmarks;
    const style = map.getStyle();

    // The raster fallback has no glyphs or sprite to draw labels with.
    if (!url || !style?.glyphs || !style?.sprite || map.getSource(LANDMARK_SOURCE)) return;

    // Otherwise the same places would be labelled twice from zoom 15.
    for (const layer of style.layers) {
        if (layer['source-layer'] === 'poi') {
            map.setLayoutProperty(layer.id, 'visibility', 'none');
        }
    }

    const dark = isDarkTheme();

    map.addSource(LANDMARK_SOURCE, { type: 'geojson', data: url });
    map.addLayer({
        id: LANDMARK_SOURCE,
        type: 'symbol',
        source: LANDMARK_SOURCE,
        // Each place carries the zoom it earns a label at: malls, the plaza and
        // the market from the opening view, a corner store at street level.
        filter: ['>=', ['zoom'], ['get', 'min_zoom']],
        layout: {
            'icon-image': [
                'match',
                ['get', 'category'],
                ...Object.entries(LANDMARK_ICONS).flat(),
                'marker',
            ],
            'text-field': ['get', 'name'],
            'text-font': ['Noto Sans Regular'],
            'text-size': ['interpolate', ['linear'], ['zoom'], 13, 11, 17, 13],
            'text-anchor': 'top',
            'text-offset': [0, 0.7],
            'text-max-width': 9,
            // Where labels collide the prominent place keeps its name.
            'symbol-sort-key': ['get', 'min_zoom'],
            'text-optional': true,
        },
        paint: {
            'text-color': dark ? '#e7d8c6' : '#5b3a24',
            'text-halo-color': dark ? '#1c1510' : '#ffffff',
            'text-halo-width': 1.4,
        },
    });
}

const styleUrl = () => {
    const styles = settings().style ?? {};

    return isDarkTheme() ? styles.dark : styles.light;
};

/**
 * Build a map. Returns the MapLibre instance with a couple of helpers attached.
 *
 * @param {HTMLElement} container
 * @param {{center?: [number, number], zoom?: number, interactive?: boolean}} options
 */
export function createMap(container, options = {}) {
    const config = settings();
    const center = options.center ?? [config.center.longitude, config.center.latitude];

    const map = new maplibregl.Map({
        container,
        style: styleUrl() ?? rasterFallbackStyle(),
        center,
        zoom: options.zoom ?? config.defaultZoom ?? 13,
        interactive: options.interactive !== false,
        attributionControl: false,
        // Keeps the camera in Negros rather than letting a stray pinch send a
        // customer to the middle of the Pacific with no way back.
        maxBounds: options.unbounded
            ? undefined
            : [
                  [config.bounds.west - 0.6, config.bounds.south - 0.6],
                  [config.bounds.east + 0.6, config.bounds.north + 0.6],
              ],
    });

    map.addControl(
        new maplibregl.AttributionControl({ compact: true }),
        'bottom-right'
    );

    // On a narrow map MapLibre opens the credits expanded until the first drag,
    // which buries a quarter of a phone-sized map. Start collapsed to the (i)
    // button instead; one tap still shows the full OpenStreetMap attribution.
    map.once('load', () => {
        container
            .querySelector('.maplibregl-ctrl-attrib.maplibregl-compact-show')
            ?.classList.remove('maplibregl-compact-show');
    });

    if (options.interactive !== false) {
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        // Touch pinch-zoom without also rotating, which on a phone is almost
        // always an accident rather than an intent.
        map.touchZoomRotate.disableRotation();
    }

    // 'style.load' fires for the first style and again after every theme swap,
    // which wipes custom layers, so the landmarks are re-added each time.
    if (options.landmarks !== false) {
        map.on('style.load', () => addLandmarkLayer(map));
    }

    let usingFallback = false;
    map.on('error', (event) => {
        // A tile 404 at the edge of coverage is normal and must not trigger a
        // whole-style swap; only a failure to load the style itself does.
        const failedStyle = event?.error && !map.isStyleLoaded() && !usingFallback;

        if (failedStyle) {
            usingFallback = true;
            console.warn('[map] vector style unavailable, falling back to raster OSM');
            map.setStyle(rasterFallbackStyle());
        }
    });

    // A map that never finishes loading raises no 'error' (a worker that fails
    // to start is silent), so give the style a deadline and announce a miss.
    // Callers listen for 'app:load-timeout' to swap their spinner for a message.
    const loadDeadline = setTimeout(() => {
        if (!map.isStyleLoaded()) map.fire('app:load-timeout');
    }, options.loadTimeoutMs ?? 15000);
    map.once('load', () => clearTimeout(loadDeadline));
    map.once('remove', () => clearTimeout(loadDeadline));

    // Follow the app's theme toggle without tearing the map down.
    const themeObserver = new MutationObserver(() => {
        if (usingFallback) return;

        const next = styleUrl();
        if (next && next !== map.__appliedStyleUrl) {
            map.__appliedStyleUrl = next;
            map.setStyle(next);
        }
    });
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
    map.__appliedStyleUrl = styleUrl();
    map.once('remove', () => themeObserver.disconnect());

    return map;
}

/**
 * A coloured dot marker. Used for pins, riders and destinations so they all
 * read as one visual family.
 */
export function createDotMarker({ color = '#8a5a2b', pulse = false, icon = null } = {}) {
    const el = document.createElement('div');
    el.className = `map-dot-marker${pulse ? ' map-dot-marker--pulse' : ''}`;
    el.style.setProperty('--dot-color', color);

    if (icon) {
        el.innerHTML = `<span class="map-dot-marker__icon">${icon}</span>`;
    }

    return el;
}

// The van's size on the map for each direction, in CSS pixels. Every picture is
// drawn at the same scale, so a side view is simply longer than one from above.
// Sized to sit on a street at a glance without hiding the roads around it.
const VAN_SIZES = {
    n: [17, 32], ne: [27, 31], e: [41, 23], se: [31, 32],
    s: [20, 35], sw: [28, 34], w: [41, 22], nw: [30, 34],
};

// Clockwise from north, 45 degrees apart, matching a compass heading.
const VAN_DIRECTIONS = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];

// Movement smaller than this between fixes is GPS drift, not driving, so it
// must not spin the van around while the rider waits at a gate.
const VAN_TURN_MIN_METRES = 8;

let vansPreloaded = false;

const vanUrl = (direction) => `${settings().riderVan ?? '/images/rider-van'}/van-${direction}.webp`;

/**
 * The rider on the map: the Cane & Cotton van, drawn facing the way it is
 * driving. Place it with anchor 'center' and move it with moveRiderMarker().
 */
export function createRiderMarker({ title = null, heading = null } = {}) {
    const el = document.createElement('div');
    el.className = 'map-rider-van';

    if (title) {
        el.title = title;
    }

    const img = document.createElement('img');
    img.alt = '';
    img.draggable = false;
    img.decoding = 'async';
    el.appendChild(img);

    // Load every direction up front so a turn swaps the picture at once
    // instead of blanking the van while the next one downloads.
    if (!vansPreloaded) {
        VAN_DIRECTIONS.forEach((direction) => {
            new Image().src = vanUrl(direction);
        });
        vansPreloaded = true;
    }

    // No heading yet: side on, which reads as a parked van.
    faceVan(el, Number.isFinite(heading) ? heading : 90);

    return el;
}

function faceVan(el, heading) {
    const normalised = ((heading % 360) + 360) % 360;
    const direction = VAN_DIRECTIONS[Math.round(normalised / 45) % 8];

    if (el.dataset.direction === direction) return;

    const [width, height] = VAN_SIZES[direction];
    el.dataset.direction = direction;
    el.style.width = `${width}px`;
    el.style.height = `${height}px`;
    el.firstChild.src = vanUrl(direction);
}

/**
 * Move the rider's van to a new fix and turn it to match.
 *
 * The way it actually travelled wins over the phone's compass heading, which
 * is missing or erratic at low speed. With too little movement to tell, the
 * reported heading is used, and failing that the van keeps facing as it was.
 */
export function moveRiderMarker(marker, target, { heading = null, duration = 900 } = {}) {
    const from = marker.getLngLat();
    const [toLng, toLat] = target;
    const el = marker.getElement();

    let facing = null;

    if (metresBetween(from.lat, from.lng, toLat, toLng) >= VAN_TURN_MIN_METRES) {
        facing = bearingBetween(from.lat, from.lng, toLat, toLng);
    } else if (Number.isFinite(heading)) {
        facing = heading;
    }

    if (facing !== null) {
        turnRiderMarker(marker, facing);
    }

    glideMarker(marker, target, duration);
}

/** Point the van at a compass heading without moving it. */
export function turnRiderMarker(marker, heading) {
    if (!Number.isFinite(heading)) return;

    // Headings are true north; keep the van right if the map is turned.
    faceVan(marker.getElement(), heading - (marker._map?.getBearing?.() ?? 0));
}

/** Compass bearing from one [lng, lat] to another, in degrees. */
export function bearing(from, to) {
    return bearingBetween(from[1], from[0], to[1], to[0]);
}

/** Distance between two [lng, lat] points, in metres. */
export function distance(from, to) {
    return metresBetween(from[1], from[0], to[1], to[0]);
}

const toRadians = (degrees) => (degrees * Math.PI) / 180;

function metresBetween(lat1, lng1, lat2, lng2) {
    const dLat = toRadians(lat2 - lat1);
    const dLng = toRadians(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.sin(dLng / 2) ** 2;

    return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function bearingBetween(lat1, lng1, lat2, lng2) {
    const y = Math.sin(toRadians(lng2 - lng1)) * Math.cos(toRadians(lat2));
    const x = Math.cos(toRadians(lat1)) * Math.sin(toRadians(lat2))
        - Math.sin(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.cos(toRadians(lng2 - lng1));

    return (Math.atan2(y, x) * 180) / Math.PI;
}

/**
 * Reverse-geocode a pin through our own server. The browser never talks to
 * Nominatim directly — see App\Support\Geocoder for why.
 */
export async function reverseGeocode(latitude, longitude) {
    try {
        const response = await fetch(
            `${settings().endpoints.reverse}?latitude=${latitude}&longitude=${longitude}`,
            { headers: { Accept: 'application/json' } }
        );

        if (!response.ok) return null;

        return (await response.json()).address ?? null;
    } catch {
        return null;
    }
}

/** Free-text address search, biased to the service area server-side. */
export async function searchAddress(query) {
    try {
        const response = await fetch(
            `${settings().endpoints.search}?q=${encodeURIComponent(query)}`,
            { headers: { Accept: 'application/json' } }
        );

        if (!response.ok) return [];

        return (await response.json()).results ?? [];
    } catch {
        return [];
    }
}

/**
 * Slide a marker to a new position instead of teleporting it.
 *
 * Positions arrive on a fixed poll interval, so without this a rider's marker
 * jumps every 10 seconds. Easing between the two points over roughly that same
 * interval is what makes the marker read as a vehicle actually moving, the way
 * a ride-hailing app does.
 */
export function glideMarker(marker, target, durationMs = 900) {
    const from = marker.getLngLat();
    const [toLng, toLat] = target;

    // A first fix, or a jump big enough that easing would look like a glitch
    // rather than movement, is applied outright.
    const jumped = Math.abs(from.lng - toLng) > 0.05 || Math.abs(from.lat - toLat) > 0.05;

    if (jumped || prefersReducedMotion()) {
        marker.setLngLat(target);
        return;
    }

    cancelAnimationFrame(marker.__glideFrame);

    const start = performance.now();
    // easeOutCubic: quick off the mark, settles gently — reads as deceleration.
    const ease = (t) => 1 - Math.pow(1 - t, 3);

    const step = (now) => {
        const t = Math.min(1, (now - start) / durationMs);
        const k = ease(t);

        marker.setLngLat([
            from.lng + (toLng - from.lng) * k,
            from.lat + (toLat - from.lat) * k,
        ]);

        if (t < 1) {
            marker.__glideFrame = requestAnimationFrame(step);
        }
    };

    marker.__glideFrame = requestAnimationFrame(step);
}

const prefersReducedMotion = () =>
    window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

export { maplibregl };

// Blade templates drive these from inline scripts, so the entry exposes itself
// rather than requiring every page to be its own bundled module.
window.AppMaps = {
    createMap,
    createDotMarker,
    createRiderMarker,
    moveRiderMarker,
    turnRiderMarker,
    bearing,
    distance,
    reverseGeocode,
    searchAddress,
    glideMarker,
    maplibregl,
};

installMapPicker();
installRiderNav();

window.dispatchEvent(new CustomEvent('app-maps-ready'));
