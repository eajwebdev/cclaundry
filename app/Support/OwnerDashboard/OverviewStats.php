<?php

namespace App\Support\OwnerDashboard;

use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Payment;
use App\Models\ZReading;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Page 1 of the owner dashboard. Sums run at full precision and are rounded once, at output. */
final class OverviewStats
{
    private const METHODS = ['cash', 'gcash', 'bank'];

    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public static function for(Period $period): array
    {
        return [
            'period' => $period->toArray(),
            'kpis' => self::kpis($period),
            'payments' => self::payments($period),
            'unpaid' => self::unpaid($period),
            'intake' => self::intake($period),
            'closings' => self::closings($period),
            'busiest' => self::busiest($period),
            'services' => self::services($period),
        ];
    }

    public static function sales(Period $period): float
    {
        return (float) JobOrderItem::query()->whereIn('job_order_id', self::orderQuery($period)->select('id'))->sum('total');
    }

    /**
     * @return array<string, float> Sales per local order date ('Y-m-d'), only days with sales.
     * DATE() of a stored local datetime gives the same 'Y-m-d' on SQLite and MySQL.
     */
    public static function salesByDay(Period $period): array
    {
        return self::lines($period)
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->groupByRaw('DATE(job_orders.created_at)')
            ->selectRaw('DATE(job_orders.created_at) as day, SUM(job_order_items.total) as total')
            ->pluck('total', 'day')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    public static function orders(Period $period): int
    {
        return self::orderQuery($period)->count();
    }

    public static function kg(Period $period): float
    {
        return (float) self::orderQuery($period)->sum('kg_weighed');
    }

    /** Non-cancelled, non-deleted orders created in the range (SoftDeletes drops deleted ones). */
    private static function orderQuery(Period $period): Builder
    {
        return JobOrder::query()
            ->where('job_orders.status', '!=', 'cancelled')
            ->whereBetween('job_orders.created_at', [$period->from.' 00:00:00', $period->to.' 23:59:59'])
            ->when($period->branchId, fn ($q, $id) => $q->where('job_orders.branch_id', $id));
    }

    private static function kpis(Period $period): array
    {
        $sales = self::sales($period);
        $orders = self::orders($period);
        $kg = self::kg($period);
        $profit = ProfitStats::summary($period);

        return [
            'sales' => round($sales, 2),
            'sales_per_day' => round($period->perOpenDay($sales), 2),
            'orders' => $orders,
            'avg_order' => round($orders ? $sales / $orders : 0.0, 2),
            'orders_per_day' => round($period->perOpenDay($orders), 2),
            'kg_washed' => round($kg, 2),
            'kg_per_order' => round($orders ? $kg / $orders : 0.0, 2),
            'kg_per_day' => round($period->perOpenDay($kg), 2),
            'profit' => round($profit['profit'], 2),
            'profit_pct' => $profit['profit_pct'],
        ];
    }

    private static function payments(Period $period): array
    {
        // Payment's global scope already drops voided rows.
        $sums = Payment::query()->toBase()
            ->whereIn('payment_type', self::METHODS)
            ->whereBetween('paid_at', [$period->from.' 00:00:00', $period->to.' 23:59:59'])
            ->when($period->branchId, fn ($q, $id) => $q->where('collected_branch_id', $id))
            ->groupBy('payment_type')
            ->selectRaw('payment_type, SUM(amount) as amount')
            ->pluck('amount', 'payment_type');
        $amounts = array_map(fn ($m) => (float) ($sums[$m] ?? 0), array_combine(self::METHODS, self::METHODS));
        $pct = Shares::percentages($amounts);

        return [
            'total' => round(array_sum($amounts), 2),
            'methods' => array_map(fn ($m) => ['method' => $m, 'amount' => round($amounts[$m], 2), 'pct' => $pct[$m]], self::METHODS),
        ];
    }

    private static function unpaid(Period $period): float
    {
        return round((float) self::orderQuery($period)->sum(DB::raw('CASE WHEN total > paid_amount THEN total - paid_amount ELSE 0 END')), 2);
    }

    private static function intake(Period $period): array
    {
        $tiles = function (string $column, array $keys) use ($period): array {
            $found = self::orderQuery($period)->toBase()->groupBy($column)->selectRaw("{$column} as k, COUNT(*) as n")->pluck('n', 'k');
            $counts = array_map(fn ($k) => (int) ($found[$k] ?? 0), array_combine($keys, $keys));
            $pct = Shares::percentages($counts);

            return array_map(fn ($k) => ['count' => $counts[$k], 'pct' => $pct[$k]], array_combine($keys, $keys));
        };
        $intake = $tiles('intake_type', ['walk_in', 'house_pickup']);
        $return = $tiles('return_type', ['shop_pickup', 'delivery']);

        return $intake + $return + ['ebike_trips' => $intake['house_pickup']['count'] + $return['delivery']['count']];
    }

    private static function closings(Period $period): array
    {
        $closings = ZReading::query()
            // Only what ClosingView and the summary read (the large JSON step columns stay in the database).
            ->select([
                'id', 'branch_id', 'business_date', 'prepared_by', 'closed_by', 'machine_counters',
                'cash_diff', 'gcash_diff', 'deposit_diff', 'expected_cash_drawer_amount', 'actual_cash_amount',
                'expected_gcash_amount', 'actual_gcash_amount', 'deposit_bag_amount', 'float_for_tomorrow', 'gcash_transfer_amount',
            ])
            ->whereDate('business_date', '>=', $period->from)
            ->whereDate('business_date', '<=', $period->to)
            ->when($period->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->with(['branch', 'closer', 'preparer'])
            ->get();
        $views = $closings->map(fn ($z) => ClosingView::from($z))
            ->sort(fn ($x, $y) => [$y['date'], $x['branch']] <=> [$x['date'], $y['branch']])
            ->values();
        $balanced = $views->where('status', 'balanced')->count();
        $singleDay = $period->from === $period->to;

        return [
            'summary' => [
                'count' => $closings->count(),
                'balanced' => $balanced,
                'with_difference' => $closings->count() - $balanced,
                'cash_over_short' => round((float) $closings->sum('cash_diff'), 2),
                'cash_deposited' => round((float) $closings->sum('deposit_bag_amount'), 2),
                'gcash_sent_to_bank' => round((float) $closings->sum('gcash_transfer_amount'), 2),
            ],
            'unbalanced' => $singleDay ? [] : $views->where('status', 'difference')->values()->all(),
            'full' => $singleDay ? $views->all() : null,
            'all' => $views->map(fn ($v) => ClosingView::row($v))->all(),
        ];
    }

    /**
     * Counted per weekday and hour in one pass over two columns, without a model per order.
     * Grouped in PHP, not SQL: tests run on SQLite, production on MySQL.
     */
    private static function busiest(Period $period): array
    {
        $counts = array_fill(0, 7, []); // weekday => [hour => orders]
        $kg = array_fill(0, 7, 0.0);
        $weekdayOf = [];
        foreach (self::orderQuery($period)->toBase()->select(['job_orders.created_at', 'job_orders.kg_weighed'])->cursor() as $o) {
            $at = (string) $o->created_at; // local time, 'Y-m-d H:i:s'
            $weekday = $weekdayOf[substr($at, 0, 10)] ??= CarbonImmutable::parse(substr($at, 0, 10))->dayOfWeek;
            $hour = (int) substr($at, 11, 2);
            $counts[$weekday][$hour] = ($counts[$weekday][$hour] ?? 0) + 1;
            $kg[$weekday] += (float) $o->kg_weighed;
        }

        if ($period->from === $period->to) {
            [$open, $close] = self::openingHours(CarbonImmutable::parse($period->from)->dayOfWeek);
            $byHour = $counts[CarbonImmutable::parse($period->from)->dayOfWeek];
            $hours = collect(range($open, $close - 1))->merge(array_keys($byHour))->unique()->sort()->values();

            return ['mode' => 'hourly', 'rows' => $hours->map(fn ($h) => ['hour' => $h, 'label' => self::hourLabel($h), 'orders' => $byHour[$h] ?? 0])->all()];
        }

        $inRange = [];
        for ($d = CarbonImmutable::parse($period->from), $i = 0; $i < 7 && $d->toDateString() <= $period->to; $d = $d->addDay(), $i++) {
            $inRange[$d->dayOfWeek] = true;
        }
        $openDays = collect($period->openDates())->countBy(fn ($date) => CarbonImmutable::parse($date)->dayOfWeek);

        $rows = [];
        foreach (self::DAYS as $weekday => $day) {
            $byHour = $counts[$weekday];
            $divisor = $openDays[$weekday] ?? 0;
            $avg = fn (float $x) => $divisor ? round($x / $divisor, 1) : 0.0;
            $beforeNoon = array_sum(array_filter($byHour, fn ($hour) => $hour < 12, ARRAY_FILTER_USE_KEY));
            $before = $avg($beforeNoon);
            $after = $avg(array_sum($byHour) - $beforeNoon);
            $rows[] = [
                'day' => $day,
                'in_range' => isset($inRange[$weekday]),
                'before_noon' => $before,
                'after_noon' => $after,
                'total' => round($before + $after, 1), // the sum of the two shown figures, so the page adds up
                'kg' => $avg($kg[$weekday]),
                'peak' => self::peak($weekday, $byHour),
            ];
        }

        return ['mode' => 'weekday', 'rows' => $rows];
    }

    /**
     * Label of the busiest 2-hour window inside opening hours, earliest on ties; null when no order falls in one.
     *
     * @param  array<int, int>  $byHour  orders per hour of that weekday
     */
    private static function peak(int $weekday, array $byHour): ?string
    {
        [$open, $close] = self::openingHours($weekday);
        $best = null;
        $bestCount = 0;
        for ($h = $open; $h + 1 < $close; $h++) {
            $n = ($byHour[$h] ?? 0) + ($byHour[$h + 1] ?? 0);
            if ($n > $bestCount) {
                [$best, $bestCount] = [$h, $n];
            }
        }
        if ($best === null) {
            return null;
        }
        [$a, $am] = self::clock($best);
        [$b, $pm] = self::clock($best + 2);

        return $am === $pm ? "{$a}–{$b} {$pm}" : "{$a} {$am}–{$b} {$pm}";
    }

    /** @return array{0: int, 1: int} first open hour and closing hour (Carbon weekday, 0 = Sunday) */
    private static function openingHours(int $weekday): array
    {
        return $weekday === 0 ? [10, 17] : [8, 19];
    }

    private static function hourLabel(int $hour): string
    {
        return implode(' ', self::clock($hour));
    }

    /** @return array{0: int, 1: string} */
    private static function clock(int $hour): array
    {
        return [$hour % 12 ?: 12, $hour < 12 ? 'AM' : 'PM'];
    }

    /** Lines of the counted orders, joined to service `s` and category `c` (both may be null). */
    public static function lines(Period $period): QueryBuilder
    {
        return DB::table('job_order_items')
            ->leftJoin('laundry_services as s', 's.id', '=', 'job_order_items.laundry_service_id')
            ->leftJoin('laundry_service_categories as c', 'c.id', '=', 's.service_category_id')
            ->whereIn('job_order_items.job_order_id', self::orderQuery($period)->select('job_orders.id'));
    }

    /** Add-on lines: their service's category is flagged is_addon. */
    public static function addonLines(Period $period): QueryBuilder
    {
        return self::lines($period)->where('c.is_addon', true);
    }

    private static function services(Period $period): array
    {
        $addonsTotal = (float) self::addonLines($period)->sum('job_order_items.total');
        $rows = self::lines($period)
            ->where(fn ($q) => $q->whereNull('c.is_addon')->orWhere('c.is_addon', false))
            ->groupByRaw('COALESCE(s.name, job_order_items.description)')
            ->selectRaw('COALESCE(s.name, job_order_items.description) as name, MAX(s.pricing_type) as pricing_type, CASE WHEN COUNT(DISTINCT s.price) = 1 AND COUNT(s.id) = COUNT(*) THEN MAX(s.price) END as price, SUM(job_order_items.quantity) as qty, SUM(job_order_items.total) as amount')
            ->orderByDesc('amount')
            ->get();
        $servicesTotal = (float) $rows->sum('amount');
        $all = $servicesTotal + $addonsTotal;
        $top = $rows->first();

        return [
            'services_total' => round($servicesTotal, 2),
            'addons_total' => round($addonsTotal, 2),
            'rows' => $rows->map(fn ($r) => [
                'name' => $r->name,
                'qty' => round((float) $r->qty, 2),
                'unit' => match ($r->pricing_type) {
                    'kilo' => 'kg',
                    'load' => 'loads',
                    'piece', null => 'pcs',
                    default => $r->pricing_type,
                },
                'amount' => round((float) $r->amount, 2),
                // The service's list price now; null for lines without a service or names sold at several prices.
                'price' => $r->price === null ? null : round((float) $r->price, 2),
            ])->all(),
            'top' => [
                'name' => $top?->name,
                'pct' => $top && $all > 0 ? (int) round($top->amount / $all * 100) : 0,
            ],
        ];
    }
}
