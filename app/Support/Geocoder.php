<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Address lookup against OpenStreetMap's Nominatim.
 *
 * This deliberately never runs from the browser. Nominatim's usage policy asks
 * for an identifying User-Agent, no more than one request per second across the
 * whole application, and that callers cache rather than re-ask — none of which
 * can be honoured from client-side JavaScript, where every visitor would be a
 * separate uncontrolled caller. Routing it through here keeps the free tier
 * usable in production instead of getting the deployment blocked.
 */
class Geocoder
{
    private const RATE_LIMIT_KEY = 'geocoder:nominatim';

    /**
     * Address search: our own barangay and landmark lists first, then
     * Kabankalan streets.
     *
     * The sources answer different questions. The local barangay list is how
     * most people here describe where they live, and the landmark list is how
     * they give directions ("near CityMall"); both always respond. Nominatim
     * adds street-level precision on top, but its matching is loose enough
     * that an unfiltered query for "vila" returns places in the next city over
     * — so anything outside Kabankalan is dropped before it is shown.
     *
     * @return array<int, array{label: string, context: string, latitude: float, longitude: float, kind: string}>
     */
    public static function search(string $query, int $limit = 8): array
    {
        $query = trim($query);

        if (Str::length($query) < 2) {
            return [];
        }

        $barangays = ServiceArea::searchBarangays($query);
        $landmarks = Landmarks::search($query, $limit - count($barangays));

        // Local matches alone are enough to place a pin, so the slower network
        // call is only worth making when there is room left to fill.
        $remaining = $limit - count($barangays) - count($landmarks);

        $streets = $remaining > 0 && Str::length($query) >= 3
            ? self::searchStreets($query, $remaining)
            : [];

        return array_merge($barangays, $landmarks, $streets);
    }

    /**
     * Street and landmark lookup through Nominatim, scoped to Kabankalan.
     *
     * @return array<int, array{label: string, context: string, latitude: float, longitude: float, kind: string}>
     */
    private static function searchStreets(string $query, int $limit): array
    {
        $cacheKey = 'geocode:streets:'.md5(Str::lower($query).'|'.$limit);

        return Cache::remember($cacheKey, self::ttl(), function () use ($query, $limit) {
            $bounds = config('maps.bounds');

            $response = self::request('/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                // Over-fetch: results outside Kabankalan get filtered out below,
                // so asking for exactly $limit would often come back short.
                'limit' => max($limit * 3, 10),
                'countrycodes' => config('maps.geocoding.country_codes'),
                'viewbox' => implode(',', [
                    $bounds['west'], $bounds['north'], $bounds['east'], $bounds['south'],
                ]),
                'bounded' => 1,
            ]);

            if ($response === null) {
                return [];
            }

            return collect($response)
                ->filter(function ($place) {
                    $address = $place['address'] ?? [];
                    $city = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? '';

                    // Nominatim happily returns Himamaylan for a Kabankalan
                    // viewbox, so the city is checked rather than trusted.
                    return Str::contains(
                        Str::lower($city.' '.($place['display_name'] ?? '')),
                        'kabankalan'
                    );
                })
                ->map(function ($place) {
                    $address = $place['address'] ?? [];

                    $name = $place['name']
                        ?: ($address['road'] ?? Str::before((string) $place['display_name'], ','));

                    // "Guanzon Street, Barangay 1, Binicuil, Kabankalan, Negros
                    // Occidental, Negros Island Region, 6111, Philippines" is
                    // unreadable in a dropdown. Keep the barangay, drop the rest.
                    $context = collect([
                        $address['village'] ?? $address['suburb'] ?? $address['quarter'] ?? null,
                        'Kabankalan City',
                    ])->filter()->unique()->implode(' · ');

                    return [
                        'label' => (string) $name,
                        'context' => $context,
                        'latitude' => (float) ($place['lat'] ?? 0),
                        'longitude' => (float) ($place['lon'] ?? 0),
                        'kind' => Str::startsWith($place['category'] ?? '', 'highway') ? 'street' : 'place',
                    ];
                })
                ->filter(fn ($place) => $place['label'] !== '' && $place['latitude'] !== 0.0)
                ->unique(fn ($place) => Str::lower($place['label'].$place['context']))
                ->take($limit)
                ->values()
                ->all();
        });
    }

    /**
     * Turn a dropped pin back into a readable address.
     */
    public static function reverse(float $latitude, float $longitude): ?string
    {
        // Rounded to ~11m before it becomes a cache key: nudging the pin a few
        // centimetres should not cost another upstream request.
        $cacheKey = sprintf('geocode:reverse:%.4f,%.4f', $latitude, $longitude);

        return Cache::remember($cacheKey, self::ttl(), function () use ($latitude, $longitude) {
            $response = self::request('/reverse', [
                'lat' => $latitude,
                'lon' => $longitude,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'zoom' => 18,
            ]);

            return $response['display_name'] ?? null;
        });
    }

    /**
     * Straight-line distance in kilometres (haversine).
     *
     * Used for the "outside our service area" hint and for sorting riders by
     * proximity, neither of which needs routed road distance.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0088;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Is this pin inside the area we actually serve?
     */
    public static function withinServiceArea(float $latitude, float $longitude): bool
    {
        $center = config('maps.center');

        return self::distanceKm(
            $center['latitude'],
            $center['longitude'],
            $latitude,
            $longitude
        ) <= (float) config('maps.service_radius_km');
    }

    /**
     * A coordinate pair is only usable if it is on Earth and not the 0,0 null
     * island a dropped form field produces.
     */
    public static function isValidCoordinate(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        return $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180
            && ! ($latitude === 0.0 && $longitude === 0.0);
    }

    /**
     * @return array<mixed>|null  null whenever the lookup could not be made —
     *                            callers degrade to the typed address instead.
     */
    private static function request(string $path, array $query): ?array
    {
        // Nominatim allows one request per second for the whole application.
        // Exceeding it gets the deployment's IP banned, so a caller that cannot
        // get a slot gives up rather than queueing behind a slow upstream.
        if (! RateLimiter::attempt(self::RATE_LIMIT_KEY, 1, fn () => true, 1)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                    'User-Agent' => config('maps.geocoding.user_agent'),
                    'Accept-Language' => 'en',
                ])
                ->timeout((int) config('maps.geocoding.timeout_seconds'))
                ->get(rtrim((string) config('maps.geocoding.endpoint'), '/').$path, $query);

            if (! $response->successful()) {
                Log::warning('Nominatim lookup failed', [
                    'path' => $path,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            // The map still works without the geocoder — a customer can always
            // drag the pin — so a lookup outage must never surface as an error.
            Log::warning('Nominatim lookup threw', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function ttl(): int
    {
        return (int) config('maps.geocoding.cache_ttl_minutes') * 60;
    }
}
