<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupBookingsListTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Kabankalan City',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'is_completed' => true,
        ]);
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay()->toDateString(),
            'trial_end_date' => now()->addDay()->toDateString(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);

        $this->branch = Branch::query()->create(['name' => 'Kabankalan Main', 'code' => 'KBK', 'is_active' => true]);
        $this->manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => ['pickup_requests'],
        ]);
    }

    public function test_the_list_opens_on_open_bookings_and_counts_every_tab(): void
    {
        $this->booking(['status' => 'pending', 'contact_name' => 'Open Pending']);
        $this->booking(['status' => 'picked_up', 'contact_name' => 'Open Collected']);
        $this->booking(['status' => 'completed', 'contact_name' => 'Closed Done']);
        $this->booking(['status' => 'cancelled', 'contact_name' => 'Closed Cancelled']);

        $response = $this->actingAs($this->manager)->get(route('admin.pickup-requests.index'))->assertOk();

        $response->assertSee('Open Pending')->assertSee('Open Collected')
            ->assertDontSee('Closed Done')->assertDontSee('Closed Cancelled');
        $this->assertSame(2, $response->viewData('viewCounts')['open']);
        $this->assertSame(4, $response->viewData('viewCounts')['all']);
        $this->assertSame(1, $response->viewData('viewCounts')['cancelled']);

        $this->actingAs($this->manager)
            ->get(route('admin.pickup-requests.index', ['view' => 'completed']))
            ->assertSee('Closed Done')
            ->assertDontSee('Open Pending');
    }

    public function test_a_search_looks_across_every_status(): void
    {
        $done = $this->booking(['status' => 'completed', 'contact_name' => 'Searchable Customer']);

        $this->actingAs($this->manager)
            ->get(route('admin.pickup-requests.index', ['search' => $done->reference_no]))
            ->assertOk()
            ->assertSee('Searchable Customer');
    }

    public function test_pickup_date_and_rider_filters(): void
    {
        $rider = $this->rider();
        $this->booking(['contact_name' => 'Late Customer', 'pickup_date' => today()->subDays(2), 'status' => 'confirmed']);
        $this->booking(['contact_name' => 'Today Customer', 'pickup_date' => today(), 'rider_id' => $rider->id]);
        $this->booking(['contact_name' => 'Tomorrow Customer', 'pickup_date' => today()->addDay()]);

        $overdue = $this->actingAs($this->manager)
            ->get(route('admin.pickup-requests.index', ['when' => 'overdue']))
            ->assertSee('Late Customer')
            ->assertDontSee('Today Customer');
        $this->assertSame(1, $overdue->viewData('overdueCount'));

        $this->actingAs($this->manager)
            ->get(route('admin.pickup-requests.index', ['when' => 'tomorrow']))
            ->assertSee('Tomorrow Customer')
            ->assertDontSee('Late Customer');

        $this->actingAs($this->manager)
            ->get(route('admin.pickup-requests.index', ['rider' => 'unassigned']))
            ->assertSee('Late Customer')
            ->assertDontSee('Today Customer');

        $this->actingAs($this->manager)
            ->get(route('admin.pickup-requests.index', [
                'date_from' => today()->addDay()->toDateString(),
                'date_to' => today()->toDateString(),
            ]))
            ->assertSee('Today Customer')
            ->assertSee('Tomorrow Customer')
            ->assertDontSee('Late Customer');
    }

    public function test_bulk_actions_only_touch_bookings_they_apply_to(): void
    {
        $pending = $this->booking(['status' => 'pending']);
        $collected = $this->booking(['status' => 'picked_up']);
        $completed = $this->booking(['status' => 'completed']);

        $this->actingAs($this->manager)
            ->post(route('admin.pickup-requests.bulk'), [
                'ids' => [$pending->id, $collected->id, $completed->id],
                'action' => 'confirm',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '1 booking confirmed. 2 skipped (not in a status this action applies to).');

        $this->assertSame('confirmed', $pending->refresh()->status);
        $this->assertSame('picked_up', $collected->refresh()->status);

        $this->actingAs($this->manager)
            ->post(route('admin.pickup-requests.bulk'), ['ids' => [$collected->id], 'action' => 'complete']);
        $this->assertSame('completed', $collected->refresh()->status);

        $this->actingAs($this->manager)
            ->post(route('admin.pickup-requests.bulk'), ['ids' => [$pending->id, $completed->id], 'action' => 'cancel']);
        $this->assertSame('cancelled', $pending->refresh()->status);
        $this->assertSame('completed', $completed->refresh()->status);
    }

    public function test_bulk_assign_sends_a_branch_rider_and_confirms_new_bookings(): void
    {
        $rider = $this->rider();
        $first = $this->booking(['status' => 'pending']);
        $second = $this->booking(['status' => 'confirmed']);

        $this->actingAs($this->manager)
            ->post(route('admin.pickup-requests.bulk'), [
                'ids' => [$first->id, $second->id],
                'action' => 'assign',
                'rider_id' => $rider->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame($rider->id, $first->refresh()->rider_id);
        $this->assertSame('confirmed', $first->status);
        $this->assertSame($rider->id, $second->refresh()->rider_id);

        $other = Branch::query()->create(['name' => 'Ilog', 'code' => 'ILG', 'is_active' => true]);
        $foreignRider = $this->rider($other);
        $this->actingAs($this->manager)
            ->post(route('admin.pickup-requests.bulk'), [
                'ids' => [$first->id],
                'action' => 'assign',
                'rider_id' => $foreignRider->id,
            ])
            ->assertSessionHas('error');
        $this->assertSame($rider->id, $first->refresh()->rider_id);
    }

    public function test_bulk_actions_cannot_reach_another_branch(): void
    {
        $other = Branch::query()->create(['name' => 'Ilog', 'code' => 'ILG', 'is_active' => true]);
        $foreign = $this->booking(['status' => 'pending', 'branch_id' => $other->id]);

        $this->actingAs($this->manager)
            ->post(route('admin.pickup-requests.bulk'), ['ids' => [$foreign->id], 'action' => 'confirm'])
            ->assertSessionHas('error');

        $this->assertSame('pending', $foreign->refresh()->status);
    }

    private function rider(?Branch $branch = null): User
    {
        return User::factory()->create([
            'role' => 'rider',
            'branch_id' => ($branch ?? $this->branch)->id,
            'status' => 'active',
            'access' => [],
        ]);
    }

    private function booking(array $overrides = []): PickupRequest
    {
        $branchId = $overrides['branch_id'] ?? $this->branch->id;
        $customer = Customer::query()->create([
            'branch_id' => $branchId,
            'name' => $overrides['contact_name'] ?? 'Ana Reyes',
            'phone' => '09171234567',
            'is_active' => true,
        ]);

        return PickupRequest::query()->create(array_merge([
            'reference_no' => PickupRequest::nextReference(),
            'customer_id' => $customer->id,
            'branch_id' => $branchId,
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '09171234567',
            'pickup_address' => '12 Guanzon Street, Kabankalan City',
            'pickup_date' => today(),
            'pickup_slot' => '08_09',
            'delivery_preference' => 'deliver',
            'status' => 'pending',
        ], $overrides));
    }
}
