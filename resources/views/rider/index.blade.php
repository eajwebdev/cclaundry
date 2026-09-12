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
        })"
    x-init="start()"
>
    <div x-ref="runs">
        @include('rider.partials.runs')
    </div>
</div>
@endsection
