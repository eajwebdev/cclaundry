@extends('admin.owner-dashboard.layout')

@section('page_title', 'Supplies')
@section('od_subtitle', 'What the shop used, bought and adjusted in the selected period')

@php($card = 'rounded-xl border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-5')
@php($loading = 'text-sm text-muted')

@section('widgets')
    <div data-od="kpis" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <p class="{{ $loading }}">Loading…</p>
    </div>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Supplies used over the period</h2>
            <p class="text-xs text-muted">One bar per day · long date ranges group bars by week or month</p>
        </div>
        <div data-od="usage" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Each supply item</h2>
            <p class="text-xs text-muted">Liquids in mL · per order = average used on one order</p>
        </div>
        <div data-od="items" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        <p class="mt-3 rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-900 dark:bg-orange-950 dark:text-orange-200"><strong>Check the "per order" column:</strong> if it jumps from one period to the next, staff are using too much product or stock is going missing.</p>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold">Stock purchased</h2>
                <p class="text-xs text-muted" data-od-sub="purchases"></p>
            </div>
            <div data-od="purchases" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        </section>
        <section class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold">Adjustments</h2>
                <p class="text-xs text-muted" data-od-sub="adjustments"></p>
            </div>
            <div data-od="adjustments" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
            <p class="mt-3 rounded-lg bg-smoke px-3 py-2 text-xs dark:bg-gray-950">Every adjustment needs a reason. Count corrections that repeat on the same item are worth checking.</p>
        </section>
    </div>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Add-ons sold</h2>
            <p class="text-xs text-muted" data-od-sub="addons"></p>
        </div>
        <div data-od="addons" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
    </section>
@endsection
