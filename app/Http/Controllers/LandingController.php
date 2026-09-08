<?php

namespace App\Http\Controllers;

use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Support\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LandingController extends Controller
{
    public function index(Request $request)
    {
        // Staff who are already signed in have no use for the marketing page.
        if (Auth::guard('web')->check()) {
            return redirect()->route('dashboard');
        }

        $customer = Auth::guard('customer')->user();

        return view('landing', [
            'settings' => SystemSetting::current(),
            'branches' => Booking::branches(),
            'serviceTypes' => Booking::serviceTypes(),
            'slots' => Booking::slots(),
            'deliveryPreferences' => Booking::deliveryPreferences(),
            'priceList' => Booking::priceList(),
            'earliestPickupDate' => Booking::earliestPickupDate(),
            'latestPickupDate' => Booking::latestPickupDate(),
            'rushSurcharge' => Booking::RUSH_SURCHARGE,
            'customer' => $customer,
            'openRequests' => $customer
                ? PickupRequest::query()
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->orderBy('pickup_date')
                    ->get()
                : collect(),
            // A booking captured before sign-up is replayed into the form so
            // nothing the customer typed is lost.
            'pendingBooking' => $request->session()->get(Booking::PENDING_SESSION_KEY),
        ]);
    }

    /**
     * Public order tracking. Deliberately requires both the reference number
     * and the phone it was booked with so a guessed reference reveals nothing.
     */
    public function track(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $requestRecord = PickupRequest::query()
            ->with(['branch', 'jobOrder'])
            ->where('reference_no', trim($validated['reference_no']))
            ->first();

        $phoneMatches = $requestRecord
            && \App\Models\Customer::normalizePhone($requestRecord->contact_phone)
                === \App\Models\Customer::normalizePhone($validated['phone']);

        if (! $phoneMatches) {
            return back()
                ->withInput()
                ->withErrors(['reference_no' => 'We could not find a booking with that reference and mobile number.']);
        }

        return view('tracking', [
            'settings' => SystemSetting::current(),
            'pickupRequest' => $requestRecord,
        ]);
    }
}
