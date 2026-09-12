<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PickupRequest;
use App\Models\User;
use App\Support\Routing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Routing is faked here rather than hitting OSRM: the tests are about how the
 * app behaves around the router, including when it is unavailable.
 */
class RiderRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Routes are cached by coordinate pair; a warm entry from one test
        // must not answer another.
        Cache::flush();
    }

    private function osrmResponse(float $distance = 1729, float $duration = 190): array
    {
        return [
            'code' => 'Ok',
            'routes' => [[
                'distance' => $distance,
                'duration' => $duration,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[122.8172, 10.0021], [122.8140, 9.9950], [122.8112, 9.9886]],
                ],
                'legs' => [[
                    'steps' => [
                        [
                            'name' => '',
                            'distance' => 23,
                            'maneuver' => ['type' => 'depart', 'location' => [122.8172, 10.0021]],
                        ],
                        [
                            'name' => 'Guanzon Street',
                            'distance' => 586,
                            'maneuver' => ['type' => 'turn', 'modifier' => 'left', 'location' => [122.8140, 9.9950]],
                        ],
                        [
                            'name' => '',
                            'distance' => 0,
                            'maneuver' => ['type' => 'arrive', 'location' => [122.8112, 9.9886]],
                        ],
                    ],
                ]],
            ]],
        ];
    }

    private function job(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Kabankalan Main', 'code' => 'KBK', 'is_active' => true, 'machine_count' => 2,
        ]);

        $rider = User::factory()->create([
            'role' => 'rider', 'branch_id' => $branch->id, 'status' => 'active', 'access' => [],
        ]);

        $customer = Customer::query()->create([
            'branch_id' => $branch->id, 'name' => 'Ana Reyes', 'phone' => '09171234567', 'is_active' => true,
        ]);

        $job = PickupRequest::query()->create([
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
            'rider_id' => $rider->id,
        ]);

        return [$rider, $job];
    }

    public function test_a_rider_gets_a_route_with_readable_instructions(): void
    {
        Http::fake(['*' => Http::response($this->osrmResponse())]);

        [$rider, $job] = $this->job();

        $response = $this->actingAs($rider)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.002174&longitude=122.817258')
            ->assertOk();

        $this->assertCount(1, $response->json('routes'));
        $this->assertSame(1729, $response->json('routes.0.distance'));
        $this->assertSame('pickup', $response->json('leg'));

        // OSRM ships maneuver codes; the rider needs sentences.
        $instructions = array_column($response->json('routes.0.steps'), 'instruction');
        $this->assertContains('Turn left onto Guanzon Street', $instructions);
        $this->assertContains('You have arrived', $instructions);
    }

    public function test_rider_chosen_waypoints_are_sent_to_the_router(): void
    {
        Http::fake(['*' => Http::response($this->osrmResponse(3680, 455))]);

        [$rider, $job] = $this->job();

        $this->actingAs($rider)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.0021&longitude=122.8172'
                .'&via[0][latitude]=9.9963&via[0][longitude]=122.8045')
            ->assertOk();

        // Three coordinate pairs in the path: start, the rider's via, destination.
        Http::assertSent(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $coordinates = substr($path, strrpos($path, '/') + 1);

            return substr_count($coordinates, ';') === 2
                && str_contains($coordinates, '122.8045,9.9963');
        });
    }

    public function test_a_router_outage_is_not_an_error_for_the_rider(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        [$rider, $job] = $this->job();

        // Empty routes, HTTP 200: the rider still has the pin and the address.
        $this->actingAs($rider)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.0021&longitude=122.8172')
            ->assertOk()
            ->assertJson(['routes' => []]);
    }

    public function test_a_booking_with_no_pin_says_so_rather_than_failing(): void
    {
        Http::preventStrayRequests();

        [$rider, $job] = $this->job();
        $job->update(['pickup_latitude' => null, 'pickup_longitude' => null]);

        $this->actingAs($rider)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.0021&longitude=122.8172')
            ->assertOk()
            ->assertJson(['routes' => [], 'reason' => 'no_destination_pin']);
    }

    public function test_a_rider_cannot_route_another_riders_job(): void
    {
        Http::preventStrayRequests();

        [, $job] = $this->job();

        $intruder = User::factory()->create([
            'role' => 'rider', 'branch_id' => $job->branch_id, 'status' => 'active', 'access' => [],
        ]);

        $this->actingAs($intruder)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.0021&longitude=122.8172')
            ->assertForbidden();
    }

    /** Deciding whether to take a run means seeing the way to it first. */
    public function test_a_rider_can_route_a_booking_that_is_still_free_to_take(): void
    {
        Http::fake(['*' => Http::response($this->osrmResponse())]);

        [, $job] = $this->job();
        $job->update(['rider_id' => null, 'status' => 'pending']);

        $sameBranchRider = User::factory()->create([
            'role' => 'rider', 'branch_id' => $job->branch_id, 'status' => 'active', 'access' => [],
        ]);

        $this->actingAs($sameBranchRider)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.0021&longitude=122.8172')
            ->assertOk()
            ->assertJsonCount(1, 'routes');
    }

    public function test_a_rider_cannot_route_an_unclaimed_booking_at_another_branch(): void
    {
        Http::preventStrayRequests();

        [, $job] = $this->job();
        $job->update(['rider_id' => null, 'status' => 'pending']);

        $elsewhere = Branch::query()->create([
            'name' => 'Ilog Satellite', 'code' => 'ILG', 'is_active' => true, 'machine_count' => 2,
        ]);

        $outsider = User::factory()->create([
            'role' => 'rider', 'branch_id' => $elsewhere->id, 'status' => 'active', 'access' => [],
        ]);

        $this->actingAs($outsider)
            ->getJson(route('rider.jobs.route', $job).'?latitude=10.0021&longitude=122.8172')
            ->assertForbidden();
    }

    public function test_deviation_distance_is_measured_from_the_line(): void
    {
        $line = [[122.8112, 9.9886], [122.8140, 9.9950]];

        // Sitting on the first vertex.
        $this->assertLessThan(1, Routing::metresFromLine(9.9886, 122.8112, $line));

        // Roughly 1.1km east of it — well past any reroute threshold.
        $onADifferentRoad = Routing::metresFromLine(9.9886, 122.8212, $line);
        $this->assertGreaterThan(500, $onADifferentRoad);
    }

    public function test_a_nonsense_coordinate_never_reaches_the_router(): void
    {
        Http::preventStrayRequests();

        $this->assertSame([], Routing::route([0.0, 0.0], [9.9886, 122.8112]));
        $this->assertSame([], Routing::route([9.9886, 122.8112], [999.0, 999.0]));
    }
}
