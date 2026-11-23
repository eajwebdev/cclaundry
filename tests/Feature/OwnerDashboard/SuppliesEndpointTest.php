<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Supplier;
use App\Models\User;
use App\Support\OwnerDashboard\Period;
use App\Support\OwnerDashboard\SuppliesStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SuppliesEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private Branch $a;
    private User $owner;
    private Inventory $detergent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->owner = $this->admin();
        $this->a = $this->seedBranch('A');
        $regular = $this->service('Regular Laundry', 'kilo', 30);
        $ariel = $this->service('Ariel', 'piece', 20, true);

        $this->order($this->a, '2026-09-05 09:00:00', [[$regular, 10], [$ariel, 1]]);
        $this->order($this->a, '2026-09-07 10:00:00', [[$regular, 5]]);
        $this->closing($this->a, '2026-09-05');
        $this->closing($this->a, '2026-09-07');

        $this->detergent = $this->stockItem($this->a, 'Detergent', 'mL', 0.06);
        $this->movement($this->detergent, '2026-09-05 09:00:00', 'out', -600);
        $this->movement($this->detergent, '2026-09-07 10:00:00', 'out', -400);
        $this->movement($this->detergent, '2026-09-18 08:00:00', 'in', 20000, ['remarks' => 'Bought at Puregold']);
        // quantity on an adjustment is the new stock level, never the change.
        $this->movement($this->detergent, '2026-09-24 18:00:00', 'adjustment', -1000, ['quantity' => 19000, 'reason' => 'count', 'remarks' => 'Monthly count']);
        $this->movement($this->detergent, '2026-09-27 12:00:00', 'in', 500, ['reason' => 'cancelled_order', 'remarks' => 'Auto restored for JO-9']);
    }

    private function supplies(string $from = '2026-09-01', string $to = '2026-09-30', string $branch = 'all'): TestResponse
    {
        return $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.supplies', ['from' => $from, 'to' => $to, 'branch' => $branch]))
            ->assertOk();
    }

    public function test_kpi_cards(): void
    {
        $this->supplies()
            ->assertJsonPath('kpis.supplies_used', 60)
            ->assertJsonPath('kpis.per_order', 30)
            ->assertJsonPath('kpis.per_kg', 4)
            ->assertJsonPath('kpis.stock_purchased', 1200)
            ->assertJsonPath('kpis.purchases_count', 1)
            ->assertJsonPath('kpis.adjustments', -30)
            ->assertJsonPath('kpis.adjustments_count', 2)
            ->assertJsonPath('kpis.addons_sold', 20)
            ->assertJsonPath('kpis.addons_count', 1)
            ->assertJsonPath('kpis.addons_pct', 4); // 20 of 470

        $this->assertEqualsWithDelta(60, SuppliesStats::usedCost(Period::make('2026-09-01', '2026-09-30', null)), 1e-9);
    }

    public function test_item_row(): void
    {
        $this->supplies()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0', [
                'name' => 'Detergent', 'unit' => 'mL', 'used' => 1000, 'avg_per_day' => 500, 'per_order' => 500,
                'purchased' => 20000, 'adjusted' => -500, 'cost_used' => 60,
            ])
            ->assertJsonPath('items_total', ['per_order_cost' => 30, 'purchased_cost' => 1200, 'adjusted_cost' => -30, 'cost_used' => 60]);
    }

    public function test_cancelled_order_return_is_an_adjustment_not_a_purchase(): void
    {
        $this->supplies()
            ->assertJsonCount(1, 'purchases')
            ->assertJsonPath('purchases.0', ['date' => '2026-09-18', 'item' => 'Detergent', 'qty' => 20000, 'unit' => 'mL', 'source' => 'Bought at Puregold', 'cost' => 1200])
            ->assertJsonCount(2, 'adjustments')
            ->assertJsonPath('adjustments.0', ['date' => '2026-09-27', 'reason' => 'cancelled_order', 'item' => 'Detergent', 'qty' => 500, 'unit' => 'mL', 'note' => 'Auto restored for JO-9', 'cost' => 30])
            ->assertJsonPath('adjustments.1', ['date' => '2026-09-24', 'reason' => 'count', 'item' => 'Detergent', 'qty' => -1000, 'unit' => 'mL', 'note' => 'Monthly count', 'cost' => -60]);
    }

    public function test_purchase_source_prefers_the_supplier(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Unilever Depot', 'is_active' => true]);
        $this->detergent->update(['supplier_id' => $supplier->id]);

        $this->supplies()->assertJsonPath('purchases.0.source', 'Unilever Depot');
    }

    public function test_order_edit_restore_reduces_use_and_is_not_a_purchase_or_adjustment(): void
    {
        $softener = $this->stockItem($this->a, 'Softener', 'mL', 0.1);
        $this->movement($softener, '2026-10-02 09:00:00', 'out', -100);
        $this->movement($softener, '2026-10-02 09:30:00', 'in', 40, ['reason' => 'order_edit']);

        $this->supplies('2026-10-01', '2026-10-31')
            ->assertJsonPath('items.0.used', 60)
            ->assertJsonPath('kpis.supplies_used', 6)
            ->assertJsonPath('purchases', [])
            ->assertJsonPath('adjustments', []);
    }

    public function test_old_adjustment_without_reason_or_change_is_other_with_zero_cost(): void
    {
        $this->movement($this->detergent, '2026-10-03 09:00:00', 'adjustment', 0, ['quantity' => 18000, 'change_qty' => 0, 'reason' => null]);

        $this->supplies('2026-10-01', '2026-10-31')
            ->assertJsonPath('kpis.adjustments_count', 1)
            ->assertJsonPath('kpis.adjustments', 0)
            ->assertJsonPath('adjustments.0.reason', 'other')
            ->assertJsonPath('adjustments.0.qty', 0)
            ->assertJsonPath('adjustments.0.cost', 0);
    }

    public function test_usage_buckets_follow_the_bucket_rule_and_flag_saturdays(): void
    {
        $month = $this->supplies()->assertJsonCount(30, 'usage_buckets');
        $month->assertJsonPath('usage_buckets.4', ['label' => '5', 'start' => '2026-09-05', 'end' => '2026-09-05', 'cost' => 36, 'is_saturday' => true])
            ->assertJsonPath('usage_buckets.6.cost', 24)
            ->assertJsonPath('usage_buckets.6.is_saturday', false)
            ->assertJsonPath('usage_average', 2); // 60 ÷ 30

        $this->supplies('2026-01-01', '2026-09-30')
            ->assertJsonPath('period.bucket', 'month')
            ->assertJsonCount(9, 'usage_buckets')
            ->assertJsonPath('usage_buckets.8.cost', 60)
            ->assertJsonPath('usage_buckets.8.is_saturday', false);

        $weeks = $this->supplies('2026-08-01', '2026-09-30')
            ->assertJsonPath('period.bucket', 'week')
            ->assertJsonPath('usage_buckets.0.end', '2026-08-07') // a Friday
            ->json('usage_buckets');
        $this->assertSame(60, array_sum(array_column($weeks, 'cost')));
        $this->assertNotContains(true, array_column($weeks, 'is_saturday'));
    }

    public function test_movement_without_stored_cost_uses_current_item_cost(): void
    {
        $this->movement($this->detergent, '2026-10-02 09:00:00', 'out', -100, ['unit_cost' => null]);  // 100 × 0.06
        $this->movement($this->detergent, '2026-10-03 09:00:00', 'out', -100, ['unit_cost' => 0.05]);  // stored cost wins

        $this->supplies('2026-10-01', '2026-10-31')
            ->assertJsonPath('kpis.supplies_used', 11)
            ->assertJsonPath('items.0.cost_used', 11);
    }

    public function test_addons_sold_equals_the_overview_addons_total(): void
    {
        $overview = $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.overview', ['from' => '2026-09-01', 'to' => '2026-09-30', 'branch' => 'all']))
            ->json('services.addons_total');

        $this->supplies()->assertJsonPath('kpis.addons_sold', $overview);
    }

    public function test_addons_grouped_by_category_with_most_popular_and_top_earner(): void
    {
        $ariel = $this->service('Ariel Pod', 'piece', 20, true, 'Detergents');
        $tide = $this->service('Tide', 'piece', 25, true, 'Detergents');
        $downy = $this->service('Downy Big', 'piece', 100, true, 'Softeners');
        $this->order($this->a, '2026-10-05 09:00:00', [[$ariel, 3], [$tide, 1]]);
        $this->order($this->a, '2026-10-06 09:00:00', [[$downy, 1]]);

        $this->supplies('2026-10-01', '2026-10-31')
            ->assertJsonPath('addons.groups', [
                ['group' => 'Softeners', 'rows' => [['name' => 'Downy Big', 'price' => 100, 'count' => 1, 'revenue' => 100]]],
                ['group' => 'Detergents', 'rows' => [
                    ['name' => 'Ariel Pod', 'price' => 20, 'count' => 3, 'revenue' => 60],
                    ['name' => 'Tide', 'price' => 25, 'count' => 1, 'revenue' => 25],
                ]],
            ])
            ->assertJsonPath('addons.most_popular', 'Ariel Pod')
            ->assertJsonPath('addons.top_earner', 'Downy Big')
            ->assertJsonPath('kpis.addons_sold', 185)
            ->assertJsonPath('kpis.addons_count', 5)
            ->assertJsonPath('kpis.addons_pct', 100);
    }

    public function test_empty_range_returns_zeros(): void
    {
        $this->supplies('2026-11-01', '2026-11-20')
            ->assertJsonPath('kpis', [
                'supplies_used' => 0, 'per_order' => 0, 'per_kg' => 0, 'stock_purchased' => 0, 'purchases_count' => 0,
                'adjustments' => 0, 'adjustments_count' => 0, 'addons_sold' => 0, 'addons_count' => 0, 'addons_pct' => 0,
            ])
            ->assertJsonCount(20, 'usage_buckets')
            ->assertJsonPath('usage_average', 0)
            ->assertJsonPath('items', [])
            ->assertJsonPath('items_total', ['per_order_cost' => 0, 'purchased_cost' => 0, 'adjusted_cost' => 0, 'cost_used' => 0])
            ->assertJsonPath('purchases', [])
            ->assertJsonPath('adjustments', [])
            ->assertJsonPath('addons', ['groups' => [], 'most_popular' => null, 'top_earner' => null]);
    }

    public function test_all_branches_equals_sum_of_branches(): void
    {
        $b = $this->seedBranch('B');
        $this->closing($b, '2026-09-10');
        $detergentB = $this->stockItem($b, 'Detergent', 'mL', 0.06);
        $this->movement($detergentB, '2026-09-10 09:00:00', 'out', -500);
        $this->movement($detergentB, '2026-09-11 09:00:00', 'in', 1000);

        $all = $this->supplies()->assertJsonCount(1, 'items')->json();
        $one = $this->supplies(branch: (string) $this->a->id)->json();
        $two = $this->supplies(branch: (string) $b->id)->json();

        foreach (['supplies_used', 'stock_purchased', 'purchases_count', 'adjustments', 'adjustments_count', 'addons_sold', 'addons_count'] as $kpi) {
            $this->assertEqualsWithDelta($one['kpis'][$kpi] + $two['kpis'][$kpi], $all['kpis'][$kpi], 0.001, $kpi);
        }
        foreach (['used', 'purchased', 'adjusted', 'cost_used'] as $col) {
            $this->assertEqualsWithDelta($one['items'][0][$col] + $two['items'][0][$col], $all['items'][0][$col], 0.001, $col);
        }
        $this->assertEquals(90, $all['kpis']['supplies_used']);
        $this->assertCount(2, $all['purchases']);
    }

    public function test_non_admin_is_forbidden_before_validation(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->a->id]);

        $this->actingAs($staff)->getJson(route('owner-dashboard.api.supplies', ['from' => 'bad']))->assertForbidden();
        $this->actingAs($this->owner)->getJson(route('owner-dashboard.api.supplies', ['from' => 'bad']))->assertStatus(400);
    }
}
