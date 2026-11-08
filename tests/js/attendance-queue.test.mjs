import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/attendance-queue.js', import.meta.url), 'utf8');

function queueWith(responses) {
    const context = {
        window: {},
        document: { querySelector: () => ({ content: 'csrf' }) },
        AbortSignal: { timeout: () => undefined },
        fetch: async (_url, options) => {
            const event = JSON.parse(options.body);
            const [status, body] = responses[event.action];
            return { ok: status < 300, status, json: async () => body };
        },
    };
    vm.runInNewContext(source, context);
    const queue = context.window.attendancePunchQueue(7, '/sync');
    const stored = new Map();
    // In-memory stand-in for IndexedDB.
    queue.write = async (action, data) => {
        if (action === 'list') return [...stored.values()];
        if (action === 'put') return stored.set(data.event_id, data);
        return stored.delete(data);
    };

    return { queue, stored };
}

test('a rejected punch is set aside and the punches after it still sync', async () => {
    const { queue, stored } = queueWith({
        in: [422, { errors: { action: ['You are already clocked in.'] } }],
        out: [200, { message: 'Clock out saved.' }],
    });
    stored.set('a', { event_id: 'a', employee_id: 7, action: 'in', captured_at: '2026-11-08T00:00:00Z' });
    stored.set('b', { event_id: 'b', employee_id: 7, action: 'out', captured_at: '2026-11-08T09:00:00Z' });

    const result = await queue.flush();

    assert.equal(result.pending, 0);
    assert.equal(result.rejected, true);
    assert.equal(result.data.message, 'Clock out saved.');
    assert.match(result.message, /already clocked in/);
    assert.deepEqual([...stored.keys()], ['a']);

    await queue.discardRejected();
    assert.equal(stored.size, 0);
});

test('a server or sign-in error keeps every punch for the next try', async () => {
    const { queue, stored } = queueWith({ in: [500, {}] });
    stored.set('a', { event_id: 'a', employee_id: 7, action: 'in', captured_at: '2026-11-08T00:00:00Z' });

    const result = await queue.flush();

    assert.equal(result.pending, 1);
    assert.equal(result.rejected, false);
    assert.ok(stored.get('a') && ! stored.get('a').rejected);
});
