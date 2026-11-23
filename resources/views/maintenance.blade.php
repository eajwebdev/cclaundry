{{--
    Used two ways:
    1. `php artisan down --render="maintenance"` pre-renders this once, with no data, no session and no user.
    2. EnsureSystemNotUnderMaintenance renders it per request with $message and $since.
    So it must stay self-contained: inline CSS only (a deploy rebuilds the Vite assets while the site is down)
    and every variable optional. The web font is an enhancement; the page reads fine on the Georgia fallback.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#F7EFE4" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1B140F" media="(prefers-color-scheme: dark)">
    <title>System Under Maintenance · Cane &amp; Cotton Laundry</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Nunito+Sans:wght@400;600&display=swap">
    <style>
        :root {
            --ground: #F7EFE4; --surface: #FCF8F2; --soft: #F2E8DB; --line: #E0CEB8; --ink: #3B2B20; --muted: #6F5B4B;
            --brown: #82573A; --tan: #B48D6D; --water: #C9DCDD; --water-deep: #A9C6C9;
            --display: "Cormorant Garamond", Georgia, "Times New Roman", serif;
            --body: "Nunito Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --ground: #1B140F; --surface: #2A2019; --soft: #231A14; --line: #4A3A2D; --ink: #F5ECE2; --muted: #C7B6A5;
                --brown: #D1AE8E; --tan: #94704F; --water: #35525A; --water-deep: #2B454C;
            }
        }
        * { box-sizing: border-box; }
        ::selection { background: var(--brown); color: var(--ground); }
        html, body { height: 100%; }
        body {
            margin: 0; min-height: 100dvh; display: grid; grid-template-rows: auto 1fr;
            padding: clamp(20px, 4vw, 40px); background: var(--ground); color: var(--ink);
            font-family: var(--body); -webkit-font-smoothing: antialiased;
        }
        .brand { margin: 0; font-family: var(--display); font-size: 22px; font-weight: 600; line-height: 1.2; color: var(--brown); }
        main {
            align-self: center; justify-self: center; width: 100%; max-width: 54rem; padding-block: 24px;
            display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: clamp(40px, 7vw, 80px);
        }
        .washer { display: block; width: clamp(190px, 25vw, 260px); height: auto; }
        h1 {
            margin: 0; font-family: var(--display); font-size: clamp(2.5rem, 5.4vw, 4rem); font-weight: 600;
            line-height: 1.02; letter-spacing: -.015em; text-wrap: balance;
        }
        p.message { margin: 20px 0 0; max-width: 44ch; font-size: 17px; line-height: 1.6; color: var(--muted); text-wrap: pretty; }
        .since { margin: 14px 0 0; font-size: 14px; color: var(--muted); font-variant-numeric: tabular-nums; }
        form { margin: 26px 0 0; }
        button {
            min-height: 44px; padding: 0 20px; font: inherit; font-size: 15px; font-weight: 600; color: var(--ink);
            background: transparent; border: 1px solid var(--tan); border-radius: 8px; cursor: pointer;
            transition: background-color .18s cubic-bezier(.16, 1, .3, 1);
        }
        button:hover { background: var(--soft); }
        button:focus-visible { outline: 2px solid var(--brown); outline-offset: 3px; }

        .tumble { transform-box: view-box; transform-origin: 100px 136px; animation: tumble 7s linear infinite; }
        .wave { animation: wave 3.2s linear infinite; }
        .wave-back { animation-duration: 4.6s; animation-direction: reverse; }
        .light { animation: blink 2.4s ease-in-out infinite; }
        @keyframes tumble { to { transform: rotate(360deg); } }
        @keyframes wave { to { transform: translateX(112px); } }
        @keyframes blink { 50% { opacity: .3; } }
        @media (prefers-reduced-motion: reduce) { .tumble, .wave, .light { animation: none; } }

        @media (max-width: 680px) {
            main { grid-template-columns: minmax(0, 1fr); justify-items: center; gap: 28px; text-align: center; }
            .brand { text-align: center; }
            .washer { width: clamp(148px, 44vw, 190px); }
            p.message { margin-inline: auto; font-size: 16px; }
        }
        @media (max-height: 560px) and (min-width: 681px) { .washer { width: 170px; } h1 { font-size: 2.5rem; } }
    </style>
</head>
<body>
    <p class="brand">Cane &amp; Cotton Laundry</p>

    <main>
        <svg class="washer" viewBox="0 0 200 232" fill="none" aria-hidden="true" focusable="false">
            <defs><clipPath id="drum"><circle cx="100" cy="136" r="54"/></clipPath></defs>
            <path d="M34 224v6M166 224v6" stroke="var(--tan)" stroke-width="5" stroke-linecap="round"/>
            <rect x="8" y="4" width="184" height="220" rx="20" fill="var(--surface)" stroke="var(--line)" stroke-width="2"/>
            <path d="M8 50h184" stroke="var(--line)" stroke-width="2"/>
            <rect x="26" y="21" width="42" height="12" rx="4" fill="var(--soft)" stroke="var(--line)" stroke-width="2"/>
            <circle class="light" cx="134" cy="27" r="4" fill="var(--brown)"/>
            <circle cx="164" cy="27" r="11" fill="var(--soft)" stroke="var(--tan)" stroke-width="2"/>
            <path d="M164 27v-7" stroke="var(--brown)" stroke-width="2" stroke-linecap="round"/>
            <circle cx="100" cy="136" r="68" fill="var(--soft)" stroke="var(--line)" stroke-width="2"/>
            <circle cx="100" cy="136" r="58" fill="var(--ground)" stroke="var(--brown)" stroke-width="4"/>
            <g clip-path="url(#drum)">
                <path class="wave wave-back" fill="var(--water-deep)" d="M-120 138q28-10 56 0t56 0 56 0 56 0 56 0V200H-120Z"/>
                <g class="tumble">
                    <path fill="var(--tan)" d="M70 112c8-8 22-6 27 3 4 8-2 17-12 18-11 2-23-12-15-21Z"/>
                    <path fill="var(--brown)" d="M118 150c9-5 21-1 24 8 2 9-6 16-16 15-11-1-18-18-8-23Z"/>
                    <path stroke="var(--line)" stroke-width="4" stroke-linecap="round" d="M100 84v12M100 176v12M48 136h12M140 136h12"/>
                </g>
                <path class="wave" fill="var(--water)" opacity=".82" d="M-120 150q28-10 56 0t56 0 56 0 56 0 56 0V200H-120Z"/>
            </g>
            <path d="M66 108a44 44 0 0 1 22-15" stroke="var(--surface)" stroke-width="4" stroke-linecap="round" opacity=".8"/>
        </svg>

        <div>
            <h1>System Under Maintenance</h1>
            <p class="message">{{ $message ?? 'The system is temporarily unavailable while we perform scheduled maintenance. Please check back shortly.' }}</p>

            @if(! empty($since))
                <p class="since">In maintenance since {{ $since->format('M d, Y g:i A') }}.</p>
            @endif

            @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Logout</button>
                </form>
            @endauth
        </div>
    </main>
</body>
</html>
