// Owner dashboard pages: fetch the page's endpoint with the server-validated filter, then render every widget.
// A failed fetch (or render) shows one inline error with Retry and hides the widgets, so nothing is half-drawn.
import { setupCalendar, destroyCharts } from './owner-dashboard/core';
import renderOverview from './owner-dashboard/overview';
import renderSupplies from './owner-dashboard/supplies';
import renderProfit from './owner-dashboard/profit';

const RENDER = { overview: renderOverview, supplies: renderSupplies, profit: renderProfit };

function start() {
    const root = document.getElementById('owner-dashboard');
    if (!root) return;
    const render = RENDER[root.dataset.page];
    const widgets = root.querySelector('#od-widgets');
    const error = root.querySelector('#od-error');
    const query = new URLSearchParams({ from: root.dataset.from, to: root.dataset.to, branch: root.dataset.branch });

    const fail = () => {
        destroyCharts();
        widgets.hidden = true;
        error.hidden = false;
    };

    const retry = root.querySelector('#od-retry');

    // On a retry the widgets stay hidden (the button shows the loading state) until a render succeeds.
    const load = async () => {
        if (retry) { retry.disabled = true; retry.textContent = 'Loading…'; }
        try {
            const response = await fetch(`${root.dataset.endpoint}?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            widgets.hidden = false; // shown before drawing so charts measure a visible box; a throw hides it again at once
            render(root, data);
            error.hidden = true;
        } catch (e) {
            console.error('Owner dashboard failed to load', e);
            fail();
        } finally {
            if (retry) { retry.disabled = false; retry.textContent = 'Retry'; }
        }
    };

    retry?.addEventListener('click', load);
    setupCalendar(root);
    if (render) load();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
