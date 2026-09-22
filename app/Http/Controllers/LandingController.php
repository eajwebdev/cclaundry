<?php

namespace App\Http\Controllers;

use App\Models\BranchSetting;
use App\Models\Customer;
use App\Models\PickupRequest;
use App\Models\SiteVisit;
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

        $response = response()->view('landing', [
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

        if ($this->shouldTrackVisit($request)) {
            $visitorToken = $request->cookie('cc_landing_visitor')
                ?: $request->session()->get('cc_landing_visitor_token')
                ?: (string) Str::uuid();
            $request->session()->put('cc_landing_visitor_token', $visitorToken);
            $this->recordVisit($request, $visitorToken);

            if (! $request->cookie('cc_landing_visitor')) {
                $response->withCookie(cookie(
                    'cc_landing_visitor',
                    $visitorToken,
                    60 * 24 * 365,
                    '/',
                    null,
                    $request->isSecure(),
                    true,
                    false,
                    'lax'
                ));
            }
        }

        return $response;
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
            'trackingVersion' => $requestRecord->trackingVersion(),
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
            'version' => $requestRecord->trackingVersion(),
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

    private function shouldTrackVisit(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();
        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?: $request->header('Purpose')));

        return $request->isMethod('GET')
            && ! str_contains($purpose, 'prefetch')
            && ! preg_match('/bot|crawler|spider|slurp|preview|facebookexternalhit|uptime|monitor/i', $userAgent);
    }

    private function recordVisit(Request $request, string $visitorToken): void
    {
        try {
            $referrerHost = parse_url((string) $request->header('referer'), PHP_URL_HOST) ?: null;
            $country = strtoupper((string) (
                $request->header('CF-IPCountry')
                ?: $request->header('X-Vercel-IP-Country')
                ?: $request->header('CloudFront-Viewer-Country')
            ));
            $region = $request->header('CF-Region') ?: $request->header('X-Vercel-IP-Country-Region');
            $city = $request->header('CF-IPCity') ?: $request->header('X-Vercel-IP-City');

            SiteVisit::query()->firstOrCreate([
                'visited_on' => today()->toDateString(),
                'visitor_hash' => hash_hmac('sha256', $visitorToken, (string) config('app.key')),
            ], [
                'path' => '/'.ltrim($request->path(), '/'),
                'referrer_host' => $referrerHost ? Str::limit($referrerHost, 255, '') : null,
                'country_code' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
                'region' => $region ? Str::limit(urldecode((string) $region), 100, '') : null,
                'city' => $city ? Str::limit(urldecode((string) $city), 100, '') : null,
            ]);
        } catch (\Throwable) {
            // Analytics must never prevent the public landing page from loading,
            // including during deployment before the migration is applied.
        }
    }

}
