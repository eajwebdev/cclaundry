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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAddonReplacementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    private LaundryService $regular;

    private array $addons;

    private array $stock;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::query()->create([
            'business_name' => 'Laundry', 'currency' => 'PHP',
            'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->branch = Branch::query()->create([
            'name' => 'Main', 'code' => 'MAIN', 'branch_type' => 'full_service', 'is_active' => true,
        ]);
        $this->customer = Customer::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Replacement Customer', 'unpaid_limit' => 1000, 'is_active' => true,
        ]);
        $this->regular = LaundryService::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Regular Laundry Full Service (Min 5 KG)',
            'report_category' => 'wash', 'pricing_type' => 'kilo', 'price' => 30, 'minimum_kilos' => 5, 'is_active' => true,
        ]);
        foreach ([
            'detergent' => ['Mrs Bloom Detergent', 'SUP-BLOOM', 'liter', 1],
            'fabcon' => ['Yen Yen Fabcon', 'SUP-YENYEN', 'liter', 1],
            'bag' => ['Laundry Plastic', 'PKG-PLASTIC', 'pcs', 20],
            'ariel' => ['Ariel Sachet', 'SUP-ARIEL', 'sachet', 10],
            'downy' => ['Downy Mystique', 'SUP-FABCON-MYSTIQUE', 'sachet', 10],
        ] as $key => [$name, $sku, $unit, $quantity]) {
            $this->stock[$key] = Inventory::query()->create([
                'branch_id' => $this->branch->id, 'name' => $name, 'sku' => $sku,
                'unit' => $unit, 'quantity' => $quantity, 'reorder_level' => 0, 'unit_cost' => 1, 'is_active' => true,
            ]);
        }
        foreach (['detergent' => 0.02, 'fabcon' => 0.015, 'bag' => 1] as $key => $quantity) {
            $this->regular->inventoryUsages()->create(['inventory_id' => $this->stock[$key]->id, 'quantity' => $quantity]);
        }
        foreach (['detergent' => ['Ariel Detergent', 'ariel', 20], 'fabcon' => ['Downy Mystique', 'downy', 10]] as $category => [$name, $stock, $price]) {
            $this->addons[$category] = LaundryService::query()->create([
                'branch_id' => $this->branch->id, 'name' => $name, 'report_category' => $category,
                'pricing_type' => 'custom', 'price' => $price, 'is_active' => true,
            ]);
            $this->addons[$category]->inventoryUsages()->create(['inventory_id' => $this->stock[$stock]->id, 'quantity' => 1]);
        }
    }

    private function items(array $categories): array
    {
        $items = [['laundry_service_id' => $this->regular->id, 'description' => $this->regular->name, 'quantity' => 5, 'unit_price' => 30]];
        foreach ($categories as $category) {
            $addon = $this->addons[$category];
            $items[] = ['laundry_service_id' => $addon->id, 'description' => $addon->name, 'quantity' => 1, 'unit_price' => $addon->price];
        }

        return $items;
    }

    private function createOrder(array $categories, ?int $productionBranchId = null): JobOrder
    {
        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $productionBranchId ?? $this->branch->id,
            'customer_id' => $this->customer->id, 'items' => $this->items($categories),
            'payment_type' => 'unpaid',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.job-orders.index'));

        return JobOrder::query()->sole();
    }

    public static function addonSelections(): array
    {
        return [
            'defaults' => [[], 0.975, 0.985, 10, 10, 150],
            'fabcon replaces Yen Yen' => [['fabcon'], 0.975, 1, 10, 9, 160],
            'detergent replaces Mrs Bloom' => [['detergent'], 1, 0.985, 9, 10, 170],
            'both defaults replaced' => [['detergent', 'fabcon'], 1, 1, 9, 9, 180],
        ];
    }

    #[DataProvider('addonSelections')]
    public function test_addons_replace_only_their_matching_defaults(array $categories, float $detergent, float $fabcon, int $ariel, int $downy, int $total): void
    {
        $order = $this->createOrder($categories);
        foreach (compact('detergent', 'fabcon', 'ariel', 'downy') as $key => $expected) {
            $this->assertEquals($expected, (float) $this->stock[$key]->fresh()->quantity, $key);
        }
        $this->assertEquals(19, (float) $this->stock['bag']->fresh()->quantity);
        $this->assertEquals($total, (float) $order->total);
        foreach ($categories as $category) {
            $this->assertDatabaseMissing('inventory_movements', ['inventory_id' => $this->stock[$category]->id, 'movement_type' => 'out']);
        }
    }

    public function test_replaced_defaults_can_be_out_of_stock(): void
    {
        $this->stock['detergent']->update(['quantity' => 0]);
        $this->stock['fabcon']->update(['quantity' => 0]);
        $this->createOrder(['detergent', 'fabcon']);
        $this->assertEquals(0, (float) $this->stock['detergent']->fresh()->quantity);
        $this->assertEquals(0, (float) $this->stock['fabcon']->fresh()->quantity);
    }

    public function test_editing_addons_restores_previous_stock_and_recalculates_defaults(): void
    {
        $order = $this->createOrder([]);
        foreach ([['fabcon'], [], ['detergent', 'fabcon']] as $categories) {
            $this->put(route('admin.job-orders.update', $order), [
                'customer_id' => $this->customer->id, 'processing_branch_id' => $this->branch->id,
                'items' => $this->items($categories), 'status' => 'pending', 'discount' => 0,
            ])->assertSessionHasNoErrors();
            $this->assertEquals(in_array('fabcon', $categories) ? 1 : 0.985, (float) $this->stock['fabcon']->fresh()->quantity);
            $this->assertEquals(in_array('detergent', $categories) ? 1 : 0.975, (float) $this->stock['detergent']->fresh()->quantity);
            $this->assertEquals(in_array('fabcon', $categories) ? 9 : 10, (float) $this->stock['downy']->fresh()->quantity);
            $this->assertEquals(in_array('detergent', $categories) ? 9 : 10, (float) $this->stock['ariel']->fresh()->quantity);
            $this->assertEquals(19, (float) $this->stock['bag']->fresh()->quantity);
        }
    }

    public function test_an_unavailable_addon_rolls_back_the_entire_order(): void
    {
        $this->stock['downy']->update(['quantity' => 0]);
        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id, 'items' => $this->items(['fabcon']), 'payment_type' => 'unpaid',
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('job_orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertEquals(1, (float) $this->stock['detergent']->fresh()->quantity);
        $this->assertEquals(1, (float) $this->stock['fabcon']->fresh()->quantity);
        $this->assertEquals(20, (float) $this->stock['bag']->fresh()->quantity);
    }

    public function test_production_scan_uses_replacements_without_requiring_default_stock_at_the_production_branch(): void
    {
        $this->branch->update(['branch_type' => 'pickup_dropoff']);
        $productionBranch = Branch::query()->create([
            'name' => 'Production', 'code' => 'PROD', 'branch_type' => 'full_service', 'is_active' => true,
        ]);
        $productionStock = [];
        foreach (['ariel', 'downy', 'bag'] as $key) {
            $productionStock[$key] = $this->stock[$key]->replicate();
            $productionStock[$key]->branch_id = $productionBranch->id;
            $productionStock[$key]->save();
        }
        $order = $this->createOrder(['detergent', 'fabcon'], $productionBranch->id);
        $this->assertNull($order->inventory_deducted_at);
        $this->assertDatabaseCount('inventory_movements', 0);
        auth()->user()->update(['branch_id' => $productionBranch->id]);
        $this->get(route('admin.cycles.scan', $order))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->inventory_deducted_at);
        foreach (['ariel' => 9, 'downy' => 9, 'bag' => 19] as $key => $quantity) {
            $this->assertEquals($quantity, (float) $productionStock[$key]->fresh()->quantity);
        }
        foreach (['detergent' => 1, 'fabcon' => 1, 'ariel' => 10, 'downy' => 10, 'bag' => 20] as $key => $quantity) {
            $this->assertEquals($quantity, (float) $this->stock[$key]->fresh()->quantity);
        }
    }
}
