@extends('layouts.app')

@section('page_title', 'Job Orders')

@section('content')
@php($dateRangeValue = request('date_range') ?: ($dateFrom && $dateTo ? $dateFrom.' to '.$dateTo : ''))
<style>
    @page {
        size: 58mm 3276mm;
        margin: 0;
    }
    @media print {
        body * { visibility: hidden !important; }
        .receipt-print-area, .receipt-print-area * { visibility: visible !important; }
        div[x-show*="receiptOpen"],
        div[x-show*="receiptOpen"] > div {
            position: static !important;
            transform: none !important;
            filter: none !important;
            padding: 0 !important;
            margin: 0 !important;
            width: 58mm !important;
            max-width: 58mm !important;
            min-width: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            border: 0 !important;
            overflow: visible !important;
            display: block !important;
        }
        .receipt-print-area {
            position: fixed !important;
            left: 0 !important;
            right: 0 !important;
            top: 0 !important;
            margin: 0 auto !important;
            max-width: 58mm !important;
            width: 58mm !important;
            display: flex !important;
            justify-content: center !important;
            align-items: flex-start !important;
            padding: 0 !important;
            background: #ffffff !important;
            z-index: 999999 !important;
            overflow: visible !important;
        }
        .receipt-print-actions { display: none !important; }
    }
</style>
<div
    x-data="{
        statusOpen: null,
        cancelOpen: null,
        paymentOpen: null,
        payOpen: null,
        receiptOpen: null,
        dateRange: @js($dateRangeValue),
        init() {
            this.$nextTick(() => {
                if (!window.flatpickr) return;
                window.flatpickr(this.$refs.dateRange, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                    onClose: (dates, value) => this.dateRange = value,
                });
            });
        },
    }"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="jobOrders" class="h-3.5 w-3.5"></span>
                {{ in_array(auth()->user()->role, ['branch_manager', 'cashier'], true) ? 'Cashier POS' : 'Laundry operations' }}
            </div>
            <h1 class="text-xl font-semibold">Job Orders</h1>
            <p class="text-sm text-muted">Create, filter, review, and edit laundry transactions.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.job-orders.create') }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                <span data-lucide="plus" class="h-4 w-4"></span>
                New POS
            </a>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <form method="GET" class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_12rem_16rem_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search JO or customer..." class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All status</option>
                <option value="active" @selected(request('status') === 'active')>In Process</option>
                <option value="released" @selected(request('status') === 'released')>Released to Customer</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                @endforeach
            </select>
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" placeholder="Date range" autocomplete="off" class="w-full bg-transparent text-sm outline-none">
            </div>
            <button class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800"><span data-lucide="search" class="h-4 w-4"></span></button>
        </form>
    </div>

    @php($totalOrdersCount = (int) ($statusCounts['total'] ?? 0))
    @php($totalBase = max($totalOrdersCount, 1))
    @php($pickupCount = (int) ($statusCounts['ready_for_pickup'] ?? 0))
    @php($deliveryCount = (int) ($statusCounts['ready_for_delivery'] ?? 0))
    @php($releasedCount = (int) ($statusCounts['released'] ?? 0))
    @php($activeCount = (int) ($statusCounts['active'] ?? 0))
    @php($pickupPct = $totalOrdersCount > 0 ? min(100, max(8, round(($pickupCount / $totalBase) * 100))) : 0)
    @php($deliveryPct = $totalOrdersCount > 0 ? min(100, max(8, round(($deliveryCount / $totalBase) * 100))) : 0)
    @php($releasedPct = $totalOrdersCount > 0 ? min(100, max(8, round(($releasedCount / $totalBase) * 100))) : 0)
    @php($activePct = $totalOrdersCount > 0 ? min(100, max(8, round(($activeCount / $totalBase) * 100))) : 0)
    @php($totalPct = 100)
    @php($activeStatus = $currentStatus ?? request('status'))

    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 md:grid-cols-6 lg:grid-cols-5 sm:gap-3 lg:gap-3.5">
        {{-- Card 1: Ready for Pickup --}}
        <a href="{{ route('admin.job-orders.index', array_merge(request()->except('page'), ['status' => 'ready_for_pickup'])) }}"
           class="group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white p-3.5 sm:p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-teal-300 dark:bg-gray-900 dark:hover:border-teal-700/60 {{ $activeStatus === 'ready_for_pickup' ? 'border-teal-500 ring-2 ring-teal-500/20 bg-teal-50/25 dark:border-teal-500 dark:bg-teal-950/20' : 'border-border dark:border-gray-800' }} col-span-1 sm:col-span-1 md:col-span-2 lg:col-span-1 touch-manipulation">
            <div>
                <div class="flex items-center justify-between gap-1.5">
                    <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted sm:text-[11px]">Ready for Pickup</span>
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400 sm:h-8 sm:w-8 sm:rounded-xl">
                        <span data-lucide="packageCheck" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                    </div>
                </div>
                <div class="mt-2.5 flex items-center justify-between gap-2 sm:mt-3">
                    <div>
                        <p class="text-2xl font-extrabold tracking-tight text-dark dark:text-white sm:text-3xl leading-none">
                            {{ number_format($pickupCount) }}
                        </p>
                        <p class="mt-1 text-[9px] font-bold uppercase tracking-wider text-teal-600 dark:text-teal-400 sm:text-[10px]">
                            Store Pickup
                        </p>
                    </div>
                    <div class="flex h-7 w-14 shrink-0 items-center justify-end sm:h-8 sm:w-16">
                        <svg class="h-full w-full" viewBox="0 0 72 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="grad-pickup" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#0d9488" stop-opacity="0.25"/>
                                    <stop offset="100%" stop-color="#0d9488" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            <path d="M2 22 C 18 20, 36 14, 66 6 L 66 26 L 2 26 Z" fill="url(#grad-pickup)"/>
                            <path d="M2 22 C 18 20, 36 14, 66 6" stroke="#0d9488" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="66" cy="6" r="2.5" fill="#0d9488"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="mt-3.5 space-y-1.5 pt-1.5 border-t border-border/60 dark:border-gray-800/80">
                <div class="flex items-center justify-between text-[10px] sm:text-[11px]">
                    <span class="text-muted">Awaiting Claim:</span>
                    <span class="font-bold text-teal-600 dark:text-teal-400">{{ $pickupCount }} orders</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-teal-100/70 dark:bg-teal-950/60">
                    <div class="h-full rounded-full bg-teal-500 transition-all duration-300" style="width: {{ $pickupPct }}%;"></div>
                </div>
            </div>
        </a>

        {{-- Card 2: Ready for Delivery --}}
        <a href="{{ route('admin.job-orders.index', array_merge(request()->except('page'), ['status' => 'ready_for_delivery'])) }}"
           class="group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white p-3.5 sm:p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-orange-300 dark:bg-gray-900 dark:hover:border-orange-700/60 {{ $activeStatus === 'ready_for_delivery' ? 'border-orange-500 ring-2 ring-orange-500/20 bg-orange-50/25 dark:border-orange-500 dark:bg-orange-950/20' : 'border-border dark:border-gray-800' }} col-span-1 sm:col-span-1 md:col-span-2 lg:col-span-1 touch-manipulation">
            <div>
                <div class="flex items-center justify-between gap-1.5">
                    <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted sm:text-[11px]">Ready for Delivery</span>
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-950/50 dark:text-orange-400 sm:h-8 sm:w-8 sm:rounded-xl">
                        <span data-lucide="truck" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                    </div>
                </div>
                <div class="mt-2.5 flex items-center justify-between gap-2 sm:mt-3">
                    <div>
                        <p class="text-2xl font-extrabold tracking-tight text-dark dark:text-white sm:text-3xl leading-none">
                            {{ number_format($deliveryCount) }}
                        </p>
                        <p class="mt-1 text-[9px] font-bold uppercase tracking-wider text-orange-600 dark:text-orange-400 sm:text-[10px]">
                            For Dispatch
                        </p>
                    </div>
                    <div class="flex h-7 w-14 shrink-0 items-center justify-end sm:h-8 sm:w-16">
                        <svg class="h-full w-full" viewBox="0 0 72 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="grad-delivery" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#f97316" stop-opacity="0.25"/>
                                    <stop offset="100%" stop-color="#f97316" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            <path d="M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12 L 66 26 L 2 26 Z" fill="url(#grad-delivery)"/>
                            <path d="M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12" stroke="#f97316" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="66" cy="12" r="2.5" fill="#f97316"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="mt-3.5 space-y-1.5 pt-1.5 border-t border-border/60 dark:border-gray-800/80">
                <div class="flex items-center justify-between text-[10px] sm:text-[11px]">
                    <span class="text-muted">Dispatch Queue:</span>
                    <span class="font-bold text-orange-600 dark:text-orange-400">{{ $deliveryCount }} packages</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-orange-100/70 dark:bg-orange-950/60">
                    <div class="h-full rounded-full bg-orange-500 transition-all duration-300" style="width: {{ $deliveryPct }}%;"></div>
                </div>
            </div>
        </a>

        {{-- Card 3: In Process --}}
        <a href="{{ route('admin.job-orders.index', array_merge(request()->except('page'), ['status' => 'active'])) }}"
           class="group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white p-3.5 sm:p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-blue-300 dark:bg-gray-900 dark:hover:border-blue-700/60 {{ $activeStatus === 'active' ? 'border-blue-500 ring-2 ring-blue-500/20 bg-blue-50/25 dark:border-blue-500 dark:bg-blue-950/20' : 'border-border dark:border-gray-800' }} col-span-1 sm:col-span-1 md:col-span-2 lg:col-span-1 touch-manipulation">
            <div>
                <div class="flex items-center justify-between gap-1.5">
                    <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted sm:text-[11px]">In Process</span>
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400 sm:h-8 sm:w-8 sm:rounded-xl">
                        <span data-lucide="laundry" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                    </div>
                </div>
                <div class="mt-2.5 flex items-center justify-between gap-2 sm:mt-3">
                    <div>
                        <p class="text-2xl font-extrabold tracking-tight text-dark dark:text-white sm:text-3xl leading-none">
                            {{ number_format($activeCount) }}
                        </p>
                        <p class="mt-1 text-[9px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400 sm:text-[10px]">
                            In Cycles
                        </p>
                    </div>
                    <div class="flex h-7 w-14 shrink-0 items-center justify-end sm:h-8 sm:w-16">
                        <svg class="h-full w-full" viewBox="0 0 72 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="grad-active" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#2563eb" stop-opacity="0.25"/>
                                    <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            <path d="M2 16 C 16 16, 22 22, 36 16 C 50 10, 58 12, 66 8 L 66 26 L 2 26 Z" fill="url(#grad-active)"/>
                            <path d="M2 16 C 16 16, 22 22, 36 16 C 50 10, 58 12, 66 8" stroke="#2563eb" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="66" cy="8" r="2.5" fill="#2563eb"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="mt-3.5 space-y-1.5 pt-1.5 border-t border-border/60 dark:border-gray-800/80">
                <div class="flex items-center justify-between text-[10px] sm:text-[11px]">
                    <span class="text-muted">Active Workload:</span>
                    <span class="font-bold text-blue-600 dark:text-blue-400">{{ $activeCount }} jobs</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-blue-100/70 dark:bg-blue-950/60">
                    <div class="h-full rounded-full bg-blue-500 transition-all duration-300" style="width: {{ $activePct }}%;"></div>
                </div>
            </div>
        </a>

        {{-- Card 4: Released to Customer --}}
        <a href="{{ route('admin.job-orders.index', array_merge(request()->except('page'), ['status' => 'released'])) }}"
           class="group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white p-3.5 sm:p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-emerald-300 dark:bg-gray-900 dark:hover:border-emerald-700/60 {{ $activeStatus === 'released' ? 'border-emerald-500 ring-2 ring-emerald-500/20 bg-emerald-50/25 dark:border-emerald-500 dark:bg-emerald-950/20' : 'border-border dark:border-gray-800' }} col-span-1 sm:col-span-1 md:col-span-3 lg:col-span-1 touch-manipulation">
            <div>
                <div class="flex items-center justify-between gap-1.5">
                    <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted sm:text-[11px]">Released to Customer</span>
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400 sm:h-8 sm:w-8 sm:rounded-xl">
                        <span data-lucide="checkCheck" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                    </div>
                </div>
                <div class="mt-2.5 flex items-center justify-between gap-2 sm:mt-3">
                    <div>
                        <p class="text-2xl font-extrabold tracking-tight text-dark dark:text-white sm:text-3xl leading-none">
                            {{ number_format($releasedCount) }}
                        </p>
                        <p class="mt-1 text-[9px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-[10px]">
                            Completed
                        </p>
                    </div>
                    <div class="flex h-7 w-14 shrink-0 items-center justify-end sm:h-8 sm:w-16">
                        <svg class="h-full w-full" viewBox="0 0 72 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="grad-released" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#059669" stop-opacity="0.25"/>
                                    <stop offset="100%" stop-color="#059669" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            <path d="M2 24 C 20 22, 38 16, 66 6 L 66 26 L 2 26 Z" fill="url(#grad-released)"/>
                            <path d="M2 24 C 20 22, 38 16, 66 6" stroke="#059669" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="66" cy="6" r="2.5" fill="#059669"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="mt-3.5 space-y-1.5 pt-1.5 border-t border-border/60 dark:border-gray-800/80">
                <div class="flex items-center justify-between text-[10px] sm:text-[11px]">
                    <span class="text-muted">Total Claimed:</span>
                    <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $releasedCount }} orders</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-emerald-100/70 dark:bg-emerald-950/60">
                    <div class="h-full rounded-full bg-emerald-500 transition-all duration-300" style="width: {{ $releasedPct }}%;"></div>
                </div>
            </div>
        </a>

        {{-- Card 5: Total in Date Range --}}
        <a href="{{ route('admin.job-orders.index', request()->except('page', 'status')) }}"
           class="group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white p-3.5 sm:p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-indigo-300 dark:bg-gray-900 dark:hover:border-indigo-700/60 {{ empty($activeStatus) ? 'border-indigo-500 ring-2 ring-indigo-500/20 bg-indigo-50/25 dark:border-indigo-500 dark:bg-indigo-950/20' : 'border-border dark:border-gray-800' }} col-span-1 sm:col-span-2 md:col-span-3 lg:col-span-1 touch-manipulation">
            <div>
                <div class="flex items-center justify-between gap-1.5">
                    <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted sm:text-[11px]">Total in Date Range</span>
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400 sm:h-8 sm:w-8 sm:rounded-xl">
                        <span data-lucide="jobOrders" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                    </div>
                </div>
                <div class="mt-2.5 flex items-center justify-between gap-2 sm:mt-3">
                    <div>
                        <p class="text-2xl font-extrabold tracking-tight text-dark dark:text-white sm:text-3xl leading-none">
                            {{ number_format($totalOrdersCount) }}
                        </p>
                        <p class="mt-1 text-[9px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 sm:text-[10px]">
                            All Orders
                        </p>
                    </div>
                    <div class="flex h-7 w-14 shrink-0 items-center justify-end sm:h-8 sm:w-16">
                        <svg class="h-full w-full" viewBox="0 0 72 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="grad-total" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#4f46e5" stop-opacity="0.25"/>
                                    <stop offset="100%" stop-color="#4f46e5" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            <path d="M2 18 C 14 12, 26 22, 40 14 C 52 8, 58 10, 66 6 L 66 26 L 2 26 Z" fill="url(#grad-total)"/>
                            <path d="M2 18 C 14 12, 26 22, 40 14 C 52 8, 58 10, 66 6" stroke="#4f46e5" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="66" cy="6" r="2.5" fill="#4f46e5"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="mt-3.5 space-y-1.5 pt-1.5 border-t border-border/60 dark:border-gray-800/80">
                <div class="flex items-center justify-between text-[10px] sm:text-[11px]">
                    <span class="text-muted">Counter Status:</span>
                    <span class="font-bold text-indigo-600 dark:text-indigo-400">• Live Range</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-indigo-100/70 dark:bg-indigo-950/60">
                    <div class="h-full rounded-full bg-indigo-500 transition-all duration-300" style="width: {{ $totalPct }}%;"></div>
                </div>
            </div>
        </a>
    </div>

    <div class="flex flex-wrap gap-2 rounded-lg border border-border bg-white p-3 text-xs shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-teal-500"></span>Ready for pickup</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-orange-500"></span>Ready for delivery</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>Released to customer</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>In process</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>Cancelled</span>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                <tr>
                    <th class="px-4 py-3">JO #</th><th class="px-4 py-3">Created</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Address</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Balance</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border dark:divide-gray-800">
                @forelse($orders as $order)
                    @php($isReadyForPickup = $order->status === 'ready_for_pickup')
                    @php($isReadyForDelivery = $order->status === 'ready_for_delivery')
                    @php($isReady = $isReadyForPickup || $isReadyForDelivery)
                    @php($isReleased = (bool) $order->released_at)
                    @php($isInProcess = in_array($order->status, ['pending', 'washing', 'drying', 'folding'], true))
                    @php($canRecordPayment = (float) $order->balance > 0 && $order->status !== 'cancelled')
                    <tr>
                        <td class="border-l-4 px-4 py-3 font-medium {{ $isReadyForPickup ? 'border-l-teal-500 bg-teal-50/50 dark:bg-teal-500/5' : ($isReadyForDelivery ? 'border-l-orange-500 bg-orange-50/50 dark:bg-orange-500/5' : ($isReleased ? 'border-l-green-500 bg-green-50/50 dark:bg-green-500/5' : ($isInProcess ? 'border-l-blue-500 bg-blue-50/50 dark:bg-blue-500/5' : ($order->status === 'cancelled' ? 'border-l-red-500' : 'border-l-transparent')))) }}">
                            <p>{{ $order->job_order_number }}</p>
                            @if($order->is_rush)
                                <span class="mt-1 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-800 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300">Rush</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $order->created_at?->format('M d, Y') }}</p>
                            <p class="text-xs text-muted">{{ $order->created_at?->format('h:i A') }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $order->customer?->name }}</p>
                            <span class="{{ \App\Support\StatusBadge::classes($order->transaction_type === 'delivery' ? 'delivery' : 'regular') }}">{{ $order->transaction_type === 'delivery' ? 'Delivery / Pick-up' : 'Walk-in / Drop Off' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $order->branch?->name }}</p>
                            @if(($order->branch?->branch_type ?? 'full_service') === 'pickup_dropoff')
                                <p class="text-xs text-muted">Pickup & Drop-off</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $order->customer?->address }}
                            {{-- <p>{{ $order->processingBranch?->name ?? $order->branch?->name }}</p>
                            @if((int) ($order->processing_branch_id ?: $order->branch_id) !== (int) $order->branch_id)
                                @if($order->production_accepted_at)
                                    <p class="text-xs text-emerald-600">Received {{ $order->production_accepted_at->format('M d, h:i A') }}</p>
                                @else
                                    <p class="text-xs text-amber-600">Waiting for QR scan</p>
                                @endif
                            @endif --}}
                        </td>
                        <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</td>
                        <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</td>
                        <td class="px-4 py-3">
                            <div class="space-y-1.5">
                                <span class="{{ \App\Support\StatusBadge::classes($order->status) }}">{{ \App\Support\StatusBadge::label($order->status) }}</span>
                                @if($isReadyForPickup)
                                    <p class="inline-flex items-center gap-1 text-xs font-medium text-teal-700 dark:text-teal-300"><span class="h-2 w-2 rounded-full bg-teal-500"></span>Awaiting customer pickup</p>
                                @elseif($isReadyForDelivery)
                                    <p class="inline-flex items-center gap-1 text-xs font-medium text-orange-700 dark:text-orange-300"><span class="h-2 w-2 rounded-full bg-orange-500"></span>Awaiting delivery</p>
                                @elseif($isReleased)
                                    <p class="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-300"><span class="h-2 w-2 rounded-full bg-green-500"></span>Released {{ $order->released_at->format('M d, h:i A') }}</p>
                                @elseif($isInProcess)
                                    <p class="inline-flex items-center gap-1 text-xs font-medium text-blue-700 dark:text-blue-300"><span class="h-2 w-2 rounded-full bg-blue-500"></span>In process</p>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.job-orders.show', $order) }}" title="View" aria-label="View job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="eye" class="h-4 w-4"></span>
                            </a>
                            <a href="{{ route('admin.job-orders.edit', $order) }}" title="Edit" aria-label="Edit job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="settings" class="h-4 w-4"></span>
                            </a>
                            <button type="button" @click="paymentOpen = {{ $order->id }}" title="Payment history" aria-label="View payment history" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="payments" class="h-4 w-4"></span>
                            </button>
                            @if($canRecordPayment)
                                <button type="button" @click="payOpen = {{ $order->id }}" title="Record payment" aria-label="Record payment" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900/60 dark:text-emerald-300 dark:hover:bg-emerald-500/10">
                                    <span data-lucide="dollar" class="h-4 w-4"></span>
                                </button>
                            @endif
                            <button type="button" @click="receiptOpen = {{ $order->id }}" title="Receipt" aria-label="Print receipt" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="receipt" class="h-4 w-4"></span>
                            </button>
                            @if($isReady)
                                <form method="POST" action="{{ route('admin.job-orders.release', $order) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" x-on:click.prevent="Swal.fire({ title: 'Complete laundry?', text: 'Confirm that this laundry was picked up or sent for delivery. This will mark the job order as completed.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#0f766e', confirmButtonText: 'Complete' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })" title="Release job order to customer" aria-label="Release job order to customer" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-teal-200 text-teal-700 hover:bg-teal-50 dark:border-teal-900/60 dark:text-teal-300 dark:hover:bg-teal-500/10">
                                        <span data-lucide="package-check" class="h-4 w-4"></span>
                                    </button>
                                </form>
                            @endif
                            @unless(in_array($order->status, ['completed', 'cancelled'], true))
                                <button type="button" @click="statusOpen = {{ $order->id }}" title="Update status" aria-label="Update status" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="activity" class="h-4 w-4"></span>
                                </button>
                                <button type="button" @click="cancelOpen = {{ $order->id }}" title="Cancel" aria-label="Cancel job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50">
                                    <span data-lucide="x" class="h-4 w-4"></span>
                                </button>
                            @endunless
                            @if(auth()->user()?->role === 'super_admin')
                                <form method="POST" action="{{ route('admin.job-orders.destroy', $order) }}" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" x-on:click.prevent="Swal.fire({ title: 'Delete job order?', text: 'This will delete the connected payments, ledger entries, items, and cycle records. A deletion log will be saved.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })" title="Delete" aria-label="Delete job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-500/10">
                                        <span data-lucide="trash" class="h-4 w-4"></span>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-muted">No job orders found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $orders->links() }}</div>
    </div>

    @foreach($orders as $order)
        <div x-cloak x-show="paymentOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="paymentOpen = null" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="payments" class="h-4 w-4 text-primary"></span>Payment History</h2>
                        <p class="text-sm text-muted">{{ $order->job_order_number }} - {{ $order->customer?->name }}</p>
                    </div>
                    <button type="button" @click="paymentOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                <div class="mb-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <p class="text-xs text-muted">Total</p>
                        <p class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <p class="text-xs text-muted">Paid</p>
                        <p class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->paid_amount, 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <p class="text-xs text-muted">Balance</p>
                        <p class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-border dark:border-gray-800">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-smoke text-xs uppercase text-muted dark:bg-gray-950">
                            <tr><th class="px-3 py-2">Payment #</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Reference</th><th class="px-3 py-2">Received By</th><th class="px-3 py-2">Date</th><th class="px-3 py-2 text-right">Amount</th></tr>
                        </thead>
                        <tbody class="divide-y divide-border dark:divide-gray-800">
                            @forelse($order->payments->sortByDesc('paid_at') as $payment)
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $payment->payment_number }}</td>
                                    <td class="px-3 py-2"><span class="{{ \App\Support\StatusBadge::classes($payment->payment_type) }}">{{ \App\Support\StatusBadge::label($payment->payment_type) }}</span></td>
                                    <td class="px-3 py-2">{{ $payment->reference_no ?: 'N/A' }}</td>
                                    <td class="px-3 py-2">{{ $payment->receiver?->name ?? 'N/A' }}</td>
                                    <td class="px-3 py-2">{{ $payment->paid_at?->format('M d, Y h:i A') }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-8 text-center text-muted">No payments recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if((float) $order->balance > 0 && $order->status !== 'cancelled')
            <div x-cloak x-show="payOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div @click.outside="payOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="dollar" class="h-4 w-4 text-primary"></span>Record Payment</h2>
                            <p class="text-sm text-muted">{{ $order->job_order_number }} - {{ $order->customer?->name }}</p>
                        </div>
                        <button type="button" @click="payOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                    </div>

                    <form method="POST" action="{{ route('admin.job-orders.payments.store', $order) }}" class="space-y-4">
                        @csrf
                        <div class="rounded-lg border border-border bg-smoke p-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                            <div class="flex items-center justify-between">
                                <span class="text-muted">Remaining balance</span>
                                <span class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</span>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium">Payment Type</label>
                            <select name="payment_type" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium">Amount</label>
                            <input type="number" step="0.01" min="0.01" max="{{ $order->balance }}" name="amount" value="{{ $order->balance }}" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium">Reference No.</label>
                            <input name="reference_no" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="GCash/card reference">
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium">Remarks</label>
                            <textarea name="remarks" rows="3" class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="Optional notes"></textarea>
                        </div>

                        @if($errors->any())
                            <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                {{ $errors->first() }}
                            </div>
                        @endif

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">
                                <span data-lucide="payments" class="h-4 w-4"></span>
                                Save Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <div x-cloak x-show="receiptOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="receiptOpen = null" class="max-h-[92vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-4 shadow-2xl dark:bg-gray-900">
                <div class="receipt-print-actions mb-3 flex items-center justify-between gap-2">
                    <h2 class="inline-flex min-w-0 items-center gap-2 text-sm font-semibold"><span data-lucide="receipt" class="h-4 w-4 text-primary"></span><span class="truncate">{{ $order->job_order_number }} Receipt</span></h2>
                    <div class="flex gap-2">
                        <button type="button" onclick="window.print()" class="inline-flex h-8 items-center gap-2 rounded-md bg-primary px-3 text-xs font-medium text-white hover:opacity-90"><span data-lucide="printer" class="h-3.5 w-3.5"></span>Print</button>
                        <button type="button" @click="receiptOpen = null" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950"><span data-lucide="x" class="h-4 w-4"></span></button>
                    </div>
                </div>
                <div class="receipt-print-area">
                    @include('admin.job-orders.partials.receipt-card', ['order' => $order, 'settings' => $appSettings, 'branchSetting' => $order->branch?->setting])
                </div>
            </div>
        </div>

        <div x-cloak x-show="statusOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="statusOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="activity" class="h-4 w-4 text-primary"></span>Update Status</h2>
                        <p class="text-sm text-muted">{{ $order->job_order_number }}</p>
                    </div>
                    <button type="button" @click="statusOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                <form method="POST" action="{{ route('admin.job-orders.status', $order) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <select name="status" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                        @foreach(array_filter($statuses, fn ($status) => $status !== 'cancelled') as $status)
                            <option value="{{ $status }}" @selected($order->status === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                        @endforeach
                    </select>
                    <div class="flex justify-end">
                        <button class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">Save Status</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-cloak x-show="cancelOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="cancelOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">Cancel job order?</h2>
                    <p class="mt-1 text-sm text-muted">{{ $order->job_order_number }} will be marked as cancelled.</p>
                </div>
                <form method="POST" action="{{ route('admin.job-orders.cancel', $order) }}" class="flex justify-end gap-2">
                    @csrf
                    @method('PATCH')
                    <button type="button" @click="cancelOpen = null" class="h-9 rounded-md border border-border px-4 text-sm font-medium hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">Keep</button>
                    <button class="h-9 rounded-md bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700">Cancel Order</button>
                </form>
            </div>
        </div>
    @endforeach
</div>
@endsection
