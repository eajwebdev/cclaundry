<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fractional_kilos_round_the_payable_total_up_and_deduct_fractional_stock(): void
    {
        $this->settings();
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'access' => ['job_orders', 'inventory'],
        ]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'branch_type' => 'full_service',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Fractional Customer',
            'billing_type' => 'regular',
            'unpaid_limit' => 1000,
            'is_active' => true,
        ]);
        $stock = Inventory::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Liquid Detergent',
            'unit' => 'liters',
            'quantity' => 10,
            'reorder_level' => 2,
            'unit_cost' => 100,
            'is_active' => true,
        ]);
        $service = LaundryService::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Wash by Kilo',
            'pricing_type' => 'kilo',
            'price' => 73.25,
            'is_active' => true,
        ]);
        $service->inventoryUsages()->create([
            'inventory_id' => $stock->id,
            'quantity' => 0.125,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $branch->id,
                'processing_branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'items' => [[
                    'laundry_service_id' => $service->id,
                    'description' => $service->name,
                    'quantity' => 0.1,
                    'unit_price' => 73.25,
                ]],
                'discount' => 0,
                'paid_amount' => 0,
                'payment_type' => 'cash',
                'transaction_type' => 'walk_in',
            ])
            ->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->firstOrFail();

        $this->assertSame('0.10', $order->items()->firstOrFail()->quantity);
        $this->assertSame('7.33', $order->subtotal);
        $this->assertSame('8.00', $order->total);
        $this->assertSame('8.00', $order->paid_amount);
        $this->assertSame('8.00', Payment::query()->firstOrFail()->amount);
        $this->assertSame('9.9875', $stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $stock->id,
            'movement_type' => 'out',
            'quantity' => 0.0125,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.job-orders.create', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee("item.pricing_type === 'kilo' ? 0.1 : 1", false);
    }

    public function test_dashboard_returns_ten_latest_low_stock_items_and_header_alarm(): void
    {
        $this->settings();
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'access' => ['dashboard', 'inventory'],
        ]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        foreach (range(1, 12) as $number) {
            $item = Inventory::query()->create([
                'branch_id' => $branch->id,
                'name' => 'Low Item '.$number,
                'unit' => 'pcs',
                'quantity' => 1,
                'reorder_level' => 5,
                'unit_cost' => 10,
                'is_active' => true,
            ]);
            $item->forceFill(['updated_at' => now()->addMinutes($number)])->saveQuietly();
        }

        $payload = $this->actingAs($admin)
            ->getJson(route('dashboard.data', ['branch_id' => $branch->id]))
            ->assertOk()
            ->json();

        $this->assertCount(10, $payload['low_stock_items']);
        $this->assertSame('Low Item 12', $payload['low_stock_items'][0]['name']);
        $this->assertSame('12', $payload['stats']['low_stock']);

        $this->actingAs($admin)
            ->get(route('admin.inventory.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('Low Stock Alarm')
            ->assertSee('12 low-stock alarms');
    }

    public function test_editing_on_hand_quantity_records_a_physical_count_adjustment(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'super_admin', 'access' => ['inventory']]);
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $stock = Inventory::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Laundry Bags',
            'sku' => 'BAG-1',
            'unit' => 'pcs',
            'quantity' => 20,
            'reorder_level' => 5,
            'unit_cost' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.inventory.update', $stock), [
                'branch_id' => $branch->id,
                'name' => $stock->name,
                'sku' => $stock->sku,
                'unit' => $stock->unit,
                'quantity' => 17.5,
                'reorder_level' => 5,
                'unit_cost' => 3,
                'is_active' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('17.5000', $stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $stock->id,
            'movement_type' => 'adjustment',
            'quantity' => 17.5,
        ]);
    }

    private function settings(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
            'vat_enabled' => false,
            'is_completed' => true,
        ]);
    }
}
