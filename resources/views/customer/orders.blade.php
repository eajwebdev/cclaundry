@extends('layouts.public')

@section('page_title', 'My bookings')

@section('content')
<section class="py-12 sm:py-16">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

        {{-- Header --}}
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[11px] font-semibold tracking-[0.22em] text-primary uppercase">Your account</p>
                <h1 class="mt-2 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                    Hello, {{ strtok($customer->name, ' ') }}
                </h1>
                <p class="mt-2 text-sm text-muted">
                    {{ $customer->phone }}@if($customer->branch) &middot; {{ $customer->branch->name }} @endif
                </p>
            </div>

            <a href="{{ route('landing') }}#book"
               class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-primary px-6 text-sm font-semibold text-white shadow-lg shadow-primary/20 transition hover:bg-primary-deep">
                <span data-lucide="truck" class="h-4 w-4"></span>
                Book a pickup
            </a>
        </div>

        {{-- Pickup bookings --}}
        <h2 class="mt-12 font-serif text-xl font-medium text-primary-deep dark:text-cane">Pickup bookings</h2>

        @if($requests->isEmpty())
            <div class="mt-5 rounded-3xl border border-dashed border-border bg-white px-8 py-14 text-center dark:border-white/12 dark:bg-[#241a13]">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                    <span data-lucide="truck" class="h-6 w-6"></span>
                </span>
                <p class="mt-5 font-medium">No bookings yet</p>
                <p class="mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-muted">
                    Book your first pickup and we will collect from your door &mdash; no charge for the trip.
                </p>
                <a href="{{ route('landing') }}#book"
                   class="mt-6 inline-flex h-11 items-center gap-2 rounded-xl bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-deep">
                    <span data-lucide="plus" class="h-4 w-4"></span>
                    Book a pickup
                </a>
            </div>
        @else
            <div class="mt-5 space-y-4">
                @foreach ($requests as $pickupRequest)
                    <article class="rounded-2xl border border-border bg-white p-5 transition hover:border-primary/30 sm:p-6 dark:border-white/10 dark:bg-[#241a13]">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <a href="{{ route('customer.bookings.show', $pickupRequest) }}"
                                       class="font-mono text-sm font-semibold text-primary hover:underline">{{ $pickupRequest->reference_no }}</a>
                                    @include('partials.booking-status', ['status' => $pickupRequest->status])
                                    @if($pickupRequest->is_rush)
                                        <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                            <span data-lucide="zap" class="h-2.5 w-2.5"></span> Rush
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-2.5 text-sm font-medium">{{ $pickupRequest->serviceTypeLabel() }}</p>
                                <p class="mt-1 text-sm text-muted">
                                    Pickup {{ $pickupRequest->pickup_date->format('M j, Y') }} &middot; {{ $pickupRequest->pickupSlotLabel() }}
                                </p>
                                <p class="mt-1 text-sm leading-relaxed text-muted">{{ \Illuminate\Support\Str::limit($pickupRequest->pickup_address, 90) }}</p>
                            </div>

                            <div class="text-right">
                                <p class="text-[11px] tracking-wide text-muted uppercase">
                                    {{ $pickupRequest->jobOrder ? 'Order total' : 'Estimate' }}
                                </p>
                                <p class="mt-0.5 font-serif text-xl font-semibold text-primary">
                                    ₱{{ number_format((float) ($pickupRequest->jobOrder->total ?? $pickupRequest->estimated_total ?? 0), 2) }}
                                </p>
                                @if($pickupRequest->jobOrder)
                                    <p class="mt-0.5 font-mono text-[11px] text-muted">{{ $pickupRequest->jobOrder->job_order_number }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4 dark:border-white/10">
                            <a href="{{ route('customer.bookings.show', $pickupRequest) }}"
                               class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3.5 text-xs font-medium transition hover:border-primary/40 hover:text-primary dark:border-white/12">
                                <span data-lucide="eye" class="h-3.5 w-3.5"></span>
                                View details
                            </a>

                            @if($pickupRequest->isCancellable())
                                <form method="POST" action="{{ route('customer.bookings.cancel', $pickupRequest) }}"
                                      onsubmit="return confirm('Cancel booking {{ $pickupRequest->reference_no }}?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3.5 text-xs font-medium text-muted transition hover:border-red-300 hover:text-red-600 dark:border-white/12">
                                        <span data-lucide="x" class="h-3.5 w-3.5"></span>
                                        Cancel
                                    </button>
                                </form>
                            @endif

                            <span class="ml-auto text-[11px] text-muted">Booked {{ $pickupRequest->created_at->diffForHumans() }}</span>
                        </div>
                    </article>
                @endforeach
            </div>

            @if($requests->hasPages())
                <div class="mt-7">{{ $requests->links() }}</div>
            @endif
        @endif

        {{-- Counter history: orders placed in person, not through the site --}}
        @if($jobOrders->isNotEmpty())
            <h2 class="mt-14 font-serif text-xl font-medium text-primary-deep dark:text-cane">Recent laundry orders</h2>
            <p class="mt-1.5 text-sm text-muted">Everything on your account, including orders made at the counter.</p>

            <div class="mt-5 overflow-hidden rounded-2xl border border-border bg-white dark:border-white/10 dark:bg-[#241a13]">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-border bg-cream text-left text-[11px] tracking-wide text-muted uppercase dark:border-white/10 dark:bg-[#1c1510]">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Order</th>
                                <th class="px-5 py-3 font-semibold">Branch</th>
                                <th class="px-5 py-3 font-semibold">Date</th>
                                <th class="px-5 py-3 text-right font-semibold">Total</th>
                                <th class="px-5 py-3 text-right font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border dark:divide-white/8">
                            @foreach ($jobOrders as $order)
                                <tr>
                                    <td class="px-5 py-3">
                                        <span class="font-mono text-xs font-semibold text-primary">{{ $order->job_order_number }}</span>
                                        <span class="mt-0.5 block text-[11px] text-muted capitalize">{{ str_replace('_', ' ', $order->status) }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-muted">{{ $order->branch?->name ?? '--' }}</td>
                                    <td class="px-5 py-3 text-muted">{{ $order->created_at->format('M j, Y') }}</td>
                                    <td class="px-5 py-3 text-right font-medium">₱{{ number_format((float) $order->total, 2) }}</td>
                                    <td class="px-5 py-3 text-right {{ (float) $order->balance > 0 ? 'font-semibold text-red-600' : 'text-muted' }}">
                                        ₱{{ number_format((float) $order->balance, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
