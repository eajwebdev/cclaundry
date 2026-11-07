<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountsPayable;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Support\Activity;
use App\Support\ExpenseReceipt;
use App\Support\ShopCash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public const CATEGORIES = [
        'supplies',
        'inventory_purchase',
        'utilities',
        'rent',
        'payroll',
        'repairs_maintenance',
        'transport_delivery',
        'marketing',
        'government_fees',
        'professional_fees',
        'software_subscription',
        'other',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        [$dateFrom, $dateTo] = $this->dateRange($request);

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $employees = AttendanceEmployee::query()
            ->with(['branch:id,name', 'user:id,monthly_salary'])
            ->where('status', 'active')
            ->when(! $canChooseBranch, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($canChooseBranch && $request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->branch_id))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $baseQuery = BranchExpense::query()
            ->with(['branch', 'creator', 'accountsPayable', 'employee'])
            ->when(! $canChooseBranch, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($canChooseBranch && $request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->branch_id))
            ->when($dateFrom, fn ($query) => $query->whereDate('expense_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))
            // Bank-paid expenses are owner/admin only.
            ->when(! $user->isAdmin(), fn ($query) => $query->where('bank_amount', 0))
            ->when(array_key_exists((string) $request->paid_with, BranchExpense::SOURCES) && ($user->isAdmin() || $request->paid_with !== 'bank'),
                fn ($query) => $query->where($request->paid_with.'_amount', '>', 0))
            ->when(in_array($request->confirmation, ['confirmed', 'pending', 'disputed'], true), fn ($query) => $query->where('drawer_status', $request->confirmation))
            ->when(in_array($request->expense_type, self::CATEGORIES, true), fn ($query) => $query->where('expense_type', $request->expense_type))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('attendance_employee_id', $request->integer('employee_id')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(fn ($query) => $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")));
            });

        $summary = (clone $baseQuery)
            ->selectRaw("COALESCE(SUM(amount), 0) as total_expenses, COALESCE(SUM(CASE WHEN drawer_status = 'confirmed' THEN drawer_amount ELSE 0 END), 0) as store_cash_expenses, COALESCE(SUM(owner_amount), 0) as owner_expenses, COALESCE(SUM(CASE WHEN expense_type = 'payroll' THEN amount ELSE 0 END), 0) as salary_expenses")
            ->first();

        $expenses = $baseQuery
            ->latest('expense_date')
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.expenses.index', [
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'expenses' => $expenses,
            'summary' => $summary,
            'categories' => self::CATEGORIES,
            'employees' => $employees,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $this->validatedExpense($request);
        $validated += $this->drawerStatus($request, $validated);
        $receiptPath = $request->hasFile('receipt') ? ExpenseReceipt::store($request->file('receipt')) : null;

        try {
            DB::transaction(function () use ($request, $validated, $user, $receiptPath): void {
                $this->recordExpense($request, $validated, $user, $receiptPath);
            });
        } catch (\Throwable $exception) {
            // Nothing was saved, so do not keep an orphaned photo.
            ExpenseReceipt::delete($receiptPath);

            throw $exception;
        }

        return back()->with('success', 'Expense recorded successfully.');
    }

    private function recordExpense(Request $request, array $validated, $user, ?string $receiptPath): void
    {
        $this->guardDuplicateSalary($validated);

        $expense = BranchExpense::create($validated + ['created_by' => $user->id, 'receipt_path' => $receiptPath]);

        if ($expense->paid_from === 'owner') {
            $this->createOwnerPayable($expense, $user->id);
        }

        Activity::log($request, 'expense_recorded', $expense, [
            'title' => $expense->title,
            'amount' => $expense->amount,
            'funding_source' => $expense->paid_from,
            'paid_with' => $expense->sourceAmounts(),
            'drawer_status' => $expense->drawer_status,
            'employee_id' => $expense->attendance_employee_id,
            'salary_period_start' => $expense->salary_period_start?->toDateString(),
            'salary_period_end' => $expense->salary_period_end?->toDateString(),
            'has_receipt' => $receiptPath !== null,
        ], $expense->branch_id);
    }

    public function update(Request $request, BranchExpense $expense)
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $this->validatedExpense($request, $expense);
        $validated += $this->drawerStatus($request, $validated, $expense);
        $previousReceipt = $expense->receipt_path;
        $newReceipt = $request->hasFile('receipt') ? ExpenseReceipt::store($request->file('receipt')) : null;

        if ($newReceipt) {
            $validated['receipt_path'] = $newReceipt;
        } elseif ($request->boolean('remove_receipt')) {
            $validated['receipt_path'] = null;
        }

        try {
            $this->updateExpense($request, $validated, $expense, $user);
        } catch (\Throwable $exception) {
            ExpenseReceipt::delete($newReceipt);

            throw $exception;
        }

        // The old photo is removed only once the new state is saved.
        if (array_key_exists('receipt_path', $validated) && $previousReceipt !== $validated['receipt_path']) {
            ExpenseReceipt::delete($previousReceipt);
        }

        return back()->with('success', 'Expense updated successfully.');
    }

    private function updateExpense(Request $request, array $validated, BranchExpense $expense, $user): void
    {
        DB::transaction(function () use ($request, $validated, $expense, $user): void {
            $this->guardDuplicateSalary($validated, $expense->id);

            $expense->loadMissing('accountsPayable.payments');
            $payable = $expense->accountsPayable;
            $paidBack = round((float) ($payable?->paid_amount ?? 0), 2);
            $tracked = ['title', 'amount', 'category', 'paid_from', 'drawer_amount', 'gcash_amount', 'bank_amount', 'owner_amount', 'drawer_status', 'branch_id', 'expense_date', 'attendance_employee_id', 'salary_period_start', 'salary_period_end'];
            $before = $expense->only($tracked);

            if ($payable && $payable->payments->isNotEmpty()) {
                if ($validated['paid_from'] !== 'owner') {
                    throw ValidationException::withMessages([
                        'paid_from' => 'This expense already has owner repayments, so it must stay owner-funded.',
                    ]);
                }

                if ((float) $validated['amount'] < $paidBack) {
                    throw ValidationException::withMessages([
                        'amount' => 'Amount cannot be lower than the '.number_format($paidBack, 2).' already repaid to the owner.',
                    ]);
                }
            }

            $expense->update($validated);

            if ($expense->paid_from === 'owner' && $payable) {
                $balance = max(round((float) $expense->amount - $paidBack, 2), 0);
                $payable->update([
                    'branch_id' => $expense->branch_id,
                    'funding_method' => $this->payableFundingMethod($expense->payment_method),
                    'reference_no' => $expense->reference_no,
                    'description' => 'Reimbursement for '.$expense->title,
                    'original_amount' => $expense->amount,
                    'balance' => $balance,
                    'status' => $balance <= 0 ? 'paid' : ($paidBack > 0 ? 'partial' : 'unpaid'),
                    'funded_at' => $expense->expense_date->toDateString(),
                ]);
            } elseif ($expense->paid_from === 'owner') {
                $this->createOwnerPayable($expense, $user->id);
            } elseif ($payable) {
                $expense->update(['accounts_payable_id' => null]);
                $payable->delete();
            }

            Activity::log($request, 'expense_updated', $expense, [
                'before' => $before,
                'after' => $expense->fresh()->only($tracked),
                'receipt' => array_key_exists('receipt_path', $validated)
                    ? ($validated['receipt_path'] ? 'replaced' : 'removed')
                    : 'unchanged',
            ], $expense->branch_id);
        });
    }

    private function validatedExpense(Request $request, ?BranchExpense $existing = null): array
    {
        $user = $request->user();
        $normalizedCategory = str((string) $request->input('category'))->snake()->toString();
        $normalizedCategory = match ($normalizedCategory) {
            'stocks', 'stock', 'inventory' => 'inventory_purchase',
            'repairs', 'maintenance' => 'repairs_maintenance',
            'transport', 'delivery' => 'transport_delivery',
            'fees' => 'government_fees',
            default => $normalizedCategory,
        };
        $request->merge([
            'category' => $normalizedCategory,
            'expense_type' => $normalizedCategory,
        ]);

        $expenseBranchId = $user->canManageAllBranches()
            ? $request->integer('branch_id')
            : (int) $user->branch_id;
        $salaryExpense = $normalizedCategory === 'payroll';
        $currentEmployeeId = $existing?->attendance_employee_id;
        $sources = $user->isAdmin() ? array_keys(BranchExpense::SOURCES) : ['drawer', 'gcash', 'owner'];

        $validated = $request->validate([
            'branch_id' => [$user->canManageAllBranches() ? 'required' : 'nullable', 'exists:branches,id'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'amount' => [Rule::requiredIf(! $salaryExpense), 'nullable', 'numeric', 'min:0.01'],
            'paid_with' => [Rule::requiredIf(! $salaryExpense), 'nullable', Rule::in($sources)],
            // Payroll: one amount per source. Staff never see the bank part.
            'split' => ['nullable', 'array'],
            'split.drawer' => ['nullable', 'numeric', 'min:0'],
            'split.gcash' => ['nullable', 'numeric', 'min:0'],
            'split.bank' => [$user->isAdmin() ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'confirm_drawer' => ['nullable', 'boolean'],
            'expense_date' => ['required', 'date'],
            'attendance_employee_id' => [
                Rule::requiredIf($salaryExpense),
                'nullable',
                // On edit, keep accepting the originally paid employee even if they have since been deactivated.
                Rule::exists('attendance_employees', 'id')->where(fn ($query) => $query
                    ->where('branch_id', $expenseBranchId)
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))
                        ->when($currentEmployeeId, fn ($query) => $query->orWhere('id', $currentEmployeeId)))),
            ],
            'salary_period_start' => [Rule::requiredIf($salaryExpense), 'nullable', 'date'],
            'salary_period_end' => [Rule::requiredIf($salaryExpense), 'nullable', 'date', 'after_or_equal:salary_period_start'],
            'expense_type' => ['nullable', Rule::in(self::CATEGORIES)],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            // Phone photos can be large; they are compressed to KB on save.
            'receipt' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'remove_receipt' => ['nullable', 'boolean'],
        ], [
            'paid_with.required' => 'Choose what the expense was paid with.',
            'paid_with.in' => 'Choose what the expense was paid with.',
            'split.bank.prohibited' => 'Only the owner can record the bank part.',
            'receipt.image' => 'The receipt must be a photo (JPG, PNG or WEBP).',
            'receipt.mimes' => 'The receipt must be a photo (JPG, PNG or WEBP).',
            'receipt.max' => 'The receipt photo must be 20 MB or smaller.',
        ]);
        unset($validated['receipt'], $validated['remove_receipt'], $validated['paid_with'], $validated['split'], $validated['confirm_drawer']);

        $parts = array_fill_keys(array_keys(BranchExpense::SOURCES), 0.0);
        if ($salaryExpense) {
            foreach (['drawer', 'gcash', 'bank'] as $source) {
                $parts[$source] = round((float) $request->input("split.{$source}", 0), 2);
            }
        } else {
            $parts[$request->input('paid_with')] = round((float) $request->input('amount'), 2);
        }
        $paidWith = array_keys(array_filter($parts));
        if ($paidWith === []) {
            throw ValidationException::withMessages(['amount' => 'Enter how much was paid.']);
        }

        $keepsReceipt = $existing?->receipt_path && ! $request->boolean('remove_receipt');
        if (array_diff($paidWith, ['owner']) && ! $request->hasFile('receipt') && ! $keepsReceipt) {
            throw ValidationException::withMessages(['receipt' => $salaryExpense
                ? 'Add a photo of the signed payslips or payroll sheet.'
                : 'Add a photo of the receipt. No receipt? Take a photo of the item and explain in the remarks.']);
        }

        foreach ($parts as $source => $amount) {
            $validated[$source.'_amount'] = $amount;
        }
        $validated['amount'] = round(array_sum($parts), 2);
        // Older reports and accounts payable still read these two columns.
        $validated['paid_from'] = $parts['owner'] > 0 ? 'owner' : 'store_cash';
        $validated['payment_method'] = count($paidWith) > 1 ? 'split' : ['drawer' => 'cash', 'gcash' => 'gcash', 'bank' => 'bank', 'owner' => null][$paidWith[0]];

        if (! $user->canManageAllBranches()) {
            $validated['branch_id'] = $user->branch_id;
        }

        $validated['expense_type'] = $validated['category'];

        if (! $salaryExpense) {
            $validated['attendance_employee_id'] = null;
            $validated['salary_period_start'] = null;
            $validated['salary_period_end'] = null;
        }

        return $validated;
    }

    /**
     * The attendant on duty confirms drawer cash in the form; anyone else's drawer expense waits
     * in Shop Cash for the attendant. The drawer part can never be more than is in the drawer.
     */
    private function drawerStatus(Request $request, array $validated, ?BranchExpense $existing = null): array
    {
        $user = $request->user();
        $drawer = (float) $validated['drawer_amount'];

        if ($drawer <= 0) {
            return ['drawer_status' => 'none', 'confirmed_by' => null, 'confirmed_at' => null];
        }

        if ($existing && (float) $existing->drawer_amount === $drawer && $existing->drawer_status !== 'none') {
            return [];
        }

        $day = ShopCash::day((int) $validated['branch_id'], \Illuminate\Support\Carbon::parse($validated['expense_date'])->toDateString());
        // shortcut: on edit the old drawer part is still counted in the drawer, so the limit is only checked on new expenses.
        if (! $existing && $drawer > $day['should_be']) {
            throw ValidationException::withMessages(['split.drawer' => 'That is more than the cash in the drawer ('.ShopCash::money($day['should_be']).'). Check the amount.']);
        }

        if ($user->isAdmin()) {
            return ['drawer_status' => 'pending', 'confirmed_by' => null, 'confirmed_at' => null];
        }

        if (! $day['opening']) {
            throw ValidationException::withMessages(['split.drawer' => 'Open the shop in Shop Cash first: count the float.']);
        }

        if (! $request->boolean('confirm_drawer')) {
            throw ValidationException::withMessages(['confirm_drawer' => 'Tick the box to confirm '.ShopCash::money($drawer).' was taken out of this drawer.']);
        }

        return ['drawer_status' => 'confirmed', 'confirmed_by' => $user->id, 'confirmed_at' => now()];
    }

    private function guardDuplicateSalary(array $validated, ?int $ignoreId = null): void
    {
        if ($validated['expense_type'] !== 'payroll') {
            return;
        }

        $duplicate = BranchExpense::query()
            ->where('expense_type', 'payroll')
            ->where('attendance_employee_id', $validated['attendance_employee_id'])
            ->whereDate('salary_period_start', $validated['salary_period_start'])
            ->whereDate('salary_period_end', $validated['salary_period_end'])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'attendance_employee_id' => 'A salary payment for this employee and pay period is already recorded.',
            ]);
        }
    }

    private function createOwnerPayable(BranchExpense $expense, int $userId): void
    {
        $payable = AccountsPayable::query()->create([
            'branch_id' => $expense->branch_id,
            'created_by' => $userId,
            'payable_number' => AccountsPayable::nextNumber(),
            'creditor_name' => 'Owner',
            'source_type' => 'owner_paid_expense',
            'source_id' => $expense->id,
            'funding_method' => $this->payableFundingMethod($expense->payment_method),
            'reference_no' => $expense->reference_no,
            'description' => 'Reimbursement for '.$expense->title,
            'original_amount' => $expense->amount,
            'paid_amount' => 0,
            'balance' => $expense->amount,
            'status' => 'unpaid',
            'funded_at' => $expense->expense_date->toDateString(),
        ]);
        $expense->update(['accounts_payable_id' => $payable->id]);
    }

    private function payableFundingMethod(?string $paymentMethod): string
    {
        $method = str((string) $paymentMethod)->lower()->trim()->toString();

        return in_array($method, ['cash', 'gcash', 'bank', 'cheque'], true) ? $method : 'cash';
    }

    public function destroy(Request $request, BranchExpense $expense)
    {
        // No silent edits: staff never remove a saved expense.
        abort_unless($request->user()->isAdmin(), 403);

        if (! $request->user()->canManageAllBranches()) {
            abort_unless((int) $request->user()->branch_id === (int) $expense->branch_id, 403);
        }

        $expense->loadMissing('accountsPayable.payments');

        if ($expense->accountsPayable?->payments->isNotEmpty()) {
            return back()->with('error', 'This expense already has payable repayments and cannot be deleted.');
        }

        $receiptPath = $expense->receipt_path;

        DB::transaction(function () use ($expense): void {
            $payable = $expense->accountsPayable;
            $expense->delete();
            $payable?->delete();
        });

        ExpenseReceipt::delete($receiptPath);

        return back()->with('success', 'Expense removed successfully.');
    }

    public function receipt(Request $request, BranchExpense $expense)
    {
        if (! $request->user()->canManageAllBranches()) {
            abort_unless((int) $request->user()->branch_id === (int) $expense->branch_id, 403);
        }

        abort_unless(ExpenseReceipt::exists($expense->receipt_path), 404);

        return ExpenseReceipt::response($expense->receipt_path);
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);

            return [
                $this->parseDate($parts[0] ?? null),
                $this->parseDate($parts[1] ?? $parts[0] ?? null),
            ];
        }

        $from = $this->parseDate($request->date_from);
        $to = $this->parseDate($request->date_to);

        if ($from || $to) {
            return [$from, $to];
        }

        return [today()->toDateString(), today()->toDateString()];
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
