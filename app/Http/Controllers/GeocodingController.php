<?php

namespace App\Http\Controllers;

use App\Support\Geocoder;
use Illuminate\Http\Request;

/**
 * Thin public proxy in front of Nominatim.
 *
 * Public because the booking form is open to visitors who have no account yet.
 * The throttle on the routes plus the application-wide rate limiter inside
 * Geocoder are what keep that from being abusable.
 */
class GeocodingController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:160'],
        ]);

        return response()->json([
            'results' => Geocoder::search($validated['q']),
        ]);
    }

    public function reverse(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];

        return response()->json([
            'address' => Geocoder::reverse($latitude, $longitude),
            'within_service_area' => Geocoder::withinServiceArea($latitude, $longitude),
        ]);
    }
}
