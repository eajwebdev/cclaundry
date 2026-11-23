@extends('admin.owner-dashboard.layout')

@section('page_title', 'Overview')
@section('od_subtitle', 'Sales, orders, closings and busy times for the selected period')

@php($card = 'rounded-xl border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-5')
@php($loading = 'text-sm text-muted')

@section('widgets')
    <div data-od="kpis" data-profit-url="{{ $links['profit'] }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <p class="{{ $loading }}">Loading…</p>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <section class="{{ $card }} lg:col-span-2">
            <h2 class="text-lg font-bold">How customers paid</h2>
            <div data-od="payments" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        </section>
        <section class="{{ $card }} lg:col-span-3">
            <h2 class="text-lg font-bold">How laundry came in and went back</h2>
            <div data-od="intake" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        </section>
    </div>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Daily closings · Z-Reading</h2>
            <p class="text-xs text-muted">Each day is closed once: Shop Cash, GCash and machine counters</p>
        </div>
        <div data-od="closings" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
        <div data-od="closing-full" class="mt-3" hidden></div>
    </section>

    <section class="{{ $card }}" data-card="busiest">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Busiest days and times</h2>
            <p class="text-xs text-muted">Average per weekday in the selected period</p>
        </div>
        <div data-od="busiest" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
    </section>

    <section class="{{ $card }}" data-card="hourly" hidden>
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">Orders per hour</h2>
            <p class="text-xs text-muted">Orders taken each hour of the selected day</p>
        </div>
        <div data-od="hourly" class="mt-3"></div>
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-lg font-bold">What customers ordered</h2>
            <p class="text-xs text-muted" data-od-sub="services"></p>
        </div>
        <div data-od="services" data-supplies-url="{{ $links['supplies'] }}" class="mt-3"><p class="{{ $loading }}">Loading…</p></div>
    </section>
@endsection
