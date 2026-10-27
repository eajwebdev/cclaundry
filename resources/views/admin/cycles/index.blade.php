@extends('layouts.app')

@section('page_title', 'Cycle Monitoring')

@section('content')
@php($dateRangeValue = request('date_range') ?: ($dateFrom && $dateTo ? $dateFrom.' to '.$dateTo : ''))
<div
    x-data="cycleBoard({ signature: @js($liveSignature), dateRange: @js($dateRangeValue) })"
    @popstate.window="visit(window.location.href)"
    class="space-y-4"
>
    {{-- Thin progress line while the board talks to the server. --}}
    <div x-cloak x-show="loading && !backgroundVisit" class="pointer-events-none fixed inset-x-0 top-0 z-[60] h-0.5 overflow-hidden bg-primary/20">
        <div class="h-full w-1/3 animate-pulse bg-primary"></div>
    </div>
    <div x-cloak x-show="liveChanged" role="status" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
        <span>Machine or order information has changed.</span>
        <button type="button" @click="refreshBoard()" class="rounded-md border border-current px-3 py-1.5 font-semibold">Refresh board</button>
    </div>

    {{-- Everything in here is redrawn in place after an action, a filter, a page
         change, or a live update, so the screen never reloads. The data-* carry
         what the server said about the last action. --}}
    <div
        id="cycle-board"
        x-ref="board"
        data-signature="{{ $liveSignature }}"
        data-success="{{ session('success') }}"
        data-info="{{ session('info') }}"
        data-errors="{{ json_encode(array_values(array_unique(array_filter([session('error'), ...$errors->all()])))) }}"
        @submit="handleSubmit($event)"
        @click="handleClick($event)"
        :aria-busy="loading"
        class="space-y-4"
    >
    <div class="rounded-lg border border-border bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-2.5">
        <form method="GET" class="flex min-w-0 flex-wrap items-center gap-1.5 sm:gap-2 md:flex-nowrap">
            <div class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="cycles" class="h-3.5 w-3.5"></span>
                <span class="whitespace-nowrap">Operations board</span>
            </div>

            <div class="flex h-8 min-w-[120px] flex-1 items-center gap-1.5 rounded-md border border-border bg-white px-2.5 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="search" class="h-3.5 w-3.5 shrink-0 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" aria-label="Search job order or customer" placeholder="Search job or customer..." class="w-full min-w-0 bg-transparent text-xs outline-none">
            </div>
            @if($canChooseBranch)
                <select name="branch_id" aria-label="Branch" onchange="this.form.customer_id.value = ''; this.form.requestSubmit()" class="h-8 shrink-0 rounded-md border border-border bg-white px-2 text-xs outline-none dark:border-gray-800 dark:bg-gray-950">
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <select name="customer_id" aria-label="Customer" class="h-8 shrink-0 rounded-md border border-border bg-white px-2 text-xs outline-none dark:border-gray-800 dark:bg-gray-950">
                <option value="">All customers</option>
                @if($customers->isEmpty())
                    <option value="" disabled>No customers in selected branch</option>
                @else
                    <optgroup label="{{ $canChooseBranch ? 'Customers in selected branch' : 'Customers in your branch' }}">
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) $selectedCustomerId === (int) $customer->id)>
                                {{ $customer->name }}
                            </option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
            <div class="flex h-8 w-[110px] shrink-0 items-center gap-1 rounded-md border border-border bg-white px-2 dark:border-gray-800 dark:bg-gray-950 sm:w-[125px] xl:w-[155px]">
                <span data-lucide="calendar" class="h-3.5 w-3.5 shrink-0 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" value="{{ $dateRangeValue }}" name="date_range" type="text" aria-label="Date range" placeholder="Date range" autocomplete="off" class="w-full min-w-0 bg-transparent text-xs outline-none">
            </div>
            <select name="status" aria-label="Order status" class="h-8 shrink-0 rounded-md border border-border bg-white px-2 text-xs outline-none dark:border-gray-800 dark:bg-gray-950">
                <option value="">In Progress Status</option>
                @foreach($statusFilters as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabels[$status] ?? str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
            <button type="submit" aria-label="Filter cycles" class="inline-flex h-8 shrink-0 touch-manipulation items-center justify-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                <span data-lucide="search" class="h-3.5 w-3.5"></span>
                <span class="whitespace-nowrap">Apply filters</span>
            </button>
        </form>
    </div>

    {{-- Collapsing the machine overview folds it into one narrow column of
         machines, and the cycle queue takes the rest of the width. --}}
    <div class="grid min-w-0 items-start gap-3.5 lg:gap-4"
         :class="machinesCollapsed
            ? 'md:grid-cols-[6rem_minmax(0,1fr)]'
            : 'md:grid-cols-[minmax(19.5rem,0.92fr)_minmax(0,1.08fr)] lg:grid-cols-[minmax(21.5rem,0.9fr)_minmax(0,1.1fr)] xl:grid-cols-[minmax(23.5rem,0.86fr)_minmax(0,1.14fr)] 2xl:grid-cols-[minmax(25.5rem,0.82fr)_minmax(0,1.18fr)]'">
    @if($machineOverviewBranches->isNotEmpty())
        <section class="order-2 min-w-0 rounded-xl border border-border bg-white p-3 shadow-sm md:order-1 sm:p-4 dark:border-gray-800 dark:bg-gray-900" :class="machinesCollapsed && 'md:hidden'">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-1.5 sm:mb-4 sm:gap-2">
                <div class="flex items-start gap-2">
                    <button type="button" @click="toggleMachines()" title="Collapse machine overview" aria-label="Collapse machine overview"
                            class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border text-muted hover:bg-smoke hover:text-dark md:inline-flex dark:border-gray-700 dark:hover:bg-gray-950 dark:hover:text-white">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/></svg>
                    </button>
                    <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-primary sm:text-xs sm:tracking-[0.18em]">Machine overview</p>
                    <h2 class="mt-0.5 text-base font-semibold sm:mt-1 sm:text-lg">Machine availability</h2>
                    </div>
                </div>
                <p class="text-[11px] text-muted sm:text-xs">
                    Usage counts by cycle date only:
                    {{ \Illuminate\Support\Carbon::parse($activityDateFrom)->format('M d, Y') }}
                    @if($activityDateFrom !== $activityDateTo)
                        - {{ \Illuminate\Support\Carbon::parse($activityDateTo)->format('M d, Y') }}
                    @endif
                </p>
            </div>

            <div class="space-y-3 sm:space-y-4">
                @foreach($machineOverviewBranches as $machineBranch)
                    @php($machineTotal = (int) $machineBranch->machine_count)
                    @php($branchActiveMachines = $activeMachinesByBranch[$machineBranch->id] ?? [])
                    @php($branchMachineActivity = $machineActivityByBranch[$machineBranch->id] ?? [])
                    @php($branchTitanMachines = \App\Support\MachineClass::titanNumbers($machineBranch))
                    <article>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div>
                                <h3 class="text-xs font-semibold sm:text-sm">{{ $machineBranch->name }}</h3>
                                <p class="text-[11px] text-muted sm:text-xs">{{ $machineTotal }} {{ \Illuminate\Support\Str::plural('machine', $machineTotal) }} configured</p>
                            </div>
                        </div>

                        @if($machineTotal > 0)
                            <div class="space-y-3 sm:space-y-4">
                                @foreach(['dry' => 'Dry Machines', 'wash' => 'Wash Machines'] as $machineType => $machineLabel)
                                    <div>
                                        <div class="mb-1.5 flex items-center gap-1.5 sm:mb-2 sm:gap-2">
                                            <span class="h-2 w-2 rounded-full sm:h-2.5 sm:w-2.5 {{ $machineType === 'wash' ? 'bg-sky-500' : 'bg-violet-500' }}"></span>
                                            <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-muted sm:text-sm sm:tracking-[0.14em]">{{ $machineLabel }}</h4>
                                        </div>
                                        <div class="overflow-x-auto pb-1" role="region" aria-label="{{ $machineLabel }} at {{ $machineBranch->name }}" tabindex="0">
                                            <div class="machine-status-grid grid gap-1.5 sm:gap-2" style="grid-template-columns: repeat({{ $machineTotal }}, minmax(var(--machine-card-min, 50px), 1fr)); min-width: 100%;">
                                            @for($machine = $machineTotal; $machine >= 1; $machine--)
                                                @php($activeMachine = data_get($branchActiveMachines, $machineType.'.'.$machine))
                                                @php($isAvailable = ! $activeMachine)
                                                @php($activityCount = (int) data_get($branchMachineActivity, $machine.'.'.$machineType, 0))
                                                <div class="machine-status-card min-w-0 overflow-hidden rounded-lg border border-border bg-gradient-to-b from-white to-slate-50 shadow-sm dark:border-gray-800 dark:from-gray-900 dark:to-gray-950 sm:rounded-xl">
                                                    <div class="flex items-center justify-between px-1.5 py-1 sm:px-1.5 sm:py-1.5 xl:px-2.5 xl:py-2">
                                                        <span class="truncate text-[11px] font-semibold sm:text-xs xl:text-sm">{{ $machineType === 'wash' ? 'Wash' : 'Dry' }} #{{ $machine }}</span>
                                                        <span class="h-2 w-2 shrink-0 rounded-full sm:h-2 sm:w-2 xl:h-2.5 xl:w-2.5 {{ $isAvailable ? 'bg-emerald-500' : 'machine-status-dot-running bg-red-500' }}" title="{{ $isAvailable ? 'Available' : 'In use' }}"></span>
                                                    </div>
                                                    @php($isTitanMachine = in_array($machine, $branchTitanMachines, true))
                                                    <p class="px-1 text-center text-[8px] font-bold uppercase tracking-wide sm:text-[9px] xl:text-[10px] {{ $isTitanMachine ? 'text-amber-700 dark:text-amber-300' : 'text-sky-700 dark:text-sky-300' }}" title="{{ $isTitanMachine ? 'Titan · rated 15 kg' : 'Giant · rated 10 kg' }}">{{ $isTitanMachine ? 'Titan' : 'Giant' }}</p>
                                                    <div class="flex items-center justify-center py-1 sm:py-1.5 xl:py-2">
                                                        <img
                                                            src="{{ asset($isAvailable ? 'available.png' : 'unavailable.png') }}"
                                                            alt="{{ $machineType === 'wash' ? 'Wash' : 'Dry' }} machine #{{ $machine }} {{ $isAvailable ? 'available' : 'unavailable' }}"
                                                            width="112"
                                                            height="112"
                                                            loading="lazy"
                                                            decoding="async"
                                                            class="machine-status-image {{ $isAvailable ? 'machine-status-image-ready' : 'machine-status-image-running' }} mx-auto h-10 w-10 sm:h-12 sm:w-12 md:h-14 md:w-14 lg:h-14 lg:w-14 xl:h-20 xl:w-20 rounded-lg object-contain"
                                                        >
                                                    </div>
                                                    <div class="border-t border-border px-1 py-1 text-center sm:px-1.5 sm:py-1.5 dark:border-gray-800">
                                                        <p class="text-xs font-bold sm:text-sm xl:text-base {{ $machineType === 'wash' ? 'text-sky-600' : 'text-violet-600' }}">{{ $activityCount }}</p>
                                                        <p class="text-[8px] font-semibold uppercase tracking-tight text-muted leading-tight sm:text-[9px] xl:text-[10px]">{{ $machineType === 'wash' ? 'Washing' : 'Drying' }}<span class="hidden 2xl:inline"> cycles</span></p>
                                                    </div>
                                                    @if(! $isAvailable)
                                                        <div class="border-t border-border px-1 py-1 text-center text-[9px] font-medium text-red-600 sm:px-1.5 sm:text-[11px] dark:border-gray-800" title="{{ $activeMachine['job_order_number'] }}">
                                                            <p class="truncate leading-tight">{{ $activeMachine['customer_name'] }}</p>
                                                            <p class="mt-0.5 truncate font-semibold leading-tight">{{ $activeMachine['job_order_number'] }}</p>
                                                            @if($activeMachine['is_rush'] || $activeMachine['is_loyal'])
                                                                <div class="mt-0.5 flex flex-wrap justify-center gap-0.5 sm:gap-1">
                                                                    @if($activeMachine['is_rush'])
                                                                        <span class="rounded bg-amber-100 px-1 py-0.2 text-[7px] font-semibold uppercase text-amber-800 sm:text-[8px] dark:bg-amber-500/15 dark:text-amber-300">Rush</span>
                                                                    @endif
                                                                    @if($activeMachine['is_loyal'])
                                                                        <span class="rounded bg-violet-100 px-1 py-0.2 text-[7px] font-semibold uppercase text-violet-700 sm:text-[8px] dark:bg-violet-500/15 dark:text-violet-300">Loyal</span>
                                                                    @endif
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            @endfor
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="w-full rounded-lg border border-dashed border-border py-6 text-center text-sm text-muted dark:border-gray-800">No machines configured.</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Collapsed: the same machines, stacked in one narrow column. --}}
        <section x-cloak x-show="machinesCollapsed" aria-label="Machine availability"
                 class="order-2 hidden min-w-0 rounded-xl border border-border bg-white p-1.5 shadow-sm md:order-1 md:block md:sticky md:top-3 md:max-h-[calc(100dvh-6rem)] md:overflow-y-auto md:overscroll-contain dark:border-gray-800 dark:bg-gray-900">
            <button type="button" @click="toggleMachines()" title="Expand machine overview" aria-label="Expand machine overview"
                    class="mb-1.5 flex h-8 w-full items-center justify-center rounded-lg border border-border text-muted hover:bg-smoke hover:text-dark dark:border-gray-700 dark:hover:bg-gray-950 dark:hover:text-white">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 17 5-5-5-5"/><path d="m13 17 5-5-5-5"/></svg>
            </button>
            <div class="space-y-2">
                @foreach($machineOverviewBranches as $machineBranch)
                    @php($machineTotal = (int) $machineBranch->machine_count)
                    @php($branchActiveMachines = $activeMachinesByBranch[$machineBranch->id] ?? [])
                    @php($branchMachineActivity = $machineActivityByBranch[$machineBranch->id] ?? [])
                    @php($branchTitanMachines = \App\Support\MachineClass::titanNumbers($machineBranch))
                    <div>
                        @if($machineOverviewBranches->count() > 1)
                            <p class="mb-1 truncate text-center text-[9px] font-bold uppercase tracking-wide text-muted" title="{{ $machineBranch->name }}">{{ $machineBranch->code ?: $machineBranch->name }}</p>
                        @endif
                        @if($machineTotal > 0)
                            @foreach(['dry' => 'Dry', 'wash' => 'Wash'] as $machineType => $machineLabel)
                                <p class="mb-1 mt-1.5 flex items-center justify-center gap-1 text-[9px] font-bold uppercase tracking-wide text-muted first:mt-0">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $machineType === 'wash' ? 'bg-sky-500' : 'bg-violet-500' }}"></span>{{ $machineLabel }}
                                </p>
                                <div class="space-y-1">
                                    @for($machine = $machineTotal; $machine >= 1; $machine--)
                                        @php($activeMachine = data_get($branchActiveMachines, $machineType.'.'.$machine))
                                        @php($isAvailable = ! $activeMachine)
                                        @php($activityCount = (int) data_get($branchMachineActivity, $machine.'.'.$machineType, 0))
                                        @php($machineClass = in_array($machine, $branchTitanMachines, true) ? 'Titan' : 'Giant')
                                        <div @class([
                                                'rounded-lg border px-1 py-1 text-center',
                                                'border-border dark:border-gray-800' => $isAvailable,
                                                'border-red-200 bg-red-50/60 dark:border-red-900/60 dark:bg-red-950/20' => ! $isAvailable,
                                             ])
                                             title="{{ $machineLabel }} {{ $machine }} ({{ $machineClass }}) · {{ $isAvailable ? 'Available' : 'In use: '.$activeMachine['customer_name'].' · '.$activeMachine['job_order_number'] }} · {{ $activityCount }} {{ \Illuminate\Support\Str::plural('cycle', $activityCount) }}">
                                            <div class="flex items-center justify-between gap-0.5">
                                                <span class="text-[11px] font-bold leading-none">{{ $machineType === 'wash' ? 'W' : 'D' }}{{ $machine }}</span>
                                                <span class="h-2 w-2 shrink-0 rounded-full {{ $isAvailable ? 'bg-emerald-500' : 'machine-status-dot-running bg-red-500' }}"></span>
                                            </div>
                                            <img src="{{ asset($isAvailable ? 'available.png' : 'unavailable.png') }}" alt="" width="32" height="32" loading="lazy" decoding="async"
                                                 class="machine-status-image {{ $isAvailable ? 'machine-status-image-ready' : 'machine-status-image-running' }} mx-auto my-0.5 h-8 w-8 object-contain">
                                            <p class="text-[9px] font-semibold leading-tight {{ $machineClass === 'Titan' ? 'text-amber-700 dark:text-amber-300' : 'text-sky-700 dark:text-sky-300' }}">
                                                {{ $machineClass }} · <span class="{{ $machineType === 'wash' ? 'text-sky-600' : 'text-violet-600' }}">{{ $activityCount }}×</span>
                                            </p>
                                            @unless($isAvailable)
                                                <p class="mt-0.5 truncate text-[9px] font-semibold leading-tight text-red-600">{{ $activeMachine['customer_name'] }}</p>
                                            @endunless
                                        </div>
                                    @endfor
                                </div>
                            @endforeach
                        @else
                            <p class="text-center text-[9px] text-muted">No machines</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="order-1 min-w-0 overflow-hidden rounded-xl border border-border bg-white shadow-sm md:order-2 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center justify-between border-b border-border px-3 py-2.5 sm:px-4 sm:py-3 dark:border-gray-800">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-primary sm:text-xs sm:tracking-[0.18em]">Job Orders</p>
                <h2 class="mt-0.5 text-base font-semibold sm:mt-1 sm:text-lg">Cycle queue</h2>
            </div>
            <span class="rounded-md bg-smoke px-2 py-1 text-xs font-medium text-muted dark:bg-gray-950">{{ $orders->total() }} orders</span>
        </div>

        <div class="p-2.5 sm:p-3">
        <div class="grid grid-cols-1 gap-2.5 sm:gap-3">
        @forelse($orders as $order)
            @php($processingBranch = $order->processingBranch ?: $order->branch)
            @php($processingBranchId = $order->processing_branch_id ?: $order->branch_id)
            @php($currentBranch = $order->currentBranch ?: $processingBranch)
            @php($releaseBranch = $order->releaseBranch ?: $currentBranch)
            @php($activeMachines = $activeMachinesByBranch[$processingBranchId] ?? [])
            @php($userBranchId = auth()->user()->branch_id)
            @php($canManageAllBranches = auth()->user()->canManageAllBranches())
            @php($canReleaseHere = $canManageAllBranches || (int) ($releaseBranch?->id ?? $order->branch_id) === (int) $userBranchId)
            @php($canReturnToDropoff = (int) $processingBranchId !== (int) $order->branch_id && ($canManageAllBranches || (int) $processingBranchId === (int) $userBranchId))
            @php($isCrossBranchProduction = (int) $processingBranchId !== (int) $order->branch_id)
            <div class="rounded-lg border border-border bg-white p-3 shadow-sm sm:p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-2.5 flex items-start justify-between gap-2 sm:mb-3 sm:gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1 sm:gap-1.5">
                            <p class="truncate text-sm font-semibold sm:text-base">{{ $order->customer?->name ?? 'Unknown customer' }}</p>
                            @if($order->is_rush)
                                <span class="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-amber-800 sm:text-[10px] dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300">Rush</span>
                            @endif
                            @if((int) ($order->customer?->job_orders_count ?? 0) >= 10)
                                <span class="rounded-md border border-violet-200 bg-violet-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-violet-700 sm:text-[10px] dark:border-violet-900/60 dark:bg-violet-500/10 dark:text-violet-300">Loyal Customer</span>
                            @endif
                        </div>
                        <p class="truncate text-xs text-muted sm:text-sm">{{ $order->job_order_number }}</p>
                        <p class="text-[11px] font-medium text-primary sm:text-xs">Order date: {{ $order->created_at?->format('M d, Y') }}</p>
                        <p class="truncate text-[11px] text-muted sm:text-xs">
                            Drop-off: {{ $order->branch?->name }} - Receiving/Processing: {{ $processingBranch?->name }} - Release: {{ $releaseBranch?->name }}
                        </p>
                        @if($isCrossBranchProduction)
                            @if($order->production_accepted_at)
                                <p class="text-[11px] text-emerald-600 sm:text-xs">Received by QR scan {{ $order->production_accepted_at->format('M d, h:i A') }}</p>
                            @else
                                <p class="text-[11px] text-amber-600 sm:text-xs">Assigned only. Waiting for {{ $processingBranch?->name }} QR scan before branch cycle.</p>
                            @endif
                        @endif
                    </div>
                    <span class="shrink-0 text-xs {{ \App\Support\StatusBadge::classes($order->status) }}">
                        {{ $statusLabels[$order->status] ?? str_replace('_', ' ', ucfirst($order->status)) }}
                    </span>
                </div>

                @include('admin.cycles.partials.dosing', ['order' => $order])

                {{--
                Branch routing summary is temporarily hidden until multi-branch operations are in use.
                <div class="mb-3 grid gap-2 text-xs text-muted sm:grid-cols-4">
                    <div class="rounded-md bg-smoke px-2.5 py-2 dark:bg-gray-950">
                        <span class="block font-medium text-ink dark:text-gray-100">Drop-off</span>
                        {{ $order->branch?->name ?? 'Unassigned' }}
                    </div>
                    <div class="rounded-md bg-smoke px-2.5 py-2 dark:bg-gray-950">
                        <span class="block font-medium text-ink dark:text-gray-100">Receiving</span>
                        {{ $processingBranch?->name ?? 'Unassigned' }}
                        @if($isCrossBranchProduction)
                            <span class="block {{ $order->production_accepted_at ? 'text-emerald-600' : 'text-amber-600' }}">
                                {{ $order->production_accepted_at ? 'QR received' : 'Pending QR scan' }}
                            </span>
                        @endif
                    </div>
                    <div class="rounded-md bg-smoke px-2.5 py-2 dark:bg-gray-950">
                        <span class="block font-medium text-ink dark:text-gray-100">Current</span>
                        {{ $currentBranch?->name ?? 'Unassigned' }}
                    </div>
                    <div class="rounded-md bg-smoke px-2.5 py-2 dark:bg-gray-950">
                        <span class="block font-medium text-ink dark:text-gray-100">Release</span>
                        {{ $releaseBranch?->name ?? 'Unassigned' }}
                    </div>
                </div>
                --}}

                @if(! in_array($order->status, ['ready_for_pickup', 'ready_for_delivery', 'completed'], true))
                    <div class="mb-2.5 grid gap-2 md:grid-cols-2 sm:mb-3">
                        @foreach(['wash' => $cycleTypes['wash'], 'dry' => $cycleTypes['dry']] as $type => $label)
                            <form method="POST" action="{{ route('admin.cycles.store', $order) }}">
                                @csrf
                                <input type="hidden" name="cycle_type" value="{{ $type }}">
                                <div class="flex flex-col gap-1 rounded-md border border-border bg-white p-1 sm:gap-1.5 sm:p-1.5 dark:border-gray-800 dark:bg-gray-950">
                                    @php($branchMachines = (int) ($processingBranch?->machine_count ?? 0))
                                    @php($pickerTitanMachines = \App\Support\MachineClass::titanNumbers($processingBranch))
                                    @if($branchMachines > 0)
                                        <div class="grid gap-1 sm:gap-1.5" style="grid-template-columns: repeat({{ $branchMachines }}, minmax(0, 1fr));">
                                            @for($machine = $branchMachines; $machine >= 1; $machine--)
                                                @php($usingMachine = data_get($activeMachines, $type.'.'.$machine))
                                                <label class="inline-flex min-h-[2.25rem] w-full touch-manipulation items-center justify-center gap-0.5 rounded-lg border border-border px-1 py-1 text-xs font-semibold sm:min-h-[2.4rem] sm:gap-1 sm:text-xs xl:min-h-[2.75rem] xl:gap-1.5 xl:px-2 xl:text-sm {{ $usingMachine ? 'bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-400 opacity-60 cursor-not-allowed' : 'hover:bg-smoke dark:hover:bg-gray-900 cursor-pointer' }} dark:border-gray-700">
                                                    <input type="checkbox" name="machine_numbers[]" value="{{ $machine }}" {{ $usingMachine ? 'disabled' : '' }} class="h-3.5 w-3.5 shrink-0 rounded border-border text-primary sm:h-3.5 sm:w-3.5 xl:h-4.5 xl:w-4.5">
                                                    <span class="flex shrink-0 flex-col items-center leading-none">
                                                        <span class="text-[11px] sm:text-xs xl:text-sm">#{{ $machine }}</span>
                                                        <span class="mt-0.5 text-[8px] font-bold uppercase sm:text-[9px] {{ in_array($machine, $pickerTitanMachines, true) ? 'text-amber-700 dark:text-amber-300' : 'text-muted' }}">{{ in_array($machine, $pickerTitanMachines, true) ? 'Titan' : 'Giant' }}</span>
                                                    </span>
                                                </label>
                                            @endfor
                                        </div>
                                    @endif
                                    <button type="submit" title="Start {{ $label }}" class="inline-flex h-9 touch-manipulation items-center justify-center gap-1.5 rounded-md bg-primary px-2.5 text-xs font-semibold text-white hover:opacity-90 sm:h-10 sm:px-3 sm:text-sm xl:h-11">
                                        <span data-lucide="plus" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                                        {{ $label }}
                                    </button>
                                </div>
                            </form>
                        @endforeach
                    </div>

                    <div class="mb-2.5 grid grid-cols-2 gap-1 sm:mb-3 sm:gap-1.5 lg:grid-cols-4">
                        @foreach(['fold' => $cycleTypes['fold'], 'iron' => $cycleTypes['iron']] as $type => $label)
                            <form method="POST" action="{{ route('admin.cycles.store', $order) }}">
                                @csrf
                                <input type="hidden" name="cycle_type" value="{{ $type }}">
                                <button type="submit" title="Start {{ $label }}" class="inline-flex h-9 w-full touch-manipulation items-center justify-center gap-1 rounded-md bg-primary px-1.5 text-xs font-semibold text-white hover:opacity-90 sm:h-10 sm:gap-1.5 sm:px-2 sm:text-xs xl:h-11 xl:text-sm">
                                    <span data-lucide="plus" class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4"></span>
                                    <span class="truncate">{{ $label }}</span>
                                </button>
                            </form>
                        @endforeach
                        @foreach([
                            'ready_for_pickup' => ['label' => 'Ready for Pickup', 'icon' => 'package-check', 'classes' => 'bg-teal-600 hover:bg-teal-700', 'color' => '#0f766e'],
                            'ready_for_delivery' => ['label' => 'Ready for Delivery', 'icon' => 'truck', 'classes' => 'bg-orange-600 hover:bg-orange-700', 'color' => '#ea580c'],
                        ] as $readyStatus => $readyAction)
                            <form method="POST" action="{{ route('admin.cycles.status', $order) }}" x-data>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ $readyStatus }}">
                                @if((int) $order->active_cycles_count > 0)
                                    <button
                                        type="button"
                                        x-on:click="Swal.fire({ title: 'Cycle still running', text: @js('This job order still has '.$order->active_cycles_count.' active '.\Illuminate\Support\Str::plural('cycle', $order->active_cycles_count).'. End all cycles before marking it as '.$readyAction['label'].'.'), icon: 'warning', confirmButtonColor: '#dc2626' })"
                                        class="inline-flex h-9 w-full cursor-not-allowed touch-manipulation items-center justify-center gap-1 rounded-md px-1.5 text-xs font-semibold text-white opacity-50 sm:h-10 sm:gap-1.5 sm:px-2 sm:text-xs xl:h-11 xl:text-sm {{ $readyAction['classes'] }}"
                                        title="End all active cycles first"
                                    >
                                        <span data-lucide="{{ $readyAction['icon'] }}" class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4"></span>
                                        <span class="truncate">{{ $readyAction['label'] }}</span>
                                    </button>
                                @else
                                    <button
                                        type="submit"
                                        x-on:click.prevent="Swal.fire({ title: @js($readyAction['label'].'?'), text: 'This will finish production and notify the customer that the laundry is ready.', icon: 'question', showCancelButton: true, confirmButtonColor: @js($readyAction['color']), confirmButtonText: 'Mark as Ready' }).then((result) => { if (result.isConfirmed) $el.closest('form').requestSubmit(); })"
                                        class="inline-flex h-9 w-full touch-manipulation items-center justify-center gap-1 rounded-md px-1.5 text-xs font-semibold text-white sm:h-10 sm:gap-1.5 sm:px-2 sm:text-xs xl:h-11 xl:text-sm {{ $readyAction['classes'] }}"
                                    >
                                        <span data-lucide="{{ $readyAction['icon'] }}" class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4"></span>
                                        <span class="truncate">{{ $readyAction['label'] }}</span>
                                    </button>
                                @endif
                            </form>
                        @endforeach
                    </div>

                @endif

                <div class="border-t border-border pt-2.5 sm:pt-3 dark:border-gray-800">
                    @if($order->cycles_count > $order->cycles->count())
                        <p class="text-[11px] text-muted sm:text-xs">
                            Showing latest {{ $order->cycles->count() }} of {{ $order->cycles_count }} cycle records.
                        </p>
                    @endif

                    <div class="flex snap-x snap-mandatory flex-nowrap gap-1.5 overflow-x-auto pb-1.5 sm:gap-2 sm:pb-2">
                    @forelse($order->cycles as $cycle)
                        <div class="flex w-44 shrink-0 snap-start items-center justify-between gap-1.5 rounded-md border border-border bg-smoke px-2 py-1.5 text-xs dark:border-gray-800 dark:bg-gray-950 sm:w-48 sm:px-2.5 sm:py-2 sm:text-sm xl:w-52">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-medium sm:text-sm">
                                    {{ $cycleTypes[$cycle->cycle_type] ?? ucfirst($cycle->cycle_type) }} #{{ $cycle->cycle_number }}
                                    @if($cycle->machine_number)
                                        <span class="text-[11px] font-normal text-muted sm:text-xs">#{{ $cycle->machine_number }}</span>
                                    @endif
                                </p>
                                <p class="text-[10px] text-muted sm:text-xs">
                                    {{ $cycle->started_at?->format('M d, h:i A') ?? 'Not started' }}
                                    @if($cycle->ended_at) - {{ $cycle->ended_at->format('h:i A') }} @endif
                                </p>
                                <p class="truncate text-[10px] text-muted sm:text-xs">{{ $cycle->user?->name ?? 'System user' }}</p>
                                @if(! $cycle->ended_at)
                                    <form method="POST" action="{{ route('admin.cycles.end', $cycle) }}" class="mt-2">
                                        @csrf
                                        @method('PATCH')
                                        <button title="End cycle" aria-label="End cycle" class="inline-flex min-h-11 touch-manipulation items-center justify-center gap-1.5 rounded-md border border-teal-200 px-3 text-xs font-semibold text-teal-700 hover:bg-teal-50 dark:border-teal-900/60 dark:text-teal-300 dark:hover:bg-teal-500/10">
                                            <span data-lucide="check" class="h-4 w-4"></span>
                                            End cycle
                                        </button>
                                    </form>
                                @else
                                    <span class="mt-2 inline-block text-[11px] text-muted sm:text-xs">Done</span>
                                @endif
                            </div>

                            <div class="ml-3 flex shrink-0 self-start items-center gap-1">

                                <form method="POST" action="{{ route('admin.cycles.destroy', $cycle) }}" x-data>
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        title="Remove cycle"
                                        aria-label="Remove cycle"
                                        x-on:click.prevent="Swal.fire({ title: 'Remove cycle?', text: 'Use this to clean duplicate or accidental cycle taps.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Remove' }).then((result) => { if (result.isConfirmed) $el.closest('form').requestSubmit(); })"
                                        class="inline-flex h-8 w-8 touch-manipulation items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900/70 dark:hover:bg-red-950/30 sm:h-9 sm:w-9 xl:h-10 xl:w-10"
                                    >
                                        <span data-lucide="trash" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="w-full rounded-md border border-dashed border-border py-3 text-center text-xs text-muted sm:py-4 sm:text-sm dark:border-gray-800">No cycles yet.</p>
                    @endforelse
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-white p-8 text-center text-sm text-muted sm:p-10 dark:border-gray-800 dark:bg-gray-900">
                No active job orders to monitor.
            </div>
        @endforelse
        </div>
        </div>

        <div data-board-nav class="border-t border-border px-3 py-2.5 sm:px-4 sm:py-3 dark:border-gray-800">{{ $orders->links() }}</div>
    </section>
    </div>
    </div>
</div>

<script>
function cycleBoard(config) {
    return {
        dateRange: config.dateRange || '',
        liveSignature: config.signature,
        liveChanged: false,
        liveChecking: false,
        liveTimer: null,
        loading: false,
        // A live refresh runs in the background; a tap must never wait on one.
        backgroundVisit: false,
        visitAbort: null,
        visitToken: 0,
        machinesCollapsed: (() => {
            try {
                return localStorage.getItem('cycles:machines-collapsed') === '1';
            } catch (_) {
                return false;
            }
        })(),

        init() {
            this.liveTimer = window.setInterval(() => this.checkLive(), 5000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.checkLive();
            });
            window.addEventListener('online', () => this.checkLive());
            this.$nextTick(() => this.initDatePicker());
        },
        destroy() {
            if (this.liveTimer) window.clearInterval(this.liveTimer);
        },
        toggleMachines() {
            this.machinesCollapsed = !this.machinesCollapsed;
            try {
                localStorage.setItem('cycles:machines-collapsed', this.machinesCollapsed ? '1' : '0');
            } catch (_) {}
        },
        initDatePicker() {
            const input = this.$refs.board?.querySelector('input[name="date_range"]');
            if (!input || !window.flatpickr || input._flatpickr) return;
            window.flatpickr(input, {
                mode: 'range',
                dateFormat: 'Y-m-d',
                defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                onClose: (dates, value) => this.dateRange = value,
            });
        },
        busyWithBoard() {
            const active = document.activeElement;
            return Boolean(this.loading
                || document.querySelector('.swal2-container')
                || this.$el.querySelector('input[type=checkbox]:checked')
                || (this.$el.contains(active) && active.matches('input, select, textarea')));
        },
        refreshBoard() {
            this.visit(window.location.href);
        },
        async checkLive() {
            if (this.liveChecking || this.loading || window.appSaving || document.hidden) return;
            this.liveChecking = true;
            const url = new URL(window.location.href);
            url.searchParams.set('live', '1');
            try {
                const request = fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
                // A save waits for this request to finish before it starts.
                window.appPolls = [...(window.appPolls || []), request];
                request.finally(() => window.appPolls = (window.appPolls || []).filter(pending => pending !== request)).catch(() => {});
                const response = await request;
                if (!response.ok) return;
                const data = await response.json();
                if (data.signature && data.signature !== this.liveSignature) {
                    if (this.busyWithBoard()) this.liveChanged = true;
                    else await this.visit(window.location.href, { background: true });
                }
            } catch (_) {
                // Keep the board visible during a temporary connection loss.
            } finally {
                this.liveChecking = false;
            }
        },

        // Every form on the board (start, end, remove, mark ready, filters) is
        // sent in the background; the server's redirect comes back as the page,
        // and only the board is swapped for the new one.
        handleSubmit(event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            event.preventDefault();
            // Only the cashier's own pending action blocks a second tap.
            if (this.loading && !this.backgroundVisit) return;

            if ((form.getAttribute('method') || 'GET').toUpperCase() === 'GET') {
                const url = new URL(form.getAttribute('action') || window.location.pathname, window.location.origin);
                url.search = new URLSearchParams(new FormData(form)).toString();
                this.visit(url.toString(), { push: true });
                return;
            }

            this.visit(form.action, { method: 'POST', body: new FormData(form), source: form });
        },
        handleClick(event) {
            const link = event.target.closest('[data-board-nav] a[href]');
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            if (this.loading && !this.backgroundVisit) return;
            this.visit(link.href, { push: true, scrollTop: true });
        },
        async visit(url, { method = 'GET', body = null, push = false, scrollTop = false, source = null, background = false } = {}) {
            const token = ++this.visitToken;
            // A save's success or error message waits in the session for the
            // very next request. Hold other polls back and let any already in
            // flight land first, so none of them uses the message up. (Aborting
            // one would not help: the server still finishes it.) Anything else
            // replaces the visit in flight; the token drops its late result.
            const saving = method !== 'GET';
            if (saving) window.appSaving = true;
            else this.visitAbort?.abort();
            const abort = new AbortController();
            this.visitAbort = abort;
            this.backgroundVisit = background;
            const buttons = source ? [...source.querySelectorAll('button')] : [];
            buttons.forEach(button => button.disabled = true);
            this.loading = true;

            try {
                if (saving) await Promise.allSettled(window.appPolls || []);
                const request = fetch(url, { method, body, cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'text/html' }, signal: abort.signal });
                if (background) {
                    window.appPolls = [...(window.appPolls || []), request];
                    request.finally(() => window.appPolls = (window.appPolls || []).filter(pending => pending !== request)).catch(() => {});
                }
                const response = await request;
                // An expired session cannot be fixed in place.
                if (response.status === 419) {
                    window.location.reload();
                    return;
                }
                const html = await response.text();
                if (token !== this.visitToken) return;

                const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('cycle-board');
                if (!fresh) {
                    // Signed out, or sent somewhere else entirely: go there.
                    window.location.assign(response.url);
                    return;
                }

                this.dateRange = fresh.querySelector('input[name="date_range"]')?.getAttribute('value') || '';
                // The date picker keeps its calendar outside the board; remove it
                // with the old input, or every redraw leaves another one behind.
                this.$refs.board.querySelector('input[name="date_range"]')?._flatpickr?.destroy();
                this.$refs.board.innerHTML = fresh.innerHTML;
                this.liveSignature = fresh.dataset.signature || this.liveSignature;
                this.liveChanged = false;

                if (push) window.history.pushState(null, '', response.url);
                if (scrollTop) this.$refs.board.scrollIntoView({ block: 'start', behavior: 'smooth' });
                this.announce(fresh.dataset);
                this.$nextTick(() => {
                    window.renderLucideIcons?.();
                    this.initDatePicker();
                });
            } catch (error) {
                buttons.forEach(button => button.disabled = false);
                // Replaced by a newer visit, or a background refresh that failed: say nothing.
                if (error?.name === 'AbortError' || background) return;
                window.toast?.fire({ icon: 'error', title: 'Could not reach the server. Check the connection and try again.' });
            } finally {
                if (saving) window.appSaving = false;
                if (token === this.visitToken) {
                    this.loading = false;
                    this.backgroundVisit = false;
                }
            }
        },
        announce(data) {
            let errors = [];
            try {
                errors = JSON.parse(data.errors || '[]');
            } catch (_) {}

            if (errors.length) {
                window.Swal?.fire({ icon: 'error', title: 'Not done', html: errors.map(message => `<p>${this.escape(message)}</p>`).join(''), confirmButtonColor: '#dc2626' });
            } else if (data.success || data.info) {
                window.toast?.fire({ icon: data.success ? 'success' : 'info', title: data.success || data.info });
            }
        },
        escape(text) {
            const node = document.createElement('span');
            node.textContent = String(text);
            return node.innerHTML;
        },
    };
}
</script>
@endsection
