@extends('layouts.public')

@section('page_title', 'My bookings')
@section('back_url', route('landing'))

@section('content')
<section class="px-4 pt-6 sm:pt-10">
    <div class="mx-auto max-w-3xl lg:max-w-5xl">

        {{-- Header --}}
        <div class="flex flex-col gap-4 text-center sm:flex-row sm:items-end sm:justify-between sm:text-left">
            <div>
                <p class="text-xs font-bold tracking-[0.24em] text-cc-brown uppercase">Your account</p>
                <h1 class="cc-title mt-1.5">Hello, {{ strtok($customer->name, ' ') }}</h1>
                <p class="mt-1 text-sm text-cc-muted">
                    {{ $customer->phone }}@if($customer->branch) &middot; {{ $customer->branch->name }} @endif
                </p>
            </div>

            <a href="{{ route('landing') }}#book" class="cc-btn w-full sm:w-auto">
                <span data-lucide="truck" class="h-4.5 w-4.5"></span>
                Book a Pickup
            </a>
        </div>

        {{-- Pickup bookings --}}
        <div class="mt-10 flex flex-wrap items-end justify-between gap-2">
            <h2 class="font-display text-2xl font-bold text-cc-deep">Pickup Bookings</h2>
            <div class="flex items-center gap-2 text-xs text-cc-muted">
                <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                <span id="customer-bookings-live-label" role="status">Updates automatically</span>
                <button id="customer-bookings-check" type="button" class="rounded-full border border-cc-line px-2.5 py-1 font-semibold text-cc-brown hover:bg-cc-surface">Check now</button>
            </div>
        </div>

        @if($requests->isEmpty())
            <div class="cc-card mt-4 px-6 py-12 text-center">
                <span class="cc-icon-tile mx-auto h-14 w-14"><span data-lucide="truck" class="h-6 w-6"></span></span>
                <p class="mt-5 font-bold text-cc-deep">No bookings yet</p>
                <p class="cc-subtitle mx-auto mt-1.5 max-w-sm">
                    Book your first pickup and we will collect from your door, no charge for the trip.
                </p>
                <a href="{{ route('landing') }}#book" class="cc-btn mt-6">
                    <span data-lucide="plus" class="h-4 w-4"></span>
                    Book a Pickup
                </a>
            </div>
        @else
            {{-- Desktop: two columns of bookings once there is more than one. --}}
            <div class="mt-4 space-y-3 {{ $requests->count() > 1 ? 'lg:grid lg:grid-cols-2 lg:gap-4 lg:space-y-0' : '' }}">
                @foreach ($requests as $pickupRequest)
                    <article class="cc-card p-4 sm:p-5 lg:flex lg:flex-col">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('customer.bookings.show', $pickupRequest) }}"
                                   class="font-mono text-sm font-bold text-cc-brown hover:underline">#{{ $pickupRequest->reference_no }}</a>
                                <p class="mt-1 font-bold text-cc-deep">{{ $pickupRequest->serviceTypeLabel() }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[11px] font-semibold text-cc-muted">{{ $pickupRequest->jobOrder ? 'Order total' : 'Estimate' }}</p>
                                <p class="font-display text-2xl leading-none font-bold text-cc-deep">
                                    ₱{{ number_format((float) ($pickupRequest->jobOrder->total ?? $pickupRequest->estimated_total ?? 0), 2) }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @include('partials.booking-status', ['status' => $pickupRequest->customerProgressStatus()])
                            @if($pickupRequest->is_rush)
                                <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">
                                    <span data-lucide="zap" class="h-2.5 w-2.5"></span> Rush
                                </span>
                            @endif
                        </div>

                        <p class="mt-3 flex items-center gap-2 text-sm text-cc-muted">
                            <span data-lucide="calendar-days" class="h-4 w-4 shrink-0 text-cc-brown"></span>
                            {{ $pickupRequest->pickup_date->format('M j, Y') }} &middot; {{ $pickupRequest->pickupSlotLabel() }}
                        </p>
                        <p class="mt-1 flex items-start gap-2 text-sm text-cc-muted lg:mb-4">
                            <span data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-cc-brown"></span>
                            <span class="min-w-0">{{ \Illuminate\Support\Str::limit($pickupRequest->pickup_address, 90) }}</span>
                        </p>

                        {{-- Pinned to the bottom so the actions line up across a row of cards. --}}
                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-cc-line pt-3 lg:mt-auto">
                            <a href="{{ route('customer.bookings.show', $pickupRequest) }}" class="cc-btn-outline cc-btn-sm">
                                <span data-lucide="eye" class="h-4 w-4"></span>
                                View status
                            </a>

                            @if($pickupRequest->isCancellable())
                                <form method="POST" action="{{ route('customer.bookings.cancel', $pickupRequest) }}"
                                      onsubmit="return confirm('Cancel booking {{ $pickupRequest->reference_no }}?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-sm font-bold text-red-700 hover:bg-red-50">
                                        <span data-lucide="x" class="h-4 w-4"></span>
                                        Cancel
                                    </button>
                                </form>
                            @endif

                            <span class="ml-auto text-[11px] text-cc-muted">Booked {{ $pickupRequest->created_at->diffForHumans() }}</span>
                        </div>
                    </article>
                @endforeach
            </div>

            @if($requests->hasPages())
                <div class="mt-6">{{ $requests->links() }}</div>
            @endif
        @endif

        {{-- Counter history: orders placed in person, not through the site --}}
        @if($jobOrders->isNotEmpty())
            <h2 class="mt-12 font-display text-2xl font-bold text-cc-deep">Recent Laundry Orders</h2>
            <p class="mt-1 text-sm text-cc-muted">Everything on your account, including orders made at the counter.</p>

            <ul class="cc-card mt-4 divide-y divide-cc-line overflow-hidden">
                @foreach ($jobOrders as $order)
                    <li class="flex items-center justify-between gap-4 px-4 py-3.5 sm:px-5">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-bold text-cc-brown">{{ $order->job_order_number }}</p>
                            <p class="mt-0.5 truncate text-xs text-cc-muted capitalize">
                                {{ ucfirst(str_replace('_', ' ', $order->customerProgressStatus())) }}
                                &middot; {{ $order->branch?->name ?? '--' }}
                                &middot; {{ $order->created_at->format('M j, Y') }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-bold text-cc-deep">₱{{ number_format((float) $order->total, 2) }}</p>
                            <p class="text-[11px] {{ (float) $order->balance > 0 ? 'font-bold text-red-700' : 'text-cc-muted' }}">
                                Balance ₱{{ number_format((float) $order->balance, 2) }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
@endsection

@push('scripts')
<script>
(() => {
    const label = document.getElementById('customer-bookings-live-label');
    const button = document.getElementById('customer-bookings-check');
    if (!label || !button) return;

    const url = new URL(window.location.href);
    url.searchParams.set('live', '1');
    const scrollKey = 'customer-bookings-scroll:' + window.location.pathname + window.location.search;
    const version = @js($liveSignature);
    let timer = null;
    let checking = false;

    try {
        const saved = sessionStorage.getItem(scrollKey);
        if (saved !== null) {
            sessionStorage.removeItem(scrollKey);
            window.scrollTo(0, Number(saved) || 0);
        }
    } catch (_) {}

    const schedule = () => {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(check, 5000);
    };

    async function check() {
        if (checking || document.hidden) return;
        checking = true;
        button.disabled = true;
        label.textContent = 'Checking for updates...';
        try {
            const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Could not check. Retrying soon.');
            const data = await response.json();
            if (data.signature !== version) {
                try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (_) {}
                label.textContent = 'New update found. Refreshing...';
                window.location.reload();
                return;
            }
            label.textContent = 'Up to date · checked just now';
        } catch (error) {
            label.textContent = error.message || 'Could not check. Retrying soon.';
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
