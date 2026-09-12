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
     * The landing page posts here. The booking is placed immediately, account
     * or no account: making someone sign up before their laundry is booked is
     * how a booking gets abandoned. A guest gets the same kind of record a
     * walk-in gets at the counter — contact details, no password — so the
     * branch has someone to call and the order has a home. Signing up later
     * with that number claims the record and everything booked under it.
     */
    public function store(Request $request)
    {
        $validated = $this->validateBooking($request);

        $customer = Auth::guard('customer')->user() ?: $this->customerFor($validated);

        $pickupRequest = $this->createFor($customer, $validated);

        // What lets a guest reopen this confirmation, and prefills the optional
        // sign-up, without a password or a link in an email we never asked for.
        $request->session()->put(Booking::RECENT_SESSION_KEY, [
            'reference_no' => $pickupRequest->reference_no,
            'contact_name' => $pickupRequest->contact_name,
            'contact_phone' => $pickupRequest->contact_phone,
            'contact_email' => $pickupRequest->contact_email,
            'pickup_address' => $pickupRequest->pickup_address,
            'branch_id' => $pickupRequest->branch_id,
        ]);

        return redirect()
            ->route('booking.confirmed', $pickupRequest->reference_no)
            ->with('success', 'Pickup booked. Reference '.$pickupRequest->reference_no.'.');
    }

    /**
     * Who a guest booking belongs to: the record we already hold for that
     * mobile number, or a new passwordless one. Never a new record for a number
     * we know, so a repeat guest keeps one history rather than collecting
     * duplicates the counter would have to merge.
     */
    private function customerFor(array $data): Customer
    {
        $existing = Customer::query()->matchingPhone($data['contact_phone'])->first();

        if ($existing) {
            return $existing;
        }

        return Customer::create([
            'branch_id' => $data['branch_id'],
            'name' => $data['contact_name'],
            'phone' => $data['contact_phone'],
            'email' => $data['contact_email'] ?? null,
            'address' => $data['pickup_address'],
            'latitude' => $data['pickup_latitude'] ?? null,
            'longitude' => $data['pickup_longitude'] ?? null,
            'billing_type' => 'regular',
            'is_active' => true,
        ]);
    }

    /**
     * The confirmation screen, reachable without an account: a guest has none
     * to sign in to. Shown for the booking this browser just placed, or one the
     * signed-in customer owns; anyone else is sent to public tracking, which
     * asks for the reference and the mobile number it was booked with.
     */
    public function confirmed(Request $request, string $reference)
    {
        $pickupRequest = PickupRequest::query()
            ->with(['branch', 'jobOrder'])
            ->where('reference_no', $reference)
            ->firstOrFail();

        $recent = $request->session()->get(Booking::RECENT_SESSION_KEY, []);
        $customer = Auth::guard('customer')->user();

        $mayView = ($recent['reference_no'] ?? null) === $pickupRequest->reference_no
            || ($customer && $pickupRequest->customer_id === $customer->id);

        if (! $mayView) {
            return redirect()
                ->to(route('landing').'#track')
                ->with('info', 'Enter your booking number and the mobile number you booked with to see your laundry.');
        }

        return view('customer.booking-confirmed', [
            'settings' => SystemSetting::current(),
            'pickupRequest' => $pickupRequest,
        ]);
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
            // Length alone would accept "asdf". The branch has to be able to
            // ring this number back, so it must normalise to a real PH mobile.
            'contact_phone' => ['required', 'string', 'max:40', function ($attribute, $value, $fail) {
                if (! preg_match('/^09\d{9}$/', Customer::normalizePhone($value))) {
                    $fail('Enter a mobile number we can reach you on, like 0917 123 4567.');
                }
            }],
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
            'branch_id.required' => 'Please choose the branch nearest you.',
            'contact_name.required' => 'Please enter your full name so we know who to ask for.',
            'contact_phone.required' => 'Please enter your mobile number.',
            'contact_email.email' => 'That email address does not look right.',
            'pickup_address.required' => 'Please tell us where to collect: house, street and barangay.',
            'pickup_date.required' => 'Please choose a pickup date.',
            'pickup_slot.required' => 'Please choose the time that suits you.',
            'delivery_preference.required' => 'Please tell us how you want your laundry back.',
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
