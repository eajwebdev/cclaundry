import { h, peso, pesoCents, num, pct, list, empty, card, bar, maxOf, note, fill, setSub } from './core';

const LINES = [
    ['staff', 'Staff', '#5b3f86'],
    ['bills', 'Bills', '#8a5a35'],
    ['supplies', 'Supplies', '#d98a2b'],
    ['other', 'Other purchases', '#9ca3af'],
];
const GREEN = '#2e7d4f';
const BILL_COLORS = ['#8a5a35', '#1f6b7a', '#d98a2b', '#7fb8d6', '#6b8e23', '#374151', '#9ca3af'];

const employees = (count) => `${num(count, 0)} ${Number(count) === 1 ? 'employee' : 'employees'}`;
const suppliesNames = (d) => list(d.supplies_items).map((name) => String(name).toLowerCase()).join(', ');


/** Money in − Money out = Profit as one card; the line under each figure shows its size against Sales. */
function equation(root, d) {
    const out = d.money_out || {};
    const loss = Number(d.profit) < 0;
    const hasSales = Number(d.money_in) > 0;
    const width = (share) => `${hasSales ? Math.min(100, Math.max(0, Number(share) || 0)) : 0}%`;
    const part = (label, value, sub, color, share, cls = '', valueCls = '') => h('div', { class: `flex flex-col justify-between gap-3 p-4 sm:p-5 ${cls}` },
        h('div', {},
            h('div', { class: 'flex items-center gap-2 text-sm font-semibold' }, h('span', { class: 'h-2.5 w-2.5 rounded-sm', style: { background: color } }), label),
            h('div', { class: `mt-1 text-4xl font-bold tracking-tight tabular-nums sm:text-[2.75rem] sm:leading-none ${valueCls}`, style: { fontFamily: "'Playfair Display', Georgia, serif" } }, value),
            h('div', { class: 'mt-1.5 text-sm text-muted' }, sub)),
        h('div', { class: 'h-1.5 w-full overflow-hidden rounded-full bg-black/5 dark:bg-white/10' }, h('div', { class: 'h-full rounded-full', style: { width: width(share), background: color } })));
    const op = (sign) => h('div', { class: 'hidden w-0 items-center justify-center md:flex' },
        h('span', { class: 'z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-border bg-white text-lg font-bold text-muted dark:border-gray-700 dark:bg-gray-900' }, sign));
    const kept = loss ? '#b91c1c' : GREEN;

    fill(root, 'equation', h('div', { class: 'grid grid-cols-1 divide-y divide-border overflow-hidden rounded-xl border border-border bg-white shadow-sm md:grid-cols-[1fr_auto_1fr_auto_1.15fr] md:divide-x md:divide-y-0 dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900' },
        part('Money in', peso(d.money_in), `sales from ${num(d.orders, 0)} orders`, '#1f6b7a', 100),
        op('−'),
        part('Money out', peso(out.total?.amount), hasSales ? `₱${num(out.total?.pct, 0)} of every ₱100 earned` : 'staff, bills, supplies, other', '#b45309', out.total?.pct),
        op('='),
        part(loss ? 'Loss' : 'Profit', peso(d.profit),
            loss ? `${pct(Math.abs(Number(d.profit_pct) || 0))} of sales lost` : `${pct(d.profit_pct)} of sales stays in the shop`,
            kept, loss ? 0 : d.profit_share,
            loss ? 'bg-red-50 dark:bg-red-950/30' : 'bg-green-50 dark:bg-green-950/30',
            loss ? 'text-red-700 dark:text-red-300' : 'text-green-800 dark:text-green-300')));
}

/** "Water ₱1,998 · Electricity ₱587": the biggest few things inside a cost line. */
const inside = (rows, nameKey) => list(rows).slice(0, 3).filter((r) => r[nameKey]).map((r) => `${r[nameKey]} ${peso(r.amount)}`).join(' · ');

/** One card that answers "where did the money go": a sentence, one bar of the sales split, and a row per cost. */
function moneyWent(root, d) {
    const out = d.money_out || {};
    const per = d.per_order || {};
    setSub(root, 'money-went', Number(d.orders) ? `Out of ${peso(d.money_in)} in sales · ${peso(per.sales)} per order` : '');
    if (!Number(d.money_in) && !Number(out.total?.amount)) { fill(root, 'money-went', empty()); return; }

    const profit = Number(d.profit);
    const loss = profit < 0;
    const staffCount = d.staff?.employees_count;
    const subs = {
        staff: `${staffCount ? `${employees(staffCount)} · ` : ''}payroll recorded in Expenses`,
        bills: inside(d.bills?.rows, 'label'),
        supplies: suppliesNames(d),
        other: inside(d.other_purchases?.rows, 'category'),
    };
    // Biggest cost first, so the answer reads top to bottom.
    const costs = LINES.map(([key, label, color]) => ({ key, label, color, amount: Number(out[key]?.amount) || 0, share: out[key]?.pct, each: per[key], sub: subs[key] }))
        .sort((x, y) => y.amount - x.amount);
    const kept = { key: 'profit', label: loss ? 'Short' : 'Stayed in the shop', color: loss ? '#b91c1c' : GREEN, amount: profit, share: d.profit_share, each: per.profit };
    const of100 = (share) => `₱${num(Math.abs(Number(share) || 0), 0)} of every ₱100`;
    const lightText = { staff: true, bills: true, profit: true };

    const headline = !Number(d.money_in)
        ? h('p', { class: 'text-lg font-semibold' }, `${peso(out.total?.amount)} was spent and nothing was sold in these dates.`)
        : h('p', { class: 'text-lg leading-snug sm:text-xl' }, 'Out of every ', h('strong', {}, '₱100'), ' the shop earned, ',
            h('strong', {}, `₱${num(out.total?.pct, 0)}`), ' was spent',
            loss ? h('span', {}, ' — ', h('strong', { class: 'text-red-700 dark:text-red-300' }, `₱${num(Math.abs(Number(d.profit_share) || 0), 0)} more than it earned`), '.')
                : h('span', {}, ' and ', h('strong', { class: 'text-green-700 dark:text-green-400' }, `₱${num(d.profit_share, 0)} stayed`), '.'));

    // One bar = all the sales (or all the spending, on a loss). Segment widths follow the amounts.
    const segments = [...costs, ...(loss ? [] : [kept])].filter((c) => c.amount > 0);
    const strip = h('div', { class: 'mt-4 flex h-11 w-full gap-0.5 overflow-hidden rounded-lg', role: 'img', 'aria-label': segments.map((c) => `${c.label} ${of100(c.share)}`).join(', ') },
        segments.map((c) => h('div', { class: `flex min-w-0 items-center justify-center px-1 text-xs font-semibold ${lightText[c.key] ? 'text-white' : 'text-stone-900'}`, style: { flex: `${c.amount} 1 0%`, background: c.color }, title: `${c.label} · ${peso(c.amount)}` },
            Number(c.share) >= 9 ? h('span', { class: 'truncate' }, `${c.label} ${pct(c.share)}`) : null)));

    const max = maxOf(costs.map((c) => c.amount));
    const row = (c, cls = '') => h('li', { class: `grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 py-3 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto] ${cls}` },
        h('div', { class: 'flex min-w-0 items-start gap-2.5' },
            h('span', { class: 'mt-1.5 h-3 w-3 shrink-0 rounded-sm', style: { background: c.color } }),
            h('div', { class: 'min-w-0' }, h('div', { class: 'font-semibold' }, c.label), c.sub ? h('div', { class: 'text-xs text-muted sm:truncate', title: c.sub }, c.sub) : null)),
        h('div', { class: 'hidden sm:block' }, c.key === 'profit' ? null : bar(c.amount, max, c.color)),
        h('div', { class: 'text-right' },
            h('div', { class: 'text-xl font-bold tabular-nums' }, peso(c.amount)),
            h('div', { class: 'text-xs text-muted' }, of100(c.share), Number(d.orders) ? h('span', { class: 'hidden sm:inline' }, ` · ${peso(c.each)} per order`) : null)));

    fill(root, 'money-went', headline, strip,
        h('ul', { class: 'mt-3 divide-y divide-border dark:divide-gray-800' }, costs.map((c) => row(c))),
        h('ul', {}, row(kept, `mt-1 rounded-lg px-3 ${loss ? 'bg-red-50 dark:bg-red-950/40' : 'bg-green-50 dark:bg-green-950/40'}`)));
}

function breakEven(root, d) {
    const b = d.break_even || {};
    const out = d.money_out || {};
    if (!Number(d.money_in) && !Number(out.total?.amount)) { fill(root, 'break-even', empty()); return; }
    const profit = Number(d.profit);
    // No sales but costs: the whole bar is costs (the API's % of zero sales is 0).
    const costWidth = Number(d.money_in) ? Math.min(100, Math.max(0, Number(out.total?.pct) || 0)) : 100;
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
        box('Total staff cost', peso(s.total), 'payroll recorded in Expenses', 'bg-violet-50 dark:bg-violet-950/40'),
        box('Attendance', pct(s.attendance_pct), `${num(s.days_present, 0)} of ${num(s.days_scheduled, 0)} scheduled days`),
        box('Staff cost per order', peso(s.per_order), `${num(d.orders, 0)} orders`),
        box('Staff cost per kg', pesoCents(s.per_kg), `${num(s.kg, 2)} kg washed`));
    if (!rows.length) { fill(root, 'staff', tiles, empty()); return; }
    const th = (t, right = true) => h('th', { class: `px-2 py-2 text-[11px] font-bold uppercase tracking-wide text-muted ${right ? 'text-right' : 'text-left'}` }, t);
    const td = (cls, ...c) => h('td', { class: `px-2 py-2 text-right ${cls}` }, ...c);
    const initials = (name) => String(name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
    fill(root, 'staff', tiles, h('div', { class: 'mt-3 overflow-x-auto' }, h('table', { class: 'w-full min-w-[420px] text-sm' },
        h('thead', {}, h('tr', { class: 'border-b border-border dark:border-gray-800' },
            th('Employee', false), th('Days worked'), th('Payroll paid'))),
        h('tbody', {}, rows.map((e) => h('tr', { class: 'border-b border-border dark:border-gray-800' },
            h('td', { class: 'px-2 py-2' }, h('div', { class: 'flex items-center gap-2' },
                h('span', { class: 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-violet-100 text-[10px] font-bold text-violet-800 dark:bg-violet-950 dark:text-violet-200' }, initials(e.name)),
                h('div', {}, h('div', { class: 'font-semibold' }, e.name ?? '—'), e.role ? h('div', { class: 'text-xs text-muted' }, e.role) : null))),
            td(Number(e.days_worked) < Number(e.days_scheduled) ? 'font-semibold text-orange-600 dark:text-orange-400' : '', num(e.days_worked, 0)),
            td('font-semibold', peso(e.total))))),
        h('tfoot', {}, h('tr', { class: 'font-bold' },
            h('td', { class: 'px-2 py-2' }, employees(s.employees_count)),
            td('', num(s.days_present, 0)), td('', peso(s.total)))))));
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
    const names = suppliesNames(d);
    setSub(root, 'supplies-items', names ? ` · ${names}` : '');
    if (!rows.length) { fill(root, 'other', empty()); return; }
    const max = maxOf(rows.map((r) => r.amount));
    fill(root, 'other', rows.map((r) => h('div', { class: 'grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 py-1.5' },
        h('div', { class: 'text-sm font-semibold' }, r.category ?? '—'),
        bar(r.amount, max, '#5b3f86'),
        h('strong', { class: 'text-sm' }, peso(r.amount)))));
}

export default function renderProfit(root, d) {
    equation(root, d);
    moneyWent(root, d);
    breakEven(root, d);
    staff(root, d);
    bills(root, d);
    other(root, d);
}
