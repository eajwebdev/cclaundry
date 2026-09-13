/**
 * A rider's taps, kept safe until the network takes them.
 *
 * Marking a load collected happens at somebody's gate, which is exactly where
 * the signal goes. A plain form post there loses the bag tag and the amount
 * and drops the rider on a browser error page, so those actions go through
 * here instead: written to the phone first, sent when there is a network, and
 * retried until the server has them.
 *
 * Every entry carries a token the server remembers, so a resend of something
 * that did arrive is answered "already done" rather than applied twice.
 */

const KEY = 'rider-outbox-v1';
const REJECTED_KEY = 'rider-outbox-rejected-v1';

const read = (key) => {
    try {
        return JSON.parse(window.localStorage.getItem(key) || '[]');
    } catch {
        // Private mode, cleared storage, a corrupt value: an empty list is
        // the safe reading, and the rider is told nothing is pending.
        return [];
    }
};

const write = (key, entries) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(entries));
    } catch {
        // Nothing sensible to do; the entry stays in memory for this attempt.
    }
};

const token = () =>
    (window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`);

const BRAND = '#A07148';

// Tokens being sent right now. Outside the store on purpose: it is plain
// bookkeeping, and the background flush must not pick up an entry that the
// tap the rider just made is already sending.
const inFlight = new Set();

/**
 * Registered as an Alpine store rather than a component: the queue belongs to
 * the phone, not to one screen, so it has to outlive whichever page is open
 * and be reachable from any of them as `$store.outbox`.
 */
export default function riderOutboxStore() {
    return {
        pending: read(KEY),

        // Queued taps the server later refused while the rider was elsewhere:
        // a claim somebody else won, a run that moved on. Kept until the rider
        // has seen them, because a queued tap that quietly vanishes is worse
        // than one that never sent.
        rejected: read(REJECTED_KEY),

        sending: false,

        init() {
            // Anything left from a previous visit goes out as soon as we load.
            this.flush();

            window.addEventListener('online', () => this.flush());
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.flush();
            });

            // A phone can report itself online while still behind a captive
            // portal or a dead cell, so keep trying rather than trusting it.
            window.setInterval(() => this.flush(), 15000);
        },

        get count() {
            return this.pending.length;
        },

        /**
         * One rider action, start to finish: send it, and then do the right
         * thing with whichever of the three outcomes comes back.
         *
         *   accepted  hand the confirmation to the next page and go there
         *   refused   stay put, so nothing the rider typed is thrown away
         *   no signal keep it, say so plainly, carry on to the run list
         */
        async perform({ url, fields, describe, fallback }) {
            const outcome = await this.submit({ url, fields, describe });

            if (outcome.sent && !outcome.ok) {
                await window.Swal.fire({
                    title: 'Not saved',
                    text: outcome.message,
                    icon: 'error',
                    confirmButtonColor: BRAND,
                });

                return outcome;
            }

            if (outcome.sent) {
                // No server redirect to hang a flash on, so pass it along.
                try { window.sessionStorage.setItem('rider-flash', outcome.message); } catch { /* quiet */ }
                window.location = outcome.redirect || fallback;

                return outcome;
            }

            await window.Swal.fire({
                title: 'Saved on your phone',
                text: outcome.message,
                icon: 'success',
                confirmButtonColor: BRAND,
            });

            window.location = fallback;

            return outcome;
        },

        /**
         * Queue one action and try it immediately.
         *
         * @returns {Promise<{sent: boolean, ok?: boolean, message: string, redirect?: string}>}
         */
        async submit({ url, fields, describe }) {
            const entry = {
                token: token(),
                url,
                fields,
                describe,
                queuedAt: Date.now(),
            };

            this.pending = [...this.pending, entry];
            write(KEY, this.pending);

            const result = await this.send(entry);

            // Answered here and now, so it is shown here and now: it must not
            // also land in the background-refusal list.
            if (result.sent) return result;

            return { sent: false, message: 'Saved on your phone. It will send itself once you have signal.' };
        },

        async send(entry) {
            if (inFlight.has(entry.token)) return { sent: false, busy: true };

            inFlight.add(entry.token);

            try {
                return await this.post(entry);
            } finally {
                inFlight.delete(entry.token);
            }
        },

        async post(entry) {
            const body = new FormData();

            Object.entries(entry.fields).forEach(([name, value]) => {
                if (value !== null && value !== undefined) body.append(name, value);
            });

            body.append('client_token', entry.token);
            body.append('_token', document.querySelector('meta[name=csrf-token]')?.content ?? '');

            try {
                const response = await fetch(entry.url, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body,
                });

                // A refusal is final: the job moved on, somebody else took it,
                // the tag is in use. Retrying forever would just hide it.
                if ([403, 419, 422].includes(response.status)) {
                    const payload = await response.json().catch(() => ({}));
                    this.drop(entry.token);

                    return {
                        sent: true,
                        ok: false,
                        message: payload.message || refusalFor(response.status),
                    };
                }

                if (!response.ok) return { sent: false };

                const payload = await response.json().catch(() => ({}));
                this.drop(entry.token);

                return { sent: true, ok: true, message: payload.message || 'Saved.', redirect: payload.redirect };
            } catch {
                return { sent: false };
            }
        },

        drop(entryToken) {
            this.pending = this.pending.filter((item) => item.token !== entryToken);
            write(KEY, this.pending);
        },

        dismissRejected() {
            this.rejected = [];
            write(REJECTED_KEY, this.rejected);
        },

        async flush() {
            if (this.sending || !this.pending.length) return;

            this.sending = true;

            // One at a time and in order: two taps on the same run must not
            // race each other to the server.
            for (const entry of [...this.pending]) {
                const result = await this.send(entry);

                if (!result.sent) break;

                // Nobody is looking at a dialog for this one, so keep the
                // refusal where the header can show it.
                if (!result.ok) {
                    this.rejected = [...this.rejected, { describe: entry.describe, message: result.message }];
                    write(REJECTED_KEY, this.rejected);
                }
            }

            this.sending = false;
        },
    };
}

function refusalFor(status) {
    if (status === 419) return 'Your session expired. Sign in again and redo this.';
    if (status === 403) return 'This run is no longer yours to change.';

    return 'That could not be saved.';
}
