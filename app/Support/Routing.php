<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Road routing for riders, via OSRM.
 *
 * The default host is the FOSSGIS instance that powers openstreetmap.org's own
 * directions — free, keyless, and considerably more dependable than the OSRM
 * demo server, which is explicitly development-only. Like the geocoder this is
 * proxied rather than called from the phone, so the User-Agent, the rate limit
 * and the cache are all enforced in one place.
 *
 * Every failure path returns an empty route list. A rider who cannot get a line
 * drawn still has the address, the pin and the Directions hand-off, so routing
 * being down must never be the thing that stops a delivery.
 */
class Routing
{
    private const RATE_LIMIT_KEY = 'routing:osrm';

    /**
     * Road route between two points, optionally forced through waypoints the
     * rider picked themselves.
     *
     * @param  array<int, array{0: float, 1: float}>  $via  [lat, lng] pairs, in order
     * @return array<int, array{geometry: array, distance: float, duration: float, steps: array}>
     */
    public static function route(array $from, array $to, array $via = [], bool $alternatives = true): array
    {
        $points = array_merge([$from], $via, [$to]);

        foreach ($points as $point) {
            if (! Geocoder::isValidCoordinate($point[0] ?? null, $point[1] ?? null)) {
                return [];
            }
        }

        // OSRM takes lon,lat — the opposite order to everything else here.
        $path = collect($points)
            ->map(fn ($point) => round((float) $point[1], 6).','.round((float) $point[0], 6))
            ->implode(';');

        // Rounded coordinates make the cache key stable while a rider inches
        // along, so a stationary phone re-polling costs nothing upstream.
        $cacheKey = 'route:'.md5($path.'|'.($alternatives ? 'alt' : 'single'));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($path, $alternatives) {
            if (! RateLimiter::attempt(self::RATE_LIMIT_KEY, 4, fn () => true, 1)) {
                return [];
            }

            try {
                $response = Http::withHeaders([
                        'User-Agent' => config('maps.routing.user_agent'),
                    ])
                    ->timeout((int) config('maps.routing.timeout_seconds'))
                    ->get(rtrim((string) config('maps.routing.endpoint'), '/')."/route/v1/driving/{$path}", [
                        // Alternatives only materialise where the road network
                        // genuinely offers a comparable second way round; rural
                        // Negros usually has exactly one.
                        'alternatives' => $alternatives ? 3 : 'false',
                        'overview' => 'full',
                        'geometries' => 'geojson',
                        'steps' => 'true',
                        'annotations' => 'false',
                    ]);

                if (! $response->successful()) {
                    Log::warning('OSRM route failed', ['status' => $response->status()]);

                    return [];
                }

                $body = $response->json();

                if (($body['code'] ?? '') !== 'Ok') {
                    return [];
                }

                return collect($body['routes'] ?? [])
                    ->map(fn ($route, $index) => [
                        'index' => $index,
                        'distance' => round((float) ($route['distance'] ?? 0)),
                        'duration' => round((float) ($route['duration'] ?? 0)),
                        'geometry' => $route['geometry'] ?? null,
                        'steps' => self::simplifySteps($route['legs'] ?? []),
                    ])
                    ->filter(fn ($route) => $route['geometry'] !== null)
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                Log::warning('OSRM route threw', ['message' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Flatten OSRM's legs/steps into the handful of fields the phone shows.
     *
     * The full step payload is large and mostly geometry the map already has;
     * a rider only needs the instruction, the street and how far off it is.
     *
     * @return array<int, array{instruction: string, name: string, distance: float, location: array}>
     */
    private static function simplifySteps(array $legs): array
    {
        return collect($legs)
            ->flatMap(fn ($leg) => $leg['steps'] ?? [])
            ->map(function ($step) {
                $maneuver = $step['maneuver'] ?? [];

                return [
                    'instruction' => self::instruction($maneuver, $step['name'] ?? ''),
                    'name' => $step['name'] ?? '',
                    'distance' => round((float) ($step['distance'] ?? 0)),
                    // [lng, lat] as OSRM gives it, ready for MapLibre.
                    'location' => $maneuver['location'] ?? null,
                ];
            })
            ->filter(fn ($step) => $step['location'] !== null)
            ->values()
            ->all();
    }

    /**
     * OSRM ships maneuver codes, not sentences. This turns them into the short
     * phrases a rider can read at a glance.
     */
    private static function instruction(array $maneuver, string $road): string
    {
        $type = $maneuver['type'] ?? '';
        $modifier = $maneuver['modifier'] ?? '';
        $where = $road !== '' ? ' onto '.$road : '';

        $turn = match ($modifier) {
            'left' => 'Turn left',
            'right' => 'Turn right',
            'sharp left' => 'Sharp left',
            'sharp right' => 'Sharp right',
            'slight left' => 'Bear left',
            'slight right' => 'Bear right',
            'straight' => 'Continue straight',
            'uturn' => 'Make a U-turn',
            default => 'Continue',
        };

        return match ($type) {
            'depart' => $road !== '' ? 'Head out on '.$road : 'Start your run',
            'arrive' => 'You have arrived',
            'roundabout', 'rotary' => 'Take the roundabout'.$where,
            'merge' => 'Merge'.$where,
            'fork' => $turn.' at the fork',
            'end of road' => $turn.$where,
            'new name' => 'Continue'.$where,
            default => $turn.$where,
        };
    }

    /**
     * How far a point sits from a route line, in metres.
     *
     * Used to decide when a rider has genuinely left the planned path and the
     * route should be recalculated, rather than re-routing on GPS jitter.
     *
     * @param  array<int, array{0: float, 1: float}>  $line  [lng, lat] pairs as GeoJSON gives them
     */
    public static function metresFromLine(float $latitude, float $longitude, array $line): float
    {
        $closest = PHP_FLOAT_MAX;

        foreach ($line as $point) {
            $distance = Geocoder::distanceKm($latitude, $longitude, (float) $point[1], (float) $point[0]) * 1000;

            if ($distance < $closest) {
                $closest = $distance;
            }
        }

        return $closest === PHP_FLOAT_MAX ? 0.0 : $closest;
    }
}
