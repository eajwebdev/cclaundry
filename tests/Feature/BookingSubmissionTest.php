<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\PickupRequest;
use App\Models\ServicePreset;
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
            'payment_method' => 'cash',
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
            'is_addon' => true,
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
        $response->assertSee('Estimated weight', false);
        $response->assertSee('Optional Add-ons', false);
        $response->assertSee('finishing spray', false);
        $response->assertSee('Number of loads', false);
        $response->assertDontSee('fixed price', false);
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

    public function test_booking_reference_sequence_continues_on_the_next_day(): void
    {
        try {
            Carbon::setTestNow('2026-09-22 10:00:00');
            $this->post(route('booking.store'), $this->payload())
                ->assertSessionHasNoErrors();

            Carbon::setTestNow('2026-09-23 10:00:00');
            $this->post(route('booking.store'), $this->payload())
                ->assertSessionHasNoErrors();

            $references = PickupRequest::query()
                ->orderBy('id')
                ->pluck('reference_no')
                ->all();

            $this->assertSame(['PU-260922-0001', 'PU-260923-0002'], $references);
        } finally {
            Carbon::setTestNow();
        }
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
        $this->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee($reference)
            ->assertSee('Updates automatically');

        $before = $this->getJson(route('customer.bookings.index', ['live' => 1]))
            ->assertOk()
            ->assertJsonStructure(['signature'])
            ->json('signature');
        PickupRequest::query()->firstOrFail()->update(['status' => 'picked_up']);
        $after = $this->getJson(route('customer.bookings.index', ['live' => 1]))
            ->assertOk()
            ->json('signature');
        $this->assertNotSame($before, $after);
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
            'is_addon' => true,
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
        $this->assertSame('load', $addon->unit);

        // Add-ons sort last, whatever order they were ticked in.
        $this->assertTrue((bool) $booking->items->last()->is_addon);
    }

    /** Detergent on its own is not a booking: there is nothing to put it in. */
    public function test_an_addon_cannot_be_booked_without_a_laundry_service(): void
    {
        $category = LaundryServiceCategory::query()->create([
            'name' => Booking::ADDON_CATEGORY,
            'visibility' => 'all',
            'is_addon' => true,
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

    public function test_a_four_kilo_blanket_and_one_and_a_half_loads_of_conditioner_cost_235(): void
    {
        $category = LaundryServiceCategory::query()->create([
            'name' => Booking::ADDON_CATEGORY,
            'visibility' => 'all',
            'is_addon' => true,
            'sort_order' => 9,
            'is_active' => true,
        ]);

        $blankets = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Blankets, Comforters & Duvets',
            'pricing_type' => 'kilo',
            'price' => 55,
            'minimum_kilos' => 4,
            'is_active' => true,
            'show_on_landing' => true,
        ]);
        $mystique = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Downy Mystique',
            'pricing_type' => 'custom',
            'report_category' => 'fabcon',
            'price' => 10,
            'price_unit_label' => 'per load',
            'is_active' => true,
            'show_on_landing' => true,
        ]);
        $sunrise = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Downy Sunrise',
            'pricing_type' => 'custom',
            'report_category' => 'fabcon',
            'price' => 10,
            'price_unit_label' => 'per load',
            'is_active' => true,
            'show_on_landing' => true,
        ]);
        $freshSpray = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Fresh Finishing Spray',
            'pricing_type' => 'custom',
            'report_category' => 'finishing_spray',
            'price' => 5,
            'price_unit_label' => 'per load',
            'is_active' => true,
            'show_on_landing' => true,
        ]);
        $floralSpray = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'service_category_id' => $category->id,
            'name' => 'Floral Finishing Spray',
            'pricing_type' => 'custom',
            'report_category' => 'finishing_spray',
            'price' => 5,
            'price_unit_label' => 'per load',
            'is_active' => true,
            'show_on_landing' => true,
        ]);

        $page = $this->get(route('landing'))->assertOk();
        $page->assertSee('Downy Mystique')->assertSee('Downy Sunrise');
        $page->assertSee('Pickup &amp; delivery available. Free for orders', false);

        $this->post(route('booking.store'), $this->payload(['items' => [
            ['key' => 'service:'.$blankets->id, 'quantity' => 4],
            ['key' => 'service:'.$mystique->id, 'quantity' => 1.5],
        ]]))->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->firstOrFail();
        $this->assertSame('235.00', $booking->estimated_total);
        $this->assertSame(4.0, $booking->declaredKilos());

        $this->post(route('booking.store'), $this->payload(['items' => [
            ['key' => 'service:'.$blankets->id, 'quantity' => 4],
            ['key' => 'service:'.$mystique->id, 'quantity' => 1],
            ['key' => 'service:'.$sunrise->id, 'quantity' => 1],
        ]]))->assertSessionHasErrors('items');

        $this->post(route('booking.store'), $this->payload(['items' => [
            ['key' => 'service:'.$blankets->id, 'quantity' => 4],
            ['key' => 'service:'.$freshSpray->id, 'quantity' => 1],
            ['key' => 'service:'.$floralSpray->id, 'quantity' => 1],
        ]]))->assertSessionHasErrors('items');
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
            'items' => [['key' => 'service:'.$linens->id, 'quantity' => 12]],
        ]))->assertSessionHasNoErrors();

        $line = PickupRequest::query()->with('items')->firstOrFail()->items->first();

        // 12 kg is two loads of 10, so ₱700 — and the counter still sees the
        // 12 kg the customer declared.
        $this->assertSame('12.00', $line->quantity);
        $this->assertSame('2.00', $line->billable_quantity);
        $this->assertSame('700.00', $line->line_total);
    }

    /**
     * ₱350 a load, up to 10 kg a load: 1 to 10 kg is one load, 11 to 20 kg is
     * two, 21 kg starts a third.
     */
    public function test_every_started_load_is_charged_as_a_full_load(): void
    {
        foreach ([[1, 350], [9.5, 350], [10, 350], [10.5, 700], [11, 700], [20, 700], [21, 1050]] as [$kilos, $total]) {
            $this->assertSame((float) $total, Booking::lineTotal('load', 350, (float) $kilos, null, 10), "{$kilos} kg");
        }

        // Wash ₱85 and dry ₱95 a load: 8 kg is still one load, not two.
        foreach ([[8, 85, 95], [10, 85, 95], [11, 170, 190]] as [$kilos, $wash, $dry]) {
            $this->assertSame((float) $wash, Booking::lineTotal('load', 85, (float) $kilos), "wash {$kilos} kg");
            $this->assertSame((float) $dry, Booking::lineTotal('load', 95, (float) $kilos), "dry {$kilos} kg");
        }

        // A service with smaller machines sets its own load size.
        $this->assertSame(3.0, Booking::billableQuantity('load', 16, null, 7));
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

    /** The rider needs to know whether to expect cash or a transfer. */
    public function test_the_chosen_payment_method_is_stored_with_the_booking(): void
    {
        $this->post(route('booking.store'), $this->payload([
            'payment_method' => 'gcash',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('gcash', PickupRequest::query()->firstOrFail()->payment_method);
    }

    public function test_a_booking_must_say_how_it_will_be_paid(): void
    {
        $payload = $this->payload();
        unset($payload['payment_method']);

        $this->post(route('booking.store'), $payload)->assertSessionHasErrors('payment_method');

        $this->post(route('booking.store'), $this->payload(['payment_method' => 'bitcoin']))
            ->assertSessionHasErrors('payment_method');
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

    public function test_the_cashier_loads_a_collected_bag_by_tag_and_creates_its_job_order_in_pos(): void
    {
        $this->post(route('booking.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->firstOrFail();
        $booking->update([
            'status' => 'picked_up',
            'tag_code' => 'CC-123456',
            'collected_amount' => 150,
            'collected_payment_method' => 'cash',
        ]);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($cashier)
            ->get(route('admin.job-orders.create'))
            ->assertOk()
            ->assertSee('Bag tag # or search');

        $this->actingAs($cashier)
            ->get(route('admin.job-orders.create', ['tag_code' => 'cc-123456']))
            ->assertOk()
            ->assertSee('Loaded '.$booking->reference_no, false)
            ->assertSee('name="pickup_request_id" value="'.$booking->id.'"', false)
            ->assertSee('The customer declared 8 kg in total', false)
            ->assertSee('collected PHP 150.00 (Cash) at pickup', false);

        $orderPayload = [
            'branch_id' => $this->branch->id,
            'customer_id' => $booking->customer_id,
            'pickup_request_id' => $booking->id,
            'transaction_type' => 'delivery',
            'items' => [[
                'laundry_service_id' => $this->service->id,
                'description' => $this->service->name,
                'quantity' => 7,
                'unit_price' => 60,
            ]],
            'payment_type' => 'cash',
            'paid_amount' => 150,
            'send_sms' => 0,
        ];

        $this->post(route('admin.job-orders.store'), $orderPayload)
            ->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->firstOrFail();
        $this->assertSame($order->id, $booking->fresh()->job_order_id);
        $this->assertEquals(7, $order->items()->firstOrFail()->quantity);
        $this->assertEquals(150, $order->paid_amount);
        $this->assertEquals(270, $order->balance);

        $this->get(route('admin.job-orders.create', ['tag_code' => 'CC-123456']))
            ->assertRedirect(route('admin.job-orders.show', $order));

        $this->post(route('admin.job-orders.store'), $orderPayload)
            ->assertSessionHasErrors('pickup_request_id');
        $this->assertSame(1, JobOrder::query()->count());
    }

    public function test_customer_sees_each_stage_from_booking_through_delivery(): void
    {
        $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
        $booking = PickupRequest::query()->firstOrFail();
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('Pickup Booked!');

        $rider = User::factory()->create([
            'role' => 'rider',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => [],
        ]);
        $this->actingAs($rider)
            ->postJson(route('rider.jobs.claim', $booking), ['client_token' => 'take-bag'])
            ->assertOk();
        $this->actingAs($rider)
            ->patchJson(route('rider.jobs.status', $booking), [
                'status' => 'picked_up',
                'tag_code' => 'CC-FLOW-1',
                'client_token' => 'collect-bag',
            ])->assertOk();

        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders', 'cycles'],
        ]);
        $this->actingAs($cashier)
            ->get(route('admin.job-orders.create', ['tag_code' => 'CC-FLOW-1']))
            ->assertOk()
            ->assertSee('Loaded '.$booking->reference_no);
        $this->actingAs($cashier)
            ->post(route('admin.job-orders.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $booking->customer_id,
                'pickup_request_id' => $booking->id,
                'transaction_type' => 'delivery',
                'items' => [[
                    'laundry_service_id' => $this->service->id,
                    'description' => $this->service->name,
                    'quantity' => 8,
                    'unit_price' => 60,
                ]],
                'paid_amount' => 0,
                'send_sms' => 0,
            ])->assertRedirect(route('admin.job-orders.index'));

        $order = JobOrder::query()->firstOrFail();
        $this->assertSame($order->id, $booking->fresh()->job_order_id);

        foreach ([
            'wash' => ['washing', 'Your Laundry Is Washing'],
            'dry' => ['drying', 'Your Laundry Is Drying'],
            'iron' => ['ironing', 'Your Laundry Is Being Steamed'],
        ] as $cycleType => [$progress, $headline]) {
            $payload = ['cycle_type' => $cycleType];
            if ($cycleType !== 'iron') {
                $payload['machine_numbers'] = [1];
            }

            $this->actingAs($cashier)
                ->post(route('admin.cycles.store', $order), $payload)
                ->assertSessionHasNoErrors();

            $this->assertSame($progress, $booking->fresh()->customerProgressStatus());
            $this->get(route('booking.confirmed', $booking->reference_no))
                ->assertOk()
                ->assertSee($headline);
            $this->post(route('track'), [
                'reference_no' => $booking->reference_no,
                'phone' => $booking->contact_phone,
            ])->assertOk()->assertSee(ucfirst($progress) === 'Ironing' ? 'Ironing / Steaming' : ucfirst($progress));
            $this->actingAs($booking->customer, 'customer')
                ->get(route('customer.bookings.index'))
                ->assertOk()
                ->assertSee($progress === 'ironing' ? 'Ironing / Steaming' : ucfirst($progress));

            $cycle = $order->cycles()->where('cycle_type', $cycleType)->latest('id')->firstOrFail();
            $this->actingAs($cashier)
                ->patch(route('admin.cycles.end', $cycle))
                ->assertRedirect();
        }

        $this->actingAs($cashier)
            ->patch(route('admin.cycles.status', $order), ['status' => 'ready_for_delivery'])
            ->assertRedirect();
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('Ready for Delivery')
            ->assertSee('Ironing / Steaming');

        $this->actingAs($rider)
            ->patchJson(route('rider.jobs.status', $booking), [
                'status' => 'completed',
                'client_token' => 'deliver-bag',
            ])->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('All Done!');
        $this->actingAs($booking->customer, 'customer')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee($booking->reference_no)
            ->assertSee('Completed');
    }

    public function test_customer_sees_ready_for_pickup_when_branch_claim_was_chosen(): void
    {
        $this->post(route('booking.store'), $this->payload([
            'delivery_preference' => 'branch_pickup',
        ]))->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->firstOrFail();
        $booking->update(['status' => 'picked_up', 'picked_up_at' => now()]);
        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $booking->customer_id,
            'job_order_number' => 'JO-CLAIM-1',
            'status' => 'ready_for_pickup',
            'total' => 480,
            'balance' => 480,
        ]);
        $booking->jobOrder()->associate($order)->save();

        $this->assertSame('ready_for_pickup', $booking->fresh()->customerProgressStatus());
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('Ready for Pickup');
        $this->post(route('track'), [
            'reference_no' => $booking->reference_no,
            'phone' => $booking->contact_phone,
        ])->assertOk()->assertSee('Ready for pickup');
        $this->actingAs($booking->customer, 'customer')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee('Ready for pickup');
    }

    public function test_public_tracking_checks_current_status_without_exposing_another_booking(): void
    {
        $this->get(route('track.form'))->assertRedirect(route('landing').'#track');

        $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
        $booking = PickupRequest::query()->firstOrFail();
        $credentials = ['reference_no' => $booking->reference_no, 'phone' => $booking->contact_phone];

        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('Checking for updates automatically every 5 seconds')
            ->assertSee($booking->trackingVersion());

        $this->post(route('track'), $credentials)
            ->assertOk()
            ->assertSee('Checking for updates automatically every 5 seconds');

        $initial = $this->postJson(route('track.status'), $credentials)
            ->assertOk()
            ->assertJsonPath('status', 'pending');
        $this->postJson(route('track.status'), ['reference_no' => $booking->reference_no, 'phone' => '09000000000'])
            ->assertNotFound()
            ->assertDontSee($booking->reference_no);

        $booking->update(['status' => 'picked_up', 'picked_up_at' => now()]);
        $collected = $this->postJson(route('track.status'), $credentials)
            ->assertOk()
            ->assertJsonPath('status', 'picked_up');
        $this->assertNotSame($initial->json('version'), $collected->json('version'));

        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $booking->customer_id,
            'job_order_number' => 'JO-LIVE-TRACK',
            'status' => 'washing',
        ]);
        $booking->jobOrder()->associate($order)->save();
        $washing = $this->postJson(route('track.status'), $credentials)
            ->assertOk()
            ->assertJsonPath('status', 'washing');
        $this->assertNotSame($collected->json('version'), $washing->json('version'));

        $order->update(['status' => 'drying']);
        $this->postJson(route('track.status'), $credentials)
            ->assertOk()
            ->assertJsonPath('status', 'drying');
    }

    public function test_a_customer_booking_reaches_cashier_admin_and_rider_even_when_pickup_is_later(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders', 'pickup_requests'],
        ]);
        $adminBranch = Branch::query()->create([
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'branch_id' => $adminBranch->id,
            'access' => ['job_orders', 'pickup_requests'],
        ]);
        $rider = User::factory()->create([
            'role' => 'rider',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => [],
        ]);

        $this->actingAs($cashier)->get(route('admin.job-orders.create'))
            ->assertOk()
            ->assertSee('Pickup booking alerts');
        $this->actingAs($admin)->get(route('admin.job-orders.create'))
            ->assertOk()
            ->assertSee('Pickup booking alerts');

        $this->post(route('booking.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $booking = PickupRequest::query()->firstOrFail();

        foreach ([$cashier, $admin] as $staff) {
            $this->actingAs($staff)
                ->getJson(route('admin.pickup-requests.feed'))
                ->assertOk()
                ->assertJsonPath('pending', 1)
                ->assertJsonPath('waiting.0.id', $booking->id)
                ->assertJsonPath('waiting.0.reference_no', $booking->reference_no);
        }

        $riderFeed = $this->actingAs($rider)
            ->getJson(route('rider.runs'))
            ->assertOk();
        $this->assertContains($booking->id, $riderFeed->json('collect_ids'));
        $this->assertContains($booking->id, $riderFeed->json('available_ids'));
        $this->assertStringContainsString($booking->reference_no, $riderFeed->json('html'));

        $this->actingAs($rider)
            ->get(route('rider.index'))
            ->assertOk()
            ->assertSee('Pickup booking alerts');
        $this->actingAs($rider)
            ->getJson(route('rider.booking-alerts'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('waiting.0.id', $booking->id);
    }

    public function test_the_tag_lookup_only_loads_collected_pickups_from_the_cashiers_branch(): void
    {
        $this->post(route('booking.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->firstOrFail();
        $booking->update(['status' => 'picked_up', 'tag_code' => 'CC-654321']);
        $otherBranch = Branch::query()->create(['name' => 'Other Branch', 'code' => 'OTHER', 'is_active' => true]);
        $otherCashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $otherBranch->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($otherCashier)
            ->get(route('admin.job-orders.create', ['tag_code' => 'CC-654321']))
            ->assertOk()
            ->assertSee('No collected pickup with that bag tag was found for your branch.')
            ->assertDontSee('From booking '.$booking->reference_no);

        $booking->update(['status' => 'confirmed']);
        $homeCashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($homeCashier)
            ->get(route('admin.job-orders.create', ['tag_code' => 'CC-654321']))
            ->assertOk()
            ->assertSee('No collected pickup with that bag tag was found for your branch.');
    }

    public function test_rider_collection_adds_its_tag_to_the_live_pos_lookup(): void
    {
        $rider = User::factory()->create([
            'role' => 'rider',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'access' => [],
        ]);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders', 'pickup_requests'],
        ]);

        $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
        $booking = PickupRequest::query()->firstOrFail();

        $this->actingAs($rider)->postJson(route('rider.jobs.claim', $booking), [])->assertOk();
        $this->actingAs($cashier)->getJson(route('admin.job-orders.pickup-tags'))
            ->assertOk()->assertJsonPath('count', 0);

        $this->actingAs($rider)->patchJson(route('rider.jobs.status', $booking), [
            'status' => 'picked_up',
            'tag_code' => 'CC-LIVE-1',
        ])->assertOk();

        $feed = $this->actingAs($cashier)->getJson(route('admin.job-orders.pickup-tags'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('tags.0.tag_code', 'CC-LIVE-1')
            ->assertJsonPath('tags.0.reference_no', $booking->reference_no);
        $this->assertStringContainsString('no-store', (string) $feed->headers->get('Cache-Control'));
        $this->actingAs($cashier)->get(route('admin.job-orders.create'))
            ->assertOk()->assertSee('tag appears here within 5 seconds');
    }

    public function test_pos_tag_button_counts_only_collected_bags_waiting_for_a_job_order(): void
    {
        $bookings = collect();
        foreach (range(1, 3) as $number) {
            $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
            $bookings->push(PickupRequest::query()->latest('id')->firstOrFail());
        }

        $waiting = $bookings[0];
        $waiting->update(['status' => 'picked_up', 'tag_code' => 'CC-WAIT-1']);

        $converted = $bookings[1];
        $converted->update(['status' => 'picked_up', 'tag_code' => 'CC-DONE-1']);
        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $converted->customer_id,
            'job_order_number' => 'JO-COUNTED-1',
            'status' => 'pending',
        ]);
        $converted->jobOrder()->associate($order)->save();

        $bookings[2]->update(['status' => 'confirmed', 'tag_code' => 'CC-NOTCOLLECTED']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders'],
        ]);

        $response = $this->actingAs($cashier)
            ->get(route('admin.job-orders.create'))
            ->assertOk()
            ->assertSee('Load tag #')
            ->assertSee('role="dialog"', false);

        $this->assertSame(1, (int) $response->viewData('waitingTagCounts')->get($this->branch->id));
    }

    public function test_pos_tag_suggestions_show_nine_oldest_waiting_pickups_and_search_beyond_them(): void
    {
        $bookings = collect();
        foreach (range(1, 10) as $number) {
            $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
            $booking = PickupRequest::query()->latest('id')->firstOrFail();
            $booking->forceFill([
                'status' => 'picked_up',
                'tag_code' => sprintf('CC-%03d', $number),
                'created_at' => now()->subMinutes(11 - $number),
            ])->save();
            $bookings->push($booking);
        }

        $otherBranch = Branch::query()->create(['name' => 'Other Branch', 'code' => 'OTHER', 'is_active' => true]);
        $bookings[0]->replicate()->forceFill([
            'branch_id' => $otherBranch->id,
            'reference_no' => 'PU-OTHER-1',
            'tag_code' => 'CC-OTHER',
        ])->save();

        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders'],
        ]);

        $initial = $this->actingAs($cashier)
            ->getJson(route('admin.job-orders.pickup-tags', ['branch_id' => $otherBranch->id]))
            ->assertOk()
            ->assertJsonPath('count', 10)
            ->assertJsonCount(9, 'tags');

        $this->assertSame('CC-001', $initial->json('tags.0.tag_code'));
        $this->assertSame('CC-009', $initial->json('tags.8.tag_code'));

        $shortcut = $this->actingAs($cashier)
            ->get($initial->json('tags.0.url'))
            ->assertOk()
            ->assertSee('Loaded '.$bookings[0]->reference_no);
        $this->assertSame((string) $bookings[0]->customer_id, $shortcut->viewData('selectedCustomerId'));

        $this->actingAs($cashier)
            ->getJson(route('admin.job-orders.pickup-tags', ['search' => 'cc-010']))
            ->assertOk()
            ->assertJsonPath('count', 10)
            ->assertJsonCount(1, 'tags')
            ->assertJsonPath('tags.0.tag_code', 'CC-010');

        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $bookings[0]->customer_id,
            'job_order_number' => 'JO-STALE-SHORTCUT',
            'status' => 'pending',
        ]);
        $bookings[0]->jobOrder()->associate($order)->save();
        $this->actingAs($cashier)
            ->get($initial->json('tags.0.url'))
            ->assertRedirect(route('admin.job-orders.show', $order));
    }

    public function test_pos_tag_lookup_prefers_todays_waiting_bag_over_an_older_converted_bag(): void
    {
        $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
        $older = PickupRequest::query()->firstOrFail();
        $older->update([
            'status' => 'picked_up',
            'tag_code' => 'CC-REUSED',
            'tag_date' => now()->subDay()->toDateString(),
            'picked_up_at' => now()->subDay(),
        ]);
        $order = JobOrder::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $older->customer_id,
            'job_order_number' => 'JO-OLDER-TAG',
            'status' => 'completed',
        ]);
        $older->jobOrder()->associate($order)->save();

        $this->post(route('booking.store'), $this->payload())->assertSessionHasNoErrors();
        $current = PickupRequest::query()->latest('id')->firstOrFail();
        $current->update([
            'status' => 'picked_up',
            'tag_code' => 'CC-REUSED',
            'tag_date' => now()->toDateString(),
            'picked_up_at' => now(),
        ]);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders'],
        ]);

        $this->actingAs($cashier)
            ->get(route('admin.job-orders.create', ['tag_code' => 'CC-REUSED']))
            ->assertOk()
            ->assertSee('name="pickup_request_id" value="'.$current->id.'"', false)
            ->assertSee('Loaded '.$current->reference_no);
    }

    public function test_tag_lookup_includes_a_booked_service_bundle_in_the_pos_cart(): void
    {
        $preset = ServicePreset::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Full Service Bundle',
            'is_active' => true,
            'show_on_landing' => true,
        ]);
        $preset->items()->create([
            'laundry_service_id' => $this->service->id,
            'quantity' => 1,
        ]);

        $this->post(route('booking.store'), $this->payload([
            'items' => [['key' => 'preset:'.$preset->id, 'quantity' => 1]],
        ]))->assertSessionHasNoErrors();

        $booking = PickupRequest::query()->firstOrFail();
        $booking->update(['status' => 'picked_up', 'tag_code' => 'CC-BUNDLE']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $this->branch->id,
            'access' => ['job_orders'],
        ]);

        $response = $this->actingAs($cashier)
            ->get(route('admin.job-orders.create', ['tag_code' => 'CC-BUNDLE']))
            ->assertOk();

        $item = $response->viewData('bookedItems')->first();
        $this->assertSame('preset', $item['type']);
        $this->assertSame($preset->id, $item['id']);
        $this->assertSame('Full Service Bundle', $item['name']);
        $this->assertSame(60.0, (float) $item['price']);
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
