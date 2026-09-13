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

// Each pin carries its own gradient, so ids must not repeat: dispatch shows
// many riders at once, and removing the first would strip the rest of colour.
let riderMarkerCount = 0;

/**
 * The rider on the map: a Cane & Cotton pin with a delivery scooter in it,
 * and a soft pulse on the ground under its tip. Place it with anchor
 * 'bottom', since the tip, not the middle, is where the rider is.
 */
export function createRiderMarker({ title = null } = {}) {
    const gradientId = `rider-pin-${++riderMarkerCount}`;
    const el = document.createElement('div');
    el.className = 'map-rider-marker';

    if (title) {
        el.title = title;
    }

    el.innerHTML = `
        <span class="map-rider-marker__ground"></span>
        <svg class="map-rider-marker__pin" viewBox="0 0 48 58" aria-hidden="true">
            <defs>
                <linearGradient id="${gradientId}" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#C39163"/>
                    <stop offset=".5" stop-color="#A07148"/>
                    <stop offset="1" stop-color="#6B4A2E"/>
                </linearGradient>
            </defs>
            <path d="M24 55.5c-2.6-4.6-7.4-8.3-12.4-12.6A21.5 21.5 0 1 1 36.4 42.9c-5 4.3-9.8 8-12.4 12.6z"
                  fill="url(#${gradientId})" stroke="#FFFDF8" stroke-width="2.6" stroke-linejoin="round"/>
            <circle cx="24" cy="23" r="16.6" fill="none" stroke="#EFC396" stroke-opacity=".75" stroke-width="1"/>
        </svg>
        <span class="map-rider-marker__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2.2" y="5.2" width="7.4" height="6.2" rx="1.3" fill="currentColor" fill-opacity=".22"/>
                <path d="M5.9 5.2v2.6"/>
                <path d="M2.4 14.1h8.4l2.3 3.4h2.2"/>
                <path d="M15.3 17.5l2-7.4-1.5-3.3h-2.1"/>
                <path d="M17.3 10.1c2 .4 3.5 2 3.9 4"/>
                <circle cx="5.6" cy="17.6" r="2.4"/>
                <circle cx="18.6" cy="17.6" r="2.4"/>
            </svg>
        </span>`;

    return el;
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
    reverseGeocode,
    searchAddress,
    glideMarker,
    maplibregl,
};

installMapPicker();
installRiderNav();

window.dispatchEvent(new CustomEvent('app-maps-ready'));
