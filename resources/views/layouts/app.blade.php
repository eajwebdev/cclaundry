@php($isPos = request()->routeIs('admin.job-orders.create', 'admin.job-orders.edit'))
@php($sidebarAutoCollapsed = $isPos)
<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth {{ $isPos ? 'h-[100dvh] max-h-[100dvh] overflow-hidden' : '' }}" style="--color-primary: {{ $appPrimaryColor }};">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="{{ $appPrimaryColor }}">
    <title>@yield('page_title', 'Dashboard') - {{ $appSystemName }}</title>
    <link rel="icon" href="{{ $appBusinessLogo }}">
    <link rel="apple-touch-icon" href="{{ $appBusinessLogo }}">
    {{-- Page-specific bundles load here, ahead of app.js, so anything they
         put on window exists before Alpine starts. --}}
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        window.appDarkModeDefault = @js($appDarkModeDefault);
        window.appPrimaryColor = @js($appPrimaryColor);
    </script>
    @if($isPos)
        <style>
            html, body {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                height: 100dvh !important;
                max-height: 100dvh !important;
                overflow: hidden !important;
                overscroll-behavior: none !important;
                -webkit-overflow-scrolling: auto !important;
            }
        </style>
    @endif
</head>

<body class="app-surface text-dark dark:text-gray-100 {{ $isPos ? 'fixed inset-0 h-full w-full overflow-hidden overscroll-none' : '' }}">
    <div
        x-data="{
            sidebarOpen: false,
            desktopSidebarOpen: @js(! $sidebarAutoCollapsed),
            isDesktop: window.matchMedia('(min-width: 1280px)').matches,
            init() {
                const query = window.matchMedia('(min-width: 1280px)');
                const sync = () => {
                    this.isDesktop = query.matches;
                    if (this.isDesktop) {
                        this.sidebarOpen = false;
                    }
                };

                sync();
                query.addEventListener('change', sync);
            },
            toggleSidebar() {
                if (this.isDesktop) {
                    this.desktopSidebarOpen = ! this.desktopSidebarOpen;
                    return;
                }

                this.sidebarOpen = true;
            },
            closeSidebar() {
                if (this.isDesktop) {
                    this.desktopSidebarOpen = false;
                    return;
                }

                this.sidebarOpen = false;
            },
            get sidebarVisible() {
                return this.sidebarOpen || (this.isDesktop && this.desktopSidebarOpen);
            }
        }"
        class="{{ $isPos ? 'fixed inset-0 z-0 h-full w-full overflow-hidden' : 'min-h-screen' }} flex"
    >

        @include('partials.sidebar')

        <div
            class="flex-1 flex min-w-0 flex-col h-full overflow-hidden transition-[padding] duration-200 {{ $sidebarAutoCollapsed ? 'xl:pl-0' : 'xl:pl-64' }}"
            :class="desktopSidebarOpen ? 'xl:!pl-64' : 'xl:!pl-0'"
        >
            @include('partials.topbar')

            <main class="flex-1 min-h-0 overflow-hidden flex flex-col {{ $isPos ? 'p-2 sm:p-2.5' : 'p-4 sm:p-6 lg:p-8' }}">
                @include('partials.alerts')
                @include('partials.billing-banner')
                @unless($isPos)
                    @include('partials.assistant-widget')
                @endunless

                {{ $slot ?? '' }}

                @yield('content')
            </main>

            @unless($isPos || trim($__env->yieldContent('hide_footer')))
                @include('partials.footer')
            @endunless
        </div>
    </div>

@stack('scripts')
@if($isPos)
<script>
(function() {
    document.addEventListener('touchmove', function(e) {
        var el = e.target;
        var canScroll = false;
        while (el && el !== document.body && el !== document.documentElement) {
            var style = window.getComputedStyle(el);
            var overflowY = style.overflowY;
            if ((overflowY === 'auto' || overflowY === 'scroll') && el.scrollHeight > el.clientHeight) {
                canScroll = true;
                break;
            }
            el = el.parentElement;
        }
        if (!canScroll) {
            e.preventDefault();
        }
    }, { passive: false });
})();
</script>
@endif
</body>
</html>
