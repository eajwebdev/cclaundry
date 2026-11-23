<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec acceptance test 17: a price change never rewrites old sales. */
class PriceSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private User $owner;
    private Branch $branch;
    private Customer $customer;
    private LaundryService $regular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->admin();
        $this->branch = $this->seedBranch('A');
        $this->customer = Customer::query()->create(['branch_id' => $this->branch->id, 'name' => 'C', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true]);
        $this->regular = $this->service('Regular', 'kilo', 30);
        $this->regular->update(['branch_id' => $this->branch->id]);
    }

    private function placeOrder(float $kg): JobOrder
    {
        $this->actingAs($this->owner)->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id, 'discount' => 0, 'paid_amount' => 0,
            'payment_type' => 'unpaid', 'transaction_type' => 'walk_in',
            'items' => [['laundry_service_id' => $this->regular->id, 'description' => 'Regular', 'quantity' => $kg, 'unit_price' => $this->regular->fresh()->price]],
        ])->assertSessionDoesntHaveErrors();

        return JobOrder::query()->latest('id')->firstOrFail();
    }

    private function changePrice(float $price): void
    {
        $this->actingAs($this->owner)->put(route('admin.services.update', $this->regular), [
            'name' => 'Regular', 'pricing_type' => 'kilo', 'price' => $price, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
    }

    private function overview(string $from, string $to)
    {
        return $this->actingAs($this->owner)
            ->getJson(route('owner-dashboard.api.overview', ['from' => $from, 'to' => $to, 'branch' => 'all']))
            ->assertOk();
    }

    /** @return array{0: JobOrder, 1: JobOrder} */
    private function ordersAcrossAPriceChange(): array
    {
        $this->travelTo('2026-11-10 10:00:00');
        $first = $this->placeOrder(10);
        $this->changePrice(35);
        $this->travelTo('2026-11-11 10:00:00');
        $second = $this->placeOrder(10);

        return [$first, $second];
    }

    public function test_old_orders_keep_their_saved_unit_price_after_a_price_change(): void
    {
        [$first, $second] = $this->ordersAcrossAPriceChange();

        $a = $first->items()->firstOrFail();
        $b = $second->items()->firstOrFail();
        $this->assertSame('30.00', $a->unit_price);
        $this->assertSame('300.00', $a->total);
        $this->assertSame('35.00', $b->unit_price);
        $this->assertSame('350.00', $b->total);
        $this->assertEquals(300, $first->fresh()->total);

        $this->overview('2026-11-10', '2026-11-11')
            ->assertJsonPath('kpis.sales', 650)
            ->assertJsonPath('services.rows.0.amount', 650)
            ->assertJsonPath('services.rows.0.qty', 20)
            ->assertJsonPath('services.rows.0.price', 35); // list price now, by design

        $this->overview('2026-11-10', '2026-11-10')->assertJsonPath('kpis.sales', 300);
    }

    public function test_editing_an_old_order_keeps_its_saved_unit_price_unless_the_line_is_changed(): void
    {
        [$first] = $this->ordersAcrossAPriceChange();
        $saved = $first->items()->firstOrFail();

        // Re-save with the line exactly as the edit form sends it back (saved price).
        $this->actingAs($this->owner)->put(route('admin.job-orders.update', $first), [
            'customer_id' => $this->customer->id, 'processing_branch_id' => $this->branch->id,
            'status' => 'pending', 'transaction_type' => 'walk_in', 'discount' => 0,
            'items' => [['laundry_service_id' => $this->regular->id, 'description' => 'Regular', 'quantity' => 10, 'unit_price' => $saved->unit_price]],
        ])->assertSessionHasNoErrors();

        $line = $first->fresh()->items()->firstOrFail();
        $this->assertSame('30.00', $line->unit_price);
        $this->assertSame('300.00', $line->total);
        $this->overview('2026-11-10', '2026-11-10')->assertJsonPath('kpis.sales', 300);
    }
}
