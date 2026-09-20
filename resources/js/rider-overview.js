/**
 * Every run on one map, for a rider deciding where to go next.
 *
 * Pins are coloured by stage, not by list, so the road view answers the only
 * question that matters on a bike: which of these can I do now, and which is
 * nearest. Tapping one opens its card; from there the job screen takes over
 * with turn-by-turn and rerouting.
 *
 * Exposed as a global for the same reason as the other map components: Alpine
 * resolves x-data against global scope, so bundle order can never break it.
 */

export const STAGES = {
    delivery: { label: 'Ready for delivery', color: '#ea580c' },
    pickup: { label: 'Ready for pickup', color: '#A07148' },
    available: { label: 'Up for grabs', color: '#0284c7' },
    in_cycle: { label: 'In the wash', color: '#94a3b8' },
};

export default function installRiderOverview() {
    window.riderOverview = (config) => ({
        map: null,
        ready: false,
        failed: false,
        loading: false,
        error: null,
        newRunMessage: '',

        jobs: config.jobs ?? [],
        markers: new Map(),
        selectedId: null,

        // Stage keys the rider wants to see. All of them, until they say not.
        hidden: [],

        position: null,
        riderMarker: null,
        heading: null,
        watchId: null,

        // Real driving distance for the pin the rider is looking at.
        roadLabel: null,
        roadFor: null,

        init() {
            this.$nextTick(() => this.build());

            // Keep the board current without the rider pulling to refresh: a
            // run they collect is a pin that should stop asking to be picked up.
            this.poll = window.setInterval(() => this.refresh(), config.pollMs ?? 5000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.refresh();
            });
        },

        // ── map ───────────────────────────────────────────────────────────

        build() {
            if (!window.AppMaps) {
                // The list below the map is the whole screen's fallback, so a
                // rider with no map still has every address and every action.
                this.failed = true;
                this.paintIcons();
                this.startWatch();
                return;
            }

            try {
                this.map = window.AppMaps.createMap(this.$refs.map, { zoom: 13 });
            } catch (error) {
                console.error('[rider-overview] map failed to start', error);
                this.failed = true;
                this.startWatch();
                return;
            }

            // 'style.load' rather than 'load': a theme swap or the raster
            // fallback replaces the style, and the pins have to come back.
            this.map.on('style.load', () => {
                this.ready = true;
                this.map.resize();
                this.drawJobs();
                this.drawRider();
            });

            this.map.on('app:load-timeout', () => { this.failed = true; });

            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => this.map?.resize()).observe(this.$refs.map);
            }

            this.startWatch();
        },

        // ── data ──────────────────────────────────────────────────────────

        async refresh() {
            if (this.loading || document.hidden) return;

            this.loading = true;

            try {
                const response = await fetch(config.feedUrl, {
                    headers: { Accept: 'application/json' },
                });

                if (response.ok) {
                    const body = await response.json();
                    const previousStages = new Map(this.jobs.map((job) => [job.id, job.stage]));
                    const freshJobs = body.jobs ?? [];
                    const newlyAssigned = freshJobs.find((job) => ['pickup', 'delivery'].includes(job.stage) && previousStages.get(job.id) !== job.stage);
                    const newAvailable = freshJobs.find((job) => job.stage === 'available' && !previousStages.has(job.id));
                    if (newlyAssigned || newAvailable) {
                        this.newRunMessage = newlyAssigned
                            ? 'A run is ready for you. Check its pin below.'
                            : 'A new pickup is available. Check its pin below.';
                    }
                    this.jobs = freshJobs;
                    this.error = null;
                    this.drawJobs();
                    this.paintIcons();
                } else {
                    this.error = 'Could not refresh. Showing the last list.';
                }
            } catch {
                // Offline on the road is normal. The pins already drawn are
                // still the best information the rider has, so they stay.
                this.error = 'Offline. Showing the last list.';
            }

            this.loading = false;
        },

        // ── pins ──────────────────────────────────────────────────────────

        get visibleJobs() {
            return this.jobs.filter((job) => !this.hidden.includes(job.stage));
        },

        get mappableJobs() {
            return this.visibleJobs.filter((job) => job.latitude !== null && job.longitude !== null);
        },

        /** Jobs we cannot draw, so the screen can own up to them. */
        get unmappedJobs() {
            return this.visibleJobs.filter((job) => job.latitude === null || job.longitude === null);
        },

        countFor(stage) {
            return this.jobs.filter((job) => job.stage === stage).length;
        },

        stageLabel(stage) {
            return (config.stages[stage] || {}).label || stage;
        },

        stageColor(stage) {
            return (config.stages[stage] || {}).color || '#A07148';
        },

        toggleStage(stage) {
            this.hidden = this.hidden.includes(stage)
                ? this.hidden.filter((s) => s !== stage)
                : [...this.hidden, stage];

            this.drawJobs();
        },

        drawJobs() {
            if (!this.map || !this.ready) return;

            const wanted = new Set(this.mappableJobs.map((job) => job.id));

            // Drop pins for anything that moved on or got filtered out.
            for (const [id, marker] of this.markers) {
                if (!wanted.has(id)) {
                    marker.remove();
                    this.markers.delete(id);
                }
            }

            this.mappableJobs.forEach((job) => {
                const existing = this.markers.get(job.id);

                if (existing) {
                    existing.setLngLat([job.longitude, job.latitude]);
                    return;
                }

                const element = window.AppMaps.createDotMarker({
                    color: this.stageColor(job.stage),
                    pulse: job.stage === 'delivery' || job.stage === 'pickup',
                });

                element.style.cursor = 'pointer';
                element.addEventListener('click', (event) => {
                    event.stopPropagation();
                    this.select(job.id);
                });

                const marker = new window.AppMaps.maplibregl.Marker({ element, anchor: 'center' })
                    .setLngLat([job.longitude, job.latitude])
                    .addTo(this.map);

                this.markers.set(job.id, marker);
            });
        },

        // ── selection ─────────────────────────────────────────────────────

        get selected() {
            return this.jobs.find((job) => job.id === this.selectedId) ?? null;
        },

        select(id) {
            this.selectedId = id;
            this.roadLabel = null;
            this.paintIcons();

            const job = this.selected;

            if (job && job.latitude !== null && this.map) {
                this.map.easeTo({ center: [job.longitude, job.latitude], zoom: 16, duration: 600 });
            }

            this.loadRoadDistance(job);
        },

        /**
         * Straight-line distance is a poor guide here: Kabankalan is split by
         * the Ilog river, so a pin a kilometre away can be a long ride to the
         * nearest crossing. Ask the router for the real thing, for the one pin
         * the rider is actually weighing up, and keep the direct figure as the
         * fallback when there is no answer.
         */
        async loadRoadDistance(job) {
            if (!job || !this.position || job.latitude === null) return;

            const asked = job.id;
            this.roadFor = asked;

            try {
                const params = new URLSearchParams({
                    latitude: String(this.position[1]),
                    longitude: String(this.position[0]),
                });

                const response = await fetch(`${job.route_url}?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) return;

                const body = await response.json();
                const route = (body.routes ?? [])[0];

                // The rider may have tapped something else while we waited.
                if (!route || this.roadFor !== asked || this.selectedId !== asked) return;

                const km = route.distance >= 1000
                    ? `${(route.distance / 1000).toFixed(1)} km`
                    : `${Math.round(route.distance)} m`;

                this.roadLabel = `${km} · ${Math.max(1, Math.round(route.duration / 60))} min by road`;
            } catch {
                // No router, no signal: the direct distance still shows.
            }
        },

        clearSelection() {
            this.selectedId = null;
            this.roadLabel = null;
            this.paintIcons();
        },

        /**
         * The card and the fallback list are built by x-if/x-for, so they
         * appear after the page's one icon pass and would show empty boxes.
         */
        paintIcons() {
            this.$nextTick(() => window.renderLucideIcons?.());
        },

        /** Straight-line distance, honest about being "as the crow flies". */
        distanceTo(job) {
            if (!this.position || job.latitude === null) return null;

            const metres = haversineMetres(
                this.position[1], this.position[0],
                job.latitude, job.longitude
            );

            return metres >= 1000 ? `${(metres / 1000).toFixed(1)} km` : `${Math.round(metres)} m`;
        },

        // ── camera ────────────────────────────────────────────────────────

        fitAll() {
            const pins = this.mappableJobs;

            if (!pins.length || !this.map) return;

            const bounds = new window.AppMaps.maplibregl.LngLatBounds();
            pins.forEach((job) => bounds.extend([job.longitude, job.latitude]));
            if (this.position) bounds.extend(this.position);

            this.map.fitBounds(bounds, { padding: 70, maxZoom: 16, duration: 600 });
        },

        recenter() {
            if (!this.position || !this.map) return;

            this.map.easeTo({ center: this.position, zoom: 15, duration: 600 });
        },

        // ── the rider ─────────────────────────────────────────────────────

        startWatch() {
            if (!navigator.geolocation) return;

            this.watchId = navigator.geolocation.watchPosition(
                (fix) => {
                    const first = this.position === null;
                    this.position = [fix.coords.longitude, fix.coords.latitude];
                    this.heading = Number.isFinite(fix.coords.heading) ? fix.coords.heading : null;
                    this.drawRider();
                    if (first) this.fitAll();
                },
                () => {},
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 10000 }
            );
        },

        drawRider() {
            if (!this.map || !this.position || !this.ready) return;

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
