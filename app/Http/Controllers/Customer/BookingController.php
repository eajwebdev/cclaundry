<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PickupRequest;
use App\Support\Geocoder;
use App\Models\SystemSetting;
use App\Support\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    /**
     * The landing page posts here. A booking always needs an account behind it
     * (that is what lets the customer track it, and what lets the branch call
     * them back) but we never make them sign up before they have seen the form.
     * We validate first, park the booking in the session, and send them through
     * sign-up. Once they are in, the booking is created automatically.
     */
    public function store(Request $request)
    {
        $validated = $this->validateBooking($request);

        $customer = Auth::guard('customer')->user();

        if (! $customer) {
            $request->session()->put(Booking::PENDING_SESSION_KEY, $validated);

            $existing = Customer::query()
                ->matchingPhone($validated['contact_phone'])
                ->first();

            // Someone who already booked with us goes to sign-in; a first-time
            // customer, and a walk-in whose record has no password yet, both go
            // to sign-up (the sign-up screen claims the walk-in record).
            $hasAccount = $existing && $existing->hasPortalAccount();

            return redirect()
                ->to($hasAccount ? route('customer.login') : route('customer.register'))
                ->with('info', $hasAccount
                    ? 'Welcome back. Sign in and we will place the pickup you just filled out.'
                    : 'Almost there. Create your free account and we will confirm this pickup right away.');
        }

        $pickupRequest = $this->createFor($customer, $validated);

        return redirect()
            ->route('customer.bookings.show', $pickupRequest)
            ->with('success', 'Pickup booked. Reference '.$pickupRequest->reference_no.'.');
    }

    /**
     * Replays a booking parked in the session once the customer has an account.
     * Called right after register and after login.
     */
    public static function flushPending(Request $request, Customer $customer): ?PickupRequest
    {
        $payload = $request->session()->pull(Booking::PENDING_SESSION_KEY);

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return (new self)->createFor($customer, $payload);
    }

    public function show(Request $request, PickupRequest $pickupRequest)
    {
        abort_unless($pickupRequest->customer_id === Auth::guard('customer')->id(), 403);

        return view('customer.booking-confirmed', [
            'settings' => SystemSetting::current(),
            'pickupRequest' => $pickupRequest->load(['branch', 'jobOrder']),
        ]);
    }

    public function index(Request $request)
    {
        $customer = Auth::guard('customer')->user();

        return view('customer.orders', [
            'settings' => SystemSetting::current(),
            'customer' => $customer,
            'requests' => PickupRequest::query()
                ->with(['branch', 'jobOrder'])
                ->where('customer_id', $customer->id)
                ->latest()
                ->paginate(10),
            'jobOrders' => $customer->jobOrders()
                ->with('branch')
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function cancel(Request $request, PickupRequest $pickupRequest)
    {
        abort_unless($pickupRequest->customer_id === Auth::guard('customer')->id(), 403);

        if (! $pickupRequest->isCancellable()) {
            return back()->with('error', 'This booking can no longer be cancelled. Please call the branch.');
        }

        $pickupRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled by customer',
        ]);

        return back()->with('success', 'Booking '.$pickupRequest->reference_no.' has been cancelled.');
    }

    private function createFor(Customer $customer, array $data): PickupRequest
    {
        return DB::transaction(function () use ($customer, $data) {
            $wantsDelivery = ($data['delivery_preference'] ?? 'deliver') === 'deliver';

            // Snapshot what was booked: a later rename, reprice or preset edit
            // must not rewrite what this customer actually agreed to.
            $offering = Booking::resolveOffering($data['offering'] ?? null);
            $service = $offering['service'] ?? null;
            $preset = $offering['preset'] ?? null;
            $booked = $preset ?: $service;

            $pickupRequest = PickupRequest::create([
                'reference_no' => PickupRequest::nextReference(),
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'],
                'laundry_service_id' => $service?->id,
                'service_preset_id' => $preset?->id,
                'service_name' => $preset?->name ?: $service?->name,
                'service_price' => $preset ? $preset->totalPrice() : $service?->price,
                'service_pricing_type' => $preset ? 'preset' : $service?->pricing_type,
                'estimated_kilos' => $data['estimated_kilos'] ?? null,
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? $customer->email,
                'pickup_address' => $data['pickup_address'],
                'pickup_latitude' => $data['pickup_latitude'] ?? null,
                'pickup_longitude' => $data['pickup_longitude'] ?? null,
                'pickup_landmark' => $data['pickup_landmark'] ?? null,
                'pickup_date' => $data['pickup_date'],
                'pickup_slot' => $data['pickup_slot'],
                'delivery_preference' => $data['delivery_preference'],
                'delivery_address' => $wantsDelivery
                    ? (($data['delivery_address'] ?? null) ?: $data['pickup_address'])
                    : null,
                // Delivery falls back to the pickup pin, matching how the
                // delivery address already falls back to the pickup address.
                'delivery_latitude' => $wantsDelivery
                    ? (($data['delivery_latitude'] ?? null) ?: ($data['pickup_latitude'] ?? null))
                    : null,
                'delivery_longitude' => $wantsDelivery
                    ? (($data['delivery_longitude'] ?? null) ?: ($data['pickup_longitude'] ?? null))
                    : null,
                'delivery_date' => $wantsDelivery ? ($data['delivery_date'] ?? null) : null,
                'delivery_slot' => $wantsDelivery ? ($data['delivery_slot'] ?? null) : null,
                'is_rush' => (bool) ($data['is_rush'] ?? false),
                'notes' => $data['notes'] ?? null,
                'estimated_total' => Booking::estimate(
                    $booked,
                    isset($data['estimated_kilos']) ? (float) $data['estimated_kilos'] : null,
                    (bool) ($data['is_rush'] ?? false)
                ),
                'status' => 'pending',
            ]);

            // Keep the customer record current with whatever they just told us,
            // so the counter is not working from stale contact details.
            $customer->forceFill(array_filter([
                'phone' => $customer->phone ?: $data['contact_phone'],
                'address' => $customer->address ?: $data['pickup_address'],
                'email' => $customer->email ?: ($data['contact_email'] ?? null),
                // So a repeat booking opens on their own house rather than the
                // city centre.
                'latitude' => $customer->latitude ?: ($data['pickup_latitude'] ?? null),
                'longitude' => $customer->longitude ?: ($data['pickup_longitude'] ?? null),
            ]))->save();

            return $pickupRequest;
        });
    }

    private function validateBooking(Request $request): array
    {
        $rules = [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'offering' => ['required', 'string', Rule::in(Booking::offeringKeys($request->integer('branch_id')))],
            'estimated_kilos' => ['nullable', 'numeric', 'min:1', 'max:200'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'pickup_address' => ['required', 'string', 'max:500'],
            // The pin is optional on purpose. Kabankalan addresses are given by
            // landmark far more often than by street number, and a booking must
            // never be blocked because the map would not load on someone's phone.
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_landmark' => ['nullable', 'string', 'max:150'],
            'pickup_date' => ['required', 'date', 'after_or_equal:'.Booking::earliestPickupDate(), 'before_or_equal:'.Booking::latestPickupDate()],
            'pickup_slot' => ['required', Rule::in(array_keys(Booking::slots()))],
            'delivery_preference' => ['required', Rule::in(array_keys(Booking::deliveryPreferences()))],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:pickup_date', 'before_or_equal:'.Booking::latestPickupDate()],
            'delivery_slot' => ['nullable', Rule::in(array_keys(Booking::slots()))],
            'is_rush' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        $messages = [
            'pickup_date.after_or_equal' => 'The earliest pickup we can promise is tomorrow.',
            'delivery_date.after_or_equal' => 'Delivery cannot be scheduled before the pickup.',
            'branch_id.exists' => 'Please choose one of our active branches.',
            'offering.in' => 'That service is not available at the branch you picked. Please choose another.',
            'offering.required' => 'Please choose a service.',
        ];

        $validated = $request->validate($rules, $messages);

        $validated['contact_phone'] = Customer::normalizePhone($validated['contact_phone']);
        $validated['is_rush'] = $request->boolean('is_rush');

        // One coordinate without the other is not a location. Drop the pair
        // rather than storing half a pin a rider would be sent to follow.
        foreach (['pickup', 'delivery'] as $leg) {
            if (! Geocoder::isValidCoordinate(
                $validated[$leg.'_latitude'] ?? null,
                $validated[$leg.'_longitude'] ?? null
            )) {
                $validated[$leg.'_latitude'] = null;
                $validated[$leg.'_longitude'] = null;
            }
        }

        return $validated;
    }
}
