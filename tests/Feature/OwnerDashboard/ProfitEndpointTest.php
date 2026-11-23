<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\LaundryService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProfitEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private Branch $a;
    private User $owner;
    private AttendanceEmployee $maria;
    private LaundryService $regular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->owner = $this->admin();
        $this->a = $this->seedBranch('A');
        $this->regular = $this->service('Regular Laundry', 'kilo', 30);

        // Maria: ₱650/day, 14.5% employer share, scheduled Sun Sep 20–Sat Sep 26 (7), present Mon Sep 21–Sat Sep 26 (6).
        $this->maria = $this->employee($this->a, 'Maria', 650, ['schedule_role' => 'attendant', 'employer_share_rate' => 14.5]);
        foreach (range(20, 26) as $d) {
            $this->shift($this->maria, "2026-09-{$d}");
        }
        foreach (range(21, 26) as $d) {
            $this->attend($this->maria, "2026-09-{$d}");
        }

        $this->bill($this->a, 'rent', 12000, '2026-09-01', '2026-09-30');
        $this->bill($this->a, 'rent', 15500, '2026-10-01', '2026-10-31');
        $this->expense($this->a, '2026-09-22', 'other', 'Gloves', 320);
        $this->expense($this->a, '2026-09-22', 'other', 'Mop', 999, ['drawer_status' => 'pending']);
        $this->expense($this->a, '2026-09-26', 'payroll', 'Maria salary', 3900);
    }

    private function profit(string $from, string $to, string $branch = 'all'): TestResponse
    {
        return $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.profit', ['from' => $from, 'to' => $to, 'branch' => $branch]))
            ->assertOk();
    }

    /** Sep 21, 23, 24: one 100 kg order (₱3,000) and a closing each; Sep 22: 1,000 mL detergent used (₱500). */
    private function seedWeek(): void
    {
        foreach (['2026-09-21', '2026-09-23', '2026-09-24'] as $date) {
            $this->order($this->a, "{$date} 10:00:00", [[$this->regular, 100]]);
            $this->closing($this->a, $date);
        }
        $detergent = $this->stockItem($this->a, 'Detergent', 'mL', 0.5);
        $this->movement($detergent, '2026-09-22 11:00:00', 'out', -1000);
    }

    public function test_staff_cost_follows_work_days(): void
    {
        $this->profit('2026-09-19', '2026-09-25')
            ->assertJsonPath('staff.wages', 3250)
            ->assertJsonPath('staff.employer_share', 471.25)
            ->assertJsonPath('staff.total', 3721.25)
            ->assertJsonPath('money_out.staff.amount', 3721.25)
            ->assertJsonPath('staff.days_present', 5)
            ->assertJsonPath('staff.days_scheduled', 6)
            ->assertJsonPath('staff.attendance_pct', 83)
            ->assertJsonPath('staff.employees', [[
                'name' => 'Maria Staff', 'role' => 'attendant', 'daily_rate' => 650, 'days_worked' => 5, 'days_scheduled' => 6,
                'wages' => 3250, 'employer_share' => 471.25, 'total' => 3721.25,
            ]]);
    }

    public function test_single_day_bill_is_the_daily_share(): void
    {
        $this->profit('2026-09-15', '2026-09-15')
            ->assertJsonPath('money_out.bills.amount', 400)
            ->assertJsonPath('bills.total', 400)
            ->assertJsonPath('bills.rows', [['type' => 'rent', 'label' => 'Rent', 'amount' => 400, 'pct' => 100]]);
    }

    public function test_range_across_two_months_uses_each_months_daily_share(): void
    {
        // 10 × 12000/30 + 10 × 15500/31
        $this->profit('2026-09-21', '2026-10-10')->assertJsonPath('money_out.bills.amount', 9000);
    }

    public function test_unconfirmed_expense_is_counted_nowhere(): void
    {
        $this->expense($this->a, '2026-09-23', 'other', 'Broom', 150, ['drawer_status' => 'disputed']);
        $this->bill($this->a, 'water', 3000, '2026-09-01', '2026-09-30', ['drawer_status' => 'disputed']);
        $this->bill($this->a, 'wifi', 3000, '2026-09-01', '2026-09-30', ['drawer_status' => 'pending']);

        $this->profit('2026-09-21', '2026-09-26')
            ->assertJsonPath('money_out.other.amount', 320)
            ->assertJsonPath('other_purchases.rows', [['category' => 'Gloves', 'amount' => 320]])
            ->assertJsonPath('money_out.bills.amount', 2400)
            ->assertJsonPath('bills.rows.0.type', 'rent')
            ->assertJsonCount(1, 'bills.rows');
    }

    public function test_payroll_expense_is_not_an_other_purchase(): void
    {
        // Range covers the ₱3,900 payroll expense on Sep 26; staff = 6 days × 650 × 1.145 from attendance.
        $this->profit('2026-09-21', '2026-09-26')
            ->assertJsonPath('money_out.other.amount', 320)
            ->assertJsonPath('other_purchases.total', 320)
            ->assertJsonPath('staff.total', 4465.5);
    }

    public function test_supplies_cost_is_usage_not_purchases(): void
    {
        $this->seedWeek();
        $detergent = $this->stockItem($this->a, 'Bleach', 'mL', 0.2);
        $this->movement($detergent, '2026-09-22 09:00:00', 'in', 5000); // purchase: ₱1,000, not a cost
        $this->expense($this->a, '2026-09-22', 'supplies', 'Bleach', 1000);

        $this->profit('2026-09-19', '2026-09-25')
            ->assertJsonPath('money_out.supplies.amount', 500)
            ->assertJsonPath('money_out.other.amount', 320);
    }

    public function test_profit_and_per_order_and_shares_add_to_100(): void
    {
        $this->seedWeek();

        // Sales 9000 − (staff 3721.25 + bills 2800 + supplies 500 + other 320 = 7341.25) = 1658.75
        $res = $this->profit('2026-09-19', '2026-09-25')
            ->assertJsonPath('money_in', 9000)
            ->assertJsonPath('orders', 3)
            ->assertJsonPath('money_out.total.amount', 7341.25)
            ->assertJsonPath('profit', 1658.75)
            ->assertJsonPath('profit_pct', 18.4)
            ->assertJsonPath('profit_share', 18)
            ->assertJsonPath('money_out.staff', ['amount' => 3721.25, 'pct' => 41])
            ->assertJsonPath('money_out.bills', ['amount' => 2800, 'pct' => 31])
            ->assertJsonPath('money_out.supplies', ['amount' => 500, 'pct' => 6])
            ->assertJsonPath('money_out.other', ['amount' => 320, 'pct' => 4])
            ->assertJsonPath('money_out.total.pct', 82)
            ->assertJsonPath('per_order', [
                'sales' => 3000, 'staff' => 1240.42, 'bills' => 933.33, 'supplies' => 166.67, 'other' => 106.67, 'profit' => 552.92,
            ])
            ->assertJsonPath('staff.per_order', 1240.42)
            ->assertJsonPath('staff.per_kg', 12.4)
            ->assertJsonPath('bills.pct_of_sales', 31)
            ->assertJsonPath('other_purchases.pct_of_sales', 4);

        $shares = collect(['staff', 'bills', 'supplies', 'other'])->sum(fn ($k) => $res->json("money_out.{$k}.pct"));
        $this->assertSame(100, $shares + $res->json('profit_share'));
    }

    public function test_staff_count_and_kilos_and_the_supplies_used_most(): void
    {
        $this->seedWeek(); // Detergent used: ₱500
        foreach (['Bleach' => 900, 'Fabcon' => 300, 'Bags' => 200, 'Spray' => 100, 'Vinegar' => 50] as $name => $cost) {
            $this->movement($this->stockItem($this->a, $name, 'mL', 1), '2026-09-23 12:00:00', 'out', -$cost);
        }
        $this->movement($this->stockItem($this->a, 'Gloves box', 'pcs', 5), '2026-09-23 12:00:00', 'in', 100); // bought, not used
        $this->movement($this->stockItem($this->a, 'Free sample', 'pcs', 0), '2026-09-23 12:00:00', 'out', -10); // used, costs nothing

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) {
            $queries += str_contains($q->sql, 'from "inventory_movements"') ? 1 : 0;
        });

        $this->profit('2026-09-19', '2026-09-25')
            ->assertJsonPath('staff.employees_count', 1)
            ->assertJsonPath('staff.kg', 300)
            ->assertJsonPath('supplies_items', ['Bleach', 'Detergent', 'Fabcon', 'Bags', 'Spray'])
            ->assertJsonPath('money_out.supplies.amount', 2050);
        $this->assertSame(1, $queries, 'the profit request reads stock movements once: the use-class aggregate');

        $queries = 0;
        $this->actingAs($this->owner)->getJson(route('owner-dashboard.api.supplies', ['from' => '2026-09-19', 'to' => '2026-09-25', 'branch' => 'all']))->assertOk();
        $this->assertSame(2, $queries, 'the supplies request reads stock movements twice: the use-class aggregate and the listed purchase/adjustment rows');
    }

    public function test_break_even_covered_on_day(): void
    {
        $this->seedWeek();

        // Total costs 7341.25; cumulative Sales 3000 (Sep 21), 6000 (Sep 23), 9000 (Sep 24) → day 6 of 7.
        $this->profit('2026-09-19', '2026-09-25')
            ->assertJsonPath('break_even', [
                'per_day' => 2447.08, 'actual_per_day' => 3000, 'difference' => 552.92, 'covered_on' => ['day' => 6, 'of' => 7],
            ]);
    }

    public function test_loss_has_negative_profit_and_no_break_even_day(): void
    {
        $this->order($this->a, '2026-09-21 10:00:00', [[$this->regular, 100]]);
        $this->closing($this->a, '2026-09-21');

        // Sales 3000 − costs (3721.25 + 2800 + 0 + 320 = 6841.25) = −3841.25; shares are each amount ÷ Sales.
        $this->profit('2026-09-19', '2026-09-25')
            ->assertJsonPath('profit', -3841.25)
            ->assertJsonPath('profit_pct', -128)
            ->assertJsonPath('profit_share', -128)
            ->assertJsonPath('money_out.staff.pct', 124)
            ->assertJsonPath('money_out.bills.pct', 93)
            ->assertJsonPath('money_out.other.pct', 11)
            ->assertJsonPath('money_out.total.pct', 228)
            ->assertJsonPath('break_even.covered_on', null)
            ->assertJsonPath('break_even.difference', -3841.25);
    }

    public function test_employee_without_daily_rate_costs_zero_and_is_listed(): void
    {
        $pedro = $this->employee($this->a, 'Pedro', null, ['schedule_role' => 'rider']);
        $this->attend($pedro, '2026-09-22');

        $res = $this->profit('2026-09-19', '2026-09-25')->assertJsonPath('staff.total', 3721.25);
        $this->assertSame([
            'name' => 'Pedro Staff', 'role' => 'rider', 'daily_rate' => null, 'days_worked' => 1, 'days_scheduled' => 0,
            'wages' => 0, 'employer_share' => 0, 'total' => 0,
        ], $res->json('staff.employees.1'));
    }

    public function test_bill_period_partly_outside_range_counts_overlap_only(): void
    {
        // Sep 28–Oct 3: 3 × 400 + 3 × 500
        $this->profit('2026-09-28', '2026-10-03')->assertJsonPath('money_out.bills.amount', 2700);
    }

    public function test_bill_with_null_or_inverted_period_is_skipped(): void
    {
        $this->bill($this->a, 'electricity', 5000, null, null);
        $this->bill($this->a, 'water', 5000, '2026-09-30', '2026-09-01');

        $this->profit('2026-09-15', '2026-09-15')->assertJsonPath('money_out.bills.amount', 400);
    }

    public function test_absent_record_and_unpublished_or_rest_shift_do_not_count(): void
    {
        $this->shift($this->maria, '2026-09-27', 'opening', false);
        $this->shift($this->maria, '2026-09-28', 'rest');
        $this->attend($this->maria, '2026-09-29', null, false);

        $this->profit('2026-09-20', '2026-09-30')
            ->assertJsonPath('staff.days_scheduled', 7)
            ->assertJsonPath('staff.days_present', 6)
            ->assertJsonPath('staff.attendance_pct', 86);
    }

    public function test_employee_at_two_branches_on_one_day_is_paid_once(): void
    {
        $b = $this->seedBranch('B');
        $juan = $this->employee($this->a, 'Juan', 500);
        $this->attend($juan, '2026-10-05', $this->a);
        $this->attend($juan, '2026-10-05', $b);

        $all = $this->profit('2026-10-05', '2026-10-05')->assertJsonPath('staff.total', 572.5)->assertJsonPath('staff.days_present', 1);
        $ja = $this->profit('2026-10-05', '2026-10-05', (string) $this->a->id)->assertJsonPath('staff.total', 572.5);
        $jb = $this->profit('2026-10-05', '2026-10-05', (string) $b->id)->assertJsonPath('staff.total', 0)->assertJsonPath('staff.employees', []);

        foreach (['staff.total', 'money_out.bills.amount', 'money_out.total.amount', 'profit'] as $key) {
            $this->assertEquals($ja->json($key) + $jb->json($key), $all->json($key), $key);
        }
    }

    public function test_empty_range_returns_zeros(): void
    {
        $this->profit('2026-08-01', '2026-08-02')
            ->assertJsonPath('money_in', 0)
            ->assertJsonPath('orders', 0)
            ->assertJsonPath('money_out.total', ['amount' => 0, 'pct' => 0])
            ->assertJsonPath('profit', 0)
            ->assertJsonPath('profit_pct', 0)
            ->assertJsonPath('profit_share', 0)
            ->assertJsonPath('per_order', ['sales' => 0, 'staff' => 0, 'bills' => 0, 'supplies' => 0, 'other' => 0, 'profit' => 0])
            ->assertJsonPath('break_even', ['per_day' => 0, 'actual_per_day' => 0, 'difference' => 0, 'covered_on' => null])
            ->assertJsonPath('staff', [
                'total' => 0, 'wages' => 0, 'employer_share' => 0, 'attendance_pct' => 0, 'days_present' => 0, 'days_scheduled' => 0,
                'per_order' => 0, 'per_kg' => 0, 'employees' => [], 'employees_count' => 0, 'kg' => 0,
            ])
            ->assertJsonPath('supplies_items', [])
            ->assertJsonPath('bills', ['total' => 0, 'pct_of_sales' => 0, 'rows' => []])
            ->assertJsonPath('other_purchases', ['total' => 0, 'pct_of_sales' => 0, 'rows' => []]);
    }

    public function test_other_purchases_group_by_title(): void
    {
        $this->expense($this->a, '2026-09-23', 'other', ' gloves ', 80);
        $this->expense($this->a, '2026-09-23', 'repairs_maintenance', '', 200);

        $this->profit('2026-09-21', '2026-09-26')
            ->assertJsonPath('other_purchases.rows', [
                ['category' => 'Gloves', 'amount' => 400],
                ['category' => 'Repairs maintenance', 'amount' => 200],
            ]);
    }

    public function test_other_purchase_without_title_or_type_is_other(): void
    {
        $this->expense($this->a, '2026-09-23', 'other', '  ', 50, ['expense_type' => '']);

        $this->profit('2026-09-21', '2026-09-26')
            ->assertJsonPath('other_purchases.rows', [
                ['category' => 'Gloves', 'amount' => 320],
                ['category' => 'Other', 'amount' => 50],
            ]);
    }

    public function test_legacy_operating_rows_are_excluded_by_their_category(): void
    {
        // Rows from before the `operating` remap keep their real type only in `category`.
        $this->expense($this->a, '2026-09-23', 'operating', 'Salaries', 1000, ['category' => 'Payroll']);
        $this->expense($this->a, '2026-09-23', 'regular', 'Detergent stock', 500, ['category' => 'inventory purchase']);
        $this->expense($this->a, '2026-09-23', 'operating', 'Soap', 200, ['category' => ' Supplies ']);
        $this->expense($this->a, '2026-09-23', 'operating', 'Fix pump', 150, ['category' => 'repairs']);

        $this->profit('2026-09-21', '2026-09-26')
            ->assertJsonPath('money_out.other.amount', 470)
            ->assertJsonPath('other_purchases.rows', [
                ['category' => 'Gloves', 'amount' => 320],
                ['category' => 'Fix pump', 'amount' => 150],
            ]);
    }

    public function test_overview_profit_card_matches_profit_endpoint(): void
    {
        $this->seedWeek();
        $profit = $this->profit('2026-09-19', '2026-09-25');

        $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.overview', ['from' => '2026-09-19', 'to' => '2026-09-25']))
            ->assertOk()
            ->assertJsonPath('kpis.profit', $profit->json('profit'))
            ->assertJsonPath('kpis.profit_pct', $profit->json('profit_pct'))
            ->assertJsonPath('kpis.profit', 1658.75);
    }

    public function test_non_admin_gets_403_and_bad_range_gets_400(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->getJson(route('owner-dashboard.api.profit', ['from' => 'bad']))->assertForbidden();
        $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.profit', ['from' => '2026-09-30', 'to' => '2026-09-01']))->assertStatus(400);
    }
}
