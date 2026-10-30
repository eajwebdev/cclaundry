@extends('layouts.app')

@section('page_title', 'Customers')

@section('content')
@php $canChooseBranch = auth()->user()->isSuperAdmin() || auth()->user()->role === 'admin'; @endphp
<div x-data="customerRecords" class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="customers" class="h-3.5 w-3.5"></span>
                Customer records
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Customers</h1>
            <p class="text-sm text-muted">Manage customer records and unpaid limits.</p>
        </div>

        <button type="button" @click="createOpen = true" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white shadow-sm transition hover:opacity-90">
            <span data-lucide="plus" class="h-4 w-4"></span>
            Add Customer
        </button>
    </div>

    {{-- Customer insights: who the best and most loyal customers are. --}}
    @php
        $currency = $appSettings?->currency ?? 'PHP';
        $money = fn ($amount) => $currency.' '.number_format((float) $amount, 2);
        $lists = [
            ['Most visits', 'repeat', 'Regulars in '.strtolower($insights['periodLabel']), $insights['mostVisits'],
                fn ($row) => $row->visits.' '.\Illuminate\Support\Str::plural('visit', $row->visits), fn ($row) => $money($row->spent).' spent'],
            ['Top spenders', 'crown', 'Best customers by sales', $insights['topSpenders'],
                fn ($row) => $money($row->spent), fn ($row) => $row->visits.' '.\Illuminate\Support\Str::plural('visit', $row->visits)],
            ['Unpaid balances', 'hand-coins', 'Collect on their next visit', $insights['unpaidBalances'],
                fn ($row) => $money($row->owed), fn ($row) => 'Last visit '.$row->last_visit_at?->format('M d')],
            ['Haven\'t come back', 'user-x', \App\Support\CustomerInsights::REGULAR_VISITS.'+ visits, away '.\App\Support\CustomerInsights::LAPSED_DAYS.'+ days', $insights['lapsedRegulars'],
                fn ($row) => $row->last_visit_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE).' away', fn ($row) => $row->visits.' visits before'],
        ];
        $emptyText = [
            'Most visits' => 'No visits in this period yet.',
            'Top spenders' => 'No sales in this period yet.',
            'Unpaid balances' => 'Nobody owes anything. Nice!',
            'Haven\'t come back' => 'All regulars are still coming in.',
        ];
    @endphp
    <section aria-labelledby="customer-insights-title" class="space-y-3 rounded-lg border border-border bg-white p-3 shadow-sm sm:p-4 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="customer-insights-title" class="text-base font-semibold">Customer insights</h2>
                <p class="text-xs text-muted">Visits exclude cancelled orders. {{ $canChooseBranch && ! request('branch_id') ? 'All branches.' : 'This branch only.' }}</p>
            </div>
            <nav aria-label="Insights period" class="flex gap-1 overflow-x-auto rounded-md bg-smoke p-1 dark:bg-gray-950">
                @foreach(\App\Support\CustomerInsights::PERIODS as $periodKey => $periodLabel)
                    <a href="{{ route('admin.customers.index', array_merge(request()->except('page', 'period'), ['period' => $periodKey])) }}"
                       @if($period === $periodKey) aria-current="true" @endif
                       class="whitespace-nowrap rounded px-2.5 py-1 text-xs font-medium {{ $period === $periodKey ? 'bg-white text-dark shadow-sm dark:bg-gray-800 dark:text-white' : 'text-muted hover:text-dark dark:hover:text-white' }}">{{ $periodLabel }}</a>
                @endforeach
            </nav>
        </div>

        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
            @foreach([
                ['Active customers', number_format($insights['activeCustomers']), number_format($insights['newCustomers']).' new '.($period === 'all' ? 'in total' : strtolower($insights['periodLabel']))],
                ['Repeat rate', $insights['repeatRate'] === null ? '—' : $insights['repeatRate'].'%', number_format($insights['returnedCustomers']).' of '.number_format($insights['visitedCustomers']).' came back (all time)'],
                ['Average spend', $insights['averageSpend'] === null ? '—' : $money($insights['averageSpend']), 'per visit · '.number_format($insights['visitCount']).' '.\Illuminate\Support\Str::plural('visit', $insights['visitCount'])],
                ['Unpaid balance', $money($insights['unpaidTotal']), number_format($insights['unpaidCustomers']).' '.\Illuminate\Support\Str::plural('customer', $insights['unpaidCustomers']).' owe'],
            ] as [$label, $value, $hint])
                <div class="rounded-md border border-border p-3 dark:border-gray-800">
                    <p class="text-xs text-muted">{{ $label }}</p>
                    <p class="mt-1 truncate text-lg font-semibold tabular-nums">{{ $value }}</p>
                    <p class="truncate text-[11px] text-muted" title="{{ $hint }}">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
            @foreach($lists as [$title, $icon, $subtitle, $rows, $stat, $detail])
                <div class="rounded-md border border-border dark:border-gray-800">
                    <div class="flex items-center gap-2 border-b border-border px-3 py-2 dark:border-gray-800">
                        <span data-lucide="{{ $icon }}" class="h-4 w-4 text-primary"></span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">{{ $title }}</h3>
                            <p class="truncate text-[11px] text-muted">{{ $subtitle }}</p>
                        </div>
                    </div>
                    <ol class="divide-y divide-border dark:divide-gray-800">
                        @forelse($rows as $rank => $row)
                            <li>
                                <button type="button" data-history-url="{{ route('admin.customers.history', $row->customer) }}" data-customer-name="{{ $row->customer->name }}"
                                        @click="openHistory($el.dataset.historyUrl, $el.dataset.customerName)"
                                        class="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-smoke dark:hover:bg-gray-950" title="View {{ $row->customer->name }}'s history">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $rank === 0 ? 'bg-primary text-white' : 'bg-smoke text-muted dark:bg-gray-800' }}">{{ $rank + 1 }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium">{{ $row->customer->name }}</span>
                                        <span class="block truncate text-[11px] text-muted">{{ $detail($row) }}</span>
                                    </span>
                                    <span class="shrink-0 text-right text-xs font-semibold tabular-nums">{{ $stat($row) }}</span>
                                </button>
                            </li>
                        @empty
                            <li class="px-3 py-4 text-center text-xs text-muted">{{ $emptyText[$title] }}</li>
                        @endforelse
                    </ol>
                </div>
            @endforeach
        </div>
    </section>

    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <form method="GET" action="{{ route('admin.customers.index') }}" class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_11rem_11rem_9rem_auto]">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input type="hidden" name="period" value="{{ $period }}">
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" placeholder="Search customers..." class="w-full bg-transparent text-sm outline-none">
            </div>

            @if($canChooseBranch)
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
            @endif

            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>

            <button type="submit" title="Filter" aria-label="Filter customers" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4"></span>
            </button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                    <tr>
                        @foreach(['name' => 'Customer', 'branch' => 'Branch', 'unpaid_limit' => 'Unpaid Limit', 'visits' => 'Laundry Visits', 'spent' => 'Total Spent', 'created_at' => 'Created', 'status' => 'Status'] as $key => $label)
                            @php $firstDirection = in_array($key, ['unpaid_limit', 'visits', 'spent', 'created_at'], true) ? 'desc' : 'asc'; @endphp
                            @php $nextDirection = $sort === $key ? ($direction === 'asc' ? 'desc' : 'asc') : $firstDirection; @endphp
                            <th scope="col" class="px-4 py-3" aria-sort="{{ $sort === $key ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <a href="{{ route('admin.customers.index', array_merge(request()->except('page', 'sort', 'direction'), ['sort' => $key, 'direction' => $nextDirection])) }}"
                                   class="inline-flex items-center gap-1.5 whitespace-nowrap hover:text-primary {{ $sort === $key ? 'text-primary' : '' }}"
                                   aria-label="Sort {{ $label }} {{ $nextDirection === 'asc' ? 'ascending' : 'descending' }}"
                                   title="{{ match ($key) {
                                       'created_at' => $nextDirection === 'asc' ? 'Oldest first' : 'Newest first',
                                       'visits' => $nextDirection === 'asc' ? 'Fewest visits first' : 'Most visits first',
                                       'unpaid_limit' => $nextDirection === 'asc' ? 'Lowest limit first' : 'Highest limit first',
                                       'spent' => $nextDirection === 'asc' ? 'Lowest spend first' : 'Highest spend first',
                                       default => $nextDirection === 'asc' ? 'Sort A to Z' : 'Sort Z to A',
                                   } }}">
                                    {{ $label }}
                                    <span data-lucide="{{ $sort === $key ? ($direction === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrow-up-down' }}" class="h-3.5 w-3.5 {{ $sort === $key ? '' : 'opacity-40' }}" aria-hidden="true"></span>
                                </a>
                            </th>
                        @endforeach
                        <th scope="col" class="px-4 py-3">History</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    @forelse($customers as $customer)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $customer->name }}</p>
                                <p class="text-xs text-muted">{{ $customer->phone ?: 'No phone' }} - {{ $customer->email ?: 'No email' }}</p>
                            </td>
                            <td class="px-4 py-3">{{ $customer->branch?->name ?? 'N/A' }}</td>
                            <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $customer->unpaid_limit, 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="font-semibold">{{ number_format($customer->job_orders_count) }}</span>
                                @if($customer->job_orders_count >= 10)
                                    <span class="ml-1 inline-flex rounded-md border border-violet-200 bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-violet-700 dark:border-violet-900/60 dark:bg-violet-500/10 dark:text-violet-300">Loyal</span>
                                @endif
                                @if($customer->last_visit_at)
                                    <p class="whitespace-nowrap text-xs text-muted">Last {{ \Illuminate\Support\Carbon::parse($customer->last_visit_at)->format('M d, Y') }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                {{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $customer->total_spent, 2) }}
                                @if(in_array($customer->id, $topSpenderIds, true))
                                    <span class="ml-1 inline-flex rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-300" title="Top 5 by sales, {{ strtolower($insights['periodLabel']) }}">Top spender</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $customer->created_at?->format('M d, Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="{{ \App\Support\StatusBadge::classes($customer->is_active ? 'active' : 'inactive') }}">
                                    {{ $customer->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" data-history-url="{{ route('admin.customers.history', $customer) }}" data-customer-name="{{ $customer->name }}"
                                        @click="openHistory($el.dataset.historyUrl, $el.dataset.customerName)"
                                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-border px-2 py-1.5 text-xs hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="history" class="h-3.5 w-3.5"></span>View history
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="editOpen = {{ $customer->id }}" title="Edit" aria-label="Edit customer" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="settings" class="h-4 w-4"></span>
                                </button>

                                <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" class="inline" x-data>
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        title="Delete"
                                        aria-label="Delete customer"
                                        x-on:click.prevent="Swal.fire({ title: 'Delete customer?', text: 'Customers with job orders cannot be deleted.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50"
                                    >
                                        <span data-lucide="trash" class="h-4 w-4"></span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-muted">No customers found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-border px-4 py-3 dark:border-gray-800">
            {{ $customers->links() }}
        </div>
    </div>

    <dialog x-cloak x-effect="if (historyOpen && !$el.open) $el.showModal(); else if (!historyOpen && $el.open) $el.close()"
            aria-labelledby="customer-history-title"
            @cancel="historyOpen = false" @close="historyOpen = false" @click="if ($event.target === $el) historyOpen = false"
            class="m-auto h-[92dvh] max-h-[92dvh] w-[calc(100%_-_1.5rem)] max-w-[96rem] overflow-hidden rounded-lg bg-white p-0 text-dark shadow-2xl backdrop:bg-black/50 dark:bg-gray-900 dark:text-gray-100">
        <section class="flex h-full max-h-[92dvh] w-full flex-col overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-gray-900">
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-border p-4 dark:border-gray-800">
                <div>
                    <h2 id="customer-history-title" class="text-lg font-semibold">Customer history</h2>
                    <p class="text-sm text-muted" x-text="historyName"></p>
                </div>
                <button type="button" @click="historyOpen = false" aria-label="Close customer history" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800">
                    <span data-lucide="x" class="h-4 w-4"></span>
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5" :aria-busy="historyLoading">
                <p x-show="historyLoading" role="status" class="py-8 text-center text-sm text-muted">Loading history…</p>
                <div x-show="historyError" role="alert" class="py-4 text-center text-sm">
                    <p x-text="historyError"></p>
                    <button type="button" @click="loadHistory(historyUrl)" class="mt-2 text-primary hover:underline">Try again</button>
                </div>
                <div x-show="!historyLoading && !historyError" x-html="historyHtml"
                     @click="const link = $event.target.closest('a[data-history-page]'); if (link) { $event.preventDefault(); loadHistory(link.href); }"></div>
            </div>
        </section>
    </dialog>

    <div x-cloak x-show="createOpen" x-transition.opacity @keydown.escape.window="createOpen = false" role="dialog" aria-modal="true" aria-labelledby="create-customer-title" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
        <div @click.outside="createOpen = false" class="max-h-[90dvh] w-full max-w-xl overflow-y-auto rounded-2xl border border-border bg-white p-5 shadow-2xl sm:p-6 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><span data-lucide="user-plus" class="h-5 w-5"></span></span>
                    <div>
                        <h2 id="create-customer-title" class="text-lg font-semibold tracking-tight">Add Customer</h2>
                        <p class="mt-0.5 text-xs text-muted">Keep their contact details ready for every visit.</p>
                    </div>
                </div>
                <button type="button" @click="createOpen = false" aria-label="Close customer form" class="rounded-lg p-2 text-muted transition hover:bg-smoke hover:text-dark dark:hover:bg-gray-800 dark:hover:text-white"><span data-lucide="x" class="h-4 w-4"></span></button>
            </div>

            @include('admin.customers.partials.form', [
                'action' => route('admin.customers.store'),
                'method' => 'POST',
                'customer' => new \App\Models\Customer(['billing_type' => 'regular', 'is_active' => true, 'unpaid_limit' => 0]),
            ])
        </div>
    </div>

    @foreach($customers as $customer)
        <div x-cloak x-show="editOpen === {{ $customer->id }}" x-transition.opacity @keydown.escape.window="editOpen = null" role="dialog" aria-modal="true" aria-labelledby="edit-customer-title-{{ $customer->id }}" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
            <div @click.outside="editOpen = null" class="max-h-[90dvh] w-full max-w-xl overflow-y-auto rounded-2xl border border-border bg-white p-5 shadow-2xl sm:p-6 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><span data-lucide="pencil" class="h-5 w-5"></span></span>
                        <div>
                            <h2 id="edit-customer-title-{{ $customer->id }}" class="text-lg font-semibold tracking-tight">Edit Customer</h2>
                            <p class="mt-0.5 text-xs text-muted">Update contact details and store settings.</p>
                        </div>
                    </div>
                    <button type="button" @click="editOpen = null" aria-label="Close customer form" class="rounded-lg p-2 text-muted transition hover:bg-smoke hover:text-dark dark:hover:bg-gray-800 dark:hover:text-white"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                @include('admin.customers.partials.form', [
                    'action' => route('admin.customers.update', $customer),
                    'method' => 'PUT',
                    'customer' => $customer,
                ])
            </div>
        </div>
    @endforeach
</div>
@endsection

@push('scripts')
<script>
    window.customerRecords = () => ({
        createOpen: @js($errors->any() && old('_customer_form') === 'create'),
        editOpen: @js($errors->any() && ctype_digit((string) old('_customer_form', '')) ? (int) old('_customer_form') : null),
        historyOpen: false,
        historyName: '',
        historyUrl: '',
        historyHtml: '',
        historyLoading: false,
        historyError: '',
        historyRequest: 0,
        openHistory(url, name) {
            this.historyName = name;
            this.historyHtml = '';
            this.historyOpen = true;
            this.loadHistory(url);
        },
        async loadHistory(url) {
            const requestId = ++this.historyRequest;
            this.historyUrl = url;
            this.historyLoading = true;
            this.historyError = '';
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok || response.redirected) throw new Error('History unavailable');
                const html = await response.text();
                if (requestId === this.historyRequest) this.historyHtml = html;
            } catch {
                if (requestId === this.historyRequest) this.historyError = 'Could not load customer history. Please try again.';
            } finally {
                if (requestId === this.historyRequest) this.historyLoading = false;
            }
        },
    });
</script>
@endpush

