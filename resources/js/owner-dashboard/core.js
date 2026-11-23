// Shared core of the owner-dashboard pages: DOM building, formatting, charts.
// Every figure comes from the endpoint as is: these helpers format, they never add or divide.

/** Build an element. Strings and numbers become text nodes (never HTML): names and notes are typed by staff. */
export function h(tag, props = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(props || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') el.className = value;
        else if (key === 'style') Object.assign(el.style, value);
        else if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
        else el.setAttribute(key, value === true ? '' : value);
    }
    append(el, children);
    return el;
}

function append(el, children) {
    for (const child of children) {
        if (child === null || child === undefined || child === false) continue;
        if (Array.isArray(child)) append(el, child);
        else el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
}

const n = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0);

/** Whole pesos with separators; a loss reads −₱120. */
export function peso(value) {
    const whole = Math.round(Math.abs(n(value)));
    return (n(value) < 0 && whole !== 0 ? '−' : '') + '₱' + whole.toLocaleString('en-US');
}

/** Peso with an explicit sign, for adjustments: +₱40 / −₱45. */
export function signedPeso(value) {
    return (n(value) > 0 ? '+' : '') + peso(value);
}

export function num(value, digits = 2) {
    const v = n(value);
    return (v < 0 ? '−' : '') + Math.abs(v).toLocaleString('en-US', { maximumFractionDigits: digits });
}

export function signedNum(value, digits = 2) {
    return (n(value) > 0 ? '+' : '') + num(value, digits);
}

export const qty = (value, unit, digits = 2) => `${num(value, digits)} ${unit ?? ''}`.trim();

export const pct = (value) => `${num(value, 1)}%`;

/** 'YYYY-MM-DD' → 'Sun Sep 27' (no time zone shift: the date is read as given). */
export function shortDate(ymd, withWeekday = true) {
    const [y, m, d] = String(ymd).split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));
    if (Number.isNaN(date.getTime())) return String(ymd ?? '');
    const opts = { timeZone: 'UTC', month: 'short', day: 'numeric' };
    if (withWeekday) opts.weekday = 'short';
    return date.toLocaleDateString('en-US', opts).replace(',', '');
}

export const list = (value) => (Array.isArray(value) ? value : []);

export const empty = () => h('p', { class: 'py-4 text-sm text-muted' }, 'No data for these dates');

export const card = (cls, ...children) => h('div', { class: `rounded-xl border p-4 ${cls}` }, ...children);

/** A KPI tile: small label, big value, muted sub-line. */
export function tile(label, value, sub, cls = 'border-border bg-white dark:border-gray-800 dark:bg-gray-900', valueCls = '') {
    return card(
        cls,
        h('div', { class: 'text-xs font-medium text-muted' }, label),
        h('div', { class: `mt-1 text-2xl font-bold tracking-tight sm:text-3xl ${valueCls}`, style: { fontFamily: "'Playfair Display', Georgia, serif" } }, value),
        sub ? h('div', { class: 'mt-1 text-xs text-muted' }, sub) : null,
    );
}

/** A horizontal bar whose width is scaled to the largest value in its list (layout only, not a figure). */
export function bar(value, max, color) {
    const width = max > 0 ? Math.max(0, Math.min(100, (n(value) / max) * 100)) : 0;
    return h('div', { class: 'h-2 w-full rounded-full bg-gray-100 dark:bg-gray-800' },
        h('div', { class: 'h-2 rounded-full', style: { width: `${width}%`, background: color } }));
}

export const maxOf = (values) => Math.max(0, ...values.map(n));

export const note = (strong, rest = '') => h('p', { class: 'mt-3 rounded-lg bg-smoke px-3 py-2 text-xs dark:bg-gray-950' }, h('strong', {}, strong), rest);

/** Replace a widget body (`[data-od=name]`) with the given nodes. */
export function fill(root, name, ...nodes) {
    const el = root.querySelector(`[data-od="${name}"]`);
    if (!el) return null;
    el.replaceChildren();
    append(el, nodes);
    return el;
}

export function setSub(root, name, text) {
    const el = root.querySelector(`[data-od-sub="${name}"]`);
    if (el) el.textContent = text ?? '';
}

// ---- Charts -------------------------------------------------------------------------------------

const charts = new Map();

/** Draws `labels` (inside each bar segment) and `topLabels` (above a bar or point) given on a dataset. */
const valueLabels = {
    id: 'odValueLabels',
    afterDatasetsDraw(chart) {
        const { ctx } = chart;
        const dark = document.documentElement.classList.contains('dark');
        ctx.save();
        ctx.font = '600 11px system-ui, sans-serif';
        ctx.textAlign = 'center';
        chart.data.datasets.forEach((ds, i) => {
            const meta = chart.getDatasetMeta(i);
            if (meta.hidden) return;
            meta.data.forEach((el, j) => {
                const inside = ds.labels?.[j];
                if (inside && el.base !== undefined && Math.abs(el.base - el.y) > 14) {
                    ctx.fillStyle = ds.labelColor || '#ffffff';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(inside, el.x, (el.y + el.base) / 2);
                }
                const top = ds.topLabels?.[j];
                if (top) {
                    ctx.fillStyle = ds.topLabelColor || (dark ? '#e5e7eb' : '#374151');
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(top, el.x, el.y - 6);
                }
            });
        });
        ctx.restore();
    },
};

/** Create a chart in a fresh canvas inside `host`, destroying the previous chart of the same key. */
export function drawChart(key, host, config, height = 260) {
    charts.get(key)?.destroy();
    charts.delete(key);
    if (!window.Chart) {
        host.append(h('p', { class: 'text-sm text-muted' }, 'Chart unavailable.'));
        return;
    }
    const canvas = h('canvas');
    host.append(h('div', { class: 'relative w-full', style: { height: `${height}px` } }, canvas));
    config.plugins = [...(config.plugins || []), valueLabels];
    config.options = { responsive: true, maintainAspectRatio: false, ...config.options };
    charts.set(key, new window.Chart(canvas, config));
}

export function destroyCharts() {
    charts.forEach((c) => c.destroy());
    charts.clear();
}

/** Tooltip label showing pesos. */
export const pesoTooltip = { callbacks: { label: (c) => `${c.dataset.label}: ${peso(c.raw)}` } };

// ---- URL state and calendar ---------------------------------------------------------------------

/** Navigate to this page with a new filter; the server validates it and renders the bar. */
export function go(from, to, branch) {
    const url = new URL(window.location.href);
    url.search = new URLSearchParams({ from, to, branch }).toString();
    window.location.assign(url.toString());
}

const DAY = 86400000;
const utc = (d) => Date.UTC(d.getFullYear(), d.getMonth(), d.getDate());

/** The date button opens a two-month range calendar; Apply reloads with the range, Cancel keeps the old one. */
export function setupCalendar(root) {
    const button = root.querySelector('#od-date-button');
    const branchSelect = root.querySelector('#od-branch');
    const { from, to } = root.dataset;
    const branch = () => branchSelect?.value || root.dataset.branch || 'all';

    branchSelect?.addEventListener('change', () => go(from, to, branch()));
    if (!button || !window.flatpickr) return;

    const anchor = h('input', { type: 'text', readonly: true, tabindex: '-1', 'aria-hidden': 'true', class: 'sr-only' });
    button.after(anchor);

    const fromField = h('input', { type: 'text', readonly: true, class: 'w-full bg-transparent text-sm font-semibold outline-none' });
    const toField = h('input', { type: 'text', readonly: true, class: 'w-full bg-transparent text-sm font-semibold outline-none' });
    const count = h('span', { class: 'font-semibold' });
    const field = (label, input) => h('label', { class: 'flex-1 rounded-lg border border-gray-300 px-3 py-1.5' },
        h('span', { class: 'block text-[10px] text-gray-500' }, label), input);

    let fp;
    let saved = [from, to];
    const sync = (dates) => {
        fromField.value = dates[0] ? fp.formatDate(dates[0], 'D, M j, Y') : '';
        toField.value = dates[1] ? fp.formatDate(dates[1], 'D, M j, Y') : (dates[0] ? fp.formatDate(dates[0], 'D, M j, Y') : '');
        // The only counting on these pages: the calendar's own feedback, not a dashboard figure.
        const days = dates.length ? Math.round((utc(dates[dates.length - 1]) - utc(dates[0])) / DAY) + 1 : 0;
        count.textContent = `${days} ${days === 1 ? 'day' : 'days'}`;
    };
    const apply = () => {
        const dates = fp.selectedDates;
        if (!dates.length) return;
        const first = fp.formatDate(dates[0], 'Y-m-d');
        go(first, dates[1] ? fp.formatDate(dates[1], 'Y-m-d') : first, branch());
    };
    const cancel = () => {
        fp.setDate(saved, false);
        sync(fp.selectedDates);
        fp.close();
    };

    fp = window.flatpickr(anchor, {
        mode: 'range',
        showMonths: window.matchMedia('(max-width: 640px)').matches ? 1 : 2,
        maxDate: 'today',
        closeOnSelect: false,
        clickOpens: false,
        dateFormat: 'Y-m-d',
        defaultDate: [from, to],
        positionElement: button,
        ignoredFocusElements: [button],
        onChange: (dates) => sync(dates),
        onOpen: () => { saved = fp.selectedDates.slice(); sync(saved); },
        onClose: () => { fp.setDate(saved, false); },
        onReady: (_d, _s, instance) => {
            instance.calendarContainer.prepend(h('div', { class: 'flex gap-2 p-2' }, field('From', fromField), field('To', toField)));
            instance.calendarContainer.append(h('div', { class: 'flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 p-2 text-xs text-gray-600' },
                h('span', {}, count, ' selected · click a first day, then a last day'),
                h('span', { class: 'flex gap-2' },
                    h('button', { type: 'button', class: 'rounded-md border border-gray-300 px-3 py-1 font-semibold text-gray-800', onclick: cancel }, 'Cancel'),
                    h('button', { type: 'button', class: 'rounded-md bg-gray-900 px-3 py-1 font-semibold text-white', onclick: apply }, 'Apply'))));
        },
    });
    sync(fp.selectedDates);
    button.addEventListener('click', () => (fp.isOpen ? cancel() : fp.open()));
}
