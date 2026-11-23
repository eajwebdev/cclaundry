<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDashboardFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $branch;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::query()->create([
            'business_name' => 'Spin Klean Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV', 'primary_color' => '#2E7D32', 'is_completed' => true,
        ]);
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->branch = Branch::query()->create(['name' => 'Branch 1', 'code' => 'B001', 'branch_type' => 'full_service', 'is_active' => true]);
        $this->customer = Customer::query()->create(['branch_id' => $this->branch->id, 'name' => 'C', 'billing_type' => 'regular', 'unpaid_limit' => 1000, 'is_active' => true]);
    }

    private function service(string $name, string $type, float $price, ?float $kilosPerLoad = null): LaundryService
    {
        return LaundryService::query()->create([
            'branch_id' => $this->branch->id, 'name' => $name, 'report_category' => 'regular',
            'pricing_type' => $type, 'price' => $price, 'kilos_per_load' => $kilosPerLoad, 'is_active' => true,
        ]);
    }

    private function line(LaundryService $service, float $qty): array
    {
        return ['laundry_service_id' => $service->id, 'description' => $service->name, 'quantity' => $qty, 'unit_price' => $service->price];
    }

    private function store(array $items, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.job-orders.store'), $extra + [
            'branch_id' => $this->branch->id, 'processing_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id, 'items' => $items,
            'discount' => 0, 'paid_amount' => 0, 'payment_type' => 'unpaid', 'transaction_type' => 'walk_in',
        ]);
    }

    private function walkInOrder(): JobOrder
    {
        $this->store([$this->line($this->service('Regular', 'kilo', 30), 6)])->assertSessionDoesntHaveErrors();

        return JobOrder::query()->firstOrFail();
    }

    public function test_saving_an_order_stores_kilos_from_its_lines(): void
    {
        $regular = $this->service('Regular', 'kilo', 30);
        $sheets = $this->service('Bed Sheets', 'load', 100, 8);
        $pillow = $this->service('Pillow', 'piece', 50);
        $this->store([$this->line($regular, 6.5), $this->line($sheets, 2), $this->line($pillow, 3)])->assertSessionDoesntHaveErrors();

        $order = JobOrder::query()->firstOrFail();
        $this->assertSame('22.50', $order->kg_weighed);

        $this->actingAs($this->admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $this->customer->id, 'processing_branch_id' => $this->branch->id,
            'items' => [$this->line($regular, 4)], 'discount' => 0, 'status' => 'pending', 'transaction_type' => 'walk_in',
        ])->assertSessionHasNoErrors();

        $this->assertSame('4.00', $order->fresh()->kg_weighed);
    }

    public function test_load_line_without_kilos_per_load_adds_no_kilos(): void
    {
        $this->assertSame(0.0, JobOrder::kgFromItems([
            (object) ['quantity' => 3, 'service' => $this->service('Loads', 'load', 100)],
            (object) ['quantity' => 2, 'service' => null],
        ]));
    }

    public function test_order_created_from_a_pickup_booking_is_house_pickup(): void
    {
        $pickup = PickupRequest::query()->create([
            'reference_no' => 'PU-1', 'customer_id' => $this->customer->id, 'branch_id' => $this->branch->id,
            'contact_name' => 'C', 'contact_phone' => '09170000000', 'pickup_address' => 'x',
            'pickup_date' => now()->toDateString(), 'status' => 'picked_up', 'delivery_preference' => 'deliver',
        ]);
        $this->store([$this->line($this->service('Regular', 'kilo', 30), 6)], ['pickup_request_id' => $pickup->id])->assertSessionDoesntHaveErrors();

        $order = JobOrder::query()->firstOrFail();
        $this->assertSame('house_pickup', $order->intake_type);
        $this->assertSame('delivery', $order->return_type);
    }

    public function test_booking_that_wants_branch_pickup_stays_shop_pickup(): void
    {
        $pickup = PickupRequest::query()->create([
            'reference_no' => 'PU-2', 'customer_id' => $this->customer->id, 'branch_id' => $this->branch->id,
            'contact_name' => 'C', 'contact_phone' => '09170000000', 'pickup_address' => 'x',
            'pickup_date' => now()->toDateString(), 'status' => 'picked_up', 'delivery_preference' => 'branch_pickup',
        ]);
        $this->store([$this->line($this->service('Regular', 'kilo', 30), 6)], ['pickup_request_id' => $pickup->id])->assertSessionDoesntHaveErrors();

        $this->assertSame('shop_pickup', JobOrder::query()->firstOrFail()->return_type);
    }

    public function test_delivery_transaction_order_is_a_delivery_from_the_start(): void
    {
        $this->store([$this->line($this->service('Regular', 'kilo', 30), 6)], ['transaction_type' => 'delivery'])->assertSessionDoesntHaveErrors();

        $order = JobOrder::query()->firstOrFail();
        $this->assertSame('walk_in', $order->intake_type);
        $this->assertSame('delivery', $order->return_type);
    }

    public function test_walk_in_order_is_walk_in(): void
    {
        $order = $this->walkInOrder();

        $this->assertSame('walk_in', $order->intake_type);
        $this->assertSame('shop_pickup', $order->return_type);
    }

    public function test_moving_to_ready_for_delivery_sets_return_type_delivery(): void
    {
        $order = $this->walkInOrder();

        $this->actingAs($this->admin)->patch(route('admin.job-orders.status', $order), ['status' => 'ready_for_delivery'])->assertSessionHasNoErrors();

        $this->assertSame('delivery', $order->fresh()->return_type);
    }

    public function test_moving_to_ready_for_pickup_sets_return_type_shop_pickup(): void
    {
        $order = $this->walkInOrder();
        $order->update(['return_type' => 'delivery']);

        $this->actingAs($this->admin)->patch(route('admin.job-orders.status', $order), ['status' => 'ready_for_pickup'])->assertSessionHasNoErrors();

        $this->assertSame('shop_pickup', $order->fresh()->return_type);
    }

    public function test_cycle_screen_ready_status_sets_return_type_too(): void
    {
        $order = $this->walkInOrder();

        $this->actingAs($this->admin)->patch(route('admin.cycles.status', $order), ['status' => 'ready_for_delivery']);

        $this->assertSame('delivery', $order->fresh()->return_type);
    }

    public function test_edit_form_status_ready_for_delivery_sets_return_type(): void
    {
        $order = $this->walkInOrder();
        $service = LaundryService::query()->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.job-orders.update', $order), [
            'customer_id' => $this->customer->id, 'processing_branch_id' => $this->branch->id,
            'items' => [$this->line($service, 6)], 'discount' => 0, 'status' => 'ready_for_delivery', 'transaction_type' => 'walk_in',
        ])->assertSessionHasNoErrors();

        $this->assertSame('delivery', $order->fresh()->return_type);
    }

    public function test_return_to_dropoff_sets_return_type_from_the_new_ready_status(): void
    {
        $order = $this->walkInOrder();
        $other = Branch::query()->create(['name' => 'Branch 2', 'code' => 'B002', 'branch_type' => 'full_service', 'is_active' => true]);
        // Wants delivery per return_type, but transaction_type walk_in re-readies it for pickup.
        $order->update(['status' => 'ready_for_delivery', 'return_type' => 'delivery', 'processing_branch_id' => $other->id]);

        $this->actingAs($this->admin)->patch(route('admin.cycles.release', $order), ['action' => 'return_to_dropoff'])->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertSame('ready_for_pickup', $order->status);
        $this->assertSame('shop_pickup', $order->return_type);
    }

    public function test_dashboard_migrations_can_run_again_after_an_interruption(): void
    {
        $this->walkInOrder();
        foreach (glob(database_path('migrations/2026_11_23_00000*.php')) as $file) {
            $migration = require $file;
            // The columns exist but the run was never recorded: up() must not fail on a duplicate column.
            $migration->up();
            $migration->down();
            $migration->down();
            $migration->up();
        }

        $this->assertSame(6.0, (float) DB::table('job_orders')->value('kg_weighed'));
        $this->assertTrue(Schema::hasColumns('attendance_employees', ['employer_share_rate']));
        $this->assertTrue(Schema::hasColumns('inventory_movements', ['unit_cost', 'change_qty', 'reason']));
        $this->assertTrue(Schema::hasColumns('branch_expenses', ['bill_type', 'period_start', 'period_end']));
    }

    public function test_date_filters_have_indexes_and_down_drops_only_its_own(): void
    {
        $file = database_path('migrations/2026_11_24_000001_add_owner_dashboard_date_indexes.php');
        $indexes = ['job_orders' => 'created_at', 'inventory_movements' => 'created_at', 'payments' => 'paid_at'];
        foreach ($indexes as $table => $column) {
            $this->assertTrue(Schema::hasIndex($table, [$column]), "{$table}.{$column}");
        }

        $migration = require $file;
        $migration->up(); // re-run: nothing to add
        $migration->down();
        foreach ($indexes as $table => $column) {
            $this->assertFalse(Schema::hasIndex($table, [$column]), "{$table}.{$column} dropped");
        }

        // An index someone already made is kept by up() and never dropped by down().
        Schema::table('payments', fn ($table) => $table->index('paid_at', 'payments_paid_on'));
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasIndex('payments', 'payments_paid_on'));
        $this->assertFalse(Schema::hasIndex('job_orders', ['created_at']));
    }

    public function test_backfill_fills_old_orders(): void
    {
        $kilo = $this->service('Regular', 'kilo', 30);
        $load = $this->service('Sheets', 'load', 100, 8);
        $noKpl = $this->service('Loads', 'load', 100);
        $piece = $this->service('Pillow', 'piece', 50);

        $order = fn (string $no, array $extra = []) => DB::table('job_orders')->insertGetId($extra + [
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'job_order_number' => $no,
            'status' => 'pending', 'transaction_type' => 'walk_in', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = fn (int $orderId, LaundryService $service, float $qty) => DB::table('job_order_items')->insert([
            'job_order_id' => $orderId, 'laundry_service_id' => $service->id, 'description' => $service->name,
            'quantity' => $qty, 'unit_price' => 1, 'total' => $qty, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $booking = fn (string $ref, int $orderId, string $preference) => DB::table('pickup_requests')->insert([
            'reference_no' => $ref, 'customer_id' => $this->customer->id, 'branch_id' => $this->branch->id,
            'contact_name' => 'C', 'contact_phone' => '0917', 'pickup_address' => 'x', 'pickup_date' => now()->toDateString(),
            'status' => 'picked_up', 'job_order_id' => $orderId, 'delivery_preference' => $preference, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $order('A');
        $booking('PU-A', $a, 'branch_pickup');
        $f = $order('F');
        $booking('PU-F', $f, 'deliver');
        $b = $order('B', ['transaction_type' => 'delivery']);
        $c = $order('C', ['deleted_at' => now()]);
        $item($c, $kilo, 6.5);
        $item($c, $load, 2);
        $item($c, $piece, 3);
        $d = $order('D');
        $item($d, $noKpl, 3);
        $e = $order('E');

        $migration = require database_path('migrations/2026_11_23_000002_add_dashboard_fields_to_job_orders.php');
        $migration->backfill();
        $snapshot = DB::table('job_orders')->orderBy('id')->get(['id', 'kg_weighed', 'intake_type', 'return_type'])->toArray();

        $row = fn (int $id) => DB::table('job_orders')->find($id);
        $this->assertSame('house_pickup', $row($a)->intake_type);
        $this->assertSame('shop_pickup', $row($a)->return_type);
        $this->assertSame('house_pickup', $row($f)->intake_type);
        $this->assertSame('delivery', $row($f)->return_type);
        $this->assertSame('delivery', $row($b)->return_type);
        $this->assertEquals(22.5, $row($c)->kg_weighed);
        $this->assertEquals(0, $row($d)->kg_weighed);
        $this->assertEquals(0, $row($e)->kg_weighed);
        $this->assertSame('walk_in', $row($e)->intake_type);
        $this->assertSame('shop_pickup', $row($e)->return_type);

        $migration->backfill();
        $this->assertEquals($snapshot, DB::table('job_orders')->orderBy('id')->get(['id', 'kg_weighed', 'intake_type', 'return_type'])->toArray());
    }
}
