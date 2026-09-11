<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The barangays of Kabankalan City, shipped with the application.
 *
 * Filipino addresses are given by barangay far more often than by street, so
 * this list is local data rather than something we ask an upstream geocoder
 * for. That buys three things a remote lookup cannot:
 *
 *   - it is complete (all 32 official barangays, not whatever OSM happens to
 *     have indexed under a fuzzy match),
 *   - it is instant and works offline, with no rate limit to respect,
 *   - the spelling is stable, so "Carol-an" and "Carolan" both resolve.
 *
 * Coordinates come from OpenStreetMap admin_level=10 boundary centroids.
 * Regenerate resources/data/kabankalan-barangays.json if a barangay is renamed
 * or split.
 */
class ServiceArea
{
    private const CACHE_KEY = 'service-area:barangays';

    /**
     * @return array<int, array{name: string, poblacion: bool, latitude: float, longitude: float, aliases: array<int, string>}>
     */
    public static function barangays(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $path = resource_path('data/kabankalan-barangays.json');

            if (! is_file($path)) {
                return [];
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            return $decoded['barangays'] ?? [];
        });
    }

    /**
     * Barangays matching a typed fragment, best match first.
     *
     * Ranking is deliberately simple and predictable: a name that starts with
     * what was typed beats one that merely contains it, and the poblacion
     * barangays win ties because that is where most of the customers are.
     *
     * @return array<int, array{label: string, context: string, latitude: float, longitude: float, kind: string}>
     */
    public static function searchBarangays(string $term, int $limit = 5): array
    {
        $term = Str::lower(trim($term));

        if ($term === '') {
            return [];
        }

        $scored = [];

        foreach (self::barangays() as $barangay) {
            $candidates = array_merge([$barangay['name']], $barangay['aliases'] ?? []);
            $best = null;

            foreach ($candidates as $candidate) {
                $haystack = Str::lower($candidate);

                if (str_starts_with($haystack, $term)) {
                    $best = min($best ?? PHP_INT_MAX, 0);
                } elseif (str_contains($haystack, $term)) {
                    $best = min($best ?? PHP_INT_MAX, 1);
                }
            }

            if ($best === null) {
                continue;
            }

            $scored[] = [
                'rank' => $best * 10 + (($barangay['poblacion'] ?? false) ? 0 : 1),
                'row' => $barangay,
            ];
        }

        usort($scored, fn ($a, $b) => [$a['rank'], $a['row']['name']] <=> [$b['rank'], $b['row']['name']]);

        return collect($scored)
            ->take($limit)
            ->map(fn ($entry) => [
                'label' => $entry['row']['name'],
                'context' => 'Barangay · Kabankalan City',
                'latitude' => (float) $entry['row']['latitude'],
                'longitude' => (float) $entry['row']['longitude'],
                'kind' => 'barangay',
            ])
            ->all();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
