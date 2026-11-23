import { h, peso, signedPeso, num, signedNum, qty, pct, shortDate, list, empty, tile, bar, maxOf, note, fill, setSub, drawChart, pesoTooltip } from './core';

const REASON = {
    cancelled_order: ['Back in · cancelled order', 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200'],
    spill: ['Spill', 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'],
    count: ['Count correction', 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'],
    other: ['Other', 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200'],
};

const signClass = (value) => (Number(value) > 0 ? 'text-green-700 dark:text-green-400' : Number(value) < 0 ? 'text-red-700 dark:text-red-300' : 'text-muted');

function kpis(root, d) {
    const k = d.kpis || {};
    fill(root, 'kpis',
        tile('Supplies used', peso(k.supplies_used), `${peso(k.per_order)} per order · ${peso(k.per_kg)} per kg washed`, 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/40', 'text-orange-800 dark:text-orange-300'),
        tile('Stock purchased', peso(k.stock_purchased), `${num(k.purchases_count, 0)} purchases`, 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/40', 'text-sky-900 dark:text-sky-200'),
        tile('Adjustments', peso(k.adjustments), `${num(k.adjustments_count, 0)} adjustments · count, spills, cancelled orders`, 'border-violet-200 bg-violet-50 dark:border-violet-900 dark:bg-violet-950/40', 'text-violet-800 dark:text-violet-300'),
        tile('Add-ons sold', peso(k.addons_sold), `${num(k.addons_count, 0)} add-ons · ${pct(k.addons_pct)} of sales`, undefined, 'text-green-700 dark:text-green-400'));
}

function usage(root, d) {
    const buckets = list(d.usage_buckets);
    const host = fill(root, 'usage');
    if (!host) return;
    if (!buckets.length || !buckets.some((b) => Number(b.cost))) { host.append(empty()); return; }
    const unit = { day: 'day', week: 'week', month: 'month' }[d.period?.bucket] || 'bar';
    const avgLabel = `avg ${peso(d.usage_average)} a ${unit}`;
    drawChart('usage', host, {
        type: 'bar',
        data: {
            labels: buckets.map((b) => b.label),
            datasets: [
                { type: 'line', label: avgLabel, data: buckets.map(() => d.usage_average), borderColor: '#6b7280', borderDash: [6, 4], borderWidth: 1.5, pointRadius: 0, order: 0 },
                { label: 'Supplies used', data: buckets.map((b) => b.cost), backgroundColor: buckets.map((b) => (b.is_saturday === true ? '#b45309' : '#f0b67f')), borderRadius: 3, order: 1 },
            ],
        },
        options: {
            plugins: { legend: { display: false }, tooltip: pesoTooltip },
            scales: { x: { grid: { display: false } }, y: { display: false, beginAtZero: true } },
        },
    }, 220);
    const anySaturday = buckets.some((b) => b.is_saturday === true);
    host.append(h('div', { class: 'mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted' },
        anySaturday ? h('span', {}, h('span', { class: 'mr-1 inline-block h-2.5 w-2.5 rounded-sm', style: { background: '#b45309' } }), 'Saturdays') : null,
        h('span', {}, h('span', { class: 'mr-1 inline-block h-2.5 w-2.5 rounded-sm', style: { background: '#f0b67f' } }), anySaturday ? 'Other days' : 'Supplies used'),
        h('span', {}, h('span', { class: 'mr-1 inline-block w-4 border-t border-dashed border-gray-500 align-middle' }), avgLabel)));
}

function items(root, d) {
    const rows = list(d.items);
    if (!rows.length) { fill(root, 'items', empty()); return; }
    const t = d.items_total || {};
    const th = (text, right = true) => h('th', { class: `px-2 py-2 text-[11px] font-bold uppercase tracking-wide text-muted ${right ? 'text-right' : 'text-left'}` }, text);
    const td = (cls, ...c) => h('td', { class: `px-2 py-2 text-right ${cls}` }, ...c);
    const adjusted = (r) => (Number(r.adjusted) === 0 ? '—' : `${signedNum(r.adjusted)} ${r.unit ?? ''}`);
    fill(root, 'items', h('div', { class: 'overflow-x-auto' }, h('table', { class: 'w-full min-w-[720px] text-sm' },
        h('thead', {}, h('tr', { class: 'border-b border-border dark:border-gray-800' },
            th('Item', false), th('Used'), th('Avg per day'), th('Per order'), th('Purchased'), th('Adjusted'), th('Cost used'))),
        h('tbody', {}, rows.map((r) => h('tr', { class: 'border-b border-border dark:border-gray-800' },
            h('td', { class: 'px-2 py-2 font-semibold' }, r.name ?? '—'),
            td('font-semibold', qty(r.used, r.unit)),
            td('', qty(r.avg_per_day, r.unit)),
            td('', qty(r.per_order, r.unit)),
            td('text-sky-800 dark:text-sky-300', Number(r.purchased) ? qty(r.purchased, r.unit) : '—'),
            td(signClass(r.adjusted), adjusted(r)),
            td('font-semibold', peso(r.cost_used))))),
        h('tfoot', {}, h('tr', { class: 'font-bold' },
            h('td', { class: 'px-2 py-2' }, 'Total'), td(''), td(''),
            td('', peso(t.per_order_cost)),
            td('text-sky-800 dark:text-sky-300', peso(t.purchased_cost)),
            td(signClass(t.adjusted_cost), signedPeso(t.adjusted_cost)),
            td('text-orange-800 dark:text-orange-300', peso(t.cost_used)))))));
}

/** Newest first; five shown, the rest behind "Show all N purchases →" (no extra request). */
function purchases(root, d) {
    const k = d.kpis || {};
    const rows = list(d.purchases);
    setSub(root, 'purchases', `${num(k.purchases_count, 0)} purchases · ${peso(k.stock_purchased)}`);
    if (!rows.length) { fill(root, 'purchases', empty()); return; }
    const item = (p) => h('li', { class: 'flex items-center gap-3 py-2' },
        h('span', { class: 'w-14 shrink-0 text-xs text-muted' }, shortDate(p.date, false)),
        h('div', { class: 'min-w-0 flex-1' },
            h('div', { class: 'text-sm' }, h('span', { class: 'mr-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] text-sky-900 dark:bg-sky-950 dark:text-sky-200' }, 'Purchase'), h('strong', {}, p.item ?? '—')),
            h('div', { class: 'text-xs text-muted' }, [`${signedNum(p.qty)} ${p.unit ?? ''}`.trim(), p.source].filter(Boolean).join(' · '))),
        h('strong', { class: 'text-sm text-sky-800 dark:text-sky-300' }, signedPeso(p.cost)));
    const ul = h('ul', { class: 'divide-y divide-border dark:divide-gray-800' }, rows.slice(0, 5).map(item));
    const more = rows.length > 5 ? h('button', { type: 'button', class: 'mt-2 text-xs font-semibold text-amber-800 underline dark:text-amber-400' }, `Show all ${num(k.purchases_count, 0)} purchases →`) : null;
    more?.addEventListener('click', () => { ul.replaceChildren(...rows.map(item)); more.remove(); });
    fill(root, 'purchases', ul, more);
}

function adjustments(root, d) {
    const k = d.kpis || {};
    const rows = list(d.adjustments);
    setSub(root, 'adjustments', `${num(k.adjustments_count, 0)} adjustments · ${signedPeso(k.adjustments)}`);
    if (!rows.length) { fill(root, 'adjustments', empty()); return; }
    fill(root, 'adjustments', h('ul', { class: 'divide-y divide-border dark:divide-gray-800' }, rows.map((a) => {
        const [label, chip] = REASON[a.reason] || [a.reason ?? 'Other', REASON.other[1]];
        return h('li', { class: 'flex items-center gap-3 py-2' },
            h('span', { class: 'w-14 shrink-0 text-xs text-muted' }, shortDate(a.date, false)),
            h('div', { class: 'min-w-0 flex-1' },
                h('div', { class: 'text-sm' }, h('span', { class: `mr-1 rounded px-1.5 py-0.5 text-[10px] ${chip}` }, label), h('strong', {}, a.item ?? '—')),
                h('div', { class: 'text-xs text-muted' }, [`${signedNum(a.qty)} ${a.unit ?? ''}`.trim(), a.note].filter(Boolean).join(' · '))),
            h('strong', { class: `text-sm ${signClass(a.cost)}` }, signedPeso(a.cost)));
    })));
}

function addons(root, d) {
    const k = d.kpis || {};
    const a = d.addons || {};
    const groups = list(a.groups);
    setSub(root, 'addons', `${peso(k.addons_sold)} · ${num(k.addons_count, 0)} add-ons`);
    if (!groups.length) { fill(root, 'addons', empty()); return; }
    const max = maxOf(groups.flatMap((g) => list(g.rows).map((r) => r.revenue)));
    fill(root, 'addons',
        h('div', { class: 'grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3' }, groups.map((g) => h('div', {},
            h('div', { class: 'mb-1 text-[11px] font-bold uppercase tracking-wide text-muted' }, g.group ?? 'Add-ons'),
            list(g.rows).map((r) => h('div', { class: 'grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 py-1.5' },
                h('div', {}, h('div', { class: 'text-sm font-semibold' }, r.name ?? '—'), h('div', { class: 'text-xs text-muted' }, `+${peso(r.price)} each · ${num(r.count, 0)} sold`)),
                bar(r.revenue, max, '#2e7d4f'),
                h('strong', { class: 'text-sm' }, peso(r.revenue))))))),
        a.most_popular && a.top_earner
            ? note(a.most_popular === a.top_earner
                ? `${a.most_popular} is the most popular add-on and brings the most money.`
                : `${a.most_popular} is the most popular add-on; ${a.top_earner} brings the most money.`)
            : null);
}

export default function renderSupplies(root, d) {
    kpis(root, d);
    usage(root, d);
    items(root, d);
    purchases(root, d);
    adjustments(root, d);
    addons(root, d);
}
