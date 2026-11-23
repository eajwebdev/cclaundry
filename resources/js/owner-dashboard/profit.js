import { h, peso, num, pct, list, empty, card, bar, maxOf, note, fill, setSub, drawChart, pesoTooltip } from './core';

const LINES = [
    ['staff', 'Staff', '#5b3f86'],
    ['bills', 'Bills', '#8a5a35'],
    ['supplies', 'Supplies', '#d98a2b'],
    ['other', 'Other purchases', '#9ca3af'],
];
const GREEN = '#2e7d4f';
const BILL_COLORS = ['#8a5a35', '#1f6b7a', '#d98a2b', '#7fb8d6', '#6b8e23', '#374151', '#9ca3af'];

const big = (text, cls = '') => h('div', { class: `mt-1 text-3xl font-bold tracking-tight ${cls}`, style: { fontFamily: "'Playfair Display', Georgia, serif" } }, text);

function equation(root, d) {
    const out = d.money_out || {};
    const loss = Number(d.profit) < 0;
    const op = (sign) => h('div', { class: 'hidden items-center justify-center text-3xl font-bold text-muted md:flex' }, sign);
    fill(root, 'equation', h('div', { class: 'grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto_1fr_auto_1fr]' },
        card('border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/40',
            h('div', { class: 'text-xs font-medium' }, 'Money in'), big(peso(d.money_in), 'text-sky-900 dark:text-sky-200'),
            h('div', { class: 'text-xs text-muted' }, `sales from ${num(d.orders, 0)} orders`)),
        op('−'),
        card('border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/40',
            h('div', { class: 'text-xs font-medium' }, 'Money out'), big(peso(out.total?.amount), 'text-orange-800 dark:text-orange-300'),
            h('div', { class: 'text-xs text-muted' }, 'staff, bills, supplies, other')),
        op('='),
        card(loss ? 'border-red-700 bg-red-700 text-white' : 'border-green-700 bg-green-700 text-white',
            h('div', { class: 'text-xs font-medium' }, 'Profit'), big(peso(d.profit)),
            h('div', { class: 'text-xs' }, `${pct(d.profit_pct)} of sales stays in the shop`))));
}

function moneyChart(root, d) {
    const host = fill(root, 'money-chart');
    if (!host) return;
    const out = d.money_out || {};
    if (!Number(d.money_in) && !Number(out.total?.amount)) { host.append(empty()); return; }
    const profit = Number(d.profit);
    const lines = profit >= 0 ? [...LINES, ['profit', 'Profit', GREEN]] : LINES;
    const last = lines[lines.length - 1][0];
    // Top of the right bar: Sales when there is a profit (costs + profit), total costs when there is a loss.
    const rightTop = profit >= 0 ? peso(d.money_in) : peso(out.total?.amount);
    const datasets = [
        { label: 'Money in', data: [d.money_in, null], backgroundColor: '#1f6b7a', stack: 's', topLabels: [peso(d.money_in), ''] },
        ...lines.map(([key, label, color]) => ({
            label,
            data: [null, key === 'profit' ? d.profit : out[key]?.amount],
            backgroundColor: color,
            stack: 's',
            topLabels: key === last ? ['', rightTop] : undefined,
        })),
    ];
    const wrap = h('div', { class: 'grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center' });
    host.append(wrap);
    const chartBox = h('div');
    wrap.append(chartBox);
    drawChart('money', chartBox, {
        type: 'bar',
        data: { labels: ['Money in', 'Where it went'], datasets },
        options: {
            layout: { padding: { top: 20 } },
            plugins: { legend: { display: false }, tooltip: pesoTooltip },
            scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, display: false, beginAtZero: true } },
        },
    }, 280);
    const legendRow = (label, color, amount, share) => h('li', { class: 'flex items-center gap-2' },
        h('span', { class: 'inline-block h-0.5 w-4', style: { background: color } }),
        h('strong', {}, label), h('span', { class: 'text-muted' }, `${peso(amount)} · ${pct(share)}`));
    wrap.append(h('ul', { class: 'space-y-2 text-xs' },
        legendRow('Profit', profit >= 0 ? GREEN : '#b91c1c', d.profit, d.profit_share),
        [...LINES].reverse().map(([key, label, color]) => legendRow(label, color, out[key]?.amount, out[key]?.pct))));
}

function perOrder(root, d) {
    const p = d.per_order || {};
    setSub(root, 'per-order', `${peso(p.sales)} per order`);
    if (!Number(d.orders)) { fill(root, 'per-order', empty()); return; }
    const billNames = list(d.bills?.rows).map((r) => r.label).filter(Boolean).join(', ');
    const otherNames = list(d.other_purchases?.rows).slice(0, 5).map((r) => r.category).filter(Boolean).join(', ');
    const subs = { staff: 'wages + SSS, PhilHealth, Pag-IBIG', bills: billNames, supplies: 'used, valued at cost', other: otherNames };
    const row = ([key, label, color]) => h('li', { class: 'flex items-center justify-between gap-3 border-b border-border py-2 dark:border-gray-800' },
        h('div', { class: 'flex items-start gap-2' }, h('span', { class: 'mt-1 h-2.5 w-2.5 shrink-0 rounded-sm', style: { background: color } }),
            h('div', {}, h('div', { class: 'text-sm font-semibold' }, label), subs[key] ? h('div', { class: 'text-xs text-muted' }, subs[key]) : null)),
        h('strong', { class: 'text-lg' }, peso(p[key])));
    const loss = Number(p.profit) < 0;
    fill(root, 'per-order',
        h('div', { class: 'flex items-baseline gap-2' }, big(peso(p.sales)), h('span', { class: 'text-sm text-muted' }, 'paid by the customer, split like this:')),
        h('ul', { class: 'mt-2' }, LINES.map(row)),
        h('div', { class: `mt-3 flex items-center justify-between rounded-lg px-3 py-2 ${loss ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200' : 'bg-green-50 text-green-800 dark:bg-green-950/40 dark:text-green-300'}` },
            h('span', { class: 'flex items-center gap-2 text-sm font-semibold' }, h('span', { class: 'h-2.5 w-2.5 rounded-sm', style: { background: loss ? '#b91c1c' : GREEN } }), 'Profit'),
            h('strong', { class: 'text-2xl' }, peso(p.profit))));
}

function breakEven(root, d) {
    const b = d.break_even || {};
    const out = d.money_out || {};
    if (!Number(d.money_in) && !Number(out.total?.amount)) { fill(root, 'break-even', empty()); return; }
    const profit = Number(d.profit);
    const costWidth = Math.min(100, Math.max(0, Number(out.total?.pct) || 0));
    const split = h('div', { class: 'flex h-9 w-full overflow-hidden rounded-lg bg-gray-100 text-xs font-semibold dark:bg-gray-800' },
        h('div', { class: 'flex items-center truncate bg-stone-300 px-3 text-stone-900', style: { width: `${costWidth}%` } }, `Paying the costs · ${peso(out.total?.amount)}`),
        profit > 0 ? h('div', { class: 'flex items-center truncate px-3 text-white', style: { width: `${Math.max(0, Number(d.profit_share) || 0)}%`, background: GREEN } }, `Profit · ${peso(d.profit)}`) : null);
    const diff = Number(b.difference);
    const covered = b.covered_on;
    const box = (label, value, sub, cls = 'bg-smoke dark:bg-gray-950', subCls = 'text-muted') => card(`border-transparent ${cls}`,
        h('div', { class: 'text-xs font-medium' }, label), h('div', { class: 'mt-1 text-2xl font-bold' }, value), h('div', { class: `text-xs ${subCls}` }, sub));
    fill(root, 'break-even',
        split,
        h('div', { class: 'mt-3 grid grid-cols-1 gap-2 md:grid-cols-3' },
            box('Break-even per day', peso(b.per_day), 'sales needed each day'),
            box('Actual sales per day', peso(b.actual_per_day),
                diff === 0 ? 'right at break-even' : `${peso(Math.abs(diff))} ${diff > 0 ? 'above' : 'below'} break-even`,
                undefined, diff >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-300'),
            covered && covered.day
                ? box('Costs covered on', `Day ${num(covered.day, 0)} of ${num(covered.of, 0)}`, 'every sale after that is profit', 'bg-green-50 text-green-900 dark:bg-green-950/40 dark:text-green-200')
                : box('Costs covered on', 'Not covered', 'sales in these dates did not reach the costs', 'bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-200')));
}

function staff(root, d) {
    const s = d.staff || {};
    const rows = list(s.employees);
    const box = (label, value, sub, cls = 'bg-smoke dark:bg-gray-950') => card(`border-transparent ${cls}`,
        h('div', { class: 'text-xs font-medium' }, label), h('div', { class: 'mt-1 text-2xl font-bold' }, value), sub ? h('div', { class: 'text-xs text-muted' }, sub) : null);
    const tiles = h('div', { class: 'grid grid-cols-2 gap-2 xl:grid-cols-4' },
        box('Total staff cost', peso(s.total), `${peso(s.wages)} wages + ${peso(s.employer_share)} employer share`, 'bg-violet-50 dark:bg-violet-950/40'),
        box('Attendance', pct(s.attendance_pct), `${num(s.days_present, 0)} of ${num(s.days_scheduled, 0)} scheduled days`),
        box('Staff cost per order', peso(s.per_order), `${num(d.orders, 0)} orders`),
        box('Staff cost per kg', `₱${num(s.per_kg, 2)}`, null));
    if (!rows.length) { fill(root, 'staff', tiles, empty()); return; }
    const th = (t, right = true) => h('th', { class: `px-2 py-2 text-[11px] font-bold uppercase tracking-wide text-muted ${right ? 'text-right' : 'text-left'}` }, t);
    const td = (cls, ...c) => h('td', { class: `px-2 py-2 text-right ${cls}` }, ...c);
    const initials = (name) => String(name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
    fill(root, 'staff', tiles, h('div', { class: 'mt-3 overflow-x-auto' }, h('table', { class: 'w-full min-w-[720px] text-sm' },
        h('thead', {}, h('tr', { class: 'border-b border-border dark:border-gray-800' },
            th('Employee', false), th('Daily rate'), th('Days worked'), th('Wages'), th('Employer share'), th('Total cost'))),
        h('tbody', {}, rows.map((e) => h('tr', { class: 'border-b border-border dark:border-gray-800' },
            h('td', { class: 'px-2 py-2' }, h('div', { class: 'flex items-center gap-2' },
                h('span', { class: 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-violet-100 text-[10px] font-bold text-violet-800 dark:bg-violet-950 dark:text-violet-200' }, initials(e.name)),
                h('div', {}, h('div', { class: 'font-semibold' }, e.name ?? '—'), e.role ? h('div', { class: 'text-xs text-muted' }, e.role) : null))),
            td('', e.daily_rate === null || e.daily_rate === undefined ? '—' : peso(e.daily_rate)),
            td(Number(e.days_worked) < Number(e.days_scheduled) ? 'font-semibold text-orange-600 dark:text-orange-400' : '', num(e.days_worked, 0)),
            td('', peso(e.wages)),
            td('text-muted', peso(e.employer_share)),
            td('font-semibold', peso(e.total))))),
        h('tfoot', {}, h('tr', { class: 'font-bold' },
            h('td', { class: 'px-2 py-2' }, 'Total'), td(''),
            td('', num(s.days_present, 0)), td('', peso(s.wages)), td('', peso(s.employer_share)), td('', peso(s.total)))))));
}

function bills(root, d) {
    const b = d.bills || {};
    const rows = list(b.rows);
    setSub(root, 'bills', `${peso(b.total)} · ${pct(b.pct_of_sales)} of sales`);
    if (!rows.length) { fill(root, 'bills', empty()); return; }
    const max = maxOf(rows.map((r) => r.amount));
    fill(root, 'bills',
        rows.map((r, i) => h('div', { class: 'grid grid-cols-[minmax(0,8rem)_1fr_auto] items-center gap-3 py-1.5' },
            h('div', {}, h('div', { class: 'text-sm font-semibold' }, r.label ?? r.type), h('div', { class: 'text-xs text-muted' }, `${pct(r.pct)} of bills`)),
            bar(r.amount, max, BILL_COLORS[i % BILL_COLORS.length]),
            h('strong', { class: 'text-sm' }, peso(r.amount)))),
        rows.length >= 2 && rows[0].label && rows[1].label ? note(`${rows[1].label} is the biggest bill after ${rows[0].label}.`) : null);
}

function other(root, d) {
    const o = d.other_purchases || {};
    const rows = list(o.rows);
    setSub(root, 'other', `${peso(o.total)} · ${pct(o.pct_of_sales)} of sales`);
    setSub(root, 'supplies-used', peso(d.money_out?.supplies?.amount));
    if (!rows.length) { fill(root, 'other', empty()); return; }
    const max = maxOf(rows.map((r) => r.amount));
    fill(root, 'other', rows.map((r) => h('div', { class: 'grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 py-1.5' },
        h('div', { class: 'text-sm font-semibold' }, r.category ?? '—'),
        bar(r.amount, max, '#5b3f86'),
        h('strong', { class: 'text-sm' }, peso(r.amount)))));
}

export default function renderProfit(root, d) {
    equation(root, d);
    moneyChart(root, d);
    perOrder(root, d);
    breakEven(root, d);
    staff(root, d);
    bills(root, d);
    other(root, d);
}
