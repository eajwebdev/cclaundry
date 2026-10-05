<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountsPayable;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    private const CATEGORIES = [
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
            ->when($request->filled('paid_from'), fn ($query) => $query->where('paid_from', $request->paid_from))
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
            ->selectRaw("COALESCE(SUM(amount), 0) as total_expenses, COALESCE(SUM(CASE WHEN paid_from = 'store_cash' THEN amount ELSE 0 END), 0) as store_cash_expenses, COALESCE(SUM(CASE WHEN paid_from = 'owner' THEN amount ELSE 0 END), 0) as owner_expenses, COALESCE(SUM(CASE WHEN expense_type = 'payroll' THEN amount ELSE 0 END), 0) as salary_expenses")
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

        DB::transaction(function () use ($request, $validated, $user): void {
            $this->guardDuplicateSalary($validated);

            $expense = BranchExpense::create($validated + ['created_by' => $user->id]);

            if ($expense->paid_from === 'owner') {
                $this->createOwnerPayable($expense, $user->id);
            }

            Activity::log($request, 'expense_recorded', $expense, [
                'title' => $expense->title,
                'amount' => $expense->amount,
                'funding_source' => $expense->paid_from,
                'employee_id' => $expense->attendance_employee_id,
                'salary_period_start' => $expense->salary_period_start?->toDateString(),
                'salary_period_end' => $expense->salary_period_end?->toDateString(),
            ], $expense->branch_id);
        });

        return back()->with('success', 'Expense recorded successfully.');
    }

    public function update(Request $request, BranchExpense $expense)
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $this->validatedExpense($request, $expense);

        DB::transaction(function () use ($request, $validated, $expense, $user): void {
            $this->guardDuplicateSalary($validated, $expense->id);

            $expense->loadMissing('accountsPayable.payments');
            $payable = $expense->accountsPayable;
            $paidBack = round((float) ($payable?->paid_amount ?? 0), 2);
            $tracked = ['title', 'amount', 'category', 'paid_from', 'branch_id', 'expense_date', 'attendance_employee_id', 'salary_period_start', 'salary_period_end'];
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
            ], $expense->branch_id);
        });

        return back()->with('success', 'Expense updated successfully.');
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

        $validated = $request->validate([
            'branch_id' => [$user->canManageAllBranches() ? 'required' : 'nullable', 'exists:branches,id'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
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
            'payment_method' => ['nullable', 'string', 'max:100'],
            'paid_from' => ['nullable', Rule::in(['store_cash', 'owner'])],
            'expense_type' => ['nullable', Rule::in(self::CATEGORIES)],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        if (! $user->canManageAllBranches()) {
            $validated['branch_id'] = $user->branch_id;
        }

        $validated['expense_type'] = $validated['category'];
        $validated['paid_from'] = $validated['paid_from'] ?? 'store_cash';

        if (! $salaryExpense) {
            $validated['attendance_employee_id'] = null;
            $validated['salary_period_start'] = null;
            $validated['salary_period_end'] = null;
        }

        return $validated;
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
        if (! $request->user()->canManageAllBranches()) {
            abort_unless((int) $request->user()->branch_id === (int) $expense->branch_id, 403);
        }

        $expense->loadMissing('accountsPayable.payments');

        if ($expense->accountsPayable?->payments->isNotEmpty()) {
            return back()->with('error', 'This expense already has payable repayments and cannot be deleted.');
        }

        DB::transaction(function () use ($expense): void {
            $payable = $expense->accountsPayable;
            $expense->delete();
            $payable?->delete();
        });

        return back()->with('success', 'Expense removed successfully.');
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
