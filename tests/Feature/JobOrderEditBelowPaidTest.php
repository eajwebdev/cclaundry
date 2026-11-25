<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderEditBelowPaidTest extends TestCase
{
    use RefreshDatabase;

    // Prod MAIN-20261125-0008: paid ₱252 for 8.4 kg, weight corrected to 5.56 kg (₱167) → Z-Reading off by ₱85.
    public function test_editing_a_paid_order_below_what_was_paid_returns_the_excess_as_change(): void
    {
        SystemSetting::query()->create(['business_name' => 'X', 'currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'primary_color' => '#000', 'is_completed' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'branch_type' => 'full_service', 'is_active' => true]);
        $customer = Customer::query()->create(['branch_id' => $branch->id, 'name' => 'A', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true]);
        $wash = LaundryService::query()->create(['branch_id' => $branch->id, 'name' => 'Regular', 'report_category' => 'regular', 'pricing_type' => 'kilo', 'price' => 30, 'is_active' => true]);
        $item = fn ($kg) => [['laundry_service_id' => $wash->id, 'description' => 'Regular', 'quantity' => $kg, 'unit_price' => 30]];

        $this->actingAs($admin)->post(route('admin.job-orders.store'), [
            'branch_id' => $branch->id, 'processing_branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => $item(8.4), 'payment_type' => 'cash', 'paid_amount' => 252,
        ])->assertSessionHasNoErrors();
        $order = JobOrder::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $customer->id, 'processing_branch_id' => $branch->id, 'items' => $item(5.56),
            'discount' => 0, 'status' => 'pending',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'give back ₱85.00'));

        $order->refresh();
        $payment = $order->payments()->sole();
        $this->assertSame(167.0, (float) $order->total);
        $this->assertSame(167.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->balance);
        $this->assertSame(167.0, (float) $payment->amount);
        $this->assertSame(252.0, (float) $payment->tendered_amount);
        $this->assertSame(85.0, (float) $payment->change_amount);
        $this->assertSame(167.0, (float) CustomerLedger::where('payment_id', $payment->id)->value('amount'));
        $this->assertSame(0.0, (float) CustomerLedger::latest('id')->value('running_balance'));

        // Raising it back above paid leaves a balance, payments untouched.
        $this->actingAs($admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $customer->id, 'processing_branch_id' => $branch->id, 'items' => $item(6),
            'discount' => 0, 'status' => 'pending',
        ])->assertSessionHas('success', 'Job order updated successfully.');
        $this->assertSame(13.0, (float) $order->fresh()->balance);
        $this->assertSame(167.0, (float) $payment->fresh()->amount);
    }
}
