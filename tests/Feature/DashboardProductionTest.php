<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_for_core_roles_and_defaults_to_today_data(): void
    {
        $this->settings();

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Dashboard Customer',
            'billing_type' => 'regular',
            'is_active' => true,
        ]);

        $todayOrder = $this->order($branch, $customer, 'JO-DASH-TODAY', 250, today()->setTime(9, 0));
        $this->payment($branch, $customer, $todayOrder, 'PAY-DASH-TODAY', 250, today()->setTime(9, 10));

        $oldOrder = $this->order($branch, $customer, 'JO-DASH-OLD', 999, today()->subDay()->setTime(9, 0));
        $this->payment($branch, $customer, $oldOrder, 'PAY-DASH-OLD', 999, today()->subDay()->setTime(9, 10));

        foreach (['super_admin', 'admin', 'branch_manager', 'cashier', 'staff'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'branch_id' => $role === 'super_admin' || $role === 'admin' ? null : $branch->id,
                'access' => ['dashboard'],
            ]);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Dashboard');

            $payload = $this->actingAs($user)
                ->getJson(route('dashboard.data'))
                ->assertOk()
                ->json();

            $this->assertSame('PHP 250.00', $payload['stats']['sales']);
            $this->assertSame('1', $payload['stats']['orders']);
        }
    }

    public function test_dashboard_filters_by_a_custom_date_range(): void
    {
        $this->settings();

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Range Customer',
            'billing_type' => 'regular',
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'access' => ['dashboard']]);

        $inRange = $this->order($branch, $customer, 'JO-RANGE-IN', 400, today()->subDays(10)->setTime(9, 0));
        $this->payment($branch, $customer, $inRange, 'PAY-RANGE-IN', 400, today()->subDays(10)->setTime(9, 10));
        $outside = $this->order($branch, $customer, 'JO-RANGE-OUT', 700, today()->subDays(30)->setTime(9, 0));
        $this->payment($branch, $customer, $outside, 'PAY-RANGE-OUT', 700, today()->subDays(30)->setTime(9, 10));

        // Picked end-first on purpose: the range is put back in order.
        $range = today()->subDays(5)->toDateString().' to '.today()->subDays(12)->toDateString();
        $payload = $this->actingAs($admin)
            ->getJson(route('dashboard.data', ['date_range' => $range]))
            ->assertOk()
            ->json();

        $this->assertSame('PHP 400.00', $payload['stats']['sales']);
        $this->assertSame('custom', $payload['current_period']);
        $this->assertStringContainsString(today()->subDays(12)->format('M j'), $payload['today']['header']['range_label']);

        $this->actingAs($admin)
            ->get(route('dashboard', ['date_range' => $range]))
            ->assertOk()
            ->assertSee('aria-label="Custom date range"', false);
    }

    public function test_payroll_table_no_longer_shows_employer_share(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'super_admin', 'access' => ['dashboard']]);

        $this->actingAs($admin)
            ->get(route('dashboard', ['tab' => 'costs']))
            ->assertOk()
            ->assertDontSee('Employer Share');

        $costs = $this->actingAs($admin)->getJson(route('dashboard.data'))->json('monthly_costs');
        $this->assertArrayNotHasKey('total_employee_share', $costs);
    }

    private function settings(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Manila',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
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

    private function order(Branch $branch, Customer $customer, string $number, float $amount, $createdAt): JobOrder
    {
        $order = JobOrder::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'job_order_number' => $number,
            'status' => 'completed',
            'subtotal' => $amount,
            'total' => $amount,
            'paid_amount' => $amount,
            'balance' => 0,
        ]);

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $order;
    }

    public function test_dashboard_tabs_and_payload_structure(): void
    {
        $this->settings();

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Test Customer',
            'billing_type' => 'regular',
            'is_active' => true,
        ]);

        $order = $this->order($branch, $customer, 'JO-TEST-TAB', 500, today()->setTime(10, 0));
        $this->payment($branch, $customer, $order, 'PAY-TEST-TAB', 500, today()->setTime(10, 15));

        $admin = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['dashboard'],
        ]);

        // Test GET with each tab
        foreach (['today', 'supplies', 'costs'] as $tab) {
            $response = $this->actingAs($admin)->get(route('dashboard', ['tab' => $tab]));
            $response->assertOk();
            $response->assertSee('Today');
            $response->assertSee('Supplies & inventory', false);
            $response->assertSee('Monthly costs');
        }

        // Test API payload structure
        $payload = $this->actingAs($admin)
            ->getJson(route('dashboard.data'))
            ->assertOk()
            ->json();

        // Verify Today Data
        $this->assertArrayHasKey('today', $payload);
        $this->assertSame('PHP 500.00', $payload['today']['sales_today']);
        $this->assertSame(1, $payload['today']['orders_count']);
        $this->assertSame('PHP 500.00', $payload['today']['money_collected']);
        $this->assertSame(100, $payload['today']['collection_percent']);
        $this->assertSame(1, $payload['today']['pipeline']['completed']);

        // Verify Supplies Data
        $this->assertArrayHasKey('supplies', $payload);
        $this->assertArrayHasKey('consumables', $payload['supplies']);
        $this->assertArrayHasKey('stock_movement_by_week', $payload['supplies']);
        $this->assertCount(4, $payload['supplies']['stock_movement_by_week']);

        // Verify Monthly Costs Data
        $this->assertArrayHasKey('monthly_costs', $payload);
        $this->assertArrayHasKey('employees', $payload['monthly_costs']);
        $this->assertArrayHasKey('bills_history_6m', $payload['monthly_costs']);
        $this->assertCount(6, $payload['monthly_costs']['bills_history_6m']['months']);
        $this->assertNotEmpty($payload['monthly_costs']['bills_history_6m']['range_label']);
        $this->assertArrayHasKey('bills_vs_last_month', $payload['monthly_costs']);
        $this->assertArrayHasKey('waterfall', $payload['monthly_costs']);
        $this->assertArrayHasKey('other_purchases', $payload['monthly_costs']);
        $this->assertArrayHasKey('vs_last_month', $payload['monthly_costs']['other_purchases']);
    }

    public function test_supplies_and_monthly_costs_follow_the_chosen_period(): void
    {
        $this->settings();

        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $branch->id,
            'access' => ['dashboard'],
        ]);
        $lastMonth = now()->startOfMonth()->subMonthNoOverflow();

        BranchExpense::query()->create([
            'branch_id' => $branch->id,
            'category' => 'Utilities',
            'expense_type' => 'utilities',
            'title' => 'Electric bill',
            'amount' => 3200,
            'expense_date' => $lastMonth->copy()->addDays(9)->toDateString(),
            'payment_method' => 'cash',
            'paid_from' => 'store_cash',
            'created_by' => $admin->id,
        ]);
        $detergent = Inventory::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Mrs. Bloom Detergent',
            'sku' => 'SUP-BLOOM',
            'unit' => 'liter',
            'quantity' => 10,
            'reorder_level' => 2,
            'unit_cost' => 100,
            'is_active' => true,
        ]);
        $detergent->movements()->create([
            'user_id' => $admin->id,
            'movement_type' => 'out',
            'quantity' => 2,
            'remarks' => 'Auto deducted for JO-LAST-MONTH',
        ])->forceFill(['created_at' => $lastMonth->copy()->addDays(9)->setTime(10, 0)])->save();

        // Nothing chosen: the other tabs keep showing the current month.
        $current = $this->actingAs($admin)->getJson(route('dashboard.data'))->assertOk()->json();
        $this->assertFalse($current['period_chosen']);
        $this->assertSame(now()->format('F Y'), $current['tab_period']['label']);
        $this->assertSame('PHP 0.00', $current['monthly_costs']['bills_this_month']);
        $this->assertSame('PHP 0.00', $current['supplies']['supplies_used_month']);

        // A chosen date range moves Supplies and Monthly costs with it.
        $range = $lastMonth->copy()->startOfMonth()->toDateString().' to '.$lastMonth->copy()->endOfMonth()->toDateString();
        $chosen = $this->actingAs($admin)->getJson(route('dashboard.data', ['date_range' => $range]))->assertOk()->json();
        $this->assertTrue($chosen['period_chosen']);
        $this->assertSame($lastMonth->format('F Y'), $chosen['tab_period']['label']);
        $this->assertSame('PHP 3,200.00', $chosen['monthly_costs']['bills_this_month']);
        $this->assertSame('PHP 200.00', $chosen['supplies']['supplies_used_month']);
        $this->assertSame($lastMonth->format('M'), end($chosen['monthly_costs']['bills_history_6m']['months']));

        // The page shows the period controls on the other tabs too.
        $this->actingAs($admin)
            ->get(route('dashboard', ['tab' => 'costs', 'date_range' => $range]))
            ->assertOk()
            ->assertSee('changePeriod(\'this_month\')', false)
            ->assertSee('Custom date range');
    }

    public function test_payroll_is_prorated_to_the_chosen_period(): void
    {
        $dashboard = app(\App\Http\Controllers\Admin\DashboardController::class);
        $salaryMonths = fn (string $from, string $to) => (new \ReflectionMethod($dashboard, 'salaryMonthsInPeriod'))->invoke($dashboard, $from, $to);

        // A whole month is exactly one month of salary; one day of a 30-day month is 1/30.
        $this->assertSame(1.0, $salaryMonths('2026-09-01', '2026-09-30'));
        $this->assertSame(round(1 / 30, 4), $salaryMonths('2026-09-10', '2026-09-10'));
        // Sep 16 – Oct 15: 15/30 of September + 15/31 of October.
        $this->assertSame(round(15 / 30 + 15 / 31, 4), $salaryMonths('2026-09-16', '2026-10-15'));
    }

    private function payment(Branch $branch, Customer $customer, JobOrder $order, string $number, float $amount, $paidAt): Payment
    {
        return Payment::query()->create([
            'branch_id' => $branch->id,
            'collected_branch_id' => $branch->id,
            'job_order_id' => $order->id,
            'customer_id' => $customer->id,
            'payment_number' => $number,
            'payment_type' => 'cash',
            'amount' => $amount,
            'settlement_status' => 'local',
            'paid_at' => $paidAt,
        ]);
    }
}

