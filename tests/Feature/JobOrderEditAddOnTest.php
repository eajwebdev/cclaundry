<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderEditAddOnTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_order_can_be_edited_to_add_an_add_on(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'Spin Klean Laundry',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
            'is_completed' => true,
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'branch_type' => 'full_service', 'is_active' => true]);
        $customer = Customer::query()->create(['branch_id' => $branch->id, 'name' => 'Joven', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true]);
        $regular = LaundryService::query()->create(['branch_id' => $branch->id, 'name' => 'Regular Laundry Full Service (Min 5KG)', 'report_category' => 'regular', 'pricing_type' => 'kilo', 'price' => 30, 'is_active' => true]);
        $addOn = LaundryService::query()->create(['branch_id' => $branch->id, 'name' => 'Ariel Detergent', 'report_category' => 'detergent', 'pricing_type' => 'load', 'price' => 20, 'is_active' => true]);
        $ariel = Inventory::query()->create(['branch_id' => $branch->id, 'name' => 'Ariel Sachet', 'sku' => 'ARIEL', 'unit' => 'pcs', 'quantity' => 0, 'reorder_level' => 1, 'unit_cost' => 10, 'is_active' => true]);
        $addOn->inventoryUsages()->create(['inventory_id' => $ariel->id, 'quantity' => 1]);

        $pickup = PickupRequest::query()->create([
            'reference_no' => 'PU-1', 'customer_id' => $customer->id, 'branch_id' => $branch->id,
            'contact_name' => 'Joven', 'contact_phone' => '09170000000', 'pickup_address' => 'x',
            'pickup_date' => now()->toDateString(), 'status' => 'picked_up',
        ]);

        $this->actingAs($admin)->post(route('admin.job-orders.store'), [
            'branch_id' => $branch->id, 'processing_branch_id' => $branch->id, 'customer_id' => $customer->id,
            'pickup_request_id' => $pickup->id,
            'items' => [['laundry_service_id' => $regular->id, 'description' => $regular->name, 'quantity' => 8.2, 'unit_price' => 30]],
            'payment_type' => 'unpaid', 'transaction_type' => 'delivery',
        ])->assertSessionHasNoErrors();

        $order = JobOrder::query()->firstOrFail();

        $payload = [
            'customer_id' => $customer->id,
            'processing_branch_id' => $branch->id,
            'items' => [
                ['laundry_service_id' => $regular->id, 'description' => $regular->name, 'quantity' => 8.2, 'unit_price' => 30],
                ['laundry_service_id' => $addOn->id, 'description' => $addOn->name, 'quantity' => 1, 'unit_price' => 20],
            ],
            'discount' => 0, 'status' => 'folding', 'transaction_type' => 'delivery',
        ];

        // Out of stock: refused, and the edit page says why and keeps the add-on in the cart.
        $this->actingAs($admin)
            ->from(route('admin.job-orders.edit', $order))
            ->followingRedirects()
            ->put(route('admin.job-orders.update', $order), $payload)
            ->assertOk()
            ->assertSee('Changes were not saved')
            ->assertSee('Ariel Sachet is insufficient.')
            ->assertSee('\u0022name\u0022:\u0022Ariel Detergent\u0022', false);
        $this->assertSame(1, $order->items()->count());

        // Restocked: the add-on saves and its stock is deducted.
        $ariel->update(['quantity' => 5]);
        $this->actingAs($admin)
            ->from(route('admin.job-orders.edit', $order))
            ->put(route('admin.job-orders.update', $order), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.job-orders.show', $order));

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(266.0, (float) $order->fresh()->total);
        $this->assertSame('4.0000', $ariel->fresh()->quantity);
    }
}
