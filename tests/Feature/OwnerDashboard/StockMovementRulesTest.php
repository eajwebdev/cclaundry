<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockMovementRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $branch;
    private Inventory $stock;

    private function setUpShop(float $stock = 1000, float $cost = 0.06): void
    {
        SystemSetting::query()->create([
            'business_name' => 'Spin Klean Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV', 'primary_color' => '#2E7D32', 'is_completed' => true,
        ]);
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->branch = Branch::query()->create([
            'name' => 'Branch 1', 'code' => 'B001', 'branch_type' => 'full_service', 'is_active' => true,
        ]);
        $this->stock = Inventory::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Detergent', 'sku' => 'SUP-DETERGENT', 'unit' => 'liter',
            'quantity' => $stock, 'reorder_level' => 0, 'unit_cost' => $cost, 'is_active' => true,
        ]);
    }

    private function move(array $data)
    {
        return $this->actingAs($this->admin)->post(route('admin.inventory.movements.store', $this->stock), $data);
    }

    private function orderPayload(int $serviceId, int $customerId, float $kilos = 7): array
    {
        return [
            'branch_id' => $this->branch->id,
            'processing_branch_id' => $this->branch->id,
            'customer_id' => $customerId,
            'items' => [['laundry_service_id' => $serviceId, 'description' => 'Regular Laundry', 'quantity' => $kilos, 'unit_price' => 30]],
            'discount' => 0, 'paid_amount' => 0, 'payment_type' => 'unpaid', 'transaction_type' => 'walk_in',
        ];
    }

    /** Creates a 7 kg order that deducts 0.03 of the detergent. */
    private function deductedOrder(): array
    {
        $customer = Customer::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'C', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true,
        ]);
        $service = LaundryService::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Regular Laundry', 'report_category' => 'wash',
            'pricing_type' => 'kilo', 'price' => 30, 'minimum_kilos' => 5, 'is_active' => true,
        ]);
        $service->inventoryUsages()->create(['inventory_id' => $this->stock->id, 'quantity' => 0.04]);
        $this->actingAs($this->admin)->post(route('admin.job-orders.store'), $this->orderPayload($service->id, $customer->id));

        return [JobOrder::query()->firstOrFail(), $service, $customer];
    }

    public function test_every_new_movement_stores_cost_and_signed_change(): void
    {
        $this->setUpShop();

        $this->move(['movement_type' => 'in', 'quantity' => 500])->assertSessionDoesntHaveErrors();
        $this->move(['movement_type' => 'out', 'quantity' => 200])->assertSessionDoesntHaveErrors();
        $this->stock->update(['quantity' => 1300]);
        $this->move(['movement_type' => 'adjustment', 'quantity' => 1200, 'reason' => 'count'])->assertSessionDoesntHaveErrors();

        $rows = InventoryMovement::query()->orderBy('id')->get();
        $this->assertSame([500.0, -200.0, -100.0], $rows->map(fn ($m) => (float) $m->change_qty)->all());
        $this->assertSame([0.06, 0.06, 0.06], $rows->map(fn ($m) => (float) $m->unit_cost)->all());
        $this->assertSame([null, null, 'count'], $rows->pluck('reason')->all());
    }

    public function test_manual_adjustment_requires_a_reason(): void
    {
        $this->setUpShop();

        $this->move(['movement_type' => 'adjustment', 'quantity' => 900])->assertSessionHasErrors('reason');
        foreach (['order_edit', 'cancelled_order'] as $systemReason) {
            $this->move(['movement_type' => 'adjustment', 'quantity' => 900, 'reason' => $systemReason])->assertSessionHasErrors('reason');
        }
        $this->assertSame(0, InventoryMovement::query()->count());

        $this->move(['movement_type' => 'adjustment', 'quantity' => 900, 'reason' => 'spill'])->assertSessionDoesntHaveErrors();
        $this->assertSame('spill', InventoryMovement::query()->firstOrFail()->reason);
    }

    public function test_adjustment_without_a_reason_shows_the_error_on_the_inventory_page(): void
    {
        $this->setUpShop();
        $this->withoutVite();
        $index = route('admin.inventory.index', ['branch_id' => $this->branch->id]);

        $this->actingAs($this->admin)->from($index)
            ->post(route('admin.inventory.movements.store', $this->stock), [
                '_movement_form' => $this->stock->id, 'movement_type' => 'adjustment', 'quantity' => 900, 'reason' => '',
            ])
            ->assertRedirect($index)
            ->assertSessionHasErrors(['reason' => 'Choose a reason for the physical count adjustment.']);

        $this->actingAs($this->admin)->get($index)->assertOk()
            ->assertSee('Choose a reason for the physical count adjustment.')
            ->assertSee('movementOpen: '.$this->stock->id, false); // the failed movement modal opens again
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_cancelling_an_order_returns_its_supplies_as_a_positive_adjustment(): void
    {
        $this->setUpShop(1);
        [$order] = $this->deductedOrder();
        $this->assertSame('0.9700', $this->stock->fresh()->quantity);
        $out = InventoryMovement::query()->where('movement_type', 'out')->firstOrFail();
        $this->assertSame(-0.03, (float) $out->change_qty);

        $this->actingAs($this->admin)->patch(route('admin.job-orders.cancel', $order))->assertSessionDoesntHaveErrors();

        $this->assertSame(2, InventoryMovement::query()->count());
        $back = InventoryMovement::query()->where('movement_type', 'in')->firstOrFail();
        $this->assertSame('cancelled_order', $back->reason);
        $this->assertSame(0.03, (float) $back->change_qty);
        $this->assertSame(1.0, (float) $this->stock->fresh()->quantity);
        $this->assertNull($order->fresh()->inventory_deducted_at);
    }

    public function test_cancelling_twice_or_cancelling_an_undeducted_order_writes_nothing(): void
    {
        $this->setUpShop(1);
        [$order] = $this->deductedOrder();
        $this->actingAs($this->admin)->patch(route('admin.job-orders.cancel', $order));
        $count = InventoryMovement::query()->count();

        $this->actingAs($this->admin)->patch(route('admin.job-orders.cancel', $order))->assertStatus(422);
        $this->assertSame($count, InventoryMovement::query()->count());

        $order2 = JobOrder::query()->whereKey($order->id)->firstOrFail()->replicate();
        $order2->job_order_number = 'JO-UNDEDUCTED';
        $order2->status = 'pending';
        $order2->inventory_deducted_at = null;
        $order2->save();
        $this->actingAs($this->admin)->patch(route('admin.job-orders.cancel', $order2));
        $this->assertSame($count, InventoryMovement::query()->count());
    }

    public function test_editing_an_order_tags_the_restore_as_order_edit(): void
    {
        $this->setUpShop(1);
        [$order, $service, $customer] = $this->deductedOrder();

        $this->actingAs($this->admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $customer->id,
            'processing_branch_id' => $this->branch->id,
            'items' => [['laundry_service_id' => $service->id, 'description' => 'Regular Laundry', 'quantity' => 7, 'unit_price' => 30]],
            'discount' => 0, 'status' => 'pending', 'transaction_type' => 'walk_in',
        ])->assertSessionDoesntHaveErrors();

        $restore = InventoryMovement::query()->where('movement_type', 'in')->firstOrFail();
        $this->assertSame('order_edit', $restore->reason);
        $this->assertSame(0.03, (float) $restore->change_qty);
        $outs = InventoryMovement::query()->where('movement_type', 'out')->orderBy('id')->get();
        $this->assertCount(2, $outs);
        $this->assertSame(-0.03, (float) $outs->last()->change_qty);
    }

    public function test_uncancelling_an_accepted_drop_off_order_takes_its_supplies_again(): void
    {
        $this->setUpShop(1);
        $plant = Branch::query()->create(['name' => 'Plant', 'code' => 'P001', 'branch_type' => 'full_service', 'is_active' => true]);
        $plantStock = $this->stock->replicate();
        $plantStock->branch_id = $plant->id;
        $plantStock->save();
        $this->branch->update(['branch_type' => 'pickup_dropoff']);
        $customer = Customer::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'C', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true,
        ]);
        $service = LaundryService::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Regular Laundry', 'report_category' => 'wash',
            'pricing_type' => 'kilo', 'price' => 30, 'minimum_kilos' => 5, 'is_active' => true,
        ]);
        $service->inventoryUsages()->create(['inventory_id' => $this->stock->id, 'quantity' => 0.04]);
        $this->actingAs($this->admin)->post(route('admin.job-orders.store'), ['processing_branch_id' => $plant->id] + $this->orderPayload($service->id, $customer->id))
            ->assertSessionHasNoErrors();
        $order = JobOrder::query()->sole();
        $this->admin->update(['branch_id' => $plant->id]);
        $this->actingAs($this->admin)->get(route('admin.cycles.scan', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch(route('admin.job-orders.cancel', $order))->assertSessionHasNoErrors();
        $this->assertSame(1.0, (float) $plantStock->fresh()->quantity);

        $this->actingAs($this->admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $customer->id, 'processing_branch_id' => $plant->id,
            'items' => [['laundry_service_id' => $service->id, 'description' => 'Regular Laundry', 'quantity' => 7, 'unit_price' => 30]],
            'discount' => 0, 'status' => 'pending', 'transaction_type' => 'walk_in',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.97, (float) $plantStock->fresh()->quantity);
        $this->assertNotNull($order->fresh()->inventory_deducted_at);
        $this->assertSame(1.0, (float) $this->stock->fresh()->quantity);
    }

    public function test_backfill_fills_old_movements(): void
    {
        $this->setUpShop(1000, 0.06);
        $customer = Customer::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'C', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true,
        ]);
        $order = fn (string $no, string $status, array $extra = []) => DB::table('job_orders')->insert($extra + [
            'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'job_order_number' => $no,
            'status' => $status, 'transaction_type' => 'walk_in', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order('JO-CANCELLED', 'cancelled');
        $order('JO-ACTIVE', 'washing');
        $order('JO-DELETED', 'cancelled', ['deleted_at' => now()]);
        $move = fn (string $type, float $qty, ?string $remarks = null, array $extra = []) => DB::table('inventory_movements')->insertGetId($extra + [
            'inventory_id' => $this->stock->id, 'movement_type' => $type, 'quantity' => $qty, 'remarks' => $remarks,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids = [
            'in' => $move('in', 5),
            'out' => $move('out', 2, 'Auto deducted for JO-ACTIVE'),
            'adjustment' => $move('adjustment', 900, 'Counted'),
            'opening' => $move('adjustment', 1000, 'Opening stock'),
            'cancelled' => $move('in', 3, 'Auto restored for JO-CANCELLED'),
            'edited' => $move('in', 4, 'Auto restored for JO-ACTIVE'),
            'deleted' => $move('in', 6, 'Auto restored for JO-DELETED'),
            'missing' => $move('in', 7, 'Auto restored for JO-GONE'),
            // Written by the app after the columns existed: never touched.
            'new' => $move('adjustment', 10, 'Spilled', ['unit_cost' => 0.05, 'change_qty' => -2, 'reason' => 'spill']),
        ];

        $migration = require database_path('migrations/2026_11_23_000001_add_cost_and_reason_to_inventory_movements.php');
        $migration->backfill();

        $read = fn () => collect($ids)->map(fn ($id) => (array) DB::table('inventory_movements')->where('id', $id)->first(['unit_cost', 'change_qty', 'reason']))
            ->map(fn ($r) => [(float) $r['unit_cost'], (float) $r['change_qty'], $r['reason']])->all();
        $this->assertSame([
            'in' => [0.06, 5.0, null],
            'out' => [0.06, -2.0, null],
            'adjustment' => [0.06, 0.0, null],
            'opening' => [0.06, 0.0, 'opening_stock'],
            'cancelled' => [0.06, 3.0, 'cancelled_order'],
            'edited' => [0.06, 4.0, 'order_edit'],
            'deleted' => [0.06, 6.0, 'cancelled_order'],
            'missing' => [0.06, 7.0, 'order_edit'],
            'new' => [0.05, -2.0, 'spill'],
        ], $first = $read());

        $this->stock->update(['unit_cost' => 0.07]);
        $migration->backfill();
        $this->assertSame($first, $read());
    }

    public function test_updating_an_order_to_cancelled_tags_the_restore_as_cancelled_order(): void
    {
        $this->setUpShop(1);
        [$order, $service, $customer] = $this->deductedOrder();

        $this->actingAs($this->admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $customer->id,
            'processing_branch_id' => $this->branch->id,
            'items' => [['laundry_service_id' => $service->id, 'description' => 'Regular Laundry', 'quantity' => 7, 'unit_price' => 30]],
            'discount' => 0, 'status' => 'cancelled', 'transaction_type' => 'walk_in',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(2, InventoryMovement::query()->count());
        $back = InventoryMovement::query()->where('movement_type', 'in')->firstOrFail();
        $this->assertSame('cancelled_order', $back->reason);
        $this->assertSame(0.03, (float) $back->change_qty);
        $this->assertSame(1.0, (float) $this->stock->fresh()->quantity);
        $this->assertNull($order->fresh()->inventory_deducted_at);
    }
}
