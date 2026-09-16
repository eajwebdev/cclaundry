@php
    $publicCustomer = auth('customer')->user();
    $publicBusinessName = $appBusinessName ?: config('app.name');

    // The mark stacks the house name over the word "Laundry". If the stored
    // business name already ends in it, split rather than repeat it.
    $publicWordmark = preg_replace('/\s*laundry\s*$/i', '', $publicBusinessName) ?: $publicBusinessName;
    $publicWordmarkSub = strcasecmp($publicWordmark, $publicBusinessName) === 0 ? null : 'Laundry';

    // A page whose hero already carries the logo artwork lets the header float
    // over it, and only shows the header logo once the hero scrolls away.
    $overlayHeader = $__env->hasSection('overlay_header');

    $landingUrl = route('landing');
    $publicMenu = [
        [$landingUrl, 'home', 'Home'],
        [$landingUrl.'#book', 'truck', 'Book a Pickup'],
        [$landingUrl.'#track', 'search', 'Track My Laundry'],
        [$landingUrl.'#services', 'shirt', 'Our Services'],
        [$landingUrl.'#how', 'laundry', 'How It Works'],
        [$landingUrl.'#about', 'info', 'About & Contact'],
    ];

    // Web copies of public/uploads/customer-landing-background.png (1.6 MB):
    // the same artwork at a size a phone on mobile data can afford.
    $backgroundJpg = asset('images/customer-background.jpg');
    $backgroundWebp = asset('images/customer-background.webp');
@endphp
<!DOCTYPE html>
{{-- The customer site is deliberately light-only: the brand artwork is a cream
     photograph and does not survive a dark theme. --}}
<html lang="en" class="scroll-smooth"
      style="--color-primary: {{ $appPrimaryColor }}; --cc-bg-jpg: url('{{ $backgroundJpg }}'); --cc-bg-set: image-set(url('{{ $backgroundWebp }}') type('image/webp'), url('{{ $backgroundJpg }}') type('image/jpeg'));">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#F7EFE4">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', $publicBusinessName . ' - Wash, Dry, Fold, Repeat. Free pick-up and delivery in Kabankalan City.')">
    <title>@yield('page_title', 'Laundry pickup & delivery') &middot; {{ $publicBusinessName }}</title>
    <link rel="icon" href="{{ $appBusinessLogo }}">
    <link rel="apple-touch-icon" href="{{ $appBusinessLogo }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Allura&family=Cormorant+Garamond:wght@600;700&family=Nunito+Sans:wght@400;600;700;800&display=swap">
    <link rel="preload" as="image" href="{{ $backgroundWebp }}" type="image/webp">
    {{-- Page-specific bundles load here, ahead of app.js, so anything they
         put on window exists before Alpine starts. --}}
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        window.appDarkModeDefault = @js($appDarkModeDefault);
        window.appPrimaryColor = @js($appPrimaryColor);
    </script>
</head>

<body x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false" :class="{ 'overflow-hidden': menuOpen }"
      class="cc-body min-h-screen antialiased">

    <div aria-hidden="true" class="cc-backdrop"></div>

    {{-- ─────────────────────────── Header ─────────────────────────── --}}
    <header
        x-data="{ scrolled: false }"
        x-init="scrolled = window.scrollY > {{ $overlayHeader ? 140 : 8 }}; window.addEventListener('scroll', () => scrolled = window.scrollY > {{ $overlayHeader ? 140 : 8 }}, { passive: true })"
        class="cc-header sticky top-0 z-50 {{ $overlayHeader ? '' : 'cc-header-solid' }}"
        @if($overlayHeader) :class="{ 'cc-header-solid': scrolled }" @endif
    >
        <div class="mx-auto grid h-16 max-w-6xl grid-cols-[2.75rem_1fr_2.75rem] items-center gap-2 px-3 sm:px-5 lg:h-20 lg:grid-cols-[1fr_auto_1fr] lg:gap-6 lg:px-8">

            {{-- Left: back on phones, the full logo on desktop --}}
            <div class="flex items-center">
                @hasSection('back_url')
                    <a href="@yield('back_url')" class="cc-icon-btn lg:hidden" aria-label="Go back">
                        <span data-lucide="chevron-left" class="h-6 w-6"></span>
                    </a>
                @endif

                <a href="{{ $landingUrl }}" class="hidden items-center gap-3 whitespace-nowrap lg:flex">
                    <x-brand-mark class="h-11 w-11" />
                    <span class="leading-none">
                        <span class="block font-display text-lg font-bold tracking-[0.12em] text-cc-deep uppercase">{{ $publicWordmark }}</span>
                        @if($publicWordmarkSub)
                            <span class="mt-1 block text-[10px] font-bold tracking-[0.42em] text-cc-muted uppercase">{{ $publicWordmarkSub }}</span>
                        @endif
                    </span>
                </a>
            </div>

            {{-- Centre: the logo on phones, navigation on desktop --}}
            <a href="{{ $landingUrl }}"
               class="flex min-w-0 items-center justify-center gap-2.5 transition-opacity duration-300 lg:hidden"
               @if($overlayHeader) :class="{ 'max-md:pointer-events-none max-md:opacity-0': ! scrolled }" @endif
               aria-label="{{ $publicBusinessName }} home">
                <x-brand-mark class="h-9 w-9" />
                <span class="min-w-0 text-left leading-none">
                    <span class="block truncate font-display text-base font-bold tracking-[0.1em] text-cc-deep uppercase">{{ $publicWordmark }}</span>
                    @if($publicWordmarkSub)
                        <span class="mt-0.5 block text-[9px] font-bold tracking-[0.42em] text-cc-muted uppercase">{{ $publicWordmarkSub }}</span>
                    @endif
                </span>
            </a>

            {{-- Short labels, and the less-used links only once there is room. --}}
            <nav class="hidden items-center gap-0.5 whitespace-nowrap lg:flex" aria-label="Main">
                @foreach ([
                    [$landingUrl.'#services', 'Services', ''],
                    [$landingUrl.'#how', 'How It Works', ''],
                    [$landingUrl.'#track', 'Track', ''],
                    [$landingUrl.'#about', 'About', ''],
                ] as [$href, $label, $visibility])
                    <a href="{{ $href }}" class="cc-nav-link {{ $visibility }}">{{ $label }}</a>
                @endforeach
            </nav>

            {{-- Right: menu on phones, account and booking on desktop --}}
            <div class="flex items-center justify-end gap-2">
                <button type="button" @click="menuOpen = true" class="cc-icon-btn lg:hidden"
                        aria-label="Open menu" :aria-expanded="menuOpen">
                    <span data-lucide="menu" class="h-6 w-6"></span>
                </button>

                <div class="hidden items-center gap-1 whitespace-nowrap lg:flex">
                    @if($publicCustomer)
                        <a href="{{ route('customer.bookings.index') }}" class="cc-nav-link inline-flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-cc-brown text-[11px] font-bold text-white">
                                {{ mb_strtoupper(mb_substr($publicCustomer->name, 0, 1)) }}
                            </span>
                            My bookings
                        </a>
                        <form method="POST" action="{{ route('customer.logout') }}">
                            @csrf
                            <button type="submit" class="cc-icon-btn" aria-label="Sign out" title="Sign out">
                                <span data-lucide="logout" class="h-4.5 w-4.5"></span>
                            </button>
                        </form>
                    @else
                        <a href="{{ route('customer.login') }}" class="cc-nav-link">Sign in</a>
                    @endif

                    <a href="{{ $landingUrl }}#book" class="cc-btn cc-btn-sm ml-2">
                        <span data-lucide="truck" class="h-4 w-4"></span>
                        Book a Pickup
                    </a>
                </div>
            </div>
        </div>
    </header>

    {{-- ─────────────────────────── Menu drawer ─────────────────────────── --}}
    {{-- Outside the header: its backdrop blur would otherwise trap this fixed
         panel inside the header's own box. --}}
    <div x-show="menuOpen" x-cloak x-transition:leave="transition duration-200"
         class="fixed inset-0 z-[60] lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
        <div x-show="menuOpen" x-transition.opacity.duration.200ms @click="menuOpen = false"
             class="absolute inset-0 bg-[#2b2018]/45"></div>

        <div x-show="menuOpen"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="absolute inset-y-0 right-0 flex w-[88%] max-w-sm flex-col overflow-y-auto bg-cc-ground shadow-2xl">

            <div class="flex items-center justify-between gap-3 border-b border-cc-line px-5 py-4">
                <a href="{{ $landingUrl }}" class="flex min-w-0 items-center gap-2.5">
                    <x-brand-mark class="h-10 w-10" />
                    <span class="min-w-0 leading-none">
                        <span class="block truncate font-display text-base font-bold tracking-[0.1em] text-cc-deep uppercase">{{ $publicWordmark }}</span>
                        @if($publicWordmarkSub)
                            <span class="mt-0.5 block text-[9px] font-bold tracking-[0.42em] text-cc-muted uppercase">{{ $publicWordmarkSub }}</span>
                        @endif
                    </span>
                </a>
                <button type="button" @click="menuOpen = false" class="cc-icon-btn -mr-2" aria-label="Close menu">
                    <span data-lucide="x" class="h-5 w-5"></span>
                </button>
            </div>

            @if($publicCustomer)
                <div class="mx-5 mt-4 flex items-center gap-3 rounded-2xl bg-cc-soft px-4 py-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-cc-brown text-sm font-bold text-white">
                        {{ mb_strtoupper(mb_substr($publicCustomer->name, 0, 1)) }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-bold text-cc-deep">{{ $publicCustomer->name }}</span>
                        <span class="block truncate text-xs text-cc-muted">{{ $publicCustomer->phone }}</span>
                    </span>
                </div>
            @endif

            <nav class="flex-1 px-3 py-3" aria-label="Menu">
                @foreach ($publicMenu as [$href, $icon, $label])
                    <a href="{{ $href }}" @click="menuOpen = false"
                       class="flex items-center gap-3.5 rounded-xl px-3 py-2.5 text-[15px] font-bold text-cc-deep transition hover:bg-cc-soft">
                        <span class="cc-icon-tile h-9 w-9"><span data-lucide="{{ $icon }}" class="h-4.5 w-4.5"></span></span>
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="space-y-2.5 border-t border-cc-line px-5 pt-4 pb-[max(1.5rem,env(safe-area-inset-bottom))]">
                @if($publicCustomer)
                    <a href="{{ route('customer.bookings.index') }}" class="cc-btn w-full">
                        <span data-lucide="jobOrders" class="h-4.5 w-4.5"></span>
                        My bookings
                    </a>
                    <form method="POST" action="{{ route('customer.logout') }}">
                        @csrf
                        <button type="submit" class="cc-btn-outline w-full">
                            <span data-lucide="logout" class="h-4.5 w-4.5"></span>
                            Sign out
                        </button>
                    </form>
                @else
                    <a href="{{ route('customer.login') }}" class="cc-btn w-full">
                        <span data-lucide="login" class="h-4.5 w-4.5"></span>
                        Sign in
                    </a>
                    <a href="{{ route('customer.register') }}" class="cc-btn-outline w-full">Create an account</a>
                @endif
                <a href="{{ route('login') }}" class="block pt-1 text-center text-xs font-semibold text-cc-muted hover:text-cc-brown">Staff sign in</a>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────── Flash messages ─────────────────────────── --}}
    {{-- Floating, so a notice never pushes the hero or the form down the page. --}}
    @if(session('success') || session('error') || session('info') || $errors->any())
        <div class="pointer-events-none fixed inset-x-0 top-[4.5rem] z-40 flex flex-col items-center gap-2 px-3 lg:top-24">
            @foreach ([
                ['success', 'check', 'border-emerald-200 bg-emerald-50 text-emerald-900'],
                ['info', 'bell', 'border-sky-200 bg-sky-50 text-sky-900'],
                ['error', 'alertTriangle', 'border-red-200 bg-red-50 text-red-900'],
            ] as [$flashKey, $flashIcon, $flashTone])
                @if(session($flashKey))
                    <div x-data="{ show: true }" x-show="show" x-transition.opacity
                         @if($flashKey !== 'error') x-init="setTimeout(() => show = false, 7000)" @endif
                         role="status"
                         class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-2xl border px-4 py-3 text-sm shadow-lg shadow-[#4a2f1f]/10 {{ $flashTone }}">
                        <span data-lucide="{{ $flashIcon }}" class="mt-0.5 h-4 w-4 shrink-0"></span>
                        <span class="flex-1">{{ session($flashKey) }}</span>
                        <button type="button" @click="show = false" class="-mt-0.5 -mr-1 rounded-full p-1 opacity-60 transition hover:opacity-100" aria-label="Dismiss">
                            <span data-lucide="x" class="h-4 w-4"></span>
                        </button>
                    </div>
                @endif
            @endforeach

            @if($errors->any())
                <div x-data="{ show: true }" x-show="show" x-transition.opacity role="alert"
                     class="pointer-events-auto w-full max-w-md rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 shadow-lg shadow-[#4a2f1f]/10">
                    <div class="flex items-start gap-3">
                        <span data-lucide="alertTriangle" class="mt-0.5 h-4 w-4 shrink-0"></span>
                        <div class="flex-1">
                            <p class="font-bold">Please check the details below</p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <button type="button" @click="show = false" class="-mt-0.5 -mr-1 rounded-full p-1 opacity-60 transition hover:opacity-100" aria-label="Dismiss">
                            <span data-lucide="x" class="h-4 w-4"></span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <main class="relative">
        @yield('content')
    </main>

    {{-- ─────────────────────────── Footer ─────────────────────────── --}}
    {{-- Generous bottom padding leaves the towels and cotton of the backdrop
         showing under the last line, as on the printed menu. --}}
    <footer class="relative mt-16 px-4 pb-[max(9rem,26vh)] text-center sm:mt-20">
        <div class="cc-footer-glow mx-auto max-w-2xl px-4 py-8">
            <p class="font-script text-[2.1rem] leading-tight text-cc-deep sm:text-5xl">
                Life&rsquo;s busy,<br class="sm:hidden"> we&rsquo;ll handle the laundry!
            </p>
            <p class="mt-2 text-[11px] font-bold tracking-[0.3em] text-cc-brown uppercase">Wash &bull; Dry &bull; Fold &bull; Repeat</p>
            <div class="mx-auto mt-3 flex max-w-[14rem] items-center gap-3 text-cc-brown" aria-hidden="true">
                <span class="h-px flex-1 bg-cc-line"></span>
                <span class="text-sm">&#9829;</span>
                <span class="h-px flex-1 bg-cc-line"></span>
            </div>

            <nav class="mt-6 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm font-bold text-cc-deep" aria-label="Footer">
                <a href="{{ $landingUrl }}#services" class="hover:text-cc-brown">Services</a>
                <a href="{{ $landingUrl }}#book" class="hover:text-cc-brown">Book a Pickup</a>
                <a href="{{ $landingUrl }}#track" class="hover:text-cc-brown">Track My Laundry</a>
                @if($publicCustomer)
                    <a href="{{ route('customer.bookings.index') }}" class="hover:text-cc-brown">My bookings</a>
                @else
                    <a href="{{ route('customer.login') }}" class="hover:text-cc-brown">Sign in</a>
                @endif
            </nav>

            <ul class="mt-5 flex flex-col items-center gap-2 text-sm text-cc-muted sm:flex-row sm:flex-wrap sm:justify-center sm:gap-x-6">
                @if($appSettings?->contact_number)
                    <li class="flex items-center gap-2">
                        <span data-lucide="phone" class="h-4 w-4 text-cc-brown"></span>
                        <a href="tel:{{ preg_replace('/\s+/', '', $appSettings->contact_number) }}" class="hover:text-cc-brown">{{ $appSettings->contact_number }}</a>
                    </li>
                @endif
                @if($appSettings?->business_email)
                    <li class="flex items-center gap-2">
                        <span data-lucide="mail" class="h-4 w-4 text-cc-brown"></span>
                        <a href="mailto:{{ $appSettings->business_email }}" class="break-all hover:text-cc-brown">{{ $appSettings->business_email }}</a>
                    </li>
                @endif
                {{-- The span the pickup windows cover, from Settings > Branch. --}}
                <li class="flex items-center gap-2">
                    <span data-lucide="time" class="h-4 w-4 text-cc-brown"></span>
                    Pickups daily, {{ \App\Support\Booking::pickupHoursLabel() }}
                </li>
                @if($appSettings?->facebook_url)
                    <li class="flex items-center gap-2">
                        <span data-lucide="message-circle" class="h-4 w-4 text-cc-brown"></span>
                        <a href="{{ $appSettings->facebook_url }}" target="_blank" rel="noopener" class="hover:text-cc-brown">
                            Message us on Facebook
                        </a>
                    </li>
                @endif
            </ul>

            <p class="mt-6 text-xs text-cc-muted">
                &copy; {{ now()->year }} {{ $publicBusinessName }}. All rights reserved.
                &middot;
                <a href="{{ route('login') }}" class="font-semibold hover:text-cc-brown">Staff sign in</a>
            </p>
        </div>
    </footer>

    @stack('scripts')

    @if($errors->any())
        {{-- A rejected form lands back at the top of the page. Once Alpine has
             opened whichever step or card the problem is on, scroll to it so
             nobody has to hunt for what went wrong. --}}
        <script>
            window.addEventListener('load', () => setTimeout(() => {
                const problem = [...document.querySelectorAll('.cc-error')].find((el) => el.offsetParent !== null);
                problem?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 400));
        </script>
    @endif
</body>
</html>
