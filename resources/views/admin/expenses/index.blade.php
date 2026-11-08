@extends('layouts.app')

@section('page_title', 'Expenses')

@section('content')
@php
    $currency = $appSettings?->currency ?? 'PHP';
    $dateRangeValue = request('date_range') ?: ($dateFrom && $dateTo ? $dateFrom.' to '.$dateTo : '');
    $canEditExpenses = auth()->user()->isAdmin();
    $paidWithOptions = collect(\App\Models\BranchExpense::SOURCES)->when(! $canEditExpenses, fn ($sources) => $sources->except('bank'));
@endphp

<div
    x-data="{
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
        }
    }"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="expense" class="h-3.5 w-3.5"></span>
                Cash management
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Expenses</h1>
            <p class="text-sm text-muted">Every expense, payroll included, is entered once here. Shop Cash and the Z-Reading read it.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.expenses.index', array_filter(['expense_type' => 'payroll', 'branch_id' => request('branch_id'), 'date_range' => $dateRangeValue])) }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border bg-white px-3 text-sm font-medium hover:bg-smoke dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800">
                <span data-lucide="users" class="h-4 w-4"></span>
                Salary Only
            </a>
            <button type="button" @click="$dispatch('expense-form')" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                <span data-lucide="plus" class="h-4 w-4"></span>
                Record Expense
            </button>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xs text-muted">Total Expenses</p>
            <p class="mt-1 text-lg font-semibold">{{ $currency }} {{ number_format((float) ($summary->total_expenses ?? 0), 2) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xs text-muted">Drawer Cash (confirmed)</p>
            <p class="mt-1 text-lg font-semibold">{{ $currency }} {{ number_format((float) ($summary->store_cash_expenses ?? 0), 2) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xs text-muted">Owner-Paid (For Reimbursement)</p>
            <p class="mt-1 text-lg font-semibold">{{ $currency }} {{ number_format((float) ($summary->owner_expenses ?? 0), 2) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xs text-muted">Salary Expenses</p>
            <p class="mt-1 text-lg font-semibold">{{ $currency }} {{ number_format((float) ($summary->salary_expenses ?? 0), 2) }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.expenses.index') }}" class="grid gap-2 rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:grid-cols-[1fr_1fr_1fr_1fr_1fr_1fr_auto]">
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

        <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
            <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
            <input x-ref="dateRange" x-model="dateRange" name="date_range" placeholder="Date range" class="w-full bg-transparent text-sm outline-none" autocomplete="off">
        </div>

        <select name="paid_with" aria-label="Paid with" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <option value="">Paid with: all</option>
            @foreach($paidWithOptions as $source => $label)
                <option value="{{ $source }}" @selected(request('paid_with') === $source)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="confirmation" aria-label="Confirmation" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <option value="">Confirmation: all</option>
            @foreach(['confirmed' => 'Confirmed', 'pending' => 'Pending', 'disputed' => 'Disputed'] as $status => $label)
                <option value="{{ $status }}" @selected(request('confirmation') === $status)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="expense_type" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <option value="">All categories</option>
            @foreach($categories as $category)
                <option value="{{ $category }}" @selected(request('expense_type') === $category)>{{ \App\Support\StatusBadge::label($category) }}</option>
            @endforeach
        </select>

        <select name="employee_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <option value="">All employees</option>
            @foreach($employees as $employee)
                <option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->name }}{{ $canChooseBranch ? ' - '.$employee->branch?->name : '' }}</option>
            @endforeach
        </select>

        <button type="submit" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
            <span data-lucide="search" class="h-4 w-4"></span>
            Filter
        </button>
    </form>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                    <tr>
                        <th class="px-4 py-3">Expense</th>
                        <th class="px-4 py-3">Branch</th>
                        <th class="px-4 py-3">Paid with</th>
                        <th class="px-4 py-3">Employee / Period</th>
                        <th class="px-4 py-3">Reference</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    @forelse($expenses as $expense)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $expense->title }}</p>
                                <p class="text-xs text-muted">{{ $expense->expense_date?->format('M d, Y') }} - {{ \App\Support\StatusBadge::label($expense->category) }}</p>
                                @if($expense->receipt_path)
                                    <a href="{{ route('admin.expenses.receipt', $expense) }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                        <span data-lucide="receipt" class="h-3.5 w-3.5"></span>
                                        View receipt
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $expense->branch?->name }}</td>
                            <td class="px-4 py-3">
                                @foreach($expense->sourceAmounts() as $source => $part)
                                    <p>{{ \App\Models\BranchExpense::SOURCES[$source] }}{{ count($expense->sourceAmounts()) > 1 ? ' · '.\App\Support\ShopCash::money($part) : '' }}</p>
                                @endforeach
                                @if($expense->drawer_status !== 'none')
                                    <p class="text-xs font-medium {{ ['pending' => 'text-amber-700', 'disputed' => 'text-red-600'][$expense->drawer_status] ?? 'text-emerald-700' }}">Drawer: {{ $expense->drawer_status }}</p>
                                @endif
                                @if($expense->accountsPayable)
                                    <p class="text-xs text-muted">{{ $expense->accountsPayable->payable_number }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($expense->employee)
                                    <p class="font-medium">{{ $expense->employee->name }}</p>
                                    <p class="text-xs text-muted">{{ $expense->salary_period_start?->format('M j') }}-{{ $expense->salary_period_end?->format('M j, Y') }}</p>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $expense->reference_no ?: 'N/A' }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $currency }} {{ number_format((float) $expense->amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    @if($canEditExpenses)
                                        <button
                                            type="button"
                                            title="Edit"
                                            aria-label="Edit expense"
                                            @click="$dispatch('expense-form', @js([
                                                'id' => $expense->id,
                                                'branch_id' => (string) $expense->branch_id,
                                                'expense_date' => $expense->expense_date?->toDateString(),
                                                'category' => $expense->category,
                                                'amount' => number_format((float) $expense->amount, 2, '.', ''),
                                                'attendance_employee_id' => (string) ($expense->attendance_employee_id ?? ''),
                                                'salary_period_start' => $expense->salary_period_start?->toDateString(),
                                                'salary_period_end' => $expense->salary_period_end?->toDateString(),
                                                'title' => $expense->title,
                                                'paid_with' => array_key_first($expense->sourceAmounts()) ?? 'drawer',
                                                'split' => ['drawer' => (string) (float) $expense->drawer_amount, 'gcash' => (string) (float) $expense->gcash_amount, 'bank' => (string) (float) $expense->bank_amount],
                                                'drawer_amount' => (float) $expense->drawer_amount,
                                                'reference_no' => $expense->reference_no,
                                                'remarks' => $expense->remarks,
                                                'receipt_url' => $expense->receipt_path ? route('admin.expenses.receipt', $expense) : null,
                                                'employee' => $expense->employee ? [
                                                    'id' => (string) $expense->employee->id,
                                                    'branch_id' => (string) $expense->employee->branch_id,
                                                    'name' => $expense->employee->name,
                                                    'branch' => $expense->branch?->name,
                                                    'monthly_salary' => 0,
                                                ] : null,
                                            ]))"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950"
                                        >
                                            <span data-lucide="pencil" class="h-4 w-4"></span>
                                        </button>
                                        <form method="POST" action="{{ route('admin.expenses.destroy', $expense) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="Delete" aria-label="Delete expense" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                                                <span data-lucide="trash" class="h-4 w-4"></span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-muted">No expenses found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $expenses->links() }}</div>
    </div>

    @include('admin.expenses._form')
</div>
@endsection
