<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Named places inside Kabankalan City — the malls, the plaza, the public
 * market, banks, churches, schools — shipped with the application.
 *
 * Directions here are given by landmark ("beside Gaisano", "near the plaza")
 * at least as often as by barangay, so the booking map both draws these and
 * finds them in address search. Like the barangay list this is local data:
 * instant, free of Nominatim's rate limit, and limited to the city boundary.
 *
 * Coordinates come from OpenStreetMap. Regenerate
 * resources/data/kabankalan-landmarks.json with the Overpass query stored in
 * the file, then call flush() (or clear the cache).
 */
class Landmarks
{
    private const CACHE_KEY = 'service-area:landmarks';

    /** Shown under a search result, so "Gaisano" reads as a mall, not a street. */
    public const CATEGORY_LABELS = [
        'mall' => 'Mall',
        'market' => 'Public market',
        'park' => 'Park',
        'hospital' => 'Hospital',
        'clinic' => 'Clinic',
        'government' => 'Government office',
        'college' => 'College',
        'school' => 'School',
        'church' => 'Church',
        'supermarket' => 'Supermarket',
        'terminal' => 'Terminal',
        'bank' => 'Bank',
        'pharmacy' => 'Pharmacy',
        'food' => 'Restaurant',
        'fuel' => 'Gas station',
        'lodging' => 'Hotel',
        'attraction' => 'Attraction',
        'public' => 'Public service',
        'sports' => 'Sports venue',
        'convenience' => 'Convenience store',
        'shop' => 'Shop',
    ];

    /**
     * @return array<int, array{name: string, category: string, min_zoom: int, latitude: float, longitude: float, aliases: array<int, string>}>
     */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $path = resource_path('data/kabankalan-landmarks.json');

            if (! is_file($path)) {
                return [];
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            return $decoded['landmarks'] ?? [];
        });
    }

    /**
     * Landmarks matching a typed fragment, best match first.
     *
     * Names are compared with spaces and punctuation squeezed out, because
     * nobody types "CityMall", "McDonald's" or "7-Eleven" the way the sign
     * spells it. A name that starts with the term beats one with a word that
     * does, which beats one merely containing it; on a tie the more prominent
     * place (a mall before a corner store) wins.
     *
     * @return array<int, array{label: string, context: string, latitude: float, longitude: float, kind: string}>
     */
    public static function search(string $term, int $limit = 5): array
    {
        $squashed = self::squash($term);

        // Two letters match half the city; wait for a third.
        if ($limit < 1 || strlen($squashed) < 3) {
            return [];
        }

        $firstWord = self::squash((string) Str::of($term)->trim()->explode(' ')->first());
        $scored = [];

        foreach (self::all() as $landmark) {
            $best = null;

            foreach (array_merge([$landmark['name']], $landmark['aliases'] ?? []) as $candidate) {
                $haystack = self::squash($candidate);

                if (str_starts_with($haystack, $squashed)) {
                    $rank = 0;
                } elseif (self::hasWordStartingWith($candidate, $firstWord) && str_contains($haystack, $squashed)) {
                    $rank = 1;
                } elseif (str_contains($haystack, $squashed)) {
                    $rank = 2;
                } else {
                    continue;
                }

                $best = min($best ?? PHP_INT_MAX, $rank);
            }

            if ($best !== null) {
                $scored[] = ['rank' => [$best, (int) $landmark['min_zoom'], $landmark['name']], 'row' => $landmark];
            }
        }

        usort($scored, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return collect($scored)
            ->take($limit)
            ->map(fn ($entry) => [
                'label' => $entry['row']['name'],
                'context' => (self::CATEGORY_LABELS[$entry['row']['category']] ?? 'Landmark').' · Kabankalan City',
                'latitude' => (float) $entry['row']['latitude'],
                'longitude' => (float) $entry['row']['longitude'],
                'kind' => 'landmark',
            ])
            ->all();
    }

    /**
     * The list as a GeoJSON FeatureCollection for the map's label layer. Only
     * what the layer draws is sent; aliases stay on the server for search.
     *
     * @return array{type: string, features: array<int, array<string, mixed>>}
     */
    public static function toGeoJson(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => array_map(fn (array $landmark) => [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $landmark['longitude'], (float) $landmark['latitude']],
                ],
                'properties' => [
                    'name' => $landmark['name'],
                    'category' => $landmark['category'],
                    'min_zoom' => (int) $landmark['min_zoom'],
                ],
            ], self::all()),
        ];
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function squash(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($value)));
    }

    private static function hasWordStartingWith(string $value, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }

        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($value))) ?: [] as $word) {
            if ($word !== '' && str_starts_with($word, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
