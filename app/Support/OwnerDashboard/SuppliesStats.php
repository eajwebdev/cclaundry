<?php

namespace App\Support\OwnerDashboard;

use App\Models\InventoryMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Page 2 of the owner dashboard: supplies used, bought and adjusted, valued at cost.
 * Movement classes follow ruling R1; sums run at full precision and are rounded once, at output.
 */
final class SuppliesStats
{
    public static function for(Period $period): array
    {
        $moves = self::movements($period);
        $orders = OverviewStats::orders($period);

        return [
            'period' => $period->toArray(),
            'kpis' => self::kpis($period, $moves, $orders),
            ...self::usage($period, $moves),
            ...self::items($period, $moves, $orders),
            'purchases' => self::purchases($moves),
            'adjustments' => self::adjustments($moves),
            'addons' => self::addons($period),
        ];
    }

    /** Cost of the "use" class only (R2): the page-2 card and the page-3 Supplies cost line. Unrounded. */
    public static function usedCost(Period $period): float
    {
        return self::usedSummary($period)['cost'];
    }

    /**
     * The used cost (unrounded) and the names of the items it was spent on, largest first, at most five,
     * from one read of the movements.
     *
     * @return array{cost: float, items: list<string>}
     */
    public static function usedSummary(Period $period): array
    {
        $used = self::movements($period)->where('class', 'use');

        return [
            'cost' => (float) $used->sum('cost'),
            'items' => $used->groupBy('name')->map(fn ($g) => (float) $g->sum('cost'))
                ->filter(fn ($cost) => $cost > 0)
                ->sortKeys()->sortDesc() // largest first, ties by name
                ->keys()->take(5)->map(fn ($name) => (string) $name)->values()->all(),
        ];
    }

    /**
     * Every classified movement in range, newest first, with signed `qty` and `cost`
     * (use: out +, order_edit restore −; purchase +; adjust signed like change_qty). Unclassified rows are dropped.
     */
    private static function movements(Period $period): Collection
    {
        return DB::table('inventory_movements')
            ->join('inventories as i', 'i.id', '=', 'inventory_movements.inventory_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->whereBetween('inventory_movements.created_at', [$period->from.' 00:00:00', $period->to.' 23:59:59'])
            ->when($period->branchId, fn ($q, $id) => $q->where('i.branch_id', $id))
            ->orderByDesc('inventory_movements.created_at')->orderByDesc('inventory_movements.id')
            ->get([
                'inventory_movements.movement_type as type', 'inventory_movements.quantity', 'inventory_movements.change_qty',
                'inventory_movements.reason', 'inventory_movements.remarks', 'inventory_movements.created_at',
                'i.name', 'i.unit', 's.name as supplier',
                DB::raw('COALESCE(inventory_movements.unit_cost, i.unit_cost) as unit_value'),
            ])
            ->map(function ($m) {
                // Old adjustment rows have no usable change; quantity there is a stock level, not a delta.
                $change = (float) ($m->change_qty ?? ($m->type === 'adjustment' ? 0 : $m->quantity));
                [$class, $sign] = match (true) {
                    $m->type === 'out' => ['use', 1],
                    $m->type === 'in' && $m->reason === 'order_edit' => ['use', -1],
                    $m->type === 'in' && $m->reason === null => ['purchase', 1],
                    $m->type === 'adjustment', $m->type === 'in' && in_array($m->reason, InventoryMovement::REASONS, true) => ['adjust', $change < 0 ? -1 : 1],
                    default => [null, 0],
                };
                $qty = $sign * abs($change);

                return (object) [
                    'class' => $class, 'date' => substr((string) $m->created_at, 0, 10), 'name' => $m->name, 'unit' => $m->unit,
                    'qty' => $qty, 'cost' => $qty * (float) $m->unit_value, 'reason' => $m->reason ?? 'other',
                    'source' => $m->supplier ?? $m->remarks, 'note' => $m->remarks,
                ];
            })
            ->whereNotNull('class')
            ->values();
    }

    private static function kpis(Period $period, Collection $moves, int $orders): array
    {
        $used = (float) $moves->where('class', 'use')->sum('cost');
        $kg = OverviewStats::kg($period);
        $sales = OverviewStats::sales($period);
        $addons = OverviewStats::addonLines($period)
            ->selectRaw('SUM(job_order_items.total) as revenue, SUM(job_order_items.quantity) as count')->first();
        $addonsSold = (float) $addons->revenue;

        return [
            'supplies_used' => round($used, 2),
            'per_order' => round($orders ? $used / $orders : 0.0, 2),
            'per_kg' => round($kg > 0 ? $used / $kg : 0.0, 2),
            'stock_purchased' => round((float) $moves->where('class', 'purchase')->sum('cost'), 2),
            'purchases_count' => $moves->where('class', 'purchase')->count(),
            'adjustments' => round((float) $moves->where('class', 'adjust')->sum('cost'), 2),
            'adjustments_count' => $moves->where('class', 'adjust')->count(),
            'addons_sold' => round($addonsSold, 2),
            'addons_count' => round((float) $addons->count, 2),
            'addons_pct' => $sales > 0 ? (int) round($addonsSold / $sales * 100) : 0,
        ];
    }

    private static function usage(Period $period, Collection $moves): array
    {
        $byDate = $moves->where('class', 'use')->groupBy('date')->map(fn ($g) => (float) $g->sum('cost'));
        $buckets = array_map(fn ($b) => $b + [
            'cost' => round((float) $byDate->filter(fn ($c, $date) => $date >= $b['start'] && $date <= $b['end'])->sum(), 2),
            'is_saturday' => $period->bucket === 'day' && CarbonImmutable::parse($b['start'])->isSaturday(),
        ], $period->buckets());

        return [
            'usage_buckets' => $buckets,
            'usage_average' => round($buckets ? (float) $byDate->sum() / count($buckets) : 0.0, 2),
        ];
    }

    /** One row per (name, unit), so the same item on several branches is one row under "all". */
    private static function items(Period $period, Collection $moves, int $orders): array
    {
        $sum = fn (Collection $g, string $class, string $field) => (float) $g->where('class', $class)->sum($field);
        $rows = $moves->groupBy(fn ($m) => $m->name."\0".$m->unit)->map(function (Collection $g) use ($sum, $period, $orders) {
            $used = $sum($g, 'use', 'qty');

            return [
                'name' => $g->first()->name,
                'unit' => $g->first()->unit,
                'used' => round($used, 2),
                'avg_per_day' => round($period->perOpenDay($used), 2),
                'per_order' => round($orders ? $used / $orders : 0.0, 2),
                'purchased' => round($sum($g, 'purchase', 'qty'), 2),
                'adjusted' => round($sum($g, 'adjust', 'qty'), 2),
                'cost_used' => $sum($g, 'use', 'cost'), // rounded below, after sorting at full precision
            ];
        })->sort(fn ($x, $y) => [$y['cost_used'], $x['name']] <=> [$x['cost_used'], $y['name']])->values();
        $used = $sum($moves, 'use', 'cost');

        return [
            'items' => $rows->map(fn ($r) => array_replace($r, ['cost_used' => round($r['cost_used'], 2)]))->all(),
            'items_total' => [
                'per_order_cost' => round($orders ? $used / $orders : 0.0, 2),
                'purchased_cost' => round($sum($moves, 'purchase', 'cost'), 2),
                'adjusted_cost' => round($sum($moves, 'adjust', 'cost'), 2),
                'cost_used' => round($used, 2),
            ],
        ];
    }

    private static function purchases(Collection $moves): array
    {
        return $moves->where('class', 'purchase')->map(fn ($m) => [
            'date' => $m->date, 'item' => $m->name, 'qty' => round($m->qty, 2), 'unit' => $m->unit,
            'source' => $m->source, 'cost' => round($m->cost, 2),
        ])->values()->all();
    }

    private static function adjustments(Collection $moves): array
    {
        return $moves->where('class', 'adjust')->map(fn ($m) => [
            'date' => $m->date, 'reason' => $m->reason, 'item' => $m->name, 'qty' => round($m->qty, 2), 'unit' => $m->unit,
            'note' => $m->note, 'cost' => round($m->cost, 2),
        ])->values()->all();
    }

    private static function addons(Period $period): array
    {
        $lines = OverviewStats::addonLines($period)
            ->groupBy('c.name', 's.name')
            ->selectRaw('c.name as grp, s.name as name, SUM(job_order_items.quantity) as count, SUM(job_order_items.total) as revenue')
            ->get()
            ->map(fn ($r) => (object) ['group' => $r->grp, 'name' => $r->name, 'count' => (float) $r->count, 'revenue' => (float) $r->revenue]);

        $groups = $lines->groupBy('group')
            ->sortByDesc(fn ($g) => $g->sum('revenue'))
            ->map(fn ($g, $group) => [
                'group' => $group,
                'rows' => $g->sort(fn ($x, $y) => [$y->revenue, $x->name] <=> [$x->revenue, $y->name])->map(fn ($r) => [
                    'name' => $r->name,
                    'price' => round($r->count > 0 ? $r->revenue / $r->count : 0.0, 2),
                    'count' => round($r->count, 2),
                    'revenue' => round($r->revenue, 2),
                ])->values()->all(),
            ])->values()->all();

        return [
            'groups' => $groups,
            'most_popular' => $lines->sort(fn ($x, $y) => [$y->count, $y->revenue, $x->name] <=> [$x->count, $x->revenue, $y->name])->first()?->name,
            'top_earner' => $lines->sort(fn ($x, $y) => [$y->revenue, $x->name] <=> [$x->revenue, $y->name])->first()?->name,
        ];
    }
}
