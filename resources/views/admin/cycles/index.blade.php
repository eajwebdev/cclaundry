@extends('layouts.app')

@section('page_title', 'Cycle Monitoring')

@section('content')
@php($dateRangeValue = request('date_range') ?: ($dateFrom && $dateTo ? $dateFrom.' to '.$dateTo : ''))
<div
    x-data="{
        dateRange: @js($dateRangeValue),
        liveSignature: @js($liveSignature),
        liveChanged: false,
        liveChecking: false,
        liveTimer: null,
        init() {
            const scrollKey = 'cycle-board-scroll:' + window.location.pathname + window.location.search;
            try {
                const saved = sessionStorage.getItem(scrollKey);
                if (saved !== null) {
                    sessionStorage.removeItem(scrollKey);
                    this.$nextTick(() => window.scrollTo(0, Number(saved) || 0));
                }
            } catch (_) {}
            this.liveTimer = window.setInterval(() => this.checkLive(), 5000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.checkLive();
            });
            window.addEventListener('online', () => this.checkLive());
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
        destroy() {
            if (this.liveTimer) window.clearInterval(this.liveTimer);
        },
        busyWithBoard() {
            const active = document.activeElement;
            return Boolean(document.querySelector('.swal2-container')
                || this.$el.querySelector('input[type=checkbox]:checked')
                || (this.$el.contains(active) && active.matches('input, select, textarea')));
        },
        refreshBoard() {
            try {
                sessionStorage.setItem('cycle-board-scroll:' + window.location.pathname + window.location.search, String(window.scrollY));
            } catch (_) {}
            window.location.reload();
        },
        async checkLive() {
            if (this.liveChecking || document.hidden) return;
            this.liveChecking = true;
            const url = new URL(window.location.href);
            url.searchParams.set('live', '1');
            try {
                const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const data = await response.json();
                if (data.signature && data.signature !== this.liveSignature) {
                    if (this.busyWithBoard()) this.liveChanged = true;
                    else this.refreshBoard();
                }
            } catch (_) {
                // Keep the board visible during a temporary connection loss.
            } finally {
                this.liveChecking = false;
            }
        },
    }"
    class="space-y-4"
>
    <div x-cloak x-show="liveChanged" role="status" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
        <span>Machine or order information has changed.</span>
        <button type="button" @click="refreshBoard()" class="rounded-md border border-current px-3 py-1.5 font-semibold">Refresh board</button>
    </div>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="cycles" class="h-3.5 w-3.5"></span>
                Operations board
            </div>
            <h1 class="text-xl font-semibold">Cycle Monitoring</h1>
            <p class="mt-1 text-sm text-muted">Find an order, choose an available machine, and update its laundry stage.</p>
        </div>

        {{-- Filters wrap 1 -> 2 -> 3 -> 6 columns so they never overflow a tablet viewport. --}}
        <form method="GET" class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <div class="flex h-11 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950 xl:h-10">
                <span data-lucide="search" class="h-4 w-4 shrink-0 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" aria-label="Search job order or customer" placeholder="Search job or customer..." class="w-full min-w-0 bg-transparent text-sm outline-none">
            </div>
            @if($canChooseBranch)
                <select name="branch_id" aria-label="Branch" onchange="this.form.customer_id.value = ''; this.form.submit()" class="h-11 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 xl:h-10">
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <select name="customer_id" aria-label="Customer" class="h-11 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 xl:h-10">
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
            <div class="flex h-11 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950 xl:h-10">
                <span data-lucide="calendar" class="h-4 w-4 shrink-0 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" aria-label="Date range" placeholder="Date range" autocomplete="off" class="w-full min-w-0 bg-transparent text-sm outline-none">
            </div>
            <select name="status" aria-label="Order status" class="h-11 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 xl:h-10">
                <option value="">In Progress Status</option>
                @foreach($statusFilters as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabels[$status] ?? str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
            <button type="submit" aria-label="Filter cycles" class="inline-flex h-11 w-full touch-manipulation items-center justify-center gap-2 rounded-md border border-border text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950 xl:h-10">
                <span data-lucide="search" class="h-4 w-4"></span>
                Apply filters
            </button>
        </form>
    </div>

    <div class="grid min-w-0 items-start gap-4 2xl:grid-cols-[minmax(21rem,0.85fr)_minmax(0,1.4fr)]">
    @if($machineOverviewBranches->isNotEmpty())
        <section class="order-2 min-w-0 rounded-xl border border-border bg-white p-4 shadow-sm 2xl:order-1 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Machine overview</p>
                    <h2 class="mt-1 text-lg font-semibold">Machine availability</h2>
                </div>
                <p class="text-xs text-muted">
                    Usage counts by cycle date only:
                    {{ \Illuminate\Support\Carbon::parse($activityDateFrom)->format('M d, Y') }}
                    @if($activityDateFrom !== $activityDateTo)
                        - {{ \Illuminate\Support\Carbon::parse($activityDateTo)->format('M d, Y') }}
                    @endif
                </p>
            </div>

            <div class="space-y-4">
                @foreach($machineOverviewBranches as $machineBranch)
                    @php($machineTotal = (int) $machineBranch->machine_count)
                    @php($branchActiveMachines = $activeMachinesByBranch[$machineBranch->id] ?? [])
                    @php($branchMachineActivity = $machineActivityByBranch[$machineBranch->id] ?? [])
                    <article>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold">{{ $machineBranch->name }}</h3>
                                <p class="text-xs text-muted">{{ $machineTotal }} {{ \Illuminate\Support\Str::plural('machine', $machineTotal) }} configured</p>
                            </div>
                        </div>

                        @if($machineTotal > 0)
                            <div class="space-y-4">
                                @foreach(['dry' => 'Dry Machines', 'wash' => 'Wash Machines'] as $machineType => $machineLabel)
                                    <div>
                                        <div class="mb-2 flex items-center gap-2">
                                            <span class="h-2.5 w-2.5 rounded-full {{ $machineType === 'wash' ? 'bg-sky-500' : 'bg-violet-500' }}"></span>
                                            <h4 class="text-sm font-semibold uppercase tracking-[0.14em] text-muted">{{ $machineLabel }}</h4>
                                        </div>
                                        <div class="overflow-x-auto pb-1" role="region" aria-label="{{ $machineLabel }} at {{ $machineBranch->name }}" tabindex="0">
                                            <div class="grid gap-2" style="grid-template-columns: repeat({{ $machineTotal }}, minmax(104px, 1fr)); min-width: {{ $machineTotal * 104 + max(0, $machineTotal - 1) * 8 }}px;">
                                            @for($machine = 1; $machine <= $machineTotal; $machine++)
                                                @php($activeMachine = data_get($branchActiveMachines, $machineType.'.'.$machine))
                                                @php($isAvailable = ! $activeMachine)
                                                @php($activityCount = (int) data_get($branchMachineActivity, $machine.'.'.$machineType, 0))
                                                <div class="machine-status-card min-w-0 overflow-hidden rounded-xl border border-border bg-gradient-to-b from-white to-slate-50 shadow-sm dark:border-gray-800 dark:from-gray-900 dark:to-gray-950">
                                                    <div class="flex items-center justify-between px-2.5 py-2">
                                                        <span class="truncate text-sm font-semibold">{{ $machineType === 'wash' ? 'Wash' : 'Dry' }} #{{ $machine }}</span>
                                                        <span class="h-2.5 w-2.5 rounded-full {{ $isAvailable ? 'bg-emerald-500' : 'machine-status-dot-running bg-red-500' }}" title="{{ $isAvailable ? 'Available' : 'In use' }}"></span>
                                                    </div>
                                                    <img
                                                        src="{{ asset($isAvailable ? 'available.png' : 'unavailable.png') }}"
                                                        alt="{{ $machineType === 'wash' ? 'Wash' : 'Dry' }} machine #{{ $machine }} {{ $isAvailable ? 'available' : 'unavailable' }}"
                                                        width="112"
                                                        height="112"
                                                        loading="lazy"
                                                        decoding="async"
                                                        class="machine-status-image {{ $isAvailable ? 'machine-status-image-ready' : 'machine-status-image-running' }} mx-auto h-20 w-20 rounded-lg object-cover"
                                                    >
                                                    <div class="border-t border-border px-1.5 py-1.5 text-center dark:border-gray-800">
                                                        <p class="text-base font-bold {{ $machineType === 'wash' ? 'text-sky-600' : 'text-violet-600' }}">{{ $activityCount }}</p>
                                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-muted">{{ $machineType === 'wash' ? 'Washing cycles' : 'Drying cycles' }}</p>
                                                    </div>
                                                    @if(! $isAvailable)
                                                        <div class="border-t border-border px-2 py-1.5 text-center text-[11px] font-medium text-red-600 dark:border-gray-800" title="{{ $activeMachine['job_order_number'] }}">
                                                            <p class="truncate">{{ $activeMachine['customer_name'] }}</p>
                                                            <p class="mt-0.5 truncate font-semibold">{{ $activeMachine['job_order_number'] }}</p>
                                                            @if($activeMachine['is_rush'] || $activeMachine['is_loyal'])
                                                                <div class="mt-1 flex justify-center gap-1">
                                                                    @if($activeMachine['is_rush'])
                                                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold uppercase text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Rush</span>
                                                                    @endif
                                                                    @if($activeMachine['is_loyal'])
                                                                        <span class="rounded bg-violet-100 px-1.5 py-0.5 font-semibold uppercase text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">Loyal</span>
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
    @endif

    <section class="order-1 min-w-0 overflow-hidden rounded-xl border border-border bg-white shadow-sm 2xl:order-2 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center justify-between border-b border-border px-4 py-3 dark:border-gray-800">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Job Orders</p>
                <h2 class="mt-1 text-lg font-semibold">Cycle queue</h2>
            </div>
            <span class="rounded-md bg-smoke px-2 py-1 text-xs font-medium text-muted dark:bg-gray-950">{{ $orders->total() }} orders</span>
        </div>

        <div class="p-3">
        <div class="grid grid-cols-1 gap-3">
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
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <p class="truncate font-semibold">{{ $order->customer?->name ?? 'Unknown customer' }}</p>
                            @if($order->is_rush)
                                <span class="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300">Rush</span>
                            @endif
                            @if((int) ($order->customer?->job_orders_count ?? 0) >= 10)
                                <span class="rounded-md border border-violet-200 bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-violet-700 dark:border-violet-900/60 dark:bg-violet-500/10 dark:text-violet-300">Loyal Customer</span>
                            @endif
                        </div>
                        <p class="truncate text-sm text-muted">{{ $order->job_order_number }}</p>
                        <p class="text-xs font-medium text-primary">Order date: {{ $order->created_at?->format('M d, Y') }}</p>
                        <p class="truncate text-xs text-muted">
                            Drop-off: {{ $order->branch?->name }} - Receiving/Processing: {{ $processingBranch?->name }} - Release: {{ $releaseBranch?->name }}
                        </p>
                        @if($isCrossBranchProduction)
                            @if($order->production_accepted_at)
                                <p class="text-xs text-emerald-600">Received by QR scan {{ $order->production_accepted_at->format('M d, h:i A') }}</p>
                            @else
                                <p class="text-xs text-amber-600">Assigned only. Waiting for {{ $processingBranch?->name }} QR scan before branch cycle.</p>
                            @endif
                        @endif
                    </div>
                    <span class="shrink-0 {{ \App\Support\StatusBadge::classes($order->status) }}">
                        {{ $statusLabels[$order->status] ?? str_replace('_', ' ', ucfirst($order->status)) }}
                    </span>
                </div>

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
                    <div class="mb-3 grid gap-2 lg:grid-cols-2">
                        @foreach(['wash' => $cycleTypes['wash'], 'dry' => $cycleTypes['dry']] as $type => $label)
                            <form method="POST" action="{{ route('admin.cycles.store', $order) }}">
                                @csrf
                                <input type="hidden" name="cycle_type" value="{{ $type }}">
                                <div class="flex flex-col gap-1.5 rounded-md border border-border bg-white p-1.5 dark:border-gray-800 dark:bg-gray-950">
                                    @if((int) ($processingBranch?->machine_count ?? 0) > 0)
                                        <div class="flex flex-wrap gap-1.5">
                                            @for($machine = 1; $machine <= (int) $processingBranch->machine_count; $machine++)
                                                @php($usingMachine = data_get($activeMachines, $type.'.'.$machine))
                                                <label class="inline-flex min-h-[2.75rem] min-w-[3rem] touch-manipulation items-center justify-center gap-1.5 rounded-lg px-2 text-sm font-semibold {{ $usingMachine ? 'bg-red-50 text-red-600 dark:bg-red-950/30 dark:text-red-400 opacity-60 cursor-not-allowed' : 'hover:bg-smoke dark:hover:bg-gray-900 cursor-pointer' }} border border-border dark:border-gray-700">
                                                    <input type="checkbox" name="machine_numbers[]" value="{{ $machine }}" {{ $usingMachine ? 'disabled' : '' }} class="h-5 w-5 rounded border-border text-primary">
                                                    <span>#{{ $machine }}</span>
                                                </label>
                                            @endfor
                                        </div>
                                    @endif
                                    <button type="submit" title="Start {{ $label }}" class="inline-flex h-11 touch-manipulation items-center justify-center gap-1.5 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:opacity-90">
                                        <span data-lucide="plus" class="h-4 w-4"></span>
                                        {{ $label }}
                                    </button>
                                </div>
                            </form>
                        @endforeach
                    </div>

                    <div class="mb-3 grid grid-cols-2 gap-1.5 xl:grid-cols-4">
                        @foreach(['fold' => $cycleTypes['fold'], 'iron' => $cycleTypes['iron']] as $type => $label)
                            <form method="POST" action="{{ route('admin.cycles.store', $order) }}">
                                @csrf
                                <input type="hidden" name="cycle_type" value="{{ $type }}">
                                <button type="submit" title="Start {{ $label }}" class="inline-flex h-11 w-full touch-manipulation items-center justify-center gap-1.5 rounded-md bg-primary px-2 text-sm font-semibold text-white hover:opacity-90">
                                    <span data-lucide="plus" class="h-4 w-4"></span>
                                    {{ $label }}
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
                                        class="inline-flex h-11 w-full cursor-not-allowed touch-manipulation items-center justify-center gap-1.5 rounded-md px-2 text-sm font-semibold text-white opacity-50 {{ $readyAction['classes'] }}"
                                        title="End all active cycles first"
                                    >
                                        <span data-lucide="{{ $readyAction['icon'] }}" class="h-4 w-4"></span>
                                        {{ $readyAction['label'] }}
                                    </button>
                                @else
                                    <button
                                        type="submit"
                                        x-on:click.prevent="Swal.fire({ title: @js($readyAction['label'].'?'), text: 'This will finish production and notify the customer that the laundry is ready.', icon: 'question', showCancelButton: true, confirmButtonColor: @js($readyAction['color']), confirmButtonText: 'Mark as Ready' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })"
                                        class="inline-flex h-11 w-full touch-manipulation items-center justify-center gap-1.5 rounded-md px-2 text-sm font-semibold text-white {{ $readyAction['classes'] }}"
                                    >
                                        <span data-lucide="{{ $readyAction['icon'] }}" class="h-4 w-4"></span>
                                        {{ $readyAction['label'] }}
                                    </button>
                                @endif
                            </form>
                        @endforeach
                    </div>

                @endif

                <div class="border-t border-border pt-3 dark:border-gray-800">
                    @if($order->cycles_count > $order->cycles->count())
                        <p class="text-xs text-muted">
                            Showing latest {{ $order->cycles->count() }} of {{ $order->cycles_count }} cycle records.
                        </p>
                    @endif

                    <div class="flex snap-x snap-mandatory flex-nowrap gap-2 overflow-x-auto pb-2">
                    @forelse($order->cycles as $cycle)
                        <div class="flex w-52 shrink-0 snap-start items-center justify-between gap-2 rounded-md border border-border bg-smoke px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ $cycleTypes[$cycle->cycle_type] ?? ucfirst($cycle->cycle_type) }} #{{ $cycle->cycle_number }}
                                    @if($cycle->machine_number)
                                        <span class="text-xs font-normal text-muted">Machine #{{ $cycle->machine_number }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-muted">
                                    {{ $cycle->started_at?->format('M d, h:i A') ?? 'Not started' }}
                                    @if($cycle->ended_at) - {{ $cycle->ended_at->format('h:i A') }} @endif
                                </p>
                                <p class="truncate text-xs text-muted">{{ $cycle->user?->name ?? 'System user' }}</p>
                            </div>

                            <div class="flex items-center gap-1">
                                @if(! $cycle->ended_at)
                                    <form method="POST" action="{{ route('admin.cycles.end', $cycle) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button title="End cycle" aria-label="End cycle" class="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-md border border-border hover:bg-white dark:border-gray-800 dark:hover:bg-gray-900">
                                            <span data-lucide="check" class="h-5 w-5"></span>
                                        </button>
                                    </form>
                                @else
                                    <span class="px-2 text-xs text-muted">Done</span>
                                @endif

                                <form method="POST" action="{{ route('admin.cycles.destroy', $cycle) }}" x-data>
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        title="Remove cycle"
                                        aria-label="Remove cycle"
                                        x-on:click.prevent="Swal.fire({ title: 'Remove cycle?', text: 'Use this to clean duplicate or accidental cycle taps.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Remove' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })"
                                        class="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900/70 dark:hover:bg-red-950/30"
                                    >
                                        <span data-lucide="trash" class="h-5 w-5"></span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="w-full rounded-md border border-dashed border-border py-4 text-center text-sm text-muted dark:border-gray-800">No cycles yet.</p>
                    @endforelse
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-white p-10 text-center text-sm text-muted dark:border-gray-800 dark:bg-gray-900">
                No active job orders to monitor.
            </div>
        @endforelse
        </div>
        </div>

        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $orders->links() }}</div>
    </section>
    </div>
</div>
@endsection
