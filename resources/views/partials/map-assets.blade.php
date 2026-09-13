{{--
    Include this on any page that renders a map.

    Pushes the MapLibre bundle into the head stack (ahead of app.js, so anything
    it puts on window exists before Alpine starts) and hands the client the
    server's map settings, so centre, bounds and intervals live in one place
    rather than being duplicated across templates.
--}}
@once
    @push('head')
        @php
            $mapConfig = [
                'style' => config('maps.style'),
                'rasterFallback' => [
                    'tiles' => config('maps.raster_fallback.tiles'),
                    'attribution' => config('maps.raster_fallback.attribution'),
                    'maxZoom' => config('maps.raster_fallback.max_zoom'),
                ],
                'center' => config('maps.center'),
                'bounds' => config('maps.bounds'),
                'defaultZoom' => config('maps.default_zoom'),
                'detailZoom' => config('maps.detail_zoom'),
                'serviceRadiusKm' => config('maps.service_radius_km'),
                'tracking' => [
                    'pingInterval' => config('maps.tracking.ping_interval_seconds'),
                    'pollInterval' => config('maps.tracking.poll_interval_seconds'),
                    'staleAfter' => config('maps.tracking.stale_after_seconds'),
                ],
                // The rider's van, one picture per direction of travel.
                'riderVan' => asset('images/rider-van'),
                'endpoints' => [
                    'search' => route('map.geocode'),
                    'reverse' => route('map.reverse'),
                    'landmarks' => route('map.landmarks'),
                ],
            ];
        @endphp

        <script>
            window.mapConfig = {!! json_encode($mapConfig, JSON_THROW_ON_ERROR) !!};
        </script>
        @vite('resources/js/maps.js')
    @endpush
@endonce
