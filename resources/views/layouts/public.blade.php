@php
    $publicCustomer = auth('customer')->user();
    $publicBusinessName = $appBusinessName ?: config('app.name');

    // The mark stacks the house name over the word "Laundry". If the stored
    // business name already ends in it, split rather than repeat it.
    $publicWordmark = preg_replace('/\s*laundry\s*$/i', '', $publicBusinessName) ?: $publicBusinessName;
    $publicWordmarkSub = strcasecmp($publicWordmark, $publicBusinessName) === 0 ? null : 'Laundry';
@endphp
<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth" style="--color-primary: {{ $appPrimaryColor }};">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="{{ $appPrimaryColor }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', $publicBusinessName . ' - free laundry pickup and delivery. Book online, we collect, wash, fold and bring it back.')">
    <title>@yield('page_title', 'Laundry pickup & delivery') &middot; {{ $publicBusinessName }}</title>
    <link rel="icon" href="{{ $appBusinessLogo }}">
    <link rel="apple-touch-icon" href="{{ $appBusinessLogo }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // The theme store in app.js reads these; without them the public site
        // ignores the admin's dark-mode default.
        window.appDarkModeDefault = @js($appDarkModeDefault);
        window.appPrimaryColor = @js($appPrimaryColor);
    </script>
</head>

<body class="bg-cream text-dark antialiased dark:bg-[#191310] dark:text-[#F3EBE1]">

    {{-- ─────────────────────────── Header ─────────────────────────── --}}
    <header
        x-data="{ scrolled: false, open: false, account: false }"
        x-init="scrolled = window.scrollY > 12; window.addEventListener('scroll', () => scrolled = window.scrollY > 12, { passive: true })"
        class="sticky top-0 z-50 transition-all duration-300"
        :class="scrolled
            ? 'border-b border-border/70 bg-cream/90 backdrop-blur-xl shadow-[0_1px_24px_rgba(43,32,24,0.06)] dark:border-white/10 dark:bg-[#191310]/90'
            : 'border-b border-transparent bg-transparent'"
    >
        <div class="mx-auto flex h-18 max-w-7xl items-center gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ route('landing') }}" class="flex shrink-0 items-center gap-3">
                <x-brand-mark class="h-11 w-11" />
                <span class="hidden leading-none sm:block">
                    <span class="block font-serif text-[15px] font-semibold tracking-[0.16em] text-primary-deep uppercase dark:text-cane">{{ $publicWordmark }}</span>
                    @if($publicWordmarkSub)
                        <span class="mt-1 block text-[10px] font-medium tracking-[0.34em] text-muted uppercase">{{ $publicWordmarkSub }}</span>
                    @endif
                </span>
            </a>

            <nav class="ml-auto hidden items-center gap-1 lg:flex">
                @foreach ([
                    '#services' => 'Services',
                    '#how' => 'How it works',
                    '#rates' => 'Rates',
                    '#branches' => 'Branches',
                    '#faq' => 'FAQ',
                ] as $href => $label)
                    <a href="{{ route('landing') }}{{ $href }}"
                       class="rounded-full px-3.5 py-2 text-sm font-medium text-dark/70 transition hover:bg-primary/8 hover:text-primary dark:text-[#F3EBE1]/70 dark:hover:text-cane">{{ $label }}</a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-2 lg:ml-0">
                <button type="button" @click="$store.theme.toggle()"
                        class="hidden h-10 w-10 items-center justify-center rounded-full border border-border text-muted transition hover:border-primary/40 hover:text-primary sm:inline-flex dark:border-white/10"
                        aria-label="Toggle dark mode">
                    <span data-lucide="sun" class="h-4 w-4" x-show="$store.theme.dark" x-cloak></span>
                    <span data-lucide="moon" class="h-4 w-4" x-show="! $store.theme.dark"></span>
                </button>

                @if($publicCustomer)
                    <div class="relative" @click.outside="account = false">
                        <button type="button" @click="account = ! account"
                                class="inline-flex h-10 items-center gap-2 rounded-full border border-border bg-white/70 px-2 pr-3 text-sm font-medium transition hover:border-primary/40 dark:border-white/10 dark:bg-white/5">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-[11px] font-semibold text-white">
                                {{ mb_strtoupper(mb_substr($publicCustomer->name, 0, 1)) }}
                            </span>
                            <span class="hidden max-w-28 truncate sm:block">{{ strtok($publicCustomer->name, ' ') }}</span>
                            <span data-lucide="chevron-down" class="h-3.5 w-3.5 text-muted"></span>
                        </button>

                        <div x-show="account" x-cloak x-transition.origin.top.right
                             class="absolute right-0 mt-2 w-56 overflow-hidden rounded-2xl border border-border bg-white shadow-xl shadow-dark/10 dark:border-white/10 dark:bg-[#241a13]">
                            <div class="border-b border-border px-4 py-3 dark:border-white/10">
                                <p class="truncate text-sm font-semibold">{{ $publicCustomer->name }}</p>
                                <p class="truncate text-xs text-muted">{{ $publicCustomer->phone }}</p>
                            </div>
                            <a href="{{ route('customer.bookings.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition hover:bg-smoke dark:hover:bg-white/5">
                                <span data-lucide="jobOrders" class="h-4 w-4 text-muted"></span> My bookings
                            </a>
                            <a href="{{ route('landing') }}#book" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition hover:bg-smoke dark:hover:bg-white/5">
                                <span data-lucide="truck" class="h-4 w-4 text-muted"></span> Book a pickup
                            </a>
                            <form method="POST" action="{{ route('customer.logout') }}" class="border-t border-border dark:border-white/10">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm text-red-600 transition hover:bg-red-50 dark:hover:bg-red-500/10">
                                    <span data-lucide="logout" class="h-4 w-4"></span> Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('customer.login') }}"
                       class="hidden h-10 items-center rounded-full px-4 text-sm font-medium text-dark/75 transition hover:text-primary sm:inline-flex dark:text-[#F3EBE1]/75">Sign in</a>
                @endif

                <a href="{{ route('landing') }}#book"
                   class="inline-flex h-10 items-center gap-2 rounded-full bg-primary px-4 text-sm font-semibold text-white shadow-lg shadow-primary/25 transition hover:bg-primary-deep sm:px-5">
                    <span data-lucide="truck" class="h-4 w-4"></span>
                    <span class="hidden sm:inline">Book a pickup</span>
                    <span class="sm:hidden">Book</span>
                </a>

                <button type="button" @click="open = ! open"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-border text-dark transition hover:border-primary/40 lg:hidden dark:border-white/10 dark:text-[#F3EBE1]"
                        aria-label="Menu">
                    <span data-lucide="menu" class="h-4.5 w-4.5" x-show="! open"></span>
                    <span data-lucide="x" class="h-4.5 w-4.5" x-show="open" x-cloak></span>
                </button>
            </div>
        </div>

        {{-- Mobile navigation --}}
        <div x-show="open" x-cloak x-transition class="border-t border-border bg-cream lg:hidden dark:border-white/10 dark:bg-[#191310]">
            <nav class="mx-auto max-w-7xl px-4 py-3 sm:px-6">
                @foreach ([
                    '#services' => 'Services',
                    '#how' => 'How it works',
                    '#book' => 'Book a pickup',
                    '#rates' => 'Rates',
                    '#branches' => 'Branches',
                    '#faq' => 'FAQ',
                ] as $href => $label)
                    <a href="{{ route('landing') }}{{ $href }}" @click="open = false"
                       class="block rounded-xl px-3 py-2.5 text-sm font-medium transition hover:bg-primary/8 hover:text-primary">{{ $label }}</a>
                @endforeach
                @unless($publicCustomer)
                    <a href="{{ route('customer.login') }}" class="mt-1 block rounded-xl px-3 py-2.5 text-sm font-medium text-primary">Sign in</a>
                @endunless
            </nav>
        </div>
    </header>

    {{-- ─────────────────────────── Flash messages ─────────────────────────── --}}
    @if(session('success') || session('error') || session('info') || $errors->any())
        <div class="mx-auto max-w-7xl px-4 pt-5 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-3 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/25 dark:bg-emerald-500/10 dark:text-emerald-200">
                    <span data-lucide="check" class="mt-0.5 h-4 w-4 shrink-0"></span>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if(session('info'))
                <div class="mb-3 flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-500/25 dark:bg-sky-500/10 dark:text-sky-200">
                    <span data-lucide="bell" class="mt-0.5 h-4 w-4 shrink-0"></span>
                    <span>{{ session('info') }}</span>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-3 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/25 dark:bg-red-500/10 dark:text-red-200">
                    <span data-lucide="alertTriangle" class="mt-0.5 h-4 w-4 shrink-0"></span>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/25 dark:bg-red-500/10 dark:text-red-200">
                    <div class="flex items-center gap-2 font-semibold">
                        <span data-lucide="alertTriangle" class="h-4 w-4"></span>
                        Please check the details below
                    </div>
                    <ul class="mt-1.5 list-disc space-y-0.5 pl-8">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <main>
        @yield('content')
    </main>

    {{-- ─────────────────────────── Footer ─────────────────────────── --}}
    <footer class="mt-24 border-t border-border bg-[#F6EFE5] dark:border-white/10 dark:bg-[#14100c]">
        <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-1">
                    <div class="flex items-center gap-3">
                        <x-brand-mark class="h-12 w-12" />
                        <span>
                            <span class="block font-serif text-sm font-semibold tracking-[0.16em] text-primary-deep uppercase dark:text-cane">{{ $publicWordmark }}</span>
                            @if($publicWordmarkSub)
                                <span class="mt-1 block text-[10px] tracking-[0.34em] text-muted uppercase">{{ $publicWordmarkSub }}</span>
                            @endif
                        </span>
                    </div>
                    <p class="mt-5 max-w-xs text-sm leading-relaxed text-muted">
                        Fresh, folded and back at your door. Free pickup and delivery, handled by people who treat your clothes like their own.
                    </p>
                </div>

                <div>
                    <h4 class="text-[11px] font-semibold tracking-[0.2em] text-dark/60 uppercase dark:text-[#F3EBE1]/60">Company</h4>
                    <ul class="mt-4 space-y-2.5 text-sm text-muted">
                        <li><a href="{{ route('landing') }}#services" class="transition hover:text-primary">Services</a></li>
                        <li><a href="{{ route('landing') }}#how" class="transition hover:text-primary">How it works</a></li>
                        <li><a href="{{ route('landing') }}#rates" class="transition hover:text-primary">Rates</a></li>
                        <li><a href="{{ route('landing') }}#faq" class="transition hover:text-primary">FAQ</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="text-[11px] font-semibold tracking-[0.2em] text-dark/60 uppercase dark:text-[#F3EBE1]/60">Your laundry</h4>
                    <ul class="mt-4 space-y-2.5 text-sm text-muted">
                        <li><a href="{{ route('landing') }}#book" class="transition hover:text-primary">Book a pickup</a></li>
                        <li><a href="{{ route('landing') }}#track" class="transition hover:text-primary">Track an order</a></li>
                        @if($publicCustomer)
                            <li><a href="{{ route('customer.bookings.index') }}" class="transition hover:text-primary">My bookings</a></li>
                        @else
                            <li><a href="{{ route('customer.login') }}" class="transition hover:text-primary">Sign in</a></li>
                            <li><a href="{{ route('customer.register') }}" class="transition hover:text-primary">Create an account</a></li>
                        @endif
                    </ul>
                </div>

                <div>
                    <h4 class="text-[11px] font-semibold tracking-[0.2em] text-dark/60 uppercase dark:text-[#F3EBE1]/60">Get in touch</h4>
                    <ul class="mt-4 space-y-3 text-sm text-muted">
                        @if($appSettings?->contact_number)
                            <li class="flex items-start gap-2.5">
                                <span data-lucide="phone" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                                <a href="tel:{{ preg_replace('/\s+/', '', $appSettings->contact_number) }}" class="transition hover:text-primary">{{ $appSettings->contact_number }}</a>
                            </li>
                        @endif
                        @if($appSettings?->business_email)
                            <li class="flex items-start gap-2.5">
                                <span data-lucide="mail" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                                <a href="mailto:{{ $appSettings->business_email }}" class="transition hover:text-primary">{{ $appSettings->business_email }}</a>
                            </li>
                        @endif
                        @if($appSettings?->business_address)
                            <li class="flex items-start gap-2.5">
                                <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                                <span>{{ $appSettings->business_address }}</span>
                            </li>
                        @endif
                        <li class="flex items-start gap-2.5">
                            <span data-lucide="clock" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></span>
                            <span>Pickups daily, 8:00 AM &ndash; 7:00 PM</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 flex flex-col gap-3 border-t border-border pt-6 text-xs text-muted sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                <p>&copy; {{ now()->year }} {{ $publicBusinessName }}. All rights reserved.</p>
                <a href="{{ route('login') }}" class="transition hover:text-primary">Staff sign in</a>
            </div>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
