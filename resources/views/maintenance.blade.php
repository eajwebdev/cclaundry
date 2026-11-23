{{--
    Used two ways:
    1. `php artisan down --render="maintenance"` pre-renders this once, with no data, no session and no user.
    2. EnsureSystemNotUnderMaintenance renders it per request with $message and $since.
    So it must stay self-contained: inline CSS only (a deploy rebuilds the Vite assets while the site is down)
    and every variable optional.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>System Under Maintenance</title>
    <style>
        :root { --bg: #F6F1EA; --card: #FFFFFF; --ink: #2B211A; --muted: #8A7A6B; --line: #E7DDD1; --brand: #A07148; --brand-soft: #F3E9DE; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #17120E; --card: #211A14; --ink: #F3ECE4; --muted: #B3A392; --line: #3A2E24; --brand: #C7AC94; --brand-soft: #33271D; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 28rem; padding: 32px 24px; text-align: center; background: var(--card);
               border: 1px solid var(--line); border-radius: 12px; }
        .icon { width: 56px; height: 56px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;
                border-radius: 50%; background: var(--brand-soft); color: var(--brand); }
        .brand { margin: 0 0 4px; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--brand); }
        h1 { margin: 0; font-size: 22px; line-height: 1.3; }
        p { margin: 12px 0 0; font-size: 15px; line-height: 1.5; color: var(--muted); }
        .since { font-size: 13px; }
        button { margin-top: 20px; height: 40px; padding: 0 18px; font: inherit; font-size: 14px; font-weight: 500; color: var(--ink);
                 background: transparent; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; }
        button:hover { background: var(--brand-soft); }
    </style>
</head>
<body>
    <main>
        <div class="icon" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
        </div>
        <p class="brand">Cane &amp; Cotton Laundry</p>
        <h1>System Under Maintenance</h1>
        <p>{{ $message ?? 'The system is temporarily unavailable while we perform scheduled maintenance. Please check back shortly.' }}</p>

        @if(! empty($since))
            <p class="since">In maintenance since {{ $since->format('M d, Y g:i A') }}.</p>
        @endif

        @auth
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Logout</button>
            </form>
        @endauth
    </main>
</body>
</html>
