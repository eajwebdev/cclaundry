/** Pickup alerts shared by every rider screen, independent of the date filter. */
export default function installRiderBookingAlerts() {
    window.riderBookingAlerts = (config) => ({
        open: false,
        count: 0,
        waiting: [],
        unacknowledged: [],
        soundBlocked: true,
        missedChime: false,
        audio: null,
        polling: false,
        stopped: false,
        timer: null,
        baseTitle: document.title,
        feedUrl: config.feedUrl,
        listUrl: config.listUrl,

        init() {
            // The run list has its own visual flash. This header handles its sound.
            window.riderBookingAlertsActive = true;
            const unlock = () => this.unlockSound();
            window.addEventListener('pointerdown', unlock, { once: true, capture: true });
            window.addEventListener('keydown', unlock, { once: true, capture: true });
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) window.clearTimeout(this.timer);
                else this.poll();
            });
            window.addEventListener('online', () => this.poll());
            this.poll();
        },

        readCursor() {
            try { return Math.max(0, Number.parseInt(localStorage.getItem(config.storageKey) || '0', 10) || 0); }
            catch { return 0; }
        },

        saveCursor(id) {
            try { localStorage.setItem(config.storageKey, String(id)); } catch { /* Private browsing. */ }
        },

        schedule() {
            window.clearTimeout(this.timer);
            if (!document.hidden && !this.stopped) this.timer = window.setTimeout(() => this.poll(), 5000);
        },

        async poll() {
            if (this.polling || this.stopped || document.hidden) return;
            this.polling = true;
            try {
                const cursor = this.readCursor();
                const url = new URL(this.feedUrl, window.location.href);
                url.searchParams.set('after', cursor);
                const response = await fetch(url, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                if (response.status === 401 || response.status === 403 || response.redirected) {
                    this.stopped = true;
                    return;
                }
                if (!response.ok) return;

                const data = await response.json();
                this.count = data.count ?? 0;
                this.waiting = data.waiting ?? [];
                const current = this.readCursor();
                const reset = data.latest_id < current;
                const fresh = cursor && !reset
                    ? (data.bookings ?? []).filter((booking) => booking.id > current)
                    : [...this.waiting].reverse();
                const next = cursor && !reset ? data.next_after : data.latest_id;
                if (reset || next > current) this.saveCursor(next);
                if (fresh.length) this.announce(fresh);
            } catch {
                // The next poll catches up after a mobile connection recovers.
            } finally {
                this.polling = false;
                this.schedule();
            }
        },

        announce(bookings) {
            this.unacknowledged = [...bookings].reverse().concat(this.unacknowledged);
            this.open = false;
            document.title = `(${this.unacknowledged.length}) New pickup · ${this.baseTitle}`;
            this.$nextTick(() => window.renderLucideIcons?.());
            this.ring();
        },

        acknowledge() {
            this.unacknowledged = [];
            document.title = this.baseTitle;
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
                    this.playChime();
                }
            } catch { this.soundBlocked = true; }
            return !this.soundBlocked;
        },

        ring() {
            if (!this.audio || this.audio.state !== 'running') {
                this.soundBlocked = true;
                this.missedChime = true;
                return;
            }
            this.playChime();
        },

        playChime() {
            try {
                const start = this.audio.currentTime + 0.05;
                for (let i = 0; i < 3; i++) {
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
