import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import installMapPicker from './map-picker';
import installRiderNav from './rider-nav';

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

    if (options.interactive !== false) {
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        // Touch pinch-zoom without also rotating, which on a phone is almost
        // always an accident rather than an intent.
        map.touchZoomRotate.disableRotation();
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
    reverseGeocode,
    searchAddress,
    glideMarker,
    maplibregl,
};

installMapPicker();
installRiderNav();

window.dispatchEvent(new CustomEvent('app-maps-ready'));
