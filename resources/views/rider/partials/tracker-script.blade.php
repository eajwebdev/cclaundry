{{--
    Location broadcasting for the rider console.

    Uses watchPosition rather than a setInterval around getCurrentPosition: the
    OS wakes us when the rider actually moves, which is far kinder to a phone
    battery on a long shift. We then throttle our own posting to the configured
    interval so a fast-moving rider does not flood the server.
--}}
<script>
    window.riderTracker = () => ({
        sharing: false,
        statusLine: 'Turn this on when you start your run.',
        watchId: null,
        lastSentAt: 0,
        pingUrl: @js(route('rider.ping')),
        stopUrl: @js(route('rider.stop-sharing')),
        activeJobId: @js($trackerJobId ?? null),

        init() {
            // Resuming a shift, not starting one: sharing still needs a tap the
            // first time, but once given it survives closing the tab, a phone
            // restart and a lost signal. Session storage lost it on every one
            // of those and took the rider off dispatch's map without saying so.
            if (localStorage.getItem('rider-sharing') === '1') {
                this.start();
            }

            // A backgrounded phone suspends the watch; re-arm on return so a
            // rider who locked their screen keeps reporting.
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && this.sharing && this.watchId === null) {
                    this.beginWatch();
                }
            });
        },

        toggle() {
            this.sharing ? this.stop() : this.start();
        },

        start() {
            if (!navigator.geolocation) {
                this.statusLine = 'This phone cannot share location.';
                return;
            }

            this.sharing = true;
            localStorage.setItem('rider-sharing', '1');
            this.statusLine = 'Getting your position…';
            this.beginWatch();
        },

        beginWatch() {
            this.watchId = navigator.geolocation.watchPosition(
                (position) => this.onPosition(position),
                (error) => this.onError(error),
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 }
            );
        },

        onPosition(position) {
            const intervalMs = (window.mapConfig?.tracking?.pingInterval ?? 10) * 1000;
            const now = Date.now();

            // watchPosition can fire several times a second while moving.
            if (now - this.lastSentAt < intervalMs) return;

            this.lastSentAt = now;
            this.send(position.coords);
        },

        async send(coords) {
            try {
                const response = await fetch(this.pingUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        latitude: coords.latitude,
                        longitude: coords.longitude,
                        accuracy: coords.accuracy ?? null,
                        heading: Number.isFinite(coords.heading) ? coords.heading : null,
                        speed: Number.isFinite(coords.speed) ? Math.max(0, coords.speed) : null,
                        pickup_request_id: this.activeJobId,
                    }),
                });

                if (!response.ok) {
                    this.statusLine = 'Could not reach the server, retrying.';
                    return;
                }

                const body = await response.json();

                // A good send says nothing: the Live switch already shows it,
                // so the line only appears when something needs the rider.
                this.statusLine = body.accepted ? '' : 'Waiting for a stronger GPS signal…';
            } catch {
                // Riders lose signal constantly. The watch keeps running and the
                // next fix will retry, so this is a status line, not an error.
                this.statusLine = 'Offline, will resend when you have signal.';
            }
        },

        onError(error) {
            if (error.code === error.PERMISSION_DENIED) {
                this.statusLine = 'Location permission denied. Enable it in your browser settings.';
                this.stop();
                return;
            }

            this.statusLine = 'Searching for GPS…';
        },

        async stop() {
            this.sharing = false;
            localStorage.removeItem('rider-sharing');
            this.statusLine = 'Turn this on when you start your run.';

            if (this.watchId !== null) {
                navigator.geolocation.clearWatch(this.watchId);
                this.watchId = null;
            }

            try {
                await fetch(this.stopUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                    },
                    // Dispatch must stop seeing this rider even if the tab is
                    // closing, so this is best-effort and never blocks the UI.
                    keepalive: true,
                });
            } catch {
                // Nothing to recover: the server ages them out on its own.
            }
        },
    });
</script>
