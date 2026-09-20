<?php

namespace App\Http\Controllers;

use App\Models\BranchSetting;
use App\Models\Customer;
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
            'offerings' => Booking::offerings(),
            // Detergent and fabric conditioner: listed under the services on the
            // booking form, chosen with a wash rather than instead of one.
            'addons' => Booking::addonOfferings(),
            // Each branch runs its own pickup windows; the form swaps them when
            // the customer picks a branch.
            'slotsByBranch' => Booking::slotsByBranch(),
            'pickupHours' => Booking::pickupHoursLabel(),
            // Opening hours per branch, from Settings > Branch.
            'branchHours' => BranchSetting::query()
                ->whereIn('branch_id', Booking::branches()->pluck('id'))
                ->with('branch:id,name')
                ->get()
                ->map(fn (BranchSetting $setting) => [
                    'branch' => $setting->branch?->name,
                    'lines' => Booking::openingHoursLines($setting->operating_hours),
                ])
                ->filter(fn (array $branch) => $branch['lines'] !== [])
                ->values(),
            'deliveryPreferences' => Booking::deliveryPreferences(),
            'paymentMethods' => Booking::paymentMethods(),
            'earliestPickupDate' => Booking::earliestPickupDate(),
            'latestPickupDate' => Booking::latestPickupDate(),
            'customer' => $customer,
            'openRequests' => $customer
                ? PickupRequest::query()
                    ->with('items')
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->orderBy('pickup_date')
                    ->get()
                : collect(),
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

        $requestRecord = $this->trackedRequest($validated, true);

        if (! $requestRecord) {
            return back()
                ->withInput()
                ->withErrors(['reference_no' => 'We could not find a booking with that reference and mobile number.']);
        }

        return view('tracking', [
            'settings' => SystemSetting::current(),
            'pickupRequest' => $requestRecord,
            'trackingPhone' => $validated['phone'],
            'trackingVersion' => $this->trackingVersion($requestRecord),
        ]);
    }

    public function trackStatus(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $requestRecord = $this->trackedRequest($validated);
        if (! $requestRecord) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        return response()->json([
            'status' => $requestRecord->customerProgressStatus(),
            'version' => $this->trackingVersion($requestRecord),
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    private function trackedRequest(array $validated, bool $withDetails = false): ?PickupRequest
    {
        $requestRecord = PickupRequest::query()
            ->with($withDetails ? ['items', 'branch', 'jobOrder.latestCycle'] : ['jobOrder.latestCycle'])
            ->where('reference_no', trim($validated['reference_no']))
            ->first();

        return $requestRecord
            && Customer::normalizePhone($requestRecord->contact_phone) === Customer::normalizePhone($validated['phone'])
                ? $requestRecord
                : null;
    }

    private function trackingVersion(PickupRequest $requestRecord): string
    {
        $order = $requestRecord->jobOrder;

        return hash('sha256', implode('|', [
            $requestRecord->customerProgressStatus(),
            $requestRecord->updated_at?->format('Y-m-d H:i:s.u'),
            $requestRecord->tag_code,
            $requestRecord->collected_amount,
            $order?->updated_at?->format('Y-m-d H:i:s.u'),
            $order?->job_order_number,
            $order?->total,
            $order?->balance,
            $order?->latestCycle?->updated_at?->format('Y-m-d H:i:s.u'),
            $order?->latestCycle?->id,
        ]));
    }
}
