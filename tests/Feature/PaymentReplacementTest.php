<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\FinancialReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReplacementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(float $targetTotal = 100): array
    {
        SystemSetting::create(['business_name' => 'Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true]);
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'branch_id' => $branch->id, 'access' => ['job_orders', 'payments']]);
        $this->actingAs($admin);
        $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Replacement Customer']);
        $orders = [];
        foreach ([['JO-ORIGINAL', 'cancelled', 100], ['JO-REPLACEMENT', 'pending', $targetTotal]] as [$number, $status, $total]) {
            $order = JobOrder::create([
                'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'job_order_number' => $number, 'status' => $status, 'subtotal' => $total, 'total' => $total, 'balance' => $total,
            ]);
            $order->items()->create(['description' => 'Laundry', 'quantity' => 1, 'unit_price' => $total, 'total' => $total]);
            CustomerLedger::create([
                'branch_id' => $branch->id, 'customer_id' => $customer->id, 'job_order_id' => $order->id,
                'entry_type' => 'debit', 'amount' => $total, 'running_balance' => $total, 'description' => $number,
            ]);
            $orders[] = $order;
        }

        return [$admin, ...$orders];
    }

    private function pay(JobOrder $order, float $amount, string $number, string $method = 'cash'): Payment
    {
        $payment = Payment::create([
            'branch_id' => $order->branch_id, 'collected_branch_id' => $order->branch_id,
            'job_order_id' => $order->id, 'customer_id' => $order->customer_id, 'received_by' => auth()->id(),
            'payment_number' => $number, 'payment_type' => $method, 'reference_no' => 'REF-'.$number,
            'amount' => $amount, 'tendered_amount' => $method === 'cash' ? $amount + 10 : null,
            'change_amount' => $method === 'cash' ? 10 : 0, 'paid_at' => now()->subDay(),
        ]);
        CustomerLedger::create([
            'branch_id' => $order->branch_id, 'customer_id' => $order->customer_id,
            'job_order_id' => $order->id, 'payment_id' => $payment->id,
            'entry_type' => 'credit', 'amount' => $amount, 'running_balance' => 0, 'description' => $number,
        ]);
        $paid = $order->payments()->sum('amount');
        $order->update(['paid_amount' => $paid, 'balance' => (float) $order->total - $paid]);

        return $payment;
    }

    private function correct(JobOrder $source, JobOrder $target, bool $duplicate = false)
    {
        return $this->post(route('admin.job-orders.reuse-payments', $source), [
            'replacement_number' => $target->job_order_number, 'correction_reason' => 'Same customer payment; original JO replaced.',
            'void_duplicate' => $duplicate,
        ]);
    }

    public function test_existing_payment_is_reused_without_a_new_collection_or_stock_change(): void
    {
        [, $source, $target] = $this->fixture(150);
        $payment = $this->pay($source, 100, 'PAY-REAL');
        $before = $payment->only(['amount', 'payment_type', 'reference_no', 'paid_at', 'received_by', 'collected_branch_id', 'tendered_amount', 'change_amount']);
        $stock = Inventory::create(['branch_id' => $source->branch_id, 'name' => 'Detergent', 'unit' => 'ml', 'quantity' => 80, 'is_active' => true]);
        InventoryMovement::create(['inventory_id' => $stock->id, 'movement_type' => 'out', 'quantity' => 20, 'remarks' => 'Original stock usage']);
        $source->update(['archived_at' => now()]);
        $this->correct($source, $target)->assertSessionHasNoErrors()->assertRedirect(route('admin.job-orders.show', $target));
        $this->assertSame(1, Payment::count());
        $this->assertEquals($before, $payment->fresh()->only(array_keys($before)));
        $this->assertSame($target->id, $payment->fresh()->job_order_id);
        $this->assertSame('100.00', $target->fresh()->paid_amount);
        $this->assertSame('50.00', $target->fresh()->balance);
        $this->assertSame('0.00', $source->fresh()->balance);
        $this->assertSame('0.00', $source->fresh()->paid_amount);
        $this->assertSame($target->id, $source->fresh()->replacement_job_order_id);
        $this->assertSame('cancelled', $source->fresh()->status);
        $this->assertNotNull($source->fresh()->archived_at);
        $this->assertSame('80.0000', $stock->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(2, \App\Models\JobOrderItem::count());
        $this->assertSame('50.00', CustomerLedger::orderByDesc('id')->first()->running_balance);
        $financial = FinancialReconciliation::forPeriod($source->branch_id, today()->subDay()->toDateString(), today()->toDateString());
        $this->assertSame(100.0, $financial['physical_collections']);
        $this->get(route('admin.job-orders.show', $source))->assertOk()->assertSee('JO-REPLACEMENT');
        $this->correct($source, $target)->assertSessionHasErrors('replacement_number');
        $this->assertSame(1, ActivityLog::where('action', 'job_order_payments_transferred')->count());
    }

    public function test_confirmed_duplicate_is_preserved_for_audit_but_excluded_from_receipts_and_financial_totals(): void
    {
        [$admin, $source, $target] = $this->fixture();
        $original = $this->pay($source, 100, 'PAY-REAL');
        $duplicate = $this->pay($target, 100, 'PAY-DUPLICATE');
        $this->correct($source, $target)->assertSessionHasErrors('replacement_number');
        $this->assertSame(2, Payment::count());
        $this->correct($source, $target, true)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Payment::count());
        $this->assertSame(2, Payment::withoutGlobalScope('not_voided')->count());
        $voided = Payment::withoutGlobalScope('not_voided')->findOrFail($duplicate->id);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($admin->id, $voided->voided_by);
        $this->assertSame($target->id, $original->fresh()->job_order_id);
        $this->assertSame('100.00', $target->fresh()->paid_amount);
        $this->assertSame('0.00', $target->fresh()->balance);
        $this->assertSame('0.00', CustomerLedger::orderByDesc('id')->first()->running_balance);
        $financial = FinancialReconciliation::forPeriod($source->branch_id, today()->subDay()->toDateString(), today()->toDateString());
        $this->assertSame(100.0, $financial['cash_collections']);
        $this->assertSame(100.0, $financial['sales_owned']);
        $this->get(route('admin.job-orders.receipt', $target))->assertOk()->assertDontSee('REF-PAY-DUPLICATE')->assertSee('REF-PAY-REAL');
        $this->get(route('admin.job-orders.show', $target))->assertOk()->assertSee('PAY-DUPLICATE')->assertSee('Voided duplicate payments');
        $this->get(route('admin.payments.index', ['date_range' => today()->subDay()->toDateString()]))->assertOk()->assertViewHas('summary', fn ($summary) => (int) $summary->payments_count === 1);
        $this->assertSame([$duplicate->id], ActivityLog::where('action', 'job_order_payments_transferred')->first()->properties['voided_duplicate_payment_ids']);
    }

    public function test_staff_cannot_make_corrections_and_invalid_targets_leave_payments_untouched(): void
    {
        [$admin, $source, $target] = $this->fixture();
        $payment = $this->pay($source, 100, 'PAY-REAL');
        $staff = User::factory()->create(['role' => 'branch_manager', 'branch_id' => $source->branch_id, 'access' => ['job_orders']]);
        $this->actingAs($staff);
        $this->correct($source, $target)->assertForbidden();
        $this->actingAs($admin);
        $other = Customer::create(['branch_id' => $target->branch_id, 'name' => 'Other']);
        $target->update(['customer_id' => $other->id]);
        $this->correct($source, $target)->assertSessionHasErrors('replacement_number');
        $target->update(['customer_id' => $source->customer_id, 'status' => 'cancelled']);
        $this->correct($source, $target)->assertSessionHasErrors('replacement_number');
        $target->update(['status' => 'pending', 'total' => 50]);
        $this->correct($source, $target)->assertSessionHasErrors('replacement_number');
        $this->correct($source, $source)->assertSessionHasErrors('replacement_number');
        $source->update(['status' => 'pending']);
        $this->correct($source, $target)->assertSessionHasErrors('replacement_number');
        $this->assertSame($source->id, $payment->fresh()->job_order_id);
        $this->assertNull($source->fresh()->replacement_job_order_id);
    }

    public function test_mismatched_duplicate_and_inconsistent_ledger_are_rejected_atomically(): void
    {
        [, $source, $target] = $this->fixture();
        $original = $this->pay($source, 100, 'PAY-REAL');
        $duplicate = $this->pay($target, 50, 'PAY-DUPLICATE');
        $this->correct($source, $target, true)->assertSessionHasErrors('replacement_number');
        $duplicate->update(['amount' => 100]);
        $target->update(['paid_amount' => 100, 'balance' => 0]);
        // The amount no longer matches its ledger credit; no partial void may persist.
        $this->correct($source, $target, true)->assertSessionHasErrors('replacement_number');
        $this->assertSame(2, Payment::count());
        $this->assertSame($source->id, $original->fresh()->job_order_id);
        $this->assertNull($source->fresh()->replacement_job_order_id);
        $this->assertSame(0, ActivityLog::where('action', 'job_order_payments_transferred')->count());
    }
    public function test_multiple_original_payments_can_be_reused_with_a_legitimate_replacement_partial_payment(): void
    {
        [, $source, $target] = $this->fixture(150);
        $this->pay($source, 60, 'PAY-CASH');
        $this->pay($source, 40, 'PAY-GCASH', 'gcash');
        $this->pay($target, 20, 'PAY-BANK', 'bank');
        $this->correct($source, $target)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, Payment::count());
        $this->assertSame(3, $target->payments()->count());
        $this->assertSame(0, $source->payments()->count());
        $this->assertSame('120.00', $target->fresh()->paid_amount);
        $this->assertSame('30.00', $target->fresh()->balance);
        $this->assertSame('30.00', CustomerLedger::orderByDesc('id')->first()->running_balance);
    }

    public function test_voided_duplicates_do_not_cause_payment_numbers_to_be_reused(): void
    {
        [, $source, $target] = $this->fixture(150);
        $this->pay($source, 100, 'PAY-REAL');
        $this->pay($target, 100, 'PAY-DUPLICATE');
        $this->correct($source, $target, true)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.job-orders.payments.store', $target), ['amount' => 50, 'payment_type' => 'cash'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('payments', ['payment_number' => 'PAY-'.today()->format('Ymd').'-0003', 'amount' => 50]);
        $this->assertSame('0.00', $target->fresh()->balance);
        $this->assertSame(2, Payment::count());
        $this->assertSame(3, Payment::withoutGlobalScope('not_voided')->count());
    }

}
