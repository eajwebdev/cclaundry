<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="--color-primary: {{ $appPrimaryColor ?? '#2E7D32' }};">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appBusinessName ?? config('app.name', 'Laundry System') }}</title>
    <link rel="icon" href="{{ $appBusinessLogo ?? asset('logo.png') }}">
    <link rel="apple-touch-icon" href="{{ $appBusinessLogo ?? asset('logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-smoke text-dark dark:bg-gray-950 dark:text-gray-100">
    <main class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-border bg-white p-6 text-center shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <x-brand-mark class="mx-auto mb-4 h-16 w-16" />
            <h1 class="text-xl font-semibold">{{ $appBusinessName ?? 'Laundry System' }}</h1>
            <p class="mt-2 text-sm text-muted">Laundry operations and attendance management.</p>
            <div class="mt-5 grid gap-2">
                <a href="{{ route('login') }}" class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-white">Staff sign in</a>
                <a href="{{ route('attendance.kiosk') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-border px-4 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">Employee Time Clock</a>
            </div>
        </div>
    </main>
</body>
</html>
