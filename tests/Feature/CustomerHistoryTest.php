<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::query()->create([
            'business_name' => 'Laundry', 'contact_number' => '09171234567',
            'business_address' => 'Main Street', 'currency' => 'PHP',
            'job_order_prefix' => 'JO', 'invoice_prefix' => 'INV', 'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true, 'trial_start_date' => today()->subDay(),
            'trial_end_date' => today()->addDay(), 'trial_status' => 'active', 'grace_period_days' => 0,
        ]);
    }

    private function customer(string $code): Customer
    {
        $branch = Branch::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);

        return Customer::query()->create(['branch_id' => $branch->id, 'name' => 'Customer '.$code, 'is_active' => true]);
    }

    private function order(Customer $customer, string $number, string $status = 'completed'): JobOrder
    {
        return JobOrder::query()->create([
            'customer_id' => $customer->id, 'branch_id' => $customer->branch_id,
            'job_order_number' => $number, 'status' => $status,
            'subtotal' => 219, 'total' => 219, 'balance' => 19,
        ]);
    }

    public function test_history_counts_return_visits_and_paginates_only_this_customers_orders(): void
    {
        $customer = $this->customer('MAIN');
        $other = $this->customer('OTHER');
        $admin = User::factory()->create(['role' => 'super_admin']);
        for ($i = 1; $i <= 12; $i++) {
            $order = $this->order($customer, 'JO-HISTORY-'.$i);
            $order->forceFill(['created_at' => today()->subDays(13 - $i)])->save();
        }
        $order->items()->create(['description' => 'Wash and fold', 'quantity' => 2, 'unit_price' => 100, 'total' => 200]);
        $cancelled = $this->order($customer, 'JO-CANCELLED', 'cancelled');
        $deleted = $this->order($customer, 'JO-DELETED');
        $deleted->delete();
        $this->order($other, 'JO-PRIVATE');

        $this->actingAs($admin)->get(route('admin.customers.history', $customer))
            ->assertOk()->assertViewHas('visitCount', 12)
            ->assertViewHas('firstVisit', fn ($date) => $date->isSameDay(today()->subDays(12)))
            ->assertViewHas('lastVisit', fn ($date) => $date->isSameDay(today()->subDay()))
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 13 && $orders->count() === 10)
            ->assertSee('Return visits')->assertSee('11')->assertSee('Wash and fold')
            ->assertSee('PHP 219.00')->assertSee('PHP 19.00')->assertSee($cancelled->job_order_number)
            ->assertDontSee('JO-PRIVATE')->assertDontSee('JO-DELETED')->assertDontSee('JO-HISTORY-1<', false);
        $this->get(route('admin.customers.history', ['customer' => $customer, 'page' => 2]))
            ->assertOk()->assertSee('JO-HISTORY-1')->assertViewHas('visitCount', 12)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 3);
        $this->get(route('admin.customers.index', ['search' => $customer->name]))
            ->assertOk()->assertViewHas('customers', fn ($rows) => $rows->sole()->job_orders_count === 12);
    }

    public function test_history_handles_no_visits_and_enforces_branch_access(): void
    {
        $customer = $this->customer('MAIN');
        $other = $this->customer('OTHER');
        $manager = User::factory()->create([
            'role' => 'branch_manager', 'branch_id' => $customer->branch_id,
            'status' => 'active', 'access' => ['customers'],
        ]);
        $this->actingAs($manager)->get(route('admin.customers.history', $customer))
            ->assertOk()->assertViewHas('visitCount', 0)->assertSee('No job orders yet')->assertSee('No visits yet');
        $this->get(route('admin.customers.history', $other))->assertForbidden();
        $this->order($customer, 'JO-OWN');
        $foreignOrder = $this->order($customer, 'JO-OTHER-BRANCH');
        $foreignOrder->update(['branch_id' => $other->branch_id]);
        $this->get(route('admin.customers.history', $customer))
            ->assertOk()->assertViewHas('visitCount', 1)->assertSee('JO-OWN')->assertDontSee('JO-OTHER-BRANCH');
        $customer->delete();
        $this->get(route('admin.customers.history', $customer))->assertNotFound();
    }

    public function test_history_shows_which_machines_each_order_used(): void
    {
        $customer = $this->customer('MAIN');
        $customer->branch->update(['machine_count' => 5, 'titan_machine_numbers' => [3]]);
        $other = $this->customer('OTHER');
        $admin = User::factory()->create(['role' => 'super_admin']);

        $first = $this->order($customer, 'JO-MACHINE-1');
        $first->cycles()->create(['cycle_type' => 'wash', 'machine_number' => 3, 'cycle_number' => 1, 'started_at' => now()->subDays(2)->setTime(9, 15), 'ended_at' => now()->subDays(2)->setTime(9, 50)]);
        $first->cycles()->create(['cycle_type' => 'dry', 'machine_number' => 2, 'cycle_number' => 1, 'started_at' => now()->subDays(2)->setTime(10, 0)]);
        $first->cycles()->create(['cycle_type' => 'fold', 'machine_number' => null, 'cycle_number' => 1, 'started_at' => now()->subDays(2)->setTime(11, 0)]);
        $second = $this->order($customer, 'JO-MACHINE-2');
        $second->cycles()->create(['cycle_type' => 'wash', 'machine_number' => 3, 'cycle_number' => 1, 'started_at' => now()->subDay()]);
        $this->order($customer, 'JO-NO-MACHINE');
        $this->order($other, 'JO-OTHER')->cycles()->create(['cycle_type' => 'wash', 'machine_number' => 5, 'cycle_number' => 1, 'started_at' => now()]);

        $this->actingAs($admin)->get(route('admin.customers.history', $customer))
            ->assertOk()
            ->assertSee('Machines used')
            ->assertViewHas('machineUsage', function ($usage) {
                $wash = $usage->first();

                return $usage->count() === 2
                    && $wash->cycle_type === 'wash' && (int) $wash->machine_number === 3
                    && (int) $wash->uses === 2 && $wash->machine_class === 'Titan';
            })
            ->assertSee('Wash #3', false)->assertSee('× 2', false)
            ->assertSee('#3 Titan')->assertSee('#2 Giant')
            ->assertSee('09:15 AM – 09:50 AM', false)
            ->assertSee('None recorded')
            ->assertDontSee('Fold #')->assertDontSee('#5 ');
    }
}
