{{--
    Live rider map for a customer watching one booking.

    Only rendered while the booking is actually trackable. It polls a small JSON
    endpoint keyed on the reference number rather than holding a socket open, so
    it costs nothing to run and works on any host.
--}}
@include('partials.map-assets')

<div x-data="bookingTracker({ endpoint: @js(route('track.location', $pickupRequest->reference_no)) })"
     x-init="init()"
     x-show="tracking || stale"
     x-cloak
     class="mt-8 overflow-hidden rounded-2xl border border-border dark:border-white/10">

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-cream px-5 py-3.5 dark:border-white/10 dark:bg-[#1c1510]">
        <div class="min-w-0">
            <p class="text-[11px] uppercase tracking-[0.16em] text-muted">Live</p>
            <p class="mt-0.5 truncate text-sm font-semibold text-primary-deep dark:text-cane"
               x-text="headline"></p>
        </div>

        <span x-show="tracking" x-cloak
              class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">
            <span class="relative flex h-2 w-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
            </span>
            <span x-text="etaLabel"></span>
        </span>
    </div>

    <div class="relative">
        <div x-ref="map" class="h-64 w-full bg-cream dark:bg-[#1c1510] sm:h-80"></div>

        <div x-show="stale" x-cloak
             class="absolute inset-x-0 bottom-0 bg-amber-50/95 px-4 py-2.5 text-center text-xs text-amber-800 backdrop-blur dark:bg-amber-500/15 dark:text-amber-200">
            Your rider is on the way — their location will appear once they have signal.
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.bookingTracker = (config) => ({
        tracking: false,
        stale: false,
        headline: 'Locating your rider…',
        etaLabel: '',
        map: null,
        riderMarker: null,
        destinationMarker: null,
        timer: null,
        centredOnce: false,

        init() {
            this.$nextTick(() => this.refresh());

            document.addEventListener('visibilitychange', () => {
                document.hidden ? this.pause() : this.refresh();
            });
        },

        pause() {
            clearTimeout(this.timer);
            this.timer = null;
        },

        async refresh() {
            this.pause();

            try {
                const response = await fetch(config.endpoint, { headers: { Accept: 'application/json' } });

                if (response.ok) {
                    this.apply(await response.json());
                }
            } catch {
                // Keep the last known position rather than blanking the map.
            }

            this.timer = setTimeout(
                () => this.refresh(),
                (window.mapConfig?.tracking?.pollInterval ?? 10) * 1000
            );
        },

        apply(data) {
            this.stale = Boolean(data.stale);
            this.tracking = Boolean(data.tracking);

            if (!this.tracking && !this.stale) {
                // Delivered, cancelled, or no rider yet — nothing to draw and
                // no reason to keep asking.
                this.pause();
                return;
            }

            const leg = data.leg === 'delivery' ? 'bringing your laundry back' : 'on the way to collect';
            this.headline = data.rider_name
                ? `${data.rider_name} is ${leg}`
                : `Your rider is ${leg}`;

            if (data.eta_minutes) {
                this.etaLabel = `~${data.eta_minutes} min away`;
            }

            this.ensureMap(data);

            if (data.tracking && data.rider) {
                this.placeRider(data.rider);
            }
        },

        ensureMap(data) {
            if (this.map || !window.AppMaps) return;

            const start = data.rider ?? data.destination;
            if (!start) return;

            this.map = window.AppMaps.createMap(this.$refs.map, {
                center: [start.longitude, start.latitude],
                zoom: 15,
            });

            this.map.on('load', () => this.map.resize());

            if (data.destination) {
                this.destinationMarker = new window.AppMaps.maplibregl.Marker({
                    element: window.AppMaps.createDotMarker({ color: '#A07148' }),
                    anchor: 'center',
                })
                    .setLngLat([data.destination.longitude, data.destination.latitude])
                    .addTo(this.map);
            }
        },

        placeRider(rider) {
            if (!this.map) return;

            const position = [rider.longitude, rider.latitude];

            if (this.riderMarker) {
                // Ease between polls so the rider reads as moving rather than
                // teleporting every interval.
                window.AppMaps.glideMarker(
                    this.riderMarker,
                    position,
                    (window.mapConfig?.tracking?.pollInterval ?? 10) * 900
                );
            } else {
                this.riderMarker = new window.AppMaps.maplibregl.Marker({
                    element: window.AppMaps.createDotMarker({ color: '#059669', pulse: true }),
                    anchor: 'center',
                })
                    .setLngLat(position)
                    .addTo(this.map);
            }

            // Frame rider and destination together once, then leave the camera
            // alone so the customer can pan without being yanked back.
            if (!this.centredOnce && this.destinationMarker) {
                const bounds = new window.AppMaps.maplibregl.LngLatBounds(position, position);
                bounds.extend(this.destinationMarker.getLngLat());
                this.map.fitBounds(bounds, { padding: 64, maxZoom: 16, duration: 0 });
                this.centredOnce = true;
            }
        },
    });
</script>
@endpush
