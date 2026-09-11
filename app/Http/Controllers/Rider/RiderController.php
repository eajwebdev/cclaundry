<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\PickupRequest;
use App\Models\RiderLocationPing;
use App\Support\Activity;
use App\Support\Geocoder;
use App\Support\Routing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The rider's own console, built for a phone held in one hand.
 *
 * A rider only ever sees bookings assigned to them — never the branch queue,
 * never another rider's runs.
 */
class RiderController extends Controller
{
    public function index(Request $request)
    {
        $rider = $request->user();

        $jobs = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->whereIn('status', ['confirmed', 'picked_up'])
            ->with(['customer:id,name,phone', 'branch:id,name,address'])
            ->orderByRaw("CASE status WHEN 'picked_up' THEN 0 ELSE 1 END")
            ->orderBy('pickup_date')
            ->orderBy('id')
            ->get();

        $completedToday = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->whereDate('delivered_at', today())
            ->count();

        return view('rider.index', [
            'rider' => $rider,
            'jobs' => $jobs,
            'completedToday' => $completedToday,
        ]);
    }

    public function show(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeRiderJob($request, $pickupRequest);

        $pickupRequest->load(['customer:id,name,phone', 'branch:id,name,address']);

        return view('rider.show', [
            'job' => $pickupRequest,
            'rider' => $request->user(),
        ]);
    }

    /**
     * Position report from the rider's phone.
     *
     * Called on an interval while the rider has sharing switched on, so it is
     * deliberately cheap: one indexed update plus one insert, no eager loads,
     * and a plain JSON body back.
     */
    public function ping(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:65535'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'pickup_request_id' => ['nullable', 'integer', 'exists:pickup_requests,id'],
        ]);

        $rider = $request->user();
        $accuracy = $validated['accuracy'] ?? null;

        // A low-confidence fix (indoors, wifi-only) would otherwise throw the
        // marker hundreds of metres and make the trail look like teleporting.
        $maxAccuracy = (int) config('maps.tracking.max_accuracy_meters');
        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            return response()->json([
                'accepted' => false,
                'reason' => 'accuracy_too_low',
            ]);
        }

        // Only accept a ping tied to a job this rider actually holds.
        $pickupRequestId = $validated['pickup_request_id'] ?? null;
        if ($pickupRequestId !== null) {
            $owns = PickupRequest::query()
                ->whereKey($pickupRequestId)
                ->where('rider_id', $rider->id)
                ->exists();

            if (! $owns) {
                $pickupRequestId = null;
            }
        }

        $recordedAt = now();

        $rider->forceFill([
            'last_latitude' => $validated['latitude'],
            'last_longitude' => $validated['longitude'],
            'last_location_accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            'last_location_heading' => isset($validated['heading'])
                ? (int) round($validated['heading']) % 360
                : null,
            'last_location_at' => $recordedAt,
            'is_sharing_location' => true,
        ])->save();

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'pickup_request_id' => $pickupRequestId,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            'heading' => isset($validated['heading']) ? (int) round($validated['heading']) % 360 : null,
            'speed' => $validated['speed'] ?? null,
            'recorded_at' => $recordedAt,
        ]);

        return response()->json([
            'accepted' => true,
            'recorded_at' => $recordedAt->toIso8601String(),
            'next_ping_in' => (int) config('maps.tracking.ping_interval_seconds'),
        ]);
    }

    /**
     * Road route from where the rider is now to where this job is going.
     *
     * Called when the job screen opens, when the rider strays off the line, and
     * when they drop a via point to force a different way round.
     */
    public function route(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeRiderJob($request, $pickupRequest);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Points the rider tapped to route through, in the order tapped.
            'via' => ['nullable', 'array', 'max:4'],
            'via.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'via.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $destination = $pickupRequest->destinationCoordinates();

        if (! $destination) {
            return response()->json([
                'routes' => [],
                'reason' => 'no_destination_pin',
            ]);
        }

        $via = collect($validated['via'] ?? [])
            ->map(fn ($point) => [(float) $point['latitude'], (float) $point['longitude']])
            ->all();

        $routes = Routing::route(
            [(float) $validated['latitude'], (float) $validated['longitude']],
            $destination,
            $via
        );

        return response()->json([
            'routes' => $routes,
            'destination' => [
                'latitude' => $destination[0],
                'longitude' => $destination[1],
            ],
            'leg' => $pickupRequest->activeLeg(),
            'reroute_after_metres' => (int) config('maps.routing.reroute_after_metres'),
        ]);
    }

    /**
     * Rider goes off duty. Clears the live flag so watching maps stop showing
     * them rather than freezing their last position on screen.
     */
    public function stopSharing(Request $request)
    {
        $request->user()->forceFill(['is_sharing_location' => false])->save();

        return response()->json(['sharing' => false]);
    }

    /**
     * Rider advances a job: collected from the customer, or delivered back.
     */
    public function updateStatus(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeRiderJob($request, $pickupRequest);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['picked_up', 'completed'])],
        ]);

        $target = $validated['status'];

        // A rider can only move a job forward, and only from the stage it is
        // actually in. Anything else is a stale button on a phone that has been
        // sitting in a pocket.
        $allowed = match ($pickupRequest->status) {
            'confirmed' => ['picked_up'],
            'picked_up' => ['completed'],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            return back()->with('error', 'That job has already moved on. Pull to refresh.');
        }

        DB::transaction(function () use ($request, $pickupRequest, $target) {
            $pickupRequest->update([
                'status' => $target,
                'picked_up_at' => $target === 'picked_up' ? now() : $pickupRequest->picked_up_at,
                'delivered_at' => $target === 'completed' ? now() : null,
            ]);

            Activity::log($request, 'pickup_request_rider_status', $pickupRequest, [
                'reference_no' => $pickupRequest->reference_no,
                'status' => $target,
                'rider_id' => $request->user()->id,
            ], $pickupRequest->branch_id);
        });

        $message = $target === 'picked_up'
            ? 'Collected. Bring it to the branch.'
            : 'Delivered. Nice work.';

        return redirect()->route('rider.index')->with('success', $message);
    }

    /**
     * Straight-line distance the rider still has to cover, for the console
     * header. Not a routed distance — it only has to be indicative.
     */
    public static function remainingKm(PickupRequest $job, $rider): ?float
    {
        $destination = $job->destinationCoordinates();

        if (! $destination || $rider->last_latitude === null) {
            return null;
        }

        return round(Geocoder::distanceKm(
            (float) $rider->last_latitude,
            (float) $rider->last_longitude,
            $destination[0],
            $destination[1]
        ), 1);
    }

    private function authorizeRiderJob(Request $request, PickupRequest $pickupRequest): void
    {
        abort_unless((int) $pickupRequest->rider_id === (int) $request->user()->id, 403);
    }
}
