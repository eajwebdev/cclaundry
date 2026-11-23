<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\User;
use App\Models\ZReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A busy September on two branches: day views add up to the range view, and "all" equals the branches. */
class RangeConsistencyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private const KEYS = [
        'sales', 'orders', 'kg_washed', 'payments', 'profit',
        'supplies_used', 'stock_purchased', 'adjustments', 'addons_sold',
        'money_in', 'staff', 'bills', 'supplies_cost', 'other', 'money_out', 'profit_total',
    ];

    private const CLOSED_DAY = 16;

    private User $owner;
    private Branch $a;
    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->owner = $this->admin();
        mt_srand(1); // after admin(): the fixture must not depend on factory internals
        $this->a = $this->seedBranch('A');
        $this->b = $this->seedBranch('B');

        $kilo = $this->service('Regular Laundry', 'kilo', 30);
        $load = $this->service('Bed Sheets', 'load', 100);
        $load->update(['kilos_per_load' => 8]);
        $addon = $this->service('Fabric Softener', 'piece', 15, true);

        foreach ([$this->a, $this->b] as $branch) {
            $this->seedBranchMonth($branch, $kilo, $load, $addon);
        }
        $this->assertFixtureHasEveryCase();
    }

    private function seedBranchMonth(Branch $br, $kilo, $load, $addon): void
    {
        $soap = $this->stockItem($br, 'Detergent', 'mL', 0.45);
        $this->movement($soap, '2026-09-01 07:00:00', 'in', 20000);

        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-09-%02d', $d);
            if ($d !== self::CLOSED_DAY) {
                $this->closing($br, $date);
            }
            // Closed day (Wednesday Sep 16): no closing, no orders, but staff still work and get paid.
            for ($n = $d === self::CLOSED_DAY ? 0 : mt_rand(2, 5); $n > 0; $n--) {
                $at = sprintf('%s %02d:%02d:00', $date, mt_rand(8, 19), mt_rand(0, 59));
                $lines = [[$kilo, mt_rand(2, 16) + 0.5], [$load, mt_rand(1, 3)], [$addon, mt_rand(1, 4)]];
                $order = $this->order($br, $at, array_slice($lines, 0, mt_rand(1, 3)));
                $this->movement($soap, $at, 'out', -mt_rand(50, 400) * 1.5);

                $roll = mt_rand(1, 10);
                if ($roll === 1) {
                    $order->update(['status' => 'cancelled']);
                } elseif ($roll <= 5) {
                    $this->pay($order, $at, 'cash', (float) $order->total);
                } elseif ($roll <= 7) {
                    $this->pay($order, $at, 'gcash', round((float) $order->total / 3, 2));
                } elseif ($roll === 8 && $d < 30) {
                    $this->pay($order, sprintf('2026-09-%02d 09:30:00', $d + 1), 'bank', (float) $order->total);
                }
            }
        }

        foreach ([5, 12, 21, 27] as $d) {
            $this->movement($soap, sprintf('2026-09-%02d 08:00:00', $d), 'in', 5000);
        }
        $this->movement($soap, '2026-09-10 18:00:00', 'adjustment', -300, ['quantity' => 12000, 'reason' => 'count']);
        $this->movement($soap, '2026-09-22 18:00:00', 'adjustment', -150, ['quantity' => 15000, 'reason' => 'count']);
        $this->movement($soap, '2026-09-14 11:00:00', 'in', 120, ['reason' => 'cancelled_order']);
        $this->movement($soap, '2026-09-18 11:00:00', 'in', 200, ['reason' => 'order_edit']);

        foreach (['Ana', 'Ben', 'Cris'] as $i => $name) {
            $emp = $this->employee($br, $name.$br->code, 500 + 50 * $i, $i === 2 ? ['employer_share_rate' => 10] : []);
            for ($d = 1; $d <= 30; $d++) {
                if (date('N', strtotime(sprintf('2026-09-%02d', $d))) == 7) {
                    continue; // no Sunday shifts
                }
                $date = sprintf('2026-09-%02d', $d);
                $this->shift($emp, $date);
                if (mt_rand(1, 10) > 2) {
                    $this->attend($emp, $date);
                }
            }
        }

        $this->bill($br, 'rent', 12000, '2026-09-01', '2026-09-30');
        $this->bill($br, 'electricity', 6400, '2026-09-01', '2026-09-30');
        $this->bill($br, 'water', 1500, '2026-08-16', '2026-09-15');
        $this->bill($br, 'wifi', 1999, '2026-09-16', '2026-10-15');
        $this->bill($br, 'lpg', 7777, '2026-09-01', '2026-09-30', ['drawer_status' => 'pending']);

        foreach ([3, 8, 14, 19, 24, 28] as $k => $d) {
            $this->expense($br, sprintf('2026-09-%02d', $d), 'other', 'Supplies run '.$k, 100 + 37 * $k);
        }
        $this->expense($br, '2026-09-15', 'other', 'Disputed', 888, ['drawer_status' => 'disputed']);
    }

    /** @return array<string, float|int> every asserted number for one range and branch */
    private function numbers(string $from, string $to, string $branch = 'all'): array
    {
        $get = fn (string $name) => $this->actingAs($this->owner)
            ->getJson(route("owner-dashboard.api.{$name}", ['from' => $from, 'to' => $to, 'branch' => $branch]))->assertOk()->json();
        $o = $get('overview');
        $s = $get('supplies');
        $p = $get('profit');
        $amount = fn ($v) => is_array($v) ? $v['amount'] : $v; // money_out lines may be {amount, pct} blocks

        return [
            'sales' => $o['kpis']['sales'], 'orders' => $o['kpis']['orders'], 'kg_washed' => $o['kpis']['kg_washed'],
            'payments' => $o['payments']['total'], 'profit' => $o['kpis']['profit'],
            'supplies_used' => $s['kpis']['supplies_used'], 'stock_purchased' => $s['kpis']['stock_purchased'],
            'adjustments' => $s['kpis']['adjustments'], 'addons_sold' => $s['kpis']['addons_sold'],
            'money_in' => $amount($p['money_in']), 'staff' => $amount($p['money_out']['staff']), 'bills' => $amount($p['money_out']['bills']),
            'supplies_cost' => $amount($p['money_out']['supplies']), 'other' => $amount($p['money_out']['other']),
            'money_out' => $amount($p['money_out']['total']), 'profit_total' => $amount($p['profit']),
            'open_days' => $o['period']['open_days'],
        ];
    }

    private function assertTotalsMatch(array $expected, array $actual, string $label): void
    {
        foreach (self::KEYS as $key) {
            $key === 'orders'
                ? $this->assertSame((int) $expected[$key], (int) $actual[$key], "{$label}: {$key}")
                : $this->assertEqualsWithDelta($expected[$key], $actual[$key], 0.5, "{$label}: {$key}");
        }
    }

    /** A pass must not come from zeros: every asserted number is non-zero. */
    private function assertNoZeros(array $numbers, string $label): void
    {
        foreach (self::KEYS as $key) {
            $this->assertNotEquals(0.0, (float) $numbers[$key], "{$label}: {$key} is zero");
        }
    }

    private function sum(array ...$parts): array
    {
        $total = array_fill_keys(self::KEYS, 0);
        foreach ($parts as $part) {
            foreach (self::KEYS as $key) {
                $total[$key] += $part[$key];
            }
        }

        return $total;
    }

    /** The random rolls must really have produced each case the totals are meant to cover. */
    private function assertFixtureHasEveryCase(): void
    {
        $orders = JobOrder::withoutGlobalScopes()->get();
        $this->assertGreaterThanOrEqual(5, $orders->where('status', 'cancelled')->count(), 'cancelled orders');
        $this->assertGreaterThanOrEqual(5, $orders->filter(fn ($o) => $o->status !== 'cancelled' && $o->balance > 0 && $o->paid_amount > 0)->count(), 'partly paid');

        $nextDay = Payment::withoutGlobalScopes()->get()->groupBy('job_order_id')->filter(function ($payments, $id) use ($orders) {
            return $payments->min('paid_at')->toDateString() === $orders->find($id)->created_at->copy()->addDay()->toDateString();
        });
        $this->assertGreaterThanOrEqual(5, $nextDay->count(), 'orders first paid the next day');

        $hours = $orders->groupBy(fn ($o) => $o->created_at->toDateString())->filter(fn ($day) => $day->contains(fn ($o) => $o->created_at->hour < 12) && $day->contains(fn ($o) => $o->created_at->hour >= 12));
        $this->assertNotEmpty($hours, 'a day with orders before and after noon');

        $closedDay = sprintf('2026-09-%02d', self::CLOSED_DAY);
        $this->assertSame(0, $orders->filter(fn ($o) => $o->created_at->toDateString() === $closedDay)->count(), 'orders on the closed day');
        $this->assertSame(0, ZReading::withoutGlobalScopes()->whereDate('business_date', $closedDay)->count(), 'closing on the closed day');

        foreach ([$this->a, $this->b] as $br) {
            $expenses = BranchExpense::withoutGlobalScopes()->where('branch_id', $br->id);
            $this->assertSame(1, (clone $expenses)->whereNotNull('bill_type')->where('drawer_status', 'pending')->count(), "{$br->code} pending bill");
            $this->assertSame(1, (clone $expenses)->where('drawer_status', 'disputed')->count(), "{$br->code} disputed expense");
        }
        $movements = InventoryMovement::query();
        $this->assertTrue((clone $movements)->where('reason', 'order_edit')->exists(), 'order_edit movement');
        $this->assertTrue((clone $movements)->where('reason', 'cancelled_order')->exists(), 'cancelled_order movement');
        $this->assertTrue((clone $movements)->where('reason', 'count')->where('change_qty', '<', 0)->exists(), 'negative count movement');
    }

    public function test_thirty_one_day_views_add_up_to_the_month_view(): void
    {
        $month = $this->numbers('2026-09-01', '2026-09-30');
        $days = [];
        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-09-%02d', $d);
            $days[$d] = $this->numbers($date, $date);
        }

        $this->assertNoZeros($month, 'month');
        $this->assertSame(29, $month['open_days']);

        // Closed day: staff are still paid, nothing was sold.
        $this->assertGreaterThan(0, $days[self::CLOSED_DAY]['staff']);
        $this->assertEquals(0, $days[self::CLOSED_DAY]['sales']);

        $this->assertTotalsMatch($month, $this->sum(...$days), 'month vs 30 days');
    }

    public function test_all_branches_equals_sum_of_branches_for_every_total(): void
    {
        $all = $this->numbers('2026-09-01', '2026-09-30');
        $a = $this->numbers('2026-09-01', '2026-09-30', (string) $this->a->id);
        $b = $this->numbers('2026-09-01', '2026-09-30', (string) $this->b->id);

        $this->assertNoZeros($all, 'all');
        $this->assertNoZeros($a, 'branch A');
        $this->assertNoZeros($b, 'branch B');
        $this->assertTotalsMatch($all, $this->sum($a, $b), 'all vs A+B');
    }

    public function test_two_halves_add_up_to_the_month(): void
    {
        $month = $this->numbers('2026-09-01', '2026-09-30');
        $first = $this->numbers('2026-09-01', '2026-09-15');
        $second = $this->numbers('2026-09-16', '2026-09-30');

        $this->assertNoZeros($month, 'month');
        $this->assertNoZeros($first, 'Sep 1-15');
        $this->assertNoZeros($second, 'Sep 16-30');
        $this->assertTotalsMatch($month, $this->sum($first, $second), 'month vs halves');
    }
}
