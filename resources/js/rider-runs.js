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
        timer: null,
        loading: false,
        offline: false,
        newRunMessage: '',
        availableIds: config.availableIds ?? [],
        assignedIds: config.assignedIds ?? [],

        start() {
            this.schedule();
            this.$nextTick(() => this.check());

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

        schedule() {
            this.stop();
            this.timer = window.setInterval(() => this.check(), config.everyMs ?? 10000);
        },

        stop() {
            if (this.timer) window.clearInterval(this.timer);
            this.timer = null;
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
                const response = await fetch(url, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                });

                if (response.ok) {
                    const body = await response.json();
                    this.offline = false;

                    const availableIds = body.available_ids ?? [];
                    const assignedIds = body.assigned_ids ?? [];
                    const newAssigned = assignedIds.some((id) => !this.assignedIds.includes(id));
                    const newAvailable = availableIds.some((id) => !this.availableIds.includes(id));
                    this.availableIds = availableIds;
                    this.assignedIds = assignedIds;

                    if (body.signature !== this.signature) {
                        this.signature = body.signature;
                        if (body.html) this.apply(body.html);
                    }
                    if (newAssigned || newAvailable) {
                        this.newRunMessage = newAssigned
                            ? 'A new run was assigned to you. It is in your list below.'
                            : 'A new pickup is available to claim below.';
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

            container.innerHTML = html;

            // Alpine picks up the new nodes through its own observer; the
            // icons are drawn once per page and need asking again.
            this.$nextTick(() => window.renderLucideIcons?.());
        },
    });
}
