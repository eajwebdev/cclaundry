<?php

namespace App\Support\OwnerDashboard;

use App\Models\BranchExpense;
use App\Models\InventoryMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Page 2 of the owner dashboard: supplies used, bought and adjusted, valued at cost.
 * Movement classes follow ruling R1; sums run at full precision and are rounded once, at output.
 * The use class is summed in SQL (it is most of the rows); purchases and adjustments are read row by row.
 */
final class SuppliesStats
{
    public static function for(Period $period): array
    {
        $moves = self::used($period)->concat(self::listed($period));
        $orders = OverviewStats::orders($period);
        $bought = self::bought($period);

        return [
            'period' => $period->toArray(),
            'kpis' => self::kpis($period, $moves, $orders, $bought),
            ...self::usage($period, $moves),
            ...self::items($period, $moves, $orders, $bought),
            'purchases' => $bought,
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
        $used = self::used($period);

        return [
            'cost' => (float) $used->sum('cost'),
            'items' => $used->groupBy('name')->map(fn ($g) => (float) $g->sum('cost'))
                ->filter(fn ($cost) => $cost > 0)
                ->sortKeys()->sortDesc() // largest first, ties by name
                ->keys()->take(5)->map(fn ($name) => (string) $name)->values()->all(),
        ];
    }

    /** Movements in range, joined to their item `i`, scoped to the branch of the item. */
    private static function inRange(Period $period): Builder
    {
        return DB::table('inventory_movements')
            ->join('inventories as i', 'i.id', '=', 'inventory_movements.inventory_id')
            ->whereBetween('inventory_movements.created_at', [$period->from.' 00:00:00', $period->to.' 23:59:59'])
            ->when($period->branchId, fn ($q, $id) => $q->where('i.branch_id', $id));
    }

    /**
     * The "use" class (R1) summed in SQL per local date and item: out rows count +, order_edit restores −,
     * each by |change| at the movement's cost (the item's cost when none was stored). DATE() of a stored
     * local datetime gives the same 'Y-m-d' on SQLite and MySQL. Unrounded.
     */
    private static function used(Period $period): Collection
    {
        $sign = "CASE WHEN inventory_movements.movement_type = 'out' THEN 1 ELSE -1 END * ABS(COALESCE(inventory_movements.change_qty, inventory_movements.quantity))";
        $day = 'DATE(inventory_movements.created_at)';

        return self::inRange($period)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('inventory_movements.movement_type', 'out')
                    ->where(fn ($q) => $q->whereNull('inventory_movements.reason')->orWhere('inventory_movements.reason', '!=', 'opening_stock')))
                ->orWhere(fn ($q) => $q->where('inventory_movements.movement_type', 'in')->where('inventory_movements.reason', 'order_edit')))
            // Grouped by item id, as rows were before: two items that share a name and unit are merged in PHP, not by collation.
            ->groupByRaw("{$day}, inventory_movements.inventory_id, i.name, i.unit")
            ->selectRaw("{$day} as day, i.name, i.unit, SUM({$sign}) as qty, SUM({$sign} * COALESCE(inventory_movements.unit_cost, i.unit_cost)) as cost")
            ->get()
            ->map(fn ($r) => (object) [
                'class' => 'use', 'date' => (string) $r->day, 'name' => $r->name, 'unit' => $r->unit,
                'qty' => (float) $r->qty, 'cost' => (float) $r->cost,
            ]);
    }

    /**
     * Purchases and adjustments (R1), one row each since the page lists them, newest first, with signed `qty`
     * and `cost` (purchase +; adjust signed like change_qty). Opening stock and the use class are left out.
     */
    private static function listed(Period $period): Collection
    {
        return self::inRange($period)
            ->leftJoin('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where(fn ($q) => $q->whereNull('inventory_movements.reason')->orWhere('inventory_movements.reason', '!=', 'opening_stock'))
            ->where(fn ($q) => $q->where('inventory_movements.movement_type', 'adjustment')
                ->orWhere(fn ($q) => $q->where('inventory_movements.movement_type', 'in')
                    ->where(fn ($q) => $q->whereNull('inventory_movements.reason')->orWhereIn('inventory_movements.reason', InventoryMovement::REASONS))))
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
                [$class, $sign] = $m->type === 'in' && $m->reason === null ? ['purchase', 1] : ['adjust', $change < 0 ? -1 : 1];
                $qty = $sign * abs($change);

                return (object) [
                    'class' => $class, 'date' => substr((string) $m->created_at, 0, 10), 'name' => $m->name, 'unit' => $m->unit,
                    'qty' => $qty, 'cost' => $qty * (float) $m->unit_value, 'reason' => $m->reason ?? 'other',
                    'source' => $m->supplier ?? $m->remarks, 'note' => $m->remarks,
                ];
            });
    }

    /**
     * Stock and supplies bought = the supplies / inventory_purchase expenses dated in range, newest first.
     * The shop records purchases in Expenses; inventory stock-in rows carry no money and are not counted here.
     *
     * @return list<array{date: string, item: string, qty: null, unit: null, source: string, cost: float}>
     */
    private static function bought(Period $period): array
    {
        $types = ['supplies', 'inventory_purchase'];

        return BranchExpense::query()->counted()
            ->whereNull('bill_type')
            ->whereIn('expense_type', [...$types, 'operating', 'regular'])
            ->whereDate('expense_date', '>=', $period->from)
            ->whereDate('expense_date', '<=', $period->to)
            ->when($period->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->get(['expense_date', 'title', 'expense_type', 'category', 'amount'])
            // Legacy rows ('operating', 'regular') keep their real type in category.
            ->filter(fn ($e) => in_array($e->expense_type, $types, true) || in_array(str_replace(' ', '_', strtolower(trim((string) $e->category))), $types, true))
            ->map(fn ($e) => [
                'date' => $e->expense_date->toDateString(), 'item' => trim((string) $e->title) ?: 'Purchase', 'qty' => null, 'unit' => null,
                'source' => $e->expense_type === 'supplies' ? 'Supplies' : 'Stock purchase', 'cost' => round((float) $e->amount, 2),
            ])->values()->all();
    }

    private static function kpis(Period $period, Collection $moves, int $orders, array $bought): array
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
            'stock_purchased' => round(array_sum(array_column($bought, 'cost')), 2),
            'purchases_count' => count($bought),
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
    private static function items(Period $period, Collection $moves, int $orders, array $bought): array
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
                'purchased_cost' => round(array_sum(array_column($bought, 'cost')), 2),
                'adjusted_cost' => round($sum($moves, 'adjust', 'cost'), 2),
                'cost_used' => round($used, 2),
            ],
        ];
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
