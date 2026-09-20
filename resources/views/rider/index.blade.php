@extends('layouts.rider')

@section('page_title', 'My runs')

@section('content')
{{--
    The rider's home screen. One job to a card, biggest thing on screen is the
    action they need next, and the work is grouped by what it needs: take it,
    collect it, deliver it. Anything already finished drops to the bottom.

    The list refreshes itself: a booking confirmed at the counter should reach
    the rider holding the phone without them thinking to pull down.
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
            defaultToday: @js($defaultToday),
        })"
    x-init="start()"
>
    <span class="sr-only" role="status" aria-live="polite" x-text="screenReaderMessage"></span>
    <p x-show="offline" x-cloak role="status" class="mb-3 text-xs text-amber-700">Could not check for new runs. Showing the last list and retrying automatically.</p>

    <form method="GET" action="{{ route('rider.index') }}" class="mb-3 rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-2 flex items-center justify-between gap-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Show runs for</p>
            <button type="button" @click="showToday()" class="text-xs font-semibold text-primary underline underline-offset-2">Today</button>
        </div>
        <input type="hidden" name="tab" value="{{ $selectedTab }}" :value="activeTab">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <label class="min-w-0 text-xs font-medium text-muted">
                From
                <input type="date" name="from" required value="{{ $rangeFrom }}" x-model="rangeFrom"
                       @change="if (rangeFrom && rangeTo && rangeFrom > rangeTo) rangeTo = rangeFrom"
                       class="mt-1 h-11 w-full min-w-0 rounded-lg border border-border bg-white px-2 text-sm text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-white">
            </label>
            <label class="min-w-0 text-xs font-medium text-muted">
                To
                <input type="date" name="to" required value="{{ $rangeTo }}" x-model="rangeTo"
                       @change="if (rangeFrom && rangeTo && rangeTo < rangeFrom) rangeFrom = rangeTo"
                       class="mt-1 h-11 w-full min-w-0 rounded-lg border border-border bg-white px-2 text-sm text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-white">
            </label>
            <button type="submit" class="h-11 rounded-lg bg-primary px-4 text-sm font-semibold text-white">Apply dates</button>
        </div>
        <p class="mt-2 text-[11px] leading-4 text-muted">Unscheduled deliveries appear when the range includes Today.</p>
    </form>
    <div x-ref="runs">
        @include('rider.partials.runs')
    </div>
</div>
@endsection
