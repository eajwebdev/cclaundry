@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
<div
    x-data="dashboardPage(@js(route('dashboard.data', request()->query())), @js($dashboardData), @js($dateRangeValue), @js($activeTab), @js($currentPeriod))"
    class="min-h-screen bg-[#F5F2EC] text-[#1E2024] p-4 sm:p-6 lg:p-8 space-y-6 rounded-2xl"
    style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;"
>
    {{-- ============================================================== --}}
    {{-- HEADER SECTION                                                 --}}
    {{-- ============================================================== --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="text-[10px] sm:text-[11px] font-bold uppercase tracking-[0.18em] text-[#8C827A] font-serif">
                {{ $settings->business_name ?: 'CANES & COTTONS LAUNDRY' }}
            </div>
            <h1
                class="text-2xl sm:text-3xl font-bold tracking-tight text-[#1E2024] mt-0.5"
                style="font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;"
                x-text="headerTitle"
            ></h1>
            <p class="text-xs text-[#7A726A] mt-1 font-normal" x-text="headerSubtitle"></p>
        </div>

        {{-- Top Right Controls --}}
        <div class="flex flex-wrap items-center gap-3">
            {{-- TAB 1: Branch & Period Filters --}}
            <template x-if="activeTab === 'today'">
                <div class="flex flex-wrap items-center gap-3">
                    @if($canChooseBranch)
                        <div class="flex items-center gap-1.5 text-xs text-[#7A726A]">
                            <span>Branch</span>
                            <select
                                name="branch_id"
                                @change="changeBranch($event.target.value)"
                                class="h-8 rounded-md border border-[#DCD6CC] bg-white px-2.5 text-xs font-medium text-[#1E2024] outline-none shadow-2xs"
                            >
                                <option value="">All branches</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="inline-flex items-center rounded-lg bg-[#EAE5DC] p-0.5 text-xs border border-[#DDD7CE]">
                        <button
                            type="button"
                            @click="changePeriod('today')"
                            :class="currentPeriod === 'today' ? 'bg-white text-[#1E2024] font-semibold shadow-xs' : 'text-[#7A726A] hover:text-[#1E2024] font-medium'"
                            class="px-3 py-1 rounded-md transition-all"
                        >Today</button>
                        <button
                            type="button"
                            @click="changePeriod('this_week')"
                            :class="currentPeriod === 'this_week' ? 'bg-white text-[#1E2024] font-semibold shadow-xs' : 'text-[#7A726A] hover:text-[#1E2024] font-medium'"
                            class="px-3 py-1 rounded-md transition-all"
                        >This week</button>
                        <button
                            type="button"
                            @click="changePeriod('this_month')"
                            :class="currentPeriod === 'this_month' ? 'bg-white text-[#1E2024] font-semibold shadow-xs' : 'text-[#7A726A] hover:text-[#1E2024] font-medium'"
                            class="px-3 py-1 rounded-md transition-all"
                        >This month</button>
                    </div>
                </div>
            </template>

            {{-- TAB 2: Supplies Controls --}}
            <template x-if="activeTab === 'supplies'">
                <div class="flex items-center gap-3">
                    <div class="h-8 rounded-md border border-[#DCD6CC] bg-white px-3 flex items-center text-xs font-medium text-[#1E2024] shadow-2xs" x-text="`${currentMonthYear} ▾`"></div>
                    @if(auth()->user()->hasMenuAccess('inventory'))
                        <a href="{{ route('admin.inventory.index') }}" class="inline-flex items-center gap-1 rounded-md bg-[#82573A] hover:bg-[#6E482E] text-white px-3 py-1.5 text-xs font-semibold shadow-xs transition-colors">
                            + Record stock in
                        </a>
                    @endif
                </div>
            </template>

            {{-- TAB 3: Monthly Costs Controls --}}
            <template x-if="activeTab === 'costs'">
                <div class="flex items-center gap-3">
                    <div class="h-8 rounded-md border border-[#DCD6CC] bg-white px-3 flex items-center text-xs font-medium text-[#1E2024] shadow-2xs" x-text="`${currentMonthYear} ▾`"></div>
                    <a href="{{ route('admin.expenses.index') }}" class="inline-flex items-center gap-1 rounded-md bg-[#82573A] hover:bg-[#6E482E] text-white px-3 py-1.5 text-xs font-semibold shadow-xs transition-colors">
                        + Record a bill or purchase
                    </a>
                </div>
            </template>
        </div>
    </div>

    {{-- Sub-tabs row --}}
    <div class="flex items-center">
        <div class="inline-flex items-center gap-1 rounded-lg bg-[#EAE5DC] p-1 border border-[#DDD7CE]">
            <button
                type="button"
                @click="switchTab('today')"
                :class="activeTab === 'today' ? 'bg-white text-[#1E2024] font-semibold shadow-xs' : 'text-[#7A726A] hover:text-[#1E2024] font-medium'"
                class="px-4 py-1.5 rounded-md text-xs sm:text-sm transition-all"
            >
                Today
            </button>
            <button
                type="button"
                @click="switchTab('supplies')"
                :class="activeTab === 'supplies' ? 'bg-white text-[#1E2024] font-semibold shadow-xs' : 'text-[#7A726A] hover:text-[#1E2024] font-medium'"
                class="px-4 py-1.5 rounded-md text-xs sm:text-sm transition-all"
            >
                Supplies & inventory
            </button>
            <button
                type="button"
                @click="switchTab('costs')"
                :class="activeTab === 'costs' ? 'bg-white text-[#1E2024] font-semibold shadow-xs' : 'text-[#7A726A] hover:text-[#1E2024] font-medium'"
                class="px-4 py-1.5 rounded-md text-xs sm:text-sm transition-all"
            >
                Monthly costs
            </button>
        </div>
    </div>

    {{-- ============================================================== --}}
    {{-- TAB 1: TODAY AT THE SHOP                                       --}}
    {{-- ============================================================== --}}
    <div x-show="activeTab === 'today'" class="space-y-5">
        {{-- Row 1: 4 Stat Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Card 1: Sales today --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Sales today</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.today.sales_today"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="`${data.today.orders_count} orders · avg ${data.today.avg_per_order} per order`"></div>
                </div>
                <div class="mt-4 text-xs text-[#9E958C] border-t border-[#F2ECE3] pt-2" x-text="`vs. same day last week: ${data.today.sales_vs_last_week}`"></div>
            </div>

            {{-- Card 2: Money collected --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Money collected</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.today.money_collected"
                    ></div>
                    <div class="mt-3 h-1 w-full bg-[#E5F2E6] rounded-full overflow-hidden">
                        <div class="h-full bg-[#2E7D32]" style="width: 100%;"></div>
                    </div>
                </div>
                <div class="mt-4 text-xs text-[#1E2024] font-medium border-t border-[#F2ECE3] pt-2" x-text="`${data.today.collection_percent}% of today's sales collected`"></div>
            </div>

            {{-- Card 3: Customers still owe us --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Customers still owe us</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#B45309] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.today.customers_owe"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]">Unpaid balances on open orders</div>
                </div>
                <div class="mt-4 text-xs border-t border-[#F2ECE3] pt-2">
                    <a href="{{ route('admin.receivables.index') }}" class="font-medium text-[#82573A] hover:underline inline-flex items-center gap-1">
                        <span>See who to follow up</span>
                        <span>→</span>
                    </a>
                </div>
            </div>

            {{-- Card 4: Net for the day (Dark Card) --}}
            <div class="rounded-xl bg-[#181A1F] text-white p-5 shadow-xs flex flex-col justify-between border border-[#2B2D33]">
                <div>
                    <span class="text-xs font-normal text-gray-400">Net for the day</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-white leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.today.net_for_day"
                    ></div>
                    <div class="mt-1 text-xs text-gray-300" x-text="`Collected ${data.today.money_collected} – expenses ${data.today.expenses_day}`"></div>
                </div>
                <div class="mt-4 text-xs text-gray-400 border-t border-gray-800 pt-2" x-text="`Supplier bills due: ${data.today.bills_due}`"></div>
            </div>
        </div>

        {{-- Row 2: Pipeline + Cash Closing --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- Where the laundry is right now (8 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-8 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3">
                        <h2 class="text-sm font-bold text-[#1E2024]">Where the laundry is right now</h2>
                        <span class="text-xs text-[#8C827A]" x-text="`${data.today.pipeline.open_count} open · ${data.today.pipeline.done_count} done · ${data.today.pipeline.cancelled_count} cancelled`"></span>
                    </div>

                    {{-- 6 Stage Boxes --}}
                    <div class="grid grid-cols-3 sm:grid-cols-6 gap-2.5 mt-2">
                        {{-- 1. Washing --}}
                        <div class="rounded-lg border border-[#E5DFD6] p-2.5 text-center bg-[#FAF8F5]">
                            <span class="text-[10px] text-[#7A726A] block">1 · Washing</span>
                            <span class="text-2xl font-bold font-serif text-[#1E2024] mt-1 block" x-text="data.today.pipeline.washing"></span>
                        </div>
                        {{-- 2. Drying --}}
                        <div class="rounded-lg border border-[#E5DFD6] p-2.5 text-center bg-[#FAF8F5]">
                            <span class="text-[10px] text-[#7A726A] block">2 · Drying</span>
                            <span class="text-2xl font-bold font-serif text-[#1E2024] mt-1 block" x-text="data.today.pipeline.drying"></span>
                        </div>
                        {{-- 3. Folding --}}
                        <div class="rounded-lg border border-[#E5DFD6] p-2.5 text-center bg-[#FAF8F5]">
                            <span class="text-[10px] text-[#7A726A] block">3 · Folding</span>
                            <span class="text-2xl font-bold font-serif text-[#1E2024] mt-1 block" x-text="data.today.pipeline.folding"></span>
                        </div>
                        {{-- 4a. Ready for pickup (Cyan) --}}
                        <div class="rounded-lg border border-[#BDE0EB] p-2.5 text-center bg-[#E5F3F6]">
                            <span class="text-[10px] text-[#0E7490] font-medium block leading-tight">4a · Ready for<br>pickup</span>
                            <span class="text-2xl font-bold font-serif text-[#0E7490] mt-1 block" x-text="data.today.pipeline.ready_pickup"></span>
                        </div>
                        {{-- 4b. Ready for delivery (Amber) --}}
                        <div class="rounded-lg border border-[#FADBB8] p-2.5 text-center bg-[#FDF1E2]">
                            <span class="text-[10px] text-[#C2410C] font-medium block leading-tight">4b · Ready for<br>delivery</span>
                            <span class="text-2xl font-bold font-serif text-[#C2410C] mt-1 block" x-text="data.today.pipeline.ready_delivery"></span>
                        </div>
                        {{-- 5. Completed (Green) --}}
                        <div class="rounded-lg border border-[#CDE5D1] p-2.5 text-center bg-[#F0F7F1]">
                            <span class="text-[10px] text-[#15803D] block">5 · Completed</span>
                            <span class="text-2xl font-bold font-serif text-[#15803D] mt-1 block" x-text="data.today.pipeline.completed"></span>
                        </div>
                    </div>

                    {{-- Notice banner --}}
                    <div class="mt-4 rounded-lg bg-[#FCF8EE] border border-[#F5EACB] p-3 flex items-start gap-2.5">
                        <span class="h-4 w-4 rounded-full border border-amber-600 text-amber-700 flex items-center justify-center text-[10px] font-bold shrink-0 mt-0.5">!</span>
                        <p class="text-xs text-[#5C4F41] leading-relaxed" x-text="data.today.pipeline.notice"></p>
                    </div>
                </div>

                <div class="mt-4 text-[11px] text-[#8C827A] pt-2 border-t border-[#F2ECE3]" x-text="`Cancelled today: ${data.today.pipeline.cancelled_count} ${data.today.pipeline.cancelled_count === 1 ? 'order' : 'orders'}`"></div>
            </div>

            {{-- Cash check at closing (4 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-4 flex flex-col justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[#1E2024] pb-3">Cash check at closing</h2>

                    <div class="space-y-3 mt-1 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1.5 text-[#5C554E]">
                                <span class="h-2 w-2 rounded-xs bg-[#82573A]"></span>
                                Cash in drawer (expected)
                            </span>
                            <span class="font-bold text-[#1E2024]" x-text="data.today.cash_check.cash_drawer"></span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1.5 text-[#5C554E]">
                                <span class="h-2 w-2 rounded-xs bg-[#0284C7]"></span>
                                GCash (expected)
                            </span>
                            <span class="font-bold text-[#1E2024]" x-text="data.today.cash_check.gcash"></span>
                        </div>

                        <div class="pt-2 border-t border-[#E5DFD6] flex items-center justify-between font-bold">
                            <span class="text-[#1E2024]">Total expected</span>
                            <span class="text-sm text-[#1E2024]" x-text="data.today.cash_check.total_expected"></span>
                        </div>

                        {{-- Cash actually counted --}}
                        <div class="pt-2">
                            <div class="text-[#7A726A] mb-1">Cash actually counted</div>
                            <div class="flex items-center gap-1 text-[#8C827A]">
                                <span>₱</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    x-model="countedCash"
                                    placeholder="enter at closing"
                                    class="w-full px-2 py-1 text-xs rounded-md border border-[#DCD6CC] bg-[#FAF8F5] outline-none text-[#1E2024]"
                                >
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Difference --}}
                <div class="mt-4 pt-3 border-t border-[#F2ECE3] text-xs text-[#8C827A]" x-text="cashDifferenceText"></div>
            </div>
        </div>

        {{-- Row 3: What customers ordered + How orders came in + Supplies/Visitors --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- Col 1: What customers ordered (4 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-4 flex flex-col justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[#1E2024] pb-3">What customers ordered</h2>

                    <div class="space-y-4 mt-1">
                        <template x-for="item in data.today.ordered_services" :key="item.name">
                            <div class="flex items-start justify-between text-xs">
                                <div>
                                    <div class="font-semibold text-[#1E2024]" x-text="item.name"></div>
                                    <div class="text-[11px] text-[#8C827A] mt-0.5" x-text="item.detail"></div>
                                </div>
                                <div class="font-bold text-xs text-[#1E2024] shrink-0 ml-2" x-text="item.amount"></div>
                            </div>
                        </template>
                        <template x-if="!data.today.ordered_services || data.today.ordered_services.length === 0">
                            <div class="py-6 text-center text-[#8C827A] italic text-xs">No orders recorded for this period</div>
                        </template>
                    </div>
                </div>

                <div class="mt-4 text-xs text-[#7A726A] pt-2 border-t border-[#F2ECE3]" x-text="`Kilos washed today: ${data.today.total_kg_washed} kg`"></div>
            </div>

            {{-- Col 2: How orders came in (4 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-4 flex flex-col justify-between">
                <div>
                    <h2 class="text-sm font-bold text-[#1E2024] pb-3">How orders came in</h2>

                    {{-- Horizontal Stacked Bar --}}
                    <div class="mt-2 flex h-7 w-full rounded-md overflow-hidden bg-gray-100">
                        <template x-if="data.today.order_sources.delivery_count === 0 && data.today.order_sources.walk_in_count === 0">
                            <div class="h-full flex items-center justify-center text-[10px] text-[#8C827A] w-full">No orders recorded yet</div>
                        </template>
                        <template x-if="data.today.order_sources.delivery_count > 0 || data.today.order_sources.walk_in_count > 0">
                            <div class="flex h-full w-full">
                                <div class="h-full bg-[#0284C7] flex items-center px-2 text-[11px] font-bold text-white transition-all" :style="`width: ${data.today.order_sources.delivery_pct}%`" x-text="`~${data.today.order_sources.delivery_pct}%`"></div>
                                <div class="h-full bg-[#EA580C] transition-all" :style="`width: ${data.today.order_sources.walk_in_pct}%`"></div>
                            </div>
                        </template>
                    </div>

                    <div class="mt-4 space-y-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1.5 text-[#5C554E]">
                                <span class="h-2 w-2 rounded-xs bg-[#0284C7]"></span>
                                Delivery / pick-up
                            </span>
                            <span class="font-medium text-[#1E2024]" x-text="`~${data.today.order_sources.delivery_pct}%`"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1.5 text-[#5C554E]">
                                <span class="h-2 w-2 rounded-xs bg-[#EA580C]"></span>
                                Walk-in drop-off
                            </span>
                            <span class="font-medium text-[#1E2024]" x-text="`~${data.today.order_sources.walk_in_pct}%`"></span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-xs text-[#7A726A] pt-2 border-t border-[#F2ECE3]">
                    Most business is delivery — rider capacity matters.
                </div>
            </div>

            {{-- Col 3: Supplies & Website visitors (4 cols) --}}
            <div class="space-y-4 lg:col-span-4 flex flex-col justify-between">
                {{-- Supplies OK Card --}}
                <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex items-start gap-3">
                    <span class="text-base text-[#15803D] font-bold shrink-0 mt-0.5">✓</span>
                    <div>
                        <div class="text-xs font-bold text-[#1E2024]" x-text="data.today.supplies.title"></div>
                        <div class="text-xs text-[#7A726A] mt-0.5 leading-snug" x-text="data.today.supplies.description"></div>
                        <button type="button" @click="switchTab('supplies')" class="mt-1.5 text-xs font-medium text-[#82573A] hover:underline inline-block">
                            View supplies →
                        </button>
                    </div>
                </div>

                {{-- Website visitors today Card --}}
                <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-normal text-[#7A726A]">Website visitors today</span>
                        <div
                            class="mt-1 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                            style="font-family: 'Playfair Display', Georgia, serif;"
                            x-text="data.today.website.visitors_count"
                        ></div>
                        <div class="mt-1 text-xs text-[#7A726A]" x-text="data.today.website.subtitle"></div>
                    </div>
                    <div class="mt-3 text-xs text-[#8C827A] border-t border-[#F2ECE3] pt-2" x-text="data.today.website.repeat_text"></div>
                </div>
            </div>
        </div>

        {{-- Row 4: Today's orders --}}
        <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                <h2 class="text-sm font-bold text-[#1E2024]">Today's orders</h2>
                <a href="{{ route('admin.job-orders.index') }}" class="text-xs font-medium text-[#82573A] hover:underline">
                    View all orders →
                </a>
            </div>

            <div class="overflow-x-auto mt-2">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-[#8C827A] text-[10px] uppercase font-bold tracking-wider border-b border-[#F2ECE3]">
                            <th class="py-2.5 font-semibold">Order</th>
                            <th class="py-2.5 font-semibold">Customer</th>
                            <th class="py-2.5 font-semibold">Status</th>
                            <th class="py-2.5 font-semibold">Next Step</th>
                            <th class="py-2.5 font-semibold text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F7F3EC]">
                        <template x-for="order in data.today.recent_orders" :key="order.id">
                            <tr class="hover:bg-[#FAF8F5] transition-colors">
                                <td class="py-3 font-mono font-medium text-[#1E2024]">
                                    <a :href="order.url" class="hover:underline" x-text="order.number"></a>
                                </td>
                                <td class="py-3 text-[#5C554E]" x-text="order.customer"></td>
                                <td class="py-3">
                                    <span
                                        class="inline-block px-2.5 py-0.5 text-[10px] font-semibold rounded-full"
                                        :class="{
                                            'bg-[#FDF1E2] text-[#C2410C]': order.status === 'ready_for_delivery',
                                            'bg-[#E5F3F6] text-[#0E7490]': order.status === 'ready_for_pickup',
                                            'bg-[#F0F7F1] text-[#15803D]': order.status === 'completed',
                                            'bg-gray-100 text-gray-700': !['ready_for_delivery', 'ready_for_pickup', 'completed'].includes(order.status)
                                        }"
                                        x-text="order.status_label"
                                    ></span>
                                </td>
                                <td class="py-3 text-[#7A726A]" x-text="order.next_step"></td>
                                <td class="py-3 text-right font-bold text-[#1E2024]" x-text="order.total"></td>
                            </tr>
                        </template>
                        <template x-if="!data.today.recent_orders || data.today.recent_orders.length === 0">
                            <tr>
                                <td colspan="5" class="py-6 text-center text-[#8C827A] italic text-xs" x-text="currentPeriod === 'week' ? 'No orders recorded this week' : 'No orders recorded today'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================== --}}
    {{-- TAB 2: SUPPLIES & INVENTORY                                    --}}
    {{-- ============================================================== --}}
    <div x-show="activeTab === 'supplies'" class="space-y-5" style="display: none;">
        {{-- Row 1: 4 Stat Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Card 1: Reorder now --}}
            <div class="rounded-xl border border-[#F5D8D8] bg-[#FDF9F9] p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-rose-800">Reorder now</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-rose-700 leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="`${data.supplies.reorder_count} items`"
                    ></div>
                    <div class="mt-1 text-xs text-rose-800/80" x-text="data.supplies.urgent_warning"></div>
                </div>
                <div class="mt-4 text-xs border-t border-rose-200/60 pt-2">
                    <a href="{{ route('admin.inventory.index') }}" class="font-medium text-rose-800 hover:underline inline-flex items-center gap-1">
                        <span>Build shopping list</span>
                        <span>→</span>
                    </a>
                </div>
            </div>

            {{-- Card 2: Supplies used this month --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Supplies used this month</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.supplies.supplies_used_month"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="data.supplies.restocked_note"></div>
                </div>
                <div class="mt-4 text-xs text-[#8C827A] border-t border-[#F2ECE3] pt-2">
                    Net monthly material consumption
                </div>
            </div>

            {{-- Card 3: Supply cost per kg washed --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Supply cost per kg washed</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.supplies.cost_per_kg"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="data.supplies.cost_per_kg_sub"></div>
                </div>
                <div class="mt-4 text-xs text-[#15803D] font-medium border-t border-[#F2ECE3] pt-2">
                    Target under ₱1.40
                </div>
            </div>

            {{-- Card 4: Add-on sales --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Add-on sales</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#0D9488] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.supplies.addon_sales_total"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="data.supplies.addon_sales_sub"></div>
                </div>
                <div class="mt-4 text-xs text-[#82573A] font-medium border-t border-[#F2ECE3] pt-2">
                    Incremental revenue per cycle
                </div>
            </div>
        </div>

        {{-- Row 2: Consumables — stock left (Full-Width Card) --}}
        <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-[#F2ECE3] gap-2">
                <h2 class="text-sm font-bold text-[#1E2024]">Consumables — stock left</h2>
                <div class="flex items-center gap-3 text-xs text-[#7A726A]">
                    <span class="flex items-center gap-1 font-mono text-gray-500 font-bold">| <span class="font-sans font-normal text-xs text-[#7A726A]">Reorder point</span></span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-[#15803D]"></span> OK</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-[#D97706]"></span> Low</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-[#E11D48]"></span> Reorder now</span>
                </div>
            </div>

            <div class="overflow-x-auto mt-2">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-[#8C827A] text-[10px] uppercase font-bold tracking-wider border-b border-[#F2ECE3]">
                            <th class="py-2.5 font-semibold">Item</th>
                            <th class="py-2.5 font-semibold">On Hand</th>
                            <th class="py-2.5 font-semibold" x-text="'Used in ' + currentMonthName"></th>
                            <th class="py-2.5 font-semibold">Daily Use</th>
                            <th class="py-2.5 font-semibold text-center">Days Left</th>
                            <th class="py-2.5 font-semibold text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F7F3EC]">
                        <template x-for="item in data.supplies.consumables" :key="item.name">
                            <tr class="hover:bg-[#FAF8F5] transition-colors">
                                <td class="py-3 font-semibold text-[#1E2024]">
                                    <div x-text="item.name"></div>
                                    <div class="text-[10px] text-[#8C827A] font-normal" x-text="item.unit"></div>
                                </td>
                                <td class="py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-28 sm:w-36 h-2 bg-[#EAE5DC] rounded-full relative overflow-hidden">
                                            <div
                                                class="h-full rounded-full transition-all"
                                                :class="{
                                                    'bg-[#E11D48]': item.status === 'Reorder now',
                                                    'bg-[#D97706]': item.status === 'Low soon',
                                                    'bg-[#15803D]': item.status === 'OK'
                                                }"
                                                :style="`width: ${item.percent}%`"
                                            ></div>
                                            <span class="absolute top-0 bottom-0 w-0.5 bg-[#4B5563] z-10" :style="`left: ${item.marker_pct ?? 35}%;`"></span>
                                        </div>
                                        <span class="font-bold text-xs text-[#1E2024] whitespace-nowrap" x-text="item.quantity"></span>
                                    </div>
                                </td>
                                <td class="py-3 text-[#5C554E]" x-text="item.used_this_month || item.used_in_sep"></td>
                                <td class="py-3 text-[#5C554E]" x-text="item.daily_use"></td>
                                <td class="py-3 text-center font-bold text-sm" :class="item.days_left <= 7 ? 'text-rose-600' : 'text-[#1E2024]'" x-text="item.days_left"></td>
                                <td class="py-3 text-right">
                                    <span
                                        class="inline-block px-2.5 py-0.5 text-[10px] font-semibold rounded-full"
                                        :class="{
                                            'bg-[#FEE2E2] text-[#B91C1C] border border-[#FECACA]': item.status === 'Reorder now',
                                            'bg-[#FEF3C7] text-[#B45309] border border-[#FDE68A]': item.status === 'Low soon',
                                            'bg-[#DCFCE7] text-[#15803D] border border-[#BBF7D0]': item.status === 'OK'
                                        }"
                                        x-text="item.status"
                                    ></span>
                                </td>
                            </tr>
                        </template>
                        <template x-if="!data.supplies.consumables || data.supplies.consumables.length === 0">
                            <tr>
                                <td colspan="6" class="py-6 text-center text-[#8C827A] italic text-xs">No active consumables found in inventory</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Row 3: Stock movement by week + Add-ons sold --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- Stock movement by week (6 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                        <h2 class="text-sm font-bold text-[#1E2024]">Stock movement by week</h2>
                        <div class="flex items-center gap-3 text-[11px] text-[#7A726A]">
                            <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#0F766E]"></span> Restocked (in)</span>
                            <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#D97706]"></span> Used (out)</span>
                        </div>
                    </div>

                    {{-- Weekly In / Out Bars (Center Baseline) --}}
                    <div class="flex items-center gap-3 mt-6">
                        {{-- Left Axis Labels --}}
                        <div class="flex flex-col justify-between h-36 text-[10px] font-semibold text-[#7A726A] pb-8 shrink-0">
                            <span>In ↑</span>
                            <span>Out ↓</span>
                        </div>

                        {{-- 4 Columns --}}
                        <div class="grid grid-cols-4 gap-2 flex-1 text-center">
                            <template x-for="w in data.supplies.stock_movement_by_week" :key="w.week">
                                <div class="flex flex-col items-center">
                                    {{-- Upper Half: In (Restocked) --}}
                                    <div class="h-16 w-full flex flex-col justify-end items-center">
                                        <div class="text-[10px] font-bold text-[#0F766E] mb-1" x-text="w.in > 0 ? `₱${w.in.toLocaleString()}` : ''"></div>
                                        <template x-if="w.in > 0">
                                            <div class="w-10 bg-[#0F766E] rounded-t-xs" :style="`height: ${Math.max(4, Math.min(48, Math.round((w.in / (data.supplies.max_weekly_val || 1)) * 48)))}px;`"></div>
                                        </template>
                                        <template x-if="w.in === 0">
                                            <span class="text-[9px] text-[#A8A29E] italic pb-1">no restock</span>
                                        </template>
                                    </div>

                                    {{-- Center Baseline --}}
                                    <div class="w-full h-px bg-[#DCD6CC]"></div>

                                    {{-- Lower Half: Out (Used) --}}
                                    <div class="h-16 w-full flex flex-col justify-start items-center">
                                        <template x-if="w.out > 0">
                                            <div class="w-10 bg-[#D97706] rounded-b-xs" :style="`height: ${Math.max(4, Math.min(48, Math.round((w.out / (data.supplies.max_weekly_val || 1)) * 48)))}px;`"></div>
                                        </template>
                                        <template x-if="w.out === 0">
                                            <span class="text-[9px] text-[#A8A29E] italic pt-1">no usage</span>
                                        </template>
                                        <div class="text-[10px] font-bold text-[#D97706] mt-1" x-text="w.out > 0 ? `₱${w.out.toLocaleString()}` : ''"></div>
                                    </div>

                                    {{-- Week & Stock Label --}}
                                    <div class="text-xs font-semibold text-[#1E2024] mt-1" x-text="w.week"></div>
                                    <div class="text-[10px] text-[#8C827A]" x-text="`stock ₱${w.stock.toLocaleString()}`"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-xs text-[#7A726A] pt-2 border-t border-[#F2ECE3]" x-text="data.supplies.movement_caption"></div>
            </div>

            {{-- Add-ons sold (6 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                        <h2 class="text-sm font-bold text-[#1E2024]">Add-ons sold</h2>
                        <span class="text-xs font-semibold text-[#7A726A]" x-text="`${data.supplies.addon_sales_total} in ${currentMonthName}`"></span>
                    </div>

                    <div class="space-y-3 mt-3">
                        <template x-for="addon in data.supplies.addons_sold" :key="addon.name">
                            <div class="flex items-center justify-between text-xs">
                                <div class="w-40 shrink-0">
                                    <div class="font-semibold text-[#1E2024]" x-text="addon.name"></div>
                                    <div class="text-[10px] text-[#8C827A]" x-text="addon.rate_label"></div>
                                </div>
                                <div class="flex-1 mx-3">
                                    <div class="h-2 w-full bg-[#EAE5DC] rounded-full overflow-hidden">
                                        <div class="h-full bg-[#0F766E] rounded-full" :style="`width: ${(addon.revenue_raw / 1000) * 100}%`"></div>
                                    </div>
                                </div>
                                <div class="w-16 text-right">
                                    <div class="font-bold text-[#1E2024]" x-text="addon.revenue"></div>
                                    <div class="text-[10px] text-[#8C827A]" x-text="addon.sold"></div>
                                </div>
                            </div>
                        </template>
                        <template x-if="!data.supplies.addons_sold || data.supplies.addons_sold.length === 0">
                            <div class="py-6 text-center text-[#8C827A] italic text-xs">No add-ons sold yet this month</div>
                        </template>
                    </div>
                </div>

                <div class="mt-4 text-xs text-[#7A726A] pt-2 border-t border-[#F2ECE3]" x-text="data.supplies.upsell_tip"></div>
            </div>
        </div>

        {{-- Row 4: Latest stock movements --}}
        <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                <h2 class="text-sm font-bold text-[#1E2024]">Latest stock movements</h2>
                <a href="{{ route('admin.inventory.index') }}" class="text-xs font-medium text-[#82573A] hover:underline">
                    Full stock ledger →
                </a>
            </div>

            <div class="overflow-x-auto mt-2">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-[#8C827A] text-[10px] uppercase font-bold tracking-wider border-b border-[#F2ECE3]">
                            <th class="py-2.5 font-semibold">Date</th>
                            <th class="py-2.5 font-semibold">Type</th>
                            <th class="py-2.5 font-semibold">Item</th>
                            <th class="py-2.5 font-semibold">Qty</th>
                            <th class="py-2.5 font-semibold">Note</th>
                            <th class="py-2.5 font-semibold text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F7F3EC]">
                        <template x-for="m in data.supplies.stock_movements" :key="m.item + m.date">
                            <tr class="hover:bg-[#FAF8F5] transition-colors">
                                <td class="py-2.5 text-[#7A726A]" x-text="m.date"></td>
                                <td class="py-2.5">
                                    <span class="inline-block px-2 py-0.5 text-[10px] font-semibold rounded-md border" :class="m.type_badge" x-text="m.type"></span>
                                </td>
                                <td class="py-2.5 font-semibold text-[#1E2024]" x-text="m.item"></td>
                                <td class="py-2.5 font-mono font-medium text-[#1E2024]" x-text="m.qty"></td>
                                <td class="py-2.5 text-[#7A726A]" x-text="m.note"></td>
                                <td class="py-2.5 text-right font-bold text-[#1E2024]" x-text="m.value"></td>
                            </tr>
                        </template>
                        <template x-if="!data.supplies.stock_movements || data.supplies.stock_movements.length === 0">
                            <tr>
                                <td colspan="6" class="py-6 text-center text-[#8C827A] italic text-xs">No stock movements recorded yet</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================== --}}
    {{-- TAB 3: MONTHLY COSTS                                           --}}
    {{-- ============================================================== --}}
    <div x-show="activeTab === 'costs'" class="space-y-5" style="display: none;">
        {{-- Row 1: 4 Stat Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Card 1: Bills this month --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Bills this month</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.monthly_costs.bills_this_month"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="data.monthly_costs.bills_vs_last_month || data.monthly_costs.bills_vs_aug"></div>
                </div>
                <div class="mt-4 text-xs text-[#8C827A] border-t border-[#F2ECE3] pt-2">
                    Rent, electricity, water, LPG, taxes
                </div>
            </div>

            {{-- Card 2: Payroll this month --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Payroll this month</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#1E2024] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.monthly_costs.payroll_this_month"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="data.monthly_costs.payroll_sub"></div>
                </div>
                <div class="mt-4 text-xs text-[#8C827A] border-t border-[#F2ECE3] pt-2">
                    Base wages + employer share
                </div>
            </div>

            {{-- Card 3: Still to pay --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-normal text-[#7A726A]">Still to pay</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-[#B45309] leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.monthly_costs.still_to_pay"
                    ></div>
                    <div class="mt-1 text-xs text-[#7A726A]" x-text="data.monthly_costs.still_to_pay_sub"></div>
                </div>
                <div class="mt-4 text-xs text-amber-700 font-medium border-t border-[#F2ECE3] pt-2">
                    Due in current cycle
                </div>
            </div>

            {{-- Card 4: Left for the shop (Dark Card) --}}
            <div class="rounded-xl bg-[#181A1F] text-white p-5 shadow-xs flex flex-col justify-between border border-[#2B2D33]">
                <div>
                    <span class="text-xs font-normal text-gray-400">Left for the shop</span>
                    <div
                        class="mt-2 text-3xl sm:text-[34px] font-bold text-white leading-tight"
                        style="font-family: 'Playfair Display', Georgia, serif;"
                        x-text="data.monthly_costs.left_for_shop"
                    ></div>
                    <div class="mt-1 text-xs text-gray-300" x-text="data.monthly_costs.left_margin_sub"></div>
                </div>
                <div class="mt-4 text-xs text-gray-400 border-t border-gray-800 pt-2">
                    Operating margin after all costs
                </div>
            </div>
        </div>

        {{-- Row 2: Monthly bills (6 months) + September bills --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- Monthly bills — last 6 months (7 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-7 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                        <h2 class="text-sm font-bold text-[#1E2024]">Monthly bills — last 6 months</h2>
                        <span class="text-xs text-[#8C827A]" x-text="data.monthly_costs.bills_history_6m.range_label || ''"></span>
                    </div>

                    <div class="flex items-center gap-3 text-xs text-[#7A726A] mt-3">
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#82573A]"></span> Rent</span>
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#1D4ED8]"></span> Electricity</span>
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#0284C7]"></span> Water</span>
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#F59E0B]"></span> LPG</span>
                        <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-xs bg-[#1E293B]"></span> Taxes</span>
                    </div>

                    {{-- 6-Month Stacked Bars --}}
                    <div class="grid grid-cols-6 gap-2 mt-6 text-center items-end">
                        <template x-for="(month, idx) in data.monthly_costs.bills_history_6m.months" :key="month">
                            <div class="flex flex-col items-center">
                                <div class="text-[10px] font-bold text-[#1E2024]" x-text="data.monthly_costs.bills_history_6m.totals[idx]"></div>
                                <div class="w-8 h-32 bg-[#FAF8F5] rounded-md my-1.5 flex flex-col justify-end overflow-hidden border border-[#EAE5DC]">
                                    <template x-if="data.monthly_costs.bills_history_6m.breakdown && data.monthly_costs.bills_history_6m.breakdown[idx]?.total > 0">
                                        <div class="w-full flex flex-col justify-end" :style="`height: ${Math.max(8, Math.min(100, Math.round((data.monthly_costs.bills_history_6m.breakdown[idx].total / (data.monthly_costs.bills_history_6m.max_bill || 1)) * 100)))}%`">
                                            <div class="w-full bg-[#1E293B]" :style="`height: ${(data.monthly_costs.bills_history_6m.breakdown[idx].taxes / data.monthly_costs.bills_history_6m.breakdown[idx].total) * 100}%`"></div>
                                            <div class="w-full bg-[#F59E0B]" :style="`height: ${(data.monthly_costs.bills_history_6m.breakdown[idx].lpg / data.monthly_costs.bills_history_6m.breakdown[idx].total) * 100}%`"></div>
                                            <div class="w-full bg-[#0284C7]" :style="`height: ${(data.monthly_costs.bills_history_6m.breakdown[idx].water / data.monthly_costs.bills_history_6m.breakdown[idx].total) * 100}%`"></div>
                                            <div class="w-full bg-[#1D4ED8]" :style="`height: ${(data.monthly_costs.bills_history_6m.breakdown[idx].electricity / data.monthly_costs.bills_history_6m.breakdown[idx].total) * 100}%`"></div>
                                            <div class="w-full bg-[#82573A]" :style="`height: ${(data.monthly_costs.bills_history_6m.breakdown[idx].rent / data.monthly_costs.bills_history_6m.breakdown[idx].total) * 100}%`"></div>
                                        </div>
                                    </template>
                                    <template x-if="!data.monthly_costs.bills_history_6m.breakdown || data.monthly_costs.bills_history_6m.breakdown[idx]?.total <= 0">
                                        <div class="w-full h-1 bg-[#DCD6CC] rounded-xs"></div>
                                    </template>
                                </div>
                                <div class="text-xs font-semibold text-[#7A726A]" x-text="month"></div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-[#F2ECE3] space-y-1 text-xs text-[#7A726A]">
                    <div x-text="data.monthly_costs.bills_history_6m.insight_1"></div>
                    <div class="text-[11px] text-[#8C827A]" x-text="data.monthly_costs.bills_history_6m.insight_2"></div>
                </div>
            </div>

            {{-- September bills checklist (5 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                        <h2 class="text-sm font-bold text-[#1E2024]" x-text="`${currentMonthName} bills`"></h2>
                        <span class="text-xs font-semibold text-[#8C827A]" x-text="data.monthly_costs.paid_bills_count"></span>
                    </div>

                    <div class="space-y-3 mt-3">
                        <template x-for="bill in data.monthly_costs.bills_list" :key="bill.title">
                            <div class="flex items-center justify-between text-xs pb-2 border-b border-[#FAF8F5] last:border-0">
                                <div>
                                    <div class="font-semibold text-[#1E2024]" x-text="bill.title"></div>
                                    <div class="text-[10px] text-[#8C827A]" x-text="bill.due_info"></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-[#1E2024]" x-text="bill.amount"></span>
                                    <template x-if="bill.is_paid">
                                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-md bg-[#DCFCE7] text-[#15803D]">Paid</span>
                                    </template>
                                    <template x-if="!bill.is_paid">
                                        <a href="{{ route('admin.accounts-payable.index') }}" class="px-2 py-0.5 text-[10px] font-medium rounded-md border border-[#DCD6CC] bg-white text-[#1E2024] hover:bg-gray-50 shadow-2xs">Mark paid</a>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="!data.monthly_costs.bills_list || data.monthly_costs.bills_list.length === 0">
                            <div class="py-6 text-center text-[#8C827A] italic text-xs">No bills or accounts payable recorded for this month</div>
                        </template>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-[#E5DFD6] flex items-center justify-between text-xs">
                    <span class="font-bold text-[#1E2024]">Total</span>
                    <span class="font-bold text-sm text-[#1E2024]" x-text="data.monthly_costs.bills_this_month"></span>
                </div>
            </div>
        </div>

        {{-- Row 3: Payroll — September (Full-Width Card) --}}
        <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                <h2 class="text-sm font-bold text-[#1E2024]" x-text="`Payroll — ${currentMonthName}`"></h2>
                <span class="text-xs text-[#8C827A]">Paid twice a month · 15th and 30th</span>
            </div>

            {{-- 4 Mini Payroll Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                <div class="rounded-lg bg-[#FAF8F5] p-3 border border-[#EAE5DC]">
                    <span class="text-[10px] text-[#8C827A] block">Total payroll cost</span>
                    <span class="text-lg font-bold text-[#1E2024] block mt-0.5" x-text="data.monthly_costs.payroll_summary.total_cost"></span>
                    <span class="text-[10px] text-[#7A726A] block mt-0.5" x-text="data.monthly_costs.payroll_summary.breakdown"></span>
                </div>
                <div class="rounded-lg bg-[#FAF8F5] p-3 border border-[#EAE5DC]">
                    <span class="text-[10px] text-[#8C827A] block">Attendance</span>
                    <span class="text-lg font-bold text-[#1E2024] block mt-0.5" x-text="data.monthly_costs.payroll_summary.attendance_pct"></span>
                    <span class="text-[10px] text-[#7A726A] block mt-0.5" x-text="data.monthly_costs.payroll_summary.attendance_sub"></span>
                </div>
                <div class="rounded-lg bg-[#FAF8F5] p-3 border border-[#EAE5DC]">
                    <span class="text-[10px] text-[#8C827A] block">Payroll per kg washed</span>
                    <span class="text-lg font-bold text-[#1E2024] block mt-0.5" x-text="data.monthly_costs.payroll_summary.payroll_per_kg"></span>
                    <span class="text-[10px] text-[#7A726A] block mt-0.5" x-text="data.monthly_costs.payroll_summary.per_kg_sub"></span>
                </div>
                <div class="rounded-lg bg-[#FAF8F5] p-3 border border-[#EAE5DC]">
                    <span class="text-[10px] text-[#8C827A] block">Next payout</span>
                    <span class="text-lg font-bold text-[#1E2024] block mt-0.5" x-text="data.monthly_costs.payroll_summary.next_payout"></span>
                    <span class="text-[10px] text-[#7A726A] block mt-0.5" x-text="data.monthly_costs.payroll_summary.next_payout_sub"></span>
                </div>
            </div>

            {{-- Employee Table --}}
            <div class="overflow-x-auto mt-4">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-[#8C827A] text-[10px] uppercase font-bold tracking-wider border-b border-[#F2ECE3]">
                            <th class="py-2.5 font-semibold">Employee</th>
                            <th class="py-2.5 font-semibold">Daily Rate</th>
                            <th class="py-2.5 font-semibold">Days</th>
                            <th class="py-2.5 font-semibold">Wages</th>
                            <th class="py-2.5 font-semibold">Employer Share</th>
                            <th class="py-2.5 font-semibold">Total Cost</th>
                            <th class="py-2.5 font-semibold text-right">Pay Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F7F3EC]">
                        <template x-for="emp in data.monthly_costs.employees" :key="emp.name">
                            <tr class="hover:bg-[#FAF8F5] transition-colors">
                                <td class="py-2.5">
                                    <div class="flex items-center gap-2">
                                        <span class="h-6 w-6 rounded-full bg-[#EAE5DC] text-[#82573A] text-[10px] font-bold flex items-center justify-center shrink-0" x-text="emp.initials"></span>
                                        <div>
                                            <div class="font-semibold text-[#1E2024]" x-text="emp.name"></div>
                                            <div class="text-[10px] text-[#8C827A]" x-text="emp.role"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2.5 text-[#5C554E]" x-text="emp.rate"></td>
                                <td class="py-2.5 text-[#5C554E]" x-text="emp.days"></td>
                                <td class="py-2.5 font-medium text-[#1E2024]" x-text="emp.wages"></td>
                                <td class="py-2.5 text-[#7A726A]" x-text="emp.employer_share"></td>
                                <td class="py-2.5 font-bold text-[#1E2024]" x-text="emp.total_cost"></td>
                                <td class="py-2.5 text-right whitespace-nowrap">
                                    <span class="inline-block px-1.5 py-0.5 text-[9px] font-medium rounded-xs bg-[#DCFCE7] text-[#15803D] mr-1" x-text="emp.pay_status_1"></span>
                                    <span class="inline-block px-1.5 py-0.5 text-[9px] font-medium rounded-xs bg-[#FEF3C7] text-[#B45309]" x-text="emp.pay_status_2"></span>
                                </td>
                            </tr>
                        </template>
                        <template x-if="!data.monthly_costs.employees || data.monthly_costs.employees.length === 0">
                            <tr>
                                <td colspan="7" class="py-6 text-center text-[#8C827A] italic text-xs">No active staff members found in system</td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="border-t border-[#EAE5DC] font-semibold text-[#1E2024] bg-[#FAF8F5]" x-show="data.monthly_costs.employees && data.monthly_costs.employees.length > 0">
                        <tr>
                            <td class="py-2.5" x-text="`${data.monthly_costs.employees.length} employees`"></td>
                            <td class="py-2.5 text-[#7A726A]">—</td>
                            <td class="py-2.5 text-[#5C554E]" x-text="data.monthly_costs.total_employee_days"></td>
                            <td class="py-2.5" x-text="data.monthly_costs.total_employee_wages"></td>
                            <td class="py-2.5 text-[#7A726A]" x-text="data.monthly_costs.total_employee_share"></td>
                            <td class="py-2.5 font-bold" x-text="data.monthly_costs.total_employee_cost"></td>
                            <td class="py-2.5 text-right text-[10px] text-[#8C827A] font-normal">Employer share = SSS, PhilHealth, Pag-IBIG (estimate)</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Row 4: Waterfall Card + Other Purchases --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- From sales to what's left (7 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-7 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                        <h2 class="text-sm font-bold text-[#1E2024]">From sales to what's left</h2>
                        <span class="text-xs text-[#8C827A]" x-text="currentMonthName"></span>
                    </div>

                    {{-- Waterfall Visual Flow --}}
                    <div class="grid grid-cols-6 gap-2 mt-6 text-center items-end">
                        <div class="flex flex-col items-center">
                            <span class="text-[10px] font-bold text-[#0F766E]" x-text="data.monthly_costs.waterfall.sales"></span>
                            <div class="w-8 bg-[#0F766E] rounded-xs my-1.5 transition-all" :style="`height: ${Math.max(4, Math.min(112, Math.round((data.monthly_costs.waterfall.sales_raw / (data.monthly_costs.waterfall.max_val || 1)) * 112)))}px;`"></div>
                            <span class="text-[10px] font-semibold text-[#1E2024]">Sales</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <span class="text-[10px] font-bold text-[#4F46E5]" x-text="data.monthly_costs.waterfall.payroll"></span>
                            <div class="w-8 bg-[#4F46E5] rounded-xs my-1.5 transition-all" :style="`height: ${Math.max(4, Math.min(112, Math.round((data.monthly_costs.waterfall.payroll_raw / (data.monthly_costs.waterfall.max_val || 1)) * 112)))}px;`"></div>
                            <span class="text-[10px] font-semibold text-[#1E2024]">Payroll</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <span class="text-[10px] font-bold text-[#EA580C]" x-text="data.monthly_costs.waterfall.bills"></span>
                            <div class="w-8 bg-[#EA580C] rounded-xs my-1.5 transition-all" :style="`height: ${Math.max(4, Math.min(112, Math.round((data.monthly_costs.waterfall.bills_raw / (data.monthly_costs.waterfall.max_val || 1)) * 112)))}px;`"></div>
                            <span class="text-[10px] font-semibold text-[#1E2024]">Bills</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <span class="text-[10px] font-bold text-[#BE123C]" x-text="data.monthly_costs.waterfall.supplies"></span>
                            <div class="w-8 bg-[#BE123C] rounded-xs my-1.5 transition-all" :style="`height: ${Math.max(4, Math.min(112, Math.round((data.monthly_costs.waterfall.supplies_raw / (data.monthly_costs.waterfall.max_val || 1)) * 112)))}px;`"></div>
                            <span class="text-[10px] font-semibold text-[#1E2024]">Supplies</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <span class="text-[10px] font-bold text-[#7E22CE]" x-text="data.monthly_costs.waterfall.other"></span>
                            <div class="w-8 bg-[#7E22CE] rounded-xs my-1.5 transition-all" :style="`height: ${Math.max(4, Math.min(112, Math.round((data.monthly_costs.waterfall.other_raw / (data.monthly_costs.waterfall.max_val || 1)) * 112)))}px;`"></div>
                            <span class="text-[10px] font-semibold text-[#1E2024]">Other</span>
                        </div>
                        <div class="flex flex-col items-center">
                            <span class="text-[10px] font-bold text-[#181A1F]" x-text="data.monthly_costs.waterfall.left"></span>
                            <div class="w-8 bg-[#181A1F] rounded-xs my-1.5 transition-all" :style="`height: ${Math.max(4, Math.min(112, Math.round((Math.abs(data.monthly_costs.waterfall.left_raw) / (data.monthly_costs.waterfall.max_val || 1)) * 112)))}px;`"></div>
                            <span class="text-[10px] font-bold text-[#1E2024]">Left for shop</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-[#F2ECE3] text-xs text-[#7A726A] leading-relaxed" x-text="data.monthly_costs.rule_of_thumb"></div>
            </div>

            {{-- Other purchases (5 cols) --}}
            <div class="rounded-xl border border-[#E5DFD6] bg-white p-5 shadow-xs lg:col-span-5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-[#F2ECE3]">
                        <h2 class="text-sm font-bold text-[#1E2024]">Other purchases</h2>
                        <span class="text-xs font-semibold text-[#7A726A]" x-text="`${data.monthly_costs.other_purchases.total} · ${data.monthly_costs.other_purchases.vs_aug}`"></span>
                    </div>

                    {{-- Category Breakdown Bars --}}
                    <div class="space-y-2.5 mt-3 text-xs">
                        <template x-for="cat in data.monthly_costs.other_purchases.breakdown" :key="cat.label">
                            <div class="flex items-center justify-between">
                                <span class="w-36 text-[#5C554E] truncate" x-text="cat.label"></span>
                                <div class="flex-1 mx-2">
                                    <div class="h-2 w-full bg-[#EAE5DC] rounded-full overflow-hidden">
                                        <div class="h-full bg-[#4C1D95]" :style="`width: ${cat.pct}%`"></div>
                                    </div>
                                </div>
                                <span class="font-bold text-[#1E2024] w-14 text-right" x-text="cat.amount"></span>
                            </div>
                        </template>
                        <template x-if="!data.monthly_costs.other_purchases.breakdown || data.monthly_costs.other_purchases.breakdown.length === 0">
                            <div class="py-4 text-center text-[#8C827A] italic text-xs">No other purchases recorded this month</div>
                        </template>
                    </div>

                    {{-- Latest Receipts --}}
                    <div class="mt-4 pt-3 border-t border-[#F2ECE3]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#8C827A] block mb-2">Latest Receipts</span>
                        <div class="space-y-1.5 text-xs">
                            <template x-for="rec in data.monthly_costs.other_purchases.latest_receipts" :key="rec.title">
                                <div class="flex items-center justify-between text-[#7A726A]">
                                    <span x-text="`${rec.date} · ${rec.title}`"></span>
                                    <span class="font-bold text-[#1E2024]" x-text="rec.amount"></span>
                                </div>
                            </template>
                            <template x-if="!data.monthly_costs.other_purchases.latest_receipts || data.monthly_costs.other_purchases.latest_receipts.length === 0">
                                <div class="text-xs text-[#8C827A] italic py-1">No expense receipts recorded yet</div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function dashboardPage(fetchUrl, initialData, initialRange, initialTab = 'today', initialPeriod = 'today') {
    return {
        data: initialData,
        dateRange: initialRange,
        activeTab: initialTab || 'today',
        currentPeriod: initialPeriod || 'today',
        countedCash: '',

        init() {
            window.setInterval(() => this.refresh(), 30000);
        },

        get currentMonthName() {
            return this.data.current_month_name || 'September';
        },

        get currentMonthYear() {
            return this.data.current_month_year || 'September 2026';
        },

        get headerTitle() {
            if (this.activeTab === 'supplies') return 'Supplies & inventory';
            if (this.activeTab === 'costs') return 'Monthly costs';
            return 'Today at the shop';
        },

        get headerSubtitle() {
            const branch = this.data.today?.header?.branch_name || 'Main Branch';
            const updated = this.data.today?.header?.time_formatted || '8:47 PM';
            const date = this.data.today?.header?.date_formatted || 'Sunday, September 27';
            const monthYear = this.currentMonthYear;

            if (this.activeTab === 'supplies') {
                return `${monthYear} · ${branch} · what came in, what went out, what to reorder`;
            }
            if (this.activeTab === 'costs') {
                return `${monthYear} · payroll, bills, extra purchases, and what's left for the shop`;
            }
            return `${date} · ${branch} · updated ${updated}`;
        },

        get cashDifferenceText() {
            if (!this.countedCash || isNaN(parseFloat(this.countedCash))) {
                return 'Difference: shows here once counted';
            }
            const counted = parseFloat(this.countedCash);
            const expected = parseFloat(this.data.today?.cash_check?.cash_drawer_raw ?? 0);
            const diff = counted - expected;
            if (Math.abs(diff) < 0.01) {
                return 'Difference: balanced (₱0.00)';
            }
            if (diff > 0) {
                return `Difference: +₱${diff.toFixed(2)} over`;
            }
            return `Difference: -₱${Math.abs(diff).toFixed(2)} short`;
        },

        switchTab(tab) {
            this.activeTab = tab;
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        },

        changePeriod(period) {
            this.currentPeriod = period;
            const url = new URL(window.location);
            url.searchParams.set('period', period);
            url.searchParams.delete('date_range');
            window.location.href = url.toString();
        },

        changeBranch(branchId) {
            const url = new URL(window.location);
            if (branchId) {
                url.searchParams.set('branch_id', branchId);
            } else {
                url.searchParams.delete('branch_id');
            }
            window.location.href = url.toString();
        },

        refresh() {
            fetch(fetchUrl, { headers: { 'Accept': 'application/json' } })
                .then(response => response.json())
                .then(payload => {
                    this.data = payload;
                })
                .catch(() => {});
        }
    };
}
</script>
@endsection
