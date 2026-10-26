@extends('layouts.app')

@section('page_title', 'Pickup Bookings')

@php
    $viewLabels = [
        'open' => 'Open',
        'pending' => 'New',
        'confirmed' => 'Confirmed',
        'picked_up' => 'Collected',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'all' => 'All',
    ];
    $whenLabels = [
        '' => 'Any day',
        'overdue' => 'Overdue',
        'today' => 'Today',
        'tomorrow' => 'Tomorrow',
        'week' => 'This week',
    ];
    $currency = $appSettings?->currency ?? 'PHP';
    $assignableStatuses = ['pending', 'confirmed', 'picked_up'];

    // The current filters with some changed; page always resets.
    $listUrl = fn (array $changes = []) => route('admin.pickup-requests.index', array_filter(
        array_merge(request()->except('page'), $changes),
        fn ($value) => $value !== null && $value !== ''
    ));
    $hasFilters = request()->hasAny(['search', 'branch_id', 'rider', 'when', 'date_from', 'date_to']);
@endphp

@section('content')
<div
    x-data="{
        selected: [],
        expanded: {},
        filtersOpen: @js(request()->hasAny(['branch_id', 'rider', 'date_from', 'date_to', 'per_page'])),
        pageIds: @js($requests->pluck('id')->values()),
        get allSelected() { return this.pageIds.length > 0 && this.pageIds.every(id => this.selected.includes(id)); },
        toggleAll() { this.selected = this.allSelected ? [] : [...this.pageIds]; },
        toggle(id) { this.expanded[id] = !this.expanded[id]; },
    }"
    class="space-y-4"
    :class="selected.length > 0 && 'pb-28 sm:pb-20'"
>

    {{-- Header --}}
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm lg:flex-row lg:items-center lg:justify-between dark:border-gray-800 dark:bg-gray-900">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="truck" class="h-3.5 w-3.5"></span>
                Website &amp; in-shop bookings
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Pickup Bookings</h1>
            <p class="text-sm text-muted">Pickup &amp; delivery requests waiting to be collected and turned into job orders.</p>
        </div>

        @if(auth()->user()->hasMenuAccess('job_orders'))
            <a href="{{ route('admin.job-orders.pickup-bookings.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-primary-deep px-4 py-2 text-sm font-semibold text-white">Book a pickup</a>
        @endif

        @if($overdueCount > 0)
            <a href="{{ route('admin.pickup-requests.index', array_filter(['when' => 'overdue', 'view' => 'open', 'branch_id' => request('branch_id')])) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 transition hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                <span data-lucide="alert-triangle" class="h-4 w-4"></span>
                {{ $overdueCount }} pickup{{ $overdueCount === 1 ? '' : 's' }} overdue
                <span class="text-xs underline">Show</span>
            </a>
        @endif
    </div>

    {{-- Status tabs --}}
    <div class="-mx-1 overflow-x-auto px-1">
        <div class="inline-flex min-w-max items-center gap-1 rounded-lg border border-border bg-smoke p-1 dark:border-gray-800 dark:bg-gray-950">
            @foreach($viewLabels as $key => $label)
                <a href="{{ $listUrl(['view' => $key]) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition',
                       'bg-white font-semibold shadow-sm dark:bg-gray-900' => $view === $key,
                       'text-muted hover:text-gray-900 dark:hover:text-white' => $view !== $key,
                   ])>
                    {{ $label }}
                    <span @class([
                        'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => $key === 'pending' && $viewCounts[$key] > 0,
                        'bg-border/60 text-muted dark:bg-gray-800' => ! ($key === 'pending' && $viewCounts[$key] > 0),
                    ])>{{ number_format($viewCounts[$key]) }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- Filters --}}
    <div class="space-y-3 rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="mr-1 text-xs font-medium text-muted">Pickup date</span>
            @foreach($whenLabels as $key => $label)
                @php $active = ($when ?? '') === $key && ($key !== '' || (! $dateFrom && ! $dateTo)); @endphp
                <a href="{{ $listUrl(['when' => $key ?: null, 'date_from' => null, 'date_to' => null]) }}"
                   @class([
                       'rounded-full border px-2.5 py-1 text-xs font-medium transition',
                       'border-primary bg-primary text-white' => $active,
                       'border-border text-muted hover:border-primary/50 hover:text-primary dark:border-gray-700' => ! $active,
                   ])>{{ $label }}</a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.pickup-requests.index') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto_auto]">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="hidden" name="when" value="{{ $when }}" x-ref="when">

            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 sm:col-span-2 lg:col-span-1 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" placeholder="Reference, bag tag, name, mobile or address..." class="w-full bg-transparent text-sm outline-none">
            </div>

            <button type="button" @click="filtersOpen = !filtersOpen" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-muted sm:col-span-2 lg:hidden dark:border-gray-700">
                <span x-text="filtersOpen ? 'Fewer filters' : 'More filters'">More filters</span>
                <span data-lucide="chevron-down" class="h-4 w-4 transition" :class="filtersOpen && 'rotate-180'"></span>
            </button>

            <div class="contents" :class="filtersOpen ? 'contents' : 'max-lg:hidden'">
            @if($branches->isNotEmpty())
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif

            <select name="rider" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">Any rider</option>
                <option value="unassigned" @selected(request('rider') === 'unassigned')>No rider yet</option>
                @foreach($riders as $rider)
                    <option value="{{ $rider->id }}" @selected(request('rider') == $rider->id)>{{ $rider->name }}</option>
                @endforeach
            </select>

            <div class="flex h-9 items-center gap-1 rounded-md border border-border bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                <input type="date" name="date_from" value="{{ $dateFrom }}" @change="$refs.when.value = ''" aria-label="Pickup from" class="w-full min-w-0 bg-transparent text-sm outline-none">
                <span class="text-muted">–</span>
                <input type="date" name="date_to" value="{{ $dateTo }}" @change="$refs.when.value = ''" aria-label="Pickup to" class="w-full min-w-0 bg-transparent text-sm outline-none">
            </div>

            <select name="per_page" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950" aria-label="Rows per page">
                @foreach($perPageOptions as $option)
                    <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }} per page</option>
                @endforeach
            </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-white transition hover:opacity-90">
                    <span data-lucide="search" class="h-4 w-4"></span>
                    Filter
                </button>
                @if($hasFilters)
                    <a href="{{ route('admin.pickup-requests.index', ['view' => $view]) }}" title="Clear filters"
                       class="inline-flex h-9 items-center justify-center rounded-md border border-border px-3 text-sm text-muted transition hover:text-primary dark:border-gray-700">
                        Clear
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- List --}}
    @if($requests->isEmpty())
        <div class="rounded-lg border border-dashed border-border bg-white px-6 py-16 text-center dark:border-gray-800 dark:bg-gray-900">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-smoke text-muted dark:bg-gray-950">
                <span data-lucide="truck" class="h-5 w-5"></span>
            </span>
            <p class="mt-4 font-medium">No bookings here</p>
            <p class="mt-1 text-sm text-muted">
                @if($hasFilters || $view !== 'open')
                    Nothing matches these filters. Try another tab or <a href="{{ route('admin.pickup-requests.index') }}" class="text-primary underline">clear the filters</a>.
                @else
                    Online pickup requests will appear on this screen as customers book them.
                @endif
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            {{-- Column heads (wide screens) --}}
            <div class="hidden grid-cols-[2rem_minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)_11rem_12rem] items-center gap-3 border-b border-border bg-smoke px-4 py-2.5 text-xs font-medium tracking-wide text-muted uppercase lg:grid dark:border-gray-800 dark:bg-gray-950">
                <input type="checkbox" :checked="allSelected" @change="toggleAll()" aria-label="Select all on this page" class="h-4 w-4 rounded border-border accent-primary">
                <span>Booking</span>
                <span>Customer</span>
                <span>Pickup</span>
                <span>Rider</span>
                <span class="text-right">Action</span>
            </div>

            <div class="divide-y divide-border dark:divide-gray-800">
                @foreach($requests as $pickupRequest)
                    @php
                        $isClosed = in_array($pickupRequest->status, ['cancelled', 'completed'], true);
                        $overdueDays = ! $isClosed && in_array($pickupRequest->status, ['pending', 'confirmed'], true) && $pickupRequest->pickup_date->lt(today())
                            ? (int) $pickupRequest->pickup_date->diffInDays(today())
                            : 0;
                        $summary = $pickupRequest->items->isNotEmpty() ? $pickupRequest->serviceSummary() : $pickupRequest->serviceTypeLabel();
                    @endphp
                    <div :class="selected.includes({{ $pickupRequest->id }}) && 'bg-primary/5'">
                        <div class="grid grid-cols-[2rem_minmax(0,1fr)] items-start gap-x-3 gap-y-2 px-4 py-3 lg:grid-cols-[2rem_minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)_11rem_12rem] lg:items-center">
                            <input type="checkbox" value="{{ $pickupRequest->id }}" x-model.number="selected" aria-label="Select {{ $pickupRequest->reference_no }}" class="mt-1 h-4 w-4 rounded border-border accent-primary lg:mt-0">

                            {{-- Booking --}}
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <button type="button" @click="toggle({{ $pickupRequest->id }})" class="font-mono text-sm font-semibold text-primary hover:underline">{{ $pickupRequest->reference_no }}</button>
                                    @include('partials.booking-status', ['status' => $pickupRequest->status])
                                    @if($pickupRequest->tag_code)
                                        <span class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary/5 px-2 py-0.5 font-mono text-[10px] font-semibold text-primary">
                                            <span data-lucide="tag" class="h-2.5 w-2.5"></span>{{ $pickupRequest->tag_code }}
                                        </span>
                                    @endif
                                    @if($pickupRequest->is_rush)
                                        <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                            <span data-lucide="zap" class="h-2.5 w-2.5"></span> Rush
                                        </span>
                                    @endif
                                    <span title="{{ $pickupRequest->wantsDelivery() ? 'Deliver back' : 'Claim at branch' }}" class="inline-flex text-muted">
                                        <span data-lucide="{{ $pickupRequest->wantsDelivery() ? 'truck' : 'store' }}" class="h-3.5 w-3.5"></span>
                                    </span>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-muted" title="{{ $summary }}">{{ $summary }}</p>
                            </div>

                            {{-- Customer --}}
                            <div class="col-start-2 min-w-0 lg:col-start-auto">
                                <p class="truncate text-sm font-medium">{{ $pickupRequest->contact_name }}</p>
                                <a href="tel:{{ $pickupRequest->contact_phone }}" class="text-xs text-muted hover:text-primary">{{ $pickupRequest->contact_phone }}</a>
                            </div>

                            {{-- Pickup --}}
                            <div class="col-start-2 min-w-0 lg:col-start-auto">
                                <p class="text-sm font-medium">
                                    {{ $pickupRequest->pickup_date->isToday() ? 'Today' : ($pickupRequest->pickup_date->isTomorrow() ? 'Tomorrow' : $pickupRequest->pickup_date->format('D, M j')) }}
                                    @if($overdueDays > 0)
                                        <span class="ml-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300">{{ $overdueDays }}d late</span>
                                    @endif
                                </p>
                                <p class="text-xs text-muted">{{ $pickupRequest->pickupSlotLabel() }}@if($branches->isNotEmpty()) &middot; {{ $pickupRequest->branch?->name }}@endif</p>
                            </div>

                            {{-- Rider --}}
                            <div class="col-start-2 min-w-0 lg:col-start-auto">
                                @if(in_array($pickupRequest->status, $assignableStatuses, true) && $riders->isNotEmpty())
                                    <form method="POST" action="{{ route('admin.riders.assign', $pickupRequest) }}">
                                        @csrf @method('PATCH')
                                        <select name="rider_id" onchange="this.form.submit()" aria-label="Rider for {{ $pickupRequest->reference_no }}"
                                                class="h-8 w-full rounded-md border border-border bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-950 {{ $pickupRequest->rider_id ? '' : 'text-muted' }}">
                                            <option value="">No rider yet</option>
                                            @foreach($riders as $rider)
                                                <option value="{{ $rider->id }}" @selected((int) $pickupRequest->rider_id === (int) $rider->id)>{{ $rider->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    <p class="truncate text-sm {{ $pickupRequest->rider ? '' : 'text-muted' }}">{{ $pickupRequest->rider?->name ?? '—' }}</p>
                                @endif
                            </div>

                            {{-- Primary action --}}
                            <div class="col-start-2 flex items-center gap-1.5 lg:col-start-auto lg:justify-end">
                                @if($pickupRequest->jobOrder)
                                    <a href="{{ route('admin.job-orders.show', $pickupRequest->jobOrder) }}"
                                       class="inline-flex h-8 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium transition hover:border-primary/50 hover:text-primary lg:flex-none dark:border-gray-700">
                                        <span data-lucide="jobOrders" class="h-3.5 w-3.5"></span>
                                        <span class="truncate">{{ $pickupRequest->jobOrder->job_order_number }}</span>
                                    </a>
                                @elseif($pickupRequest->status === 'pending')
                                    <form method="POST" action="{{ route('admin.pickup-requests.status', $pickupRequest) }}" class="flex-1 lg:flex-none">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="confirmed">
                                        <button type="submit" class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-md border border-sky-300 bg-sky-50 px-2.5 text-xs font-medium text-sky-700 transition hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                                            <span data-lucide="check" class="h-3.5 w-3.5"></span>
                                            Confirm
                                        </button>
                                    </form>
                                @elseif(! $isClosed)
                                    <a href="{{ route('admin.pickup-requests.convert', $pickupRequest) }}"
                                       class="inline-flex h-8 flex-1 items-center justify-center gap-1.5 rounded-md bg-primary px-2.5 text-xs font-medium text-white transition hover:opacity-90 lg:flex-none">
                                        <span data-lucide="plus" class="h-3.5 w-3.5"></span>
                                        Job order
                                    </a>
                                @endif
                                <button type="button" @click="toggle({{ $pickupRequest->id }})" :aria-expanded="!!expanded[{{ $pickupRequest->id }}]"
                                        class="inline-flex h-8 items-center justify-center gap-1 rounded-md border border-border px-2.5 text-xs font-medium text-muted transition hover:text-primary dark:border-gray-700">
                                    Details
                                    <span data-lucide="chevron-down" class="h-3.5 w-3.5 transition" :class="expanded[{{ $pickupRequest->id }}] && 'rotate-180'"></span>
                                </button>
                            </div>
                        </div>

                        {{-- Details --}}
                        <div x-cloak x-show="expanded[{{ $pickupRequest->id }}]" x-transition.opacity class="border-t border-dashed border-border bg-smoke/60 px-4 py-4 lg:pl-15 dark:border-gray-800 dark:bg-gray-950/50">
                            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_14rem]">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Service</p>
                                        @if($pickupRequest->items->isNotEmpty())
                                            <ul class="mt-0.5 space-y-0.5">
                                                @foreach ($pickupRequest->items as $item)
                                                    <li class="text-sm">
                                                        <span class="font-medium">{{ $item->service_name }}</span>
                                                        <span class="text-muted"> &middot; {{ $item->quantityLabel() }}</span>
                                                        @if($item->is_addon)
                                                            <span class="ml-1 rounded-full border border-border bg-white px-1.5 py-0.5 text-[9px] font-semibold tracking-wide text-muted uppercase dark:border-gray-800 dark:bg-gray-900">Add-on</span>
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
                                                &middot; ~{{ $currency }} {{ number_format((float) $pickupRequest->estimated_total, 2) }}
                                            @endif
                                            &middot; paying by {{ $pickupRequest->paymentMethodLabel() }}
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Address</p>
                                        <p class="mt-0.5 text-sm">{{ $pickupRequest->pickup_address }}</p>
                                        @if($pickupRequest->pickup_landmark)
                                            <p class="text-xs text-muted">Landmark: {{ $pickupRequest->pickup_landmark }}</p>
                                        @endif
                                        <p class="mt-1 text-xs text-muted">{{ $pickupRequest->wantsDelivery() ? 'Deliver back' : 'Claim at branch' }}@if($pickupRequest->wantsDelivery() && filled($pickupRequest->delivery_address) && $pickupRequest->delivery_address !== $pickupRequest->pickup_address): {{ $pickupRequest->delivery_address }}@endif</p>
                                    </div>

                                    @if($pickupRequest->notes)
                                        <div class="sm:col-span-2">
                                            <p class="text-[11px] font-medium tracking-wide text-muted uppercase">Instructions</p>
                                            <p class="mt-0.5 rounded-md bg-white px-3 py-2 text-sm dark:bg-gray-900">{{ $pickupRequest->notes }}</p>
                                        </div>
                                    @endif

                                    @if($pickupRequest->collected_amount !== null || $pickupRequest->collected_payment_method)
                                        {{-- Payment is taken by the rider at the door, so the
                                             counter needs to see it before pricing the job order. --}}
                                        <p class="inline-flex flex-wrap items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-800 sm:col-span-2 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                                            <span data-lucide="wallet" class="h-3.5 w-3.5"></span>
                                            @if($pickupRequest->collected_payment_method === 'unpaid' || $pickupRequest->collected_amount === null)
                                                Rider collected nothing yet, collect at the counter
                                            @else
                                                Rider collected {{ $currency }}
                                                {{ number_format((float) $pickupRequest->collected_amount, 2) }}
                                                ({{ ucfirst($pickupRequest->collected_payment_method ?? 'cash') }})
                                            @endif
                                        </p>
                                    @endif

                                    <p class="text-xs text-muted sm:col-span-2">
                                        Booked {{ $pickupRequest->created_at->diffForHumans() }} &middot; {{ $pickupRequest->branch?->name }}
                                        @if($pickupRequest->handler) &middot; handled by {{ $pickupRequest->handler->name }} @endif
                                        @if($pickupRequest->status === 'cancelled' && $pickupRequest->cancellation_reason) &middot; {{ $pickupRequest->cancellation_reason }} @endif
                                    </p>
                                </div>

                                <div class="flex flex-col gap-2">
                                    @if(! $pickupRequest->jobOrder && ! $isClosed && $pickupRequest->status === 'pending')
                                        <a href="{{ route('admin.pickup-requests.convert', $pickupRequest) }}"
                                           class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white transition hover:opacity-90">
                                            <span data-lucide="plus" class="h-4 w-4"></span>
                                            Create job order
                                        </a>
                                    @endif

                                    @if($pickupRequest->status === 'picked_up')
                                        <form method="POST" action="{{ route('admin.pickup-requests.status', $pickupRequest) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                                                <span data-lucide="check-check" class="h-4 w-4"></span>
                                                Mark completed
                                            </button>
                                        </form>
                                    @endif

                                    @if(! $isClosed)
                                        <form method="POST" action="{{ route('admin.pickup-requests.status', $pickupRequest) }}"
                                              onsubmit="return confirm('Cancel booking {{ $pickupRequest->reference_no }}?');">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="cancelled">
                                            <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-border bg-white px-3 text-sm font-medium text-muted transition hover:border-red-300 hover:text-red-600 dark:border-gray-700 dark:bg-gray-900">
                                                <span data-lucide="x" class="h-4 w-4"></span>
                                                Cancel booking
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col gap-2 border-t border-border px-4 py-3 text-sm text-muted sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                <label class="inline-flex items-center gap-2 lg:hidden">
                    <input type="checkbox" :checked="allSelected" @change="toggleAll()" class="h-4 w-4 rounded border-border accent-primary">
                    Select all on this page
                </label>
                <span>Showing {{ number_format($requests->firstItem()) }}–{{ number_format($requests->lastItem()) }} of {{ number_format($requests->total()) }}</span>
                @if($requests->hasPages())
                    <div>{{ $requests->links() }}</div>
                @endif
            </div>
        </div>
    @endif

    {{-- Bulk actions --}}
    <div x-cloak x-show="selected.length > 0" x-transition
         class="fixed bottom-4 left-4 right-20 z-40 rounded-lg border border-primary/30 bg-white/95 px-4 py-3 shadow-lg backdrop-blur transition-[left] dark:border-gray-700 dark:bg-gray-900/95"
         :class="$data.desktopSidebarOpen ? 'xl:left-72' : 'xl:left-8'">
        <form method="POST" action="{{ route('admin.pickup-requests.bulk') }}" class="flex flex-wrap items-center gap-2"
              @submit="if ($event.submitter?.value === 'cancel' && !confirm(`Cancel ${selected.length} booking(s)?`)) $event.preventDefault()">
            @csrf
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>

            <span class="mr-1 text-sm font-semibold" x-text="`${selected.length} selected`"></span>

            <button type="submit" name="action" value="confirm" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-sky-300 bg-sky-50 px-2.5 text-xs font-medium text-sky-700 hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                <span data-lucide="check" class="h-3.5 w-3.5"></span> Confirm
            </button>

            @if($riders->isNotEmpty())
                <div class="inline-flex items-center gap-1">
                    <select name="rider_id" aria-label="Rider for selected bookings" class="h-8 rounded-md border border-border bg-white px-2 text-xs dark:border-gray-700 dark:bg-gray-950">
                        <option value="">Choose rider…</option>
                        @foreach($riders as $rider)
                            <option value="{{ $rider->id }}">{{ $rider->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" name="action" value="assign" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium hover:border-primary/50 hover:text-primary dark:border-gray-700">
                        <span data-lucide="truck" class="h-3.5 w-3.5"></span> Assign
                    </button>
                </div>
            @endif

            <button type="submit" name="action" value="complete" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-emerald-300 bg-emerald-50 px-2.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <span data-lucide="check-check" class="h-3.5 w-3.5"></span> Mark completed
            </button>

            <button type="submit" name="action" value="cancel" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium text-muted hover:border-red-300 hover:text-red-600 dark:border-gray-700">
                <span data-lucide="x" class="h-3.5 w-3.5"></span> Cancel
            </button>

            <button type="button" @click="selected = []" class="ml-auto text-xs text-muted underline hover:text-primary">Clear selection</button>
        </form>
    </div>
</div>
@endsection
