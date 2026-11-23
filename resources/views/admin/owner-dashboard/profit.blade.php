@extends('admin.owner-dashboard.layout')

@section('page_title', 'Profit & costs')
@section('od_subtitle', 'What the shop earned and where the money went in the selected period')

@php($card = 'rounded-xl border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-5')
@php($loading = 'text-sm text-muted')

@section('widgets')
    <div data-od="equation"><p class="{{ $loading }}">Loading…</p></div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <h2 class="text-lg font-bold">Money in vs money out</h2>
                <p class="text-xs text-muted">Same scale — the green part is what you keep</p>
            </div>
            <div data-od="money-chart" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        </section>
        <section class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold">On one average order</h2>
                <p class="text-xs text-muted" data-od-sub="per-order"></p>
            </div>
            <div data-od="per-order" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        </section>
    </div>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Break-even</h2>
            <p class="text-xs text-muted">Sales needed to pay every cost</p>
        </div>
        <div data-od="break-even" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Staff</h2>
            <p class="text-xs text-muted">Paid weekly · pay week Saturday to Friday, paid Saturday · cash, bank or GCash</p>
        </div>
        <div data-od="staff" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        <p class="mt-3 text-xs text-muted">Employer share = SSS, PhilHealth, Pag-IBIG. Days in orange = fewer days than scheduled.</p>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold">Bills</h2>
                <p class="text-xs text-muted" data-od-sub="bills"></p>
            </div>
            <div data-od="bills" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        </section>
        <section class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold">Other purchases</h2>
                <p class="text-xs text-muted" data-od-sub="other"></p>
            </div>
            <div data-od="other" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
            <a href="{{ $links['supplies'] }}" class="mt-3 flex items-center justify-between rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-900 hover:opacity-90 dark:bg-orange-950 dark:text-orange-200">
                <span><strong>Supplies used</strong><span data-od-sub="supplies-items"></span></span>
                <span class="font-semibold"><span data-od-sub="supplies-used"></span> →</span>
            </a>
        </section>
    </div>
@endsection
