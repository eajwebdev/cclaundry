import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/partials/booking-alerts.blade.php', import.meta.url), 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)?.[1];

test('the first waiting booking appears under the pickup icon and starts the alert', async () => {
    assert.ok(script);

    let register;
    let factory;
    let response;
    const storage = new Map();
    const timers = [];
    const requested = [];
    const context = {
        document: {
            title: 'Cashier POS',
            hidden: false,
            addEventListener: (name, callback) => {
                if (name === 'alpine:init') register = callback;
            },
        },
        window: { renderLucideIcons: () => {} },
        Alpine: { data: (_name, callback) => { factory = callback; } },
        localStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
        fetch: async (url, options) => {
            requested.push({ url, options });
            return { ok: true, status: 200, redirected: false, json: async () => response };
        },
        setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length; },
        clearTimeout: () => {},
        setInterval: () => 1,
        clearInterval: () => {},
    };

    vm.runInNewContext(script, context);
    register();
    const alerts = factory({ feedUrl: '/admin/pickup-requests/feed', listUrl: '/admin/pickup-requests', storageKey: 'cashier' });
    alerts.$nextTick = (callback) => callback();
    const chimes = [];
    alerts.ring = (times) => chimes.push(times);

    const booking = { id: 1, reference_no: 'PU-1', contact_name: 'Customer', pickup: 'Sep 21', url: '/booking/1' };
    response = { latest_id: 1, next_after: 1, pending: 1, bookings: [], waiting: [booking] };
    await alerts.poll();

    assert.equal(requested[0].url, '/admin/pickup-requests/feed?after=0');
    assert.equal(requested[0].options.cache, 'no-store');
    assert.equal(alerts.pending, 1);
    assert.equal(alerts.recent[0].reference_no, 'PU-1');
    assert.equal(alerts.unacknowledged[0].reference_no, 'PU-1');
    assert.deepEqual(chimes, [3]);
    assert.equal(timers[0].delay, 5000);

    await alerts.poll();
    assert.equal(requested[1].url, '/admin/pickup-requests/feed?after=1');
    assert.deepEqual(chimes, [3], 'the same booking should not ring twice');

    const nextBooking = { ...booking, id: 2, reference_no: 'PU-2' };
    response = { latest_id: 2, next_after: 2, pending: 2, bookings: [nextBooking], waiting: [nextBooking, booking] };
    await alerts.poll();
    assert.equal(alerts.recent[0].reference_no, 'PU-2');
    assert.equal(alerts.unacknowledged[0].reference_no, 'PU-2');
    assert.deepEqual(chimes, [3, 3]);
});
