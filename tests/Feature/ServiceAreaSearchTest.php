<?php

namespace Tests\Feature;

use App\Support\ServiceArea;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The barangay half of address search is local data, so these run without
 * touching the network. Nominatim is faked to prove the street half degrades
 * cleanly rather than taking the barangay results down with it.
 */
class ServiceAreaSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ServiceArea::flush();
    }

    public function test_every_kabankalan_barangay_is_available(): void
    {
        $barangays = ServiceArea::barangays();

        // Kabankalan City has 32 barangays: 9 poblacion plus 23 rural.
        $this->assertCount(32, $barangays);

        $names = array_column($barangays, 'name');

        foreach (['Barangay 1', 'Barangay 9', 'Camugao', 'Carol-an', 'Tapi', 'Binicuil'] as $expected) {
            $this->assertContains($expected, $names);
        }

        foreach ($barangays as $barangay) {
            $this->assertNotEmpty($barangay['name']);
            // Every pin must land inside Negros, not on null island.
            $this->assertGreaterThan(9.0, $barangay['latitude']);
            $this->assertLessThan(11.0, $barangay['latitude']);
            $this->assertGreaterThan(122.0, $barangay['longitude']);
            $this->assertLessThan(123.5, $barangay['longitude']);
        }
    }

    public function test_a_two_letter_fragment_matches_barangays(): void
    {
        $results = ServiceArea::searchBarangays('ta');

        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertSame('barangay', $result['kind']);
            $this->assertStringContainsString('Kabankalan', $result['context']);
        }

        $names = array_column($results, 'label');
        $this->assertContains('Tabugon', $names);
    }

    public function test_names_starting_with_the_term_outrank_ones_merely_containing_it(): void
    {
        $results = ServiceArea::searchBarangays('cam');
        $names = array_column($results, 'label');

        $this->assertSame('Camansi', $names[0]);
    }

    /** Poblacion barangays win ties, since most bookings come from the town centre. */
    public function test_poblacion_barangays_rank_above_rural_ones_on_a_tie(): void
    {
        $names = array_column(ServiceArea::searchBarangays('bara'), 'label');

        $this->assertNotEmpty($names);
        $this->assertStringStartsWith('Barangay ', $names[0]);
    }

    /**
     * People write the poblacion barangays half a dozen ways.
     */
    public function test_common_spellings_resolve(): void
    {
        foreach (['brgy 5', 'Barangay V', 'Poblacion 5'] as $typed) {
            $names = array_column(ServiceArea::searchBarangays($typed), 'label');

            $this->assertContains('Barangay 5', $names, "'{$typed}' should find Barangay 5");
        }

        // Hyphen-less spellings of hyphenated barangays.
        $this->assertContains('Carol-an', array_column(ServiceArea::searchBarangays('carolan'), 'label'));
        $this->assertContains('Tan-awan', array_column(ServiceArea::searchBarangays('tanawan'), 'label'));
    }

    public function test_search_endpoint_returns_barangays_without_any_upstream_call(): void
    {
        Http::preventStrayRequests();
        // Two characters never reaches the street lookup at all.
        $response = $this->getJson(route('map.geocode', ['q' => 'ta']))->assertOk();

        $this->assertNotEmpty($response->json('results'));
        $this->assertSame('barangay', $response->json('results.0.kind'));
    }

    public function test_a_nominatim_outage_still_returns_barangays(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->getJson(route('map.geocode', ['q' => 'camu']))->assertOk();

        $names = array_column($response->json('results'), 'label');
        $this->assertContains('Camugao', $names);
    }

    public function test_street_results_outside_kabankalan_are_dropped(): void
    {
        Http::fake([
            '*' => Http::response([
                [
                    'name' => 'Real Kabankalan Street',
                    'lat' => '9.9886', 'lon' => '122.8112',
                    'category' => 'highway',
                    'display_name' => 'Real Kabankalan Street, Camugao, Kabankalan, Negros Occidental',
                    'address' => ['city' => 'Kabankalan', 'village' => 'Camugao'],
                ],
                [
                    // The neighbouring city Nominatim keeps volunteering.
                    'name' => 'Somewhere In Himamaylan',
                    'lat' => '10.10', 'lon' => '122.87',
                    'category' => 'highway',
                    'display_name' => 'Somewhere In Himamaylan, Himamaylan, Negros Occidental',
                    'address' => ['city' => 'Himamaylan'],
                ],
            ]),
        ]);

        $names = array_column(
            $this->getJson(route('map.geocode', ['q' => 'zzqq street']))->assertOk()->json('results'),
            'label'
        );

        $this->assertContains('Real Kabankalan Street', $names);
        $this->assertNotContains('Somewhere In Himamaylan', $names);
    }
}
