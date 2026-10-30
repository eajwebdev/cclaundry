<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CycleRecord;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobOrderArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'admin'): array
    {
        SystemSetting::create(['business_name' => 'Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true]);
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $user = User::factory()->create(['role' => $role, 'branch_id' => $branch->id, 'access' => ['job_orders']]);
        $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Archive Customer']);
        $order = JobOrder::create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'job_order_number' => 'JO-ARCHIVE-001', 'status' => 'cancelled',
            'subtotal' => 100, 'total' => 100, 'paid_amount' => 40, 'balance' => 60,
        ]);
        $this->actingAs($user);

        return [$user, $order, $customer];
    }

    public static function adminRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('adminRoles')]
    public function test_admin_can_archive_and_restore_without_changing_connected_records(string $role): void
    {
        [$user, $order, $customer] = $this->fixture($role);
        $order->items()->create(['description' => 'Wash', 'quantity' => 1, 'unit_price' => 100, 'total' => 100]);
        Payment::create([
            'branch_id' => $order->branch_id, 'collected_branch_id' => $order->branch_id,
            'job_order_id' => $order->id, 'customer_id' => $customer->id,
            'payment_number' => 'PAY-ARCHIVE', 'payment_type' => 'cash', 'amount' => 40, 'paid_at' => now(),
        ]);
        CustomerLedger::create(['branch_id' => $order->branch_id, 'customer_id' => $customer->id, 'job_order_id' => $order->id, 'entry_type' => 'debit', 'amount' => 100, 'running_balance' => 60, 'description' => 'Original ledger']);
        CycleRecord::create(['job_order_id' => $order->id, 'cycle_type' => 'wash', 'machine_number' => 1, 'cycle_number' => 1, 'started_at' => now()->subHour(), 'ended_at' => now()]);
        $stock = Inventory::create(['branch_id' => $order->branch_id, 'name' => 'Detergent', 'unit' => 'ml', 'quantity' => 8, 'is_active' => true]);
        InventoryMovement::create(['inventory_id' => $stock->id, 'movement_type' => 'out', 'quantity' => 2, 'remarks' => "Auto deducted for {$order->job_order_number}"]);
        $snapshot = fn () => [
            $order->fresh()->only(['status', 'total', 'paid_amount', 'balance', 'deleted_at']),
            $order->items()->get()->toArray(), $order->payments()->get()->toArray(),
            CustomerLedger::where('job_order_id', $order->id)->get()->toArray(),
            $order->cycles()->get()->toArray(), $stock->fresh()->toArray(), InventoryMovement::all()->toArray(),
        ];
        $before = $snapshot();
        $this->get(route('admin.job-orders.index'))->assertOk()->assertSee('Archive cancelled job order');
        $this->patch(route('admin.job-orders.archive', $order))->assertRedirect(route('admin.job-orders.index'));
        $this->assertNotNull($order->fresh()->archived_at);
        $this->assertSame($user->id, $order->fresh()->archived_by);
        $this->assertSame($before, $snapshot());
        $this->get(route('admin.job-orders.index'))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->get(route('admin.job-orders.index', ['status' => 'cancelled']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->get(route('admin.job-orders.index', ['status' => 'archived']))->assertOk()->assertSee('Restore archived job order')->assertViewHas('orders', fn ($orders) => $orders->contains('id', $order->id));
        $this->get(route('admin.job-orders.show', $order))->assertOk()->assertSee('kept for reference');
        $this->get(route('admin.job-orders.receipt', $order))->assertOk();
        $this->get(route('admin.job-orders.edit', $order))->assertUnprocessable();
        $this->putJson(route('admin.job-orders.update', $order), [])->assertUnprocessable();
        $this->patch(route('admin.job-orders.archive', $order))->assertUnprocessable();
        $this->patch(route('admin.job-orders.restore-archive', $order))
            ->assertRedirect(route('admin.job-orders.index', ['status' => 'cancelled', 'date_range' => $order->created_at->toDateString()]));
        $this->assertNull($order->fresh()->archived_at);
        $this->assertSame($before, $snapshot());
        $this->get(route('admin.job-orders.index', ['status' => 'cancelled']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->contains('id', $order->id));
        $this->assertSame(1, ActivityLog::where('action', 'job_order_archived')->count());
        $this->assertSame(1, ActivityLog::where('action', 'job_order_archive_restored')->count());
    }

    public function test_staff_cannot_archive_restore_or_open_the_archived_filter(): void
    {
        [, $order] = $this->fixture('branch_manager');
        $this->get(route('admin.job-orders.index'))->assertOk()->assertDontSee('Archive cancelled job order');
        $this->patch(route('admin.job-orders.archive', $order))->assertForbidden();
        $order->update(['archived_at' => now()]);
        $this->patch(route('admin.job-orders.restore-archive', $order))->assertForbidden();
        $this->get(route('admin.job-orders.index', ['status' => 'archived']))->assertForbidden();
    }

    public function test_only_cancelled_orders_can_be_archived_and_unarchived_orders_cannot_be_restored(): void
    {
        [, $order] = $this->fixture();
        foreach (['pending', 'washing', 'ready_for_pickup', 'completed'] as $status) {
            $order->update(['status' => $status]);
            $this->patch(route('admin.job-orders.archive', $order))->assertUnprocessable();
            $this->assertNull($order->fresh()->archived_at);
        }
        $this->patch(route('admin.job-orders.restore-archive', $order))->assertUnprocessable();
        $this->assertSame(0, ActivityLog::where('action', 'job_order_archived')->count());
    }

    public function test_archived_filter_finds_older_orders_and_still_respects_search_and_explicit_dates(): void
    {
        [, $order] = $this->fixture();
        $order->forceFill(['created_at' => now()->subMonth()])->save();
        $this->patch(route('admin.job-orders.archive', $order))->assertRedirect();
        $this->get(route('admin.job-orders.index', ['status' => 'archived']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 1);
        $this->get(route('admin.job-orders.index', ['status' => 'archived', 'search' => 'missing']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->get(route('admin.job-orders.index', ['status' => 'archived', 'date_range' => today()->toDateString()]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $response = $this->patch(route('admin.job-orders.restore-archive', $order))->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->contains('id', $order->id));
    }
}
