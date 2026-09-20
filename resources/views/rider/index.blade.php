@extends('layouts.rider')

@section('page_title', 'My runs')

@php
    // The layout's location tracker pins itself to whatever run is live.
    $trackerJobId = $toDeliver->first()?->id ?? $toCollect->first()?->id;
@endphp

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
            signature: @js($runsSignature),
            availableIds: @js($available->pluck('id')->values()),
            assignedIds: @js($toCollect->pluck('id')->merge($toDeliver->pluck('id'))->values()),
        })"
    x-init="start()"
>
    <div x-show="newRunMessage" x-cloak role="status" class="mb-3 flex items-start justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
        <span x-text="newRunMessage"></span>
        <button type="button" @click="newRunMessage = ''" aria-label="Dismiss new run notice" class="shrink-0 rounded p-1 hover:bg-sky-100 dark:hover:bg-sky-900">Dismiss</button>
    </div>
    <p x-show="offline" x-cloak role="status" class="mb-3 text-xs text-amber-700">Could not check for new runs. Showing the last list and retrying automatically.</p>
    <div x-ref="runs">
        @include('rider.partials.runs')
    </div>
</div>
@endsection
