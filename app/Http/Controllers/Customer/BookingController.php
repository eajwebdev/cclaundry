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
use Illuminate\Validation\ValidationException;

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
            ->with(['items', 'branch', 'jobOrder'])
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
            'pickupRequest' => $pickupRequest->load(['items', 'branch', 'jobOrder']),
        ]);
    }

    public function index(Request $request)
    {
        $customer = Auth::guard('customer')->user();

        return view('customer.orders', [
            'settings' => SystemSetting::current(),
            'customer' => $customer,
            'requests' => PickupRequest::query()
                ->with(['items', 'branch', 'jobOrder'])
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

    /**
     * The booking's lines, snapshotted: a later rename, reprice or preset edit
     * must not rewrite what this customer actually agreed to.
     *
     * Add-ons are pushed to the end whatever order they were ticked in, so the
     * bag reads the way the form did: the washing first, the extras with it.
     *
     * @return list<array<string, mixed>>
     */
    private function linesFor(array $data): array
    {
        $addonIds = Booking::addons((int) $data['branch_id'])->pluck('id')->all();
        $lines = [];

        foreach (array_values($data['items']) as $position => $item) {
            $offering = Booking::resolveOffering($item['key']);

            if (! $offering) {
                continue;
            }

            $service = $offering['service'];
            $preset = $offering['preset'];
            $pricingType = $preset ? 'preset' : $service->pricing_type;
            $price = $preset ? (float) $preset->totalPrice() : (float) $service->price;
            $quantity = (float) $item['quantity'];
            $isAddon = $service && in_array($service->id, $addonIds, true);

            // An add-on is counted by the sachet, so a weight minimum on the
            // service it rides along with must not reach it.
            $minimumKilos = ! $isAddon && $service?->minimum_kilos !== null
                ? (float) $service->minimum_kilos
                : null;

            $lines[] = [
                'laundry_service_id' => $service?->id,
                'service_preset_id' => $preset?->id,
                'service_name' => $preset?->name ?: $service->name,
                'service_price' => $price,
                'pricing_type' => $pricingType,
                'quantity' => $quantity,
                // An add-on is counted by the sachet even if it were ever
                // priced by weight, so it is never mistaken for laundry.
                'unit' => $isAddon ? 'qty' : Booking::unitFor($pricingType),
                'billable_quantity' => Booking::billableQuantity($pricingType, $quantity, $minimumKilos),
                'line_total' => Booking::lineTotal($pricingType, $price, $quantity, $minimumKilos),
                'is_addon' => $isAddon,
                'sort_order' => $position,
            ];
        }

        usort($lines, fn ($a, $b) => [$a['is_addon'], $a['sort_order']] <=> [$b['is_addon'], $b['sort_order']]);

        return $lines;
    }

    private function createFor(Customer $customer, array $data): PickupRequest
    {
        return DB::transaction(function () use ($customer, $data) {
            $wantsDelivery = ($data['delivery_preference'] ?? 'deliver') === 'deliver';
            $isRush = (bool) ($data['is_rush'] ?? false);

            $lines = $this->linesFor($data);

            // The washing, not the detergent: what the single-service columns
            // below describe, for the screens and reports that read them.
            $services = array_values(array_filter($lines, fn ($line) => ! $line['is_addon']));
            $first = $services[0] ?? null;

            // What the customer says the bag weighs, for the counter to check.
            $kilos = array_sum(array_map(
                fn ($line) => $line['unit'] === 'kg' ? $line['quantity'] : 0,
                $lines
            ));

            $pickupRequest = PickupRequest::create([
                'reference_no' => PickupRequest::nextReference(),
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'],
                'laundry_service_id' => $first['laundry_service_id'] ?? null,
                'service_preset_id' => $first['service_preset_id'] ?? null,
                'service_name' => $first['service_name'] ?? null,
                'service_price' => $first['service_price'] ?? null,
                'service_pricing_type' => $first['pricing_type'] ?? null,
                'estimated_kilos' => $kilos > 0 ? round($kilos, 2) : null,
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
                'is_rush' => $isRush,
                'notes' => $data['notes'] ?? null,
                'estimated_total' => Booking::estimate($lines, $isRush),
                'status' => 'pending',
            ]);

            foreach ($lines as $line) {
                $pickupRequest->items()->create($line);
            }

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
            // One booking, as many services as the bag holds: a regular load
            // and a comforter travel together, each with its own amount.
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'string', Rule::in(Booking::bookableKeys($request->integer('branch_id')))],
            'items.*.quantity' => ['required', 'numeric', 'min:0.5', 'max:200'],
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
            // Same-day pickup is allowed, but not a window that has already
            // closed: the van for it has gone.
            'pickup_slot' => ['required', Rule::in(array_keys(Booking::slots())), function ($attribute, $value, $fail) use ($request) {
                if ($request->input('pickup_date') === now()->toDateString() && Booking::slotHasPassed($value)) {
                    $fail('That pickup time has already passed today. Please choose a later time, or tomorrow.');
                }
            }],
            'delivery_preference' => ['required', Rule::in(array_keys(Booking::deliveryPreferences()))],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:pickup_date', 'before_or_equal:'.Booking::latestPickupDate()],
            'delivery_slot' => ['nullable', Rule::in(array_keys(Booking::slots()))],
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
            'pickup_date.after_or_equal' => 'Please choose a pickup date from today onwards, and today only while a collection window is still open.',
            'delivery_date.after_or_equal' => 'Delivery cannot be scheduled before the pickup.',
            'branch_id.exists' => 'Please choose one of our active branches.',
            'items.required' => 'Please choose what you would like us to clean.',
            'items.min' => 'Please choose what you would like us to clean.',
            'items.*.key.in' => 'That service is not available at the branch you picked. Please choose another.',
            'items.*.quantity.required' => 'Please enter how much laundry for each service you picked.',
            'items.*.quantity.numeric' => 'Please enter the amount as a number, like 8.',
            'items.*.quantity.min' => 'Please enter an amount of at least 0.5 for each service you picked.',
            'items.*.quantity.max' => 'That is more than we can take in one pickup. Please book up to 200 per service.',
        ];

        $validated = $request->validate($rules, $messages);

        // Detergent and fabric conditioner go with a wash; on their own there
        // is nothing to put them in.
        $addonIds = Booking::addons((int) $validated['branch_id'])->pluck('id')->all();
        $hasService = collect($validated['items'])->contains(function (array $item) use ($addonIds) {
            $offering = Booking::resolveOffering($item['key']);

            return $offering && ! in_array($offering['service']?->id, $addonIds, true);
        });

        if (! $hasService) {
            throw ValidationException::withMessages([
                'items' => 'Please choose a laundry service. Add-ons like detergent go with a wash.',
            ]);
        }

        $validated['contact_phone'] = Customer::normalizePhone($validated['contact_phone']);

        // Rush is a counter decision now, not something the public form offers,
        // so a posted flag cannot buy its way to the front of the queue.
        $validated['is_rush'] = false;

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
