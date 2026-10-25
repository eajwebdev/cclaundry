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

    public function test_customer_columns_sort_both_ways_with_numbers_biggest_first(): void
    {
        $quiet = $this->customer('MAIN');
        $quiet->update(['name' => 'Ana Quiet', 'unpaid_limit' => 500]);
        $busy = Customer::query()->create(['branch_id' => $quiet->branch_id, 'name' => 'Zed Busy', 'unpaid_limit' => 100, 'is_active' => true]);
        foreach (range(1, 3) as $i) {
            $this->order($busy, 'JO-BUSY-'.$i);
        }
        $this->order($quiet, 'JO-QUIET-1');
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $names = fn (array $query) => $this->get(route('admin.customers.index', $query))->assertOk()->viewData('customers')->pluck('name')->all();

        // A first click on a number column shows the biggest first.
        $this->assertSame(['Zed Busy', 'Ana Quiet'], $names(['sort' => 'visits']));
        $this->assertSame(['Ana Quiet', 'Zed Busy'], $names(['sort' => 'visits', 'direction' => 'asc']));
        $this->assertSame(['Ana Quiet', 'Zed Busy'], $names(['sort' => 'unpaid_limit']));
        $this->assertSame(['Ana Quiet', 'Zed Busy'], $names(['sort' => 'name']));
        $this->assertSame(['Zed Busy', 'Ana Quiet'], $names(['sort' => 'name', 'direction' => 'desc']));

        // The header link flips the direction of the active column.
        $this->get(route('admin.customers.index', ['sort' => 'visits', 'direction' => 'desc']))
            ->assertSee('direction=asc', false)->assertSee('Fewest visits first');
        $this->get(route('admin.customers.index'))->assertSee('Most visits first');
    }

    public function test_insights_rank_best_customers_and_find_lapsed_regulars(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        $vip = $this->customer('MAIN');
        $vip->update(['name' => 'Vip Vera']);
        $branchId = $vip->branch_id;
        $make = fn (string $name) => Customer::query()->create(['branch_id' => $branchId, 'name' => $name, 'is_active' => true]);
        $regular = $make('Regular Rita');
        $lapsed = $make('Lapsed Lito');
        $once = $make('Once Olive');
        $make('Never Nora');
        $other = $this->customer('OTHER');

        $visit = function (Customer $customer, string $number, string $date, float $total, float $balance = 0, string $status = 'completed') {
            $order = JobOrder::query()->create([
                'customer_id' => $customer->id, 'branch_id' => $customer->branch_id, 'job_order_number' => $number,
                'status' => $status, 'subtotal' => $total, 'total' => $total, 'balance' => $balance,
            ]);
            $order->forceFill(['created_at' => $date])->save();
        };
        $visit($vip, 'V1', '2026-10-05', 1000);
        $visit($vip, 'V2', '2026-10-15', 900, 300);
        foreach (['R1' => '2026-10-02', 'R2' => '2026-10-08', 'R3' => '2026-10-18'] as $number => $date) {
            $visit($regular, $number, $date, 150);
        }
        $visit($regular, 'R-CANCELLED', '2026-10-19', 5000, 0, 'cancelled');
        foreach (['L1' => '2026-06-01', 'L2' => '2026-07-01', 'L3' => '2026-08-01'] as $number => $date) {
            $visit($lapsed, $number, $date, 200, $number === 'L3' ? 50 : 0);
        }
        $visit($once, 'OL1', '2026-09-10', 100);
        $visit($other, 'O1', '2026-10-10', 99999);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $names = fn ($rows) => $rows->map(fn ($row) => $row->customer->name)->all();

        $response = $this->get(route('admin.customers.index', ['branch_id' => $branchId]))->assertOk()
            ->assertSee('Customer insights')->assertSee('Most visits')->assertSee('Haven&#039;t come back', false);
        $insights = $response->viewData('insights');

        $this->assertSame('month', $insights['period']);
        $this->assertSame(2, $insights['activeCustomers']);
        $this->assertSame(5, $insights['newCustomers']);
        $this->assertSame(5, $insights['visitCount']);
        $this->assertEqualsWithDelta(2350 / 5, $insights['averageSpend'], 0.001);
        // Vera, Rita and Lito came back; Olive came once.
        $this->assertEquals(75, $insights['repeatRate']);
        $this->assertSame(['Regular Rita', 'Vip Vera'], $names($insights['mostVisits']));
        $this->assertSame(['Vip Vera', 'Regular Rita'], $names($insights['topSpenders']));
        $this->assertSame(['Vip Vera', 'Lapsed Lito'], $names($insights['unpaidBalances']));
        $this->assertEquals(350, $insights['unpaidTotal']);
        $this->assertSame(['Lapsed Lito'], $names($insights['lapsedRegulars']));
        $this->assertNotContains('Customer OTHER', $names($insights['topSpenders']));
        $this->assertContains($vip->id, $response->viewData('topSpenderIds'));

        // All time counts Lito's summer visits too.
        $allTime = $this->get(route('admin.customers.index', ['branch_id' => $branchId, 'period' => 'all']))->viewData('insights');
        $this->assertSame(['Regular Rita', 'Lapsed Lito', 'Vip Vera', 'Once Olive'], $names($allTime['mostVisits']));

        // The table can sort by money spent, highest first.
        $this->assertSame('Vip Vera', $this->get(route('admin.customers.index', ['branch_id' => $branchId, 'sort' => 'spent']))
            ->viewData('customers')->first()->name);
        $this->get(route('admin.customers.index', ['period' => 'forever']))->assertSessionHasErrors('period');
    }
}
