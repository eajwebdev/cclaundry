{{--
    New-booking alert for staff who handle pickup bookings.

    Polls the feed every few seconds rather than holding a socket open, so it
    works on any host with nothing extra to run. The last booking seen is kept
    per user in localStorage, so moving between pages neither misses a booking
    nor rings twice for one, and only one open tab rings for it.

    Browsers only let a page make sound after someone has clicked or typed on
    it. Staff do that constantly, so the first interaction unlocks the ringtone;
    until then the card offers a button to turn sound on.
--}}
<div
    x-data="bookingAlerts({
        feedUrl: @js(route('admin.pickup-requests.feed')),
        listUrl: @js(route('admin.pickup-requests.index', ['status' => 'pending'])),
        storageKey: @js('booking-alerts:'.auth()->id()),
    })"
    class="relative"
>
    <button
        type="button"
        @click="open = ! open; acknowledge()"
        class="relative flex h-9 w-9 items-center justify-center rounded-md border border-border bg-white transition hover:bg-smoke dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800"
        :class="unacknowledged.length && 'border-primary ring-2 ring-primary/30'"
        aria-label="Pickup booking alerts"
        title="Pickup bookings"
    >
        <span data-lucide="truck" class="h-4 w-4" :class="unacknowledged.length && 'text-primary'"></span>
        <span
            x-cloak
            x-show="pending > 0"
            x-text="pending > 99 ? '99+' : pending"
            class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white"
        ></span>
    </button>

    {{-- Dropdown: what arrived while this page was open, plus the sound switch. --}}
    <div
        x-cloak
        x-show="open"
        x-transition
        @click.outside="open = false"
        class="fixed inset-x-2 top-16 z-10 overflow-hidden rounded-md border border-border bg-white shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-80 dark:border-gray-800 dark:bg-gray-900"
    >
        <div class="flex items-center justify-between border-b border-border px-3 py-2 dark:border-gray-800">
            <p class="text-sm font-semibold">Pickup Bookings</p>
            <a :href="listUrl" class="text-xs font-medium text-primary hover:underline">
                <span x-text="pending ? pending + ' waiting' : 'Open'"></span>
            </a>
        </div>
        <div class="max-h-80 overflow-y-auto p-2">
            <template x-for="booking in recent" :key="booking.id">
                <a :href="booking.url" class="mb-1 block rounded-md border border-border px-3 py-2 text-sm last:mb-0 hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-800">
                    <p class="font-medium"><span x-text="booking.contact_name"></span> <span class="text-xs font-normal text-muted" x-text="booking.reference_no"></span></p>
                    <p class="mt-0.5 text-xs text-muted">
                        <span x-text="booking.pickup"></span><span x-show="booking.branch" x-text="' · ' + booking.branch"></span>
                    </p>
                </a>
            </template>
            <p x-show="! recent.length" class="px-3 py-6 text-center text-sm text-muted">New bookings will appear here, with a ring.</p>
        </div>
        <label class="flex cursor-pointer items-center justify-between gap-3 border-t border-border px-3 py-2 text-sm dark:border-gray-800">
            <span class="inline-flex items-center gap-2">
                <span data-lucide="sms" class="h-4 w-4 text-muted"></span>
                Ring for new bookings
            </span>
            <input type="checkbox" x-model="soundOn" @change="saveSound(); if (soundOn) unlockSound().then(() => ring(1))" class="rounded border-border text-primary">
        </label>
    </div>

    {{-- New bookings appear below the pickup icon until acknowledged. --}}
    <div
        x-cloak
        x-show="unacknowledged.length"
        x-transition
        role="alert"
        class="fixed inset-x-2 top-16 z-20 rounded-lg border-2 border-primary bg-white p-4 shadow-2xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 dark:bg-gray-900"
    >
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 animate-pulse items-center justify-center rounded-full bg-primary text-white">
                    <span data-lucide="truck" class="h-5 w-5"></span>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold" x-text="unacknowledged.length === 1 ? 'New pickup booking' : unacknowledged.length + ' new pickup bookings'"></p>
                    <template x-for="booking in unacknowledged.slice(0, 3)" :key="booking.id">
                        <p class="mt-0.5 truncate text-sm text-muted">
                            <span class="font-medium text-dark dark:text-white" x-text="booking.contact_name"></span>
                            · <span x-text="booking.pickup"></span><span x-show="booking.branch" x-text="' · ' + booking.branch"></span>
                        </p>
                    </template>
                    <button type="button" x-show="soundOn && soundBlocked" @click="unlockSound()"
                            class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">
                        <span data-lucide="sms" class="h-3.5 w-3.5"></span>
                        Tap to turn on the ringtone
                    </button>
                    <div class="mt-3 flex gap-2">
                        <a :href="unacknowledged.length === 1 ? unacknowledged[0].url : listUrl" @click="acknowledge()"
                           class="inline-flex h-8 items-center rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                            View booking<span x-show="unacknowledged.length > 1">s</span>
                        </a>
                        <button type="button" @click="acknowledge()"
                                class="inline-flex h-8 items-center rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('bookingAlerts', (config) => ({
            feedUrl: config.feedUrl,
            listUrl: config.listUrl,
            open: false,
            pending: 0,
            // Arrived while this page was open, newest first.
            recent: [],
            // Arrived and nobody has looked yet: drives the pop-up and the re-ring.
            unacknowledged: [],
            soundOn: true,
            soundBlocked: true,
            missedRing: false,
            audio: null,
            rings: 0,
            reringTimer: null,
            pollTimer: null,
            polling: false,
            stopped: false,
            baseTitle: document.title,

            // Seconds between checks, and between re-rings of an ignored alert.
            pollEvery: 5,
            reringEvery: 30,
            maxRerings: 5,

            init() {
                this.soundOn = this.read('sound') !== 'off';

                // The first click or key on the page is what browsers require
                // before a page may play sound.
                const unlock = () => this.unlockSound();
                window.addEventListener('pointerdown', unlock, { once: true, capture: true });
                window.addEventListener('keydown', unlock, { once: true, capture: true });

                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) clearTimeout(this.pollTimer);
                    else this.poll();
                });
                window.addEventListener('online', () => this.poll());

                this.poll();
            },

            schedulePoll() {
                clearTimeout(this.pollTimer);
                if (!document.hidden && !this.stopped) {
                    this.pollTimer = setTimeout(() => this.poll(), this.pollEvery * 1000);
                }
            },

            read(key) {
                try { return localStorage.getItem(config.storageKey + ':' + key); } catch { return null; }
            },

            write(key, value) {
                try { localStorage.setItem(config.storageKey + ':' + key, String(value)); } catch {}
            },

            saveSound() {
                this.write('sound', this.soundOn ? 'on' : 'off');
            },

            async poll() {
                if (this.polling || this.stopped || document.hidden) return;
                this.polling = true;
                try {
                    const lastSeen = Math.max(0, Number.parseInt(this.read('lastSeen') || '0', 10) || 0);
                    const response = await fetch(this.feedUrl + '?after=' + lastSeen, {
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    // Signed out or lost access: stop quietly rather than spin.
                    if (response.status === 401 || response.status === 403 || response.redirected) {
                        this.stopped = true;
                        return;
                    }

                    if (response.ok) {
                        const data = await response.json();
                        this.pending = data.pending;
                        this.recent = data.waiting || [];

                        // Another tab may have claimed these while we waited.
                        const stillUnseen = Math.max(0, Number.parseInt(this.read('lastSeen') || '0', 10) || 0);
                        const resetCursor = data.latest_id < stillUnseen;
                        const fresh = lastSeen > 0 && !resetCursor
                            ? data.bookings.filter((booking) => booking.id > stillUnseen)
                            : [...(data.waiting || [])].reverse();

                        const nextSeen = lastSeen > 0 && !resetCursor ? data.next_after : data.latest_id;
                        if (resetCursor || nextSeen > stillUnseen) this.write('lastSeen', nextSeen);
                        if (fresh.length) this.announce(fresh);
                    }
                } catch {
                    // Offline for a moment; the next check catches up.
                } finally {
                    this.polling = false;
                    this.schedulePoll();
                }
            },

            announce(bookings) {
                const newestFirst = [...bookings].reverse();
                this.unacknowledged = [...newestFirst, ...this.unacknowledged];
                this.open = false;
                document.title = '(' + this.unacknowledged.length + ') New booking · ' + this.baseTitle;
                this.$nextTick(() => window.renderLucideIcons?.());

                this.rings = 0;
                this.ring(3);
                clearInterval(this.reringTimer);
                this.reringTimer = setInterval(() => {
                    if (! this.unacknowledged.length || ++this.rings > this.maxRerings) {
                        clearInterval(this.reringTimer);
                        return;
                    }
                    this.ring(2);
                }, this.reringEvery * 1000);
            },

            acknowledge() {
                this.unacknowledged = [];
                document.title = this.baseTitle;
                clearInterval(this.reringTimer);
            },

            async unlockSound() {
                try {
                    this.audio = this.audio || new (window.AudioContext || window.webkitAudioContext)();
                    if (this.audio.state === 'suspended') await this.audio.resume();
                    this.soundBlocked = this.audio.state !== 'running';
                    if (!this.soundBlocked && this.missedRing) {
                        this.missedRing = false;
                        this.playChime(3);
                    }
                } catch {
                    this.soundBlocked = true;
                }
                return !this.soundBlocked;
            },

            /** A two-note door chime, built in the browser so no sound file is needed. */
            ring(times) {
                if (! this.soundOn) return;

                if (! this.audio || this.audio.state !== 'running') {
                    this.soundBlocked = true;
                    this.missedRing = true;
                    return;
                }

                this.playChime(times);
            },

            playChime(times) {
                const start = this.audio.currentTime + 0.05;
                for (let i = 0; i < times; i++) {
                    const at = start + i * 1.1;
                    this.tone(988, at, 0.45);
                    this.tone(784, at + 0.32, 0.7);
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
        }));
    });
</script>
@endpush
