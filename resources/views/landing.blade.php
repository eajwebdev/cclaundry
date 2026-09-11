@extends('layouts.public')

@section('page_title', 'Laundry pickup & delivery, booked in a minute')
@section('overlay_header', '1')

@php
    $businessName = $appBusinessName ?: config('app.name');
    $contactNumber = $settings?->contact_number;

    // The price list never uses centavos, so "₱30" rather than "₱30.00".
    $peso = fn ($amount) => '₱'.number_format((float) $amount, fmod((float) $amount, 1) ? 2 : 0);
    $unitShort = fn (?string $type) => match ($type) {
        'kilo' => 'kg',
        'load' => 'load',
        'piece' => 'pc',
        'preset' => 'bundle',
        default => null,
    };

    // The headline rate on the home screen: the per-kilo service, if there is one.
    $featured = $offerings->firstWhere('pricing_type', 'kilo') ?? $offerings->first();
    $featuredUnit = $featured ? $unitShort($featured['pricing_type']) : null;
@endphp

@section('content')

{{-- ══════════════════════════════ HERO ══════════════════════════════ --}}
{{-- On phones the brand artwork is the whole screen: logo at the top, towels
     and cotton at the bottom, and the call to action in the cream between.
     Wider screens keep the same art as the hero background, anchored to its
     towels and cotton, with the copy centred over a soft cream wash. --}}
<section class="cc-hero relative -mt-16 lg:-mt-20">
    <div class="relative mx-auto flex min-h-[max(100svh,40rem)] max-w-3xl flex-col items-center px-6 pt-[max(12rem,27svh)] pb-12 text-center md:min-h-[min(100svh,58rem)] md:justify-center md:px-8 md:pt-28 md:pb-44 lg:pt-32">
        <p class="hidden text-xs font-bold tracking-[0.3em] text-cc-brown uppercase md:block">{{ $businessName }}</p>

        <h1 class="font-script text-[clamp(2.1rem,9vw,2.8rem)] leading-[1.08] text-cc-deep md:mt-3 md:text-6xl lg:text-7xl">
            Life&rsquo;s busy &mdash;<br>
            we&rsquo;ll handle the laundry! <span class="text-cc-brown" aria-hidden="true">&#9829;</span>
        </h1>

        <p class="mt-5 hidden max-w-lg text-base leading-relaxed text-cc-muted md:block">
            Book a pickup in about a minute. We collect at your door, wash, dry and fold with care,
            and bring everything back fresh.
        </p>

        <div class="cc-pill mt-6">
            <span class="text-[13px] font-extrabold tracking-[0.12em] uppercase">Free pickup &amp; delivery</span>
            <span class="text-xs font-semibold text-cc-muted">Minimum 5 kg</span>
        </div>

        <div class="mt-6 flex w-full max-w-[20rem] flex-col gap-3 whitespace-nowrap md:w-auto md:max-w-none md:flex-row">
            <a href="#book" class="cc-btn w-full md:w-auto md:px-7">
                <span data-lucide="truck" class="h-5 w-5"></span>
                Book a Pickup
                <span data-lucide="arrow-right" class="h-4 w-4"></span>
            </a>
            <a href="#track" class="cc-btn-outline w-full md:w-auto md:px-7">
                <span data-lucide="search" class="h-4.5 w-4.5"></span>
                Track My Laundry
            </a>
        </div>

        @if($openRequests->isNotEmpty())
            <a href="{{ route('customer.bookings.index') }}"
               class="mt-5 inline-flex items-center gap-2 rounded-full bg-cc-surface/90 px-4 py-2 text-xs font-bold text-cc-deep ring-1 ring-cc-line">
                <span data-lucide="calendar" class="h-4 w-4 text-cc-brown"></span>
                {{ $openRequests->count() }} upcoming {{ Str::plural('pickup', $openRequests->count()) }} &middot; View
            </a>
        @endif
    </div>
</section>

{{-- ══════════════════════════════ RATE + WHAT WE HANDLE ══════════════════════════════ --}}
<section class="relative px-4">
    <div class="cc-card mx-auto max-w-md p-5 sm:p-6 md:grid md:max-w-6xl md:grid-cols-[auto_1fr] md:items-center md:gap-10 md:px-8">
        @if($featured)
            <a href="#book" @click="$dispatch('preselect-offering', '{{ $featured['key'] }}')"
               class="flex items-center justify-center gap-4 border-b border-cc-line pb-5 md:justify-start md:border-r md:border-b-0 md:pr-10 md:pb-0">
                <span class="cc-icon-tile h-16 w-16"><span data-lucide="laundry" class="h-8 w-8"></span></span>
                <span class="text-left">
                    <span class="block font-display text-[2.6rem] leading-none font-bold text-cc-deep">
                        {{ $peso($featured['price']) }}@if($featuredUnit)<span class="text-2xl"> / {{ strtoupper($featuredUnit) }}</span>@endif
                    </span>
                    <span class="mt-1 block text-sm font-semibold text-cc-muted">
                        {{ $featured['pricing_type'] === 'kilo' ? 'Wash • Dry • Fold' : $featured['name'] }}
                    </span>
                </span>
            </a>
        @endif

        <div class="{{ $featured ? 'pt-5 md:pt-0' : '' }}">
            <h2 class="text-center font-display text-xl font-bold text-cc-deep md:text-left">What We Handle</h2>
            <ul class="mt-4 grid grid-cols-5 gap-1 text-center md:gap-4">
                @foreach ([
                    ['shirt', 'Everyday clothes'],
                    ['bed-double', 'Bedsheets'],
                    ['bed-single', 'Duvets'],
                    ['layers', 'Towels'],
                    ['steam', 'Steaming'],
                ] as [$icon, $label])
                    <li class="flex flex-col items-center gap-1.5">
                        <span data-lucide="{{ $icon }}" class="h-8 w-8 text-cc-brown sm:h-9 sm:w-9"></span>
                        <span class="text-[11px] leading-tight font-semibold text-cc-ink sm:text-xs">{{ $label }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ SERVICES ══════════════════════════════ --}}
<section id="services" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="mx-auto max-w-6xl">
        <div class="text-center md:text-left">
            <h2 class="cc-title">Our Services</h2>
            <p class="cc-subtitle mt-1">Quality care for every fabric. Tap one to book it.</p>
        </div>

        <div class="mt-6 grid gap-8 md:grid-cols-[0.95fr_1.05fr] md:items-start lg:gap-12">
            {{-- Photos: the branded wash-dry-fold shot, with a close-up tucked into
                 its top corner, clear of the logo and the caption printed on it. --}}
            <div class="relative mx-auto w-full max-w-md pt-14 md:sticky md:top-24 md:mx-0 md:max-w-none">
                <picture>
                    <source srcset="{{ asset('images/laundry1.webp') }}" type="image/webp">
                    <img src="{{ asset('images/laundry1.jpg') }}" width="1000" height="1067" loading="lazy" decoding="async"
                         alt="{{ $businessName }}: Wash, Dry & Fold. Fresh, clean and ready to wear."
                         class="block w-full rounded-[1.75rem] shadow-[0_30px_60px_-36px_rgba(74,47,31,0.6)] ring-1 ring-cc-line">
                </picture>
                <picture>
                    <source srcset="{{ asset('images/laundry.webp') }}" type="image/webp">
                    <img src="{{ asset('images/laundry.jpg') }}" width="1000" height="1152" loading="lazy" decoding="async"
                         alt="Soft folded towels beside fresh cotton"
                         class="absolute top-0 left-4 block w-[36%] rounded-2xl border-4 border-cc-surface shadow-[0_20px_40px_-20px_rgba(74,47,31,0.65)] sm:left-6">
                </picture>
            </div>

            <div>
        {{-- Pinned services and bundles, so every price here is the live one. --}}
        @if($offerings->isEmpty())
            <div class="cc-card px-6 py-12 text-center">
                <span class="cc-icon-tile mx-auto h-14 w-14"><span data-lucide="tag" class="h-6 w-6"></span></span>
                <p class="mt-5 font-bold text-cc-deep">Our service list is being updated</p>
                <p class="cc-subtitle mx-auto mt-1.5 max-w-sm">Please call the branch to book in the meantime &mdash; online booking will be back shortly.</p>
            </div>
        @else
            <div class="grid gap-3 lg:gap-4">
                @foreach ($offerings as $offering)
                    {{-- Block form on purpose: the one-line form, in a file that
                         also uses the block form, makes Blade swallow everything
                         up to the next closing tag. --}}
                    @php $unit = $unitShort($offering['pricing_type']); @endphp
                    <a href="#book" @click="$dispatch('preselect-offering', '{{ $offering['key'] }}')"
                       class="cc-card group flex items-center gap-4 p-3 pr-4 transition hover:-translate-y-0.5 hover:border-cc-tan">
                        <span class="cc-icon-tile h-20 w-20 rounded-2xl">
                            <span data-lucide="{{ $offering['icon'] }}" class="h-9 w-9"></span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span class="leading-snug font-bold text-cc-deep">{{ $offering['name'] }}</span>
                                @if($offering['type'] === 'preset')
                                    <span class="rounded-full bg-cc-soft px-2 py-0.5 text-[10px] font-bold tracking-wide text-cc-brown uppercase">Bundle</span>
                                @endif
                            </span>
                            <span class="mt-0.5 block font-display text-xl leading-tight font-bold text-cc-brown">
                                {{ $peso($offering['price']) }}@if($unit)<span class="text-base"> / {{ $unit }}</span>@endif
                            </span>
                            @if($offering['includes'])
                                <span class="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-cc-muted">{{ implode(' + ', $offering['includes']) }}</span>
                            @elseif($offering['blurb'])
                                <span class="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-cc-muted">{{ $offering['blurb'] }}</span>
                            @endif
                        </span>
                        <span data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-cc-muted transition group-hover:translate-x-0.5 group-hover:text-cc-brown"></span>
                    </a>
                @endforeach
            </div>

            <p class="mt-5 flex items-center justify-center gap-2 text-sm font-semibold text-cc-muted md:justify-start">
                <span class="cc-icon-tile h-7 w-7"><span data-lucide="scale" class="h-3.5 w-3.5"></span></span>
                Minimum 5 kg per pickup
            </p>
        @endif
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ HOW IT WORKS ══════════════════════════════ --}}
<section id="how" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="mx-auto max-w-6xl">
        <div class="text-center md:text-left">
            <h2 class="cc-title">How It Works</h2>
            <p class="cc-subtitle mt-1">Four easy steps, and laundry day is handled.</p>
        </div>

        <ol class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
            @foreach ([
                ['calendar', 'Book online', 'Tell us what you have, where and when. About a minute.'],
                ['truck', 'We collect', 'Our rider arrives in your chosen window.'],
                ['laundry', 'Wash & fold', 'Sorted, washed right, dried and folded by hand.'],
                ['packageCheck', 'Back at your door', 'Delivered fresh, with a heads-up before we arrive.'],
            ] as $index => [$icon, $title, $body])
                <li class="cc-card relative p-4 sm:p-5">
                    <span class="absolute top-3 right-3 flex h-6 w-6 items-center justify-center rounded-full bg-cc-brown text-[11px] font-bold text-white">{{ $index + 1 }}</span>
                    <span class="cc-icon-tile h-11 w-11"><span data-lucide="{{ $icon }}" class="h-5 w-5"></span></span>
                    <h3 class="mt-3 font-display text-lg leading-tight font-bold text-cc-deep">{{ $title }}</h3>
                    <p class="mt-1 text-xs leading-relaxed text-cc-muted sm:text-sm">{{ $body }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ══════════════════════════════ BOOKING FORM ══════════════════════════════ --}}
@include('partials.booking-form')

{{-- ══════════════════════════════ TRACK ══════════════════════════════ --}}
<section id="track" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="cc-card mx-auto max-w-xl p-5 sm:p-8">
        <h2 class="cc-title">Track My Laundry</h2>
        <p class="cc-subtitle mt-1.5">Enter your booking number and the mobile number you booked with to check the status of your laundry.</p>

        <form method="POST" action="{{ route('track') }}" class="mt-6 space-y-3">
            @csrf
            <div>
                <label for="track_reference" class="sr-only">Booking number</label>
                <div class="relative">
                    <span data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 h-4.5 w-4.5 -translate-y-1/2 text-cc-muted"></span>
                    <input id="track_reference" type="text" name="reference_no" value="{{ old('reference_no') }}" required
                           autocapitalize="characters" autocomplete="off" placeholder="Booking number (e.g. PU-260908-0001)"
                           class="cc-input pl-11">
                </div>
                @error('reference_no') <p class="cc-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="track_phone" class="sr-only">Mobile number</label>
                <div class="relative">
                    <span data-lucide="smartphone" class="pointer-events-none absolute top-1/2 left-4 h-4.5 w-4.5 -translate-y-1/2 text-cc-muted"></span>
                    <input id="track_phone" type="tel" name="phone" value="{{ old('phone') }}" required
                           inputmode="tel" autocomplete="tel" placeholder="Mobile number (09XX XXX XXXX)"
                           class="cc-input pl-11">
                </div>
                @error('phone') <p class="cc-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="cc-btn w-full">Track</button>
        </form>

        @if($contactNumber || $settings?->business_email)
            <div class="mt-6 border-t border-cc-line pt-5 text-center text-sm">
                <p class="text-cc-muted">Need help? Contact us at</p>
                <div class="mt-2 flex flex-col items-center gap-1.5 font-semibold text-cc-deep">
                    @if($contactNumber)
                        <a href="tel:{{ preg_replace('/\s+/', '', $contactNumber) }}" class="inline-flex items-center gap-2 hover:text-cc-brown">
                            <span data-lucide="phone" class="h-4 w-4 text-cc-brown"></span>{{ $contactNumber }}
                        </a>
                    @endif
                    @if($settings?->business_email)
                        <a href="mailto:{{ $settings->business_email }}" class="inline-flex items-center gap-2 break-all hover:text-cc-brown">
                            <span data-lucide="mail" class="h-4 w-4 text-cc-brown"></span>{{ $settings->business_email }}
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>

{{-- ══════════════════════════════ PRICE LIST ══════════════════════════════ --}}
@if($priceList->isNotEmpty())
<section id="rates" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="mx-auto max-w-3xl">
        <div class="text-center">
            <h2 class="font-script text-6xl leading-none text-cc-deep sm:text-7xl">Price List</h2>
            <p class="cc-subtitle mx-auto mt-3 max-w-md">Your bag is weighed at the branch and priced from this list. The final total is confirmed before we start.</p>
        </div>

        <div class="mt-7 space-y-4">
            @foreach ($priceList as $categoryName => $services)
                <div class="cc-card overflow-hidden">
                    <h3 class="border-b border-cc-line bg-cc-soft/60 px-5 py-3 font-display text-lg font-bold tracking-wide text-cc-deep uppercase">{{ $categoryName }}</h3>
                    <ul class="divide-y divide-cc-line">
                        @foreach ($services as $service)
                            @php $unit = $unitShort($service->pricing_type); @endphp
                            <li class="flex items-center justify-between gap-4 px-5 py-3.5">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-cc-deep">{{ $service->name }}</p>
                                    <p class="text-xs text-cc-muted">{{ ucfirst($service->priceUnitLabel()) }}</p>
                                </div>
                                <p class="shrink-0 font-display text-2xl leading-none font-bold whitespace-nowrap text-cc-brown">
                                    {{ $peso($service->price) }}@if($unit)<span class="text-base">/{{ $unit }}</span>@endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <div class="cc-soft flex items-center gap-4 px-5 py-4">
                <span data-lucide="truck" class="h-9 w-9 shrink-0 text-cc-brown"></span>
                <div>
                    <p class="font-display text-lg leading-tight font-bold tracking-wide text-cc-deep uppercase">Free pick up &amp; delivery</p>
                    <p class="text-sm text-cc-muted">We make laundry easier for you.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- ══════════════════════════════ BRANCHES ══════════════════════════════ --}}
@if($branches->isNotEmpty())
<section id="branches" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="mx-auto max-w-6xl">
        <div class="text-center md:text-left">
            <h2 class="cc-title">{{ $branches->count() > 1 ? 'Our Branches' : 'Our Branch' }}</h2>
            <p class="cc-subtitle mt-1">Choose the branch nearest you and our rider comes from there.</p>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 lg:gap-4">
            @foreach ($branches as $branch)
                <div class="cc-card p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-display text-xl leading-tight font-bold text-cc-deep">{{ $branch->name }}</h3>
                        <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold tracking-wide text-emerald-700 uppercase ring-1 ring-emerald-200">Open</span>
                    </div>

                    @if($branch->address)
                        <p class="mt-3 flex items-start gap-2 text-sm leading-relaxed text-cc-muted">
                            <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-cc-brown"></span>
                            {{ $branch->address }}
                        </p>
                    @endif

                    @if($branch->contact_number)
                        <p class="mt-2 flex items-center gap-2 text-sm text-cc-muted">
                            <span data-lucide="phone" class="h-4 w-4 shrink-0 text-cc-brown"></span>
                            <a href="tel:{{ preg_replace('/\s+/', '', $branch->contact_number) }}" class="hover:text-cc-brown">{{ $branch->contact_number }}</a>
                        </p>
                    @endif

                    <a href="#book" @click="$dispatch('preselect-branch', {{ $branch->id }})"
                       class="mt-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-bold text-cc-brown hover:gap-2.5">
                        Book from here
                        <span data-lucide="arrow-right" class="h-4 w-4"></span>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ══════════════════════════════ FAQ ══════════════════════════════ --}}
<section id="faq" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="mx-auto max-w-3xl">
        <div class="text-center">
            <h2 class="cc-title">Questions? We&rsquo;ve Got You</h2>
            <p class="cc-subtitle mt-1">Everything people usually ask us.</p>
        </div>

        <div x-data="{ open: 0 }" class="mt-6 space-y-2.5">
            @foreach ([
                ['Do I need an account to book?', 'You can fill in the whole booking first &mdash; we only ask you to create an account at the last step, and it takes a few seconds. The account is what lets you track the order, cancel it and rebook next time.'],
                ['How much does pickup and delivery cost?', 'Nothing. Pickup and delivery are free for orders of 5 kg and above. You only pay for the laundry itself, confirmed once your bag is weighed.'],
                ['When will my laundry come back?', 'Standard turnaround is 24 hours from pickup. Choose rush when booking and we prioritise your load for same-day handling where the schedule allows.'],
                ['How is the price calculated?', 'Loads are priced by weight against our published price list. The estimate you see while booking is a guide; the exact total is confirmed once your bag is weighed at the branch.'],
                ['Can I change or cancel a booking?', 'Yes. Open My bookings and cancel any request that has not been collected yet. To move a pickup to another day, cancel and rebook, or call the branch directly.'],
                ['What if something is damaged or missing?', 'Tell the branch within 24 hours of delivery. Every booking is bagged and tagged on its own, so we can trace exactly where your item went.'],
            ] as $index => [$question, $answer])
                <div class="cc-card overflow-hidden" :class="open === {{ $index }} ? 'border-cc-tan!' : ''">
                    <button type="button" @click="open = open === {{ $index }} ? null : {{ $index }}"
                            class="flex min-h-14 w-full items-center justify-between gap-4 px-5 py-4 text-left"
                            :aria-expanded="open === {{ $index }}">
                        <span class="text-[15px] font-bold text-cc-deep">{{ $question }}</span>
                        <span data-lucide="chevron-down" class="h-5 w-5 shrink-0 text-cc-brown transition-transform"
                              :class="open === {{ $index }} ? 'rotate-180' : ''"></span>
                    </button>
                    <div x-show="open === {{ $index }}" x-cloak x-transition class="px-5 pb-5">
                        <p class="text-sm leading-relaxed text-cc-muted">{!! $answer !!}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════════════════════ ABOUT & CONTACT ══════════════════════════════ --}}
<section id="about" class="scroll-mt-20 px-4 pt-16 sm:pt-20">
    <div class="cc-card mx-auto max-w-6xl overflow-hidden md:grid md:grid-cols-2">
        <div class="cc-about-banner flex min-h-52 items-center justify-center px-6 py-10 md:min-h-full">
            <p class="text-center font-script text-5xl leading-tight text-white drop-shadow-[0_2px_10px_rgba(0,0,0,0.35)] sm:text-6xl">
                Clean clothes.<br>A brighter day.
            </p>
        </div>

        <div class="p-5 sm:p-8">
            <h2 class="cc-title text-[1.75rem] sm:text-3xl">About Us</h2>
            <p class="mt-3 text-sm leading-relaxed text-cc-muted">
                {{ $businessName }} is a local laundry service{{ $settings?->business_address ? ' in '.$settings->business_address : '' }},
                dedicated to giving you fresh, clean and well-cared-for clothes. We&rsquo;re here to make your everyday
                life easier &mdash; because you have better things to do.
            </p>

            <ul class="mt-5 space-y-3 text-sm text-cc-ink">
                @if($settings?->business_address)
                    <li class="flex items-start gap-3">
                        <span data-lucide="map-pin" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        {{ $settings->business_address }}
                    </li>
                @endif
                @if($contactNumber)
                    <li class="flex items-center gap-3">
                        <span data-lucide="phone" class="h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        <a href="tel:{{ preg_replace('/\s+/', '', $contactNumber) }}" class="font-semibold hover:text-cc-brown">{{ $contactNumber }}</a>
                    </li>
                @endif
                @if($settings?->business_email)
                    <li class="flex items-center gap-3">
                        <span data-lucide="mail" class="h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        <a href="mailto:{{ $settings->business_email }}" class="font-semibold break-all hover:text-cc-brown">{{ $settings->business_email }}</a>
                    </li>
                @endif
                <li class="flex items-center gap-3">
                    <span data-lucide="time" class="h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                    Pickups daily, 8:00 AM &ndash; 7:00 PM
                </li>
            </ul>

            <div class="mt-6 grid gap-2.5 sm:grid-cols-2">
                @foreach ([
                    ['shieldCheck', 'Nothing gets mixed up', 'Every booking is bagged, tagged and washed on its own.'],
                    ['scale', 'Weighed in front of you', 'Weight and total confirmed before a machine starts.'],
                    $settings?->sms_enabled
                        ? ['sms', 'Never left guessing', 'An SMS when we collect, when it is ready and on the way.']
                        : ['phone', 'Never left guessing', 'We call ahead at every step that matters.'],
                    ['heart', 'Fabric-first care', 'Colour sorting, the right temperatures, hand-finished folds.'],
                ] as [$icon, $title, $body])
                    <div class="cc-soft flex items-start gap-3 px-3.5 py-3">
                        <span data-lucide="{{ $icon }}" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        <div>
                            <p class="text-sm font-bold text-cc-deep">{{ $title }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-cc-muted">{{ $body }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            @if(count($stats))
                @php
                    // Written out in full: Tailwind scans source text, so a class
                    // built by string interpolation is never generated.
                    $statColumns = match (min(count($stats), 4)) {
                        1 => 'sm:grid-cols-1',
                        2 => 'sm:grid-cols-2',
                        3 => 'sm:grid-cols-3',
                        default => 'sm:grid-cols-4',
                    };
                @endphp
                <dl class="mt-6 grid grid-cols-2 gap-2.5 border-t border-cc-line pt-5 {{ $statColumns }}">
                    @foreach ($stats as $stat)
                        <div class="text-center">
                            <dt class="font-display text-3xl leading-none font-bold text-cc-brown">{{ $stat['value'] }}</dt>
                            <dd class="mt-1 text-[11px] leading-snug font-semibold text-cc-muted">{{ $stat['label'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </div>
</section>

@endsection
