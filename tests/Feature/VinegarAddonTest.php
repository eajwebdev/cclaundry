<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\ServicePreset;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VinegarAddonTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    private LaundryService $regular;

    private LaundryService $vinegar;

    private LaundryService $downy;

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
            'branch_id' => $this->branch->id, 'name' => 'Vinegar Customer', 'unpaid_limit' => 1000, 'is_active' => true,
        ]);

        // The migration adds the vinegar service, its stock item and recipe.
        (require database_path('migrations/2026_10_25_000001_add_vinegar_addon.php'))->up();
        $this->vinegar = LaundryService::query()->where('branch_id', $this->branch->id)->where('name', 'Vinegar')->sole();
        $this->stock['vinegar'] = Inventory::query()->where('branch_id', $this->branch->id)->where('sku', 'SUP-VINEGAR')->sole();
        $this->stock['vinegar']->update(['quantity' => 1000]);

        $this->regular = LaundryService::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Regular Laundry Full Service (Min 5 KG)',
            'report_category' => 'wash', 'pricing_type' => 'kilo', 'price' => 30, 'minimum_kilos' => 5, 'is_active' => true,
        ]);
        foreach ([
            'detergent' => ['Mrs Bloom Detergent', 'SUP-BLOOM', 'liter', 1],
            'fabcon' => ['Yen Yen Fabcon', 'SUP-YENYEN', 'liter', 1],
            'bag' => ['Laundry Plastic', 'PKG-PLASTIC', 'pcs', 20],
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
        $this->downy = LaundryService::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Downy Mystique', 'report_category' => 'fabcon',
            'pricing_type' => 'custom', 'price' => 10, 'is_active' => true,
        ]);
        $this->downy->inventoryUsages()->create(['inventory_id' => $this->stock['downy']->id, 'quantity' => 1]);
    }

    private function items(array $extra): array
    {
        return [
            ['laundry_service_id' => $this->regular->id, 'description' => $this->regular->name, 'quantity' => 5, 'unit_price' => 30],
            ...$extra,
        ];
    }

    private function vinegarLine(float $ml, float $price = 0): array
    {
        return ['laundry_service_id' => $this->vinegar->id, 'description' => 'Vinegar', 'quantity' => $ml, 'unit_price' => $price];
    }

    public function test_migration_adds_a_free_ml_vinegar_addon_and_is_repeatable(): void
    {
        (require database_path('migrations/2026_10_25_000001_add_vinegar_addon.php'))->up();

        $this->assertSame(1, LaundryService::query()->where('branch_id', $this->branch->id)->where('name', 'Vinegar')->count());
        $this->assertSame('vinegar', $this->vinegar->report_category);
        $this->assertSame('ml', $this->vinegar->pricing_type);
        $this->assertEquals(0, (float) $this->vinegar->price);
        $this->assertFalse((bool) $this->vinegar->show_on_landing);
        $this->assertTrue((bool) $this->vinegar->serviceCategory->is_addon);
        $this->assertSame(1, $this->vinegar->inventoryUsages()->count());
    }

    public function test_vinegar_is_free_and_replaces_the_default_fabcon(): void
    {
        // A tampered price is ignored: vinegar is always free.
        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id, 'items' => $this->items([$this->vinegarLine(100, 5)]),
            'payment_type' => 'unpaid',
        ])->assertSessionHasNoErrors();

        $order = JobOrder::query()->sole();
        $this->assertEquals(150, (float) $order->total);
        $this->assertEquals(0, (float) $order->items()->where('laundry_service_id', $this->vinegar->id)->value('unit_price'));
        $this->assertEquals(900, (float) $this->stock['vinegar']->fresh()->quantity);
        $this->assertEquals(1, (float) $this->stock['fabcon']->fresh()->quantity, 'Yen Yen untouched');
        $this->assertEquals(0.975, (float) $this->stock['detergent']->fresh()->quantity);
        $this->assertEquals(19, (float) $this->stock['bag']->fresh()->quantity);
    }

    public function test_vinegar_drops_a_fabcon_addon(): void
    {
        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => $this->items([
                $this->vinegarLine(100),
                ['laundry_service_id' => $this->downy->id, 'description' => 'Downy Mystique', 'quantity' => 1, 'unit_price' => 10],
            ]),
            'payment_type' => 'unpaid',
        ])->assertSessionHasNoErrors();

        $order = JobOrder::query()->sole();
        $this->assertEquals(150, (float) $order->total);
        $this->assertFalse($order->items()->where('laundry_service_id', $this->downy->id)->exists());
        $this->assertEquals(10, (float) $this->stock['downy']->fresh()->quantity);
        $this->assertEquals(1, (float) $this->stock['fabcon']->fresh()->quantity);
    }

    public function test_vinegar_cancels_the_fabcon_inside_a_package(): void
    {
        $preset = ServicePreset::query()->create(['branch_id' => $this->branch->id, 'name' => 'Full Service 5kg', 'is_active' => true]);
        $preset->items()->create(['laundry_service_id' => $this->regular->id, 'quantity' => 5]);
        $preset->items()->create(['laundry_service_id' => $this->downy->id, 'quantity' => 1]);

        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [
                ['service_preset_id' => $preset->id, 'description' => $preset->name, 'quantity' => 1, 'unit_price' => 160],
                $this->vinegarLine(100),
            ],
            'payment_type' => 'unpaid',
        ])->assertSessionHasNoErrors();

        $order = JobOrder::query()->sole();
        $this->assertEquals(150, (float) $order->total, 'charged without the Downy');
        $this->assertTrue($order->items()->where('laundry_service_id', $this->regular->id)->exists());
        $this->assertFalse($order->items()->where('laundry_service_id', $this->downy->id)->exists());
        $this->assertEquals(10, (float) $this->stock['downy']->fresh()->quantity);
        $this->assertEquals(1, (float) $this->stock['fabcon']->fresh()->quantity);
        $this->assertEquals(900, (float) $this->stock['vinegar']->fresh()->quantity);
    }

    public function test_editing_to_vinegar_restores_the_yen_yen(): void
    {
        $this->post(route('admin.job-orders.store'), [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id, 'items' => $this->items([]), 'payment_type' => 'unpaid',
        ])->assertSessionHasNoErrors();
        $order = JobOrder::query()->sole();
        $this->assertEquals(0.985, (float) $this->stock['fabcon']->fresh()->quantity);

        $this->put(route('admin.job-orders.update', $order), [
            'customer_id' => $this->customer->id, 'processing_branch_id' => $this->branch->id,
            'items' => $this->items([$this->vinegarLine(150)]), 'status' => 'pending', 'discount' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1, (float) $this->stock['fabcon']->fresh()->quantity);
        $this->assertEquals(850, (float) $this->stock['vinegar']->fresh()->quantity);
    }
}
