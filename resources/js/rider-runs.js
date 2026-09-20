/**
 * Keeps the rider's run list current without them thinking to refresh.
 *
 * Polling rather than a socket on purpose: the rest of this app is built to
 * run on shared hosting with no long-lived daemon, and a rider on mobile data
 * is better served by a small request they can miss than by a connection that
 * silently dies in a dead spot.
 *
 * The server sends back the same markup the page was rendered with, so there
 * is one description of how a run looks rather than two that drift apart.
 */
export default function installRiderRuns() {
    window.riderRuns = (config) => ({
        signature: config.signature,
        activeTab: config.activeTab ?? 'collect',
        rangeFrom: config.rangeFrom ?? '',
        rangeTo: config.rangeTo ?? '',
        dateRange: config.dateRangeValue ?? '',
        timer: null,
        loading: false,
        offline: false,
        screenReaderMessage: '',
        seenCollectIds: new Set((config.collectIds ?? []).map(String)),
        highlightedUntil: new Map(),
        audio: null,
        soundBlocked: true,
        missedChime: false,

        start() {
            this.schedule();
            this.$nextTick(() => this.check());
            this.$nextTick(() => this.initDatePicker());

            // Browsers permit sound only after a gesture. The first gesture
            // prepares the same two-note chime used for admin bookings.
            if (!window.riderBookingAlertsActive) {
                const unlock = () => this.unlockSound();
                window.addEventListener('pointerdown', unlock, { once: true, capture: true });
                window.addEventListener('keydown', unlock, { once: true, capture: true });
            }

            // Coming back to the app is the moment a stale list is most
            // obvious, so check then rather than waiting for the next tick.
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.stop();
                    return;
                }

                this.schedule();
                this.check();
            });

            window.addEventListener('online', () => this.check());
        },

        initDatePicker() {
            if (!window.flatpickr || !this.$refs.dateRange) return;
            window.flatpickr(this.$refs.dateRange, {
                mode: 'range',
                dateFormat: 'Y-m-d',
                disableMobile: true,
                defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                onChange: (_dates, value) => { this.dateRange = value; },
            });
        },

        schedule() {
            this.stop();
            this.timer = window.setInterval(() => this.check(), config.everyMs ?? 5000);
        },

        stop() {
            if (this.timer) window.clearInterval(this.timer);
            this.timer = null;
        },

        selectTab(tab) {
            this.activeTab = tab;
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState(null, '', url);
        },

        clearDateRange() {
            const url = new URL(config.indexUrl, window.location.href);
            url.searchParams.set('tab', this.activeTab);
            window.location.assign(url);
        },

        async check() {
            if (this.loading || document.hidden) return;

            // Never pull the list out from under a confirmation the rider is
            // reading, or a form they have already submitted.
            if (document.querySelector('.swal2-container')) return;

            this.loading = true;

            try {
                const url = new URL(config.feedUrl, window.location.href);
                url.searchParams.set('signature', this.signature);
                if (config.dateRangeValue) {
                    url.searchParams.set('date_range', config.dateRangeValue);
                }
                const response = await fetch(url, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                });

                if (response.ok) {
                    const body = await response.json();
                    this.offline = false;

                    // An overnight dashboard still defaults to the new day.
                    if (config.defaultToday && body.range_from !== config.rangeFrom) {
                        this.loading = false;
                        window.location.reload();
                        return;
                    }

                    if (body.signature !== this.signature && body.html) {
                        const collectIds = body.collect_ids ?? [];
                        const fresh = collectIds.filter((id) => !this.seenCollectIds.has(String(id)));

                        this.apply(body.html);
                        this.signature = body.signature;
                        collectIds.forEach((id) => this.seenCollectIds.add(String(id)));

                        if (fresh.length) {
                            this.flash(fresh);
                            this.screenReaderMessage = fresh.length === 1
                                ? 'New pickup in To collect.'
                                : `${fresh.length} new pickups in To collect.`;
                            if (!window.riderBookingAlertsActive) this.ring(3);
                        }
                    }
                } else {
                    this.offline = true;
                }
            } catch {
                // A dead spot is not an error worth shouting about; the list
                // already on screen is still the best the rider has.
                this.offline = true;
            }

            this.loading = false;
        },

        apply(html) {
            const container = this.$refs.runs;

            if (!container) return;

            const focusedTab = container.contains(document.activeElement)
                ? document.activeElement?.dataset.riderTab
                : null;
            container.innerHTML = html;
            if (focusedTab) container.querySelector(`[data-rider-tab="${focusedTab}"]`)?.focus();
            this.paintHighlights();

            // Alpine picks up the new nodes through its own observer; the
            // icons are drawn once per page and need asking again.
            this.$nextTick(() => window.renderLucideIcons?.());
        },

        flash(ids) {
            const expires = Date.now() + 3000;
            ids.forEach((id) => this.highlightedUntil.set(String(id), expires));
            this.paintHighlights();
            window.setTimeout(() => this.paintHighlights(), 3000);
        },

        paintHighlights() {
            const now = Date.now();
            for (const [id, expires] of this.highlightedUntil) {
                if (expires <= now) this.highlightedUntil.delete(id);
            }

            this.$refs.runs?.querySelectorAll('[data-rider-collect-id]').forEach((card) => {
                const expires = this.highlightedUntil.get(card.dataset.riderCollectId) ?? 0;
                card.classList.toggle('rider-new-run', expires > now);
            });

            const summary = this.$refs.runs?.querySelector('[data-rider-collect-summary]');
            summary?.classList.toggle('rider-new-run', this.highlightedUntil.size > 0);
        },

        async unlockSound() {
            try {
                const Audio = window.AudioContext || window.webkitAudioContext;
                if (!Audio) throw new Error('Audio unavailable');
                this.audio ??= new Audio();
                if (this.audio.state !== 'running') await this.audio.resume();
                this.soundBlocked = this.audio.state !== 'running';

                if (!this.soundBlocked && this.missedChime) {
                    this.missedChime = false;
                    this.playChime(3);
                }

                return !this.soundBlocked;
            } catch {
                this.soundBlocked = true;
                return false;
            }
        },

        async enableSound() {
            await this.unlockSound();
        },

        async ring(times) {
            this.missedChime = false;
            if (!await this.unlockSound()) {
                this.missedChime = true;
                return;
            }

            this.playChime(times);
        },

        playChime(times) {
            if (this.audio?.state !== 'running') return;

            try {
                const start = this.audio.currentTime + 0.05;
                for (let i = 0; i < times; i++) {
                    const at = start + i * 1.1;
                    this.tone(988, at, 0.45);
                    this.tone(784, at + 0.32, 0.7);
                }
            } catch {
                this.soundBlocked = true;
                this.missedChime = true;
            }
        },

        tone(frequency, at, length) {
            const oscillator = this.audio.createOscillator();
            const gain = this.audio.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(0.5, at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + length);

            oscillator.connect(gain).connect(this.audio.destination);
            oscillator.start(at);
            oscillator.stop(at + length + 0.05);
        },
    });
}
