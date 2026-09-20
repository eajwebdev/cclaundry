@extends('layouts.rider')

@section('page_title', 'My runs')

@section('content')
{{--
    The rider's home screen. One job to a card, biggest thing on screen is the
    action they need next, and the work is grouped by what it needs: take it,
    collect it, deliver it. Anything already finished drops to the bottom.

    The list refreshes itself: a new customer booking should reach the rider
    holding the phone without them thinking to pull down.
--}}
<div
    x-data="riderRuns({
            feedUrl: @js(route('rider.runs')),
            indexUrl: @js(route('rider.index')),
            signature: @js($runsSignature),
            collectIds: @js($available->pluck('id')->merge($toCollect->pluck('id'))->values()),
            activeTab: @js($selectedTab),
            rangeFrom: @js($rangeFrom),
            rangeTo: @js($rangeTo),
            dateRangeValue: @js($dateRangeValue),
            defaultToday: @js($defaultToday),
        })"
    x-init="start()"
>
    <span class="sr-only" role="status" aria-live="polite" x-text="screenReaderMessage"></span>
    <p x-show="offline" x-cloak role="status" class="mb-3 text-xs text-amber-700">Could not check for new runs. Showing the last list and retrying automatically.</p>

    <p x-show="activeTab === 'collect'" class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-medium text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-200">
        Showing all pickups to collect, including future dates.
    </p>
    <form x-show="activeTab !== 'collect'" x-cloak method="GET" action="{{ route('rider.index') }}" class="mb-3 rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Optional date range</p>
        <input type="hidden" name="tab" value="{{ $selectedTab }}" :value="activeTab">
        <div class="flex flex-wrap items-end gap-2">
            <label class="min-w-0 flex-1 text-xs font-medium text-muted">
                Date range
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" value="{{ $dateRangeValue }}"
                       placeholder="Choose a date or range" autocomplete="off"
                       class="mt-1 h-11 w-full min-w-0 rounded-lg border border-border bg-white px-3 text-sm text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-white">
            </label>
            <button type="submit" class="h-11 rounded-lg bg-primary px-4 text-sm font-semibold text-white">Apply</button>
            <button type="button" x-show="dateRange" x-cloak @click="clearDateRange()"
                    class="h-11 rounded-lg border border-border px-3 text-sm font-semibold text-primary dark:border-gray-700">Clear</button>
        </div>
        <p class="mt-2 text-[11px] leading-4 text-muted">Leave blank to see today's deliveries and finished runs. Pickups to collect always show all dates.</p>
    </form>
    <div x-ref="runs">
        @include('rider.partials.runs')
    </div>
</div>
@endsection
