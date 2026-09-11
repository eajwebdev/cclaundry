<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base map style
    |--------------------------------------------------------------------------
    | MapLibre GL JS renders from a style document. The default is OpenFreeMap,
    | which serves OpenStreetMap vector tiles with no API key, no request cap
    | and no billing account — the whole stack stays free to run.
    |
    | To move to a paid provider later (MapTiler, Stadia, a self-hosted
    | tileserver) only these two URLs change; no application code knows the
    | difference.
    */
    'style' => [
        'light' => env('MAP_STYLE_LIGHT', 'https://tiles.openfreemap.org/styles/positron'),
        'dark' => env('MAP_STYLE_DARK', 'https://tiles.openfreemap.org/styles/dark'),
    ],

    /*
    | If the vector style host is unreachable the map falls back to plain
    | OpenStreetMap raster tiles, which are built into the client as an inline
    | style. A rider mid-delivery still gets a usable map.
    */
    'raster_fallback' => [
        'tiles' => ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
        'attribution' => '&copy; OpenStreetMap contributors',
        'max_zoom' => 19,
    ],

    /*
    |--------------------------------------------------------------------------
    | Service area — Kabankalan City, Negros Occidental
    |--------------------------------------------------------------------------
    | Every pickup this business serves is inside Kabankalan, so the map opens
    | there, the geocoder is biased there, and a pin dropped far outside is
    | flagged before the booking is submitted.
    */
    'center' => [
        'latitude' => (float) env('MAP_CENTER_LAT', 9.9886),
        'longitude' => (float) env('MAP_CENTER_LNG', 122.8112),
    ],

    'default_zoom' => (float) env('MAP_DEFAULT_ZOOM', 13),

    // Pin-drop zoom once an address is resolved — close enough to see houses.
    'detail_zoom' => (float) env('MAP_DETAIL_ZOOM', 17),

    /*
    | Bounding box the geocoder searches within, and the camera is limited to.
    | Kabankalan runs from the Sulu Sea coast east into the uplands, so this is
    | deliberately generous rather than just the poblacion.
    */
    'bounds' => [
        'south' => (float) env('MAP_BOUNDS_SOUTH', 9.79),
        'west' => (float) env('MAP_BOUNDS_WEST', 122.65),
        'north' => (float) env('MAP_BOUNDS_NORTH', 10.19),
        'east' => (float) env('MAP_BOUNDS_EAST', 123.10),
    ],

    // A pin further than this from the centre gets a "we may not reach you"
    // warning. It never blocks the booking — staff make the final call.
    'service_radius_km' => (float) env('MAP_SERVICE_RADIUS_KM', 30),

    /*
    |--------------------------------------------------------------------------
    | Geocoding (Nominatim)
    |--------------------------------------------------------------------------
    | Address search and reverse lookup run through OpenStreetMap's Nominatim,
    | which is free and keyless. Its usage policy requires an identifying
    | User-Agent, at most one request per second, and that results are cached
    | rather than re-fetched — all three are enforced server-side in
    | App\Support\Geocoder, never from the browser.
    */
    'geocoding' => [
        'endpoint' => env('NOMINATIM_ENDPOINT', 'https://nominatim.openstreetmap.org'),

        // Nominatim blocks traffic that does not identify itself. Set this to
        // something that names the deployment and a contact address.
        'user_agent' => env(
            'NOMINATIM_USER_AGENT',
            'CaneAndCottonLaundry/1.0 (+'.env('APP_URL', 'https://ccl.eajwebdev.com').')'
        ),

        'country_codes' => env('NOMINATIM_COUNTRY_CODES', 'ph'),

        // How long a resolved address stays cached. Streets do not move.
        'cache_ttl_minutes' => (int) env('NOMINATIM_CACHE_TTL', 60 * 24 * 30),

        'timeout_seconds' => (int) env('NOMINATIM_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing (OSRM)
    |--------------------------------------------------------------------------
    | Turn-by-turn road routing for riders. The default host is the FOSSGIS
    | instance behind openstreetmap.org's own directions — free, no API key, and
    | far steadier than the OSRM demo server, which is development-only.
    |
    | routed-car is the right profile for a motorcycle here: same road access,
    | same one-way rules. routed-bike would route down paths a rider cannot use.
    */
    'routing' => [
        'endpoint' => env('OSRM_ENDPOINT', 'https://routing.openstreetmap.de/routed-car'),

        'user_agent' => env(
            'OSRM_USER_AGENT',
            'CaneAndCottonLaundry/1.0 (+'.env('APP_URL', 'https://ccl.eajwebdev.com').')'
        ),

        'timeout_seconds' => (int) env('OSRM_TIMEOUT', 10),

        // A rider drifting further than this from the drawn line has taken a
        // different road, so the route is recalculated. Well above GPS jitter.
        'reroute_after_metres' => (int) env('ROUTE_REROUTE_METRES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live rider tracking
    |--------------------------------------------------------------------------
    | The rider's phone posts its position on an interval and the watching map
    | polls on the same interval. No websocket daemon, so this runs unchanged
    | on shared hosting.
    */
    'tracking' => [
        // Seconds between position posts from a rider's phone.
        'ping_interval_seconds' => (int) env('TRACKING_PING_INTERVAL', 10),

        // Seconds between refreshes on a watching map.
        'poll_interval_seconds' => (int) env('TRACKING_POLL_INTERVAL', 10),

        // A rider with no ping newer than this is shown as offline rather than
        // leaving a stale marker sitting on the map.
        'stale_after_seconds' => (int) env('TRACKING_STALE_AFTER', 120),

        // Positions worse than this accuracy are dropped — a 2km-accurate
        // wifi fix would otherwise make the marker jump across the city.
        'max_accuracy_meters' => (int) env('TRACKING_MAX_ACCURACY', 250),

        // How long the breadcrumb trail is kept before pruning.
        'retain_pings_days' => (int) env('TRACKING_RETAIN_DAYS', 30),
    ],
];
