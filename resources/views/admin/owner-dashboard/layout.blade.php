{{-- Shared frame of the three owner-dashboard pages: header, tabs, the date/branch filter bar and the load error.
     Pages extend this and fill `od_subtitle` and `widgets`; owner-dashboard.js fills every [data-od] body. --}}
@extends('layouts.app')

@php
    $fromDate = \Carbon\CarbonImmutable::parse($period->from);
    $toDate = \Carbon\CarbonImmutable::parse($period->to);
    $card = 'rounded-xl border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-5';
@endphp

@push('scripts')
    @vite('resources/js/owner-dashboard.js')
@endpush

@section('content')
<div
    id="owner-dashboard"
    data-page="{{ $page }}"
    data-endpoint="{{ route("owner-dashboard.api.$page") }}"
    data-from="{{ $period->from }}"
    data-to="{{ $period->to }}"
    data-branch="{{ $period->branchId ?? 'all' }}"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-amber-800 dark:text-amber-400">{{ $appBusinessName }}</div>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl" style="font-family: 'Playfair Display', Georgia, serif;">@yield('page_title')</h1>
            <p class="mt-0.5 text-sm text-muted">@yield('od_subtitle')</p>
        </div>
        <nav class="inline-flex self-start rounded-lg border border-border bg-smoke p-0.5 text-xs dark:border-gray-800 dark:bg-gray-950 sm:self-auto">
            @foreach(['overview' => 'Overview', 'supplies' => 'Supplies', 'profit' => 'Profit & costs'] as $tab => $label)
                <a href="{{ $links[$tab] }}"
                   @class([
                       'rounded-md px-3 py-1.5 font-medium',
                       'bg-white text-dark shadow-xs dark:bg-gray-800 dark:text-gray-100' => $tab === $page,
                       'text-muted hover:text-dark dark:hover:text-gray-100' => $tab !== $page,
                   ])
                   @if($tab === $page) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    <div class="{{ $card }} flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-xs font-medium text-muted">Dates</span>
            <button type="button" id="od-date-button" class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-3 py-1.5 text-sm hover:bg-smoke dark:border-gray-700 dark:bg-gray-950 dark:hover:bg-gray-800">
                <span data-lucide="calendar-days" class="h-4 w-4 text-amber-800 dark:text-amber-400"></span>
                <span><span class="text-muted">From</span> <strong>{{ $fromDate->format('M j, Y') }}</strong> → <span class="text-muted">To</span> <strong>{{ $toDate->format('M j, Y') }}</strong></span>
            </button>
            <span class="text-xs text-muted">{{ $period->calendarDays }} {{ $period->calendarDays === 1 ? 'day' : 'days' }}</span>
        </div>
        <label class="flex items-center gap-2 text-xs text-muted">
            <span>Branch</span>
            <select id="od-branch" class="h-9 rounded-lg border border-border bg-white px-2.5 text-sm text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                <option value="all" @selected($period->branchId === null)>All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($period->branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div id="od-error" hidden class="flex items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        <span>The figures could not be loaded.</span>
        <button type="button" id="od-retry" class="rounded-lg bg-red-700 px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Retry</button>
    </div>

    <div id="od-widgets" class="space-y-4">
        @yield('widgets')
    </div>
</div>
@endsection
