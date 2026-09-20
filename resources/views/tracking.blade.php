@extends('layouts.public')

@section('page_title', 'Tracking ' . $pickupRequest->reference_no)
@section('back_url', route('landing') . '#track')

@php
    $declaredKilos = $pickupRequest->declaredKilos();
    $kilos = $declaredKilos
        ? rtrim(rtrim(number_format($declaredKilos, 2), '0'), '.').' kg'
        : 'Weight to be confirmed';

    $details = [
        ['laundry', 'Booked', $pickupRequest->serviceSummary() ?: $pickupRequest->serviceTypeLabel()],
        ['store', 'Branch', $pickupRequest->branch?->name ?? '--'],
        ...($pickupRequest->tag_code
            ? [['tag', 'Laundry tag', $pickupRequest->tag_code.', the number on your bag']]
            : []),
        ['calendar-days', 'Pickup', $pickupRequest->pickup_date->format('D, M j, Y').' · '.$pickupRequest->pickupSlotLabel()],
        ['truck', 'Return', $pickupRequest->wantsDelivery()
            ? 'Delivery'.($pickupRequest->delivery_date ? ' on '.$pickupRequest->delivery_date->format('D, M j, Y') : ', date to be confirmed')
            : 'Claim at branch'],
        ['jobOrders', 'Job order', $pickupRequest->jobOrder?->job_order_number ?? 'Not yet created'],
    ];
@endphp

@section('content')
<section class="px-4 pt-6 sm:pt-10">
    {{-- Desktop: the timeline and map on the left, the details beside them. --}}
    <div class="mx-auto max-w-xl space-y-5 lg:grid lg:max-w-5xl lg:grid-cols-[minmax(0,1.3fr)_minmax(0,0.9fr)] lg:items-start lg:gap-x-8 lg:gap-y-6 lg:space-y-0">

        <div class="text-center sm:text-left lg:col-span-2">
            <h1 class="cc-title">Laundry Status</h1>
            <p class="cc-subtitle mt-1">Updated by the branch as your laundry moves along.</p>
            <div class="mt-3 flex flex-wrap items-center justify-center gap-2 text-xs text-cc-muted sm:justify-start">
                <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                <span id="tracking-refresh-label" role="status">Checking for updates automatically every 15 seconds</span>
                <button id="tracking-refresh-button" type="button" class="rounded-full border border-cc-line px-2.5 py-1 font-semibold text-cc-brown hover:bg-cc-surface">Check now</button>
            </div>
        </div>

        <div class="cc-card p-5 sm:p-6 lg:p-8">
            <div class="cc-soft flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-bold wrap-break-word text-cc-deep">Booking #{{ $pickupRequest->reference_no }}</p>
                    <p class="mt-0.5 text-xs text-cc-muted">{{ $kilos }} &middot; {{ $pickupRequest->serviceTypeLabel() }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-xs text-cc-muted">
                        <span data-lucide="calendar-days" class="h-3.5 w-3.5 text-cc-brown"></span>
                        Pickup: {{ $pickupRequest->pickup_date->format('M j, Y') }}
                    </p>
                </div>
                @include('partials.booking-status', ['status' => $pickupRequest->customerProgressStatus()])
            </div>

            <div class="mt-5">
                @include('partials.booking-timeline')
            </div>

            @if($pickupRequest->isTrackable())
                @include('partials.live-tracking-map')
            @endif
        </div>

        <div class="space-y-5 lg:sticky lg:top-28">
            <div class="cc-card p-5 sm:p-6">
                <ul class="divide-y divide-cc-line">
                    @foreach ($details as [$icon, $label, $detail])
                        <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                            <span data-lucide="{{ $icon }}" class="mt-0.5 h-5 w-5 shrink-0 text-cc-brown"></span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-cc-muted">{{ $label }}</p>
                                <p class="mt-0.5 text-sm font-bold wrap-break-word text-cc-deep">{{ $detail }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if($pickupRequest->jobOrder)
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="cc-soft px-4 py-3">
                            <p class="text-xs text-cc-muted">Order total</p>
                            <p class="mt-1 font-display text-2xl leading-none font-bold text-cc-deep">₱{{ number_format((float) $pickupRequest->jobOrder->total, 2) }}</p>
                        </div>
                        <div class="cc-soft px-4 py-3">
                            <p class="text-xs text-cc-muted">Balance</p>
                            <p class="mt-1 font-display text-2xl leading-none font-bold {{ (float) $pickupRequest->jobOrder->balance > 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                ₱{{ number_format((float) $pickupRequest->jobOrder->balance, 2) }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>

            <p class="text-center text-sm text-cc-muted lg:text-left">
                Want to manage this booking?
                <a href="{{ route('login') }}" class="font-bold text-cc-brown hover:underline">Sign in to your account</a>
            </p>
        </div>
    </div>
</section>
<form id="tracking-refresh-form" method="POST" action="{{ route('track') }}" class="hidden" aria-hidden="true">
    @csrf
    <input type="hidden" name="reference_no" value="{{ $pickupRequest->reference_no }}">
    <input type="hidden" name="phone" value="{{ $trackingPhone }}">
</form>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('tracking-refresh-form');
    const label = document.getElementById('tracking-refresh-label');
    const button = document.getElementById('tracking-refresh-button');
    if (!form || !label || !button) return;

    const endpoint = @js(route('track.status'));
    const scrollKey = @js('tracking-scroll-'.$pickupRequest->reference_no);
    let version = @js($trackingVersion);
    let timer = null;
    let checking = false;

    try {
        const savedScroll = sessionStorage.getItem(scrollKey);
        if (savedScroll !== null) {
            sessionStorage.removeItem(scrollKey);
            window.scrollTo(0, Number(savedScroll) || 0);
        }
    } catch (_) {}

    const schedule = () => {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(check, 15000);
    };

    async function check() {
        if (checking || document.hidden) return;
        checking = true;
        button.disabled = true;
        label.textContent = 'Checking your laundry status...';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 419) {
                const tokenResponse = await fetch(@js(route('csrf.token')), { cache: 'no-store' });
                if (tokenResponse.ok) form.elements['_token'].value = (await tokenResponse.json()).token;
                throw new Error('Session refreshed; checking again soon.');
            }
            if (!response.ok) throw new Error('Could not check status. Retrying soon.');

            const result = await response.json();
            if (result.version !== version) {
                try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (_) {}
                label.textContent = 'New update found. Refreshing...';
                form.submit();
                return;
            }
            label.textContent = 'Up to date · checked just now';
        } catch (error) {
            label.textContent = error.message || 'Could not check status. Retrying soon.';
        } finally {
            checking = false;
            button.disabled = false;
            schedule();
        }
    }

    button.addEventListener('click', check);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else check();
    });
    window.addEventListener('online', check);
    schedule();
})();
</script>
@endpush
