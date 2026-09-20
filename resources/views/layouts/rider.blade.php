<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    {{-- viewport-fit=cover so sticky bars clear the home indicator on a notched
         phone, and no user scaling: this is an app surface, not a document. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#A07148">
    <title>@yield('page_title', 'Rider') &middot; {{ $appSystemName ?? config('app.name') }}</title>
    <link rel="icon" href="{{ asset('logo.png') }}">
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- overflow-hidden on full-bleed screens: the navigation view manages its own
     viewport and must never let the page scroll behind the map. --}}
<body class="app-surface min-h-dvh text-dark dark:text-gray-100 @if(trim($__env->yieldContent('full_bleed'))) overflow-hidden @endif">

{{-- Location sharing lives in the header so it is one tap from anywhere and
     always visible — a rider must never have to hunt for it, or wonder whether
     dispatch can see them. --}}
<div x-data="riderTracker()" class="contents">

    {{-- Fixed height only on the full-screen map views, which size themselves
         to the 60px bar. On the run list the header grows with its notice
         lines, so they push the cards down instead of covering them. --}}
    <header class="sticky top-0 z-40 @if(trim($__env->yieldContent('full_bleed'))) h-15 @endif border-b border-border bg-white/95 backdrop-blur dark:border-gray-800 dark:bg-[#241a13]/95">
        <div class="mx-auto flex h-15 max-w-2xl items-center gap-2 px-3">
            @if(trim($__env->yieldContent('full_bleed')))
                <a href="{{ route('rider.index') }}" aria-label="All runs"
                   class="inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center rounded-lg border border-border dark:border-gray-800">
                    <span data-lucide="arrow-left" class="h-4 w-4"></span>
                </a>
            @else
                <img src="{{ asset('logo.png') }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-contain">
            @endif

            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold leading-tight">@yield('page_title', 'My runs')</span>
                <span class="block truncate text-xs text-muted">{{ auth()->user()->name }}</span>
            </span>

            {{-- Compact sharing switch: dot shows state, tap toggles. --}}
            <button type="button" @click="toggle()"
                    :aria-label="sharing ? 'Stop sharing location' : 'Start sharing location'"
                    class="inline-flex h-11 shrink-0 touch-manipulation items-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold transition"
                    :class="sharing
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                        : 'border-border text-muted dark:border-gray-800'">
                <span class="relative flex h-2.5 w-2.5">
                    <span x-show="sharing" x-cloak
                          class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full"
                          :class="sharing ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                </span>
                <span x-text="sharing ? 'Live' : 'Off'"></span>
            </button>

            @include('rider.partials.booking-alerts')

            <button type="button" @click="$store.theme.toggle()"
                    aria-label="Toggle theme"
                    class="inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center rounded-lg border border-border dark:border-gray-800">
                <span data-lucide="moon" class="h-4 w-4"></span>
            </button>

            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <button type="submit" aria-label="Sign out"
                        class="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-lg border border-border dark:border-gray-800">
                    <span data-lucide="logout" class="h-4 w-4"></span>
                </button>
            </form>
        </div>

        {{-- Status line only appears when there is something to say, so it
             costs no vertical space the rest of the time. --}}
        <p x-show="sharing && statusLine" x-cloak
           class="truncate bg-emerald-50 px-3 py-1 text-center text-[11px] text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300"
           x-text="statusLine"></p>

        {{-- Anything the phone is holding onto. Says so plainly, because the
             run list will keep showing work the rider has already done until
             these land, and that gap is otherwise baffling. --}}
        <p x-show="$store.outbox.count" x-cloak
           class="truncate bg-amber-50 px-3 py-1 text-center text-[11px] font-semibold text-amber-900 dark:bg-amber-500/15 dark:text-amber-200">
            <span x-text="$store.outbox.count"></span>
            <span x-text="$store.outbox.count === 1 ? 'update waiting to send' : 'updates waiting to send'"></span>
            &middot; <span x-text="$store.outbox.sending ? 'sending…' : 'will retry automatically'"></span>
        </p>

        {{-- A queued tap the server turned down after the rider moved on: a run
             somebody else took first, a job that had already changed. It sent
             in the background, so nobody saw a dialog; this is where it shows. --}}
        <div x-show="$store.outbox.rejected.length" x-cloak
             class="flex items-start gap-2 bg-red-50 px-3 py-1.5 text-[11px] text-red-900 dark:bg-red-500/15 dark:text-red-200">
            <div class="min-w-0 flex-1">
                <template x-for="(item, index) in $store.outbox.rejected" :key="index">
                    <p class="truncate"><span class="font-semibold" x-text="item.describe"></span>: <span x-text="item.message"></span></p>
                </template>
            </div>
            <button type="button" @click="$store.outbox.dismissRejected()"
                    class="shrink-0 font-semibold underline">OK</button>
        </div>

        {{-- Sharing off with work in hand means dispatch and the customer are
             both blind. Worth one line rather than a silent nothing. --}}
        @if(! trim($__env->yieldContent('full_bleed')) && ($riderHasOpenRuns ?? false))
            <button type="button" @click="start()" x-show="! sharing" x-cloak
                    class="block w-full bg-amber-100 px-3 py-1.5 text-center text-[11px] font-semibold text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">
                Location sharing is off, so nobody can see where you are. Tap to turn it on.
            </button>
        @endif
    </header>

    @if(trim($__env->yieldContent('full_bleed')))
        @yield('content')
    @else
        <main class="mx-auto max-w-2xl px-3 pb-8 pt-3">
            @include('partials.alerts')
            @yield('content')
        </main>
    @endif
</div>

@include('rider.partials.tracker-script')
@stack('scripts')
</body>
</html>
