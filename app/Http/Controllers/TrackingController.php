<?php

namespace App\Http\Controllers;

use App\Models\PickupRequest;
use App\Support\Geocoder;
use Illuminate\Http\Request;

/**
 * Live rider position for a single booking, polled by the customer's tracking
 * page.
 *
 * Addressed by the booking's reference number, which the customer already has
 * to know to reach the tracking page at all. The payload is deliberately
 * minimal: a position and an ETA hint, never the rider's phone number, their
 * other jobs, or where they have been.
 */
class TrackingController extends Controller
{
    public function location(Request $request, string $reference)
    {
        $pickupRequest = PickupRequest::query()
            ->where('reference_no', $reference)
            ->with('rider:id,name,last_latitude,last_longitude,last_location_at,last_location_heading,is_sharing_location')
            ->first();

        if (! $pickupRequest) {
            return response()->json(['tracking' => false], 404);
        }

        $trackable = $pickupRequest->isTrackable();
        $destination = $trackable ? $pickupRequest->destinationCoordinates() : null;

        $payload = [
            'tracking' => false,
            'status' => $pickupRequest->status,
            'leg' => $trackable ? $pickupRequest->activeLeg() : null,
            'destination' => $destination
                ? ['latitude' => $destination[0], 'longitude' => $destination[1]]
                : null,
            'poll_interval' => (int) config('maps.tracking.poll_interval_seconds'),
        ];

        $rider = $pickupRequest->rider;

        if (! $trackable || ! $rider || ! $rider->is_sharing_location) {
            return response()->json($payload);
        }

        if (! $rider->hasLiveLocation()) {
            // Assigned and en route, but the phone has not reported recently —
            // say so rather than pinning a stale marker to the map.
            return response()->json($payload + ['rider_name' => $rider->name, 'stale' => true]);
        }

        $riderLat = (float) $rider->last_latitude;
        $riderLng = (float) $rider->last_longitude;

        $payload['tracking'] = true;
        $payload['stale'] = false;
        $payload['rider_name'] = $rider->name;
        $payload['rider'] = [
            'latitude' => $riderLat,
            'longitude' => $riderLng,
            'heading' => $rider->last_location_heading,
            'updated_at' => $rider->last_location_at?->toIso8601String(),
            'seconds_ago' => $rider->last_location_at
                ? (int) $rider->last_location_at->diffInSeconds(now())
                : null,
        ];

        if ($destination) {
            $distanceKm = Geocoder::distanceKm($riderLat, $riderLng, $destination[0], $destination[1]);

            $payload['distance_km'] = round($distanceKm, 1);
            // Straight-line distance at a conservative city speed. Presented as
            // an estimate in the UI, never as a promised arrival time.
            $payload['eta_minutes'] = max(1, (int) ceil(($distanceKm / 18) * 60));
        }

        return response()->json($payload);
    }
}
