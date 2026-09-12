<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Support\Booking;
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
            'offering' => 'service:'.$this->service->id,
            'contact_name' => 'Juan Dela Cruz',
            'contact_phone' => '09171234567',
            'pickup_address' => 'Purok 3, Brgy. Uno, Kabankalan City',
            'pickup_date' => now()->addDays(2)->toDateString(),
            'pickup_slot' => array_key_first(Booking::slots()),
            'delivery_preference' => 'deliver',
        ], $overrides);
    }

    public function test_every_important_field_is_named_when_the_form_arrives_empty(): void
    {
        $this->post(route('booking.store'), [])->assertSessionHasErrors([
            'branch_id',
            'offering',
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
        $this->post(route('booking.store'), $this->payload(['estimated_kilos' => 500]))
            ->assertSessionHasErrors('estimated_kilos');
    }

    public function test_a_pickup_cannot_be_booked_for_today(): void
    {
        $this->post(route('booking.store'), $this->payload(['pickup_date' => now()->toDateString()]))
            ->assertSessionHasErrors('pickup_date');
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
