<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_hide_standard_products_and_keep_services_and_paid_and_free_addons(): void
    {
        SystemSetting::create([
            'business_name' => 'Laundry', 'currency' => 'PHP',
            'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Receipt Customer']);
        $order = JobOrder::create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'created_by' => auth()->id(), 'job_order_number' => 'JO-VISIBILITY',
            'status' => 'pending', 'transaction_type' => 'walk_in',
            'subtotal' => 180, 'discount' => 0, 'tax' => 0, 'total' => 180,
            'paid_amount' => 0, 'balance' => 180,
        ]);
        foreach ([
            ['Regular Laundry', 'wash', 150],
            ['Mrs. Bloom Detergent', 'detergent', 0],
            ['Yen Yen Fabric Conditioner', 'fabcon', 0],
            ['Ariel Detergent', 'detergent', 20],
            ['Downy Mystique', 'fabcon', 10],
            ['Finishing Spray', 'finishing_spray', 0],
            ['Vinegar', 'vinegar', 0],
        ] as [$name, $category, $price]) {
            $service = LaundryService::create([
                'branch_id' => $branch->id, 'name' => $name, 'report_category' => $category,
                'pricing_type' => 'custom', 'price' => $price, 'is_active' => true,
            ]);
            $order->items()->create([
                'laundry_service_id' => $service->id, 'description' => $name,
                'service_category' => $category, 'quantity' => 1, 'unit_price' => $price, 'total' => $price,
            ]);
        }
        // Legacy product rows must remain hidden when their service is deleted.
        $order->items()->create(['description' => 'Mrs-Bloom Detergent (included)', 'quantity' => 1, 'unit_price' => 0, 'total' => 0]);
        $order->items()->create(['description' => 'YenYen Fabcon', 'quantity' => 1, 'unit_price' => 0, 'total' => 0]);
        LaundryService::where('name', 'Mrs. Bloom Detergent')->firstOrFail()->delete();

        foreach (['admin.job-orders.receipt', 'admin.cycles.receipt'] as $route) {
            $this->get(route($route, $order))->assertOk()
                ->assertSee('Regular Laundry')->assertSee('Ariel Detergent')->assertSee('Downy Mystique')
                ->assertSee('Finishing Spray')->assertSee('Vinegar')
                ->assertDontSee('Mrs. Bloom')->assertDontSee('Mrs-Bloom')
                ->assertDontSee('Yen Yen')->assertDontSee('YenYen')->assertSee('180.00');
        }
        $this->assertSame(9, $order->items()->count());
        $this->assertSame('180.00', $order->fresh()->total);
    }
    public function test_receipts_keep_charged_products_and_services_even_when_names_start_with_a_standard_brand(): void
    {
        $charged = new \App\Models\JobOrderItem(['description' => 'Mrs. Bloom Detergent', 'service_category' => 'detergent', 'unit_price' => 20, 'total' => 20]);
        $this->assertTrue($charged->appearsOnReceipt());
        $service = new \App\Models\JobOrderItem(['description' => 'Yen Yen Laundry Wash', 'service_category' => 'wash', 'unit_price' => 0, 'total' => 0]);
        $this->assertTrue($service->appearsOnReceipt());
    }

}
