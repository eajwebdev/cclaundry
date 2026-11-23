<?php

namespace App\Support\OwnerDashboard;

use App\Models\AttendanceEmployee;
use App\Models\BranchExpense;
use App\Models\EmployeeAttendanceRecord;
use App\Models\ScheduleShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Page 3 of the owner dashboard: money in − money out = profit. Each cost counts for the days it belongs to:
 * staff by work_date, bills by their period, supplies by use (R2), other purchases by expense_date.
 * Sums run at full precision and are rounded once, at output.
 */
final class ProfitStats
{
    /** Shift templates that are not a working day ('unscheduled' is what the planner's Clear button saves). */
    private const OFF_TEMPLATES = ['rest', 'leave', 'unscheduled'];

    /** Expense types costed elsewhere: payroll via attendance, supplies via stock use. */
    private const NOT_OTHER = ['payroll', 'supplies', 'inventory_purchase'];

    /** Types of rows saved before the 2026-06-14 remap, whose real type is only in `category`. */
    private const LEGACY_TYPES = ['operating', 'regular'];

    public static function for(Period $period): array
    {
        $staff = self::staff($period);
        $bills = self::bills($period);
        $other = self::other($period);
        $supplies = SuppliesStats::usedSummary($period);
        $summary = self::summarise($period, $staff, $bills, $other, $supplies['cost']);
        $orders = OverviewStats::orders($period);
        $pct = self::shares($summary);

        return [
            'period' => $period->toArray(),
            'money_in' => round($summary['sales'], 2),
            'orders' => $orders,
            'money_out' => self::moneyOut($summary, $pct),
            'profit' => round($summary['profit'], 2),
            'profit_pct' => $summary['profit_pct'],
            'profit_share' => $pct['profit'],
            'per_order' => self::perOrder($summary, $orders),
            'break_even' => self::breakEven($period, $summary),
            'staff' => self::staffBlock($staff, $orders, OverviewStats::kg($period)),
            'bills' => self::billsBlock($bills, $summary['sales']),
            'other_purchases' => self::otherBlock($other, $summary['sales']),
            'supplies_items' => $supplies['items'],
        ];
    }

    /**
     * @return array{sales: float, costs: array{staff: float, bills: float, supplies: float, other: float},
     *               total_costs: float, profit: float, profit_pct: float} full precision except profit_pct (1 decimal)
     */
    public static function summary(Period $period): array
    {
        return self::summarise($period, self::staff($period), self::bills($period), self::other($period), SuppliesStats::usedCost($period));
    }

    private static function summarise(Period $period, array $staff, Collection $bills, Collection $other, float $suppliesUsed): array
    {
        $sales = OverviewStats::sales($period);
        $costs = [
            'staff' => $staff['total'],
            'bills' => (float) $bills->sum(),
            'supplies' => $suppliesUsed,
            'other' => (float) $other->sum('amount'),
        ];
        $total = array_sum($costs);
        $profit = $sales - $total;

        return [
            'sales' => $sales,
            'costs' => $costs,
            'total_costs' => $total,
            'profit' => $profit,
            'profit_pct' => $sales > 0 ? round($profit / $sales * 100, 1) : 0.0,
        ];
    }

    /**
     * Paid days (a clock-in) and published working shifts in range, per employee. Unrounded.
     *
     * @return array{wages: float, employer_share: float, total: float, days_present: int, days_scheduled: int, employees: Collection}
     */
    private static function staff(Period $period): array
    {
        $paid = EmployeeAttendanceRecord::query()
            ->whereDate('work_date', '>=', $period->from)
            ->whereDate('work_date', '<=', $period->to)
            ->orderBy('id')
            ->get(['id', 'attendance_employee_id', 'branch_id', 'work_date', 'clock_in'])
            ->filter(fn ($r) => ! empty($r->clock_in))
            // Clocked in at two branches on one day: the day belongs to the lowest-id record, in every view.
            ->unique(fn ($r) => $r->attendance_employee_id.'|'.$r->work_date->toDateString())
            ->when($period->branchId, fn ($records, $id) => $records->where('branch_id', $id))
            ->countBy('attendance_employee_id');

        $scheduled = ScheduleShift::query()
            ->whereDate('work_date', '>=', $period->from)
            ->whereDate('work_date', '<=', $period->to)
            ->whereNotNull('published_at')
            ->whereNotIn('template', self::OFF_TEMPLATES)
            ->when($period->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->toBase()
            ->groupBy('attendance_employee_id')
            ->selectRaw('attendance_employee_id, COUNT(*) as n')
            ->pluck('n', 'attendance_employee_id');

        $employees = AttendanceEmployee::withTrashed()
            ->whereIn('id', $paid->keys()->merge($scheduled->keys())->unique()->all())
            ->get()
            ->map(function (AttendanceEmployee $e) use ($paid, $scheduled) {
                $days = (int) ($paid[$e->id] ?? 0);
                $rate = $e->getAttribute('daily_rate');
                $wages = $days * (float) $rate;
                $share = $wages * (float) $e->getAttribute('employer_share_rate') / 100;

                return [
                    'name' => $e->name, 'role' => $e->schedule_role, 'daily_rate' => $rate === null ? null : (float) $rate,
                    'days_worked' => $days, 'days_scheduled' => (int) ($scheduled[$e->id] ?? 0),
                    'wages' => $wages, 'employer_share' => $share, 'total' => $wages + $share,
                ];
            })
            ->sort(fn ($x, $y) => [$y['total'], $x['name']] <=> [$x['total'], $y['name']])
            ->values();

        return [
            'wages' => (float) $employees->sum('wages'),
            'employer_share' => (float) $employees->sum('employer_share'),
            'total' => (float) $employees->sum('total'),
            'days_present' => (int) $employees->sum('days_worked'),
            'days_scheduled' => (int) $employees->sum('days_scheduled'),
            'employees' => $employees,
        ];
    }

    /** @return Collection<string, float> bill_type => share of the bills' periods that falls in range, unrounded */
    private static function bills(Period $period): Collection
    {
        $days = fn (string $a, string $b) => (int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b)) + 1;

        return BranchExpense::query()->counted()
            ->whereNotNull('bill_type')
            ->whereDate('period_start', '<=', $period->to)
            ->whereDate('period_end', '>=', $period->from)
            ->when($period->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->get(['bill_type', 'amount', 'period_start', 'period_end'])
            ->map(fn ($b) => [$b->bill_type, $b->period_start->toDateString(), $b->period_end->toDateString(), (float) $b->amount])
            ->filter(fn ($b) => $b[1] <= $b[2]) // an inverted period cannot be spread
            ->groupBy(0)
            ->map(fn ($bills) => (float) $bills->sum(fn ($b) => $b[3] / $days($b[1], $b[2]) * $days(max($b[1], $period->from), min($b[2], $period->to))));
    }

    /** @return Collection<int, array{category: string, amount: float}> one row per title (case-insensitive), largest first, unrounded */
    private static function other(Period $period): Collection
    {
        $label = fn ($e) => trim((string) $e->title) ?: (ucfirst(str_replace('_', ' ', (string) $e->expense_type)) ?: 'Other');
        $legacyNotOther = fn ($e) => in_array($e->expense_type, self::LEGACY_TYPES, true)
            && in_array(str_replace(' ', '_', strtolower(trim((string) $e->category))), self::NOT_OTHER, true);

        return BranchExpense::query()->counted()
            ->whereNull('bill_type')
            ->where(fn ($q) => $q->whereNull('expense_type')->orWhereNotIn('expense_type', self::NOT_OTHER))
            ->whereDate('expense_date', '>=', $period->from)
            ->whereDate('expense_date', '<=', $period->to)
            ->when($period->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('expense_date')->orderBy('id')
            ->get(['title', 'expense_type', 'category', 'amount'])
            ->reject($legacyNotOther)
            ->groupBy(fn ($e) => mb_strtolower($label($e)))
            ->map(fn ($group) => ['category' => $label($group->first()), 'amount' => (float) $group->sum('amount')])
            ->sort(fn ($x, $y) => [$y['amount'], $x['category']] <=> [$x['amount'], $y['category']])
            ->values();
    }

    /**
     * Whole-number shares of Sales for each cost line and profit. With a profit, one Shares call so the chart adds to 100;
     * with a loss (or no sales) each is its own amount ÷ Sales.
     *
     * @return array{staff: int, bills: int, supplies: int, other: int, profit: int}
     */
    private static function shares(array $summary): array
    {
        $amounts = $summary['costs'] + ['profit' => $summary['profit']];

        return $summary['sales'] > 0 && $summary['profit'] >= 0
            ? Shares::percentages($amounts)
            : array_map(fn ($amount) => self::pctOf($amount, $summary['sales']), $amounts);
    }

    private static function moneyOut(array $summary, array $pct): array
    {
        $out = [];
        foreach ($summary['costs'] as $key => $amount) {
            $out[$key] = ['amount' => round($amount, 2), 'pct' => $pct[$key]];
        }
        $out['total'] = [
            'amount' => round($summary['total_costs'], 2),
            'pct' => $summary['sales'] > 0 && $summary['profit'] >= 0 ? 100 - $pct['profit'] : self::pctOf($summary['total_costs'], $summary['sales']),
        ];

        return $out;
    }

    private static function perOrder(array $summary, int $orders): array
    {
        $each = fn (float $amount) => round($orders ? $amount / $orders : 0.0, 2);

        return ['sales' => $each($summary['sales'])]
            + array_map($each, $summary['costs'])
            + ['profit' => $each($summary['profit'])];
    }

    /** covered_on per ruling R5: first day whose cumulative Sales reach the range's total costs. */
    private static function breakEven(Period $period, array $summary): array
    {
        $perDay = $period->perOpenDay($summary['total_costs']);
        $actual = $period->perOpenDay($summary['sales']);
        $covered = null;

        if ($summary['sales'] > 0 || $summary['total_costs'] > 0) {
            $byDay = OverviewStats::salesByDay($period);
            $target = round($summary['total_costs'], 2);
            $cumulative = 0.0;
            $day = CarbonImmutable::parse($period->from);
            for ($n = 1; $n <= $period->calendarDays; $n++, $day = $day->addDay()) {
                $cumulative += $byDay[$day->toDateString()] ?? 0.0;
                if (round($cumulative, 2) >= $target) {
                    $covered = ['day' => $n, 'of' => $period->calendarDays];
                    break;
                }
            }
        }

        return [
            'per_day' => round($perDay, 2),
            'actual_per_day' => round($actual, 2),
            'difference' => round($actual - $perDay, 2),
            'covered_on' => $covered,
        ];
    }

    private static function staffBlock(array $staff, int $orders, float $kg): array
    {
        return [
            'total' => round($staff['total'], 2),
            'wages' => round($staff['wages'], 2),
            'employer_share' => round($staff['employer_share'], 2),
            'attendance_pct' => $staff['days_scheduled'] ? min(100, (int) round($staff['days_present'] / $staff['days_scheduled'] * 100)) : 0,
            'days_present' => $staff['days_present'],
            'days_scheduled' => $staff['days_scheduled'],
            'per_order' => round($orders ? $staff['total'] / $orders : 0.0, 2),
            'per_kg' => round($kg > 0 ? $staff['total'] / $kg : 0.0, 2),
            'employees' => $staff['employees']->map(fn ($e) => array_merge($e, [
                'daily_rate' => $e['daily_rate'] === null ? null : round($e['daily_rate'], 2),
                'wages' => round($e['wages'], 2),
                'employer_share' => round($e['employer_share'], 2),
                'total' => round($e['total'], 2),
            ]))->all(),
            'employees_count' => $staff['employees']->count(),
            'kg' => round($kg, 2),
        ];
    }

    private static function billsBlock(Collection $bills, float $sales): array
    {
        $bills = $bills->sortDesc();
        $pct = Shares::percentages($bills->all());

        return [
            'total' => round((float) $bills->sum(), 2),
            'pct_of_sales' => self::pctOf((float) $bills->sum(), $sales),
            'rows' => $bills->map(fn ($amount, $type) => [
                'type' => $type, 'label' => BranchExpense::BILL_TYPES[$type] ?? $type, 'amount' => round($amount, 2), 'pct' => $pct[$type],
            ])->values()->all(),
        ];
    }

    private static function otherBlock(Collection $other, float $sales): array
    {
        $total = (float) $other->sum('amount');

        return [
            'total' => round($total, 2),
            'pct_of_sales' => self::pctOf($total, $sales),
            'rows' => $other->map(fn ($r) => ['category' => $r['category'], 'amount' => round($r['amount'], 2)])->all(),
        ];
    }

    private static function pctOf(float $part, float $sales): int
    {
        return $sales > 0 ? (int) round($part / $sales * 100) : 0;
    }
}
