@extends('layouts.public')

@section('page_title', 'Tracking ' . $pickupRequest->reference_no)
@section('back_url', route('landing') . '#track')

@php
    $kilos = $pickupRequest->estimated_kilos
        ? rtrim(rtrim(number_format((float) $pickupRequest->estimated_kilos, 2), '0'), '.').' kg'
        : 'Weight to be confirmed';

    $details = [
        ['store', 'Branch', $pickupRequest->branch?->name ?? '--'],
        ...($pickupRequest->tag_code
            ? [['tag', 'Laundry tag', $pickupRequest->tag_code.' — the number on your bag']]
            : []),
        ['calendar-days', 'Pickup', $pickupRequest->pickup_date->format('D, M j, Y').' · '.$pickupRequest->pickupSlotLabel()],
        ['truck', 'Return', $pickupRequest->wantsDelivery()
            ? 'Free delivery'.($pickupRequest->delivery_date ? ' on '.$pickupRequest->delivery_date->format('D, M j, Y') : ', date to be confirmed')
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
                @include('partials.booking-status', ['status' => $pickupRequest->status])
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
                <a href="{{ route('customer.login') }}" class="font-bold text-cc-brown hover:underline">Sign in to your account</a>
            </p>
        </div>
    </div>
</section>
@endsection
