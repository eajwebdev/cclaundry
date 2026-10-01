<?php

namespace Tests\Feature;

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

class SalaryExpenseTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_expense_records_employee_period_and_updates_dashboard_unpaid_list(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00'));
        $this->settings();

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $owner = User::factory()->create([
            'role' => 'super_admin',
            'access' => ['dashboard', 'expenses', 'employees'],
        ]);
        $alice = $this->employee($branch, 'Alice', 'Santos', 13000);
        $bob = $this->employee($branch, 'Bob', 'Reyes', 12000);

        BranchExpense::query()->create([
            'branch_id' => $branch->id,
            'category' => 'utilities',
            'expense_type' => 'utilities',
            'title' => 'Electric Bill',
            'amount' => 3000,
            'expense_date' => today(),
            'paid_from' => 'store_cash',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.expenses.store'), [
                'branch_id' => $branch->id,
                'category' => 'payroll',
                'attendance_employee_id' => $alice->id,
                'salary_period_start' => '2026-10-01',
                'salary_period_end' => '2026-10-15',
                'title' => 'Salary - Alice Santos',
                'amount' => 6500,
                'expense_date' => '2026-10-10',
                'payment_method' => 'cash',
                'paid_from' => 'store_cash',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branch_expenses', [
            'attendance_employee_id' => $alice->id,
            'expense_type' => 'payroll',
            'salary_period_start' => '2026-10-01 00:00:00',
            'salary_period_end' => '2026-10-15 00:00:00',
            'amount' => 6500,
        ]);

        $this->actingAs($owner)
            ->get(route('admin.expenses.index', [
                'branch_id' => $branch->id,
                'expense_type' => 'payroll',
                'employee_id' => $alice->id,
            ]))
            ->assertOk()
            ->assertSee('Salary - Alice Santos')
            ->assertSee('Salary Expenses')
            ->assertDontSee('Electric Bill');

        $payload = $this->actingAs($owner)
            ->getJson(route('dashboard.data', ['branch_id' => $branch->id]))
            ->assertOk()
            ->json('monthly_costs');

        $this->assertSame(1, $payload['unpaid_employee_count']);
        $this->assertSame('Bob Reyes', $payload['unpaid_employees'][0]['name']);
        $this->assertSame('PHP 6,000.00', $payload['salary_pending_current_period']);
        $this->assertSame('PHP 6,500.00', $payload['salary_paid_this_month']);
        $this->assertSame('15th: Paid', collect($payload['employees'])->firstWhere('name', 'Alice Santos')['pay_status_1']);
        $this->assertSame('15th: Upcoming', collect($payload['employees'])->firstWhere('name', 'Bob Reyes')['pay_status_1']);
    }

    public function test_duplicate_salary_period_and_employee_from_another_branch_are_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00'));
        $this->settings();

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Other Branch', 'code' => 'OTHER', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'super_admin', 'access' => ['expenses']]);
        $employee = $this->employee($branch, 'Ana', 'Cruz', 10000);
        $otherEmployee = $this->employee($otherBranch, 'Mia', 'Lim', 10000);
        $payload = [
            'branch_id' => $branch->id,
            'category' => 'payroll',
            'attendance_employee_id' => $employee->id,
            'salary_period_start' => '2026-10-01',
            'salary_period_end' => '2026-10-15',
            'title' => 'Salary - Ana Cruz',
            'amount' => 5000,
            'expense_date' => '2026-10-10',
            'paid_from' => 'store_cash',
        ];

        $this->actingAs($owner)->post(route('admin.expenses.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('admin.expenses.store'), $payload)
            ->assertSessionHasErrors('attendance_employee_id');

        $payload['attendance_employee_id'] = $otherEmployee->id;
        $this->actingAs($owner)->post(route('admin.expenses.store'), $payload)
            ->assertSessionHasErrors('attendance_employee_id');

        $this->assertSame(1, BranchExpense::query()->where('expense_type', 'payroll')->count());
    }

    private function employee(Branch $branch, string $firstName, string $lastName, float $salary): AttendanceEmployee
    {
        return AttendanceEmployee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => strtolower($firstName.'.'.$lastName),
            'password' => Hash::make('password123'),
            'monthly_salary' => $salary,
            'status' => 'active',
        ]);
    }

    private function settings(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#A07148',
            'is_completed' => true,
        ]);

        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => today()->subDay(),
            'trial_end_date' => today()->addDay(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
    }
}
