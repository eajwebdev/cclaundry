@extends('layouts.app')

@section('page_title', 'Rider Dispatch')

@section('content')
@include('partials.map-assets')

<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="truck" class="h-3.5 w-3.5"></span>
                Live fleet
            </div>
            <h1 class="text-xl font-semibold">Rider Dispatch</h1>
        </div>

        @if($canChooseBranch)
            <form method="GET" class="grid grid-cols-1 gap-2 sm:grid-cols-[16rem_auto]">
                <select name="branch_id" onchange="this.form.submit()"
                        class="h-11 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 xl:h-10">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(28rem,1fr)_minmax(20rem,26rem)]">

        {{-- Live map. Polls rather than holding a socket open, so this needs no
             daemon and runs unchanged on shared hosting. --}}
        <section x-data="riderFleetMap({ endpoint: @js(route('admin.riders.locations', ['branch_id' => $selectedBranchId])) })"
                 class="overflow-hidden rounded-xl border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3 dark:border-gray-800">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Map</p>
                    <h2 class="mt-1 text-lg font-semibold">Riders on the road</h2>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-md bg-smoke px-2 py-1 text-xs font-medium text-muted dark:bg-gray-950">
                    <span class="h-2 w-2 rounded-full" :class="live ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                    <span x-text="live ? riders.length + ' live' : 'No riders live'"></span>
                </span>
            </div>

            <div class="relative">
                <div x-ref="map" class="h-[26rem] w-full bg-cream dark:bg-[#1c1510] lg:h-[34rem]"></div>
                <div x-show="!ready" x-cloak
                     class="absolute inset-0 flex items-center justify-center text-xs text-muted">
                    Loading map…
                </div>
            </div>
        </section>

        <div class="space-y-4">
            {{-- Roster --}}
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-border px-4 py-3 dark:border-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Roster</p>
                    <h2 class="mt-1 text-lg font-semibold">{{ $riders->count() }} {{ \Illuminate\Support\Str::plural('rider', $riders->count()) }}</h2>
                </div>

                <ul class="divide-y divide-border dark:divide-gray-800">
                    @forelse($riders as $rider)
                        @php($live = $rider->hasLiveLocation() && $rider->is_sharing_location)
                        <li class="flex items-center gap-3 px-4 py-3">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $live ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600' }}"
                                  title="{{ $live ? 'Sharing location' : 'Offline' }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $rider->name }}</p>
                                <p class="truncate text-xs text-muted">
                                    {{ $live ? 'Live · updated '.$rider->last_location_at->diffForHumans() : 'Not sharing location' }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-md bg-smoke px-2 py-1 text-xs font-semibold dark:bg-gray-950">
                                {{ $rider->open_jobs_count }}
                            </span>
                        </li>
                    @empty
                        <li class="px-4 py-10 text-center text-sm text-muted">
                            No riders yet. Add a user with the <span class="font-medium">Rider</span> role.
                        </li>
                    @endforelse
                </ul>
            </section>

            {{-- Runs currently out --}}
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-border px-4 py-3 dark:border-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">In progress</p>
                    <h2 class="mt-1 text-lg font-semibold">{{ $activeRuns->count() }} active {{ \Illuminate\Support\Str::plural('run', $activeRuns->count()) }}</h2>
                </div>

                <ul class="divide-y divide-border dark:divide-gray-800">
                    @forelse($activeRuns as $run)
                        <li class="px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $run->customer?->name ?? $run->contact_name }}</p>
                                    <p class="truncate font-mono text-xs text-muted">{{ $run->reference_no }}</p>
                                </div>
                                <span class="{{ \App\Support\StatusBadge::classes($run->status) }} shrink-0">
                                    {{ \App\Support\StatusBadge::label($run->status) }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-xs text-muted">
                                <span data-lucide="user" class="inline-block h-3 w-3 align-[-2px]"></span>
                                {{ $run->rider?->name }}
                            </p>
                        </li>
                    @empty
                        <li class="px-4 py-10 text-center text-sm text-muted">Nothing out for delivery.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.riderFleetMap = (config) => ({
        ready: false,
        live: false,
        riders: [],
        map: null,
        markers: new Map(),
        timer: null,

        init() {
            this.$nextTick(() => {
                if (!window.AppMaps) return;

                this.map = window.AppMaps.createMap(this.$refs.map);
                this.map.on('load', () => {
                    this.ready = true;
                    this.map.resize();
                });

                this.refresh();
            });

            // Stop polling while the tab is hidden. A dispatch board is often
            // left open all day, and there is no point asking the server for
            // positions nobody is looking at.
            document.addEventListener('visibilitychange', () => {
                document.hidden ? this.pause() : this.refresh();
            });
        },

        pause() {
            clearTimeout(this.timer);
            this.timer = null;
        },

        async refresh() {
            this.pause();

            try {
                const response = await fetch(config.endpoint, { headers: { Accept: 'application/json' } });

                if (response.ok) {
                    const body = await response.json();
                    this.riders = body.riders ?? [];
                    this.live = this.riders.length > 0;
                    this.draw();
                    this.schedule(body.poll_interval);
                    return;
                }
            } catch {
                // Transient network trouble — keep the last known positions on
                // screen and try again on the next tick.
            }

            this.schedule();
        },

        schedule(seconds) {
            const interval = (seconds ?? window.mapConfig?.tracking?.pollInterval ?? 10) * 1000;
            this.timer = setTimeout(() => this.refresh(), interval);
        },

        draw() {
            if (!this.map) return;

            const seen = new Set();

            this.riders.forEach((rider) => {
                seen.add(rider.id);
                const position = [rider.longitude, rider.latitude];
                const existing = this.markers.get(rider.id);

                if (existing) {
                    window.AppMaps.moveRiderMarker(existing, position, {
                        heading: rider.heading,
                        duration: (window.mapConfig?.tracking?.pollInterval ?? 10) * 900,
                    });
                    return;
                }

                const marker = new window.AppMaps.maplibregl.Marker({
                    element: window.AppMaps.createRiderMarker({ title: rider.name, heading: rider.heading }),
                    anchor: 'center',
                })
                    .setLngLat(position)
                    .setPopup(new window.AppMaps.maplibregl.Popup({ offset: 34 }).setText(rider.name))
                    .addTo(this.map);

                this.markers.set(rider.id, marker);
            });

            // Drop riders who have gone offline rather than leaving a marker
            // frozen where they last were.
            this.markers.forEach((marker, id) => {
                if (!seen.has(id)) {
                    marker.remove();
                    this.markers.delete(id);
                }
            });
        },
    });
</script>
@endpush
