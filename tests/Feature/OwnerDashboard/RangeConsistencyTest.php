<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\User;
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

    private User $owner;
    private Branch $a;
    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        mt_srand(1);
        $this->owner = $this->admin();
        $this->a = $this->seedBranch('A');
        $this->b = $this->seedBranch('B');

        $kilo = $this->service('Regular Laundry', 'kilo', 30);
        $load = $this->service('Bed Sheets', 'load', 100);
        $load->update(['kilos_per_load' => 8]);
        $addon = $this->service('Fabric Softener', 'piece', 15, true);

        foreach ([$this->a, $this->b] as $branch) {
            $this->seedBranchMonth($branch, $kilo, $load, $addon);
        }
    }

    private function seedBranchMonth(Branch $br, $kilo, $load, $addon): void
    {
        $soap = $this->stockItem($br, 'Detergent', 'mL', 0.45);
        $this->movement($soap, '2026-09-01 07:00:00', 'in', 20000);

        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-09-%02d', $d);
            if ($d === 13) {
                continue; // closed day: no closing, no orders
            }
            $this->closing($br, $date);

            for ($n = mt_rand(2, 5); $n > 0; $n--) {
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
        $this->movement($soap, '2026-09-10 18:00:00', 'adjustment', -300, ['quantity' => 12000]);
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

    /** @return array<string, float> every asserted number for one range and branch */
    private function numbers(string $from, string $to, string $branch = 'all'): array
    {
        $get = fn (string $name) => $this->actingAs($this->owner)
            ->getJson(route("owner-dashboard.api.{$name}", ['from' => $from, 'to' => $to, 'branch' => $branch]))->assertOk()->json();
        $o = $get('overview');
        $s = $get('supplies');
        $p = $get('profit');

        return [
            'sales' => $o['kpis']['sales'], 'orders' => $o['kpis']['orders'], 'kg_washed' => $o['kpis']['kg_washed'],
            'payments' => $o['payments']['total'], 'profit' => $o['kpis']['profit'],
            'supplies_used' => $s['kpis']['supplies_used'], 'stock_purchased' => $s['kpis']['stock_purchased'],
            'adjustments' => $s['kpis']['adjustments'], 'addons_sold' => $s['kpis']['addons_sold'],
            'money_in' => $p['money_in'], 'staff' => $p['money_out']['staff'], 'bills' => $p['money_out']['bills'],
            'supplies_cost' => $p['money_out']['supplies'], 'other' => $p['money_out']['other'],
            'money_out' => $p['money_out']['total'], 'profit_total' => $p['profit'],
            'open_days' => $o['period']['open_days'],
        ];
    }

    /** Scalar money values may be plain numbers or {amount: ..} blocks. */
    private function assertTotalsMatch(array $expected, array $actual, string $label): void
    {
        foreach (self::KEYS as $key) {
            $e = is_array($expected[$key]) ? $expected[$key]['amount'] : $expected[$key];
            $a = is_array($actual[$key]) ? $actual[$key]['amount'] : $actual[$key];
            $key === 'orders'
                ? $this->assertSame((int) $e, (int) $a, "{$label}: {$key}")
                : $this->assertEqualsWithDelta($e, $a, 0.5, "{$label}: {$key}");
        }
    }

    private function sum(array ...$parts): array
    {
        $total = array_fill_keys(self::KEYS, 0);
        foreach ($parts as $part) {
            foreach (self::KEYS as $key) {
                $total[$key] += is_array($part[$key]) ? $part[$key]['amount'] : $part[$key];
            }
        }

        return $total;
    }

    public function test_thirty_one_day_views_add_up_to_the_month_view(): void
    {
        $month = $this->numbers('2026-09-01', '2026-09-30');
        $days = [];
        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-09-%02d', $d);
            $days[] = $this->numbers($date, $date);
        }

        // The month is not empty, so a pass cannot come from all zeros.
        foreach (['sales', 'supplies_used', 'staff', 'bills', 'other'] as $key) {
            $value = is_array($month[$key]) ? $month[$key]['amount'] : $month[$key];
            $this->assertGreaterThan(0, $value, "month {$key} is non-zero");
        }
        $this->assertSame(29, $month['open_days']);

        $this->assertTotalsMatch($month, $this->sum(...$days), 'month vs 30 days');
    }

    public function test_all_branches_equals_sum_of_branches_for_every_total(): void
    {
        $all = $this->numbers('2026-09-01', '2026-09-30');
        $sum = $this->sum($this->numbers('2026-09-01', '2026-09-30', (string) $this->a->id), $this->numbers('2026-09-01', '2026-09-30', (string) $this->b->id));

        $this->assertTotalsMatch($all, $sum, 'all vs A+B');
    }

    public function test_two_halves_add_up_to_the_month(): void
    {
        $month = $this->numbers('2026-09-01', '2026-09-30');
        $halves = $this->sum($this->numbers('2026-09-01', '2026-09-15'), $this->numbers('2026-09-16', '2026-09-30'));

        $this->assertTotalsMatch($month, $halves, 'month vs halves');
    }
}
