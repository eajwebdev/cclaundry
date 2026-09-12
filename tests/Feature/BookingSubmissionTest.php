<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Models\User;
use App\Support\Booking;
use Carbon\Carbon;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * The public booking form: what a customer sees when something is missing or
 * unusable, that booking never requires an account, and that a token gone
 * stale while they typed does not cost them the booking.
 */
class BookingSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private LaundryService $service;

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
            'primary_color' => '#A07148',
            'is_completed' => true,
        ]);

        SystemTrialSetting::query()->create([
            'trial_enabled' => true,
            'trial_start_date' => now()->subDay()->toDateString(),
            'trial_end_date' => now()->addDay()->toDateString(),
            'trial_status' => 'active',
            'grace_period_days' => 0,
        ]);

        $this->branch = Branch::query()->create([
            'name' => 'Kabankalan Main',
            'code' => 'KBK',
            'address' => 'Guanzon Street, Kabankalan City',
            'is_active' => true,
            'machine_count' => 3,
        ]);

        $this->service = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Wash 7kg',
            'pricing_type' => 'kilo',
            'price' => 60,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 1,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'items' => [
                ['key' => 'service:'.$this->service->id, 'quantity' => 8],
            ],
            'contact_name' => 'Juan Dela Cruz',
            'contact_phone' => '09171234567',
            'pickup_address' => 'Purok 3, Brgy. Uno, Kabankalan City',
            'pickup_date' => now()->addDays(2)->toDateString(),
            'pickup_slot' => array_key_first(Booking::slots()),
            'delivery_preference' => 'deliver',
        ], $overrides);
    }

    /**
     * The form asks for each service on its own terms, and keeps the add-ons in
     * their own tagged section below — rather than one weight for the lot.
     */
    public function test_the_form_asks_per_service_amounts_and_tags_the_addons(): void
    {
        $category = LaundryServiceCategory::query()->create([
            'name' => Booking::ADDON_CATEGORY,
            'visibility' => 'all',
            'sort_order' => 9,
            'is_active' => true,
        ]);

        LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Downy Mystique Fabcon',
            'pricing_type' => 'custom',
            'price' => 10,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 5,
        ]);

        $response = $this->get(route('landing'))->assertOk();

        $response->assertSee('What would you like us to clean?', false);
        $response->assertSee('How many kilos?', false);
        $response->assertSee('Detergent &amp; fabric conditioner', false);
        $response->assertSee('Add-on', false);

        // The one-weight-for-everything question is gone: each chosen service
        // now carries its own amount.
        $response->assertDontSee('How much laundry do you have?', false);

        // An add-on is never offered as the laundry itself.
        $this->assertSame(['Wash 7kg'], Booking::services()->pluck('name')->all());
        $this->assertSame(['Downy Mystique Fabcon'], Booking::addons()->pluck('name')->all());
    }

    public function test_every_important_field_is_named_when_the_form_arrives_empty(): void
    {
        $this->post(route('booking.store'), [])->assertSessionHasErrors([
            'branch_id',
            'items',
            'contact_name',
            'contact_phone',
            'pickup_address',
            'pickup_date',
            'pickup_slot',
            'delivery_preference',
        ]);
    }

    /** The branch has to be able to ring the customer back, so length alone is not enough. */
    public function test_a_mobile_number_that_could_not_be_called_is_rejected(): void
    {
        foreach (['not a phone', '12345', '0917123456'] as $unusable) {
            $this->post(route('booking.store'), $this->payload(['contact_phone' => $unusable]))
                ->assertSessionHasErrors('contact_phone');
        }

        $this->assertSame(0, PickupRequest::query()->count());
    }

    public function test_the_ways_people_write_their_number_are_all_accepted(): void
    {
        foreach (['09171234567', '0917 123 4567', '+63 917 123 4567'] as $typed) {
            $this->post(route('booking.store'), $this->payload(['contact_phone' => $typed]))
                ->assertSessionHasNoErrors();

            $this->flushSession();
        }

        // Stored one way however it was typed, and all three land on the one
        // customer record rather than three the counter would have to merge.
        $this->assertSame(3, PickupRequest::query()->where('contact_phone', '09171234567')->count());
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_booking_is_placed_without_an_account(): void
    {
        $response = $this->post(route('booking.store'), $this->payload());

        $pickupRequest = PickupRequest::query()->firstOrFail();

        $response->assertRedirect(route('booking.confirmed', $pickupRequest->reference_no));
        $this->assertSame('pending', $pickupRequest->status);
        $this->assertSame('Juan Dela Cruz', $pickupRequest->contact_name);

        // It hangs off a counter-style record: contact details, no password, so
        // nothing about it asks the customer to sign up.
        $customer = Customer::query()->findOrFail($pickupRequest->customer_id);
        $this->assertFalse($customer->hasPortalAccount());
        $this->assertSame('09171234567', $customer->phone);
    }

    public function test_the_confirmation_opens_for_the_booking_this_browser_just_made(): void
    {
        $this->post(route('booking.store'), $this->payload());

        $reference = PickupRequest::query()->value('reference_no');

        $this->get(route('booking.confirmed', $reference))
            ->assertOk()
            ->assertSee($reference);
    }

    /** A guessed reference reveals nothing; public tracking asks for the phone too. */
    public function test_someone_elses_booking_reference_is_not_browsable(): void
    {
        $this->post(route('booking.store'), $this->payload());

        $reference = PickupRequest::query()->value('reference_no');

        $this->flushSession();

        $this->get(route('booking.confirmed', $reference))
            ->assertRedirect(route('landing').'#track');
    }

    /** Signing up later is what claims the guest bookings, rather than gating them. */
    public function test_signing_up_afterwards_keeps_the_bookings_made_as_a_guest(): void
    {
        $this->post(route('booking.store'), $this->payload());

        $reference = PickupRequest::query()->value('reference_no');

        $this->post(route('customer.register.submit'), [
            'name' => 'Juan Dela Cruz',
            'phone' => '0917 123 4567',
            'branch_id' => $this->branch->id,
            'password' => 'laundry-secret',
            'password_confirmation' => 'laundry-secret',
            'terms' => '1',
        ])->assertRedirect(route('customer.bookings.index'));

        $this->assertSame(1, Customer::query()->count());
        $customer = Customer::query()->firstOrFail();
        $this->assertTrue($customer->hasPortalAccount());

        // The booking placed as a guest is on the account, not stranded.
        $this->get(route('customer.bookings.index'))->assertOk()->assertSee($reference);
    }

    public function test_a_weight_outside_what_we_can_wash_is_refused(): void
    {
        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$this->service->id, 'quantity' => 500]],
        ]))->assertSessionHasErrors('items.0.quantity');
    }

    /** A service with nothing said about how much of it cannot be priced or weighed. */
    public function test_a_service_with_no_amount_is_refused(): void
    {
        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$this->service->id, 'quantity' => '']],
        ]))->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(0, PickupRequest::query()->count());
    }

    /**
     * The point of the whole change: a regular load and a comforter in one
     * booking, each weighed on its own terms, with the detergent alongside.
     */
    public function test_several_services_and_an_addon_travel_in_one_booking(): void
    {
        $comforters = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Comforters',
            'pricing_type' => 'kilo',
            'price' => 55,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 2,
        ]);

        $category = LaundryServiceCategory::query()->create([
            'name' => Booking::ADDON_CATEGORY,
            'visibility' => 'all',
            'sort_order' => 9,
            'is_active' => true,
        ]);

        $downy = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Downy Mystique Fabcon',
            'pricing_type' => 'custom',
            'price' => 10,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 3,
        ]);

        $this->post(route('booking.store'), $this->payload([
            'items' => [
                ['key' => 'service:'.$this->service->id, 'quantity' => 8],
                ['key' => 'service:'.$comforters->id, 'quantity' => 4],
                ['key' => 'service:'.$downy->id, 'quantity' => 2],
            ],
        ]))->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->with('items')->firstOrFail();

        $this->assertCount(3, $booking->items);

        // 8 × 60 + 4 × 55 + 2 × 10 = 720.
        $this->assertSame('720.00', $booking->estimated_total);

        // Only what goes on the scale counts towards the weight; the fabcon
        // is two sachets, not two kilos.
        $this->assertSame(12.0, $booking->declaredKilos());

        $addon = $booking->items->firstWhere('is_addon', true);
        $this->assertSame('Downy Mystique Fabcon', $addon->service_name);
        $this->assertSame('qty', $addon->unit);

        // Add-ons sort last, whatever order they were ticked in.
        $this->assertTrue((bool) $booking->items->last()->is_addon);
    }

    /** Detergent on its own is not a booking: there is nothing to put it in. */
    public function test_an_addon_cannot_be_booked_without_a_laundry_service(): void
    {
        $category = LaundryServiceCategory::query()->create([
            'name' => Booking::ADDON_CATEGORY,
            'visibility' => 'all',
            'sort_order' => 9,
            'is_active' => true,
        ]);

        $downy = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Downy Mystique Fabcon',
            'pricing_type' => 'custom',
            'price' => 10,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 3,
        ]);

        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$downy->id, 'quantity' => 2]],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, PickupRequest::query()->count());
    }

    /** A load-priced service is asked for in kilos and billed by the load. */
    public function test_kilos_on_a_load_priced_service_become_whole_loads(): void
    {
        $linens = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Bed Sheets & Towels',
            'pricing_type' => 'load',
            'price' => 350,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 2,
        ]);

        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$linens->id, 'quantity' => 9]],
        ]))->assertSessionHasNoErrors();

        $line = PickupRequest::query()->with('items')->firstOrFail()->items->first();

        // 9 kg is two loads of 7, so ₱700 — and the counter still sees the 9 kg
        // the customer declared.
        $this->assertSame('9.00', $line->quantity);
        $this->assertSame('2.00', $line->billable_quantity);
        $this->assertSame('700.00', $line->line_total);
    }

    /** Under the minimum is charged at the minimum, as it is at the counter. */
    public function test_a_kilo_service_below_its_minimum_is_charged_the_minimum(): void
    {
        $regular = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'price' => 30,
            'minimum_kilos' => 5,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 3,
        ]);

        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$regular->id, 'quantity' => 3]],
        ]))->assertSessionHasNoErrors();

        $request = PickupRequest::query()->with('items')->firstOrFail();
        $line = $request->items->first();

        // 3 kg at a 5 kg minimum is 5 x P30, and the counter still sees the
        // 3 kg the customer declared.
        $this->assertSame('3.00', $line->quantity);
        $this->assertSame('5.00', $line->billable_quantity);
        $this->assertSame('150.00', $line->line_total);
        $this->assertSame('150.00', $request->estimated_total);
    }

    /** At or above the minimum, the weight is simply the weight. */
    public function test_a_kilo_service_at_or_above_its_minimum_is_charged_by_weight(): void
    {
        $regular = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Regular Laundry',
            'pricing_type' => 'kilo',
            'price' => 30,
            'minimum_kilos' => 5,
            'is_active' => true,
            'show_on_landing' => true,
            'landing_sort_order' => 3,
        ]);

        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$regular->id, 'quantity' => 8]],
        ]))->assertSessionHasNoErrors();

        $line = PickupRequest::query()->with('items')->firstOrFail()->items->first();

        $this->assertSame('8.00', $line->billable_quantity);
        $this->assertSame('240.00', $line->line_total);
    }

    /** A service with no minimum set is untouched by the rule. */
    public function test_a_kilo_service_without_a_minimum_is_charged_by_weight(): void
    {
        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$this->service->id, 'quantity' => 2]],
        ]))->assertSessionHasNoErrors();

        $line = PickupRequest::query()->with('items')->firstOrFail()->items->first();

        $this->assertSame('2.00', $line->billable_quantity);
        $this->assertSame('120.00', $line->line_total);
    }

    /** Someone who rings at breakfast wants the bag gone before lunch. */
    public function test_a_pickup_can_be_booked_for_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 07:00'));

        $this->post(route('booking.store'), $this->payload([
            'pickup_date' => now()->toDateString(),
            'pickup_slot' => '08_09',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(now()->toDateString(), PickupRequest::query()->firstOrFail()->pickup_date->toDateString());

        Carbon::setTestNow();
    }

    /** The van for that window has already gone. */
    public function test_a_pickup_window_that_has_closed_today_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 11:30'));

        $this->post(route('booking.store'), $this->payload([
            'pickup_date' => now()->toDateString(),
            'pickup_slot' => '08_09',
        ]))->assertSessionHasErrors('pickup_slot');

        // The 11 AM run, on the same day, has not finished yet.
        $this->post(route('booking.store'), $this->payload([
            'pickup_date' => now()->toDateString(),
            'pickup_slot' => '11_12',
        ]))->assertSessionHasNoErrors();

        Carbon::setTestNow();
    }

    public function test_a_pickup_cannot_be_booked_for_a_past_date(): void
    {
        $this->post(route('booking.store'), $this->payload(['pickup_date' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors('pickup_date');
    }

    /**
     * The counter opens the booking in the POS and finds the cart already
     * holding what the customer said they were sending, with the amounts they
     * entered shown against it — the figures to check on the scale.
     */
    public function test_the_pos_opens_a_booking_with_the_declared_amounts_to_check(): void
    {
        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'service:'.$this->service->id, 'quantity' => 8]],
        ]))->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->firstOrFail();

        $staff = User::factory()->create([
            'role' => 'super_admin',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($staff)
            ->get(route('admin.job-orders.create', [
                'branch_id' => $this->branch->id,
                'customer_id' => $booking->customer_id,
                'pickup_request_id' => $booking->id,
            ]))
            ->assertOk();

        $response->assertSee('From booking '.$booking->reference_no, false);
        $response->assertSee('The customer declared 8 kg in total', false);

        // The line itself reaches the cart, so nothing is retyped from paper.
        $response->assertSee('8 kg', false);
        $response->assertSee('pickup_request_id', false);
    }

    /**
     * A page left open outlives its CSRF token. Laravel's own answer is a blank
     * "Page Expired" screen, which throws a finished booking away.
     */
    public function test_an_expired_token_hands_the_form_back_with_everything_typed(): void
    {
        $session = $this->app['session.store'];
        $session->start();

        $request = Request::create('/book', 'POST', [
            'contact_name' => 'Juan Dela Cruz',
            'pickup_address' => 'Purok 3, Brgy. Uno, Kabankalan City',
            'password' => 'never-flash-this',
        ]);
        $request->headers->set('referer', url('/'));
        $request->setLaravelSession($session);

        $response = $this->app[ExceptionHandler::class]->render(
            $request,
            new TokenMismatchException('CSRF token mismatch.')
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/'), $response->getTargetUrl());

        $this->assertStringContainsString('timed out', (string) $session->get('error'));
        $this->assertSame('Juan Dela Cruz', $session->get('_old_input')['contact_name'] ?? null);
        $this->assertArrayNotHasKey('password', $session->get('_old_input', []));
    }

    public function test_the_form_can_fetch_a_fresh_token_before_submitting(): void
    {
        $response = $this->getJson(route('csrf.token'))->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
