@extends('layouts.app')

@section('page_title', 'Pickup Bookings')

@php
    $statusLabels = [
        'pending' => 'New',
        'confirmed' => 'Confirmed',
        'picked_up' => 'Collected',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
@endphp

@section('content')
<div class="space-y-4">

    {{-- Header --}}
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm lg:flex-row lg:items-center lg:justify-between dark:border-gray-800 dark:bg-gray-900">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="truck" class="h-3.5 w-3.5"></span>
                From the website
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Pickup Bookings</h1>
            <p class="text-sm text-muted">Online pickup &amp; delivery requests waiting to be collected and turned into job orders.</p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:min-w-96">
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                <p class="text-xs font-medium text-amber-700 dark:text-amber-300">Needs action</p>
                <p class="mt-1 text-lg font-semibold">{{ number_format($statusCounts['pending']) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                <p class="text-xs font-medium text-muted">Scheduled</p>
                <p class="mt-1 text-lg font-semibold">{{ number_format($statusCounts['confirmed']) }}</p>
            </div>
            <div class="col-span-2 rounded-lg border border-border bg-smoke p-3 sm:col-span-1 dark:border-gray-800 dark:bg-gray-950">
                <p class="text-xs font-medium text-muted">Collected</p>
                <p class="mt-1 text-lg font-semibold">{{ number_format($statusCounts['picked_up']) }}</p>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <form method="GET" action="{{ route('admin.pickup-requests.index') }}" class="grid grid-cols-1 gap-2 lg:grid-cols-[1fr_12rem_12rem_auto]">
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" placeholder="Search reference, name or mobile..." class="w-full bg-transparent text-sm outline-none">
            </div>

            @if($branches->isNotEmpty())
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif

            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All status ({{ $statusCounts['all'] }})</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>
                        {{ $statusLabels[$status] ?? ucfirst($status) }} ({{ $statusCounts[$status] ?? 0 }})
                    </option>
                @endforeach
            </select>

            <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-white transition hover:opacity-90">
                <span data-lucide="search" class="h-4 w-4"></span>
                Filter
            </button>
        </form>
    </div>

    {{-- List --}}
    @if($requests->isEmpty())
        <div class="rounded-lg border border-dashed border-border bg-white px-6 py-16 text-center dark:border-gray-800 dark:bg-gray-900">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-smoke text-muted dark:bg-gray-950">
                <span data-lucide="truck" class="h-5 w-5"></span>
            </span>
            <p class="mt-4 font-medium">No bookings here</p>
            <p class="mt-1 text-sm text-muted">Online pickup requests will appear on this screen as customers book them.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($requests as $pickupRequest)
                <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">

                        {{-- Booking --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-sm font-semibold text-primary">{{ $pickupRequest->reference_no }}</span>
                                @if($pickupRequest->tag_code)
                                    {{-- The number on the bag: what matches this load to this booking. --}}
                                    <span class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary/5 px-2 py-0.5 font-mono text-[10px] font-semibold text-primary">
                                        <span data-lucide="tag" class="h-2.5 w-2.5"></span>
                                        {{ $pickupRequest->tag_code }}
                                    </span>
                                @endif
                                @include('partials.booking-status', ['status' => $pickupRequest->status])
                                @if($pickupRequest->is_rush)
                                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                        <span data-lucide="zap" class="h-2.5 w-2.5"></span> Rush
                                    </span>
                                @endif
                                <span class="inline-flex items-center gap-1 rounded-full border border-border bg-smoke px-2 py-0.5 text-[10px] font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                                    <span data-lucide="{{ $pickupRequest->wantsDelivery() ? 'truck' : 'store' }}" class="h-2.5 w-2.5"></span>
                                    {{ $pickupRequest->wantsDelivery() ? 'Deliver back' : 'Claim at branch' }}
                                </span>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Customer</p>
                                    <p class="mt-0.5 text-sm font-medium">{{ $pickupRequest->contact_name }}</p>
                                    <p class="text-sm text-muted">{{ $pickupRequest->contact_phone }}</p>
                                </div>

                                <div>
                                    <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Pickup</p>
                                    <p class="mt-0.5 text-sm font-medium">{{ $pickupRequest->pickup_date->format('D, M j') }}</p>
                                    <p class="text-sm text-muted">{{ $pickupRequest->pickupSlotLabel() }}</p>
                                </div>

                                <div>
                                    <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Service</p>
                                    @if($pickupRequest->items->isNotEmpty())
                                        {{-- Each line with the amount the customer entered: this is
                                             what the counter holds against the scale. --}}
                                        <ul class="mt-0.5 space-y-0.5">
                                            @foreach ($pickupRequest->items as $item)
                                                <li class="text-sm">
                                                    <span class="font-medium">{{ $item->service_name }}</span>
                                                    <span class="text-muted"> &middot; {{ $item->quantityLabel() }}</span>
                                                    @if($item->is_addon)
                                                        <span class="ml-1 rounded-full border border-border bg-smoke px-1.5 py-0.5 text-[9px] font-semibold tracking-wide text-muted uppercase dark:border-gray-800 dark:bg-gray-950">Add-on</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="mt-0.5 text-sm font-medium">{{ $pickupRequest->serviceTypeLabel() }}</p>
                                    @endif
                                    <p class="text-sm text-muted">
                                        {{ $pickupRequest->declaredKilos() ? rtrim(rtrim(number_format((float) $pickupRequest->declaredKilos(), 2), '0'), '.').' kg declared' : 'Weight on pickup' }}
                                        @if($pickupRequest->estimated_total)
                                            &middot; ~{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $pickupRequest->estimated_total, 2) }}
                                        @endif
                                        &middot; paying by {{ $pickupRequest->paymentMethodLabel() }}
                                    </p>
                                </div>

                                <div class="sm:col-span-2 lg:col-span-3">
                                    <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Address</p>
                                    <p class="mt-0.5 text-sm">{{ $pickupRequest->pickup_address }}</p>
                                    @if($pickupRequest->pickup_landmark)
                                        <p class="text-xs text-muted">Landmark: {{ $pickupRequest->pickup_landmark }}</p>
                                    @endif
                                    @if($pickupRequest->wantsDelivery() && $pickupRequest->delivery_address !== $pickupRequest->pickup_address)
                                        <p class="mt-1 text-xs text-muted">Deliver to: {{ $pickupRequest->delivery_address }}</p>
                                    @endif
                                </div>

                                @if($pickupRequest->notes)
                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Instructions</p>
                                        <p class="mt-0.5 rounded-md bg-smoke px-3 py-2 text-sm dark:bg-gray-950">{{ $pickupRequest->notes }}</p>
                                    </div>
                                @endif
                            </div>

                            @if($pickupRequest->collected_amount !== null || $pickupRequest->collected_payment_method)
                                {{-- Payment is taken by the rider at the door, so the
                                     counter needs to see it before pricing the job order. --}}
                                <p class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                                    <span data-lucide="wallet" class="h-3.5 w-3.5"></span>
                                    @if($pickupRequest->collected_payment_method === 'unpaid' || $pickupRequest->collected_amount === null)
                                        Rider collected nothing yet, collect at the counter
                                    @else
                                        Rider collected {{ $appSettings?->currency ?? 'PHP' }}
                                        {{ number_format((float) $pickupRequest->collected_amount, 2) }}
                                        ({{ ucfirst($pickupRequest->collected_payment_method ?? 'cash') }})
                                    @endif
                                </p>
                            @endif

                            <p class="mt-3 text-xs text-muted">
                                Booked {{ $pickupRequest->created_at->diffForHumans() }} &middot; {{ $pickupRequest->branch?->name }}
                                @if($pickupRequest->handler) &middot; handled by {{ $pickupRequest->handler->name }} @endif
                            </p>
                        </div>

                        {{-- Actions --}}
                        <div class="flex shrink-0 flex-col gap-2 xl:w-52">
                            @if($pickupRequest->jobOrder)
                                <a href="{{ route('admin.job-orders.show', $pickupRequest->jobOrder) }}"
                                   class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium transition hover:border-primary/50 hover:text-primary dark:border-gray-700">
                                    <span data-lucide="jobOrders" class="h-4 w-4"></span>
                                    {{ $pickupRequest->jobOrder->job_order_number }}
                                </a>
                            @elseif(! in_array($pickupRequest->status, ['cancelled', 'completed'], true))
                                <a href="{{ route('admin.pickup-requests.convert', $pickupRequest) }}"
                                   class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white transition hover:opacity-90">
                                    <span data-lucide="plus" class="h-4 w-4"></span>
                                    Create job order
                                </a>
                            @endif

                            {{-- Rider assignment. Choosing a rider on a still-pending
                                 booking also confirms it: someone is now going. --}}
                            @if(in_array($pickupRequest->status, ['pending', 'confirmed', 'picked_up'], true) && $riders->isNotEmpty())
                                <form method="POST" action="{{ route('admin.riders.assign', $pickupRequest) }}" class="space-y-1.5">
                                    @csrf @method('PATCH')
                                    <label class="block text-xs font-medium text-muted" for="rider-{{ $pickupRequest->id }}">Rider</label>
                                    <div class="flex gap-1.5">
                                        <select id="rider-{{ $pickupRequest->id }}" name="rider_id"
                                                class="h-9 min-w-0 flex-1 rounded-md border border-border bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-950">
                                            <option value="">Unassigned</option>
                                            @foreach($riders as $rider)
                                                <option value="{{ $rider->id }}" @selected((int) $pickupRequest->rider_id === (int) $rider->id)>{{ $rider->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" title="Save rider"
                                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border transition hover:border-primary/50 hover:text-primary dark:border-gray-700">
                                            <span data-lucide="check" class="h-4 w-4"></span>
                                        </button>
                                    </div>
                                </form>
                            @elseif($pickupRequest->rider)
                                <p class="text-xs text-muted">
                                    <span data-lucide="truck" class="inline-block h-3 w-3 align-[-2px]"></span>
                                    {{ $pickupRequest->rider->name }}
                                </p>
                            @endif

                            @if($pickupRequest->status === 'pending')
                                <form method="POST" action="{{ route('admin.pickup-requests.status', $pickupRequest) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="confirmed">
                                    <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-sky-300 bg-sky-50 px-3 text-sm font-medium text-sky-700 transition hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                                        <span data-lucide="check" class="h-4 w-4"></span>
                                        Confirm pickup
                                    </button>
                                </form>
                            @endif

                            @if(in_array($pickupRequest->status, ['picked_up'], true))
                                <form method="POST" action="{{ route('admin.pickup-requests.status', $pickupRequest) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                                        <span data-lucide="check-check" class="h-4 w-4"></span>
                                        Mark completed
                                    </button>
                                </form>
                            @endif

                            @if(! in_array($pickupRequest->status, ['cancelled', 'completed'], true))
                                <form method="POST" action="{{ route('admin.pickup-requests.status', $pickupRequest) }}"
                                      onsubmit="return confirm('Cancel booking {{ $pickupRequest->reference_no }}?');">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-muted transition hover:border-red-300 hover:text-red-600 dark:border-gray-700">
                                        <span data-lucide="x" class="h-4 w-4"></span>
                                        Cancel booking
                                    </button>
                                </form>
                            @endif

                            @if($pickupRequest->status === 'cancelled' && $pickupRequest->cancellation_reason)
                                <p class="text-xs text-muted">{{ $pickupRequest->cancellation_reason }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($requests->hasPages())
            <div>{{ $requests->links() }}</div>
        @endif
    @endif
</div>
@endsection
