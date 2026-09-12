<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\PickupRequest;
use App\Models\SystemSetting;
use App\Support\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
            'offerings' => Booking::offerings(),
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
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Real figures from the system, for the landing page. Only counts that
     * actually mean something are returned: a brand new install should not
     * advertise "0 orders completed".
     */
    private function stats(): array
    {
        $stats = [];

        $branches = Booking::branches()->count();
        if ($branches > 0) {
            $stats[] = [
                'value' => (string) $branches,
                'label' => Str::plural('Branch', $branches).' serving you',
                'icon' => 'branches',
            ];
        }

        // Counts what the public price list actually shows, not every service.
        $services = LaundryService::query()->where('is_active', true)->where('show_on_landing', true)->count();
        if ($services > 0) {
            $stats[] = [
                'value' => (string) $services,
                'label' => 'Services on the price list',
                'icon' => 'services',
            ];
        }

        $completed = JobOrder::query()->where('status', 'completed')->count();
        if ($completed > 0) {
            $stats[] = [
                'value' => $this->compactNumber($completed),
                'label' => 'Loads completed',
                'icon' => 'packageCheck',
            ];
        }

        $customers = Customer::query()->where('is_active', true)->count();
        if ($customers > 0) {
            $stats[] = [
                'value' => $this->compactNumber($customers),
                'label' => 'Customers served',
                'icon' => 'customers',
            ];
        }

        return $stats;
    }

    /** 1,240 reads better as 1.2k on a stat tile. */
    private function compactNumber(int $value): string
    {
        return $value >= 1000
            ? rtrim(rtrim(number_format($value / 1000, 1), '0'), '.').'k'
            : number_format($value);
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
