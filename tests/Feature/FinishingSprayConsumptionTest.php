<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinishingSprayConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    private LaundryService $laundry;

    private LaundryService $spray;

    private Inventory $stock;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::query()->create(['business_name' => 'Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'branch_type' => 'full_service', 'is_active' => true]);
        $this->customer = Customer::query()->create(['branch_id' => $this->branch->id, 'name' => 'Spray Customer', 'unpaid_limit' => 10000, 'is_active' => true]);
        $this->laundry = LaundryService::query()->create(['branch_id' => $this->branch->id, 'name' => 'Laundry', 'pricing_type' => 'kilo', 'price' => 30, 'is_active' => true]);
        $this->spray = LaundryService::query()->create(['branch_id' => $this->branch->id, 'name' => 'Finishing Spray', 'report_category' => 'finishing_spray', 'pricing_type' => 'custom', 'price' => 0, 'is_active' => true]);
        $this->stock = Inventory::query()->create(['branch_id' => $this->branch->id, 'name' => 'Finishing Spray', 'sku' => 'SUP-FINISHING-SPRAY', 'unit' => 'ml', 'quantity' => 1000, 'is_active' => true]);
        $this->spray->inventoryUsages()->create(['inventory_id' => $this->stock->id, 'quantity' => 1]);
    }

    private function createOrder(bool $withSpray = true): JobOrder
    {
        $items = [['laundry_service_id' => $this->laundry->id, 'description' => 'Laundry', 'quantity' => 7.5, 'unit_price' => 30]];
        if ($withSpray) {
            $items[] = ['laundry_service_id' => $this->spray->id, 'description' => 'Finishing Spray', 'quantity' => 2, 'unit_price' => 0];
        }
        $this->post(route('admin.job-orders.store'), ['branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'items' => $items, 'payment_type' => 'unpaid'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.job-orders.index'));

        return JobOrder::query()->latest('id')->firstOrFail();
    }

    public function test_selected_spray_consumes_3_5_ml_per_actual_kilo_once(): void
    {
        $order = $this->createOrder();
        $this->assertEquals(973.75, $this->stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', ['inventory_id' => $this->stock->id, 'movement_type' => 'out', 'quantity' => 26.25, 'remarks' => "Auto deducted for {$order->job_order_number}"]);
    }

    public function test_spray_in_liters_and_orders_without_spray(): void
    {
        $this->stock->update(['unit' => 'liter', 'quantity' => 1]);
        $this->createOrder(false);
        $this->assertEquals(1, $this->stock->fresh()->quantity);
        $this->createOrder();
        $this->assertEquals(0.9737, $this->stock->fresh()->quantity);
    }

    public function test_backfill_previews_corrects_and_is_repeatable_from_september_26(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 26)->startOfDay());
        $order = $this->createOrder();
        // Reproduce the old one-use recipe's recorded deduction.
        $this->stock->movements()->update(['quantity' => 1]);
        $this->stock->update(['quantity' => 999]);
        $this->travelBack();

        $this->artisan('inventory:backfill-finishing-spray')->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertEquals(999, $this->stock->fresh()->quantity);
        $this->artisan('inventory:backfill-finishing-spray', ['--apply' => true])->expectsOutputToContain('1 order(s) adjusted.')->assertSuccessful();
        $this->assertEquals(973.75, $this->stock->fresh()->quantity);
        $this->artisan('inventory:backfill-finishing-spray', ['--apply' => true])->expectsOutputToContain('0 order(s) adjusted.')->assertSuccessful();
        $this->assertEquals(973.75, $this->stock->fresh()->quantity);

        $this->delete(route('admin.job-orders.destroy', $order))->assertSessionHasNoErrors();
        $this->assertEquals(1000, $this->stock->fresh()->quantity);
    }

    public function test_backfill_excludes_earlier_orders(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 25));
        $this->createOrder();
        $this->stock->movements()->update(['quantity' => 1]);
        $this->stock->update(['quantity' => 999]);
        $this->travelBack();
        $this->artisan('inventory:backfill-finishing-spray', ['--apply' => true])->expectsOutputToContain('0 order(s) adjusted.')->assertSuccessful();
        $this->assertEquals(999, $this->stock->fresh()->quantity);
    }

    public function test_backfill_reduces_excessive_liter_deductions_in_usage_reports(): void
    {
        $this->stock->update(['unit' => 'liter', 'quantity' => 10]);
        $this->travelTo(now()->setDate(2026, 9, 26));
        $this->createOrder();
        $this->stock->movements()->update(['quantity' => 1]);
        $this->stock->update(['quantity' => 9]);
        $this->travelBack();

        $this->artisan('inventory:backfill-finishing-spray', ['--apply' => true])->assertSuccessful();
        $this->assertEquals(9.9737, $this->stock->fresh()->quantity);
        $this->assertEquals(0.0263, $this->stock->movements()->where('movement_type', 'out')->sum('quantity'));
        $this->artisan('inventory:backfill-finishing-spray', ['--apply' => true])->expectsOutputToContain('0 order(s) adjusted.')->assertSuccessful();
    }

    public function test_configured_load_weight_drives_spray_consumption(): void
    {
        $this->laundry->update(['pricing_type' => 'load', 'kilos_per_load' => 8]);
        $this->createOrder(); // 7.5 loads × 8 kg × 3.5 mL
        $this->assertEquals(790, $this->stock->fresh()->quantity);
    }

    public function test_unknown_units_reject_a_new_order_without_deducting_stock(): void
    {
        $this->stock->update(['unit' => 'use']);
        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'items' => [
                ['laundry_service_id' => $this->laundry->id, 'description' => 'Laundry', 'quantity' => 5, 'unit_price' => 30],
                ['laundry_service_id' => $this->spray->id, 'description' => 'Spray', 'quantity' => 1, 'unit_price' => 0],
            ], 'payment_type' => 'unpaid',
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('job_orders', 0);
        $this->assertEquals(1000, $this->stock->fresh()->quantity);
    }

    public function test_backfill_refuses_unknown_volume_units_without_writing(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 26));
        $this->createOrder();
        $this->travelBack();
        $this->stock->update(['unit' => 'use']);
        $this->artisan('inventory:backfill-finishing-spray', ['--apply' => true])->expectsOutputToContain('No adjustments were saved.')->assertFailed();
        $this->assertEquals(973.75, $this->stock->fresh()->quantity);
        $this->assertEquals(1, $this->stock->movements()->count());
    }
}
