<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PickupRequest;
use App\Models\RiderLocationPing;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function completeSystemSettings(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'Cane & Cotton Laundry',
            'contact_number' => '09171234567',
            'business_address' => 'Kabankalan City',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#A07148',
            'is_completed' => true,
        ]);
    }

    private function activeTrial(): void
    {
        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay()->toDateString(),
            'trial_end_date' => now()->addDay()->toDateString(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::query()->create([
            'name' => 'Kabankalan Main',
            'code' => 'KBK',
            'address' => 'Guanzon Street, Kabankalan City',
            'is_active' => true,
            'machine_count' => 3,
        ]);
    }

    private function rider(Branch $branch): User
    {
        return User::factory()->create([
            'role' => 'rider',
            'branch_id' => $branch->id,
            'status' => 'active',
            'access' => [],
        ]);
    }

    private function booking(Branch $branch, array $overrides = []): PickupRequest
    {
        $customer = Customer::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Ana Reyes',
            'phone' => '09171234567',
            'is_active' => true,
        ]);

        return PickupRequest::query()->create(array_merge([
            'reference_no' => PickupRequest::nextReference(),
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '09171234567',
            'pickup_address' => '12 Guanzon Street, Kabankalan City',
            'pickup_latitude' => 9.9886,
            'pickup_longitude' => 122.8112,
            'pickup_date' => today(),
            'pickup_slot' => 'morning',
            'delivery_preference' => 'deliver',
            'status' => 'confirmed',
        ], $overrides));
    }

    public function test_rider_lands_on_the_console_and_only_sees_their_own_runs(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $otherRider = $this->rider($branch);

        $mine = $this->booking($branch, ['rider_id' => $rider->id]);
        $theirs = $this->booking($branch, ['rider_id' => $otherRider->id]);
        $unassigned = $this->booking($branch);

        $this->actingAs($rider)
            ->get(route('rider.index'))
            ->assertOk()
            ->assertSee($mine->reference_no)
            ->assertDontSee($theirs->reference_no)
            ->assertDontSee($unassigned->reference_no);
    }

    public function test_rider_cannot_open_another_riders_job(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $theirs = $this->booking($branch, ['rider_id' => $this->rider($branch)->id]);

        $this->actingAs($rider)
            ->get(route('rider.jobs.show', $theirs))
            ->assertForbidden();
    }

    public function test_rider_cannot_reach_the_admin_area(): void
    {
        // Settings and trial must be in place, otherwise those gates redirect
        // first and we would not actually be exercising authorization.
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->branch();
        $rider = $this->rider($branch);

        $this->actingAs($rider)
            ->get(route('admin.riders.index'))
            ->assertForbidden();
    }

    public function test_non_rider_staff_cannot_reach_the_rider_console(): void
    {
        $branch = $this->branch();
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'status' => 'active',
            'access' => ['dashboard'],
        ]);

        $this->actingAs($cashier)
            ->get(route('rider.index'))
            ->assertForbidden();
    }

    public function test_ping_records_position_and_marks_the_rider_live(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $job = $this->booking($branch, ['rider_id' => $rider->id]);

        $this->actingAs($rider)
            ->postJson(route('rider.ping'), [
                'latitude' => 9.9901,
                'longitude' => 122.8130,
                'accuracy' => 12,
                'heading' => 90,
                'speed' => 4.2,
                'pickup_request_id' => $job->id,
            ])
            ->assertOk()
            ->assertJson(['accepted' => true]);

        $rider->refresh();

        $this->assertSame('9.9901000', (string) $rider->last_latitude);
        $this->assertTrue($rider->is_sharing_location);
        $this->assertTrue($rider->hasLiveLocation());

        $this->assertDatabaseHas('rider_location_pings', [
            'rider_id' => $rider->id,
            'pickup_request_id' => $job->id,
            'accuracy' => 12,
        ]);
    }

    public function test_a_low_accuracy_fix_is_rejected_rather_than_jumping_the_marker(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);

        $this->actingAs($rider)
            ->postJson(route('rider.ping'), [
                'latitude' => 9.99,
                'longitude' => 122.81,
                // Well past config('maps.tracking.max_accuracy_meters').
                'accuracy' => 3000,
            ])
            ->assertOk()
            ->assertJson(['accepted' => false, 'reason' => 'accuracy_too_low']);

        $this->assertSame(0, RiderLocationPing::query()->count());
        $this->assertNull($rider->refresh()->last_latitude);
    }

    public function test_ping_ignores_a_job_belonging_to_another_rider(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $foreignJob = $this->booking($branch, ['rider_id' => $this->rider($branch)->id]);

        $this->actingAs($rider)
            ->postJson(route('rider.ping'), [
                'latitude' => 9.99,
                'longitude' => 122.81,
                'accuracy' => 10,
                'pickup_request_id' => $foreignJob->id,
            ])
            ->assertOk();

        // Position still recorded, but never attributed to a job they do not hold.
        $this->assertDatabaseHas('rider_location_pings', [
            'rider_id' => $rider->id,
            'pickup_request_id' => null,
        ]);
    }

    public function test_rider_advances_a_job_only_one_stage_at_a_time(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $job = $this->booking($branch, ['rider_id' => $rider->id, 'status' => 'confirmed']);

        // Cannot skip straight to completed from confirmed.
        $this->actingAs($rider)
            ->patch(route('rider.jobs.status', $job), ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('confirmed', $job->refresh()->status);

        $this->actingAs($rider)
            ->patch(route('rider.jobs.status', $job), ['status' => 'picked_up'])
            ->assertRedirect(route('rider.index'));

        $job->refresh();
        $this->assertSame('picked_up', $job->status);
        $this->assertNotNull($job->picked_up_at);

        $this->actingAs($rider)
            ->patch(route('rider.jobs.status', $job), ['status' => 'completed'])
            ->assertRedirect(route('rider.index'));

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->delivered_at);
    }

    public function test_stop_sharing_takes_the_rider_off_the_map(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);

        $this->actingAs($rider)->postJson(route('rider.ping'), [
            'latitude' => 9.99, 'longitude' => 122.81, 'accuracy' => 10,
        ])->assertOk();

        $this->actingAs($rider)->postJson(route('rider.stop-sharing'))->assertOk();

        $this->assertFalse($rider->refresh()->is_sharing_location);
    }

    public function test_customer_tracking_feed_returns_the_live_rider(): void
    {
        $this->completeSystemSettings();

        $branch = $this->branch();
        $rider = $this->rider($branch);
        $job = $this->booking($branch, ['rider_id' => $rider->id, 'status' => 'confirmed']);

        $this->actingAs($rider)->postJson(route('rider.ping'), [
            'latitude' => 9.9950, 'longitude' => 122.8200, 'accuracy' => 15,
        ])->assertOk();

        $this->get(route('track.location', $job->reference_no))
            ->assertOk()
            ->assertJson([
                'tracking' => true,
                'stale' => false,
                'leg' => 'pickup',
            ])
            ->assertJsonStructure([
                'rider' => ['latitude', 'longitude', 'updated_at'],
                'destination' => ['latitude', 'longitude'],
                'distance_km',
                'eta_minutes',
            ]);
    }

    public function test_tracking_feed_hides_a_stale_position(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $job = $this->booking($branch, ['rider_id' => $rider->id, 'status' => 'confirmed']);

        // Sharing is on, but the last fix is older than the staleness window.
        $rider->forceFill([
            'last_latitude' => 9.99,
            'last_longitude' => 122.81,
            'last_location_at' => now()->subSeconds((int) config('maps.tracking.stale_after_seconds') + 60),
            'is_sharing_location' => true,
        ])->save();

        $this->get(route('track.location', $job->reference_no))
            ->assertOk()
            ->assertJson(['tracking' => false, 'stale' => true])
            ->assertJsonMissingPath('rider.latitude');
    }

    public function test_tracking_feed_says_nothing_before_a_rider_is_assigned(): void
    {
        $branch = $this->branch();
        $job = $this->booking($branch);

        $this->get(route('track.location', $job->reference_no))
            ->assertOk()
            ->assertJson(['tracking' => false]);
    }

    public function test_dispatcher_assigns_a_rider_and_the_booking_is_confirmed(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->branch();
        $rider = $this->rider($branch);
        $job = $this->booking($branch, ['status' => 'pending']);

        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'status' => 'active',
            'access' => ['riders'],
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.riders.assign', $job), ['rider_id' => $rider->id])
            ->assertRedirect();

        $job->refresh();

        $this->assertSame($rider->id, $job->rider_id);
        $this->assertSame('confirmed', $job->status);
        $this->assertNotNull($job->assigned_at);
    }

    public function test_dispatcher_cannot_assign_a_rider_from_another_branch(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->branch();
        $otherBranch = Branch::query()->create([
            'name' => 'Ilog', 'code' => 'ILG', 'is_active' => true, 'machine_count' => 1,
        ]);

        $foreignRider = $this->rider($otherBranch);
        $job = $this->booking($branch, ['status' => 'pending']);

        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'status' => 'active',
            'access' => ['riders'],
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.riders.assign', $job), ['rider_id' => $foreignRider->id])
            ->assertRedirect();

        $this->assertNull($job->refresh()->rider_id);
    }

    public function test_dispatch_locations_only_lists_riders_who_are_live(): void
    {
        $this->completeSystemSettings();
        $this->activeTrial();

        $branch = $this->branch();
        $live = $this->rider($branch);
        $offline = $this->rider($branch);

        $this->actingAs($live)->postJson(route('rider.ping'), [
            'latitude' => 9.99, 'longitude' => 122.81, 'accuracy' => 10,
        ])->assertOk();

        $manager = User::factory()->create([
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'status' => 'active',
            'access' => ['riders'],
        ]);

        $response = $this->actingAs($manager)
            ->getJson(route('admin.riders.locations'))
            ->assertOk();

        $ids = collect($response->json('riders'))->pluck('id')->all();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($offline->id, $ids);
    }
}
