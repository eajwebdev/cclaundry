@extends('layouts.rider')

@section('page_title', 'Run map')
@section('full_bleed', true)

@php
    use App\Support\RiderMapStages;

    $stages = RiderMapStages::all();
@endphp

@section('content')
@include('partials.map-assets')

{{--
    Every run on one map. The list a rider gets on the home screen answers
    "what is mine"; this answers "where do I go next", which on a bike is a
    different question. Pins are coloured by stage and the card that opens
    hands off to the job screen, where the turn-by-turn already lives.
--}}
<div
    x-data="riderOverview({
            jobs: @js($jobs),
            feedUrl: @js(route('rider.map.jobs')),
            stages: @js($stages),
        })"
    x-init="$nextTick(() => window.renderLucideIcons?.())"
    class="relative h-[calc(100dvh-3.75rem)] w-full overflow-hidden"
>
    <div x-ref="map" class="absolute inset-0 h-full w-full bg-cream dark:bg-[#1c1510]"></div>

    {{-- ══ Filters: tap a stage to take it off the map ══ --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 z-20 p-3">
        <div class="pointer-events-auto flex flex-wrap gap-1.5 pr-12">
            @foreach ($stages as $key => $stage)
                <button type="button" @click="toggleStage(@js($key))"
                        :aria-pressed="! hidden.includes(@js($key))"
                        class="inline-flex shrink-0 touch-manipulation items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-[11px] font-semibold shadow-sm backdrop-blur transition"
                        :class="hidden.includes(@js($key))
                            ? 'border-border bg-white/80 text-muted line-through dark:border-gray-800 dark:bg-[#241a13]/80'
                            : 'border-transparent bg-white/95 text-dark dark:bg-[#241a13]/95 dark:text-gray-100'">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $stage['color'] }}"></span>
                    {{ $stage['label'] }}
                    <span class="tabular-nums text-muted" x-text="countFor(@js($key))"></span>
                </button>
            @endforeach
        </div>

        <div x-show="newRunMessage" x-cloak role="status" class="pointer-events-auto mt-2 flex items-start justify-between gap-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-900 shadow-sm dark:border-sky-900 dark:bg-sky-950/70 dark:text-sky-200">
            <span x-text="newRunMessage"></span>
            <button type="button" @click="newRunMessage = ''" aria-label="Dismiss new run notice" class="shrink-0 underline">Dismiss</button>
        </div>

        {{-- Only ever shown when something is actually wrong. --}}
        <p x-show="error" x-cloak
           class="pointer-events-auto mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 shadow-sm dark:bg-amber-500/15 dark:text-amber-300"
           x-text="error"></p>
    </div>

    {{-- ══ Camera controls ══ --}}
    <div x-show="! failed" class="absolute right-3 bottom-[9.5rem] z-20 flex flex-col gap-2">
        <button type="button" @click="recenter()" aria-label="Centre on me"
                class="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-full bg-white/95 shadow-lg backdrop-blur dark:bg-[#241a13]/95">
            <span data-lucide="locate-fixed" class="h-5 w-5"></span>
        </button>
        <button type="button" @click="fitAll()" aria-label="Fit every run"
                class="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-full bg-white/95 shadow-lg backdrop-blur dark:bg-[#241a13]/95">
            <span data-lucide="maximize" class="h-5 w-5"></span>
        </button>
        <button type="button" @click="refresh()" aria-label="Refresh runs"
                class="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-full bg-white/95 shadow-lg backdrop-blur dark:bg-[#241a13]/95">
            <span data-lucide="refresh-cw" class="h-5 w-5" :class="loading && 'animate-spin'"></span>
        </button>
    </div>

    {{-- ══ No map: the same runs as a plain list, so the screen still works ══ --}}
    <div x-show="failed" x-cloak
         class="absolute inset-x-0 top-[5.25rem] bottom-0 z-10 overflow-y-auto bg-cream px-3 pt-2 dark:bg-[#1c1510]">
        <p class="px-1 pb-2 text-xs text-muted">Map unavailable. Every run is listed below with its address.</p>
        <div class="space-y-2 pb-6">
            <template x-for="job in visibleJobs" :key="job.id">
                <a :href="job.url" class="block rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-[#241a13]">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background: ' + stageColor(job.stage)"></span>
                        <span class="text-sm font-semibold" x-text="job.customer"></span>
                        <span class="ml-auto text-[11px] text-muted" x-text="stageLabel(job.stage)"></span>
                    </div>
                    <p class="mt-1 text-xs text-muted" x-text="job.address"></p>
                </a>
            </template>
        </div>
    </div>

    {{-- ══ Bottom sheet: the tapped pin, or a summary when nothing is tapped ══ --}}
    <div x-show="! failed" class="absolute inset-x-0 bottom-0 z-30">
        <div class="max-h-[70svh] overflow-y-auto overscroll-contain rounded-t-2xl bg-white shadow-[0_-8px_30px_rgba(43,32,24,0.18)] dark:bg-[#241a13]">

            {{-- Nothing selected: what is out there, and anything unmappable. --}}
            <div x-show="! selected" class="px-4 pb-[calc(0.9rem+env(safe-area-inset-bottom))] pt-3.5">
                <p class="text-sm font-semibold">
                    <span x-text="mappableJobs.length"></span>
                    <span x-text="mappableJobs.length === 1 ? 'run on the map' : 'runs on the map'"></span>
                </p>
                <p class="mt-0.5 text-xs text-muted">Tap a pin to see the job and start navigating.</p>

                {{-- Never silently lose a booking: if it has no pin, say so and
                     still give the rider a way into it. --}}
                <template x-if="unmappedJobs.length">
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p class="text-xs font-semibold text-amber-900 dark:text-amber-200">
                            <span x-text="unmappedJobs.length"></span>
                            <span x-text="unmappedJobs.length === 1 ? 'run has no map pin' : 'runs have no map pin'"></span>
                        </p>
                        <div class="mt-2 space-y-1.5">
                            <template x-for="job in unmappedJobs" :key="job.id">
                                <a :href="job.url" class="flex items-center gap-2 text-xs font-semibold text-amber-900 underline dark:text-amber-200">
                                    <span data-lucide="map-pin" class="h-3.5 w-3.5 shrink-0"></span>
                                    <span x-text="job.customer + ' · ' + job.address"></span>
                                </a>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- A pin is selected: who, where, and the way in. --}}
            <template x-if="selected">
                <div class="px-4 pb-[calc(0.9rem+env(safe-area-inset-bottom))] pt-3">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 h-3 w-3 shrink-0 rounded-full" :style="'background: ' + stageColor(selected.stage)"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold" x-text="selected.customer"></p>
                            <p class="mt-0.5 text-xs text-muted" x-text="selected.address"></p>
                        </div>
                        <button type="button" @click="clearSelection()" aria-label="Close"
                                class="inline-flex h-9 w-9 shrink-0 touch-manipulation items-center justify-center rounded-lg border border-border dark:border-gray-800">
                            <span data-lucide="x" class="h-4 w-4"></span>
                        </button>
                    </div>

                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
                        <span class="rounded-full px-2 py-0.5 text-white" :style="'background: ' + stageColor(selected.stage)"
                              x-text="stageLabel(selected.stage)"></span>
                        <template x-if="selected.when">
                            <span class="rounded-full border border-border px-2 py-0.5 text-muted dark:border-gray-800" x-text="selected.when"></span>
                        </template>
                        <template x-if="selected.is_rush">
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Rush</span>
                        </template>
                        <template x-if="distanceTo(selected)">
                            <span class="rounded-full border border-border px-2 py-0.5 text-muted dark:border-gray-800"
                                  x-text="roadLabel ?? (distanceTo(selected) + ' direct')"></span>
                        </template>
                        <span class="font-mono text-[10px] text-muted" x-text="selected.reference"></span>
                    </div>

                    {{-- Nothing to drive to yet, and saying exactly what is
                         holding it beats a grey pin that never changes. --}}
                    <template x-if="selected.holding_note">
                        <p class="mt-2.5 rounded-lg bg-smoke px-3 py-2 text-xs text-muted dark:bg-gray-950">
                            <span x-text="selected.holding_note"></span>
                            <span class="mt-0.5 block">This pin is where it goes back to once it is finished.</span>
                            <template x-if="selected.job_order_number">
                                <span class="mt-0.5 block font-mono text-[10px]" x-text="selected.job_order_number"></span>
                            </template>
                        </p>
                    </template>

                    <div class="mt-3 flex gap-2">
                        <a :href="selected.url"
                           class="inline-flex h-12 flex-1 touch-manipulation items-center justify-center gap-2 rounded-xl bg-primary text-sm font-semibold text-white shadow-sm">
                            <span data-lucide="navigation" class="h-4 w-4"></span>
                            <span x-text="selected.stage === 'available' ? 'Open run' : 'Navigate'"></span>
                        </a>
                        <template x-if="selected.phone">
                            <a :href="'tel:' + selected.phone" aria-label="Call customer"
                               class="inline-flex h-12 w-12 shrink-0 touch-manipulation items-center justify-center rounded-xl border border-border dark:border-gray-800">
                                <span data-lucide="phone" class="h-4 w-4"></span>
                            </a>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection
