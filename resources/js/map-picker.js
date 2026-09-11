/**
 * Alpine component behind <x-map-picker>.
 *
 * Exposed as a plain global rather than registered through Alpine.data().
 * Alpine resolves an x-data expression against global scope when it is not a
 * registered component, so this works no matter how the two bundles are
 * ordered — and there is no window in which a map on the page would throw
 * because Alpine started first.
 */
export default function installMapPicker() {
    window.mapPicker = (config) => ({
        latitude: config.latitude,
        longitude: config.longitude,
        addressField: config.addressField,
        query: '',
        results: [],
        searching: false,
        locating: false,
        ready: false,
        failed: false,
        outsideServiceArea: false,
        map: null,
        marker: null,

        init() {
            // Needs a laid-out container to size against, and on the booking
            // form this starts inside a step that is still display:none.
            this.$nextTick(() => this.build());
        },

        build() {
            if (!window.AppMaps) {
                this.failed = true;
                return;
            }

            const settings = window.mapConfig;
            const hasPin = this.latitude !== null && this.longitude !== null;
            const center = hasPin
                ? [this.longitude, this.latitude]
                : [settings.center.longitude, settings.center.latitude];

            try {
                this.map = window.AppMaps.createMap(this.$refs.map, {
                    center,
                    zoom: hasPin ? settings.detailZoom : settings.defaultZoom,
                });
            } catch (error) {
                console.error('[map-picker] could not start map', error);
                this.failed = true;
                return;
            }

            this.marker = new window.AppMaps.maplibregl.Marker({
                element: window.AppMaps.createDotMarker({ color: '#8a5a2b' }),
                draggable: true,
                anchor: 'center',
            })
                .setLngLat(center)
                .addTo(this.map);

            this.marker.on('dragend', () => {
                const { lng, lat } = this.marker.getLngLat();
                this.commit(lat, lng, { reverse: true });
            });

            // Tapping is easier than dragging on a phone.
            this.map.on('click', (event) => {
                this.marker.setLngLat(event.lngLat);
                this.commit(event.lngLat.lat, event.lngLat.lng, { reverse: true });
            });

            this.map.on('load', () => {
                this.ready = true;
                this.map.resize();
            });

            // A step hidden at build time gives the canvas zero width; redraw
            // it the moment the step is actually shown.
            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => this.map?.resize()).observe(this.$refs.map);
            }

            if (hasPin) {
                this.checkServiceArea(this.latitude, this.longitude);
            }
        },

        /** Record a new pin position, and sync the address box if it is empty. */
        async commit(latitude, longitude, { reverse = false } = {}) {
            this.latitude = Number(latitude.toFixed(7));
            this.longitude = Number(longitude.toFixed(7));

            this.checkServiceArea(this.latitude, this.longitude);

            if (!reverse) return;

            const address = await window.AppMaps.reverseGeocode(this.latitude, this.longitude);

            if (address) {
                this.fillAddress(address);
            }
        },

        /**
         * Only ever fills an empty address box. Someone who typed "blue gate
         * beside the sari-sari store" has told the rider far more than a
         * geocoded street name would, so that must never be overwritten.
         */
        fillAddress(address) {
            const field = document.getElementById(this.addressField);

            if (!field || field.value.trim() !== '') return;

            field.value = address;
            // Alpine x-model on the same field needs to hear about this.
            field.dispatchEvent(new Event('input', { bubbles: true }));
        },

        async search() {
            const term = this.query.trim();

            // Two characters is enough to narrow the barangay list, which is
            // answered from data already on the page.
            if (term.length < 2) {
                this.results = [];
                return;
            }

            this.searching = true;

            const results = await window.AppMaps.searchAddress(term);

            // A slower request that resolves after the box was cleared must not
            // repopulate the dropdown.
            if (this.query.trim() === term) {
                this.results = results;
            }

            this.searching = false;
        },

        choose(result) {
            this.results = [];
            this.query = '';

            // A barangay covers a whole area, so stop at a zoom that shows the
            // neighbourhood and invites a drag. A street is precise enough to
            // go all the way in.
            const zoom = result.kind === 'barangay' ? 15 : window.mapConfig.detailZoom;

            this.map?.flyTo({ center: [result.longitude, result.latitude], zoom });
            this.marker?.setLngLat([result.longitude, result.latitude]);

            this.commit(result.latitude, result.longitude);

            // Seed the address box with something the rider can read, keeping
            // the city on the end so it reads as a real address.
            this.fillAddress(
                result.kind === 'barangay'
                    ? `${result.label}, Kabankalan City`
                    : `${result.label}, ${result.context}`
            );
        },

        locateMe() {
            if (!navigator.geolocation) return;

            this.locating = true;

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const { latitude, longitude } = position.coords;

                    this.map?.flyTo({
                        center: [longitude, latitude],
                        zoom: window.mapConfig.detailZoom,
                    });
                    this.marker?.setLngLat([longitude, latitude]);
                    this.commit(latitude, longitude, { reverse: true });
                    this.locating = false;
                },
                () => {
                    // Denied or unavailable. The pin is still draggable, so
                    // there is nothing to recover and nothing to shout about.
                    this.locating = false;
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
            );
        },

        checkServiceArea(latitude, longitude) {
            const { center, serviceRadiusKm } = window.mapConfig;

            this.outsideServiceArea =
                haversineKm(center.latitude, center.longitude, latitude, longitude) > serviceRadiusKm;
        },
    });
}

export function haversineKm(lat1, lng1, lat2, lng2) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const earthRadiusKm = 6371.0088;

    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

    return earthRadiusKm * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
}
