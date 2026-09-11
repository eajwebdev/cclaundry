@extends('layouts.public')

@section('page_title', 'Tracking ' . $pickupRequest->reference_no)

@php
    $timeline = [
        ['pending', 'Booking received'],
        ['confirmed', 'Pickup scheduled'],
        ['picked_up', 'Collected & in progress'],
        ['completed', $pickupRequest->wantsDelivery() ? 'Delivered' : 'Claimed at branch'],
    ];

    $order = array_search($pickupRequest->status, array_column($timeline, 0), true);
    $currentIndex = $pickupRequest->status === 'cancelled' ? -1 : ($order === false ? 0 : $order);
@endphp

@section('content')
<section class="py-12 sm:py-16">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">

        <a href="{{ route('landing') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-primary">
            <span data-lucide="arrow-left" class="h-3.5 w-3.5"></span>
            Back to home
        </a>

        <div class="mt-6 overflow-hidden rounded-3xl border border-border bg-white dark:border-white/10 dark:bg-[#241a13]">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border bg-cream px-7 py-6 dark:border-white/10 dark:bg-[#1c1510]">
                <div>
                    <p class="text-[11px] tracking-[0.16em] text-muted uppercase">Reference</p>
                    <p class="mt-1 font-mono text-xl font-semibold text-primary">{{ $pickupRequest->reference_no }}</p>
                </div>
                @include('partials.booking-status', ['status' => $pickupRequest->status])
            </div>

            <div class="p-7">
                @if($pickupRequest->status === 'cancelled')
                    <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 dark:border-red-500/25 dark:bg-red-500/10 dark:text-red-200">
                        This booking was cancelled{{ $pickupRequest->cancelled_at ? ' on '.$pickupRequest->cancelled_at->format('M j, Y') : '' }}.
                        @if($pickupRequest->cancellation_reason) <span class="block mt-1">{{ $pickupRequest->cancellation_reason }}</span> @endif
                    </div>
                @else
                    <ol class="space-y-1">
                        @foreach ($timeline as $index => [$key, $title])
                            @php($done = $index <= $currentIndex)
                            <li class="flex gap-4">
                                <div class="flex flex-col items-center">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2
                                        {{ $done ? 'border-primary bg-primary text-white' : 'border-border bg-white text-muted dark:bg-[#241a13]' }}">
                                        @if($done)
                                            <span data-lucide="check" class="h-4 w-4"></span>
                                        @else
                                            <span class="text-[11px] font-semibold">{{ $index + 1 }}</span>
                                        @endif
                                    </span>
                                    @if(! $loop->last)
                                        <span class="h-8 w-0.5 {{ $index < $currentIndex ? 'bg-primary' : 'bg-border' }}"></span>
                                    @endif
                                </div>
                                <p class="pt-2 text-sm font-medium {{ $done ? 'text-primary-deep dark:text-cane' : 'text-muted' }}">{{ $title }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if($pickupRequest->isTrackable())
                    @include('partials.live-tracking-map')
                @endif

                <dl class="mt-8 divide-y divide-border border-t border-border pt-2 dark:divide-white/8 dark:border-white/10">
                    @foreach ([
                        'Service' => $pickupRequest->serviceTypeLabel(),
                        'Branch' => $pickupRequest->branch?->name ?? '--',
                        'Pickup' => $pickupRequest->pickup_date->format('D, M j, Y').' · '.$pickupRequest->pickupSlotLabel(),
                        'Return' => $pickupRequest->wantsDelivery()
                            ? 'Delivered'.($pickupRequest->delivery_date ? ' on '.$pickupRequest->delivery_date->format('D, M j, Y') : ', date to be confirmed')
                            : 'Claim at branch',
                        'Job order' => $pickupRequest->jobOrder?->job_order_number ?? 'Not yet created',
                    ] as $label => $detail)
                        <div class="flex items-start justify-between gap-4 py-3.5">
                            <dt class="shrink-0 text-sm text-muted">{{ $label }}</dt>
                            <dd class="text-right text-sm font-medium">{{ $detail }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if($pickupRequest->jobOrder)
                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl bg-cream px-5 py-4 dark:bg-[#1c1510]">
                            <p class="text-[11px] tracking-wide text-muted uppercase">Order total</p>
                            <p class="mt-1 font-serif text-xl font-semibold text-primary">₱{{ number_format((float) $pickupRequest->jobOrder->total, 2) }}</p>
                        </div>
                        <div class="rounded-2xl bg-cream px-5 py-4 dark:bg-[#1c1510]">
                            <p class="text-[11px] tracking-wide text-muted uppercase">Balance</p>
                            <p class="mt-1 font-serif text-xl font-semibold {{ (float) $pickupRequest->jobOrder->balance > 0 ? 'text-red-600' : 'text-accent-deep' }}">
                                ₱{{ number_format((float) $pickupRequest->jobOrder->balance, 2) }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-muted">
            Sign in to manage this booking &mdash;
            <a href="{{ route('customer.login') }}" class="font-medium text-primary hover:underline">go to your account</a>
        </p>
    </div>
</section>
@endsection
