<?php

namespace Tests\Feature;

use App\Models\AccountsPayable;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExpenseEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_correct_a_salary_expense_after_creation(): void
    {
        [$branch, $admin] = $this->setUpAdmin('admin');
        $employee = $this->employee($branch);

        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->salaryPayload($branch, $employee, ['amount' => 5000]))
            ->assertSessionHasNoErrors();
        $expense = BranchExpense::query()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.expenses.index', ['date_range' => '2026-10-10 to 2026-10-10']))
            ->assertOk()
            ->assertSee('aria-label="Edit expense"', false);

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $expense), $this->salaryPayload($branch, $employee, [
                'amount' => 6500,
                'salary_period_start' => '2026-10-16',
                'salary_period_end' => '2026-10-31',
                'title' => 'Salary - Ana Cruz (corrected)',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame('6500.00', $expense->amount);
        $this->assertSame('2026-10-16', $expense->salary_period_start->toDateString());
        $this->assertSame('Salary - Ana Cruz (corrected)', $expense->title);
        $this->assertDatabaseHas('activity_logs', ['action' => 'expense_updated']);
    }

    public function test_non_admin_cannot_edit_expenses(): void
    {
        [$branch] = $this->setUpAdmin();
        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'access' => ['expenses'],
        ]);
        $expense = $this->storeExpense($branch, $manager);

        $this->actingAs($manager)
            ->get(route('admin.expenses.index', ['date_range' => '2026-10-10 to 2026-10-10']))
            ->assertOk()
            ->assertSee('Electric Bill')
            ->assertDontSee('aria-label="Edit expense"', false);

        $this->actingAs($manager)
            ->put(route('admin.expenses.update', $expense), $this->generalPayload($branch, ['amount' => 1]))
            ->assertForbidden();

        $this->assertSame('1200.00', $expense->fresh()->amount);
    }

    public function test_changing_funding_source_creates_or_removes_owner_payable(): void
    {
        [$branch, $admin] = $this->setUpAdmin();
        $expense = $this->storeExpense($branch, $admin);

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $expense), $this->generalPayload($branch, ['paid_from' => 'owner', 'amount' => 1500]))
            ->assertSessionHasNoErrors();

        $payable = AccountsPayable::query()->firstOrFail();
        $this->assertSame($payable->id, $expense->fresh()->accounts_payable_id);
        $this->assertSame('1500.00', $payable->balance);

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $expense), $this->generalPayload($branch, ['paid_from' => 'store_cash']))
            ->assertSessionHasNoErrors();

        $this->assertNull($expense->fresh()->accounts_payable_id);
        $this->assertDatabaseCount('accounts_payables', 0);
    }

    public function test_owner_payable_with_repayments_is_kept_in_sync(): void
    {
        [$branch, $admin] = $this->setUpAdmin();
        $expense = $this->storeExpense($branch, $admin, ['paid_from' => 'owner', 'amount' => 1000]);
        $payable = AccountsPayable::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.accounts-payable.payments.store', $payable), [
                'amount' => 400,
                'payment_date' => today()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $expense), $this->generalPayload($branch, ['paid_from' => 'owner', 'amount' => 1200]))
            ->assertSessionHasNoErrors();

        $payable->refresh();
        $this->assertSame('1200.00', $payable->original_amount);
        $this->assertSame('800.00', $payable->balance);
        $this->assertSame('partial', $payable->status);

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $expense), $this->generalPayload($branch, ['paid_from' => 'owner', 'amount' => 300]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $expense), $this->generalPayload($branch, ['paid_from' => 'store_cash', 'amount' => 1200]))
            ->assertSessionHasErrors('paid_from');

        $this->assertSame('1200.00', $expense->fresh()->amount);
        $this->assertSame('owner', $expense->fresh()->paid_from);
    }

    public function test_editing_salary_still_blocks_duplicate_pay_periods(): void
    {
        [$branch, $admin] = $this->setUpAdmin();
        $employee = $this->employee($branch);

        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->salaryPayload($branch, $employee));
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->salaryPayload($branch, $employee, [
            'salary_period_start' => '2026-10-16',
            'salary_period_end' => '2026-10-31',
        ]));
        $second = BranchExpense::query()->whereDate('salary_period_start', '2026-10-16')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $second), $this->salaryPayload($branch, $employee))
            ->assertSessionHasErrors('attendance_employee_id');

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $second), $this->salaryPayload($branch, $employee, [
                'salary_period_start' => '2026-10-16',
                'salary_period_end' => '2026-10-31',
                'amount' => 4800,
            ]))
            ->assertSessionHasNoErrors();
    }

    private function storeExpense(Branch $branch, User $user, array $overrides = []): BranchExpense
    {
        $this->actingAs($user)
            ->post(route('admin.expenses.store'), $this->generalPayload($branch, $overrides))
            ->assertSessionHasNoErrors();

        return BranchExpense::query()->latest('id')->firstOrFail();
    }

    private function generalPayload(Branch $branch, array $overrides = []): array
    {
        return $overrides + [
            'branch_id' => $branch->id,
            'category' => 'utilities',
            'title' => 'Electric Bill',
            'amount' => 1200,
            'expense_date' => '2026-10-10',
            'payment_method' => 'cash',
            'paid_from' => 'store_cash',
        ];
    }

    private function salaryPayload(Branch $branch, AttendanceEmployee $employee, array $overrides = []): array
    {
        return $overrides + [
            'branch_id' => $branch->id,
            'category' => 'payroll',
            'attendance_employee_id' => $employee->id,
            'salary_period_start' => '2026-10-01',
            'salary_period_end' => '2026-10-15',
            'title' => 'Salary - Ana Cruz',
            'amount' => 5000,
            'expense_date' => '2026-10-10',
            'payment_method' => 'cash',
            'paid_from' => 'store_cash',
        ];
    }

    private function employee(Branch $branch): AttendanceEmployee
    {
        return AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'username' => 'ana.cruz',
            'password' => Hash::make('password123'),
            'monthly_salary' => 10000,
            'status' => 'active',
        ]);
    }

    private function setUpAdmin(string $role = 'super_admin'): array
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00'));

        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => today()->subDay(),
            'trial_end_date' => today()->addDay(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $admin = User::factory()->create([
            'role' => $role,
            'access' => ['expenses', 'accounts_payable'],
        ]);

        return [$branch, $admin];
    }
}
