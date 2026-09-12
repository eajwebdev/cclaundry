@extends('layouts.rider')

@section('page_title', $job->reference_no)
@section('full_bleed', true)

@php
    // Unclaimed bookings open here too, so a rider can see where one is before
    // taking it. Confirming is what puts it on their list.
    $isMine = $isMine ?? true;
    $isCollected = $job->status === 'picked_up';
    $destination = $job->destinationCoordinates();
    $address = $isCollected && $job->delivery_address ? $job->delivery_address : $job->pickup_address;
    $trackerJobId = $job->id;
@endphp

@section('content')
@include('partials.map-assets')

{{--
    Navigation screen. The map owns the viewport and everything else floats on
    top of it, so a rider never scrolls to find the next instruction or the
    button that closes the job out.
--}}
<div
    @if($destination)
        x-data="riderNav({
                destination: @js(['latitude' => $destination[0], 'longitude' => $destination[1]]),
                routeUrl: @js(route('rider.jobs.route', $job)),
                rerouteAfterMetres: @js((int) config('maps.routing.reroute_after_metres')),
            })"
        x-init="$nextTick(() => window.renderLucideIcons?.())"
    @else
        x-data="{ sheetOpen: false }"
    @endif
    class="relative h-[calc(100dvh-3.75rem)] w-full overflow-hidden"
>
    @if($destination)
        <div x-ref="map" class="absolute inset-0 bg-cream dark:bg-[#1c1510]"></div>
    @else
        <div class="absolute inset-0 flex items-center justify-center bg-cream px-8 text-center text-sm text-muted dark:bg-[#1c1510]">
            No map pin on this booking, follow the address below.
        </div>
    @endif

    {{-- ══ Top: the next instruction, the one thing a moving rider reads ══ --}}
    @if($destination)
        <div class="pointer-events-none absolute inset-x-0 top-0 z-20 p-3">
            <div class="pointer-events-auto flex items-center gap-3 rounded-xl bg-white/95 px-3.5 py-3 shadow-lg backdrop-blur dark:bg-[#241a13]/95">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-white">
                    <span data-lucide="navigation" class="h-5 w-5"></span>
                </span>

                <div class="min-w-0 flex-1">
                    <template x-if="current && nextStep">
                        <div>
                            <p class="truncate text-sm font-semibold leading-tight" x-text="nextStep.instruction"></p>
                            <p class="mt-0.5 text-xs text-muted">
                                <span x-text="distanceLabel"></span> ·
                                <span x-text="etaMinutes + ' min'"></span> to
                                {{ $isCollected ? 'drop-off' : 'pickup' }}
                            </p>
                        </div>
                    </template>

                    <template x-if="!current">
                        <p class="truncate text-sm font-medium text-muted"
                           x-text="loadingRoute ? 'Finding the best route…'
                                 : (routeError ? 'No route available, follow the pin'
                                 : (failed ? 'Map unavailable, use the address' : 'Waiting for GPS…'))"></p>
                    </template>
                </div>

                <span x-show="loadingRoute" x-cloak
                      class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-primary/30 border-t-primary"></span>
            </div>

            {{-- Alternatives, when the road network actually offers one. --}}
            <div x-show="routes.length > 1" x-cloak class="pointer-events-auto mt-2 flex gap-1.5 overflow-x-auto pb-1">
                <template x-for="(route, index) in routes" :key="index">
                    <button type="button" @click="selectRoute(index); fitRoute()"
                            class="inline-flex h-9 shrink-0 touch-manipulation items-center gap-1.5 rounded-full px-3 text-xs font-semibold shadow-sm transition"
                            :class="index === activeRoute
                                ? 'bg-primary text-white'
                                : 'bg-white/95 text-dark dark:bg-[#241a13]/95 dark:text-gray-100'">
                        <span data-lucide="route" class="h-3.5 w-3.5"></span>
                        <span x-text="Math.max(1, Math.round(route.duration / 60)) + ' min'"></span>
                        <span class="opacity-70"
                              x-text="'· ' + (route.distance >= 1000
                                    ? (route.distance / 1000).toFixed(1) + ' km'
                                    : Math.round(route.distance) + ' m')"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- ══ Right rail: map controls, thumb-reachable ══ --}}
        <div class="absolute right-3 top-1/2 z-20 flex -translate-y-1/2 flex-col gap-2">
            <button type="button" @click="recenter()"
                    aria-label="Recentre on me"
                    class="inline-flex h-12 w-12 touch-manipulation items-center justify-center rounded-full bg-white/95 shadow-lg backdrop-blur transition dark:bg-[#241a13]/95"
                    :class="following ? 'text-primary' : 'text-muted'">
                <span data-lucide="locate-fixed" class="h-5 w-5"></span>
            </button>

            <button type="button" @click="fitRoute()"
                    aria-label="Show whole route"
                    class="inline-flex h-12 w-12 touch-manipulation items-center justify-center rounded-full bg-white/95 text-muted shadow-lg backdrop-blur dark:bg-[#241a13]/95">
                <span data-lucide="maximize" class="h-5 w-5"></span>
            </button>

            {{-- Tap the map to force the route through a point of the rider's
                 choosing — the practical way to override a route when OSRM
                 only offers one. --}}
            <button type="button" @click="toggleViaPicking()"
                    aria-label="Route via a point I choose"
                    class="inline-flex h-12 w-12 touch-manipulation items-center justify-center rounded-full shadow-lg backdrop-blur transition"
                    :class="pickingVia ? 'bg-sky-600 text-white' : 'bg-white/95 text-muted dark:bg-[#241a13]/95'">
                <span data-lucide="git-branch" class="h-5 w-5"></span>
            </button>

            <button type="button" x-show="via.length" x-cloak @click="clearVia()"
                    aria-label="Clear my detour"
                    class="inline-flex h-12 w-12 touch-manipulation items-center justify-center rounded-full bg-white/95 text-red-600 shadow-lg backdrop-blur dark:bg-[#241a13]/95">
                <span data-lucide="x" class="h-5 w-5"></span>
            </button>
        </div>

        <div x-show="pickingVia" x-cloak
             class="pointer-events-none absolute inset-x-0 top-28 z-20 flex justify-center px-4">
            <p class="rounded-full bg-sky-600 px-4 py-2 text-xs font-semibold text-white shadow-lg">
                Tap the road you want to go through
            </p>
        </div>
    @endif

    {{-- ══ Bottom sheet: job detail and the action, always in reach ══ --}}
    <div class="absolute inset-x-0 bottom-0 z-30">
        <div class="rounded-t-2xl bg-white shadow-[0_-8px_30px_rgba(43,32,24,0.18)] dark:bg-[#241a13]">

            {{-- Collapsed by default: the rider needs the map, not the detail.
                 Tapping the handle reveals notes, phone and branch. --}}
            <button type="button" @click="sheetOpen = !sheetOpen"
                    aria-label="Toggle booking detail"
                    class="flex w-full touch-manipulation items-center justify-center px-4 pb-1.5 pt-2.5">
                <span class="block h-1 w-10 rounded-full bg-border dark:bg-gray-700"></span>
            </button>

            <div class="flex items-center gap-3 px-4 pb-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg
                    {{ $isCollected ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }}">
                    <span data-lucide="{{ $isCollected ? 'package-check' : 'hand-helping' }}" class="h-5 w-5"></span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ $job->customer?->name ?? $job->contact_name }}</p>
                    <p class="truncate text-xs text-muted">{{ $address }}</p>
                </div>

                @if($job->contact_phone)
                    <a href="tel:{{ $job->contact_phone }}" aria-label="Call customer"
                       class="inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center rounded-lg border border-border dark:border-gray-800">
                        <span data-lucide="phone" class="h-4 w-4"></span>
                    </a>
                @endif
            </div>

            {{-- Expanded detail --}}
            <div x-show="sheetOpen" x-cloak x-transition
                 class="space-y-2.5 border-t border-border px-4 py-3 text-sm dark:border-gray-800">
                <div class="flex justify-between gap-3">
                    <span class="text-xs uppercase tracking-wide text-muted">Reference</span>
                    <span class="font-mono text-xs">{{ $job->reference_no }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="shrink-0 text-xs uppercase tracking-wide text-muted">Service</span>
                    @if($job->items->isNotEmpty())
                        {{-- Spelled out: the rider is holding the bag the customer
                             described, and can see it is all there. --}}
                        <span class="min-w-0 text-right text-xs">
                            @foreach ($job->items as $item)
                                <span class="block">{{ $item->service_name }} &middot; {{ $item->quantityLabel() }}</span>
                            @endforeach
                        </span>
                    @else
                        <span class="truncate text-xs">{{ $job->serviceTypeLabel() }}</span>
                    @endif
                </div>
                @if($job->pickup_landmark)
                    <div class="flex justify-between gap-3">
                        <span class="shrink-0 text-xs uppercase tracking-wide text-muted">Landmark</span>
                        <span class="text-right text-xs">{{ $job->pickup_landmark }}</span>
                    </div>
                @endif
                @if($job->notes)
                    <div class="rounded-lg bg-smoke px-3 py-2 text-xs leading-relaxed dark:bg-gray-950">
                        {{ $job->notes }}
                    </div>
                @endif

                <a href="{{ route('rider.index') }}"
                   class="inline-flex h-11 w-full touch-manipulation items-center justify-center gap-2 rounded-lg border border-border text-sm font-medium dark:border-gray-800">
                    <span data-lucide="arrow-left" class="h-4 w-4"></span>
                    All runs
                </a>
            </div>

            {{-- The action, pinned above the home indicator --}}
            @if(! $isMine)
                {{-- Nobody has taken this one yet. --}}
                <form method="POST" action="{{ route('rider.jobs.claim', $job) }}"
                      class="px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
                    @csrf
                    <button type="submit"
                            class="inline-flex h-14 w-full touch-manipulation items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-white shadow-sm transition hover:opacity-90">
                        <span data-lucide="check" class="h-5 w-5"></span>
                        Confirm pickup and take this run
                    </button>
                </form>
            @elseif(in_array($job->status, ['confirmed', 'picked_up'], true))
                <form method="POST" action="{{ route('rider.jobs.status', $job) }}"
                      class="px-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $isCollected ? 'completed' : 'picked_up' }}">

                    @unless($isCollected)
                        {{-- Filled in at the door: the tag that goes on the bag,
                             and what the customer handed over. Payment is taken
                             on pickup, so it is recorded with the collection. --}}
                        <div class="mb-3 space-y-2 rounded-xl border border-border p-3 dark:border-gray-800">
                            {{-- What the customer said they would pay with, so the
                                 rider knows before knocking. --}}
                            <p class="flex items-center gap-2 text-xs font-semibold text-muted">
                                <span data-lucide="{{ $job->payment_method === 'gcash' ? 'smartphone' : 'wallet' }}" class="h-3.5 w-3.5 text-primary"></span>
                                Customer chose {{ $job->paymentMethodLabel() }}
                            </p>

                            <div>
                                <label for="tag_code" class="block text-xs font-semibold text-muted">Bag tag # <span class="text-primary">*</span></label>
                                <input id="tag_code" name="tag_code" required maxlength="24" autocomplete="off"
                                       value="{{ old('tag_code', $job->tag_code ?: $job->suggestedTagCode()) }}"
                                       class="mt-1 h-11 w-full rounded-lg border border-border bg-white px-3 font-mono text-base uppercase dark:border-gray-700 dark:bg-gray-950">
                                <p class="mt-1 text-[11px] text-muted">Write this on the bag. It follows the laundry through the branch.</p>
                                @error('tag_code') <p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="collected_amount" class="block text-xs font-semibold text-muted">Amount collected</label>
                                    <input id="collected_amount" name="collected_amount" type="number" inputmode="decimal" step="0.01" min="0"
                                           placeholder="{{ $job->estimated_total ? number_format((float) $job->estimated_total, 2, '.', '') : '0.00' }}"
                                           value="{{ old('collected_amount') }}"
                                           class="mt-1 h-11 w-full rounded-lg border border-border bg-white px-3 text-base dark:border-gray-700 dark:bg-gray-950">
                                </div>
                                <div>
                                    <label for="collected_payment_method" class="block text-xs font-semibold text-muted">Paid by</label>
                                    <select id="collected_payment_method" name="collected_payment_method"
                                            class="mt-1 h-11 w-full rounded-lg border border-border bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-950">
                                        @foreach(\App\Support\Booking::paymentMethods() as $methodKey => $methodLabel)
                                            <option value="{{ $methodKey }}" @selected(old('collected_payment_method', $job->payment_method) === $methodKey)>{{ $methodLabel }}</option>
                                        @endforeach
                                        <option value="unpaid" @selected(old('collected_payment_method') === 'unpaid')>Not paid yet</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endunless
                    <button type="submit"
                            x-on:click.prevent="(() => {
                                const form = $el.closest('form');
                                const tag = form.querySelector('[name=tag_code]');

                                if (tag && ! tag.value.trim()) { tag.focus(); return; }

                                Swal.fire({
                                    title: @js($isCollected ? 'Mark as delivered?' : 'Mark as collected?'),
                                    text: tag
                                        ? 'Tag ' + tag.value.toUpperCase() + ' goes on this bag.'
                                        : 'Confirm the customer has their laundry back.',
                                    icon: 'question',
                                    showCancelButton: true,
                                    confirmButtonColor: '#A07148',
                                    confirmButtonText: @js($isCollected ? 'Delivered' : 'Collected'),
                                }).then((result) => { if (result.isConfirmed) form.submit(); });
                            })()"
                            class="inline-flex h-14 w-full touch-manipulation items-center justify-center gap-2 rounded-xl text-base font-semibold text-white shadow-sm transition
                                {{ $isCollected ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-primary hover:opacity-90' }}">
                        <span data-lucide="{{ $isCollected ? 'package-check' : 'hand-helping' }}" class="h-5 w-5"></span>
                        {{ $isCollected ? 'Mark delivered' : 'Mark collected' }}
                    </button>
                </form>

                {{-- The ways out, deliberately quieter than the action above:
                     hand the run back, or close it off with a reason the branch
                     can repeat to the customer. --}}
                <div class="grid {{ $job->status === 'confirmed' ? 'grid-cols-2' : 'grid-cols-1' }} gap-2 px-4 pt-2 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
                    @if($job->status === 'confirmed')
                        <form method="POST" action="{{ route('rider.jobs.release', $job) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    x-on:click.prevent="Swal.fire({
                                        title: 'Hand this run back?',
                                        text: 'It goes back on the branch list for another rider to take.',
                                        icon: 'question',
                                        showCancelButton: true,
                                        confirmButtonColor: '#A07148',
                                        confirmButtonText: 'Hand back',
                                    }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })"
                                    class="inline-flex h-12 w-full touch-manipulation items-center justify-center gap-2 rounded-lg border border-border text-sm font-semibold dark:border-gray-800">
                                <span data-lucide="undo-2" class="h-4 w-4"></span>
                                Hand back
                            </button>
                        </form>
                    @endif

                    @if($job->isCancellable())
                        <form method="POST" action="{{ route('rider.jobs.cancel', $job) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="reason" x-ref="cancelReason">
                            <button type="submit"
                                    x-on:click.prevent="Swal.fire({
                                        title: 'Cancel this booking?',
                                        input: 'text',
                                        inputLabel: 'What happened? The branch will tell the customer.',
                                        inputPlaceholder: 'e.g. nobody home after three calls',
                                        inputAttributes: { maxlength: 200 },
                                        icon: 'warning',
                                        showCancelButton: true,
                                        confirmButtonColor: '#b42318',
                                        confirmButtonText: 'Cancel booking',
                                        cancelButtonText: 'Keep it',
                                        inputValidator: (value) => (! value || ! value.trim())
                                            &amp;&amp; 'Please say what happened.',
                                    }).then((result) => {
                                        if (! result.isConfirmed) return;
                                        $refs.cancelReason.value = result.value;
                                        $el.closest('form').submit();
                                    })"
                                    class="inline-flex h-12 w-full touch-manipulation items-center justify-center gap-2 rounded-lg border border-red-200 text-sm font-semibold text-red-700 dark:border-red-900/60 dark:text-red-300">
                                <span data-lucide="x" class="h-4 w-4"></span>
                                Cancel
                            </button>
                        </form>
                    @endif
                </div>
            @else
                {{-- Finished or called off. Nothing left to do, but the run stays
                     openable so a rider can check what they dropped off
                     yesterday without ringing the branch. --}}
                <div class="space-y-2 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
                    <div class="rounded-xl border border-border p-3 text-sm dark:border-gray-800">
                        <p class="flex items-center gap-2 font-semibold">
                            <span data-lucide="{{ $job->status === 'cancelled' ? 'x' : 'package-check' }}"
                                  class="h-4 w-4 {{ $job->status === 'cancelled' ? 'text-red-600' : 'text-emerald-600' }}"></span>
                            {{ $job->status === 'cancelled' ? 'Cancelled' : 'Delivered' }}
                            {{ ($job->status === 'cancelled' ? $job->cancelled_at : $job->delivered_at)?->format('D, M j · g:i A') }}
                        </p>

                        <dl class="mt-2 space-y-1 text-xs text-muted">
                            @if($job->tag_code)
                                <div class="flex justify-between gap-3">
                                    <dt>Bag tag</dt>
                                    <dd class="font-mono font-semibold text-primary">{{ $job->tag_code }}</dd>
                                </div>
                            @endif
                            @if($job->collected_amount !== null)
                                <div class="flex justify-between gap-3">
                                    <dt>Collected</dt>
                                    <dd class="font-semibold">₱{{ number_format((float) $job->collected_amount, 2) }}
                                        ({{ ucfirst($job->collected_payment_method ?? 'cash') }})</dd>
                                </div>
                            @endif
                            @if($job->cancellation_reason)
                                <div class="flex justify-between gap-3">
                                    <dt class="shrink-0">Reason</dt>
                                    <dd class="text-right">{{ $job->cancellation_reason }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    <a href="{{ route('rider.index') }}"
                       class="inline-flex h-12 w-full touch-manipulation items-center justify-center gap-2 rounded-lg border border-border text-sm font-semibold dark:border-gray-800">
                        <span data-lucide="arrow-left" class="h-4 w-4"></span>
                        Back to my runs
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
