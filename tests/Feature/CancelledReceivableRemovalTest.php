<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CancelledReceivableRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    private JobOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::query()->create(['business_name' => 'Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true]);
        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $this->customer = Customer::create(['branch_id' => $this->branch->id, 'name' => 'Customer', 'is_active' => true]);
        $this->order = JobOrder::create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'job_order_number' => 'JO-CANCELLED', 'status' => 'cancelled',
            'subtotal' => 300, 'total' => 300, 'paid_amount' => 150, 'balance' => 150,
        ]);
        CustomerLedger::create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'job_order_id' => $this->order->id, 'entry_type' => 'debit', 'amount' => 300, 'running_balance' => 300,
        ]);
        CustomerLedger::create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'job_order_id' => $this->order->id, 'entry_type' => 'credit', 'amount' => 150, 'running_balance' => 150,
        ]);
    }

    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role, 'branch_id' => $this->branch->id, 'access' => ['receivables']]);
        $this->actingAs($user);

        return $user;
    }

    private function approve(array $overrides = [])
    {
        return $this->from(route('admin.receivables.index'))->patch(route('admin.receivables.approve-removal', $this->order), array_merge([
            'approved' => 1, 'reason' => 'Cancellation approved by management',
        ], $overrides));
    }

    public static function adminRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('adminRoles')]
    public function test_admin_approval_clears_only_the_outstanding_balance_and_records_an_audit(string $role): void
    {
        $user = $this->login($role);
        $payment = Payment::create([
            'branch_id' => $this->branch->id, 'job_order_id' => $this->order->id,
            'customer_id' => $this->customer->id, 'received_by' => $user->id,
            'payment_number' => 'PAY-EXISTING', 'payment_type' => 'cash', 'amount' => 150, 'paid_at' => now(),
        ]);
        $this->approve()->assertSessionHasNoErrors()->assertRedirect(route('admin.receivables.index'));
        $order = $this->order->fresh();
        $this->assertEquals(0, $order->balance);
        $this->assertEquals(300, $order->total);
        $this->assertEquals(150, $order->paid_amount);
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($payment->fresh());
        $this->assertDatabaseCount('payments', 1);
        $credit = CustomerLedger::latest('id')->firstOrFail();
        $this->assertEquals(150, $credit->amount);
        $this->assertEquals(0, $credit->running_balance);
        $this->assertStringContainsString('Cancellation approved by management', $credit->description);
        $log = ActivityLog::where('action', 'cancelled_receivable_removal_approved')->sole();
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals(150, $log->properties['amount']);
        $this->get(route('admin.receivables.index'))->assertOk()->assertDontSee('JO-CANCELLED');
        // A repeated submission must not create another credit.
        $this->approve()->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('customer_ledgers', 3);
    }

    public static function staffRoles(): array
    {
        return [['cashier'], ['branch_manager'], ['staff']];
    }

    #[DataProvider('staffRoles')]
    public function test_other_staff_cannot_approve_removal(string $role): void
    {
        $this->login($role);
        $this->approve()->assertForbidden();
        $this->assertEquals(150, $this->order->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 2);
        $this->get(route('admin.receivables.index'))->assertOk()->assertDontSee('Approve removal');
    }

    public function test_active_order_cannot_be_removed(): void
    {
        $this->login();
        $this->order->update(['status' => 'pending']);
        $this->approve()->assertUnprocessable();
        $this->assertEquals(150, $this->order->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 2);
    }

    public function test_approval_and_reason_are_required(): void
    {
        $this->login();
        $this->approve(['approved' => 0, 'reason' => ''])->assertSessionHasErrors(['approved', 'reason']);
        $this->assertEquals(150, $this->order->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 2);
    }

    public function test_cancelled_filter_keeps_orders_visible_until_approval(): void
    {
        $this->login();
        JobOrder::create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'job_order_number' => 'JO-ACTIVE', 'status' => 'pending', 'balance' => 90,
        ]);
        $this->get(route('admin.receivables.index', ['status' => 'cancelled']))
            ->assertOk()->assertSee('JO-CANCELLED')->assertSee('Approve removal')->assertDontSee('JO-ACTIVE');
    }
}
