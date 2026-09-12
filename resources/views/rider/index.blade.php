@extends('layouts.rider')

@section('page_title', 'My runs')

@php
    $trackerJobId = $toDeliver->first()?->id ?? $toCollect->first()?->id;

    /**
     * A run is worked by date: collect on the pickup day, deliver on the
     * delivery day (which is often the next day, or "when the branch says so").
     * These chips are what let a rider scan the list and know what is theirs
     * *today* without reading every card.
     */
    // Built here rather than inline in the template: Blade's directive parser
    // does not survive a multi-line array literal with list destructuring.
    $workGroups = [
        ['mode' => 'collect', 'heading' => 'To collect', 'icon' => 'hand-helping', 'jobs' => $toCollect],
        ['mode' => 'deliver', 'heading' => 'To deliver', 'icon' => 'package-check', 'jobs' => $toDeliver],
    ];

    $whenChip = function (?\Illuminate\Support\Carbon $date) {
        if (! $date) {
            return ['Any day', 'border-border text-muted dark:border-gray-800'];
        }

        return match (true) {
            $date->isToday() => ['Today', 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'],
            $date->isTomorrow() => ['Tomorrow', 'border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-300'],
            $date->isPast() => ['Overdue', 'border-red-300 bg-red-50 text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300'],
            default => [$date->format('M j'), 'border-border text-muted dark:border-gray-800'],
        };
    };
@endphp

@section('content')
{{--
    The rider's home screen. One job to a card, biggest thing on screen is the
    action they need next, and the work is grouped by what it needs: take it,
    collect it, deliver it. Anything already finished drops to the bottom.
--}}
<div class="space-y-3">

    <div class="grid grid-cols-3 gap-2">
        <div class="rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-2xl font-bold text-amber-600">{{ $toCollect->count() }}</p>
            <p class="text-[11px] font-medium uppercase tracking-wide text-muted">To collect</p>
        </div>
        <div class="rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-2xl font-bold text-sky-600">{{ $toDeliver->count() }}</p>
            <p class="text-[11px] font-medium uppercase tracking-wide text-muted">To deliver</p>
        </div>
        <div class="rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-2xl font-bold text-emerald-600">{{ $completedToday }}</p>
            <p class="text-[11px] font-medium uppercase tracking-wide text-muted">Done today</p>
        </div>
    </div>

    {{-- ══ Up for grabs: confirming one of these assigns it to this rider ══ --}}
    @if($available->isNotEmpty())
        <div class="pt-1">
            <h2 class="flex items-center gap-2 px-1 pb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                <span data-lucide="hand-helping" class="h-3.5 w-3.5"></span>
                Available now &middot; {{ $available->count() }}
            </h2>

            <div class="space-y-3">
                @foreach ($available as $job)
                    {{-- Never name a Blade directive inside a comment: Blade
                         reads it as the real thing and swallows the markup up
                         to the next closing tag. --}}
                    @php
                        $chip = $whenChip($job->pickup_date);
                        [$chipLabel, $chipClasses] = $chip;
                    @endphp
                    <article class="overflow-hidden rounded-xl border border-dashed border-primary/40 bg-white shadow-sm dark:bg-gray-900">
                        <div class="flex items-start justify-between gap-3 border-b border-border px-4 py-3 dark:border-gray-800">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ $job->customer?->name ?? $job->contact_name }}</p>
                                <p class="truncate font-mono text-xs text-muted">{{ $job->reference_no }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if($job->is_rush)
                                    <span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold uppercase text-amber-800 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300">Rush</span>
                                @endif
                                <span class="rounded-md border px-2 py-1 text-[11px] font-semibold {{ $chipClasses }}">{{ $chipLabel }}</span>
                            </div>
                        </div>

                        <div class="space-y-2 px-4 py-3 text-sm">
                            <div class="flex gap-2">
                                <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                                <p class="leading-relaxed">{{ $job->pickup_address }}</p>
                            </div>

                            @if($job->pickup_landmark)
                                <div class="flex gap-2 text-muted">
                                    <span data-lucide="flag" class="mt-0.5 h-4 w-4 shrink-0"></span>
                                    <p class="text-xs leading-relaxed">{{ $job->pickup_landmark }}</p>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                                <span class="inline-flex items-center gap-1.5">
                                    <span data-lucide="calendar" class="h-3.5 w-3.5"></span>
                                    {{ $job->pickup_date?->format('M j') }} &middot; {{ $job->pickupSlotLabel() }}
                                </span>
                                @php
                                    $away = \App\Http\Controllers\Rider\RiderController::remainingKm($job, $rider);
                                @endphp
                                @if($away !== null)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span data-lucide="navigation" class="h-3.5 w-3.5"></span>
                                        {{ $away }} km away
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-[1fr_auto] gap-2 border-t border-border p-3 dark:border-gray-800">
                            <form method="POST" action="{{ route('rider.jobs.claim', $job) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex h-12 w-full touch-manipulation items-center justify-center gap-2 rounded-lg bg-primary text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                                    <span data-lucide="check" class="h-4 w-4"></span>
                                    Confirm pickup
                                </button>
                            </form>

                            <a href="{{ route('rider.jobs.show', $job) }}" aria-label="Look at the map first"
                               class="inline-flex h-12 w-12 touch-manipulation items-center justify-center rounded-lg border border-border dark:border-gray-800">
                                <span data-lucide="map" class="h-4 w-4"></span>
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ══ The two halves of a run: go and get it, then take it back ══ --}}
    @foreach ($workGroups as $workGroup)
        @php
            $mode = $workGroup['mode'];
            $heading = $workGroup['heading'];
            $headingIcon = $workGroup['icon'];
            $group = $workGroup['jobs'];
        @endphp
        @if($group->isNotEmpty())
            <div class="pt-1">
                <h2 class="flex items-center gap-2 px-1 pb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                    <span data-lucide="{{ $headingIcon }}" class="h-3.5 w-3.5"></span>
                    {{ $heading }} &middot; {{ $group->count() }}
                </h2>

                <div class="space-y-3">
                    @foreach ($group as $job)
                        @php
                            $isDelivering = $mode === 'deliver';
                            // Deliveries run to their own date, which is often the
                            // day after collection and sometimes not set at all.
                            $workDate = $isDelivering ? $job->delivery_date : $job->pickup_date;
                            [$chipLabel, $chipClasses] = $whenChip($workDate);
                            $address = $isDelivering && $job->delivery_address ? $job->delivery_address : $job->pickup_address;
                        @endphp
                        <article class="overflow-hidden rounded-xl border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-start justify-between gap-3 border-b border-border px-4 py-3 dark:border-gray-800">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold">{{ $job->customer?->name ?? $job->contact_name }}</p>
                                    <p class="truncate font-mono text-xs text-muted">
                                        {{ $job->reference_no }}
                                        @if($job->tag_code)
                                            &middot; <span class="text-primary">{{ $job->tag_code }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    @if($job->is_rush)
                                        <span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold uppercase text-amber-800 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300">Rush</span>
                                    @endif
                                    <span class="rounded-md border px-2 py-1 text-[11px] font-semibold {{ $chipClasses }}">{{ $chipLabel }}</span>
                                </div>
                            </div>

                            <div class="space-y-2 px-4 py-3 text-sm">
                                <div class="flex gap-2">
                                    <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                                    <p class="leading-relaxed">{{ $address }}</p>
                                </div>

                                @if(! $isDelivering && $job->pickup_landmark)
                                    <div class="flex gap-2 text-muted">
                                        <span data-lucide="flag" class="mt-0.5 h-4 w-4 shrink-0"></span>
                                        <p class="text-xs leading-relaxed">{{ $job->pickup_landmark }}</p>
                                    </div>
                                @endif

                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span data-lucide="calendar" class="h-3.5 w-3.5"></span>
                                        @if($isDelivering)
                                            {{ $workDate ? $workDate->format('M j').' · '.($job->deliverySlotLabel() ?: 'any time') : 'Deliver when the branch says ready' }}
                                        @else
                                            {{ $job->pickup_date?->format('M j') }} &middot; {{ $job->pickupSlotLabel() }}
                                        @endif
                                    </span>

                                    @if($isDelivering && $job->collected_amount !== null)
                                        <span class="inline-flex items-center gap-1.5">
                                            <span data-lucide="wallet" class="h-3.5 w-3.5"></span>
                                            ₱{{ number_format((float) $job->collected_amount, 2) }} collected
                                        </span>
                                    @endif
                                </div>

                                @if($isDelivering)
                                    {{-- Collected today, delivered tomorrow: the rider needs to
                                         know whether the branch has actually finished it. --}}
                                    <p class="flex items-center gap-2 rounded-lg bg-smoke px-2.5 py-1.5 text-xs text-muted dark:bg-gray-950">
                                        <span data-lucide="{{ $job->jobOrder ? 'activity' : 'clock' }}" class="h-3.5 w-3.5 shrink-0"></span>
                                        @if($job->jobOrder)
                                            {{ $job->jobOrder->job_order_number }} &middot; {{ \App\Support\StatusBadge::label($job->jobOrder->status) }}
                                        @else
                                            With the branch, not started yet
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <div class="grid grid-cols-2 gap-2 border-t border-border p-3 dark:border-gray-800">
                                <a href="{{ route('rider.jobs.show', $job) }}"
                                   class="inline-flex h-12 touch-manipulation items-center justify-center gap-2 rounded-lg border border-border text-sm font-semibold dark:border-gray-800">
                                    <span data-lucide="map" class="h-4 w-4"></span>
                                    {{ $isDelivering ? 'Deliver' : 'Open map' }}
                                </a>

                                @if($job->contact_phone)
                                    <a href="tel:{{ $job->contact_phone }}"
                                       class="inline-flex h-12 touch-manipulation items-center justify-center gap-2 rounded-lg border border-border text-sm font-semibold dark:border-gray-800">
                                        <span data-lucide="phone" class="h-4 w-4"></span>
                                        Call
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    @if($toCollect->isEmpty() && $toDeliver->isEmpty())
        <div class="rounded-xl border border-dashed border-border py-14 text-center dark:border-gray-800">
            <span data-lucide="coffee" class="mx-auto mb-3 block h-8 w-8 text-muted"></span>
            <p class="text-sm font-medium">You are not holding any runs.</p>
            <p class="mt-1 text-xs text-muted">
                {{ $available->isNotEmpty()
                    ? 'Confirm one of the bookings above to take it.'
                    : 'New bookings at your branch show up here to confirm.' }}
            </p>
        </div>
    @endif

    {{-- ══ Finished: the last week, so yesterday's run is still checkable ══ --}}
    @if($recent->isNotEmpty())
        <div x-data="{ open: false }" class="pt-2">
            <button type="button" @click="open = !open"
                    class="flex w-full touch-manipulation items-center justify-between gap-2 rounded-xl border border-border bg-white px-4 py-3 text-left dark:border-gray-800 dark:bg-gray-900">
                <span class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted">
                    <span data-lucide="history" class="h-3.5 w-3.5"></span>
                    Finished this week &middot; {{ $recent->count() }}
                </span>
                <span data-lucide="chevron-down" class="h-4 w-4 text-muted transition-transform" :class="open && 'rotate-180'"></span>
            </button>

            <ul x-show="open" x-cloak x-transition class="mt-2 space-y-2">
                @foreach ($recent as $job)
                    @php
                        $wasCancelled = $job->status === 'cancelled';
                    @endphp
                    <li class="rounded-xl border border-border bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $job->customer?->name ?? $job->contact_name }}</p>
                                <p class="truncate font-mono text-[11px] text-muted">
                                    {{ $job->reference_no }}@if($job->tag_code) &middot; {{ $job->tag_code }} @endif
                                </p>
                            </div>
                            <span class="shrink-0 rounded-md border px-2 py-1 text-[11px] font-semibold
                                {{ $wasCancelled
                                    ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-500/10 dark:text-red-300'
                                    : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-500/10 dark:text-emerald-300' }}">
                                {{ $wasCancelled ? 'Cancelled' : 'Delivered' }}
                            </span>
                        </div>

                        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted">
                            <span class="inline-flex items-center gap-1.5">
                                <span data-lucide="calendar" class="h-3 w-3"></span>
                                {{ ($wasCancelled ? $job->cancelled_at : $job->delivered_at)?->format('D, M j · g:i A') }}
                            </span>
                            @if($job->collected_amount !== null)
                                <span class="inline-flex items-center gap-1.5">
                                    <span data-lucide="wallet" class="h-3 w-3"></span>
                                    ₱{{ number_format((float) $job->collected_amount, 2) }}
                                </span>
                            @endif
                        </p>

                        @if($wasCancelled && $job->cancellation_reason)
                            <p class="mt-1 text-[11px] text-muted">{{ $job->cancellation_reason }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endsection
