<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\User;
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
}
