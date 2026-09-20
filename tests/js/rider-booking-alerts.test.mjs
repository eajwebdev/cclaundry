import assert from 'node:assert/strict';
import test from 'node:test';

import installRiderBookingAlerts from '../../resources/js/rider-booking-alerts.js';

test('rider header alerts on future pickups on every five-second check', async () => {
    const old = {
        window: globalThis.window,
        document: globalThis.document,
        localStorage: globalThis.localStorage,
        fetch: globalThis.fetch,
    };

    try {
        const stored = new Map();
        const timers = [];
        const requests = [];
        const first = { id: 4, contact_name: 'Ana', pickup: 'Oct 1, 2026 · 8:00 AM', url: '/rider/jobs/4' };
        const future = { id: 5, contact_name: 'Ben', pickup: 'Nov 1, 2026 · 8:00 AM', url: '/rider/jobs/5' };
        const replies = [
            { latest_id: 4, next_after: 4, count: 1, waiting: [first], bookings: [] },
            { latest_id: 5, next_after: 5, count: 2, waiting: [future, first], bookings: [future] },
            { latest_id: 5, next_after: 5, count: 2, waiting: [future, first], bookings: [] },
        ];
        globalThis.window = {
            location: { href: 'http://localhost/rider/map?date_range=2026-09-20' },
            setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length; },
            clearTimeout: () => {},
            renderLucideIcons: () => {},
        };
        globalThis.document = { hidden: false, title: 'Rider map' };
        globalThis.localStorage = {
            getItem: (key) => stored.get(key) ?? null,
            setItem: (key, value) => stored.set(key, value),
        };
        globalThis.fetch = async (url) => {
            requests.push(url);
            return { ok: true, status: 200, redirected: false, json: async () => replies.shift() };
        };

        installRiderBookingAlerts();
        const alerts = window.riderBookingAlerts({
            feedUrl: '/rider/booking-alerts', listUrl: '/rider', storageKey: 'rider:1',
        });
        alerts.$nextTick = (callback) => callback();
        const chimes = [];
        alerts.ring = () => chimes.push('ring');

        await alerts.poll();
        assert.equal(alerts.unacknowledged[0].id, 4);
        assert.equal(timers.at(-1).delay, 5000);
        assert.equal(stored.get('rider:1'), '4');
        alerts.acknowledge();

        await alerts.poll();
        assert.equal(alerts.unacknowledged[0].id, 5);
        assert.equal(alerts.count, 2);
        assert.equal(chimes.length, 2);
        assert.equal(stored.get('rider:1'), '5');

        alerts.acknowledge();
        await alerts.poll();
        assert.equal(alerts.unacknowledged.length, 0);
        assert.equal(chimes.length, 2);
        assert.equal(requests[1].searchParams.get('after'), '4');
        assert.equal(requests[1].searchParams.has('date_range'), false);
    } finally {
        globalThis.window = old.window;
        globalThis.document = old.document;
        globalThis.localStorage = old.localStorage;
        globalThis.fetch = old.fetch;
    }
});
