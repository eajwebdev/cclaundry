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

    /** @return array<string, float> Sales per local order date ('Y-m-d'), only days with sales. Grouped in PHP. */
    public static function salesByDay(Period $period): array
    {
        return self::lines($period)
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->get(['job_orders.created_at', 'job_order_items.total'])
            ->groupBy(fn ($l) => substr((string) $l->created_at, 0, 10))
            ->map(fn ($lines) => (float) $lines->sum('total'))
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

    /** Grouped in PHP, not SQL: tests run on SQLite, production on MySQL. */
    private static function busiest(Period $period): array
    {
        $orders = self::orderQuery($period)->get(['id', 'created_at', 'kg_weighed']);

        if ($period->from === $period->to) {
            [$open, $close] = self::openingHours(CarbonImmutable::parse($period->from)->dayOfWeek);
            $byHour = $orders->countBy(fn ($o) => $o->created_at->hour);
            $hours = collect(range($open, $close - 1))->merge($byHour->keys())->unique()->sort()->values();

            return ['mode' => 'hourly', 'rows' => $hours->map(fn ($h) => ['hour' => $h, 'label' => self::hourLabel($h), 'orders' => $byHour[$h] ?? 0])->all()];
        }

        $inRange = [];
        for ($d = CarbonImmutable::parse($period->from), $i = 0; $i < 7 && $d->toDateString() <= $period->to; $d = $d->addDay(), $i++) {
            $inRange[$d->dayOfWeek] = true;
        }
        $openDays = collect($period->openDates())->countBy(fn ($date) => CarbonImmutable::parse($date)->dayOfWeek);
        $byDay = $orders->groupBy(fn ($o) => $o->created_at->dayOfWeek);

        $rows = [];
        foreach (self::DAYS as $weekday => $day) {
            $dayOrders = $byDay->get($weekday, collect());
            $divisor = $openDays[$weekday] ?? 0;
            $avg = fn (float $x) => $divisor ? round($x / $divisor, 1) : 0.0;
            $beforeNoon = $dayOrders->filter(fn ($o) => $o->created_at->hour < 12)->count();
            $before = $avg($beforeNoon);
            $after = $avg($dayOrders->count() - $beforeNoon);
            $rows[] = [
                'day' => $day,
                'in_range' => isset($inRange[$weekday]),
                'before_noon' => $before,
                'after_noon' => $after,
                'total' => round($before + $after, 1), // the sum of the two shown figures, so the page adds up
                'kg' => $avg($dayOrders->sum(fn ($o) => (float) $o->kg_weighed)),
                'peak' => self::peak($weekday, $dayOrders),
            ];
        }

        return ['mode' => 'weekday', 'rows' => $rows];
    }

    /** Label of the busiest 2-hour window inside opening hours, earliest on ties; null when no order falls in one. */
    private static function peak(int $weekday, Collection $orders): ?string
    {
        [$open, $close] = self::openingHours($weekday);
        $byHour = $orders->countBy(fn ($o) => $o->created_at->hour);
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
            ->selectRaw('COALESCE(s.name, job_order_items.description) as name, MAX(s.pricing_type) as pricing_type, SUM(job_order_items.quantity) as qty, SUM(job_order_items.total) as amount')
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
            ])->all(),
            'top' => [
                'name' => $top?->name,
                'pct' => $top && $all > 0 ? (int) round($top->amount / $all * 100) : 0,
            ],
        ];
    }
}
