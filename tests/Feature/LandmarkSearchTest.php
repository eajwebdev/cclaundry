<?php

namespace Tests\Feature;

use App\Support\Landmarks;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Landmarks are local data like the barangays, so none of this touches the
 * network; Nominatim is faked wherever the search endpoint could reach it.
 */
class LandmarkSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Landmarks::flush();
    }

    public function test_the_well_known_kabankalan_landmarks_are_available(): void
    {
        $landmarks = Landmarks::all();
        $names = array_column($landmarks, 'name');

        foreach (['CityMall Kabankalan', 'Gaisano Grand Mall Kabankalan', 'Kabankalan Plaza', 'Kabankalan Public Market', 'Kabankalan Cathedral'] as $expected) {
            $this->assertContains($expected, $names);
        }

        foreach ($landmarks as $landmark) {
            $this->assertArrayHasKey($landmark['category'], Landmarks::CATEGORY_LABELS, "{$landmark['name']} has an unknown category");
            $this->assertGreaterThanOrEqual(13, $landmark['min_zoom']);
            $this->assertLessThanOrEqual(17, $landmark['min_zoom']);

            // Kabankalan City only: inside the city's extent in southern Negros.
            $this->assertGreaterThan(9.6, $landmark['latitude']);
            $this->assertLessThan(10.25, $landmark['latitude']);
            $this->assertGreaterThan(122.6, $landmark['longitude']);
            $this->assertLessThan(123.2, $landmark['longitude']);
        }
    }

    /** People type names the way they say them, not the way the sign spells them. */
    public function test_nicknames_and_loose_spellings_find_the_landmark(): void
    {
        $cases = [
            'citymall' => 'CityMall Kabankalan',
            'city mall' => 'CityMall Kabankalan',
            'gaisano' => 'Gaisano Grand Mall Kabankalan',
            'palengke' => 'Kabankalan Public Market',
            'plaza' => 'Kabankalan Plaza',
            'mcdonalds' => "McDonald's",
        ];

        foreach ($cases as $typed => $expected) {
            $this->assertContains($expected, array_column(Landmarks::search($typed), 'label'), "'{$typed}' should find {$expected}");
        }
    }

    public function test_results_are_labelled_as_kabankalan_landmarks(): void
    {
        $results = Landmarks::search('citymall');

        $this->assertNotEmpty($results);
        $this->assertSame('landmark', $results[0]['kind']);
        $this->assertSame('Mall · Kabankalan City', $results[0]['context']);
    }

    public function test_two_letters_are_not_enough_to_search_landmarks(): void
    {
        $this->assertSame([], Landmarks::search('ci'));
    }

    public function test_search_endpoint_puts_landmarks_ahead_of_streets(): void
    {
        Http::fake(['*' => Http::response([])]);

        $results = $this->getJson(route('map.geocode', ['q' => 'gaisano']))->assertOk()->json('results');

        $this->assertNotEmpty($results);
        $this->assertSame('landmark', $results[0]['kind']);
        $this->assertSame('Gaisano Grand Mall Kabankalan', $results[0]['label']);
    }

    public function test_landmarks_endpoint_serves_geojson_for_the_map(): void
    {
        Http::preventStrayRequests();

        $response = $this->getJson(route('map.landmarks'))->assertOk();

        $this->assertSame('FeatureCollection', $response->json('type'));
        $this->assertCount(count(Landmarks::all()), $response->json('features'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));

        $feature = $response->json('features.0');
        [$longitude, $latitude] = $feature['geometry']['coordinates'];

        // GeoJSON is [longitude, latitude]; swapped, the pin lands in the ocean.
        $this->assertGreaterThan(122.0, $longitude);
        $this->assertLessThan(11.0, $latitude);
        $this->assertArrayHasKey('min_zoom', $feature['properties']);
    }
}
