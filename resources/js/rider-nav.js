/**
 * Turn-by-turn navigation for the rider's job screen.
 *
 * Draws the road route as MapLibre line layers, keeps the rider's own GPS
 * position on the map, recalculates when they leave the line, and lets them
 * force a different way round by tapping the map.
 *
 * Exposed as a global for the same reason as the picker: Alpine resolves an
 * x-data expression against global scope, so bundle order can never break it.
 */
export default function installRiderNav() {
    window.riderNav = (config) => ({
        // ── map + layers ──────────────────────────────────────────────────
        map: null,
        ready: false,
        failed: false,
        riderMarker: null,
        destinationMarker: null,
        viaMarkers: [],
        routeHandlersBound: false,

        // ── route state ───────────────────────────────────────────────────
        routes: [],
        activeRoute: 0,
        via: [],
        loadingRoute: false,
        routeError: false,
        pickingVia: false,

        // ── live position ─────────────────────────────────────────────────
        position: null,
        heading: null,
        watchId: null,
        following: true,

        // ── sheet ─────────────────────────────────────────────────────────
        sheetOpen: false,

        get current() {
            return this.routes[this.activeRoute] ?? null;
        },

        get etaMinutes() {
            return this.current ? Math.max(1, Math.round(this.current.duration / 60)) : null;
        },

        get distanceLabel() {
            if (!this.current) return null;

            const m = this.current.distance;
            return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`;
        },

        /** The instruction the rider needs next, based on where they are now. */
        get nextStep() {
            const steps = this.current?.steps ?? [];

            if (!steps.length) return null;
            if (!this.position) return steps[0];

            // Nearest upcoming maneuver — steps are ordered, so the closest one
            // ahead is a good enough "next turn" without map-matching.
            let best = steps[0];
            let bestDistance = Infinity;

            steps.forEach((step) => {
                const d = haversineMetres(
                    this.position[1], this.position[0],
                    step.location[1], step.location[0]
                );

                if (d < bestDistance) {
                    bestDistance = d;
                    best = step;
                }
            });

            return best;
        },

        init() {
            this.$nextTick(() => this.build());

            // A phone that sleeps mid-run drops the watch; re-arm on return.
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && this.watchId === null) this.startWatch();
            });
        },

        build() {
            if (!window.AppMaps) {
                this.failed = true;
                // Routing and the next-turn text do not need a map to work, so
                // a rider with no map still gets an ETA and instructions.
                this.startWatch();
                return;
            }

            try {
                this.map = window.AppMaps.createMap(this.$refs.map, {
                    center: [config.destination.longitude, config.destination.latitude],
                    zoom: 15,
                });
            } catch (error) {
                console.error('[rider-nav] map failed to start', error);
                this.failed = true;
                this.startWatch();
                return;
            }

            this.destinationMarker = new window.AppMaps.maplibregl.Marker({
                element: window.AppMaps.createDotMarker({ color: '#dc2626' }),
                anchor: 'center',
            })
                .setLngLat([config.destination.longitude, config.destination.latitude])
                .addTo(this.map);

            // 'style.load', not 'load': setStyle() wipes every custom source
            // and layer, and the style is swapped both by the theme toggle and
            // by the raster fallback when the vector host is down. Bound to
            // 'load' the route lines vanished for good after either one.
            this.map.on('style.load', () => {
                this.ready = true;
                this.map.resize();
                this.installRouteLayers();
                // A route may already have arrived while the style was still
                // loading; paint it now that there are layers to paint into.
                this.drawRoutes();
            });

            // Deliberately not inside the 'load' handler. If the tile host is
            // slow or down that event can be late or never fire, and a rider
            // must not lose turn-by-turn guidance just because the basemap
            // did not draw.
            this.startWatch();

            // Any manual pan means the rider wants to look around; stop
            // yanking the camera back to them until they ask for it.
            this.map.on('dragstart', () => { this.following = false; });

            this.map.on('click', (event) => {
                if (!this.pickingVia) return;

                this.addVia(event.lngLat.lat, event.lngLat.lng);
            });

            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => this.map?.resize()).observe(this.$refs.map);
            }
        },

        /**
         * Empty sources up front so route redraws are a setData() call rather
         * than tearing layers down and rebuilding them on every recalculation.
         */
        installRouteLayers() {
            // Re-runs after every style swap, which is what wiped the sources.
            if (this.map.getSource('route-active')) return;

            const empty = { type: 'FeatureCollection', features: [] };

            this.map.addSource('route-alternatives', { type: 'geojson', data: empty });
            this.map.addSource('route-active', { type: 'geojson', data: empty });

            // Alternatives sit underneath, muted, so the chosen line reads first.
            this.map.addLayer({
                id: 'route-alternatives',
                type: 'line',
                source: 'route-alternatives',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    'line-color': '#94a3b8',
                    'line-width': 5,
                    'line-opacity': 0.55,
                },
            });

            // Casing under the active line keeps it legible over dark tiles.
            this.map.addLayer({
                id: 'route-active-casing',
                type: 'line',
                source: 'route-active',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: { 'line-color': '#ffffff', 'line-width': 10, 'line-opacity': 0.9 },
            });

            this.map.addLayer({
                id: 'route-active',
                type: 'line',
                source: 'route-active',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: { 'line-color': '#A07148', 'line-width': 6 },
            });

            this.installRouteHandlers();
        },

        /**
         * Bound to the map rather than the style, so unlike the layers above
         * these outlive a style swap and must only ever be attached once.
         */
        installRouteHandlers() {
            if (this.routeHandlersBound) return;
            this.routeHandlersBound = true;

            // Tapping a grey line switches to it.
            this.map.on('click', 'route-alternatives', (event) => {
                const index = event.features?.[0]?.properties?.index;

                if (index !== undefined) this.selectRoute(Number(index));
            });

            this.map.on('mouseenter', 'route-alternatives', () => {
                this.map.getCanvas().style.cursor = 'pointer';
            });
            this.map.on('mouseleave', 'route-alternatives', () => {
                this.map.getCanvas().style.cursor = '';
            });
        },

        // ── live position ────────────────────────────────────────────────
        startWatch() {
            if (!navigator.geolocation) return;

            this.watchId = navigator.geolocation.watchPosition(
                (fix) => this.onPosition(fix),
                () => {},
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 3000 }
            );
        },

        onPosition(fix) {
            const { latitude, longitude, heading } = fix.coords;
            const first = this.position === null;

            this.position = [longitude, latitude];
            this.heading = Number.isFinite(heading) ? heading : null;

            this.drawRider();

            if (first) {
                // Nothing to route from until the first fix lands.
                this.fetchRoute({ fit: true });
                return;
            }

            if (this.following) {
                this.map?.easeTo({ center: this.position, duration: 800 });
            }

            this.checkDeviation(latitude, longitude);
        },

        drawRider() {
            if (!this.map || !this.position) return;

            if (!this.riderMarker) {
                this.riderMarker = new window.AppMaps.maplibregl.Marker({
                    element: window.AppMaps.createRiderMarker({ heading: this.heading }),
                    anchor: 'center',
                })
                    .setLngLat(this.position)
                    .addTo(this.map);
                return;
            }

            window.AppMaps.moveRiderMarker(this.riderMarker, this.position, { heading: this.heading, duration: 700 });
        },

        /**
         * Off the line by more than the configured margin means a different
         * road was taken, not GPS noise — so recalculate from here.
         */
        checkDeviation(latitude, longitude) {
            const line = this.current?.geometry?.coordinates;

            if (!line || this.loadingRoute) return;

            let closest = Infinity;
            for (const [lng, lat] of line) {
                const d = haversineMetres(latitude, longitude, lat, lng);
                if (d < closest) closest = d;
                if (closest < config.rerouteAfterMetres) return;
            }

            if (closest >= config.rerouteAfterMetres) {
                // A rider who deliberately routed through their own via points
                // is not "off route" — keep their choice.
                this.fetchRoute({ fit: false });
            }
        },

        // ── routing ──────────────────────────────────────────────────────
        async fetchRoute({ fit = false } = {}) {
            if (!this.position || this.loadingRoute) return;

            this.loadingRoute = true;
            this.routeError = false;

            const params = new URLSearchParams({
                latitude: String(this.position[1]),
                longitude: String(this.position[0]),
            });

            this.via.forEach((point, i) => {
                params.append(`via[${i}][latitude]`, String(point[1]));
                params.append(`via[${i}][longitude]`, String(point[0]));
            });

            try {
                const response = await fetch(`${config.routeUrl}?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (response.ok) {
                    const body = await response.json();
                    this.routes = body.routes ?? [];
                    this.activeRoute = 0;
                    this.routeError = this.routes.length === 0;
                    this.drawRoutes();

                    if (fit) this.fitRoute();
                } else {
                    this.routeError = true;
                }
            } catch {
                // Offline or the router is down. The pin and the address still
                // get the rider there, so this is a notice, not a failure.
                this.routeError = true;
            }

            this.loadingRoute = false;
        },

        drawRoutes() {
            if (!this.map?.getSource('route-active')) return;

            const active = this.current;

            this.map.getSource('route-active').setData(
                active
                    ? { type: 'Feature', geometry: active.geometry, properties: {} }
                    : { type: 'FeatureCollection', features: [] }
            );

            this.map.getSource('route-alternatives').setData({
                type: 'FeatureCollection',
                features: this.routes
                    .map((route, index) => ({ route, index }))
                    .filter(({ index }) => index !== this.activeRoute)
                    .map(({ route, index }) => ({
                        type: 'Feature',
                        geometry: route.geometry,
                        properties: { index },
                    })),
            });
        },

        selectRoute(index) {
            if (index === this.activeRoute || !this.routes[index]) return;

            this.activeRoute = index;
            this.drawRoutes();
        },

        fitRoute() {
            const line = this.current?.geometry?.coordinates;

            if (!line?.length || !this.map) return;

            const bounds = new window.AppMaps.maplibregl.LngLatBounds(line[0], line[0]);
            line.forEach((point) => bounds.extend(point));

            this.following = false;
            this.map.fitBounds(bounds, { padding: { top: 70, bottom: 260, left: 40, right: 40 } });
        },

        // ── rider-chosen detour ──────────────────────────────────────────
        toggleViaPicking() {
            this.pickingVia = !this.pickingVia;

            if (this.pickingVia) {
                this.following = false;
                this.sheetOpen = false;
            }
        },

        addVia(latitude, longitude) {
            this.via.push([longitude, latitude]);
            this.pickingVia = false;

            const marker = new window.AppMaps.maplibregl.Marker({
                element: window.AppMaps.createDotMarker({ color: '#0284c7' }),
                anchor: 'center',
            })
                .setLngLat([longitude, latitude])
                .addTo(this.map);

            this.viaMarkers.push(marker);
            this.fetchRoute({ fit: true });
        },

        clearVia() {
            this.via = [];
            this.viaMarkers.forEach((marker) => marker.remove());
            this.viaMarkers = [];
            this.fetchRoute({ fit: true });
        },

        recenter() {
            if (!this.position) return;

            this.following = true;
            this.map?.easeTo({ center: this.position, zoom: 16, duration: 600 });
        },
    });
}

function haversineMetres(lat1, lng1, lat2, lng2) {
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

    return 6371008.8 * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
}
