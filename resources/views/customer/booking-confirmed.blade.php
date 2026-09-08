@extends('layouts.public')

@section('page_title', 'Booking ' . $pickupRequest->reference_no)

@php
    $businessName = $appBusinessName ?: config('app.name');

    // The four beats of a booking, so the customer can see where theirs sits.
    $timeline = [
        ['pending', 'Booking received', 'We have your request and are confirming the slot.'],
        ['confirmed', 'Pickup scheduled', 'Your rider is assigned for the window you chose.'],
        ['picked_up', 'Collected & in progress', 'Your laundry is with us being washed and folded.'],
        ['completed', $pickupRequest->wantsDelivery() ? 'Delivered' : 'Claimed at branch', 'All done. Thank you for choosing us.'],
    ];

    $order = array_search($pickupRequest->status, array_column($timeline, 0), true);
    $currentIndex = $pickupRequest->status === 'cancelled' ? -1 : ($order === false ? 0 : $order);
@endphp

@section('content')
<section class="py-12 sm:py-16">
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

        {{-- Confirmation header --}}
        <div class="text-center">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-accent/15 text-accent-deep">
                <span data-lucide="check" class="h-7 w-7"></span>
            </span>
            <h1 class="mt-6 font-serif text-3xl leading-tight font-medium text-primary-deep sm:text-4xl dark:text-cane">
                @if($pickupRequest->status === 'cancelled')
                    This booking was cancelled
                @else
                    You&rsquo;re all set
                @endif
            </h1>
            <p class="mt-3 text-[15px] text-muted">
                @if($pickupRequest->status === 'cancelled')
                    Nothing further will happen with this request.
                @else
                    We&rsquo;ll text {{ $pickupRequest->contact_phone }} before the rider heads over.
                @endif
            </p>

            <div class="mt-6 inline-flex items-center gap-3 rounded-2xl border border-border bg-white px-5 py-3 dark:border-white/10 dark:bg-[#241a13]">
                <span class="text-[11px] tracking-[0.16em] text-muted uppercase">Reference</span>
                <span class="font-mono text-lg font-semibold tracking-wide text-primary">{{ $pickupRequest->reference_no }}</span>
            </div>
        </div>

        {{-- Progress --}}
        @if($pickupRequest->status !== 'cancelled')
            <ol class="mt-12 grid gap-6 sm:grid-cols-4">
                @foreach ($timeline as $index => [$key, $title, $body])
                    @php($done = $index <= $currentIndex)
                    <li class="relative">
                        <div class="flex items-center gap-3 sm:block">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 transition
                                {{ $done ? 'border-primary bg-primary text-white' : 'border-border bg-white text-muted dark:bg-[#241a13]' }}">
                                @if($done)
                                    <span data-lucide="check" class="h-4 w-4"></span>
                                @else
                                    <span class="text-xs font-semibold">{{ $index + 1 }}</span>
                                @endif
                            </span>
                            <p class="text-sm font-medium sm:mt-3 {{ $done ? 'text-primary-deep dark:text-cane' : 'text-muted' }}">{{ $title }}</p>
                        </div>
                        <p class="mt-1 hidden text-xs leading-relaxed text-muted sm:block">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Details --}}
        <div class="mt-12 grid gap-5 lg:grid-cols-2">
            <div class="rounded-3xl border border-border bg-white p-7 dark:border-white/10 dark:bg-[#241a13]">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-serif text-lg font-medium text-primary-deep dark:text-cane">Booking details</h2>
                    @include('partials.booking-status', ['status' => $pickupRequest->status])
                </div>

                <dl class="mt-5 divide-y divide-border dark:divide-white/8">
                    @foreach ([
                        'Service' => $pickupRequest->serviceTypeLabel(),
                        'Estimated load' => $pickupRequest->estimated_kilos ? rtrim(rtrim(number_format((float) $pickupRequest->estimated_kilos, 2), '0'), '.') . ' kg' : 'To be weighed',
                        'Branch' => $pickupRequest->branch?->name ?? '--',
                        'Rush service' => $pickupRequest->is_rush ? 'Yes' : 'No',
                        'Estimated total' => $pickupRequest->estimated_total ? '₱' . number_format((float) $pickupRequest->estimated_total, 2) : 'Set after weighing',
                    ] as $label => $detail)
                        <div class="flex items-start justify-between gap-4 py-3">
                            <dt class="shrink-0 text-sm text-muted">{{ $label }}</dt>
                            <dd class="text-right text-sm font-medium">{{ $detail }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if($pickupRequest->notes)
                    <div class="mt-5 rounded-2xl bg-cream px-4 py-3 dark:bg-[#1c1510]">
                        <p class="text-[11px] tracking-wide text-muted uppercase">Your instructions</p>
                        <p class="mt-1.5 text-sm leading-relaxed">{{ $pickupRequest->notes }}</p>
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-border bg-white p-7 dark:border-white/10 dark:bg-[#241a13]">
                <h2 class="font-serif text-lg font-medium text-primary-deep dark:text-cane">Pickup &amp; return</h2>

                <div class="mt-5 space-y-5">
                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <span data-lucide="truck" class="h-4.5 w-4.5"></span>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium">Pickup &mdash; {{ $pickupRequest->pickup_date->format('D, M j, Y') }}</p>
                            <p class="mt-0.5 text-sm text-muted">{{ $pickupRequest->pickupSlotLabel() }}</p>
                            <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ $pickupRequest->pickup_address }}</p>
                            @if($pickupRequest->pickup_landmark)
                                <p class="mt-1 text-xs text-muted">Landmark: {{ $pickupRequest->pickup_landmark }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent/15 text-accent-deep">
                            <span data-lucide="{{ $pickupRequest->wantsDelivery() ? 'packageCheck' : 'store' }}" class="h-4.5 w-4.5"></span>
                        </span>
                        <div class="min-w-0">
                            @if($pickupRequest->wantsDelivery())
                                <p class="text-sm font-medium">
                                    Delivery
                                    @if($pickupRequest->delivery_date) &mdash; {{ $pickupRequest->delivery_date->format('D, M j, Y') }} @endif
                                </p>
                                <p class="mt-0.5 text-sm text-muted">{{ $pickupRequest->deliverySlotLabel() ?: 'Window to be confirmed' }}</p>
                                <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ $pickupRequest->delivery_address }}</p>
                            @else
                                <p class="text-sm font-medium">Claim at {{ $pickupRequest->branch?->name }}</p>
                                <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ $pickupRequest->branch?->address }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <span data-lucide="user" class="h-4.5 w-4.5"></span>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ $pickupRequest->contact_name }}</p>
                            <p class="mt-0.5 text-sm text-muted">{{ $pickupRequest->contact_phone }}</p>
                        </div>
                    </div>
                </div>

                @if($pickupRequest->jobOrder)
                    <div class="mt-6 rounded-2xl border border-border bg-cream px-4 py-3.5 dark:border-white/10 dark:bg-[#1c1510]">
                        <p class="text-[11px] tracking-wide text-muted uppercase">Now a job order</p>
                        <p class="mt-1 font-mono text-sm font-semibold text-primary">{{ $pickupRequest->jobOrder->job_order_number }}</p>
                        <p class="mt-1 text-xs text-muted">Total ₱{{ number_format((float) $pickupRequest->jobOrder->total, 2) }} &middot; Balance ₱{{ number_format((float) $pickupRequest->jobOrder->balance, 2) }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Actions --}}
        <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="{{ route('customer.bookings.index') }}"
               class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-sm font-semibold text-white shadow-lg shadow-primary/20 transition hover:bg-primary-deep sm:w-auto">
                <span data-lucide="jobOrders" class="h-4 w-4"></span>
                View all my bookings
            </a>

            @if($pickupRequest->isCancellable())
                <form method="POST" action="{{ route('customer.bookings.cancel', $pickupRequest) }}" class="w-full sm:w-auto"
                      onsubmit="return confirm('Cancel booking {{ $pickupRequest->reference_no }}? This cannot be undone.');">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-border px-6 text-sm font-medium text-muted transition hover:border-red-300 hover:text-red-600 sm:w-auto dark:border-white/12">
                        <span data-lucide="x" class="h-4 w-4"></span>
                        Cancel this booking
                    </button>
                </form>
            @endif

            <a href="{{ route('landing') }}#book"
               class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-border px-6 text-sm font-medium transition hover:border-primary/40 sm:w-auto dark:border-white/12">
                <span data-lucide="plus" class="h-4 w-4"></span>
                Book another
            </a>
        </div>
    </div>
</section>
@endsection
