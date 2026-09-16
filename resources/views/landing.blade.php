@extends('layouts.public')

@section('page_title', 'Free pick-up & delivery in Kabankalan City')

@php
    $businessName = $appBusinessName ?: config('app.name');
    $contactNumber = $settings?->contact_number;

    // The price list never uses centavos, so "₱30" rather than "₱30.00".
    $peso = fn ($amount) => '₱'.number_format((float) $amount, fmod((float) $amount, 1) ? 2 : 0);
@endphp

@section('content')

{{-- ══════════════════════════════ HERO ══════════════════════════════ --}}
{{-- `isolate` and the cream are what let this hero keep its plain field: the
     site's artwork is a fixed layer at z-index -1, so without a stacking
     context of its own the -z-10 wash below would fall behind that artwork
     instead of covering it. --}}
<section class="relative isolate overflow-hidden bg-cream dark:bg-[#191310]">
    {{-- Soft cane/sage wash behind the fold --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-40 -right-32 h-[34rem] w-[34rem] rounded-full bg-primary/10 blur-3xl"></div>
        <div class="absolute top-40 -left-40 h-[26rem] w-[26rem] rounded-full bg-accent/12 blur-3xl"></div>
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/25 to-transparent"></div>
    </div>

    <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 pt-10 pb-14 sm:gap-14 sm:px-6 sm:pt-14 sm:pb-20 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16 lg:px-8 lg:pt-20 lg:pb-28">
        <div>
            <span class="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/8 px-3.5 py-1.5 text-[11px] font-semibold tracking-[0.16em] text-primary uppercase">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-60"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-primary"></span>
                </span>
                Free pick up and delivery
            </span>

            <h1 class="mt-5 font-serif text-[2.4rem] leading-[1.05] font-medium tracking-tight text-primary-deep sm:mt-6 sm:text-6xl lg:text-[4.25rem] dark:text-cane">
                Laundry day,<br>
                <span class="relative inline-block">
                    <span class="relative z-10">handled</span>
                    <svg class="absolute -bottom-1 left-0 -z-0 h-3 w-full text-accent/45" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M2 8 C 50 2, 150 2, 198 7" stroke="currentColor" stroke-width="4" fill="none" stroke-linecap="round"/>
                    </svg>
                </span><span class="text-primary">.</span>
            </h1>

            <p class="mt-4 max-w-lg text-[15px] leading-relaxed text-muted sm:mt-6 sm:text-[17px]">
                Book a pickup in about a minute. We collect at your door, wash and fold with cotton-soft care,
                and bring everything back the way it should be: fresh, folded and on time.
            </p>

            <div class="mt-7 flex flex-col gap-3 sm:mt-9 sm:flex-row sm:items-center">
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

            @if($openRequests->isNotEmpty())
                <a href="{{ route('customer.bookings.index') }}"
                   class="mt-5 inline-flex items-center gap-2 rounded-full border border-border bg-white/70 px-4 py-2 text-xs font-bold text-primary-deep dark:border-white/12 dark:bg-white/5 dark:text-cane">
                    <span data-lucide="calendar" class="h-4 w-4 text-primary"></span>
                    {{ $openRequests->count() }} upcoming {{ Str::plural('pickup', $openRequests->count()) }} &middot; View
                </a>
            @endif

            @php
                // The SMS promise only appears when SMS is actually enabled.
                $heroStats = [
                    ['24h', 'Standard turnaround'],
                    ['Free', 'Pick up & delivery'],
                ];

                if ($settings?->sms_enabled) {
                    $heroStats[] = ['SMS', 'Updates at every step'];
                }

                // No branch count here: with one shop, "1 Branch near you"
                // reads as a chain that is not there. The row sizes itself to
                // however many stats remain, so two do not leave a gap.
                // Written out in full because Tailwind only builds classes it
                // can read in the source.
                $heroStatColumns = count($heroStats) >= 3 ? 'grid-cols-3' : 'grid-cols-2';
            @endphp
            <dl class="mt-9 grid max-w-lg {{ $heroStatColumns }} gap-4 border-t border-border pt-6 sm:mt-12 sm:gap-6 sm:pt-8 dark:border-white/10">
                @foreach ($heroStats as [$value, $label])
                    <div>
                        <dt class="font-serif text-xl font-semibold text-primary sm:text-2xl">{{ $value }}</dt>
                        <dd class="mt-1 text-[12px] leading-snug text-muted sm:text-[13px]">{{ $label }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Hero visual: the mark, framed the way it is on the sign.
             Hidden on a phone, where the header already carries the logo and
             the headline and buttons should own the first screen. --}}
        <div class="relative mx-auto hidden w-full max-w-lg sm:block">
            <div class="relative mx-auto aspect-square w-full">
                <div class="absolute inset-0 rounded-full bg-gradient-to-br from-[#F6EFE5] to-[#EFE3D2] shadow-2xl shadow-primary/15 dark:from-[#241a13] dark:to-[#1c1510]"></div>
                <div class="absolute inset-5 rounded-full border border-primary/25"></div>
                <x-brand-mark class="absolute inset-0 m-auto h-[82%] w-[82%] shadow-lg shadow-primary/10" />
            </div>

            {{-- Floating proof cards, over the mark's corners. --}}
            <div>
                <div class="absolute -top-2 -left-4 rounded-2xl border border-border bg-white/95 px-4 py-3 shadow-xl shadow-dark/8 backdrop-blur dark:border-white/10 dark:bg-[#241a13]/95">
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

                <div class="absolute -right-2 bottom-8 rounded-2xl border border-border bg-white/95 px-4 py-3 shadow-xl shadow-dark/8 backdrop-blur dark:border-white/10 dark:bg-[#241a13]/95">
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
    </div>
</section>

{{-- ══════════════════════════════ SERVICES ══════════════════════════════ --}}
<section id="services" class="scroll-mt-20 px-4 pt-16 sm:pt-20 lg:scroll-mt-24 lg:pt-28">
    <div class="mx-auto max-w-6xl">
        <div class="text-center md:text-left">
            <p class="text-xs font-bold tracking-[0.3em] text-cc-brown uppercase">What We Do</p>
            <h2 class="cc-title mt-2">Wash &bull; Dry &bull; Fold &bull; Repeat</h2>
            <p class="cc-subtitle mt-1">
                Simple laundry care designed around your schedule. Choose your service, book a pickup,
                and let us take care of the rest.
            </p>
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
                <p class="cc-subtitle mx-auto mt-1.5 max-w-sm">Please call the branch to book in the meantime. Online booking will be back shortly.</p>
            </div>
        @else
            {{-- Desktop: two columns of stacked cards, so the list stays about as
                 tall as the photos beside it. --}}
            <div class="grid gap-3 lg:grid-cols-2 lg:gap-4">
                @foreach ($offerings as $offering)
                    {{-- Block form on purpose: the one-line form, in a file that
                         also uses the block form, makes Blade swallow everything
                         up to the next closing tag. --}}
                    @php $unit = $offering['unit_short'] ?: null; @endphp
                    <a href="#book" @click="$dispatch('preselect-offering', '{{ $offering['key'] }}')"
                       class="cc-card group relative flex items-center gap-4 p-3 pr-4 transition hover:-translate-y-0.5 hover:border-cc-tan lg:flex-col lg:items-start lg:gap-3 lg:p-5">
                        <span class="cc-icon-tile h-20 w-20 rounded-2xl lg:h-14 lg:w-14">
                            <span data-lucide="{{ $offering['icon'] }}" class="h-9 w-9 lg:h-7 lg:w-7"></span>
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
                        <span data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-cc-muted transition group-hover:translate-x-0.5 group-hover:text-cc-brown lg:absolute lg:top-6 lg:right-5"></span>
                    </a>
                @endforeach
            </div>

            @if(filled($settings?->booking_minimum_kilos))
                <p class="mt-5 flex items-center justify-center gap-2 text-sm font-semibold text-cc-muted md:justify-start">
                    <span class="cc-icon-tile h-7 w-7"><span data-lucide="scale" class="h-3.5 w-3.5"></span></span>
                    Minimum {{ rtrim(rtrim(number_format((float) $settings->booking_minimum_kilos, 2), '0'), '.') }} kg per pickup
                </p>
            @endif
        @endif
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════ HOW IT WORKS ══════════════════════════════ --}}
<section id="how" class="scroll-mt-20 px-4 pt-16 sm:pt-20 lg:scroll-mt-24 lg:pt-28">
    <div class="mx-auto max-w-6xl">
        <div class="text-center md:text-left">
            <p class="text-xs font-bold tracking-[0.3em] text-cc-brown uppercase">Easy as 1-2-3</p>
            <h2 class="cc-title mt-2">We make laundry easier for you.</h2>
        </div>

        <ol class="mt-6 grid gap-3 sm:grid-cols-3 lg:gap-4">
            @foreach ([
                ['calendar', 'Book', 'Tell us what you need, your pickup address, and your preferred date and time.'],
                ['truck', 'We Pick Up', 'Have your laundry ready. Our team will pick it up and bring it to '.$businessName.'.'],
                ['packageCheck', 'Fresh & Ready', 'We wash, dry, fold and care for your laundry, then arrange delivery back to you.'],
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
<section id="track" class="scroll-mt-20 px-4 pt-16 sm:pt-20 lg:scroll-mt-24 lg:pt-28">
    {{-- Desktop: the explanation and help on the left, the form on the right. --}}
    <div class="cc-card mx-auto max-w-xl p-5 sm:p-8 lg:grid lg:max-w-6xl lg:grid-cols-2 lg:gap-x-14 lg:p-12">
        <div>
            <span class="cc-icon-tile mb-5 hidden h-14 w-14 lg:flex"><span data-lucide="search" class="h-6 w-6"></span></span>
            <h2 class="cc-title">Track My Laundry</h2>
            <p class="cc-subtitle mt-1.5 lg:max-w-md">Enter your booking number and the mobile number you booked with to check the status of your laundry.</p>
        </div>

        <form method="POST" action="{{ route('track') }}" class="mt-6 space-y-3 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:mt-0 lg:self-center">
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
            <div class="mt-6 border-t border-cc-line pt-5 text-center text-sm lg:self-end lg:text-left">
                <p class="text-cc-muted">Need help? Contact us at</p>
                <div class="mt-2 flex flex-col items-center gap-1.5 font-semibold text-cc-deep lg:items-start">
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

{{-- ══════════════════════════════ ABOUT & CONTACT ══════════════════════════════ --}}
<section id="about" class="scroll-mt-20 px-4 pt-16 sm:pt-20 lg:scroll-mt-24 lg:pt-28">
    {{-- Side by side from lg: at tablet width the half-card squeezed the four
         feature tiles into columns a few words wide. --}}
    <div class="cc-card mx-auto max-w-6xl overflow-hidden lg:grid lg:grid-cols-2">
        <div class="cc-about-banner min-h-52 md:min-h-72 lg:min-h-full"
             style="--cc-about-img: url('{{ asset('about.jpg') }}')"></div>

        <div class="p-5 sm:p-8">
            <p class="text-xs font-bold tracking-[0.3em] text-cc-brown uppercase">About {{ $businessName }}</p>
            <h2 class="cc-title mt-2 text-[1.75rem] sm:text-3xl">Thoughtful laundry care, made local.</h2>
            <p class="mt-3 text-sm leading-relaxed text-cc-muted">
                {{ $businessName }} was created to make everyday laundry feel a little lighter. From the wash to the
                fold, we believe your clothes deserve careful handling and your time deserves to be spent on what matters.
            </p>
            <p class="mt-2.5 text-sm leading-relaxed text-cc-muted">
                Rooted in {{ $settings?->business_address ?: 'Kabankalan City' }}, we&rsquo;re here to offer dependable
                laundry care with the convenience of free pick-up and delivery.
            </p>

            {{-- Where the name comes from, in the shop's own words. --}}
            <div class="cc-soft mt-5 px-4 py-4">
                <p class="font-display text-lg leading-tight font-bold text-cc-deep">Why {{ $businessName }}?</p>
                <p class="mt-2.5 text-sm leading-relaxed text-cc-muted">
                    <span class="font-bold text-cc-deep">Cane</span> reflects the warmth and strength of homegrown
                    sugarcane, a part of our local story.
                </p>
                <p class="mt-2 text-sm leading-relaxed text-cc-muted">
                    <span class="font-bold text-cc-deep">Cotton</span> represents softness, freshness and the everyday
                    fabrics we care for.
                </p>
                <p class="mt-2 text-sm leading-relaxed text-cc-muted">
                    Together, they are our promise of laundry care that feels warm, simple and dependable.
                </p>
            </div>

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
                @if($pickupHours)
                    <li class="flex items-center gap-3">
                        <span data-lucide="time" class="h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        {{-- The span the pickup windows cover, from Settings. --}}
                        Pickups daily, {{ $pickupHours }}
                    </li>
                @endif
                {{-- Opening hours: desktop only, so the phone layout is unchanged. --}}
                @foreach($branchHours as $hours)
                    <li class="hidden items-start gap-3 lg:flex">
                        <span data-lucide="store" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        <span>
                            <span class="block font-semibold">{{ $branchHours->count() > 1 ? $hours['branch'].' hours' : 'Store hours' }}</span>
                            @foreach($hours['lines'] as $line)
                                <span class="block text-cc-muted">{{ $line }}</span>
                            @endforeach
                        </span>
                    </li>
                @endforeach
                @if($settings?->facebook_url)
                    <li class="flex items-center gap-3">
                        <span data-lucide="message-circle" class="h-4.5 w-4.5 shrink-0 text-cc-brown"></span>
                        {{-- Read off the saved URL: the handle was hard-coded here,
                             so changing the page in settings left the old one showing. --}}
                        <a href="{{ $settings->facebook_url }}" target="_blank" rel="noopener" class="font-semibold hover:text-cc-brown">
                            {{ rtrim(preg_replace('#^https?://(www\.)?#i', '', $settings->facebook_url), '/') }}
                        </a>
                    </li>
                @endif
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
        </div>
    </div>
</section>

@endsection
