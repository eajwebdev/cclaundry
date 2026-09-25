@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
<div
    x-data="dashboardPage(@js(route('dashboard.data', request()->query())), @js($dashboardData), @js($dateRangeValue))"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="dashboard" class="h-3.5 w-3.5"></span>
                {{ $canChooseBranch ? 'Executive overview' : 'Branch command center' }}
            </div>
            <h1 class="text-xl font-semibold tracking-normal">
                {{ $canChooseBranch ? 'Business Dashboard' : auth()->user()->branch?->name.' Dashboard' }}
            </h1>
            <p class="text-sm text-muted">
                Live sales, physical collections, workflow, receivables, and inventory signals.
                <span class="ml-1" x-text="`Updated ${data.generated_at}`"></span>
            </p>
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-[12rem_16rem_auto]">
            @if($canChooseBranch)
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
            @endif

            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" class="w-full bg-transparent text-sm outline-none" autocomplete="off">
            </div>

            <button class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                <span data-lucide="search" class="h-4 w-4"></span>
                Apply
            </button>
        </form>
    </div>

    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-7 sm:gap-3 lg:gap-3.5">
        <template x-for="card in statCards" :key="card.key">
            <div class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-border bg-white p-3.5 sm:p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
                <div>
                    <div class="flex items-center justify-between gap-1.5">
                        <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted sm:text-[11px]" x-text="card.label"></span>
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg sm:h-8 sm:w-8 sm:rounded-xl" :class="card.iconClass">
                            <span :data-lucide="card.icon" class="h-3.5 w-3.5 sm:h-4 sm:w-4"></span>
                        </div>
                    </div>
                    <div class="mt-2.5 flex items-center justify-between gap-2 sm:mt-3">
                        <div class="min-w-0">
                            <p class="truncate text-xl font-extrabold tracking-tight text-dark dark:text-white sm:text-2xl leading-none" x-text="data.stats[card.key]"></p>
                            <p class="mt-1 text-[9px] font-bold uppercase tracking-wider sm:text-[10px]" :class="card.subClass" x-text="card.subtitle"></p>
                        </div>
                        <div class="flex h-7 w-14 shrink-0 items-center justify-end sm:h-8 sm:w-16">
                            <svg class="h-full w-full" viewBox="0 0 72 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path :d="card.sparkArea" :fill="card.sparkColor" fill-opacity="0.2"/>
                                <path :d="card.sparkPath" :stroke="card.sparkColor" stroke-width="2" stroke-linecap="round"/>
                                <circle :cx="card.sparkDotX" :cy="card.sparkDotY" r="2.5" :fill="card.sparkColor"/>
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="mt-3.5 space-y-1.5 pt-1.5 border-t border-border/60 dark:border-gray-800/80">
                    <div class="flex items-center justify-between text-[10px] sm:text-[11px]">
                        <span class="text-muted" x-text="card.footerLabel"></span>
                        <span class="font-bold" :class="card.subClass" x-text="card.footerVal"></span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full" :class="card.trackClass">
                        <div class="h-full rounded-full transition-all duration-300" :class="card.barClass" :style="`width: ${card.percent || 75}%;`"></div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div class="overflow-hidden rounded-lg border border-amber-200 bg-white shadow-sm dark:border-amber-900/60 dark:bg-gray-900">
        <div class="flex flex-col gap-2 border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-500/10 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="inline-flex items-center gap-2 text-base font-semibold text-amber-900 dark:text-amber-200">
                    <span data-lucide="alert-triangle" class="h-4 w-4"></span>
                    Low Stock Purchase List
                </h2>
                <p class="text-sm text-amber-800/80 dark:text-amber-300/80">The 10 most recently updated active items at or below their quantity alarm.</p>
            </div>
            @if(auth()->user()->hasMenuAccess('inventory'))
                <a href="{{ route('admin.inventory.index', array_filter(['branch_id' => $selectedBranchId, 'stock_status' => 'low'])) }}" class="inline-flex h-8 items-center justify-center rounded-md border border-amber-300 bg-white px-3 text-sm font-medium text-amber-900 hover:bg-amber-100 dark:border-amber-800 dark:bg-gray-900 dark:text-amber-200">Manage stock</a>
            @endif
        </div>
        <div class="grid gap-px bg-border dark:bg-gray-800 sm:grid-cols-2 xl:grid-cols-5">
            <template x-for="item in data.low_stock_items" :key="item.id">
                <div class="bg-white p-3 dark:bg-gray-900">
                    <p class="truncate text-sm font-semibold" x-text="item.name"></p>
                    <p class="truncate text-xs text-muted" x-text="`${item.branch} · ${item.supplier}`"></p>
                    <p class="mt-2 text-sm font-bold text-red-700 dark:text-red-300">
                        <span x-text="`${item.quantity} ${item.unit}`"></span>
                        <span class="font-normal text-muted" x-text="` / alarm ${item.reorder_level}`"></span>
                    </p>
                </div>
            </template>
            <div x-show="data.low_stock_items.length === 0" class="bg-white p-6 text-center text-sm text-muted dark:bg-gray-900 sm:col-span-2 xl:col-span-5">All active inventory items are above their quantity alarms.</div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.8fr)]">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Sales Trend</h2>
                    <p class="text-sm text-muted">Sales owned by branch in the selected date range.</p>
                </div>
                <span data-lucide="payments" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-72">
                <canvas x-ref="salesChart"></canvas>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Workflow Status</h2>
                    <p class="text-sm text-muted">Job orders by status.</p>
                </div>
                <span data-lucide="activity" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-72">
                <canvas x-ref="statusChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Payment Mix</h2>
                    <p class="text-sm text-muted">Physical collections by method.</p>
                </div>
                <span data-lucide="payments" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-64">
                <canvas x-ref="paymentMixChart"></canvas>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">POS Type Mix</h2>
                    <p class="text-sm text-muted">Walk-in/drop-off versus delivery orders.</p>
                </div>
                <span data-lucide="shopping-bag" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-64">
                <canvas x-ref="transactionTypeChart"></canvas>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Financial Snapshot</h2>
                    <p class="text-sm text-muted">Collections, expenses, receivables, and payables.</p>
                </div>
                <span data-lucide="scale" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-64">
                <canvas x-ref="financialChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Top Selling Services</h2>
                    <p class="text-sm text-muted">Ranked by service sales amount in the selected range.</p>
                </div>
                <span data-lucide="services" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-72">
                <canvas x-ref="topServicesChart"></canvas>
            </div>
            <div class="mt-3 divide-y divide-border text-sm dark:divide-gray-800">
                <template x-for="service in data.top_services" :key="service.label">
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0 truncate font-medium" x-text="service.label"></span>
                        <span class="shrink-0 text-xs text-muted" x-text="`${service.quantity} qty · ${service.amount}`"></span>
                    </div>
                </template>
                <p x-show="data.top_services.length === 0" class="py-6 text-center text-sm text-muted">No service sales in this date range.</p>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Top Selling Presets</h2>
                    <p class="text-sm text-muted">Preset sales from POS preset cart selections.</p>
                </div>
                <span data-lucide="tag" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-72">
                <canvas x-ref="topPresetsChart"></canvas>
            </div>
            <div class="mt-3 divide-y divide-border text-sm dark:divide-gray-800">
                <template x-for="preset in data.top_presets" :key="preset.label">
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0 truncate font-medium" x-text="preset.label"></span>
                        <span class="shrink-0 text-xs text-muted" x-text="`${preset.orders_count} order(s) · ${preset.amount}`"></span>
                    </div>
                </template>
                <p x-show="data.top_presets.length === 0" class="py-6 text-center text-sm text-muted">Preset sales will appear for new orders saved from preset cart selections.</p>
            </div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-border px-4 py-3 dark:border-gray-800">
                <div>
                    <h2 class="text-base font-semibold">Recent Job Orders</h2>
                    <p class="text-sm text-muted">Live latest transactions.</p>
                </div>
                <a href="{{ route('admin.job-orders.index') }}" class="inline-flex h-8 items-center rounded-md border border-border px-3 text-sm hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">View all</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                        <tr>
                            <th class="px-4 py-3">JO #</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Branch</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border dark:divide-gray-800">
                        <template x-for="order in data.recent_orders" :key="order.id">
                            <tr>
                                <td class="px-4 py-3"><a :href="order.url" class="font-medium hover:text-primary" x-text="order.number"></a></td>
                                <td class="px-4 py-3" x-text="order.customer"></td>
                                <td class="px-4 py-3" x-text="order.branch"></td>
                                <td class="px-4 py-3"><span :class="order.status_badge" x-text="order.status"></span></td>
                                <td class="px-4 py-3 text-right font-medium" x-text="order.total"></td>
                            </tr>
                        </template>
                        <tr x-show="data.recent_orders.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-muted">No recent job orders.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Branch Sales</h2>
                    <p class="text-sm text-muted">Sales owned by branch in the selected range.</p>
                </div>
                <span data-lucide="branches" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-80">
                <canvas x-ref="branchSalesChart"></canvas>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-2 border-b border-border px-4 py-3 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold">Customers Who Trust {{ $appBusinessName }}</h2>
                <p class="text-sm text-muted">Customers with 10 or more laundry orders, ranked by total orders entrusted to the store.</p>
            </div>
            @if(auth()->user()->hasMenuAccess('customers'))
                <a href="{{ route('admin.customers.index') }}" class="inline-flex h-8 items-center justify-center rounded-md border border-border px-3 text-sm hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">View customers</a>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                    <tr>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Branch</th>
                        <th class="px-4 py-3 text-center">Laundry Orders</th>
                        <th class="px-4 py-3">Latest Service</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    <template x-for="customer in data.trusted_customers" :key="customer.id">
                        <tr>
                            <td class="px-4 py-3 font-medium" x-text="customer.name"></td>
                            <td class="px-4 py-3 text-muted" x-text="customer.phone"></td>
                            <td class="px-4 py-3" x-text="customer.branch"></td>
                            <td class="px-4 py-3 text-center font-semibold" x-text="customer.orders_count"></td>
                            <td class="px-4 py-3" x-text="customer.latest_order"></td>
                            <td class="px-4 py-3"><span :class="customer.status_badge" x-text="customer.status"></span></td>
                        </tr>
                    </template>
                    <tr x-show="data.trusted_customers.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-muted">Customer laundry history will appear here after the first job order.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Daily Website Visitors</h2>
                    <p class="text-sm text-muted">Distinct browsers per day. A browser is counted once each day, even if it reloads the page.</p>
                </div>
                <span data-lucide="mouse-pointer-click" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-64">
                <canvas x-ref="siteVisitsChart"></canvas>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">New vs Returning Visitors</h2>
                    <p class="text-sm text-muted">First-time browsers compared with browsers seen before the selected period.</p>
                </div>
                <span data-lucide="users" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-64">
                <canvas x-ref="visitorSummaryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
function dashboardPage(fetchUrl, initialData, initialDateRange) {
    return {
        data: initialData,
        dateRange: initialDateRange,
        salesChart: null,
        statusChart: null,
        paymentMixChart: null,
        transactionTypeChart: null,
        financialChart: null,
        topServicesChart: null,
        topPresetsChart: null,
        branchSalesChart: null,
        siteVisitsChart: null,
        visitorSummaryChart: null,
        statCards: [
            {
                key: 'sales',
                label: 'Sales Owned',
                subtitle: 'Revenue',
                icon: 'payments',
                iconClass: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400',
                subClass: 'text-emerald-600 dark:text-emerald-400',
                barClass: 'bg-emerald-500',
                trackClass: 'bg-emerald-100/70 dark:bg-emerald-950/60',
                sparkColor: '#059669',
                sparkPath: 'M2 22 C 18 20, 36 14, 66 6',
                sparkArea: 'M2 22 C 18 20, 36 14, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Branch Sales:',
                footerVal: 'Owned',
                percent: 88,
            },
            {
                key: 'collections',
                label: 'Physical Collections',
                subtitle: 'Received',
                icon: 'receipt',
                iconClass: 'bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400',
                subClass: 'text-teal-600 dark:text-teal-400',
                barClass: 'bg-teal-500',
                trackClass: 'bg-teal-100/70 dark:bg-teal-950/60',
                sparkColor: '#0d9488',
                sparkPath: 'M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12',
                sparkArea: 'M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 12,
                footerLabel: 'Total Collected:',
                footerVal: 'Net Cash In',
                percent: 82,
            },
            {
                key: 'cash_drawer',
                label: 'Expected Cash Drawer',
                subtitle: 'Physical Cash',
                icon: 'wallet',
                iconClass: 'bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400',
                subClass: 'text-amber-600 dark:text-amber-400',
                barClass: 'bg-amber-500',
                trackClass: 'bg-amber-100/70 dark:bg-amber-950/60',
                sparkColor: '#d97706',
                sparkPath: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6',
                sparkArea: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Register Status:',
                footerVal: 'Drawer Total',
                percent: 74,
            },
            {
                key: 'gcash',
                label: 'Expected GCash',
                subtitle: 'E-Wallet',
                icon: 'smartphone',
                iconClass: 'bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400',
                subClass: 'text-blue-600 dark:text-blue-400',
                barClass: 'bg-blue-500',
                trackClass: 'bg-blue-100/70 dark:bg-blue-950/60',
                sparkColor: '#2563eb',
                sparkPath: 'M2 18 C 16 18, 24 10, 36 14 C 48 18, 56 12, 66 8',
                sparkArea: 'M2 18 C 16 18, 24 10, 36 14 C 48 18, 56 12, 66 8 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 8,
                footerLabel: 'Digital Pay:',
                footerVal: 'Verified',
                percent: 68,
            },
            {
                key: 'expenses',
                label: 'Recorded Expenses',
                subtitle: 'Outflow',
                icon: 'expense',
                iconClass: 'bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400',
                subClass: 'text-rose-600 dark:text-rose-400',
                barClass: 'bg-rose-500',
                trackClass: 'bg-rose-100/70 dark:bg-rose-950/60',
                sparkColor: '#e11d48',
                sparkPath: 'M2 10 C 18 12, 34 18, 66 22',
                sparkArea: 'M2 10 C 18 12, 34 18, 66 22 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 22,
                footerLabel: 'Total Spent:',
                footerVal: 'Operational',
                percent: 55,
            },
            {
                key: 'accounts_payable',
                label: 'Accounts Payable',
                subtitle: 'Due to Pay',
                icon: 'receivables',
                iconClass: 'bg-orange-50 text-orange-600 dark:bg-orange-950/50 dark:text-orange-400',
                subClass: 'text-orange-600 dark:text-orange-400',
                barClass: 'bg-orange-500',
                trackClass: 'bg-orange-100/70 dark:bg-orange-950/60',
                sparkColor: '#f97316',
                sparkPath: 'M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12',
                sparkArea: 'M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 12,
                footerLabel: 'Supplier Payables:',
                footerVal: 'Pending',
                percent: 50,
            },
            {
                key: 'receivables',
                label: 'Unpaid Customer Balance',
                subtitle: 'To Collect',
                icon: 'receivables',
                iconClass: 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-400',
                subClass: 'text-violet-600 dark:text-violet-400',
                barClass: 'bg-violet-500',
                trackClass: 'bg-violet-100/70 dark:bg-violet-950/60',
                sparkColor: '#7c3aed',
                sparkPath: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6',
                sparkArea: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Credit Balance:',
                footerVal: 'Receivables',
                percent: 62,
            },
            {
                key: 'over_short',
                label: 'Z Reading Over / Short',
                subtitle: 'Reconciliation',
                icon: 'scale',
                iconClass: 'bg-cyan-50 text-cyan-600 dark:bg-cyan-950/50 dark:text-cyan-400',
                subClass: 'text-cyan-600 dark:text-cyan-400',
                barClass: 'bg-cyan-500',
                trackClass: 'bg-cyan-100/70 dark:bg-cyan-950/60',
                sparkColor: '#0891b2',
                sparkPath: 'M2 18 C 16 18, 24 10, 36 14 C 48 18, 56 12, 66 8',
                sparkArea: 'M2 18 C 16 18, 24 10, 36 14 C 48 18, 56 12, 66 8 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 8,
                footerLabel: 'Daily Audit:',
                footerVal: 'Balanced',
                percent: 78,
            },
            {
                key: 'orders',
                label: 'Orders in Period',
                subtitle: 'Job Orders',
                icon: 'jobOrders',
                iconClass: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400',
                subClass: 'text-indigo-600 dark:text-indigo-400',
                barClass: 'bg-indigo-500',
                trackClass: 'bg-indigo-100/70 dark:bg-indigo-950/60',
                sparkColor: '#4f46e5',
                sparkPath: 'M2 22 C 18 20, 36 14, 66 6',
                sparkArea: 'M2 22 C 18 20, 36 14, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Order Volume:',
                footerVal: 'Transactions',
                percent: 92,
            },
            {
                key: 'open_orders',
                label: 'Open Orders',
                subtitle: 'In Process',
                icon: 'laundry',
                iconClass: 'bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400',
                subClass: 'text-sky-600 dark:text-sky-400',
                barClass: 'bg-sky-500',
                trackClass: 'bg-sky-100/70 dark:bg-sky-950/60',
                sparkColor: '#0284c7',
                sparkPath: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6',
                sparkArea: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Active Cycles:',
                footerVal: 'In Store',
                percent: 70,
            },
            {
                key: 'ready_for_pickup',
                label: 'Ready for Pickup',
                subtitle: 'Store Pickup',
                icon: 'packageCheck',
                iconClass: 'bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400',
                subClass: 'text-teal-600 dark:text-teal-400',
                barClass: 'bg-teal-500',
                trackClass: 'bg-teal-100/70 dark:bg-teal-950/60',
                sparkColor: '#0d9488',
                sparkPath: 'M2 22 C 18 20, 36 14, 66 6',
                sparkArea: 'M2 22 C 18 20, 36 14, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Awaiting Claim:',
                footerVal: 'Pick up',
                percent: 75,
            },
            {
                key: 'ready_for_delivery',
                label: 'Ready for Delivery',
                subtitle: 'Outbound',
                icon: 'truck',
                iconClass: 'bg-orange-50 text-orange-600 dark:bg-orange-950/50 dark:text-orange-400',
                subClass: 'text-orange-600 dark:text-orange-400',
                barClass: 'bg-orange-500',
                trackClass: 'bg-orange-100/70 dark:bg-orange-950/60',
                sparkColor: '#f97316',
                sparkPath: 'M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12',
                sparkArea: 'M2 18 C 14 20, 22 8, 34 12 C 46 16, 54 8, 66 12 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 12,
                footerLabel: 'Dispatch Queue:',
                footerVal: 'Deliver',
                percent: 65,
            },
            {
                key: 'unique_site_visits',
                label: 'Website Visitors',
                subtitle: 'Web Traffic',
                icon: 'users',
                iconClass: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400',
                subClass: 'text-emerald-600 dark:text-emerald-400',
                barClass: 'bg-emerald-500',
                trackClass: 'bg-emerald-100/70 dark:bg-emerald-950/60',
                sparkColor: '#059669',
                sparkPath: 'M2 22 C 18 20, 36 14, 66 6',
                sparkArea: 'M2 22 C 18 20, 36 14, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Distinct Users:',
                footerVal: 'Browsers',
                percent: 86,
            },
            {
                key: 'daily_unique_site_visits',
                label: 'Daily Unique Visits',
                subtitle: 'Daily Active',
                icon: 'mouse-pointer-click',
                iconClass: 'bg-cyan-50 text-cyan-600 dark:bg-cyan-950/50 dark:text-cyan-400',
                subClass: 'text-cyan-600 dark:text-cyan-400',
                barClass: 'bg-cyan-500',
                trackClass: 'bg-cyan-100/70 dark:bg-cyan-950/60',
                sparkColor: '#0891b2',
                sparkPath: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6',
                sparkArea: 'M2 16 C 16 14, 26 22, 40 14 C 52 8, 58 10, 66 6 L 66 26 L 2 26 Z',
                sparkDotX: 66,
                sparkDotY: 6,
                footerLabel: 'Counter Status:',
                footerVal: '• Live Counter',
                percent: 100,
            },
        ],
        init() {
            this.$nextTick(() => {
                this.initDateRange();
                this.drawCharts();
                window.renderLucideIcons();
            });

            window.setInterval(() => this.refresh(), 30000);
        },
        initDateRange() {
            if (!window.flatpickr) return;

            window.flatpickr(this.$refs.dateRange, {
                mode: 'range',
                dateFormat: 'Y-m-d',
                defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                onClose: (dates, value) => this.dateRange = value,
            });
        },
        refresh() {
            fetch(fetchUrl, { headers: { 'Accept': 'application/json' } })
                .then(response => response.json())
                .then(payload => {
                    this.data = payload;
                    this.updateCharts();
                    this.$nextTick(() => window.renderLucideIcons());
                });
        },
        drawCharts() {
            const color = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#2E7D32';
            const grid = document.documentElement.classList.contains('dark') ? '#1f2937' : '#e2e8f0';

            this.salesChart = new window.Chart(this.$refs.salesChart, {
                type: 'line',
                data: {
                    labels: this.data.charts.sales.labels,
                    datasets: [{
                        label: 'Sales',
                        data: this.data.charts.sales.values,
                        borderColor: color,
                        backgroundColor: color + '22',
                        fill: true,
                        tension: 0.35,
                    }]
                },
                options: this.chartOptions(grid)
            });

            this.siteVisitsChart = new window.Chart(this.$refs.siteVisitsChart, {
                type: 'line',
                data: {
                    labels: this.data.charts.site_visits.labels,
                    datasets: [{
                        label: 'Daily unique visitors',
                        data: this.data.charts.site_visits.values,
                        borderColor: '#0ea5e9',
                        backgroundColor: '#0ea5e922',
                        fill: true,
                        tension: 0.35,
                    }]
                },
                options: this.chartOptions(grid)
            });

            this.visitorSummaryChart = new window.Chart(this.$refs.visitorSummaryChart, {
                type: 'doughnut',
                data: this.dataset('visitor_summary', ['#0ea5e9', '#f59e0b']),
                options: this.pieOptions()
            });

            this.statusChart = new window.Chart(this.$refs.statusChart, {
                type: 'bar',
                data: {
                    labels: this.data.charts.status.labels,
                    datasets: [{
                        label: 'Orders',
                        data: this.data.charts.status.values,
                        backgroundColor: color,
                        borderRadius: 6,
                    }]
                },
                options: this.chartOptions(grid)
            });

            this.paymentMixChart = new window.Chart(this.$refs.paymentMixChart, {
                type: 'doughnut',
                data: this.dataset('payment_mix', this.palette(color)),
                options: this.pieOptions()
            });

            this.transactionTypeChart = new window.Chart(this.$refs.transactionTypeChart, {
                type: 'pie',
                data: this.dataset('transaction_types', this.palette(color)),
                options: this.pieOptions()
            });

            this.financialChart = new window.Chart(this.$refs.financialChart, {
                type: 'bar',
                data: this.dataset('financial_snapshot', this.palette(color)),
                options: this.chartOptions(grid)
            });

            this.topServicesChart = new window.Chart(this.$refs.topServicesChart, {
                type: 'bar',
                data: this.dataset('top_services', this.palette(color)),
                options: this.horizontalOptions(grid)
            });

            this.topPresetsChart = new window.Chart(this.$refs.topPresetsChart, {
                type: 'bar',
                data: this.dataset('top_presets', this.palette(color)),
                options: this.horizontalOptions(grid)
            });

            this.branchSalesChart = new window.Chart(this.$refs.branchSalesChart, {
                type: 'bar',
                data: this.dataset('branch_sales', this.palette(color)),
                options: this.horizontalOptions(grid)
            });
        },
        updateCharts() {
            if (!this.salesChart || !this.statusChart) return;

            this.salesChart.data.labels = this.data.charts.sales.labels;
            this.salesChart.data.datasets[0].data = this.data.charts.sales.values;
            this.salesChart.update();

            this.siteVisitsChart.data.labels = this.data.charts.site_visits.labels;
            this.siteVisitsChart.data.datasets[0].data = this.data.charts.site_visits.values;
            this.siteVisitsChart.update();

            this.updateChart(this.visitorSummaryChart, 'visitor_summary');

            this.statusChart.data.labels = this.data.charts.status.labels;
            this.statusChart.data.datasets[0].data = this.data.charts.status.values;
            this.statusChart.update();

            this.updateChart(this.paymentMixChart, 'payment_mix');
            this.updateChart(this.transactionTypeChart, 'transaction_types');
            this.updateChart(this.financialChart, 'financial_snapshot');
            this.updateChart(this.topServicesChart, 'top_services');
            this.updateChart(this.topPresetsChart, 'top_presets');
            this.updateChart(this.branchSalesChart, 'branch_sales');
        },
        dataset(key, colors) {
            return {
                labels: this.data.charts[key].labels,
                datasets: [{
                    label: 'Amount',
                    data: this.data.charts[key].values,
                    backgroundColor: colors,
                    borderColor: colors,
                    borderRadius: 6,
                }]
            };
        },
        updateChart(chart, key) {
            if (!chart) return;

            chart.data.labels = this.data.charts[key].labels;
            chart.data.datasets[0].data = this.data.charts[key].values;
            chart.update();
        },
        palette(primary) {
            return [primary, '#0ea5e9', '#f59e0b', '#10b981', '#6366f1', '#ef4444', '#14b8a6', '#8b5cf6'];
        },
        chartOptions(grid) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: grid }, ticks: { color: '#64748B' } },
                    y: { beginAtZero: true, grid: { color: grid }, ticks: { color: '#64748B', precision: 0 } },
                }
            };
        },
        horizontalOptions(grid) {
            const options = this.chartOptions(grid);
            options.indexAxis = 'y';
            return options;
        },
        pieOptions() {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
            };
        }
    }
}
</script>
@endsection
