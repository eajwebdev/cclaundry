@extends('layouts.rider')

@section('page_title', 'My runs')

@php($trackerJobId = $jobs->first()?->id)

@section('content')
{{--
    The rider's home screen. One job to a card, biggest thing on screen is the
    action they need next, and the location switch is pinned to the bottom so it
    is reachable with a thumb while holding a bag of laundry.
--}}
<div class="space-y-3">

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-2xl font-bold text-primary">{{ $jobs->count() }}</p>
            <p class="text-xs font-medium uppercase tracking-wide text-muted">Open runs</p>
        </div>
        <div class="rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-2xl font-bold text-emerald-600">{{ $completedToday }}</p>
            <p class="text-xs font-medium uppercase tracking-wide text-muted">Done today</p>
        </div>
    </div>

    @forelse($jobs as $job)
        @php($isCollected = $job->status === 'picked_up')
        <article class="overflow-hidden rounded-xl border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3 border-b border-border px-4 py-3 dark:border-gray-800">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $job->customer?->name ?? $job->contact_name }}</p>
                    <p class="truncate font-mono text-xs text-muted">{{ $job->reference_no }}</p>
                </div>
                <span class="shrink-0 rounded-md border px-2 py-1 text-[11px] font-semibold uppercase
                    {{ $isCollected
                        ? 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900/60 dark:bg-sky-500/10 dark:text-sky-300'
                        : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300' }}">
                    {{ $isCollected ? 'Deliver' : 'Collect' }}
                </span>
            </div>

            <div class="space-y-2 px-4 py-3 text-sm">
                <div class="flex gap-2">
                    <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                    <p class="leading-relaxed">
                        {{ $isCollected && $job->delivery_address ? $job->delivery_address : $job->pickup_address }}
                    </p>
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
                    @if($job->is_rush)
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold uppercase text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Rush</span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-border p-3 dark:border-gray-800">
                <a href="{{ route('rider.jobs.show', $job) }}"
                   class="inline-flex h-12 touch-manipulation items-center justify-center gap-2 rounded-lg border border-border text-sm font-semibold dark:border-gray-800">
                    <span data-lucide="map" class="h-4 w-4"></span>
                    Open map
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
    @empty
        <div class="rounded-xl border border-dashed border-border py-14 text-center dark:border-gray-800">
            <span data-lucide="coffee" class="mx-auto mb-3 block h-8 w-8 text-muted"></span>
            <p class="text-sm font-medium">No runs assigned right now.</p>
            <p class="mt-1 text-xs text-muted">Dispatch will assign your next pickup here.</p>
        </div>
    @endforelse
</div>
@endsection

