@props([
    'name' => 'pickup',
    'label' => 'Pin the exact spot',
    'latitude' => null,
    'longitude' => null,
    'addressField' => 'pickup_address',
    'height' => 'h-64 sm:h-72',
])

@php
    $hasPin = \App\Support\Geocoder::isValidCoordinate($latitude, $longitude);
@endphp

{{-- The component pulls in its own bundle, so dropping <x-map-picker> on a page
     is all that is needed. map-assets is @once-guarded, so the pickup and
     delivery pickers on the booking form share a single copy. --}}
@include('partials.map-assets')

{{--
    Address picker: pick a barangay, search a street, drag the pin, or tap
    "use my location".

    The typed address stays the source of truth for the rider — Kabankalan
    addresses are described by landmark far more often than by street number —
    and the pin adds the precision a map needs. Either alone still books.
--}}
<div
    x-data="mapPicker({
        name: @js($name),
        addressField: @js($addressField),
        latitude: @js($hasPin ? (float) $latitude : null),
        longitude: @js($hasPin ? (float) $longitude : null),
    })"
    x-init="init()"
    class="space-y-2"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="block text-sm font-medium">{{ $label }}</span>

        <button type="button" @click="locateMe()" :disabled="locating"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-medium transition hover:border-primary/50 disabled:opacity-50 dark:border-white/12">
            <span data-lucide="locate-fixed" class="h-3.5 w-3.5"></span>
            <span x-text="locating ? 'Finding you…' : 'Use my location'"></span>
        </button>
    </div>

    {{-- Search: barangays answer locally and instantly, streets come from
         Nominatim scoped to Kabankalan. --}}
    <div class="relative">
        <div class="flex h-11 items-center gap-2 rounded-xl border border-border bg-white px-3 dark:border-white/12 dark:bg-[#241a13]">
            <span data-lucide="search" class="h-4 w-4 shrink-0 text-muted"></span>
            <input type="search" x-model="query" @input.debounce.350ms="search()"
                   @focus="search()" @keydown.escape="results = []"
                   placeholder="Search a barangay, street or landmark…"
                   class="w-full min-w-0 bg-transparent text-sm outline-none">
            <span x-show="searching" x-cloak class="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-primary/30 border-t-primary"></span>
        </div>

        <ul x-show="results.length" x-cloak @click.outside="results = []"
            x-effect="results.length; $nextTick(() => window.renderLucideIcons?.())"
            class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-border bg-white py-1 shadow-lg dark:border-white/12 dark:bg-[#241a13]">
            <template x-for="result in results" :key="result.kind + result.label + result.context">
                <li>
                    <button type="button" @click="choose(result)"
                            class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left transition hover:bg-cream dark:hover:bg-white/5">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                              :class="result.kind === 'barangay'
                                    ? 'bg-primary/12 text-primary'
                                    : 'bg-accent/15 text-accent-deep'">
                            <span :data-lucide="result.kind === 'barangay' ? 'map-pin' : (result.kind === 'street' ? 'route' : 'store')"
                                  class="h-3.5 w-3.5"></span>
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium" x-text="result.label"></span>
                            <span class="block truncate text-xs text-muted" x-text="result.context"></span>
                        </span>
                    </button>
                </li>
            </template>
        </ul>
    </div>

    <div class="relative overflow-hidden rounded-xl border border-border dark:border-white/12">
        <div x-ref="map" class="{{ $height }} w-full bg-cream dark:bg-[#1c1510]"></div>

        {{-- Shown until MapLibre is up, and if it never comes up. Opaque, so the
             blank canvas and a floating pin never show through underneath. --}}
        <div x-show="!ready"
             class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2.5 bg-cream px-4 text-center text-xs text-muted dark:bg-[#1c1510]">
            <span x-show="!failed" class="h-6 w-6 animate-spin rounded-full border-2 border-primary/25 border-t-primary"></span>
            <span x-show="failed" x-cloak data-lucide="map-pin" class="h-6 w-6 text-primary"></span>
            <span x-text="failed
                ? 'Map unavailable here — your address and barangay are enough.'
                : 'Loading map…'">Loading map…</span>
        </div>
    </div>

    <p class="text-xs text-muted" x-show="ready" x-cloak>
        Drag the pin to your gate. This is what the rider follows.
    </p>

    <p x-show="outsideServiceArea" x-cloak
       class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        That pin is outside our usual Kabankalan service area. You can still book — we will call to confirm we can reach you.
    </p>

    <input type="hidden" name="{{ $name }}_latitude" :value="latitude">
    <input type="hidden" name="{{ $name }}_longitude" :value="longitude">
</div>
