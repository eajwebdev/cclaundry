import { h, peso, pesoCents, num, qty, pct, shortDate, list, empty, card, tile, bar, maxOf, note, fill, setSub, drawChart } from './core';

const METHOD = { cash: ['Cash', '#8a5a35'], gcash: ['GCash', '#2f7fc1'], bank: ['Bank', '#4b5563'] };

// A difference under ₱0.50 would read "₱0 short", so it is shown to the centavo.
const money = (value) => (Math.round(Math.abs(Number(value) || 0)) === 0 ? pesoCents(Math.abs(value)) : peso(Math.abs(value)));

const okOr = (difference) => (Number(difference) === 0
    ? h('span', { class: 'text-green-700 dark:text-green-400' }, '✓ OK')
    : h('span', { class: 'rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200' },
        `${money(difference)} ${Number(difference) < 0 ? 'short' : 'over'}`));

const signed = (difference) => (Number(difference) === 0 ? '' : ` ${Number(difference) > 0 ? '+' : ''}${num(difference, 0)}`);

const statusChip = (status) => (status === 'balanced'
    ? h('span', { class: 'rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800 dark:bg-green-950 dark:text-green-200' }, 'Balanced')
    : h('span', { class: 'rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200' }, 'With a difference'));

function kpis(root, d) {
    const k = d.kpis || {};
    const profitUrl = root.querySelector('[data-od="kpis"]')?.dataset.profitUrl;
    fill(root, 'kpis',
        tile('Sales', peso(k.sales), `avg ${peso(k.sales_per_day)} a day`),
        tile('Orders', num(k.orders, 0), `avg ${peso(k.avg_order)} per order · ${num(k.orders_per_day, 1)} a day`),
        tile('Kilos washed', qty(k.kg_washed, 'kg', 0), `${num(k.kg_per_order, 1)} kg per order · ${num(k.kg_per_day, 0)} kg a day`),
        card('border-gray-900 bg-gray-900 text-white dark:border-gray-700 dark:bg-black',
            h('div', { class: 'text-xs font-medium text-gray-300' }, 'Profit'),
            h('div', { class: `mt-1 text-2xl font-bold tracking-tight sm:text-3xl ${Number(k.profit) < 0 ? 'text-red-300' : 'text-green-300'}`, style: { fontFamily: "'Playfair Display', Georgia, serif" } }, peso(k.profit)),
            h('div', { class: 'mt-1 text-xs text-gray-300' }, `${pct(k.profit_pct)} of sales, after staff, bills & supplies`),
            profitUrl ? h('a', { href: profitUrl, class: 'mt-1 inline-block text-xs font-semibold underline' }, 'See the breakdown →') : null));
}

function payments(root, d) {
    const methods = list(d.payments?.methods);
    const split = h('div', { class: 'flex h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800' },
        methods.map((m) => h('div', { style: { width: `${Math.max(0, Number(m.pct) || 0)}%`, background: (METHOD[m.method] || [])[1] } })));
    fill(root, 'payments',
        Number(d.payments?.total) ? [split,
            h('ul', { class: 'mt-3 space-y-1.5 text-sm' }, methods.map((m) => h('li', { class: 'flex items-center justify-between gap-2' },
                h('span', { class: 'flex items-center gap-2' }, h('span', { class: 'h-2.5 w-2.5 rounded-sm', style: { background: (METHOD[m.method] || [])[1] } }), (METHOD[m.method] || [m.method])[0]),
                h('span', {}, h('strong', {}, peso(m.amount)), h('span', { class: 'text-muted' }, ` · ${pct(m.pct)}`)))))] : empty(),
        h('div', { class: 'mt-4 flex items-center justify-between border-t border-border pt-3 dark:border-gray-800' },
            h('span', { class: 'text-sm font-semibold text-amber-800 dark:text-amber-400' }, 'Still unpaid by customers'),
            h('strong', { class: 'text-lg text-amber-800 dark:text-amber-400' }, peso(d.unpaid))),
        h('p', { class: 'text-xs text-muted' }, 'Orders from this period not fully paid yet'));
}

function intake(root, d) {
    const i = d.intake || {};
    if (!Number(d.kpis?.orders)) { fill(root, 'intake', empty()); return; }
    const box = (icon, label, data, sub, tint) => card(`border-transparent ${tint}`,
        h('div', { class: 'flex items-center gap-2 text-sm font-semibold' }, h('span', { 'data-lucide': icon, class: 'h-4 w-4' }), label),
        h('div', { class: 'mt-2' }, h('span', { class: 'text-2xl font-bold' }, num(data?.count, 0)), h('span', { class: 'ml-1 text-sm text-muted' }, pct(data?.pct))),
        h('div', { class: 'text-xs text-muted' }, sub));
    const warm = 'bg-orange-50 dark:bg-orange-950/40';
    const cool = 'bg-sky-50 dark:bg-sky-950/40';
    const group = (title, ...boxes) => h('div', {}, h('div', { class: 'mb-2 text-[11px] font-bold uppercase tracking-wide text-muted' }, title), h('div', { class: 'grid grid-cols-2 gap-2' }, boxes));
    fill(root, 'intake',
        h('div', { class: 'mb-3 flex justify-end' }, h('span', { class: 'rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-900 dark:bg-sky-950 dark:text-sky-200' }, `${num(i.ebike_trips, 0)} e-bike trips`)),
        h('div', { class: 'grid grid-cols-1 gap-4 sm:grid-cols-2' },
            group('Came in', box('footprints', 'Walk-in drop-off', i.walk_in, 'brought to the shop', warm), box('bike', 'E-bike pickup', i.house_pickup, 'collected at home', cool)),
            group('Went back', box('store', 'Picked up at shop', i.shop_pickup, 'customer collected', warm), box('bike', 'E-bike delivery', i.delivery, 'delivered home', cool))));
    window.renderLucideIcons?.();
}

function machinesCell(machines) {
    const off = list(machines).filter((m) => m.ok === false);
    return off.length
        ? h('span', { class: 'rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200' },
            off.map((m) => `${m.label}${signed(m.difference)}`).join(', '))
        : h('span', { class: 'text-green-700 dark:text-green-400' }, '✓ OK');
}

function closingsTable(rows, showBranch) {
    const th = (t) => h('th', { class: 'px-2 py-2 text-left text-[11px] font-bold uppercase tracking-wide text-muted' }, t);
    const td = (...c) => h('td', { class: 'px-2 py-2 align-top' }, ...c);
    return h('div', { class: 'overflow-x-auto' }, h('table', { class: 'w-full min-w-[720px] text-sm' },
        h('thead', {}, h('tr', { class: 'border-b border-border dark:border-gray-800' }, ['Closing', 'Closed by', 'Shop Cash', 'GCash', 'Machine counters', 'What to check'].map(th))),
        h('tbody', {}, rows.map((r) => h('tr', { class: 'border-b border-border last:border-0 dark:border-gray-800' },
            td(h('strong', {}, shortDate(r.date)), showBranch && r.branch ? h('div', { class: 'text-xs text-muted' }, r.branch) : null),
            td(r.closed_by ?? '—'),
            td(okOr(r.shop_cash?.difference)),
            td(okOr(r.gcash?.difference)),
            td(machinesCell(r.machines)),
            td(h('span', { class: 'text-xs text-muted' }, r.what_to_check || '')))))));
}

function allClosingsList(rows, showBranch) {
    if (!rows.length) return empty();
    return h('ul', { class: 'divide-y divide-border text-sm dark:divide-gray-800' }, rows.map((r) => h('li', { class: 'flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:gap-4' },
        h('strong', { class: 'sm:w-28' }, shortDate(r.date)),
        showBranch && r.branch ? h('span', { class: 'text-xs text-muted sm:w-32' }, r.branch) : null,
        h('span', { class: 'sm:w-32' }, r.closed_by ?? '—'),
        statusChip(r.status),
        h('span', { class: 'text-xs text-muted sm:flex-1' }, r.what_to_check || ''))));
}

function closings(root, d) {
    const c = d.closings || {};
    const s = c.summary || {};
    const showBranch = d.period?.branch === 'all';
    const rangeEl = root.querySelector('[data-od="closings"]');
    const fullEl = root.querySelector('[data-od="closing-full"]');
    const single = Array.isArray(c.full);
    if (rangeEl) rangeEl.hidden = single;
    if (fullEl) fullEl.hidden = !single;

    if (single) {
        fill(root, 'closing-full', c.full.length ? c.full.map((v) => fullClosing(v, showBranch)) : empty());
        return;
    }
    if (!s.count) {
        fill(root, 'closings', empty());
        return;
    }

    const small = (label, value, sub, tint) => card(`border-transparent ${tint}`,
        h('div', { class: 'text-xs font-medium' }, label), h('div', { class: 'mt-1 text-xl font-bold' }, value), sub ? h('div', { class: 'text-xs text-muted' }, sub) : null);
    const unbalanced = list(c.unbalanced);
    const body = h('div', {}, unbalanced.length ? closingsTable(unbalanced, showBranch) : h('p', { class: 'py-3 text-sm text-muted' }, 'No closings with a difference.'));
    const footerText = h('span', {}, `Showing only closings with a difference · ${num(s.balanced, 0)} balanced closings hidden`);
    const toggle = h('button', { type: 'button', class: 'text-xs font-semibold text-amber-800 underline dark:text-amber-400' }, `Show all ${num(s.count, 0)} closings →`);
    toggle.addEventListener('click', () => {
        body.replaceChildren(allClosingsList(list(c.all), showBranch));
        toggle.remove();
        footerText.textContent = 'Showing every closing';
    });

    fill(root, 'closings',
        h('div', { class: 'grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6' },
            small('Closings', num(s.count, 0), 'one per day open', 'bg-smoke dark:bg-gray-950'),
            small('Balanced', num(s.balanced, 0), 'everything matched', 'bg-green-50 dark:bg-green-950/40'),
            small('With a difference', num(s.with_difference, 0), 'listed below', 'bg-red-50 dark:bg-red-950/40'),
            small('Cash over / short', peso(s.cash_over_short), 'total of all closings',
                Number(s.cash_over_short) < 0 ? 'bg-red-50 dark:bg-red-950/40' : Number(s.cash_over_short) > 0 ? 'bg-green-50 dark:bg-green-950/40' : 'bg-smoke dark:bg-gray-950'),
            small('Cash deposited', peso(s.cash_deposited), 'sealed envelopes to bank', 'bg-sky-50 dark:bg-sky-950/40'),
            small('GCash sent to bank', peso(s.gcash_sent_to_bank), null, 'bg-sky-50 dark:bg-sky-950/40')),
        h('div', { class: 'mt-3' }, body),
        h('div', { class: 'mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-muted' }, footerText, toggle));
}

function fullClosing(v, showBranch) {
    const line = (label, value) => h('div', { class: 'flex justify-between gap-2 text-sm' }, h('span', { class: 'text-muted' }, label), h('strong', {}, value));
    const pair = (title, p) => card('border-border dark:border-gray-800',
        h('div', { class: 'mb-2 text-sm font-semibold' }, title),
        line('Expected', peso(p?.expected)), line('Counted', peso(p?.counted)),
        h('div', { class: 'mt-1 flex justify-between gap-2 text-sm' }, h('span', { class: 'text-muted' }, 'Difference'), okOr(p?.difference)));
    const machines = list(v.machines);
    return h('div', { class: 'mb-4 last:mb-0' },
        h('div', { class: 'mb-2 flex flex-wrap items-center gap-2' },
            h('strong', {}, showBranch && v.branch ? `${v.branch} · ${shortDate(v.date)}` : shortDate(v.date)),
            h('span', { class: 'text-xs text-muted' }, `closed by ${v.closed_by ?? '—'}`),
            statusChip(v.status)),
        h('div', { class: 'grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4' },
            pair('Shop Cash', v.shop_cash),
            pair('GCash', v.gcash),
            card('border-border dark:border-gray-800',
                h('div', { class: 'mb-2 text-sm font-semibold' }, 'Bank envelope'),
                line('Sealed envelope for the bank', peso(v.envelope_amount)),
                line('Shop Cash left in the drawer', peso(v.shop_cash_left)),
                line('GCash sent to bank', peso(v.gcash_sent_to_bank))),
            card('border-border dark:border-gray-800',
                h('div', { class: 'mb-2 text-sm font-semibold' }, 'Machine counters'),
                machines.length
                    ? machines.map((m) => h('div', { class: 'flex justify-between gap-2 text-sm' },
                        h('span', { class: 'text-muted' }, m.label),
                        h('span', {}, `${num(m.booked, 0)} booked · ${num(m.counted, 0)} counted `,
                            m.ok !== false ? h('span', { class: 'text-green-700 dark:text-green-400' }, '✓')
                                : h('strong', { class: 'text-red-700 dark:text-red-300' }, signed(m.difference).trim() || 'check'))))
                    : h('p', { class: 'text-sm text-muted' }, 'No machine counters recorded'))),
        v.what_to_check ? h('p', { class: 'mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-950 dark:text-red-200' }, v.what_to_check) : null);
}

function busiest(root, d) {
    const b = d.busiest || {};
    const rows = list(b.rows);
    const hourly = b.mode === 'hourly';
    const show = (name, on) => { const el = root.querySelector(`[data-card="${name}"]`); if (el) el.hidden = !on; };
    show('busiest', !hourly);
    show('hourly', hourly);

    if (hourly) {
        const host = fill(root, 'hourly');
        if (!host) return;
        if (!rows.length) { host.append(empty()); return; }
        drawChart('hourly', host, {
            type: 'bar',
            data: {
                labels: rows.map((r) => r.label),
                datasets: [{ label: 'Orders', data: rows.map((r) => r.orders), backgroundColor: '#1f6b7a', borderRadius: 4, topLabels: rows.map((r) => (r.orders ? num(r.orders, 0) : '')) }],
            },
            options: { layout: { padding: { top: 20 } }, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { display: false, beginAtZero: true } } },
        });
        return;
    }

    const host = fill(root, 'busiest');
    if (!host) return;
    if (!rows.some((r) => Number(r.total) || Number(r.kg))) { host.append(empty()); return; }
    const inRange = (r) => r.in_range !== false;
    // An empty, pale bar marks a weekday the range never reaches; its height only matches the tallest bar.
    const placeholder = Math.max(1, maxOf(rows.map((r) => r.total)));
    const surface = document.documentElement.classList.contains('dark') ? '#111827' : '#ffffff';
    const lower = { maxBarThickness: 72, borderSkipped: false, borderWidth: { top: 2 }, borderColor: surface };
    const upper = { maxBarThickness: 72, borderSkipped: false, borderRadius: { topLeft: 4, topRight: 4 } };
    const top = rows.filter(inRange).reduce((best, r) => (Number(r.total) > Number(best?.total ?? 0) ? r : best), null);
    const key = (color, label, line = false) => h('span', { class: 'inline-flex items-center gap-1.5' },
        h('span', { class: line ? 'inline-block h-0.5 w-4' : 'inline-block h-2.5 w-2.5 rounded-sm', style: { background: color } }), label);
    const chips = h('div', { class: 'mt-1 grid grid-cols-7 text-center' }, rows.map((r) => h('div', {},
        inRange(r) && r.peak
            ? h('span', { class: 'inline-block rounded-md bg-sky-50 px-0.5 py-0.5 text-[9px] leading-tight font-medium text-sky-900 sm:rounded-full sm:px-2 sm:text-[11px] dark:bg-sky-950 dark:text-sky-200' }, r.peak)
            : h('span', { class: 'text-[11px] text-muted' }, '—'))));

    host.append(h('div', { class: 'mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between' },
        top ? h('p', { class: 'text-sm' }, h('strong', {}, `Busiest: ${top.day}`), ` · ${num(top.total, 1)} orders on average${top.peak ? ` · ${top.peak}` : ''}`) : h('span'),
        h('div', { class: 'flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted' },
            key('#9cc8d4', 'Orders before 12 PM'), key('#1f6b7a', 'Orders after 12 PM'), key('#b45309', 'Kilos washed', true))));

    drawChart('busiest', host, {
        type: 'bar',
        data: {
            labels: rows.map((r) => (inRange(r) ? r.day : [r.day, 'not in range'])),
            datasets: [
                { type: 'line', label: 'Kilos washed', data: rows.map((r) => (inRange(r) ? r.kg : null)), yAxisID: 'kg', borderColor: '#b45309', backgroundColor: '#ffffff', pointBorderColor: '#b45309', pointRadius: 4, borderWidth: 2, spanGaps: false, topLabelColor: '#b45309', topLabels: rows.map((r) => (inRange(r) ? `${num(r.kg, 0)}${window.innerWidth < 640 ? '' : ' kg'}` : '')), order: 0 },
                { label: 'Orders before 12 PM', data: rows.map((r) => (inRange(r) ? r.before_noon : null)), ...lower, backgroundColor: '#9cc8d4', stack: 'o', labelColor: '#134e5e', labels: rows.map((r) => (inRange(r) && r.before_noon ? num(r.before_noon, 1) : '')), order: 1 },
                { label: 'Orders after 12 PM', data: rows.map((r) => (inRange(r) ? r.after_noon : null)), ...upper, backgroundColor: '#1f6b7a', stack: 'o', labels: rows.map((r) => (inRange(r) && r.after_noon ? num(r.after_noon, 1) : '')), topLabels: rows.map((r) => (inRange(r) ? num(r.total, 1) : '')), order: 1 },
                { label: 'not in range', data: rows.map((r) => (inRange(r) ? null : placeholder)), ...upper, backgroundColor: 'rgba(148,163,184,0.18)', stack: 'o', order: 2 },
            ],
        },
        // Keeps each peak chip centred under its bar at any width.
        plugins: [{ id: 'odAlignChips', afterLayout(c) { chips.style.paddingLeft = `${c.chartArea.left}px`; chips.style.paddingRight = `${c.width - c.chartArea.right}px`; } }],
        options: {
            layout: { padding: { top: 22 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    filter: (item) => item.dataset.label !== 'not in range' && item.raw !== null,
                    callbacks: { label: (c) => `${c.dataset.label}: ${c.dataset.yAxisID === 'kg' ? `${num(c.raw, 0)} kg` : num(c.raw, 1)}` },
                },
            },
            // Kilos get their own band above the bars, so the line and its labels never cross the order counts.
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, display: false, beginAtZero: true, grace: '14%', position: 'left', stack: 'busiest', stackWeight: 3 },
                kg: { type: 'linear', axis: 'y', display: false, beginAtZero: false, bounds: 'data', grace: '15%', position: 'left', stack: 'busiest', stackWeight: 1, offset: true },
            },
        },
    }, 320);
    host.append(chips,
        h('p', { class: 'mt-3 text-xs text-muted' }, 'Chip = busiest 2 hours · open Mon–Sat 8 AM–7 PM, Sun 10 AM–5 PM'));
}

/** "5,190 kg · ₱30/kg", "18 loads · ₱175/load"; no price part when the API has none. */
function priceLine(r) {
    if (r.price === null || r.price === undefined) return qty(r.qty, r.unit);
    const price = Number.isInteger(Number(r.price)) ? peso(r.price) : pesoCents(r.price);
    const per = { kg: 'kg', loads: 'load', pcs: 'pc' }[r.unit] ?? r.unit;
    return `${qty(r.qty, r.unit)} · ${price}${per ? `/${per}` : ''}`;
}

function services(root, d) {
    const s = d.services || {};
    const rows = list(s.rows);
    const suppliesUrl = root.querySelector('[data-od="services"]')?.dataset.suppliesUrl;
    setSub(root, 'services', `Services ${peso(s.services_total)} · add-ons ${peso(s.addons_total)}`);
    if (!rows.length && !Number(s.addons_total)) { fill(root, 'services', empty()); return; }

    const max = maxOf(rows.map((r) => r.amount));
    const row = (r) => h('div', { class: 'grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 py-1.5' },
        h('div', {}, h('div', { class: 'text-sm font-semibold' }, r.name ?? '—'), h('div', { class: 'text-xs text-muted' }, priceLine(r))),
        bar(r.amount, max, '#8a5a35'),
        h('strong', { class: 'text-sm' }, peso(r.amount)));
    const half = Math.ceil(rows.length / 2);
    const addons = h(suppliesUrl ? 'a' : 'div', { href: suppliesUrl, class: 'mt-2 flex items-center justify-between rounded-lg bg-green-50 px-3 py-2 text-xs dark:bg-green-950/40' },
        h('span', {}, h('strong', {}, 'Add-ons'), ' · see the Supplies page'), h('strong', {}, `${peso(s.addons_total)} →`));

    fill(root, 'services',
        h('div', { class: 'grid grid-cols-1 gap-x-8 lg:grid-cols-2' },
            h('div', {}, rows.slice(0, half).map(row)),
            h('div', {}, rows.slice(half).map(row), addons)),
        s.top?.name ? note(`${s.top.name} brings ${pct(s.top.pct)} of sales.`) : null);
}

export default function renderOverview(root, d) {
    kpis(root, d);
    payments(root, d);
    intake(root, d);
    closings(root, d);
    busiest(root, d);
    services(root, d);
}
