<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\User;
use App\Support\OwnerDashboard\Shares;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OverviewEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private Branch $a;
    private User $owner;
    private JobOrder $order1;
    private Payment $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->owner = $this->admin();
        $this->a = $this->seedBranch('A');
        $regular = $this->service('Regular Laundry', 'kilo', 30);
        $this->service('Bed Sheets', 'load', 175);
        $ariel = $this->service('Ariel', 'piece', 20, true);

        $this->order1 = $this->order($this->a, '2026-09-28 09:15:00', [[$regular, 10], [$ariel, 1]]);
        $this->cash = $this->pay($this->order1, '2026-09-28 09:20:00', 'cash', 320);
        $order2 = $this->order($this->a, '2026-09-30 14:30:00', [[$regular, 5]], ['intake_type' => 'house_pickup', 'return_type' => 'delivery']);
        $this->pay($order2, '2026-10-01 11:00:00', 'gcash', 100);
        $this->order($this->a, '2026-09-29 10:00:00', [[$regular, 4]], ['status' => 'cancelled']);

        $this->closing($this->a, '2026-09-28');
        $this->closing($this->a, '2026-09-30');
    }

    private function overview(string $from = '2026-09-28', string $to = '2026-09-30', string $branch = 'all'): TestResponse
    {
        return $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.overview', ['from' => $from, 'to' => $to, 'branch' => $branch]))
            ->assertOk();
    }

    public function test_kpis_for_the_range(): void
    {
        $this->overview()
            ->assertJsonPath('kpis.sales', 470)
            ->assertJsonPath('kpis.orders', 2)
            ->assertJsonPath('kpis.avg_order', 235)
            ->assertJsonPath('kpis.kg_washed', 15)
            ->assertJsonPath('kpis.kg_per_order', 7.5)
            ->assertJsonPath('period.open_days', 2)
            ->assertJsonPath('kpis.sales_per_day', 235)
            ->assertJsonPath('kpis.profit', 470) // no costs in this fixture
            ->assertJsonPath('kpis.profit_pct', 100)
            ->assertJsonPath('closings.summary.count', 2)
            ->assertJsonPath('closings.summary.balanced', 2)
            ->assertJsonPath('closings.unbalanced', [])
            ->assertJsonPath('closings.full', null);
    }

    public function test_sale_counts_on_order_date_and_payment_on_payment_date(): void
    {
        $this->overview()
            ->assertJsonPath('payments.total', 320)
            ->assertJsonPath('payments.methods', [
                ['method' => 'cash', 'amount' => 320, 'pct' => 100],
                ['method' => 'gcash', 'amount' => 0, 'pct' => 0],
                ['method' => 'bank', 'amount' => 0, 'pct' => 0],
            ]);

        $this->overview('2026-10-01', '2026-10-01')
            ->assertJsonPath('kpis.sales', 0)
            ->assertJsonPath('payments.total', 100)
            ->assertJsonPath('payments.methods.1', ['method' => 'gcash', 'amount' => 100, 'pct' => 100]);
    }

    public function test_still_unpaid_is_total_minus_paid_for_orders_created_in_range(): void
    {
        $this->overview()->assertJsonPath('unpaid', 50);
    }

    public function test_cancelled_orders_are_in_no_total(): void
    {
        $deleted = $this->order($this->a, '2026-09-30 10:00:00', [[$this->order1->items()->first()->service, 2]]);
        $deleted->delete();

        $this->overview()
            ->assertJsonPath('kpis.sales', 470)
            ->assertJsonPath('kpis.orders', 2)
            ->assertJsonPath('kpis.kg_washed', 15)
            ->assertJsonPath('unpaid', 50)
            ->assertJsonPath('services.rows.0.qty', 15)
            ->assertJsonPath('intake.walk_in.count', 1);
    }

    public function test_voided_payment_is_not_counted(): void
    {
        $this->cash->update(['voided_at' => now()]);

        $this->overview()
            ->assertJsonPath('payments.total', 0)
            ->assertJsonPath('payments.methods.0.amount', 0);
    }

    public function test_intake_and_return_tiles_and_ebike_trips(): void
    {
        $this->overview()->assertJsonPath('intake', [
            'walk_in' => ['count' => 1, 'pct' => 50],
            'house_pickup' => ['count' => 1, 'pct' => 50],
            'shop_pickup' => ['count' => 1, 'pct' => 50],
            'delivery' => ['count' => 1, 'pct' => 50],
            'ebike_trips' => 2,
        ]);
    }

    public function test_services_sorted_by_amount_with_addons_total_and_top_service(): void
    {
        $this->overview()
            ->assertJsonPath('services.rows', [['name' => 'Regular Laundry', 'qty' => 15, 'unit' => 'kg', 'amount' => 450]])
            ->assertJsonPath('services.services_total', 450)
            ->assertJsonPath('services.addons_total', 20)
            ->assertJsonPath('services.top', ['name' => 'Regular Laundry', 'pct' => 96]);
    }

    public function test_single_day_switches_busiest_to_hourly(): void
    {
        $res = $this->overview('2026-09-28', '2026-09-28')->assertJsonPath('busiest.mode', 'hourly');

        $rows = collect($res->json('busiest.rows'));
        $this->assertSame(range(8, 18), $rows->pluck('hour')->all()); // Monday opening hours
        $this->assertSame(['hour' => 9, 'label' => '9 AM', 'orders' => 1], $rows->firstWhere('hour', 9));
        $this->assertSame(1, $rows->sum('orders'));
    }

    public function test_closed_day_does_not_change_averages(): void
    {
        $res = $this->overview()
            ->assertJsonPath('period.calendar_days', 3)
            ->assertJsonPath('kpis.sales_per_day', 235)
            ->assertJsonPath('kpis.orders_per_day', 1)
            ->assertJsonPath('kpis.kg_per_day', 7.5)
            ->assertJsonPath('busiest.mode', 'weekday');

        $rows = collect($res->json('busiest.rows'))->keyBy('day');
        $this->assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], $rows->keys()->all());
        $this->assertSame(['day' => 'Mon', 'in_range' => true, 'before_noon' => 1, 'after_noon' => 0, 'total' => 1, 'kg' => 10, 'peak' => '8–10 AM'], $rows['Mon']);
        $this->assertSame(['day' => 'Tue', 'in_range' => true, 'before_noon' => 0, 'after_noon' => 0, 'total' => 0, 'kg' => 0, 'peak' => null], $rows['Tue']);
        $this->assertSame(['day' => 'Wed', 'in_range' => true, 'before_noon' => 0, 'after_noon' => 1, 'total' => 1, 'kg' => 5, 'peak' => '1–3 PM'], $rows['Wed']);
        $this->assertFalse($rows['Sun']['in_range']);
    }

    public function test_all_branches_equals_sum_of_branches(): void
    {
        $b = $this->seedBranch('B');
        $order = $this->order($b, '2026-09-29 10:00:00', [[$this->order1->items()->first()->service, 3]]);
        $this->pay($order, '2026-09-29 10:05:00', 'bank', 90);
        $this->closing($b, '2026-09-29');

        $all = $this->overview()->json();
        $ja = $this->overview(branch: (string) $this->a->id)->json();
        $jb = $this->overview(branch: (string) $b->id)->json();

        foreach (['kpis.sales', 'kpis.orders', 'kpis.kg_washed', 'payments.total', 'unpaid', 'closings.summary.count', 'services.services_total'] as $key) {
            $this->assertEquals(data_get($ja, $key) + data_get($jb, $key), data_get($all, $key), $key);
        }
        $this->assertEquals(560, data_get($all, 'kpis.sales'));
        $this->assertSame(3, data_get($all, 'period.open_days'));
    }

    public function test_empty_range_returns_zeros(): void
    {
        $res = $this->overview('2026-08-01', '2026-08-02')
            ->assertJsonPath('period.open_days', 0)
            ->assertJsonPath('kpis', [
                'sales' => 0, 'sales_per_day' => 0, 'orders' => 0, 'avg_order' => 0, 'orders_per_day' => 0,
                'kg_washed' => 0, 'kg_per_order' => 0, 'kg_per_day' => 0, 'profit' => 0, 'profit_pct' => 0,
            ])
            ->assertJsonPath('payments.total', 0)
            ->assertJsonPath('payments.methods.2', ['method' => 'bank', 'amount' => 0, 'pct' => 0])
            ->assertJsonPath('unpaid', 0)
            ->assertJsonPath('intake.walk_in', ['count' => 0, 'pct' => 0])
            ->assertJsonPath('intake.ebike_trips', 0)
            ->assertJsonPath('closings.summary', [
                'count' => 0, 'balanced' => 0, 'with_difference' => 0, 'cash_over_short' => 0,
                'cash_deposited' => 0, 'gcash_sent_to_bank' => 0,
            ])
            ->assertJsonPath('services', ['services_total' => 0, 'addons_total' => 0, 'rows' => [], 'top' => ['name' => null, 'pct' => 0]]);

        $this->assertCount(7, $res->json('busiest.rows'));
        $this->assertSame(0, collect($res->json('busiest.rows'))->sum('total'));
    }

    public function test_non_admin_gets_403_and_bad_range_gets_400(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->a->id]);
        $this->actingAs($staff)->getJson(route('owner-dashboard.api.overview', ['from' => 'nonsense']))->assertForbidden();

        $this->actingAs($this->owner)->getJson(route('owner-dashboard.api.overview', ['from' => '2026-11-01', 'to' => '2026-11-24']))->assertStatus(400);
    }

    public function test_shares_add_to_100(): void
    {
        $shares = Shares::percentages(['a' => 1, 'b' => 1, 'c' => 1]);

        $this->assertSame(['a', 'b', 'c'], array_keys($shares));
        $this->assertSame(100, array_sum($shares));
        $this->assertSame(['a' => 0, 'b' => 0], Shares::percentages(['a' => 0, 'b' => 0]));
    }
}
