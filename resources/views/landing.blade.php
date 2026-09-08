@extends('layouts.public')

@section('page_title', 'Laundry pickup & delivery, booked in a minute')

@php
    $businessName = $appBusinessName ?: config('app.name');
    $contactNumber = $settings?->contact_number;
@endphp

@section('content')

{{-- ══════════════════════════════ HERO ══════════════════════════════ --}}
<section class="relative overflow-hidden">
    {{-- Soft cane/sage wash behind the fold --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-40 -right-32 h-[34rem] w-[34rem] rounded-full bg-primary/10 blur-3xl"></div>
        <div class="absolute top-40 -left-40 h-[26rem] w-[26rem] rounded-full bg-accent/12 blur-3xl"></div>
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/25 to-transparent"></div>
    </div>

    <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 pt-14 pb-20 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16 lg:px-8 lg:pt-20 lg:pb-28">
        <div>
            <span class="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/8 px-3.5 py-1.5 text-[11px] font-semibold tracking-[0.16em] text-primary uppercase">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-60"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-primary"></span>
                </span>
                Free pickup &amp; delivery
            </span>

            <h1 class="mt-6 font-serif text-[2.75rem] leading-[1.05] font-medium tracking-tight text-primary-deep sm:text-6xl lg:text-[4.25rem] dark:text-cane">
                Laundry day,<br>
                <span class="relative inline-block">
                    <span class="relative z-10">handled</span>
                    <svg class="absolute -bottom-1 left-0 -z-0 h-3 w-full text-accent/45" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M2 8 C 50 2, 150 2, 198 7" stroke="currentColor" stroke-width="4" fill="none" stroke-linecap="round"/>
                    </svg>
                </span><span class="text-primary">.</span>
            </h1>

            <p class="mt-6 max-w-lg text-[17px] leading-relaxed text-muted">
                Book a pickup in about a minute. We collect at your door, wash and fold with cotton-soft care,
                and bring everything back the way it should be &mdash; fresh, folded and on time.
            </p>

            <div class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a href="#book"
                   class="group inline-flex h-13 items-center justify-center gap-2.5 rounded-full bg-primary px-7 text-[15px] font-semibold text-white shadow-xl shadow-primary/25 transition hover:bg-primary-deep hover:shadow-2xl hover:shadow-primary/30">
                    <span data-lucide="truck" class="h-4.5 w-4.5"></span>
                    Book a free pickup
                    <span data-lucide="arrow-right" class="h-4 w-4 transition-transform group-hover:translate-x-0.5"></span>
                </a>
                <a href="#track"
                   class="inline-flex h-13 items-center justify-center gap-2.5 rounded-full border border-border bg-white/70 px-6 text-[15px] font-semibold text-primary-deep transition hover:border-primary/40 hover:bg-white dark:border-white/12 dark:bg-white/5 dark:text-cane">
                    <span data-lucide="search" class="h-4 w-4"></span>
                    Track my order
                </a>
            </div>

            @php
                // The SMS promise only appears when SMS is actually enabled.
                $heroStats = [
                    ['24h', 'Standard turnaround'],
                    ['Free', 'Pickup & delivery'],
                ];

                if ($settings?->sms_enabled) {
                    $heroStats[] = ['SMS', 'Updates at every step'];
                } else {
                    $heroStats[] = [$branches->count(), \Illuminate\Support\Str::plural('Branch', $branches->count()).' near you'];
                }
            @endphp
            <dl class="mt-12 grid max-w-lg grid-cols-3 gap-6 border-t border-border pt-8 dark:border-white/10">
                @foreach ($heroStats as [$value, $label])
                    <div>
                        <dt class="font-serif text-2xl font-semibold text-primary">{{ $value }}</dt>
                        <dd class="mt-1 text-[13px] leading-snug text-muted">{{ $label }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Hero visual: the mark, framed the way it is on the sign --}}
        <div class="relative mx-auto w-full max-w-lg">
            <div class="relative aspect-square">
                <div class="absolute inset-0 rounded-full bg-gradient-to-br from-[#F6EFE5] to-[#EFE3D2] shadow-2xl shadow-primary/15 dark:from-[#241a13] dark:to-[#1c1510]"></div>
                <div class="absolute inset-5 rounded-full border border-primary/25"></div>
                <x-brand-mark class="absolute inset-0 m-auto h-[82%] w-[82%] shadow-lg shadow-primary/10" />
            </div>

            {{-- Floating proof cards --}}
            <div class="absolute -top-2 -left-4 hidden rounded-2xl border border-border bg-white/95 px-4 py-3 shadow-xl shadow-dark/8 backdrop-blur sm:block dark:border-white/10 dark:bg-[#241a13]/95">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-accent/15 text-accent-deep">
                        <span data-lucide="leaf" class="h-4.5 w-4.5"></span>
                    </span>
                    <div>
                        <p class="text-[13px] font-semibold">Gentle on fabric</p>
                        <p class="text-[11px] text-muted">Skin-safe detergents</p>
                    </div>
                </div>
            </div>

            <div class="absolute -right-2 bottom-8 hidden rounded-2xl border border-border bg-white/95 px-4 py-3 shadow-xl shadow-dark/8 backdrop-blur sm:block dark:border-white/10 dark:bg-[#241a13]/95">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/12 text-primary">
                        <span data-lucide="packageCheck" class="h-4.5 w-4.5"></span>
                    </span>
                    <div>
                        <p class="text-[13px] font-semibold">Folded, not crumpled</p>
                        <p class="text-[11px] text-muted">Sorted per household</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ SERVICES ══════════════════════════════ --}}
<section id="services" class="scroll-mt-24 border-y border-border bg-[#F9F4EC] py-20 sm:py-24 dark:border-white/10 dark:bg-[#1c1510]">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">What we do</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                Every kind of load, one pickup
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                Pick what you need when you book. If you are not sure, choose the closest match &mdash; we will
                sort the rest when we weigh your bag at the branch.
            </p>
        </div>

        {{-- Pinned bundles and services, so the prices here are the live ones. --}}
        @if($offerings->isEmpty())
            <div class="mt-12 rounded-3xl border border-dashed border-border bg-white px-8 py-14 text-center dark:border-white/12 dark:bg-[#241a13]">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                    <span data-lucide="tag" class="h-6 w-6"></span>
                </span>
                <p class="mt-5 font-medium">Our service list is being updated</p>
                <p class="mx-auto mt-1.5 max-w-md text-sm leading-relaxed text-muted">
                    Please call the branch to book in the meantime &mdash; we will have online booking back shortly.
                </p>
            </div>
        @else
            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($offerings as $offering)
                    <article class="group relative flex flex-col overflow-hidden rounded-3xl border bg-white p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-primary/8 dark:bg-[#241a13]
                        {{ $offering['type'] === 'preset' ? 'border-primary/35 ring-1 ring-primary/15' : 'border-border hover:border-primary/30 dark:border-white/10' }}">

                        <div class="flex items-start justify-between gap-3">
                            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary transition group-hover:bg-primary group-hover:text-white">
                                <span data-lucide="{{ $offering['icon'] }}" class="h-5 w-5"></span>
                            </span>
                            @if($offering['type'] === 'preset')
                                <span class="rounded-full bg-primary/12 px-2.5 py-1 text-[10px] font-semibold tracking-wide text-primary uppercase">Bundle</span>
                            @endif
                        </div>

                        <h3 class="mt-5 font-serif text-xl font-medium text-primary-deep dark:text-cane">{{ $offering['name'] }}</h3>
                        @if($offering['blurb'])
                            <p class="mt-2 text-sm leading-relaxed text-muted">{{ $offering['blurb'] }}</p>
                        @endif

                        {{-- What a bundle actually contains, straight from its preset items. --}}
                        @if($offering['includes'])
                            <ul class="mt-3 space-y-1.5">
                                @foreach($offering['includes'] as $included)
                                    <li class="flex items-center gap-2 text-[13px] text-muted">
                                        <span data-lucide="check" class="h-3.5 w-3.5 shrink-0 text-accent-deep"></span>
                                        {{ $included }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-5 flex items-end justify-between border-t border-border pt-4 dark:border-white/10">
                            <div>
                                <p class="text-[11px] tracking-wide text-muted uppercase">{{ $offering['type'] === 'preset' ? 'All in' : 'Starts at' }}</p>
                                <p class="font-serif text-2xl font-semibold text-primary">&#8369;{{ number_format($offering['price'], 2) }}</p>
                            </div>
                            <p class="pb-1 text-[11px] text-muted">{{ $offering['unit'] }}</p>
                        </div>

                        <a href="#book" @click="$dispatch('preselect-offering', '{{ $offering['key'] }}')"
                           class="absolute inset-0" aria-label="Book {{ $offering['name'] }}"></a>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>

{{-- ══════════════════════════════ HOW IT WORKS ══════════════════════════════ --}}
<section id="how" class="scroll-mt-24 py-20 sm:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">How it works</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                Four steps, and you are done
            </h2>
        </div>

        <div class="relative mt-14">
            {{-- The thread that ties the steps together on wide screens --}}
            <div aria-hidden="true" class="absolute top-7 right-[12.5%] left-[12.5%] hidden h-px bg-gradient-to-r from-primary/15 via-primary/35 to-primary/15 lg:block"></div>

            <ol class="relative grid gap-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
                @foreach ([
                    ['calendar', 'Book online', 'Tell us what you have, where to collect it and when. Takes about a minute.'],
                    ['truck', 'We collect', 'Our rider arrives in your chosen window. Nothing to weigh or prepare.'],
                    ['laundry', 'Wash &amp; fold', 'Sorted, washed at the right temperature, dried and folded by hand.'],
                    ['packageCheck', 'Back at your door', 'Delivered on the day you picked, with an SMS before we arrive.'],
                ] as $index => [$icon, $title, $body])
                    <li class="relative text-center lg:text-left">
                        <div class="flex justify-center lg:justify-start">
                            <span class="relative flex h-14 w-14 items-center justify-center rounded-2xl border border-primary/20 bg-white text-primary shadow-sm dark:bg-[#241a13]">
                                <span data-lucide="{{ $icon }}" class="h-5.5 w-5.5"></span>
                                <span class="absolute -top-2 -right-2 flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white">{{ $index + 1 }}</span>
                            </span>
                        </div>
                        <h3 class="mt-5 font-serif text-lg font-medium text-primary-deep dark:text-cane">{!! $title !!}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted">{!! $body !!}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ BOOKING FORM ══════════════════════════════ --}}
@include('partials.booking-form')

{{-- ══════════════════════════════ RATES ══════════════════════════════ --}}
@if($priceList->isNotEmpty())
<section id="rates" class="scroll-mt-24 border-y border-border bg-[#F9F4EC] py-20 sm:py-24 dark:border-white/10 dark:bg-[#1c1510]">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">Rates</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                The whole price list, nothing hidden
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                Your bag is weighed at the branch and priced from this list. The estimate you see when booking is
                a guide &mdash; the final total is confirmed before we start.
            </p>
        </div>

        <div x-data="{ openGroup: '{{ $priceList->keys()->first() }}' }" class="mt-11 grid items-start gap-4 lg:grid-cols-2">
            @foreach ($priceList as $categoryName => $services)
                <div class="overflow-hidden rounded-2xl border border-border bg-white dark:border-white/10 dark:bg-[#241a13]">
                    <button type="button" @click="openGroup = openGroup === @js($categoryName) ? null : @js($categoryName)"
                            class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left transition hover:bg-smoke dark:hover:bg-white/5">
                        <span class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <span data-lucide="tag" class="h-4 w-4"></span>
                            </span>
                            <span>
                                <span class="block font-medium">{{ $categoryName }}</span>
                                <span class="block text-xs text-muted">{{ $services->count() }} {{ Str::plural('item', $services->count()) }}</span>
                            </span>
                        </span>
                        <span data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-muted transition-transform"
                              :class="openGroup === @js($categoryName) ? 'rotate-180' : ''"></span>
                    </button>

                    <div x-show="openGroup === @js($categoryName)" x-cloak x-transition class="border-t border-border dark:border-white/10">
                        <ul class="divide-y divide-border dark:divide-white/8">
                            @foreach ($services as $service)
                                <li class="flex items-center justify-between gap-4 px-5 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm">{{ $service->name }}</p>
                                        <p class="text-[11px] text-muted">{{ [
                                            'kilo' => 'Per kilo',
                                            'load' => 'Per load',
                                            'piece' => 'Per piece',
                                            'custom' => 'Fixed price',
                                        ][$service->pricing_type] ?? ucfirst($service->pricing_type) }}</p>
                                    </div>
                                    <p class="shrink-0 text-sm font-semibold text-primary">&#8369;{{ number_format((float) $service->price, 2) }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ══════════════════════════════ WHY US ══════════════════════════════ --}}
<section class="py-20 sm:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid gap-12 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16">
            <div>
                <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">Why {{ $businessName }}</p>
                <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                    Careful hands, honest pricing
                </h2>
                <p class="mt-4 text-[15px] leading-relaxed text-muted">
                    We built this the way we would want our own clothes handled: separate loads per household,
                    detergents that will not irritate skin, and a price you can check before you book.
                </p>

                @if($contactNumber)
                    <a href="tel:{{ preg_replace('/\s+/', '', $contactNumber) }}"
                       class="mt-8 inline-flex items-center gap-3 rounded-2xl border border-border bg-white px-5 py-4 transition hover:border-primary/35 dark:border-white/10 dark:bg-[#241a13]">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <span data-lucide="phone" class="h-4.5 w-4.5"></span>
                        </span>
                        <span>
                            <span class="block text-[11px] tracking-wide text-muted uppercase">Prefer to talk?</span>
                            <span class="block font-serif text-lg font-semibold text-primary-deep dark:text-cane">{{ $contactNumber }}</span>
                        </span>
                    </a>
                @endif
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                @foreach ([
                    ['shieldCheck', 'Nothing gets mixed up', 'Every booking is bagged, tagged and washed on its own. Your load never shares a drum with someone else&rsquo;s.'],
                    ['scale', 'Weighed in front of you', 'We confirm the weight and the total before a single machine starts.'],
                    $settings?->sms_enabled
                        ? ['sms', 'You are never left guessing', 'An SMS when we collect, when it is ready and when the rider is on the way.']
                        : ['phone', 'You are never left guessing', 'We call ahead when we collect, when it is ready and when the rider is on the way.'],
                    ['heart', 'Fabric-first care', 'Colour sorting, correct temperatures and hand-finishing for anything delicate.'],
                ] as [$icon, $title, $body])
                    <div class="rounded-2xl border border-border bg-white p-6 transition hover:border-primary/25 dark:border-white/10 dark:bg-[#241a13]">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-accent/15 text-accent-deep">
                            <span data-lucide="{{ $icon }}" class="h-4.5 w-4.5"></span>
                        </span>
                        <h3 class="mt-4 font-medium text-primary-deep dark:text-cane">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted">{!! $body !!}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ BRANCHES ══════════════════════════════ --}}
@if($branches->isNotEmpty())
<section id="branches" class="scroll-mt-24 border-y border-border bg-[#F9F4EC] py-20 sm:py-24 dark:border-white/10 dark:bg-[#1c1510]">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">Where we are</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                {{ $branches->count() > 1 ? 'Our branches' : 'Our branch' }}
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                Booking online? Choose the branch nearest you and our rider will come from there.
            </p>
        </div>

        <div class="mt-11 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($branches as $branch)
                <div class="rounded-2xl border border-border bg-white p-6 dark:border-white/10 dark:bg-[#241a13]">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-serif text-lg font-medium text-primary-deep dark:text-cane">{{ $branch->name }}</h3>
                        <span class="shrink-0 rounded-full bg-accent/15 px-2.5 py-1 text-[10px] font-semibold tracking-wide text-accent-deep uppercase">Open</span>
                    </div>

                    @if($branch->address)
                        <p class="mt-3 flex items-start gap-2 text-sm leading-relaxed text-muted">
                            <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                            {{ $branch->address }}
                        </p>
                    @endif

                    @if($branch->contact_number)
                        <p class="mt-2 flex items-center gap-2 text-sm text-muted">
                            <span data-lucide="phone" class="h-4 w-4 shrink-0 text-primary"></span>
                            <a href="tel:{{ preg_replace('/\s+/', '', $branch->contact_number) }}" class="transition hover:text-primary">{{ $branch->contact_number }}</a>
                        </p>
                    @endif

                    <a href="#book" @click="$dispatch('preselect-branch', {{ $branch->id }})"
                       class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-primary transition hover:gap-2.5">
                        Book from here
                        <span data-lucide="arrow-right" class="h-3.5 w-3.5"></span>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ══════════════════════════════ BY THE NUMBERS ══════════════════════════════ --}}
@if(count($stats))
<section class="py-20 sm:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">By the numbers</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                Where we are today
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-muted">
                Counted live from our own system, not rounded up for a website.
            </p>
        </div>

        @php
            // Written out in full: Tailwind scans source text, so a class built
            // by string interpolation is never generated.
            $statColumns = match (min(count($stats), 4)) {
                1 => 'lg:grid-cols-1',
                2 => 'lg:grid-cols-2',
                3 => 'lg:grid-cols-3',
                default => 'lg:grid-cols-4',
            };
        @endphp
        <dl class="mx-auto mt-12 grid max-w-4xl gap-5 sm:grid-cols-2 {{ $statColumns }}">
            @foreach ($stats as $stat)
                <div class="rounded-3xl border border-border bg-white p-7 text-center dark:border-white/10 dark:bg-[#241a13]">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                        <span data-lucide="{{ $stat['icon'] }}" class="h-5 w-5"></span>
                    </span>
                    <dt class="mt-5 font-serif text-4xl font-semibold text-primary">{{ $stat['value'] }}</dt>
                    <dd class="mt-1.5 text-sm leading-snug text-muted">{{ $stat['label'] }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
@endif

{{-- ══════════════════════════════ TRACK ══════════════════════════════ --}}
<section id="track" class="scroll-mt-24 py-4">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-3xl border border-primary/20 bg-gradient-to-br from-[#F6EFE5] to-[#EFE3D2] p-8 sm:p-11 dark:from-[#241a13] dark:to-[#1c1510]">
            <div class="grid items-center gap-8 lg:grid-cols-[1fr_1.15fr]">
                <div>
                    <h2 class="font-serif text-2xl leading-tight font-medium text-primary-deep sm:text-3xl dark:text-cane">
                        Already booked? Track it here
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted">
                        Enter the reference number from your confirmation together with the mobile number you booked with.
                    </p>
                </div>

                <form method="POST" action="{{ route('track') }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    @csrf
                    <div>
                        <label for="track_reference" class="sr-only">Reference number</label>
                        <input id="track_reference" type="text" name="reference_no" value="{{ old('reference_no') }}" required
                               placeholder="PU-260908-0001"
                               class="h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#191310]">
                    </div>
                    <div>
                        <label for="track_phone" class="sr-only">Mobile number</label>
                        <input id="track_phone" type="tel" name="phone" value="{{ old('phone') }}" required
                               placeholder="09XX XXX XXXX"
                               class="h-12 w-full rounded-xl border border-border bg-white px-4 text-sm outline-none transition placeholder:text-muted/60 focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-white/12 dark:bg-[#191310]">
                    </div>
                    <button type="submit"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary px-6 text-sm font-semibold text-white shadow-lg shadow-primary/20 transition hover:bg-primary-deep">
                        <span data-lucide="search" class="h-4 w-4"></span>
                        Track
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ FAQ ══════════════════════════════ --}}
<section id="faq" class="scroll-mt-24 py-20 sm:py-24">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">Questions</p>
            <h2 class="mt-3 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                Everything people ask us
            </h2>
        </div>

        <div x-data="{ open: 0 }" class="mt-11 space-y-3">
            @foreach ([
                ['Do I need an account to book?', 'You can fill in the whole booking form first &mdash; we only ask you to create an account at the last step, and it takes a few seconds. The account is what lets you track the order, cancel it and rebook with one tap next time.'],
                ['How much does pickup and delivery cost?', 'Pickup is free. Delivery is charged by zone and is added to your final total at the branch, so you always see it before you pay.'],
                ['When will my laundry come back?', 'Standard turnaround is 24 hours from pickup. Choose rush when booking and we prioritise your load for same-day handling where the schedule allows.'],
                ['How is the price calculated?', 'Loads are priced by weight against our published rate list. The estimate you see while booking is a guide; the exact total is confirmed once your bag is weighed at the branch.'],
                ['Can I change or cancel a booking?', 'Yes. Open My bookings and cancel any request that has not been collected yet. To move a pickup to another day, cancel and rebook, or call the branch directly.'],
                ['What if something is damaged or missing?', 'Tell the branch within 24 hours of delivery. Every booking is bagged and tagged on its own, so we can trace exactly where your item went.'],
            ] as $index => [$question, $answer])
                <div class="overflow-hidden rounded-2xl border border-border bg-white transition dark:border-white/10 dark:bg-[#241a13]"
                     :class="open === {{ $index }} ? 'border-primary/30 shadow-lg shadow-primary/5' : ''">
                    <button type="button" @click="open = open === {{ $index }} ? null : {{ $index }}"
                            class="flex w-full items-center justify-between gap-4 px-6 py-5 text-left">
                        <span class="text-[15px] font-medium">{{ $question }}</span>
                        <span data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-primary transition-transform"
                              :class="open === {{ $index }} ? 'rotate-180' : ''"></span>
                    </button>
                    <div x-show="open === {{ $index }}" x-cloak x-transition class="px-6 pb-5">
                        <p class="text-sm leading-relaxed text-muted">{!! $answer !!}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════════════════════ CLOSING CTA ══════════════════════════════ --}}
<section class="pb-4">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-[2rem] bg-primary-deep px-8 py-14 text-center sm:px-14 sm:py-20">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -top-24 -right-16 h-72 w-72 rounded-full bg-cane/15 blur-3xl"></div>
                <div class="absolute -bottom-24 -left-16 h-72 w-72 rounded-full bg-accent/20 blur-3xl"></div>
            </div>

            <div class="relative">
                <h2 class="mx-auto max-w-2xl font-serif text-3xl leading-tight font-medium text-cream sm:text-[2.75rem]">
                    Give your weekend back
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-[15px] leading-relaxed text-cream/75">
                    Book a pickup now and have it all back tomorrow, fresh and folded.
                </p>
                <a href="#book"
                   class="mt-9 inline-flex h-13 items-center justify-center gap-2.5 rounded-full bg-cream px-8 text-[15px] font-semibold text-primary-deep shadow-xl transition hover:bg-white">
                    <span data-lucide="truck" class="h-4.5 w-4.5"></span>
                    Book a free pickup
                </a>
            </div>
        </div>
    </div>
</section>

@endsection
