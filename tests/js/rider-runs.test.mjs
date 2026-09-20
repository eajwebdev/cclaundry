import assert from 'node:assert/strict';
import test from 'node:test';

import installRiderRuns from '../../resources/js/rider-runs.js';

test('new pickups refresh To collect, chime once, and highlight for three seconds', async () => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const originalFetch = globalThis.fetch;
    const originalNow = Date.now;

    try {
        const timers = [];
        const classes = new Set();
        const card = {
            dataset: { riderCollectId: '2' },
            classList: { toggle: (name, active) => active ? classes.add(name) : classes.delete(name) },
        };

        globalThis.window = {
            location: { href: 'http://localhost/rider' },
            setTimeout: (callback, delay) => timers.push({ callback, delay }),
        };
        globalThis.document = { hidden: false, querySelector: () => null };

        installRiderRuns();
        const runs = window.riderRuns({ feedUrl: '/rider/runs', signature: 'first', collectIds: [1] });
        runs.$refs = { runs: { querySelectorAll: () => [card], querySelector: () => null } };
        runs.$nextTick = () => {};

        let now = 1000;
        Date.now = () => now;
        const applied = [];
        const chimes = [];
        runs.apply = (html) => applied.push(html);
        runs.ring = (times) => chimes.push(times);

        const replies = [
            { signature: 'second', html: '<article>New pickup</article>', collect_ids: [1, 2] },
            { signature: 'third', html: '<article>Delivery updated</article>', collect_ids: [1, 2] },
        ];
        globalThis.fetch = async () => ({ ok: true, json: async () => replies.shift() });

        await runs.check();
        assert.equal(applied.length, 1);
        assert.deepEqual(chimes, [3]);
        assert.equal(classes.has('rider-new-run'), true);
        assert.equal(timers[0].delay, 3000);

        await runs.check();
        assert.equal(applied.length, 2);
        assert.deepEqual(chimes, [3], 'a delivery update must not ring as a new pickup');

        now = 4000;
        timers[0].callback();
        assert.equal(classes.has('rider-new-run'), false);
    } finally {
        globalThis.window = originalWindow;
        globalThis.document = originalDocument;
        globalThis.fetch = originalFetch;
        Date.now = originalNow;
    }
});

test('the live feed keeps the selected date range while status cards change the visible tab', async () => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const originalFetch = globalThis.fetch;

    try {
        let requestedUrl;
        let selectedUrl;
        globalThis.window = {
            location: { href: 'http://localhost/rider?from=2026-09-20&to=2026-09-22' },
            history: { replaceState: (_state, _title, url) => { selectedUrl = url; } },
        };
        globalThis.document = { hidden: false, querySelector: () => null };
        globalThis.fetch = async (url) => {
            requestedUrl = url;
            return { ok: true, json: async () => ({ signature: 'same', html: null, range_from: '2026-09-20' }) };
        };

        installRiderRuns();
        const runs = window.riderRuns({
            feedUrl: '/rider/runs',
            signature: 'same',
            collectIds: [],
            activeTab: 'collect',
            rangeFrom: '2026-09-20',
            rangeTo: '2026-09-22',
            dateRangeValue: '2026-09-20 to 2026-09-22',
            defaultToday: false,
        });

        runs.selectTab('done');
        await runs.check();

        assert.equal(runs.activeTab, 'done');
        assert.equal(selectedUrl.searchParams.get('tab'), 'done');
        assert.equal(requestedUrl.searchParams.get('date_range'), '2026-09-20 to 2026-09-22');
        assert.equal(requestedUrl.searchParams.has('from'), false);
    } finally {
        globalThis.window = originalWindow;
        globalThis.document = originalDocument;
        globalThis.fetch = originalFetch;
    }
});

test('a dashboard left open overnight resets its default range to the new day', async () => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const originalFetch = globalThis.fetch;

    try {
        let reloaded = false;
        globalThis.window = {
            location: { href: 'http://localhost/rider', reload: () => { reloaded = true; } },
        };
        globalThis.document = { hidden: false, querySelector: () => null };
        globalThis.fetch = async () => ({
            ok: true,
            json: async () => ({ signature: 'new-day', html: '<div>Today</div>', range_from: '2026-09-21' }),
        });

        installRiderRuns();
        const runs = window.riderRuns({
            feedUrl: '/rider/runs',
            signature: 'previous-day',
            collectIds: [],
            rangeFrom: '2026-09-20',
            rangeTo: '2026-09-20',
            defaultToday: true,
        });

        await runs.check();
        assert.equal(reloaded, true);
        assert.equal(runs.loading, false);
    } finally {
        globalThis.window = originalWindow;
        globalThis.document = originalDocument;
        globalThis.fetch = originalFetch;
    }
});
