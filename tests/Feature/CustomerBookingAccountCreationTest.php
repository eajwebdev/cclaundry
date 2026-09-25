<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use App\Support\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBookingAccountCreationTest extends TestCase
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
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $this->service = LaundryService::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Wash Dry Fold',
            'pricing_type' => 'kilo',
            'price' => 35,
            'is_active' => true,
            'show_on_landing' => true,
        ]);
    }

    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'items' => [['key' => 'service:'.$this->service->id, 'quantity' => 7]],
            'contact_name' => 'Pedro Penduko',
            'contact_phone' => '0919 888 7766',
            'contact_email' => 'pedro@example.com',
            'pickup_address' => 'Purok Mangga, Kabankalan City',
            'pickup_date' => now()->addDays(2)->toDateString(),
            'pickup_slot' => '09_10',
            'delivery_preference' => 'deliver',
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function test_guest_sees_create_account_popup_at_end_of_booking(): void
    {
        $this->post(route('booking.store'), $this->bookingPayload())
            ->assertRedirect();

        $booking = PickupRequest::query()->latest('id')->firstOrFail();

        // Visiting confirmation page as guest shows the create account modal popup
        $response = $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('Create your account')
            ->assertSee('Create Account &amp; View Booking', false)
            ->assertSee($booking->contact_name)
            ->assertSee($booking->contact_phone);

        $this->assertFalse(auth('customer')->check());
    }

    public function test_guest_creating_account_from_popup_auto_logs_in_and_sees_booking(): void
    {
        $this->post(route('booking.store'), $this->bookingPayload());
        $booking = PickupRequest::query()->latest('id')->firstOrFail();

        // Submit register from the popup on the confirmation page
        $response = $this->post(route('customer.register.submit'), [
            'name' => 'Pedro Penduko',
            'phone' => '09198887766',
            'email' => 'pedro@example.com',
            'address' => 'Purok Mangga, Kabankalan City',
            'branch_id' => $this->branch->id,
            'password' => 'supersecret123',
            'password_confirmation' => 'supersecret123',
            'terms' => '1',
            'redirect_to' => route('booking.confirmed', $booking->reference_no),
        ]);

        // Redirects straight to the booking confirmation
        $response->assertRedirect(route('booking.confirmed', $booking->reference_no));

        // Customer is now authenticated
        $this->assertTrue(auth('customer')->check());
        $customer = auth('customer')->user();
        $this->assertSame('Pedro Penduko', $customer->name);
        $this->assertTrue($customer->hasPortalAccount());

        // Following redirect shows booking as owner
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('View all my bookings')
            ->assertDontSee('Account Required');
    }

    public function test_existing_customer_logging_in_from_popup_auto_logs_in_and_sees_booking(): void
    {
        // Existing customer with an account
        $customer = Customer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Maria Clara',
            'phone' => '09181234567',
            'password' => 'secret1234',
            'billing_type' => 'regular',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Books while not logged in
        $this->post(route('booking.store'), $this->bookingPayload([
            'contact_name' => 'Maria Clara',
            'contact_phone' => '09181234567',
        ]));
        $booking = PickupRequest::query()->latest('id')->firstOrFail();

        // Guest visits confirmation page: popup renders with Sign in option
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('Sign in to your account')
            ->assertSee('Sign In &amp; View Booking', false);

        // Sign in from the modal
        $response = $this->post(route('customer.login.submit'), [
            'login' => '09181234567',
            'password' => 'secret1234',
            'redirect_to' => route('booking.confirmed', $booking->reference_no),
        ]);

        $response->assertRedirect(route('booking.confirmed', $booking->reference_no));
        $this->assertTrue(auth('customer')->check());
        $this->assertSame($customer->id, auth('customer')->id());

        // Now viewing as owner
        $this->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('View all my bookings');
    }

    public function test_already_logged_in_customer_sees_booking_directly_without_modal(): void
    {
        $customer = Customer::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Simoun Ibarra',
            'phone' => '09179998877',
            'password' => 'secret1234',
            'billing_type' => 'regular',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('booking.store'), $this->bookingPayload([
                'contact_name' => 'Simoun Ibarra',
                'contact_phone' => '09179998877',
            ]));

        $booking = PickupRequest::query()->latest('id')->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->get(route('booking.confirmed', $booking->reference_no))
            ->assertOk()
            ->assertSee('View all my bookings')
            ->assertDontSee('Account Required');
    }
}
