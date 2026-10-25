@extends('layouts.app')

@php
    $isEditing = isset($jobOrder);
    $booked = $isEditing ? null : ($bookedRequest ?? null);

    // What the rider took at the door, when this order comes from a booking
    // they collected. Starts the payment there so the load is not saved as
    // money still owed and charged a second time on delivery.
    $riderPayment = $booked ? $booked->riderPaymentPrefill() : null;

    $pageTitle = $isEditing ? 'Edit Job Order' : (in_array(auth()->user()->role, ['branch_manager', 'cashier'], true) ? 'Cashier POS' : 'New Job Order');
    $currency = $appSettings?->currency ?? 'PHP';
    $currencySymbol = in_array(strtoupper($currency), ['PHP', '₱'], true) ? '₱' : $currency.' ';

    $selectedBranch = $branches->firstWhere('id', (int) $branchId);
    $canSelectProcessingBranch = auth()->user()->isAdmin() || $selectedBranch?->isPickupDropoff();
    $canChooseBranch = ! $isEditing && ! $booked && in_array(auth()->user()->role, ['super_admin', 'admin'], true);

    // The catalog groups add-ons (detergent, fabric conditioner, extras)
    // apart from laundry services. Neither the add-on flag nor the report
    // category is in the catalog payload, so read them alongside it here.
    $addonCategoryIds = \App\Models\LaundryServiceCategory::query()->where('is_addon', true)->pluck('id')->values();
    $reportCategories = \App\Models\LaundryService::query()->whereIn('id', $services->pluck('id'))->pluck('report_category', 'id');

    // A save the server refused comes back with the cart as it was submitted,
    // so the lines the cashier just added (add-ons especially) are not lost.
    $bookedLines = collect($bookedItems ?? []);
    $submittedItems = collect(old('items', []))
        ->filter(fn ($item) => is_array($item) && (! empty($item['laundry_service_id']) || ! empty($item['service_preset_id'])))
        ->map(function ($item) use ($services, $servicePresets, $bookedLines) {
            $isPreset = ! empty($item['service_preset_id']);
            $id = (int) ($isPreset ? $item['service_preset_id'] : $item['laundry_service_id']);
            $type = $isPreset ? 'preset' : 'service';
            $preset = $isPreset ? collect($servicePresets)->firstWhere('id', $id) : null;
            $booked = $bookedLines->first(fn ($line) => $line['type'] === $type && (int) $line['id'] === $id);

            return array_filter([
                'id' => $id,
                'type' => $type,
                'name' => (string) ($item['description'] ?? ''),
                'pricing_type' => $isPreset ? null : $services->firstWhere('id', $id)?->pricing_type,
                'summary' => $preset ? collect($preset['items'])->map(fn ($component) => $component['name'])->implode(', ') : null,
                'quantity' => (float) ($item['quantity'] ?? 0),
                'price' => (float) ($item['unit_price'] ?? 0),
                'booked' => $booked['booked'] ?? null,
            ], fn ($value) => $value !== null);
        })
        ->values();

    $transactionType = old('transaction_type', $isEditing ? $jobOrder->transaction_type : ($booked && $booked->wantsDelivery() ? 'delivery' : request('transaction_type', 'walk_in')));
    $riderCollected = ($riderPayment['state'] ?? null) === 'collected';

    $initialState = [
        'isEditing' => $isEditing,
        // A new order opened from an online booking starts as what the customer
        // said they were sending, for the cashier to check against the scale.
        'initialItems' => $submittedItems->isNotEmpty() ? $submittedItems : ($isEditing ? $initialItems : ($bookedItems ?? [])),
        'selectedCustomerId' => (string) old('customer_id', $selectedCustomerId ?? ''),
        'processingBranchId' => (string) old('processing_branch_id', $isEditing ? ($jobOrder->processing_branch_id ?: $jobOrder->branch_id) : ''),
        'discount' => (float) old('discount', $isEditing ? $jobOrder->discount : 0),
        'paid' => (float) ($isEditing
            ? $jobOrder->payments->sum('amount')
            : ($riderCollected ? $riderPayment['paid'] : old('paid_amount', 0))),
        'paymentType' => $riderCollected ? $riderPayment['type'] : old('payment_type', 'unpaid'),
        // Payment is a step the cashier answers on purpose. Only a refused
        // save or a rider who already collected answers it for them.
        'paymentChosen' => $riderCollected || old('payment_type') !== null,
        'riderCashCollected' => $riderCollected && ($riderPayment['method'] ?? null) === 'cash',
        'notes' => (string) old('notes', $isEditing ? ($jobOrder->notes ?? '') : ($booked->notes ?? '')),
        'transactionType' => $transactionType === 'delivery' ? 'delivery' : 'walk_in',
        'isRush' => (bool) old('is_rush', $isEditing ? $jobOrder->is_rush : ($booked->is_rush ?? request()->boolean('is_rush'))),
        'addonCategoryIds' => $addonCategoryIds,
        'reportCategories' => $reportCategories,
        'currencySymbol' => $currencySymbol,
    ];
@endphp

@section('page_title', $pageTitle)
@section('hide_footer', true)

@section('content')
<div
    x-data="posPage(@js($branches), @js($processingBranches), @js($services), @js($customers), @js($serviceCategories), @js($servicePresets), @js((float) ($appSettings?->vat_rate ?? 0)), @js((bool) ($appSettings?->vat_enabled ?? false)), @js($initialState))"
    class="flex-1 min-h-0 flex flex-col h-full overflow-hidden"
>
    <form
        method="POST"
        action="{{ $isEditing ? route('admin.job-orders.update', $jobOrder) : route('admin.job-orders.store') }}"
        @submit="submitOrder($event)"
        @keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()"
        class="flex-1 min-h-0 flex flex-col overflow-hidden"
    >
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif

        {{-- Set when staff open this form from a customer's online pickup booking. --}}
        @if($booked)
            <input type="hidden" name="pickup_request_id" value="{{ $booked->id }}">
        @endif

        <input type="hidden" name="customer_id" :value="selectedCustomerId">
        <input type="hidden" name="transaction_type" :value="transactionType">
        <input type="hidden" name="is_rush" :value="isRush ? 1 : 0">
        <input type="hidden" name="notes" :value="composedNotes">
        <input type="hidden" name="discount" :value="Number(discount || 0)">
        @unless($isEditing)
            <input type="hidden" name="payment_type" :value="paymentTypeValue">
            <input type="hidden" name="paid_amount" :value="paidAmount">
        @endunless

        {{-- HEADER --}}
        <header class="mb-2 shrink-0 rounded-xl border border-border bg-white px-3 py-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                <div class="min-w-0">
                    <p class="hidden truncate text-[10px] font-bold uppercase tracking-[0.14em] text-primary 2xl:block">
                        {{ $appSystemName }} · <span x-text="selectedBranch?.name || ''">{{ $selectedBranch?->name }}</span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-serif text-lg font-bold leading-tight text-dark dark:text-white">{{ $isEditing ? 'Edit job order' : 'New job order' }}</h1>
                        <span class="rounded-full bg-smoke px-2 py-0.5 font-mono text-[10px] font-semibold text-muted ring-1 ring-border dark:bg-gray-800 dark:ring-gray-700">
                            {{ $isEditing ? $jobOrder->job_order_number : now()->format('D, M j') }}
                        </span>
                        <span class="truncate text-[11px] font-semibold text-primary 2xl:hidden" x-text="selectedBranch?.name || ''">{{ $selectedBranch?->name }}</span>
                    </div>
                    <p class="sr-only">{{ $isEditing ? 'Edit customer order' : 'Create customer order' }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 sm:ml-auto">
                    @if($canChooseBranch)
                        <label class="flex items-center gap-1.5 text-[11px] font-semibold text-muted">
                            <span class="hidden xl:inline">Branch</span>
                            <select name="branch_id" x-model="branchId" aria-label="Branch" class="h-8 max-w-40 rounded-lg border border-border bg-white px-2 text-xs font-semibold text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-white" required>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($branchId == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <input type="hidden" name="branch_id" value="{{ $branchId }}">
                    @endif

                    @if($canSelectProcessingBranch)
                        <label class="flex items-center gap-1.5 text-[11px] font-semibold text-muted">
                            <span class="hidden 2xl:inline">Receiving Production Branch</span>
                            <span class="2xl:hidden">Receiving</span>
                            <select name="processing_branch_id" x-model="processingBranchId" class="h-8 max-w-40 rounded-lg border border-border bg-white px-2 text-xs font-semibold text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-white" required>
                                <template x-for="branch in processingBranches" :key="branch.id">
                                    <option :value="branch.id" x-text="branch.name"></option>
                                </template>
                            </select>
                        </label>
                    @else
                        <input type="hidden" name="processing_branch_id" value="{{ $branchId }}">
                    @endif

                    @if($isEditing)
                        <label class="flex items-center gap-1.5 text-[11px] font-semibold text-muted">
                            Status
                            <select name="status" class="h-8 rounded-lg border border-border bg-white px-2 text-xs font-semibold text-dark dark:border-gray-700 dark:bg-gray-950 dark:text-white" required>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" @selected(old('status', $jobOrder->status) === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <span class="hidden text-[11px] text-muted xl:inline">Staff: <span class="font-bold text-dark dark:text-white">{{ auth()->user()->name }}</span></span>
                    <a href="{{ route('admin.job-orders.index') }}" class="inline-flex h-8 items-center rounded-lg border border-border bg-white px-2.5 text-xs font-bold text-dark hover:border-primary/50 dark:border-gray-700 dark:bg-gray-950 dark:text-white">Orders list</a>
                    @if($isEditing)
                        <a href="{{ route('admin.job-orders.show', $jobOrder) }}" class="inline-flex h-8 items-center rounded-lg border border-border bg-white px-2.5 text-xs font-bold text-muted hover:text-dark dark:border-gray-700 dark:bg-gray-950 dark:hover:text-white">Cancel</a>
                    @else
                        <button type="button" @click="clearOrder()" class="inline-flex h-8 items-center rounded-lg border border-border bg-white px-2.5 text-xs font-bold text-muted hover:text-dark dark:border-gray-700 dark:bg-gray-950 dark:hover:text-white">Clear</button>
                    @endif
                </div>
            </div>
        </header>

        {{-- BODY: the steps scroll on the left, the summary stays in view on the right. --}}
        <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain pb-20 md:grid md:grid-rows-1 md:grid-cols-[minmax(0,1fr)_17.5rem] md:gap-2 md:overflow-hidden md:pb-0 lg:grid-cols-[minmax(0,1fr)_18.5rem] xl:grid-cols-[minmax(0,1fr)_19.5rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="@container/steps flex flex-col gap-2 tall:gap-2.5 md:min-h-0 md:overflow-y-auto md:overscroll-contain md:pr-0.5">

                {{-- STEP 1 · CUSTOMER --}}
                <section class="rounded-xl border bg-white px-3 py-2.5 shadow-sm tall:py-3 roomy:px-4 dark:bg-gray-900" :class="customerDone ? 'border-emerald-300 dark:border-emerald-500/40' : 'border-border dark:border-gray-800'">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="customerDone ? 'bg-emerald-600 text-white' : 'bg-smoke text-muted ring-1 ring-border dark:bg-gray-800 dark:ring-gray-700'">
                                <span x-show="!customerDone">1</span>
                                <svg x-show="customerDone" x-cloak class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            </span>
                            <h2 class="text-sm font-bold text-dark dark:text-white">Customer</h2>
                        </div>

                        {{-- Chosen customer --}}
                        <div x-show="selectedCustomer" x-cloak class="flex min-w-0 flex-1 basis-64 items-center gap-2.5 rounded-lg border border-border bg-smoke/60 py-1 pl-1 pr-1 dark:border-gray-700 dark:bg-gray-950">
                            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/15 font-serif text-xs font-bold text-primary-deep dark:text-primary-soft" x-text="initials(selectedCustomer?.name)"></span>
                            <p class="min-w-0 flex-1 truncate text-xs">
                                <span class="font-bold text-dark dark:text-white" x-text="selectedCustomer?.name"></span>
                                <span class="text-muted" x-text="' · ' + [selectedCustomer?.phone || 'No phone', formatBilling(selectedCustomer?.billing_type)].join(' · ')"></span>
                            </p>
                            @unless($booked)
                                <button type="button" @click="clearCustomer(); $nextTick(() => $refs.customerSearch?.focus())" class="h-7 shrink-0 rounded-md border border-border bg-white px-2.5 text-[11px] font-bold text-dark hover:border-primary/50 dark:border-gray-700 dark:bg-gray-900 dark:text-white">Change</button>
                            @endunless
                        </div>

                        {{-- Search --}}
                        <div x-show="!selectedCustomer" class="flex min-w-0 flex-1 basis-64 gap-1.5">
                            <div class="relative min-w-0 flex-1" @click.outside="customerOpen = false">
                                <input
                                    type="search"
                                    x-ref="customerSearch"
                                    x-model="customerSearch"
                                    @focus="customerOpen = true"
                                    @input="customerOpen = true"
                                    @keydown.enter.prevent="filteredCustomers[0] && selectCustomer(filteredCustomers[0])"
                                    @keydown.escape="customerOpen = false"
                                    placeholder="Search name or phone number..."
                                    aria-label="Search customer by name or phone"
                                    class="h-9 w-full rounded-lg border border-border bg-white px-3 text-sm outline-none tall:h-10 roomy:h-11 placeholder:text-muted focus:border-primary focus:ring-1 focus:ring-primary dark:border-gray-700 dark:bg-gray-950"
                                    autocomplete="off"
                                >
                                <div x-cloak x-show="customerOpen" x-transition.opacity class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-950">
                                    <template x-for="customer in filteredCustomers" :key="customer.id">
                                        <button type="button" @click="selectCustomer(customer)" class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-smoke dark:hover:bg-gray-900">
                                            <span class="min-w-0">
                                                <span class="block truncate font-semibold" x-text="customer.name"></span>
                                                <span class="block truncate text-xs text-muted" x-text="customer.phone || 'No phone'"></span>
                                            </span>
                                            <span class="shrink-0 text-[10px] font-semibold text-muted" x-text="formatBilling(customer.billing_type)"></span>
                                        </button>
                                    </template>
                                    <div x-show="filteredCustomers.length === 0" class="px-3 py-5 text-center text-sm text-muted">
                                        No customers found.
                                        @unless($isEditing)
                                            <button type="button" @click="openQuickCustomer()" class="mt-1 block w-full font-semibold text-primary hover:underline">Add as a new customer</button>
                                        @endunless
                                    </div>
                                </div>
                            </div>
                            @unless($isEditing)
                                <button type="button" @click="openQuickCustomer()" class="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg border border-primary/50 tall:h-10 roomy:h-11 bg-white px-2.5 text-xs font-bold text-primary-deep hover:bg-primary/5 dark:bg-gray-950 dark:text-primary-soft" title="New customer">
                                    <span aria-hidden="true">+</span><span>New<span class="hidden @lg/steps:inline"> customer</span></span>
                                </button>
                            @endunless
                        </div>

                        @unless($isEditing)
                            {{-- How the laundry comes in. A house pickup is the rider's
                                 collected bag, loaded by its tag. --}}
                            <div class="flex w-full items-center gap-1.5 @5xl/steps:w-auto" role="group" aria-label="How does the laundry come in?">
                                <span class="hidden shrink-0 text-[11px] font-bold text-muted @2xl/steps:inline @5xl/steps:hidden">How does the laundry come in?</span>
                                <button type="button" @click="chooseWalkIn()" title="Walk-in drop-off: customer brings it to the shop"
                                        class="flex h-9 min-w-0 flex-1 items-center gap-1.5 rounded-lg border px-2.5 text-left transition tall:h-10 roomy:h-11 @5xl/steps:flex-none"
                                        :class="!bookingLoaded ? 'border-primary-deep bg-primary/5 ring-1 ring-primary-deep dark:border-primary-soft dark:ring-primary-soft' : 'border-border hover:border-primary/50 dark:border-gray-700'">
                                    <span data-lucide="footprints" class="h-4 w-4 shrink-0 text-primary-deep dark:text-primary-soft"></span>
                                    <span class="truncate text-xs font-bold text-dark dark:text-white">Walk-in drop-off</span>
                                </button>
                                <button type="button" @click="openTagModal()"
                                        class="flex h-9 min-w-0 flex-1 items-center gap-1.5 rounded-lg border px-2.5 text-left transition tall:h-10 roomy:h-11 @5xl/steps:flex-none"
                                        :class="bookingLoaded ? 'border-primary-deep bg-primary/5 ring-1 ring-primary-deep dark:border-primary-soft dark:ring-primary-soft' : 'border-border hover:border-primary/50 dark:border-gray-700'"
                                        title="We collect at the customer's home · Load tag #"
                                        :aria-label="`House pickup by e-bike: load rider pickup bag tag, ${waitingTagCounts[branchId] ?? 0} waiting at this branch`">
                                    <span data-lucide="bike" class="h-4 w-4 shrink-0 text-primary-deep dark:text-primary-soft"></span>
                                    <span class="truncate text-xs font-bold text-dark dark:text-white">House pickup<span class="hidden @md/steps:inline"> by e-bike</span></span>
                                    <span class="sr-only">Load tag #</span>
                                    <span class="ml-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-bold leading-none text-white"
                                          title="Collected bags waiting for a job order"
                                          x-text="waitingTagCounts[branchId] ?? 0">{{ $waitingTagCounts[$branchId] ?? 0 }}</span>
                                </button>
                            </div>
                        @endunless
                    </div>

                    <div x-show="!selectedCustomer && recentCustomers.length" x-cloak class="mt-1.5 flex items-center gap-1.5 overflow-x-auto">
                        <span class="shrink-0 text-[11px] text-muted">Recent:</span>
                        <template x-for="customer in recentCustomers" :key="customer.id">
                            <button type="button" @click="selectCustomer(customer)" class="inline-flex h-6 shrink-0 items-center gap-1 rounded-full bg-smoke px-2.5 text-[11px] ring-1 ring-border hover:ring-primary/50 dark:bg-gray-800 dark:ring-gray-700">
                                <span class="font-bold text-dark dark:text-white" x-text="customer.name"></span>
                                <span class="text-muted" x-show="customer.phone" x-text="'· ' + maskPhone(customer.phone)"></span>
                            </button>
                        </template>
                    </div>

                    @if($booked)
                        <div class="mt-1.5 flex flex-wrap items-center gap-2 rounded-lg bg-primary/10 px-3 py-1.5 text-xs">
                            <span class="font-bold text-primary-deep dark:text-primary-soft">Loaded {{ $booked->reference_no }}@if($booked->tag_code) · {{ $booked->tag_code }}@endif</span>
                            <span class="text-muted">· {{ $booked->contact_name }}</span>
                            <a href="{{ route('admin.job-orders.create', ['branch_id' => $branchId]) }}" class="ml-auto font-bold text-muted hover:text-primary">Clear</a>
                        </div>
                    @endif
                    @unless($isEditing)
                        @error('pickup_request_id')
                            <p class="mt-1 text-xs font-medium text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                    @endunless
                </section>

                {{-- STEP 2 · SERVICES --}}
                <section aria-label="Service Catalog" class="flex grow flex-col rounded-xl border bg-white px-3 py-2.5 shadow-sm tall:py-3 roomy:px-4 dark:bg-gray-900" :class="servicesDone ? 'border-emerald-300 dark:border-emerald-500/40' : 'border-border dark:border-gray-800'">
                    <div class="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="servicesDone ? 'bg-emerald-600 text-white' : 'bg-smoke text-muted ring-1 ring-border dark:bg-gray-800 dark:ring-gray-700'">
                                <span x-show="!servicesDone">2</span>
                                <svg x-show="servicesDone" x-cloak class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            </span>
                            <h2 class="text-sm font-bold text-dark dark:text-white">Services</h2>
                        </div>
                        <div x-show="serviceTypes.length > 2" class="order-last flex w-full min-w-0 gap-1 overflow-x-auto @2xl/steps:order-0 @2xl/steps:w-auto @2xl/steps:flex-1">
                            <template x-for="type in serviceTypes" :key="type.value">
                                <button type="button" @click="typeFilter = type.value" class="h-6 shrink-0 rounded-full px-2.5 text-[11px] font-bold whitespace-nowrap ring-1 transition"
                                        :class="String(typeFilter) === String(type.value) ? 'bg-primary-deep text-white ring-primary-deep' : 'bg-white text-muted ring-border hover:text-dark dark:bg-gray-950 dark:ring-gray-700 dark:hover:text-white'"
                                        x-text="type.label"></button>
                            </template>
                        </div>
                        <div class="ml-auto flex h-7 w-40 items-center gap-1.5 rounded-lg border border-border px-2 @lg/steps:w-48 dark:border-gray-700">
                            <span data-lucide="search" class="h-3.5 w-3.5 shrink-0 text-muted"></span>
                            <input type="search" x-model.debounce.150ms="serviceSearch" placeholder="Search services..." aria-label="Search services" class="w-full min-w-0 bg-transparent text-xs outline-none">
                        </div>
                    </div>

                    <div class="flex grow flex-col gap-2 tall:gap-3">
                        {{-- One compact tile per service, as many per row as fit. On a wide
                             panel the group name sits beside its tiles instead of above. --}}
                        <template x-for="group in serviceGroups" :key="group.key">
                            <div class="grid grow grid-rows-[auto_minmax(0,1fr)] gap-x-2 gap-y-1 @4xl/steps:grid-cols-[6.5rem_minmax(0,1fr)] @4xl/steps:grid-rows-1">
                                <p class="text-[10px] font-bold uppercase leading-tight tracking-widest text-muted @4xl/steps:pt-2 roomy:@4xl/steps:pt-3.5">
                                    <span x-text="group.label"></span><span x-show="group.hint" class="text-muted/70 @4xl/steps:block" x-text="' · ' + group.hint"></span>
                                </p>
                                <div class="grid auto-rows-[minmax(min-content,1fr)] gap-(--catalog-gap) [--catalog-gap:0.375rem] roomy:[--catalog-gap:0.5rem]" :style="catalogGridStyle">
                                    <template x-for="entry in group.entries" :key="entry.key">
                                        <button type="button" @click="entry.preset ? addPreset(entry.preset) : tapService(entry.service)" :title="entry.name"
                                                class="relative flex min-w-0 items-center gap-2 rounded-lg border px-1.5 py-1.5 text-left transition tall:gap-2.5 tall:px-2.5 tall:py-2.5 roomy:flex-col roomy:items-start roomy:justify-center roomy:gap-2 roomy:px-4 roomy:py-3.5"
                                                :class="entry.line() ? 'border-primary-deep bg-primary/5 ring-1 ring-primary-deep dark:border-primary-soft' : 'border-border hover:border-primary/50 dark:border-gray-700'">
                                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md tall:h-10 tall:w-10 tall:rounded-lg roomy:h-12 roomy:w-12" :class="group.tone.icon" x-html="icon(entry.icon, 'h-4 w-4 tall:h-5 tall:w-5 roomy:h-6 roomy:w-6')"></span>
                                            <span class="min-w-0 flex-1 roomy:w-full roomy:flex-none">
                                                <span class="line-clamp-2 text-[11px] font-bold leading-[1.2] break-words text-dark tall:text-xs roomy:text-sm dark:text-white" x-text="entry.name"></span>
                                                <span class="mt-0.5 flex min-w-0 items-baseline gap-1 roomy:mt-1">
                                                    <span class="shrink-0 font-serif text-[13px] font-bold leading-none tall:text-[15px] roomy:text-lg" :class="group.tone.price" x-text="entry.price"></span>
                                                    <span x-show="!entry.line()" class="truncate text-[10px] leading-none text-muted roomy:text-[11px]" x-text="entry.detail"></span>
                                                </span>
                                            </span>
                                            <span x-show="entry.line()" class="shrink-0 rounded-full bg-primary-deep px-1.5 py-0.5 text-[10px] font-bold text-white roomy:absolute roomy:right-2.5 roomy:top-2.5 roomy:text-[11px]" x-text="entry.line() ? entry.badge() : ''"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Add-ons: every group flows on the same lines, each led by its name. --}}
                        <div x-show="addonGroups.length" class="grid gap-x-2 gap-y-1 border-t border-border pt-2 @4xl/steps:grid-cols-[6.5rem_minmax(0,1fr)] dark:border-gray-800">
                            <p class="text-[10px] font-bold uppercase leading-tight tracking-widest text-muted @4xl/steps:pt-2 roomy:@4xl/steps:pt-3.5">Add-ons<span class="text-muted/70 @4xl/steps:block"> · products</span></p>
                            <div class="flex flex-wrap items-center gap-1.5">
                        <template x-for="group in addonGroups" :key="group.key">
                                <div class="contents">
                                    <template x-for="(service, position) in group.services" :key="service.id">
                                        <span class="inline-flex max-w-full items-center gap-1.5">
                                        <span x-show="position === 0" class="shrink-0 pl-1 text-[11px] font-bold text-dark dark:text-white" x-text="group.label"></span>
                                        <button type="button" @click="toggleAddon(service)" class="inline-flex h-7 max-w-full items-center gap-1.5 rounded-full border py-0.5 pl-0.5 pr-2.5 text-left transition tall:h-8 roomy:h-10 roomy:pr-3.5"
                                                :class="lineFor(service) ? 'border-emerald-600 bg-emerald-50 ring-1 ring-emerald-600 dark:bg-emerald-500/10' : 'border-border hover:border-emerald-500/60 dark:border-gray-700'"
                                                :aria-pressed="Boolean(lineFor(service))" :title="service.name">
                                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#E7EFE3] text-[#4D6B45] dark:bg-[#4D6B45]/30 dark:text-[#B9D3AF]" x-html="icon(serviceIcon(service), 'h-3.5 w-3.5')"></span>
                                            <span class="truncate text-xs font-bold text-dark dark:text-white" x-text="service.name"></span>
                                            <span class="shrink-0 text-[11px] font-semibold text-[#4D6B45] dark:text-[#B9D3AF]" x-text="addonPrice(service)"></span>
                                        </button>
                                        </span>
                                    </template>
                                </div>
                        </template>
                            </div>
                        </div>

                        <div x-show="catalogEmpty" class="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted dark:border-gray-700">
                            No services match your filter.
                        </div>
                    </div>
                </section>

                {{-- STEP 3 · RETURN & TIMING --}}
                <section class="rounded-xl border bg-white px-3 py-2.5 shadow-sm tall:py-3 roomy:px-4 dark:bg-gray-900" :class="timingDone ? 'border-emerald-300 dark:border-emerald-500/40' : 'border-border dark:border-gray-800'">
                    <div class="mb-2 flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="timingDone ? 'bg-emerald-600 text-white' : 'bg-smoke text-muted ring-1 ring-border dark:bg-gray-800 dark:ring-gray-700'">
                            <span x-show="!timingDone">3</span>
                            <svg x-show="timingDone" x-cloak class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <h2 class="text-sm font-bold text-dark dark:text-white">Return &amp; timing</h2>
                        <p x-show="readyByKey === 'kept'" x-cloak class="truncate text-[11px] text-muted">· Promised: <span class="font-bold text-dark dark:text-white" x-text="readyByLabel"></span></p>
                    </div>

                    <div class="grid gap-2 @2xl/steps:grid-cols-[minmax(0,2fr)_minmax(0,4fr)]">
                        <div role="group" aria-label="How does the laundry go back?">
                            <p class="mb-1 text-[10px] font-bold uppercase tracking-widest text-muted @2xl/steps:sr-only">How does the laundry go back?</p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button type="button" @click="transactionType = 'walk_in'" class="h-10 rounded-lg border px-2 text-left leading-tight transition tall:h-11 roomy:h-12 roomy:px-3"
                                        :class="transactionType === 'walk_in' ? 'border-primary-deep bg-primary/5 ring-1 ring-primary-deep dark:border-primary-soft dark:ring-primary-soft' : 'border-border hover:border-primary/50 dark:border-gray-700'">
                                    <span class="block truncate text-xs font-bold text-dark dark:text-white">Pick up at shop</span>
                                    <span class="block truncate text-[10px] text-muted">Customer collects</span>
                                </button>
                                <button type="button" @click="transactionType = 'delivery'" class="h-10 rounded-lg border px-2 text-left leading-tight transition tall:h-11 roomy:h-12 roomy:px-3"
                                        :class="transactionType === 'delivery' ? 'border-primary-deep bg-primary/5 ring-1 ring-primary-deep dark:border-primary-soft dark:ring-primary-soft' : 'border-border hover:border-primary/50 dark:border-gray-700'">
                                    <span class="block truncate text-xs font-bold text-dark dark:text-white">E-bike delivery</span>
                                    <span class="block truncate text-[10px] text-muted">To customer address</span>
                                </button>
                            </div>
                        </div>
                        <div role="group" aria-label="Ready by">
                            <p class="mb-1 text-[10px] font-bold uppercase tracking-widest text-muted @2xl/steps:sr-only">Ready by</p>
                            <div class="grid grid-cols-4 gap-1.5">
                                <template x-for="option in readyOptions" :key="option.key">
                                    <button type="button" @click="setReadyBy(option.key)" :disabled="option.disabled" class="h-10 min-w-0 rounded-lg border px-2 text-left leading-tight transition tall:h-11 roomy:h-12 roomy:px-3 disabled:cursor-not-allowed disabled:opacity-40"
                                            :class="readyByKey === option.key ? 'border-primary-deep bg-primary/5 ring-1 ring-primary-deep dark:border-primary-soft dark:ring-primary-soft' : 'border-border hover:border-primary/50 dark:border-gray-700'">
                                        <span class="block truncate text-xs font-bold" :class="option.key === 'rush' ? 'text-amber-700 dark:text-amber-400' : 'text-dark dark:text-white'" x-text="option.title"></span>
                                        <span class="block truncate text-[10px]" :class="option.key === 'rush' ? 'text-amber-700/80 dark:text-amber-400/80' : 'text-muted'" x-text="option.sub"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Notes, with the quick tags beside them once there is room. --}}
                    <div class="mt-2 flex flex-col gap-1 @4xl/steps:flex-row @4xl/steps:items-center @4xl/steps:gap-2">
                        <label for="staff-notes" class="sr-only">Notes for staff</label>
                        <textarea id="staff-notes" x-model="notesText" rows="1" @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                                  placeholder="Notes for staff, e.g. separate whites, no fabcon, delicate blouse..."
                                  class="block min-h-8 w-full min-w-0 resize-none rounded-lg tall:min-h-9 roomy:min-h-10 border border-border bg-white px-2.5 py-1.5 text-xs outline-none placeholder:text-muted focus:border-primary focus:ring-1 focus:ring-primary @4xl/steps:flex-1 dark:border-gray-700 dark:bg-gray-950"></textarea>
                        <div class="flex min-w-0 gap-1 overflow-x-auto pb-0.5 @4xl/steps:max-w-[55%]">
                            <template x-for="tag in noteTags" :key="tag">
                                <button type="button" @click="appendNote(tag)" class="shrink-0 rounded-full bg-smoke px-2 py-0.5 text-[10px] font-semibold whitespace-nowrap text-muted ring-1 ring-border hover:text-primary hover:ring-primary/40 dark:bg-gray-800 dark:ring-gray-700" x-text="'+ ' + tag"></button>
                            </template>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ORDER SUMMARY --}}
            <aside x-ref="summary" aria-label="Cart" class="mt-2.5 flex flex-col overflow-hidden rounded-xl border border-border bg-white shadow-sm md:mt-0 md:min-h-0 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-1 flex-col md:min-h-0 md:overflow-y-auto md:overscroll-contain">
                    <div class="border-b border-border p-3 sm:p-4 dark:border-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="font-serif text-base font-bold text-dark dark:text-white">Order summary</h2>
                            <button type="button" x-show="items.length" @click="items = []; editIndex = null" class="text-[11px] font-semibold text-red-600 hover:underline">Clear items</button>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <template x-for="check in checklist" :key="check.label">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ring-1"
                                      :class="check.done ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30' : 'bg-smoke text-muted ring-border dark:bg-gray-800 dark:ring-gray-700'">
                                    <span x-text="check.done ? '✓' : '○'" aria-hidden="true"></span>
                                    <span x-text="check.label"></span>
                                </span>
                            </template>
                        </div>
                        <p x-show="selectedCustomer" x-cloak class="mt-2 truncate text-xs text-muted md:hidden">For <span class="font-bold text-dark dark:text-white" x-text="selectedCustomer?.name"></span></p>
                        <p x-show="!selectedCustomer" class="mt-2 text-xs text-muted">Choose a customer to begin</p>
                    </div>

                    <div class="flex grow flex-col gap-2 p-3">
                        {{-- Why the last save was refused, e.g. an add-on whose stock
                             ran out. Without this the page just reloads and the
                             order looks like it saved nothing. --}}
                        @php
                            $cartErrors = collect($errors->getMessages())->except('pickup_request_id')->flatten()->unique();
                        @endphp
                        @if($cartErrors->isNotEmpty())
                            <div class="rounded-lg border border-red-300 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10" role="alert">
                                <p class="text-xs font-semibold text-red-700 dark:text-red-400">{{ $isEditing ? 'Changes were not saved' : 'Order was not saved' }}</p>
                                <ul class="mt-1 space-y-0.5 text-[11px] leading-relaxed text-red-700 dark:text-red-300">
                                    @foreach($cartErrors as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if($booked)
                            {{-- The customer declared these amounts when they booked.
                                 Weighing the bag is what settles them. --}}
                            <div class="rounded-lg border border-primary/30 bg-primary/5 p-3">
                                <p class="text-xs font-semibold text-primary-deep dark:text-primary-soft">From booking {{ $booked->reference_no }}</p>
                                <p class="mt-1 text-[11px] leading-relaxed text-muted">
                                    @if($booked->declaredKilos())
                                        The customer declared {{ rtrim(rtrim(number_format((float) $booked->declaredKilos(), 2), '0'), '.') }} kg in total.
                                    @endif
                                    Weigh the bag and correct any line that does not match.
                                </p>
                            </div>

                            @if($riderPayment)
                                @php
                                    $collectedBy = $booked->rider?->name ?? 'The rider';
                                    $methodName = \App\Support\Booking::paymentMethods()[$riderPayment['method']] ?? ucfirst((string) $riderPayment['method']);
                                    $money = $currency.' '.number_format((float) $riderPayment['paid'], 2);
                                @endphp
                                <div @class([
                                    'rounded-lg border p-3',
                                    'border-emerald-300 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10' => $riderPayment['state'] === 'collected',
                                    'border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => $riderPayment['state'] !== 'collected',
                                ])>
                                    <p class="text-xs font-semibold">
                                        @if($riderPayment['state'] === 'collected')
                                            {{ $collectedBy }} collected {{ $money }} ({{ $methodName }}) at pickup
                                        @elseif($riderPayment['state'] === 'unpaid')
                                            {{ $collectedBy }} collected nothing at pickup
                                        @else
                                            {{ $collectedBy }} marked {{ $methodName }} but entered no amount
                                        @endif
                                    </p>
                                    <p class="mt-1 text-[11px] leading-relaxed text-muted">
                                        @if($riderPayment['state'] === 'collected')
                                            Payment below is set to match, so the customer is not charged twice.
                                        @elseif($riderPayment['state'] === 'unpaid')
                                            Payment is still due. Record it below.
                                        @else
                                            Check with the rider before recording what was paid.
                                        @endif
                                    </p>
                                </div>
                            @endif
                        @endif

                        {{-- What the order says so far. Shown while the cart is short, where
                             it fills space that would otherwise sit empty. --}}
                        <dl x-show="items.length < 3" class="hidden grid-cols-[auto_minmax(0,1fr)] [@media(min-height:860px)]:grid gap-x-3 gap-y-1 rounded-lg bg-smoke/70 p-2.5 text-[11px] tall:gap-y-1.5 tall:p-3 tall:text-xs dark:bg-gray-950">
                            <dt class="text-muted">Customer</dt>
                            <dd class="truncate font-semibold" :class="selectedCustomer ? 'text-dark dark:text-white' : 'text-muted/70'" x-text="selectedCustomer ? selectedCustomer.name + (selectedCustomer.phone ? ' · ' + selectedCustomer.phone : '') : 'Not chosen'"></dd>
                            @unless($isEditing)
                                <dt class="text-muted">Comes in</dt>
                                <dd class="truncate font-semibold text-dark dark:text-white" x-text="bookingLoaded ? 'House pickup (bag tag)' : 'Walk-in drop-off'"></dd>
                            @endunless
                            <dt class="text-muted">Goes back</dt>
                            <dd class="truncate font-semibold text-dark dark:text-white" x-text="transactionType === 'delivery' ? 'E-bike delivery' : 'Pick up at shop'"></dd>
                            <dt class="text-muted">Ready by</dt>
                            <dd class="truncate font-semibold" :class="readyByText ? (isRush ? 'text-amber-700 dark:text-amber-400' : 'text-dark dark:text-white') : 'text-muted/70'" x-text="readyByText || 'Not set'"></dd>
                            <dt class="text-muted">Notes</dt>
                            <dd class="line-clamp-2 font-semibold" :class="notesText.trim() ? 'text-dark dark:text-white' : 'text-muted/70'" x-text="notesText.trim() || 'None'"></dd>
                        </dl>

                        <template x-for="(item, index) in items" :key="index">
                            <div class="rounded-lg border p-2.5 transition" :class="editIndex === index ? 'border-primary/50 bg-primary/5' : 'border-border dark:border-gray-700'">
                                <input type="hidden" :name="item.type === 'preset' ? `items[${index}][service_preset_id]` : `items[${index}][laundry_service_id]`" :value="item.id">
                                <input type="hidden" :name="`items[${index}][description]`" :value="item.name">

                                <div class="flex items-start justify-between gap-2">
                                    <button type="button" @click="editIndex = editIndex === index ? null : index" class="min-w-0 flex-1 text-left" :aria-expanded="editIndex === index" :title="'Edit quantity or price of ' + item.name">
                                        <span class="block truncate text-xs font-bold text-dark dark:text-white" x-text="item.name"></span>
                                        <span class="block text-[11px] text-muted"><span x-text="lineCaption(item)"></span> <span class="font-semibold text-primary" x-text="editIndex === index ? '· done' : '· edit'"></span></span>
                                        {{-- What the customer said this line was, next to what
                                             the cashier has actually keyed in. --}}
                                        <span x-show="item.booked" x-cloak class="mt-0.5 block text-[10px] font-semibold"
                                              :class="matchesBooking(item) ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                                              x-text="bookedNote(item)"></span>
                                        <span x-show="item.type === 'preset'" class="mt-0.5 block truncate text-[10px] text-muted" x-text="item.summary"></span>
                                    </button>
                                    <div class="flex shrink-0 items-start gap-1">
                                        <p class="text-sm font-bold text-dark dark:text-white" x-text="peso(item.quantity * item.price)"></p>
                                        <button type="button" @click="items.splice(index, 1); editIndex = null" title="Remove" aria-label="Remove item" class="-mr-1 -mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-md text-muted hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                        </button>
                                    </div>
                                </div>
                                {{-- Collapsed until tapped; the inputs stay in the form either way. --}}
                                <div x-show="editIndex === index" x-cloak class="mt-1.5 flex items-center gap-2">
                                    <div class="flex h-7 overflow-hidden rounded-md border border-border dark:border-gray-700">
                                        <button type="button" @click="decreaseQuantity(item)" class="flex w-7 items-center justify-center text-sm hover:bg-smoke dark:hover:bg-gray-800" aria-label="Less">−</button>
                                        <input type="number" step="0.01" min="0.01" :name="`items[${index}][quantity]`" x-model.number="item.quantity" aria-label="Quantity" class="w-12 border-x border-border bg-transparent text-center text-xs outline-none dark:border-gray-700">
                                        <button type="button" @click="increaseQuantity(item)" class="flex w-7 items-center justify-center text-sm hover:bg-smoke dark:hover:bg-gray-800" aria-label="More">+</button>
                                    </div>
                                    <button type="button" x-show="usesKeypad(item)" @click="openKeypad(serviceById(item.id))" class="h-7 rounded-md border border-border px-2 text-[10px] font-bold text-muted hover:text-dark dark:border-gray-700 dark:hover:text-white">Weigh</button>
                                    <span class="text-[11px] text-muted">×</span>
                                    <label class="flex h-7 items-center rounded-md border border-border px-1.5 dark:border-gray-700">
                                        <span class="mr-0.5 text-[10px] text-muted">{{ trim($currencySymbol) }}</span>
                                        <input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model.number="item.price" aria-label="Unit price" class="w-14 bg-transparent text-right text-xs outline-none">
                                    </label>
                                </div>
                            </div>
                        </template>

                        <div x-show="items.length === 0" class="flex grow flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border py-4 text-center text-xs text-muted dark:border-gray-700">
                            <span class="hidden h-12 w-12 items-center justify-center rounded-full bg-smoke text-primary/60 tall:h-16 tall:w-16 [@media(min-height:860px)]:inline-flex dark:bg-gray-800" x-html="icon('shopping-bag', 'h-6 w-6 tall:h-8 tall:w-8')"></span>
                            <p><span class="block font-semibold text-dark dark:text-white">No services yet</span>Tap a service to start the order.</p>
                        </div>
                    </div>
                </div>

                {{-- Totals, payment, and the button: always in view. --}}
                <div class="shrink-0 space-y-2.5 border-t border-border bg-smoke/60 p-3 sm:p-4 dark:border-gray-800 dark:bg-gray-950">
                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between"><span class="text-muted">Services &amp; add-ons</span><span class="font-semibold" x-text="peso(subtotal)"></span></div>
                        <div class="flex items-center justify-between gap-2">
                            <button type="button" x-show="!showDiscount" @click="showDiscount = true; $nextTick(() => $refs.discountInput?.focus())" class="font-semibold text-primary hover:underline">+ Discount</button>
                            <span x-show="showDiscount" x-cloak class="text-muted">Discount</span>
                            <label x-show="showDiscount" x-cloak class="flex h-7 items-center rounded-md border border-border bg-white px-1.5 dark:border-gray-700 dark:bg-gray-900">
                                <span class="mr-0.5 text-[10px] text-muted">−{{ trim($currencySymbol) }}</span>
                                <input x-ref="discountInput" x-model.number="discount" type="number" min="0" step="0.01" aria-label="Discount" class="w-16 bg-transparent text-right text-xs outline-none">
                            </label>
                        </div>
                        <div x-show="vatEnabled && tax > 0" x-cloak class="flex justify-between"><span class="text-muted" x-text="`VAT (${vatRate}%)`"></span><span class="font-semibold" x-text="peso(tax)"></span></div>
                    </div>
                    <div class="flex items-end justify-between border-t border-border pt-2 dark:border-gray-800">
                        <span class="text-sm font-bold">Total</span>
                        <span class="font-serif text-2xl font-bold leading-none text-dark dark:text-white" x-text="currencySymbol + wholeMoney(total)"></span>
                    </div>

                    @if($isEditing)
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between"><span class="text-muted">Existing payments</span><span class="font-semibold" x-text="peso(paid)"></span></div>
                            <div class="flex justify-between font-bold"><span>Balance</span><span x-text="currencySymbol + wholeMoney(balance)"></span></div>
                        </div>
                    @else
                        {{-- Rider brought the cash in: amounts are fixed to what they collected. --}}
                        <div x-cloak x-show="riderCashCollected" class="space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-200">
                            <div class="flex justify-between"><span>Cash handed over by rider</span><span class="font-semibold">Cash</span></div>
                            <div class="flex justify-between"><span>Cash tendered by rider</span><span class="font-semibold" x-text="peso(paid)"></span></div>
                            <div class="flex justify-between"><span>Applied as paid amount</span><span class="font-semibold" x-text="peso(appliedPayment)"></span></div>
                            <div x-show="changeOnDelivery > 0" class="flex justify-between font-bold"><span>Change recorded for return</span><span x-text="peso(changeOnDelivery)"></span></div>
                            <p x-show="changeOnDelivery > 0" class="text-[10px] leading-relaxed">The change stays attached to the tagged job order for delivery.</p>
                        </div>

                        <div x-show="!riderCashCollected" class="space-y-2">
                            {{-- One choice covers both "paid now or later" and "how". --}}
                            <div class="grid grid-cols-3 gap-1.5" role="group" aria-label="Payment">
                                <button type="button" @click="choosePayment('cash')" class="h-9 rounded-lg border text-xs font-bold transition"
                                        :class="paymentMode === 'now' && paymentType === 'cash' ? 'border-dark bg-white text-dark ring-1 ring-dark dark:border-white dark:bg-gray-900 dark:text-white dark:ring-white' : 'border-border bg-white text-muted hover:text-dark dark:border-gray-700 dark:bg-gray-900 dark:hover:text-white'">Cash</button>
                                <button type="button" @click="choosePayment('gcash')" class="h-9 rounded-lg border text-xs font-bold transition"
                                        :class="paymentMode === 'now' && paymentType === 'gcash' ? 'border-dark bg-white text-dark ring-1 ring-dark dark:border-white dark:bg-gray-900 dark:text-white dark:ring-white' : 'border-border bg-white text-muted hover:text-dark dark:border-gray-700 dark:bg-gray-900 dark:hover:text-white'">GCash</button>
                                <button type="button" @click="choosePayLater()" class="h-9 rounded-lg border px-1 text-xs font-bold leading-tight transition"
                                        :class="paymentMode === 'later' ? 'border-dark bg-white text-dark ring-1 ring-dark dark:border-white dark:bg-gray-900 dark:text-white dark:ring-white' : 'border-border bg-white text-muted hover:text-dark dark:border-gray-700 dark:bg-gray-900 dark:hover:text-white'">Pay on pickup</button>
                            </div>

                            <div x-show="paymentMode === 'now'" x-cloak class="space-y-1.5">
                                <div x-show="paymentType === 'cash'" class="space-y-1.5">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <span class="text-[10px] text-muted">Received</span>
                                        <button type="button" @click="cashReceived = null; customCash = ''" class="h-7 rounded-md border px-1.5 text-[11px] font-bold"
                                                :class="cashReceived === null ? 'border-dark text-dark ring-1 ring-dark dark:border-white dark:text-white dark:ring-white' : 'border-border text-muted dark:border-gray-700'">Exact</button>
                                        <template x-for="amount in cashOptions" :key="amount">
                                            <button type="button" @click="cashReceived = amount; customCash = ''" class="h-7 rounded-md border px-1.5 text-[11px] font-bold"
                                                    :class="cashReceived === amount && customCash === '' ? 'border-dark text-dark ring-1 ring-dark dark:border-white dark:text-white dark:ring-white' : 'border-border text-muted dark:border-gray-700'"
                                                    x-text="currencySymbol + amount.toLocaleString('en-PH')"></button>
                                        </template>
                                        <input type="number" min="0" step="1" x-model="customCash" @input="cashReceived = customCash === '' ? null : Number(customCash)" placeholder="Other" aria-label="Other amount received"
                                               class="h-7 w-14 min-w-0 flex-1 rounded-md border border-border bg-white px-1.5 text-right text-[11px] outline-none focus:border-primary dark:border-gray-700 dark:bg-gray-900">
                                    </div>
                                    <div class="flex justify-between text-xs" x-show="changeDue > 0 || balanceDue === 0">
                                        <span class="text-muted">Change to give</span>
                                        <span class="font-bold text-emerald-700 dark:text-emerald-400" x-text="peso(changeDue)"></span>
                                    </div>
                                    <div class="flex justify-between text-xs" x-show="balanceDue > 0" x-cloak>
                                        <span class="text-amber-700 dark:text-amber-400">Short · balance on pickup</span>
                                        <span class="font-bold text-amber-700 dark:text-amber-400" x-text="peso(balanceDue)"></span>
                                    </div>
                                </div>

                                <div x-show="paymentType === 'gcash'" x-cloak class="space-y-1.5">
                                    <input name="payment_reference_no" value="{{ old('payment_reference_no') }}" placeholder="GCash reference no." aria-label="GCash reference number" class="h-8 w-full rounded-lg border border-border bg-white px-2.5 text-xs outline-none focus:border-primary dark:border-gray-700 dark:bg-gray-900">
                                    <label class="flex items-center justify-between gap-2 text-xs">
                                        <span class="text-muted">Amount sent</span>
                                        <input type="number" min="0" step="1" x-model="customGcash" @input="gcashAmount = customGcash === '' ? null : Number(customGcash)" :placeholder="wholeMoney(total)" class="h-7 w-24 rounded-md border border-border bg-white px-1.5 text-right text-xs outline-none focus:border-primary dark:border-gray-700 dark:bg-gray-900">
                                    </label>
                                    <div class="flex justify-between text-xs" x-show="balanceDue > 0" x-cloak>
                                        <span class="text-amber-700 dark:text-amber-400">Balance on pickup</span>
                                        <span class="font-bold text-amber-700 dark:text-amber-400" x-text="peso(balanceDue)"></span>
                                    </div>
                                </div>
                            </div>

                            <p x-show="paymentMode === 'later'" x-cloak class="flex justify-between text-xs">
                                <span class="text-muted">Collect on pickup</span>
                                <span class="font-bold" x-text="currencySymbol + wholeMoney(total)"></span>
                            </p>
                        </div>

                        <label class="flex items-start gap-2 text-[11px]" :class="canSendSms ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
                            <input type="checkbox" name="send_sms" value="1" :disabled="!canSendSms" :checked="canSendSms && sendSms" @change="sendSms = $event.target.checked" class="mt-0.5 rounded border-border text-primary">
                            <span>
                                <span class="block font-semibold">Send order received SMS</span>
                                <span x-show="!canSendSms" class="block text-muted" x-text="smsAvailabilityMessage"></span>
                            </span>
                        </label>
                    @endif

                    <button type="submit" :disabled="!canSubmit" class="h-11 w-full rounded-lg bg-primary-deep text-sm font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:bg-primary-soft dark:disabled:bg-gray-700"
                            x-text="submitting ? 'Saving...' : (isEditing ? 'Save changes' : (riderCashCollected ? 'Record Payment & Order' : 'Create order'))">{{ $isEditing ? 'Save changes' : 'Create order' }}</button>
                    <p class="text-center text-[11px]" :class="nextStepHint ? 'text-muted' : 'text-emerald-700 dark:text-emerald-400'" x-text="nextStepHint || readyHint"></p>
                </div>
            </aside>
        </div>

        {{-- Phone: the summary sits below the steps, so keep the total in reach. --}}
        <div class="fixed inset-x-0 bottom-0 z-20 flex items-center justify-between gap-3 border-t border-border bg-white/95 px-4 py-2.5 backdrop-blur md:hidden dark:border-gray-800 dark:bg-gray-900/95">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wide text-muted">Total</p>
                <p class="font-serif text-lg font-bold leading-none" x-text="currencySymbol + wholeMoney(total)"></p>
            </div>
            <button type="button" @click="$refs.summary.scrollIntoView({ behavior: 'smooth', block: 'start' })" class="h-10 rounded-lg bg-primary-deep px-4 text-sm font-bold text-white" x-text="nextStepHint ? 'Summary' : (isEditing ? 'Review & save' : 'Review & pay')"></button>
        </div>
    </form>

    {{-- Weight keypad for per-kilo (and per-liter) services. --}}
    <div x-cloak x-show="keypad.open" x-transition.opacity @keydown.window="keypad.open && keypadKey($event)"
         class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-3 backdrop-blur-sm sm:items-center"
         role="dialog" aria-modal="true" aria-labelledby="weight-keypad-title" @click.self="closeKeypad()">
        <div class="w-full max-w-sm rounded-2xl bg-white p-4 shadow-2xl dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted" x-text="keypadIsVinegar ? 'Smelly laundry?' : 'Weigh first'"></p>
                    <h2 id="weight-keypad-title" class="truncate font-serif text-lg font-bold" x-text="keypadIsVinegar ? 'Add vinegar' : keypad.service?.name"></h2>
                    <p x-show="!keypadIsVinegar" class="text-xs text-muted" x-text="keypad.service ? priceTag(keypad.service) + (keypadMinimum ? ` · min ${fmtQty(keypadMinimum)} ${keypadUnit}` : '') : ''"></p>
                    <p x-show="keypadIsVinegar" x-cloak class="text-xs text-muted">Free · how many ml?</p>
                </div>
                <button type="button" @click="keypadIsVinegar ? skipVinegar() : closeKeypad()" class="rounded-lg p-1.5 text-muted hover:bg-smoke dark:hover:bg-gray-800" aria-label="Close keypad">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <div class="mt-3 rounded-xl bg-smoke px-4 py-3 text-right ring-1 ring-border dark:bg-gray-950 dark:ring-gray-700">
                <p class="font-serif text-4xl font-bold tabular-nums leading-none">
                    <span x-text="keypad.value || '0'"></span>
                    <span class="text-lg text-muted" x-text="keypadUnit"></span>
                </p>
                <p class="mt-1 text-xs text-muted" x-show="keypadWeight > 0 && !keypadIsVinegar" x-text="'= ' + peso(keypadWeight * Number(keypad.service?.price || 0))"></p>
                <p class="mt-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400" x-show="keypadWeight > 0 && keypadIsVinegar" x-cloak>Free</p>
            </div>
            <div x-show="keypadIsVinegar" x-cloak class="mt-2 space-y-2">
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                   x-text="'Vinegar replaces the fabric conditioner: no Yen Yen'
                        + (fabconLines.length ? `, ${fabconLines.map(item => item.name).join(', ')} removed` : '')
                        + (fabconPresetLines.length ? `, and the fabcon in ${fabconPresetLines.map(item => item.name).join(', ')} is taken off` : '')
                        + '.'"></p>
                <div class="grid grid-cols-4 gap-2">
                    <template x-for="ml in [50, 100, 150, 200]" :key="ml">
                        <button type="button" @click="setVinegarMl(ml)" class="h-9 rounded-lg border text-xs font-bold"
                                :class="keypadWeight === ml ? 'border-primary-deep bg-primary-deep text-white' : 'border-border dark:border-gray-700'"
                                x-text="`${ml} ml`"></button>
                    </template>
                </div>
            </div>
            <p x-show="keypadBelowMinimum" x-cloak class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
               x-text="`Below the ${fmtQty(keypadMinimum)} ${keypadUnit} minimum. Charge the minimum (${peso(keypadMinimum * Number(keypad.service?.price || 0))}) or the actual weight.`"></p>

            <div class="mt-3 grid grid-cols-3 gap-2">
                <template x-for="key in ['1','2','3','4','5','6','7','8','9','.','0','back']" :key="key">
                    <button type="button" @click="keypadPress(key)" class="flex h-12 items-center justify-center rounded-xl bg-smoke text-lg font-bold ring-1 ring-border active:bg-primary/10 dark:bg-gray-800 dark:ring-gray-700"
                            :aria-label="key === 'back' ? 'Delete' : key">
                        <span x-show="key !== 'back'" x-text="key"></span>
                        <span x-show="key === 'back'" x-html="icon('delete', 'h-5 w-5')"></span>
                    </button>
                </template>
            </div>

            <div x-show="keypadIsVinegar" x-cloak class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" @click="skipVinegar()" class="h-11 rounded-lg border border-border text-sm font-bold dark:border-gray-700">No vinegar</button>
                <button type="button" @click="confirmKeypad(true)" :disabled="keypadWeight <= 0" class="h-11 rounded-lg bg-primary-deep text-sm font-bold text-white disabled:bg-primary-soft"
                        x-text="keypadWeight > 0 ? `Add ${fmtQty(keypadWeight)} ml vinegar` : 'Enter the ml'"></button>
            </div>
            <div x-show="!keypadIsVinegar" class="mt-3 grid gap-2" :class="keypadBelowMinimum ? 'grid-cols-2' : 'grid-cols-1'">
                <button type="button" x-show="keypadBelowMinimum" @click="confirmKeypad(false)" class="h-11 rounded-lg border border-border text-sm font-bold dark:border-gray-700"
                        x-text="`Charge ${fmtQty(keypadWeight)} ${keypadUnit}`"></button>
                <button type="button" @click="confirmKeypad(true)" :disabled="keypadWeight <= 0" class="h-11 rounded-lg bg-primary-deep text-sm font-bold text-white disabled:bg-primary-soft"
                        x-text="keypadBelowMinimum ? `Charge ${fmtQty(keypadMinimum)} ${keypadUnit} minimum` : (keypadWeight > 0 ? `Add ${fmtQty(keypadWeight)} ${keypadUnit}` : 'Enter the weight')"></button>
            </div>
            <button type="button" x-show="!keypadIsVinegar && keypad.service && lineFor(keypad.service)" @click="removeLine(keypad.service); closeKeypad()" class="mt-2 w-full text-center text-xs font-semibold text-red-600 hover:underline">Remove from order</button>
        </div>
    </div>

    @unless($isEditing)
        <div x-cloak x-show="tagModalOpen" x-transition.opacity @keydown.escape.window="tagModalOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
             role="dialog" aria-modal="true" aria-labelledby="pickup-tag-title" @click.self="tagModalOpen = false">
            <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted">House pickup by e-bike</p>
                        <h2 id="pickup-tag-title" class="font-serif text-lg font-bold">Load a pickup by bag tag</h2>
                        <p class="mt-1 text-xs text-muted">Search a bag tag, booking number, or customer. A rider's tag appears here within 5 seconds after they mark the bag collected.</p>
                    </div>
                    <button type="button" @click="tagModalOpen = false" class="rounded-lg p-1.5 hover:bg-smoke dark:hover:bg-gray-800" aria-label="Close tag lookup">
                        <span data-lucide="x" class="h-5 w-5"></span>
                    </button>
                </div>
                <form x-ref="tagLookupForm" method="GET" action="{{ route('admin.job-orders.create') }}" class="mt-4 space-y-3">
                    <input type="hidden" name="branch_id" :value="branchId">
                    <label for="pickup_tag_code" class="block text-xs font-semibold">Bag tag # or search</label>
                    <input id="pickup_tag_code" x-ref="pickupTagInput" name="tag_code" type="text" maxlength="40" autocomplete="off" required
                           x-model="tagSearch" @input="queueTagSearch()"
                           @keydown.enter.prevent="loadTagSelection()"
                           placeholder="Search tag, booking, or customer"
                           class="h-10 w-full rounded-lg border border-border bg-white px-3 font-mono text-sm uppercase dark:border-gray-700 dark:bg-gray-950">
                    <div class="rounded-lg border border-border dark:border-gray-700">
                        <div class="flex items-center justify-between border-b border-border px-3 py-2 text-xs font-semibold dark:border-gray-700">
                            <span x-text="tagSearch.trim() ? 'Matching pickups' : 'Oldest 9 waiting pickups'"></span>
                            <span class="text-muted"><span x-text="waitingTagCounts[branchId] ?? 0"></span> waiting</span>
                        </div>
                        <div class="max-h-60 overflow-y-auto p-1">
                            <p x-show="tagSuggestionsLoading" class="px-3 py-4 text-center text-xs text-muted">Searching tags...</p>
                            <p x-show="!tagSuggestionsLoading && tagSuggestionsError" x-text="tagSuggestionsError" class="px-3 py-4 text-center text-xs text-red-600"></p>
                            <p x-show="!tagSuggestionsLoading && !tagSuggestionsError && tagSuggestions.length === 0" class="px-3 py-4 text-center text-xs text-muted">No waiting pickups found.</p>
                            <template x-for="tag in tagSuggestions" :key="tag.id">
                                <button type="button" @click="window.location.assign(tag.url)"
                                        class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left hover:bg-smoke focus:bg-smoke focus:outline-none dark:hover:bg-gray-800 dark:focus:bg-gray-800">
                                    <span class="min-w-0">
                                        <span class="block font-mono text-sm font-semibold text-primary" x-text="tag.tag_code"></span>
                                        <span class="block truncate text-xs text-muted" x-text="`${tag.contact_name} · ${tag.reference_no}`"></span>
                                    </span>
                                    <span class="shrink-0 text-[10px] text-muted" x-text="tag.booked_at"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    @if($tagLookupError)
                        <p x-show="tagSearch.trim().toUpperCase() === @js($tagCode)" class="text-sm font-medium text-red-600" role="alert">{{ $tagLookupError }}</p>
                    @endif
                    <p x-show="tagSelectionHint" x-text="tagSelectionHint" class="text-xs font-medium text-amber-700" role="status"></p>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="tagModalOpen = false" class="h-10 rounded-lg border border-border px-4 text-sm font-semibold hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">Cancel</button>
                        <button type="button" @click="loadTagSelection()" class="h-10 rounded-lg bg-primary-deep px-4 text-sm font-semibold text-white hover:opacity-90">Load pickup</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Quick Add Customer --}}
        <div x-cloak x-show="quickCustomerOpen" x-transition.opacity @keydown.escape.window="quickCustomerOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
             role="dialog" aria-modal="true" aria-labelledby="quick-customer-title" @click.self="quickCustomerOpen = false">
            <div x-ref="quickCustomer" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-6 dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 id="quick-customer-title" class="inline-flex items-center gap-2 font-serif text-lg font-bold">
                        <span data-lucide="user-plus" class="h-5 w-5 text-primary"></span>
                        New customer
                    </h2>
                    <button type="button" @click="quickCustomerOpen = false" class="rounded-lg p-2 hover:bg-smoke dark:hover:bg-gray-800" aria-label="Close">
                        <span data-lucide="x" class="h-5 w-5"></span>
                    </button>
                </div>

                @include('admin.customers.partials.form', [
                    'action' => route('admin.job-orders.customers.store'),
                    'method' => 'POST',
                    'customer' => new \App\Models\Customer(['branch_id' => $branchId, 'billing_type' => 'regular', 'is_active' => true, 'unpaid_limit' => 0]),
                    'redirectTo' => 'pos',
                    'branchModel' => 'branchId',
                ])
            </div>
        </div>
    @endunless
</div>

<script>
function posPage(branches, processingBranches, services, customers, serviceCategories, servicePresets, vatRate, vatEnabled, initialState = {}) {
    return {
        isEditing: Boolean(initialState.isEditing),
        branchId: @js((string) $branchId),
        branches: branches || [],
        processingBranches: processingBranches || [],
        processingBranchId: initialState.processingBranchId || '',
        services: services || [],
        servicePresets: servicePresets || [],
        customers: customers || [],
        serviceCategories: serviceCategories || [],
        addonCategoryIds: (initialState.addonCategoryIds || []).map(String),
        reportCategories: initialState.reportCategories || {},
        currencySymbol: initialState.currencySymbol || '₱',
        items: initialState.initialItems || [],
        paymentType: initialState.paymentType || 'unpaid',
        paymentChosen: Boolean(initialState.paymentChosen),
        cashReceived: null,
        customCash: '',
        gcashAmount: null,
        customGcash: '',
        discount: Number(initialState.discount || 0),
        showDiscount: Number(initialState.discount || 0) > 0,
        paid: Number(initialState.paid || 0),
        riderCashCollected: Boolean(initialState.riderCashCollected),
        transactionType: initialState.transactionType || 'walk_in',
        readyByKey: '',
        readyByLabel: '',
        readyByTouched: false,
        keptWasRush: false,
        initialRush: Boolean(initialState.isRush),
        notesText: '',
        noteTags: ['Separate colors', 'Delicate wash', 'Hang dry only', 'Extra Downy', 'Stain treatment', 'Customer detergent', 'Hand wash', 'Fold only'],
        sendSms: true,
        submitting: false,
        editIndex: null,
        bookingLoaded: @js((bool) $booked),
        // step: 'weight' for the scale, then 'vinegar' to ask about smelly laundry.
        keypad: { open: false, service: null, value: '', step: 'weight' },
        recentIds: [],
        tagModalOpen: @js((bool) ($tagLookupError ?? null)),
        tagSearch: @js($tagCode ?? ''),
        tagSuggestions: [],
        tagSuggestionsLoading: false,
        tagRefreshing: false,
        tagSuggestionsError: '',
        tagSelectionHint: '',
        tagRequestId: 0,
        tagSearchTimer: null,
        tagPollTimer: null,
        waitingTagCounts: @js($waitingTagCounts ?? []),
        quickCustomerOpen: @js($errors->any() && old('redirect_to') === 'pos'),
        customerOpen: false,
        customerSearch: '',
        selectedCustomerId: initialState.selectedCustomerId || '',
        serviceSearch: '',
        typeFilter: 'all',
        vatRate: vatRate || 0,
        vatEnabled: vatEnabled || false,

        init() {
            if (!this.processingBranchId) {
                this.setDefaultProcessingBranch();
            }
            this.syncSelectedCustomer();
            this.restoreNotes(initialState.notes || '');
            this.restorePayment();
            this.loadRecentCustomers();

            if (this.tagModalOpen) {
                this.loadTagSuggestions();
                this.$nextTick(() => this.$refs.pickupTagInput?.focus());
            }
            if (!this.isEditing) {
                this.tagPollTimer = window.setInterval(() => this.pollTags(), 5000);
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) this.pollTags();
                });
                window.addEventListener('online', () => this.pollTags());
                this.pollTags();
            }

            // Vinegar cancels the fabcon inside packages too, so keep their prices in step.
            this.$watch('items', () => this.syncPresetFabcon());
            this.syncPresetFabcon();

            this.$watch('branchId', () => {
                if (this.isEditing) {
                    return;
                }

                if (this.tagModalOpen) {
                    this.loadTagSuggestions();
                } else {
                    this.pollTags();
                }

                this.items = [];
                this.discount = 0;
                this.serviceSearch = '';
                this.typeFilter = 'all';
                this.setDefaultProcessingBranch();
                this.loadRecentCustomers();

                const selected = this.selectedCustomer;
                if (selected && String(selected.branch_id) !== String(this.branchId)) {
                    this.clearCustomer();
                }
            });

            this.$nextTick(() => this.refreshIcons());
        },
        destroy() {
            if (this.tagPollTimer) window.clearInterval(this.tagPollTimer);
            clearTimeout(this.tagSearchTimer);
        },

        // ---- Order steps -------------------------------------------------
        get customerDone() { return Boolean(this.selectedCustomer); },
        get servicesDone() { return this.items.length > 0; },
        get paymentDone() { return this.isEditing || this.riderCashCollected || this.paymentChosen; },
        get timingDone() { return Boolean(this.transactionType && this.readyByKey); },
        get checklist() {
            return [
                { label: 'Customer', done: this.customerDone },
                { label: 'Services', done: this.servicesDone },
                { label: 'Payment', done: this.servicesDone && this.paymentDone },
            ];
        },
        get nextStepHint() {
            if (!this.customerDone) return 'Step 1: choose a customer';
            if (!this.servicesDone) return 'Step 2: tap a service';
            if (this.items.some(item => !(Number(item.quantity) > 0))) return 'Enter a quantity for every line';
            if (!this.paymentDone) return 'Choose Paid now or Pay on pickup';
            return '';
        },
        get readyHint() {
            if (this.isEditing) return 'Ready to save';
            if (this.paymentMode === 'later' || this.balanceDue > 0) return `Ready · ${this.currencySymbol}${this.wholeMoney(this.balanceDue)} to collect on pickup`;
            if (this.paymentType === 'cash' && this.changeDue > 0) return `Ready · give ${this.peso(this.changeDue)} change`;
            return 'Ready · fully paid';
        },
        get canSubmit() {
            return !this.submitting && this.nextStepHint === '';
        },
        submitOrder(event) {
            if (!this.canSubmit) {
                event.preventDefault();
                return;
            }
            this.rememberCustomer(this.selectedCustomerId);
            this.submitting = true;

            // A refused save comes back with its reasons and the cart in the
            // session, kept for the very next request only. Stop the polls and
            // let any already in flight land first, so none of them uses it up.
            window.appSaving = true;
            // If the page never leaves (connection lost), let the polls resume.
            setTimeout(() => window.appSaving = false, 15000);
            const pending = window.appPolls || [];
            if (pending.length) {
                event.preventDefault();
                const form = event.target;
                Promise.allSettled(pending).then(() => form.submit());
            }
        },
        clearOrder() {
            if (this.bookingLoaded) {
                window.location.assign(@js(route('admin.job-orders.create')) + '?branch_id=' + encodeURIComponent(this.branchId));
                return;
            }
            if ((this.items.length || this.selectedCustomerId || this.notesText.trim()) && !window.confirm('Clear this order and start over?')) {
                return;
            }
            this.items = [];
            this.editIndex = null;
            this.clearCustomer();
            this.discount = 0;
            this.showDiscount = false;
            this.paymentChosen = false;
            this.paymentType = 'unpaid';
            this.cashReceived = null;
            this.customCash = '';
            this.gcashAmount = null;
            this.customGcash = '';
            this.transactionType = 'walk_in';
            this.readyByKey = '';
            this.readyByLabel = '';
            this.readyByTouched = false;
            this.initialRush = false;
            this.notesText = '';
            this.serviceSearch = '';
            this.typeFilter = 'all';
        },

        // ---- Customer ----------------------------------------------------
        get availableCustomers() {
            return this.customers.filter(customer => String(customer.branch_id) === String(this.branchId));
        },
        get selectedCustomer() {
            return this.customers.find(customer => String(customer.id) === String(this.selectedCustomerId));
        },
        get filteredCustomers() {
            const term = this.customerSearch.toLowerCase().trim();
            const digits = term.replace(/\D/g, '');

            return this.availableCustomers.filter(customer => {
                const billing = this.formatBilling(customer.billing_type).toLowerCase();
                const phone = String(customer.phone || '');

                return !term
                    || customer.name.toLowerCase().includes(term)
                    || phone.toLowerCase().includes(term)
                    || (digits.length >= 3 && phone.replace(/\D/g, '').includes(digits))
                    || billing.includes(term);
            }).slice(0, 30);
        },
        get recentCustomers() {
            return this.recentIds
                .map(id => this.availableCustomers.find(customer => String(customer.id) === String(id)))
                .filter(Boolean)
                .slice(0, 4);
        },
        recentKey() {
            return `pos:recent-customers:${this.branchId}`;
        },
        loadRecentCustomers() {
            try {
                const stored = JSON.parse(localStorage.getItem(this.recentKey()) || '[]');
                this.recentIds = Array.isArray(stored) ? stored : [];
            } catch (_) {
                this.recentIds = [];
            }
        },
        rememberCustomer(id) {
            if (!id) return;
            const ids = [String(id), ...this.recentIds.map(String).filter(existing => existing !== String(id))].slice(0, 8);
            try {
                localStorage.setItem(this.recentKey(), JSON.stringify(ids));
            } catch (_) {
                // Recent customers are a convenience; the order saves without them.
            }
        },
        selectCustomer(customer) {
            this.selectedCustomerId = customer.id;
            this.customerSearch = customer.name;
            this.customerOpen = false;
        },
        clearCustomer() {
            this.selectedCustomerId = '';
            this.customerSearch = '';
            this.customerOpen = false;
        },
        syncSelectedCustomer() {
            if (this.selectedCustomer) {
                this.selectCustomer(this.selectedCustomer);
            }
        },
        openQuickCustomer() {
            this.customerOpen = false;
            this.quickCustomerOpen = true;
            this.$nextTick(() => {
                this.refreshIcons();
                // Start the form with whatever was typed into the search.
                const term = this.customerSearch.trim();
                const form = this.$refs.quickCustomer;
                const target = form?.querySelector(/^[\d\s+()-]{4,}$/.test(term) ? 'input[name="phone"]' : 'input[name="name"]');
                if (term && target && !target.value) target.value = term;
                form?.querySelector('input[name="name"]')?.focus();
            });
        },
        chooseWalkIn() {
            if (!this.bookingLoaded) return;
            if (window.confirm('Unload this pickup and take a walk-in order instead?')) {
                window.location.assign(@js(route('admin.job-orders.create')) + '?branch_id=' + encodeURIComponent(this.branchId));
            }
        },
        initials(name) {
            return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join('');
        },
        maskPhone(phone) {
            const digits = String(phone || '').replace(/\D/g, '');
            return digits.length >= 8 ? `${digits.slice(0, 4)} ••• ${digits.slice(-4)}` : String(phone || '');
        },
        get canSendSms() {
            return Boolean(this.selectedCustomer?.phone);
        },
        get smsAvailabilityMessage() {
            if (!this.selectedCustomer?.phone) {
                return 'Add a customer phone number to enable SMS.';
            }

            return 'Tell the customer their laundry was received and queued.';
        },
        formatBilling(value) {
            if (value === 'monthly_billing') return 'Legacy Billing';
            return String(value || 'regular').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
        },

        // ---- Branches ----------------------------------------------------
        get selectedBranch() {
            return this.branches.find(branch => String(branch.id) === String(this.branchId));
        },
        setDefaultProcessingBranch() {
            const branch = this.selectedBranch;
            const fullService = this.processingBranches.find(option => String(option.id) === String(this.branchId));

            if (branch && branch.branch_type !== 'pickup_dropoff' && fullService) {
                this.processingBranchId = fullService.id;
                return;
            }

            this.processingBranchId = this.processingBranches[0]?.id || '';
        },

        // ---- Catalog -----------------------------------------------------
        get serviceTypes() {
            const all = { value: 'all', label: 'All' };
            const cats = this.serviceCategories
                .filter(c => c.visibility === 'all' || String(c.branch_id) === String(this.branchId))
                .map(c => ({ value: c.id, label: c.name }));
            return [all, ...cats];
        },
        get availableServices() {
            return this.services.filter(service => String(service.branch_id) === String(this.branchId));
        },
        get availablePresets() {
            return this.servicePresets.filter(preset => String(preset.branch_id) === String(this.branchId) && preset.items.length > 0);
        },
        get filteredPresets() {
            const term = this.serviceSearch.toLowerCase().trim();
            return this.availablePresets.filter(preset => {
                const matchesType = this.typeFilter === 'all' || String(preset.service_category_id) === String(this.typeFilter);
                const matchesSearch = !term
                    || preset.name.toLowerCase().includes(term)
                    || preset.items.some(item => item.name.toLowerCase().includes(term));
                return matchesType && matchesSearch;
            });
        },
        get filteredServices() {
            const term = this.serviceSearch.toLowerCase().trim();
            const rush = this.rushService;
            return this.availableServices.filter(service => {
                const matchesType = this.typeFilter === 'all' || String(service.service_category_id) === String(this.typeFilter);
                const matchesSearch = !term || service.name.toLowerCase().includes(term);
                return matchesType && matchesSearch && String(service.id) !== String(rush?.id);
            });
        },
        get kiloServices() { return this.filteredServices.filter(s => !this.isAddon(s) && s.pricing_type === 'kilo'); },
        get loadServices() { return this.filteredServices.filter(s => !this.isAddon(s) && s.pricing_type === 'load'); },
        get pieceServices() { return this.filteredServices.filter(s => !this.isAddon(s) && s.pricing_type === 'piece'); },
        get otherServices() { return this.filteredServices.filter(s => !this.isAddon(s) && !['kilo', 'load', 'piece'].includes(s.pricing_type)); },
        get addonGroups() {
            const addons = this.filteredServices.filter(s => this.isAddon(s));
            return [
                { key: 'detergent', label: 'Detergent' },
                { key: 'fabcon', label: 'Fabric conditioner' },
                { key: 'vinegar', label: 'Smelly laundry' },
                { key: 'extras', label: 'Extras' },
            ]
                .map(group => ({ ...group, services: addons.filter(s => this.addonKind(s) === group.key) }))
                .filter(group => group.services.length);
        },
        get catalogEmpty() {
            return this.filteredServices.length === 0 && this.filteredPresets.length === 0;
        },
        /**
         * Every group shares one set of columns, as many as the largest group
         * has tiles, so the tiles fill the row instead of stopping short of it.
         * A narrow panel falls back to as many 9.5rem tiles as fit.
         */
        get catalogGridStyle() {
            const columns = Math.max(1, ...this.serviceGroups.map(group => group.entries.length));
            const gap = 'var(--catalog-gap, 0.375rem)';
            return `grid-template-columns: repeat(auto-fill, minmax(max(9.5rem, calc((100% - ${columns - 1} * ${gap}) / ${columns} - 0.5px)), 1fr))`;
        },
        /** Laundry services and packages, grouped by how they are priced, as tiles. */
        get serviceGroups() {
            const tones = {
                kilo: { icon: 'bg-[#F6E7D6] text-[#8A5A33] dark:bg-[#8A5A33]/25 dark:text-cane', price: 'text-[#7A4B2A] dark:text-cane' },
                load: { icon: 'bg-[#E3F1F0] text-[#2F6F73] dark:bg-[#2F6F73]/30 dark:text-[#9FD3D0]', price: 'text-[#1F4E5F] dark:text-[#9FD3D0]' },
                piece: { icon: 'bg-[#ECE6F5] text-[#5B3F8C] dark:bg-[#5B3F8C]/30 dark:text-[#CBB8EC]', price: 'text-[#5B3F8C] dark:text-[#CBB8EC]' },
                packages: { icon: 'bg-primary text-white', price: 'text-primary-deep dark:text-primary-soft' },
                other: { icon: 'bg-smoke text-primary-deep ring-1 ring-border dark:bg-gray-800 dark:text-primary-soft dark:ring-gray-700', price: 'text-primary-deep dark:text-primary-soft' },
            };
            const fromService = service => ({
                key: `s${service.id}`,
                service,
                name: service.name,
                icon: this.serviceIcon(service),
                price: this.priceTag(service),
                detail: this.serviceDetail(service),
                line: () => this.lineFor(service),
                badge: () => this.qtyLabel(this.lineFor(service)),
            });
            const fromPreset = preset => ({
                key: `p${preset.id}`,
                preset,
                name: preset.name,
                icon: 'package',
                price: this.peso(this.presetTotal(preset)),
                detail: this.presetSummary(preset),
                line: () => this.presetLine(preset),
                badge: () => '×' + this.fmtQty(this.presetLine(preset)?.quantity),
            });

            return [
                { key: 'kilo', label: 'Per kilo', hint: 'weigh first', tone: tones.kilo, entries: this.kiloServices.map(fromService) },
                { key: 'load', label: 'Per load', hint: '', tone: tones.load, entries: this.loadServices.map(fromService) },
                { key: 'piece', label: 'Per piece', hint: '', tone: tones.piece, entries: this.pieceServices.map(fromService) },
                { key: 'packages', label: 'Packages', hint: '', tone: tones.packages, entries: this.filteredPresets.map(fromPreset) },
                { key: 'other', label: 'Other', hint: '', tone: tones.other, entries: this.otherServices.map(fromService) },
            ].filter(group => group.entries.length);
        },
        reportCategory(service) {
            return String(this.reportCategories[service.id] ?? service.report_category ?? '');
        },
        isAddon(service) {
            return this.addonCategoryIds.includes(String(service.service_category_id))
                || ['detergent', 'fabcon'].includes(this.reportCategory(service));
        },
        addonKind(service) {
            const category = this.reportCategory(service);
            const name = String(service.name || '').toLowerCase();
            if (category === 'vinegar' || /vinegar/.test(name)) return 'vinegar';
            if (category === 'fabcon' || /fabcon|fabric|conditioner|downy|comfort/.test(name)) return 'fabcon';
            if (category === 'detergent' || /detergent|ariel|tide|surf|breeze|champion/.test(name)) return 'detergent';
            return 'extras';
        },
        /** Free vinegar for smelly laundry; it replaces the fabric conditioner. */
        get vinegarService() {
            return this.availableServices.find(service => this.isAddon(service) && this.addonKind(service) === 'vinegar') || null;
        },
        get vinegarLine() {
            return this.vinegarService ? this.lineFor(this.vinegarService) : null;
        },
        get fabconLines() {
            return this.items.filter(item => {
                return item.type !== 'preset' && this.isFabconService(this.serviceById(item.id));
            });
        },
        isFabconService(service) {
            return Boolean(service) && this.isAddon(service) && this.addonKind(service) === 'fabcon';
        },
        presetById(id) {
            return this.servicePresets.find(preset => String(preset.id) === String(id)) || null;
        },
        /** Price of the fabcon inside one package. */
        presetFabconTotal(preset) {
            return preset.items
                .filter(item => this.isFabconService(this.serviceById(item.id)))
                .reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.price || 0), 0);
        },
        /** Package lines in the cart that include a fabcon. */
        get fabconPresetLines() {
            return this.items.filter(item => {
                const preset = item.type === 'preset' ? this.presetById(item.id) : null;
                return preset && this.presetFabconTotal(preset) > 0;
            });
        },
        /** With vinegar on the order, each package is charged without its fabcon. */
        syncPresetFabcon() {
            const withoutFabcon = Boolean(this.vinegarLine);
            this.items.forEach(item => {
                if (item.type !== 'preset') return;
                const preset = this.presetById(item.id);
                if (!preset) return;
                const fabcon = this.presetFabconTotal(preset);
                const price = this.presetTotal(preset) - (withoutFabcon ? fabcon : 0);
                const summary = this.presetSummary(preset) + (withoutFabcon && fabcon > 0 ? ' · no fabcon (vinegar)' : '');
                if (item.price !== price) item.price = price;
                if (item.summary !== summary) item.summary = summary;
            });
        },
        get rushService() {
            return this.availableServices.find(service => /\brush\b/i.test(service.name)) || null;
        },
        serviceById(id) {
            return this.services.find(service => String(service.id) === String(id));
        },
        lineFor(service) {
            return this.items.find(item => item.type !== 'preset' && String(item.id) === String(service.id)) || null;
        },
        presetLine(preset) {
            return this.items.find(item => item.type === 'preset' && String(item.id) === String(preset.id)) || null;
        },
        unitShort(service) {
            // Some catalogs put a whole description in the unit label
            // ("Comforter Full Services (Min 5KG)"); only a short word reads as a unit.
            const label = String(service?.price_unit_label || '').replace(/^per\s+/i, '').trim();
            if (label && label.length <= 8) return label;
            return { kilo: 'kg', load: 'load', piece: 'pc', ml: 'ml', liter: 'L' }[service?.pricing_type] || '';
        },
        priceTag(service) {
            const unit = this.unitShort(service);
            return this.peso(service.price) + (unit ? ` / ${unit}` : '');
        },
        addonPrice(service) {
            if (Number(service.price) === 0) return 'Free';
            const unit = this.unitShort(service);
            return '+' + this.peso(service.price) + (unit ? `/${unit}` : '');
        },
        serviceDetail(service) {
            // The price already names the unit; this only adds what it can't say.
            if (service.pricing_type === 'kilo' && Number(service.minimum_kilos || 0) > 0) {
                return `min ${this.fmtQty(service.minimum_kilos)} kg`;
            }
            return '';
        },
        serviceIcon(service) {
            const name = String(service.name || '').toLowerCase();

            if (name.includes('comforter') || name.includes('duvet') || name.includes('blanket')) return 'bed-double';
            if (name.includes('sheet'))                                  return 'bed';
            if (name.includes('towel'))                                  return 'bath';
            if (name.includes('uniform') || name.includes('steam') || name.includes('iron')) return 'shirt';
            if (name.includes('delivery'))                               return 'truck';
            if (name.includes('dry clean'))                              return 'sparkles';
            if (name.includes('dry') || name.includes('dryer'))          return 'wind';
            if (name.includes('wash only'))                              return 'washing-machine';
            if (name.includes('bleach') || name.includes('stain') || name.includes('vinegar')) return 'flask-conical';
            if (name.includes('hang'))                                   return 'shirt';
            if (name.includes('shoe'))                                   return 'footprints';
            if (name.includes('carpet'))                                 return 'layout-grid';
            if (this.isAddon(service)) return this.addonKind(service) === 'detergent' ? 'droplets' : 'sparkles';
            return { kilo: 'shirt', load: 'washing-machine', piece: 'shirt' }[service.pricing_type] || 'package';
        },
        icon(name, className) {
            return typeof window.lucideSvg === 'function' ? window.lucideSvg(name, className) : '';
        },
        refreshIcons() {
            this.$nextTick(() => {
                if (typeof window.renderLucideIcons === 'function') {
                    window.renderLucideIcons();
                }
            });
        },

        // ---- Cart --------------------------------------------------------
        usesKeypad(item) {
            return item.type !== 'preset' && ['kilo', 'liter'].includes(item.pricing_type);
        },
        tapService(service) {
            if (['kilo', 'liter'].includes(service.pricing_type)) {
                this.openKeypad(service);
                return;
            }
            this.add(service);
        },
        add(service, quantity = null) {
            const existing = this.lineFor(service);
            if (existing) {
                existing.quantity = quantity ?? Number(existing.quantity) + 1;
                return;
            }
            this.items.push({
                type: 'service',
                id: service.id,
                name: service.name,
                pricing_type: service.pricing_type,
                minimum_kilos: service.minimum_kilos === null || service.minimum_kilos === undefined ? null : Number(service.minimum_kilos),
                quantity: quantity ?? this.defaultQuantity(service),
                price: Number(service.price),
            });
        },
        removeLine(service) {
            this.editIndex = null;
            this.items = this.items.filter(item => !(item.type !== 'preset' && String(item.id) === String(service.id)));
        },
        toggleAddon(service) {
            if (this.addonKind(service) === 'vinegar') {
                this.openVinegar();
                return;
            }
            // Sold by weight (e.g. hand wash per kilo): weigh it like a laundry
            // service rather than charging one unit. The keypad can also remove it.
            if (this.usesKeypad({ type: 'service', pricing_type: service.pricing_type })) {
                this.openKeypad(service);
                return;
            }
            if (this.lineFor(service)) {
                this.removeLine(service);
                return;
            }
            // Fabric conditioner and vinegar don't go together.
            if (this.addonKind(service) === 'fabcon' && this.vinegarLine) {
                this.removeLine(this.vinegarService);
            }
            this.add(service);
        },
        addPreset(preset) {
            const existing = this.presetLine(preset);
            if (existing) {
                existing.quantity = Number(existing.quantity || 0) + 1;
            } else {
                this.items.push({
                    type: 'preset',
                    id: preset.id,
                    name: preset.name,
                    summary: this.presetSummary(preset),
                    quantity: 1,
                    price: this.presetTotal(preset),
                });
            }
        },
        /** Does the keyed-in amount still agree with what the customer booked? */
        matchesBooking(item) {
            if (!item.booked) {
                return true;
            }

            return Math.abs(Number(item.quantity || 0) - Number(item.booked.quantity || 0)) < 0.005;
        },
        bookedNote(item) {
            if (!item.booked) {
                return '';
            }

            const minimum = item.booked.minimum ? ` (charged at the ${item.booked.minimum} kg minimum)` : '';

            return this.matchesBooking(item)
                ? `Customer booked ${item.booked.label}${minimum}, matches`
                : `Customer booked ${item.booked.label}${minimum}, you have ${Number(item.quantity || 0)}`;
        },
        presetTotal(preset) {
            return preset.items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.price || 0)), 0);
        },
        presetSummary(preset) {
            return preset.items.map(item => `${Number(item.quantity || 0).toFixed(2).replace(/\.?0+$/, '')}x ${item.name}`).join(', ');
        },
        quantityStep(item) {
            return item.type === 'service' && ['kilo', 'liter'].includes(item.pricing_type) ? 0.1 : 1;
        },
        defaultQuantity(service) {
            if (service.pricing_type === 'kilo' && Number(service.minimum_kilos || 0) > 0) {
                return Number(service.minimum_kilos);
            }

            return 1;
        },
        increaseQuantity(item) {
            const step = this.quantityStep(item);
            item.quantity = Math.round((Number(item.quantity || 0) + step) * 100) / 100;
        },
        decreaseQuantity(item) {
            const step = this.quantityStep(item);
            item.quantity = Math.max(Math.round((Number(item.quantity || 0) - step) * 100) / 100, step);
        },
        qtyLabel(item) {
            if (item.type === 'preset') return `×${this.fmtQty(item.quantity)}`;
            const service = this.serviceById(item.id);
            const type = item.pricing_type || service?.pricing_type;
            const quantity = this.fmtQty(item.quantity);
            if (type === 'kilo') return `${quantity} kg`;
            if (type === 'liter') return `${quantity} L`;
            if (type === 'ml') return `${quantity} ml`;
            if (type === 'load') return `${quantity} ${Number(item.quantity) === 1 ? 'load' : 'loads'}`;
            const unit = this.unitShort(service);
            return unit && type === 'piece' ? `${quantity} ${unit}` : `×${quantity}`;
        },
        lineCaption(item) {
            return `${this.qtyLabel(item).replace(/^×/, '')} × ${this.peso(item.price)}`;
        },

        // ---- Weight keypad -----------------------------------------------
        openKeypad(service) {
            if (!service) return;
            const line = this.lineFor(service);
            this.keypad = { open: true, service, value: line ? this.fmtQty(line.quantity) : '', step: 'weight' };
        },
        closeKeypad() {
            this.keypad = { open: false, service: null, value: '', step: 'weight' };
        },
        /** Ask how many ml of vinegar, in the same keypad. */
        openVinegar() {
            const service = this.vinegarService;
            if (!service) {
                this.closeKeypad();
                return;
            }
            const line = this.lineFor(service);
            this.keypad = { open: true, service, value: line ? this.fmtQty(line.quantity) : '', step: 'vinegar' };
        },
        get keypadIsVinegar() {
            return this.keypad.step === 'vinegar';
        },
        setVinegarMl(ml) {
            this.keypad.value = String(ml);
        },
        /** "No vinegar": drop any vinegar already on the order. */
        skipVinegar() {
            if (this.vinegarLine) this.removeLine(this.vinegarService);
            this.closeKeypad();
        },
        get keypadUnit() {
            if (this.keypadIsVinegar) return 'ml';
            return this.keypad.service?.pricing_type === 'liter' ? 'L' : 'kg';
        },
        get keypadWeight() {
            return Number(this.keypad.value) || 0;
        },
        get keypadMinimum() {
            return !this.keypadIsVinegar && this.keypad.service?.pricing_type === 'kilo' ? Number(this.keypad.service.minimum_kilos || 0) : 0;
        },
        get keypadBelowMinimum() {
            return this.keypadWeight > 0 && this.keypadMinimum > 0 && this.keypadWeight < this.keypadMinimum;
        },
        keypadPress(key) {
            let value = this.keypad.value;
            if (key === 'back') {
                value = value.slice(0, -1);
            } else if (key === '.') {
                if (value.includes('.')) return;
                value = (value || '0') + '.';
            } else {
                if (/\.\d{2}$/.test(value) || value.replace('.', '').length >= 5) return;
                value = value === '0' ? key : value + key;
            }
            this.keypad.value = value;
        },
        keypadKey(event) {
            if (/^[0-9]$/.test(event.key)) this.keypadPress(event.key);
            else if (event.key === '.' || event.key === ',') this.keypadPress('.');
            else if (event.key === 'Backspace') this.keypadPress('back');
            else if (event.key === 'Enter') this.confirmKeypad(true);
            else if (event.key === 'Escape') this.keypadIsVinegar ? this.skipVinegar() : this.closeKeypad();
            else return;
            event.preventDefault();
        },
        confirmKeypad(useMinimum) {
            if (this.keypadWeight <= 0) return;
            if (this.keypadIsVinegar) {
                // Vinegar is free and takes the place of every fabric conditioner.
                this.fabconLines.forEach(item => this.removeLine(this.serviceById(item.id)));
                this.add(this.keypad.service, Math.round(this.keypadWeight * 100) / 100);
                this.lineFor(this.keypad.service).price = 0;
                this.closeKeypad();
                return;
            }
            const service = this.keypad.service;
            const quantity = useMinimum ? Math.max(this.keypadWeight, this.keypadMinimum) : this.keypadWeight;
            this.add(service, Math.round(quantity * 100) / 100);
            // After weighing a laundry service, ask if it's smelly and needs vinegar.
            if (service.pricing_type === 'kilo' && !this.isAddon(service) && this.vinegarService) {
                this.openVinegar();
                return;
            }
            this.closeKeypad();
        },

        // ---- Return & timing ---------------------------------------------
        get readyOptions() {
            const now = new Date();
            const rush = this.rushService;
            return [
                { key: 'today', title: 'Today', sub: 'by 6 PM', disabled: now.getHours() >= 17 },
                { key: 'tomorrow-am', title: 'Tomorrow', sub: 'by 10 AM', disabled: false },
                { key: 'tomorrow-pm', title: 'Tomorrow', sub: 'by 4 PM', disabled: false },
                { key: 'rush', title: 'Rush', sub: rush ? `4 hrs · +${this.peso(rush.price)}` : '4 hrs', disabled: false },
            ];
        },
        get readyByText() {
            if (this.readyByLabel) return this.readyByLabel;
            return this.isRush ? 'Rush' : '';
        },
        get isRush() {
            return this.readyByKey === 'rush' || (this.readyByKey === 'kept' && this.keptWasRush) || (!this.readyByTouched && this.initialRush);
        },
        readyDate(key) {
            const date = new Date();
            if (key === 'rush') {
                date.setMinutes(date.getMinutes() + 240);
                date.setMinutes(Math.ceil(date.getMinutes() / 15) * 15, 0, 0);
                return date;
            }
            if (key !== 'today') date.setDate(date.getDate() + 1);
            date.setHours({ today: 18, 'tomorrow-am': 10, 'tomorrow-pm': 16 }[key], 0, 0, 0);
            return date;
        },
        formatReady(date) {
            return date.toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        },
        setReadyBy(key) {
            this.readyByTouched = true;
            this.readyByKey = this.readyByKey === key ? '' : key;
            this.readyByLabel = this.readyByKey
                ? this.formatReady(this.readyDate(this.readyByKey)) + (this.readyByKey === 'rush' ? ' (Rush)' : '')
                : '';
            this.syncRushFee();
        },
        /** A branch that sells rush as a service gets that fee with the Rush option. */
        syncRushFee() {
            const rush = this.rushService;
            if (!rush) return;
            if (this.isRush && !this.lineFor(rush)) this.add(rush);
            if (!this.isRush && this.lineFor(rush)) this.removeLine(rush);
        },
        // There is no due-date column, so the promised time travels as the
        // first line of the order notes, where the job order page shows it.
        restoreNotes(notes) {
            // Submitted forms carry CRLF line breaks.
            const lines = String(notes).split(/\r?\n/);
            const match = lines[0]?.match(/^Ready by:\s*(.+)$/);
            if (match) {
                lines.shift();
                this.readyByKey = 'kept';
                this.readyByLabel = match[1].trim();
                this.keptWasRush = this.initialRush;
            } else if (this.initialRush) {
                this.readyByKey = 'rush';
            }
            this.notesText = lines.join('\n');
        },
        get composedNotes() {
            const line = this.readyByKey && (this.readyByKey === 'kept' || this.readyByTouched)
                ? `Ready by: ${this.readyByLabel}`
                : '';
            return [line, this.notesText.trim()].filter(Boolean).join('\n');
        },
        appendNote(tag) {
            const current = (this.notesText || '').trim();
            if (!current) {
                this.notesText = tag;
            } else if (!current.toLowerCase().includes(tag.toLowerCase())) {
                this.notesText = current + ', ' + tag;
            }
        },

        // ---- Payment -----------------------------------------------------
        restorePayment() {
            if (this.isEditing || !this.paymentChosen) return;
            if (this.paymentType === 'cash' && this.paid > 0) {
                this.cashReceived = this.paid;
                this.customCash = String(this.paid);
            }
            if (this.paymentType === 'gcash' && this.paid > 0) {
                this.gcashAmount = this.paid;
                this.customGcash = String(this.paid);
            }
        },
        get paymentMode() {
            if (!this.paymentChosen) return '';
            return this.paymentType === 'unpaid' ? 'later' : 'now';
        },
        choosePayment(type) {
            this.paymentChosen = true;
            this.paymentType = type;
        },
        choosePaidNow() {
            this.paymentChosen = true;
            if (!['cash', 'gcash'].includes(this.paymentType)) this.paymentType = 'cash';
        },
        choosePayLater() {
            this.paymentChosen = true;
            this.paymentType = 'unpaid';
        },
        get paymentTypeValue() {
            return this.paymentChosen || this.riderCashCollected ? this.paymentType : 'unpaid';
        },
        get cashOptions() {
            const total = this.total;
            if (total <= 0) return [200, 500, 1000];
            const options = [100, 200, 500, 1000]
                .map(note => Math.ceil(total / note) * note)
                .filter(amount => amount > total);
            return [...new Set(options)].sort((a, b) => a - b).slice(0, 3);
        },
        get paidAmount() {
            if (this.isEditing || this.riderCashCollected) return Number(this.paid || 0);
            if (this.paymentMode !== 'now') return 0;
            if (this.paymentType === 'cash') return this.cashReceived ?? this.total;
            return this.gcashAmount ?? this.total;
        },
        get changeDue() {
            return this.paymentMode === 'now' && this.paymentType === 'cash' ? Math.max(this.paidAmount - this.total, 0) : 0;
        },
        get balanceDue() {
            return Math.max(this.total - this.paidAmount, 0);
        },

        // ---- Totals ------------------------------------------------------
        get subtotal() {
            return this.items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.price || 0)), 0);
        },
        get tax() {
            return this.vatEnabled ? Math.max(this.subtotal - Number(this.discount || 0), 0) * (Number(this.vatRate) / 100) : 0;
        },
        get total() {
            return Math.ceil(Math.max(this.subtotal - Number(this.discount || 0), 0) + this.tax);
        },
        get balance() {
            return Math.max(this.total - Number(this.paid || 0), 0);
        },
        get appliedPayment() {
            return Math.min(Math.max(Number(this.paid || 0), 0), this.total);
        },
        get changeOnDelivery() {
            return this.riderCashCollected ? Math.max(Number(this.paid || 0) - this.total, 0) : 0;
        },
        fmtQty(value) {
            return Number(value || 0).toFixed(2).replace(/\.?0+$/, '');
        },
        peso(value) {
            const amount = Number(value || 0);
            const decimals = Math.abs(amount % 1) > 0.004 ? 2 : 0;
            return this.currencySymbol + amount.toLocaleString('en-PH', { minimumFractionDigits: decimals, maximumFractionDigits: 2 });
        },
        money(value) {
            return Number(value || 0).toFixed(2);
        },
        wholeMoney(value) {
            return Math.ceil(Number(value || 0)).toLocaleString('en-PH', { maximumFractionDigits: 0 });
        },

        // ---- Rider pickup tags -------------------------------------------
        async pollTags() {
            if (this.isEditing || this.submitting || document.hidden || this.tagSuggestionsLoading || this.tagRefreshing || this.tagSearchTimer) return;
            if (this.tagModalOpen) {
                await this.loadTagSuggestions(true);
                return;
            }

            const branchId = this.branchId;
            const url = new URL(@js(route('admin.job-orders.pickup-tags')), window.location.origin);
            url.searchParams.set('branch_id', branchId);
            try {
                const request = fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
                // A save waits for this request to finish before it starts.
                window.appPolls = [...(window.appPolls || []), request];
                request.finally(() => window.appPolls = (window.appPolls || []).filter(pending => pending !== request)).catch(() => {});
                const response = await request;
                if (!response.ok) return;
                const result = await response.json();
                this.waitingTagCounts[branchId] = result.count ?? 0;
            } catch (_) {
                // Keep the last count while the cashier is offline.
            }
        },
        openTagModal() {
            this.tagModalOpen = true;
            this.tagSelectionHint = '';
            this.loadTagSuggestions();
            this.$nextTick(() => this.$refs.pickupTagInput?.focus());
        },
        queueTagSearch() {
            ++this.tagRequestId;
            this.tagSuggestions = [];
            this.tagSuggestionsLoading = true;
            this.tagSelectionHint = '';
            clearTimeout(this.tagSearchTimer);
            this.tagSearchTimer = setTimeout(() => {
                this.tagSearchTimer = null;
                this.loadTagSuggestions();
            }, 250);
        },
        loadTagSelection() {
            const value = this.tagSearch.trim().toUpperCase();
            if (!value) {
                this.$refs.pickupTagInput?.focus();
                return;
            }
            if (this.tagSuggestionsLoading) {
                this.tagSelectionHint = 'Wait for the search results, then choose a pickup.';
                return;
            }

            const exact = this.tagSuggestions.filter(tag => tag.tag_code.toUpperCase() === value);
            const match = exact.length === 1 ? exact[0] : (this.tagSuggestions.length === 1 ? this.tagSuggestions[0] : null);
            if (match) {
                window.location.assign(match.url);
                return;
            }
            if (this.tagSuggestions.length > 1) {
                this.tagSelectionHint = 'Several pickups match. Select the correct bag from the list.';
                return;
            }

            this.$refs.tagLookupForm.requestSubmit();
        },
        async loadTagSuggestions(silent = false) {
            clearTimeout(this.tagSearchTimer);
            this.tagSearchTimer = null;
            const requestId = ++this.tagRequestId;
            if (!silent) {
                this.tagSuggestions = [];
                this.tagSuggestionsError = '';
            }
            if (!silent) this.tagSuggestionsLoading = true;
            if (silent) this.tagRefreshing = true;

            const url = new URL(@js(route('admin.job-orders.pickup-tags')), window.location.origin);
            url.searchParams.set('branch_id', this.branchId);
            if (this.tagSearch.trim()) {
                url.searchParams.set('search', this.tagSearch.trim());
            }

            try {
                const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Could not load waiting tags. Try again.');
                const result = await response.json();
                if (requestId !== this.tagRequestId) return;
                this.tagSuggestions = result.tags || [];
                this.waitingTagCounts[this.branchId] = result.count || 0;
                this.tagSuggestionsError = '';
            } catch (error) {
                if (requestId === this.tagRequestId && !silent) {
                    this.tagSuggestionsError = error.message;
                }
            } finally {
                if (requestId === this.tagRequestId) {
                    if (!silent) this.tagSuggestionsLoading = false;
                }
                if (silent) this.tagRefreshing = false;
            }
        },
    }
}
</script>
@endsection
