<?php

namespace Tests\Feature;

use App\Models\SiteVisit;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteVisitAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reopening_the_landing_page_counts_once_per_browser_per_day(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 Test Browser',
            'CF-IPCountry' => 'PH',
            'CF-Region' => 'Negros Occidental',
            'CF-IPCity' => 'Kabankalan City',
        ])->get(route('landing'))->assertOk();

        $this->get(route('landing'))->assertOk();

        $this->assertSame(1, SiteVisit::query()->count());
        $visit = SiteVisit::query()->firstOrFail();
        $this->assertSame('PH', $visit->country_code);
        $this->assertSame('Kabankalan City', $visit->city);

        $this->withCookie('cc_landing_visitor', (string) Str::uuid())
            ->get(route('landing'))
            ->assertOk();

        $this->assertSame(2, SiteVisit::query()->count());
    }

    public function test_bots_and_prefetch_requests_are_not_counted(): void
    {
        $this->withHeader('User-Agent', 'Googlebot/2.1')
            ->get(route('landing'))
            ->assertOk();

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0',
            'Purpose' => 'prefetch',
        ])->get(route('landing'))->assertOk();

        $this->assertSame(0, SiteVisit::query()->count());
    }

    public function test_dashboard_filters_daily_unique_visits_and_reports_available_locations(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'super_admin', 'access' => ['dashboard']]);

        SiteVisit::query()->create([
            'visited_on' => '2026-09-20',
            'visitor_hash' => hash('sha256', 'visitor-a'),
            'path' => '/',
            'country_code' => 'PH',
            'region' => 'Negros Occidental',
            'city' => 'Kabankalan City',
        ]);
        foreach (['visitor-a', 'visitor-b'] as $visitor) {
            SiteVisit::query()->create([
                'visited_on' => '2026-09-21',
                'visitor_hash' => hash('sha256', $visitor),
                'path' => '/',
                'country_code' => 'PH',
                'region' => 'Negros Occidental',
                'city' => 'Kabankalan City',
            ]);
        }
        SiteVisit::query()->create([
            'visited_on' => '2026-09-19',
            'visitor_hash' => hash('sha256', 'visitor-c'),
            'path' => '/',
            'country_code' => 'PH',
            'region' => 'Negros Occidental',
            'city' => 'Kabankalan City',
        ]);
        SiteVisit::query()->create([
            'visited_on' => '2026-09-21',
            'visitor_hash' => hash('sha256', 'visitor-c'),
            'path' => '/',
            'country_code' => 'PH',
            'region' => 'Negros Occidental',
            'city' => 'Kabankalan City',
        ]);

        $payload = $this->actingAs($admin)
            ->getJson(route('dashboard.data', ['date_range' => '2026-09-20 to 2026-09-21']))
            ->assertOk()
            ->json();

        $this->assertSame('3', $payload['stats']['unique_site_visits']);
        $this->assertSame('4', $payload['stats']['daily_unique_site_visits']);
        $this->assertSame(['Sep 20', 'Sep 21'], $payload['charts']['site_visits']['labels']);
        $this->assertSame([1, 3], $payload['charts']['site_visits']['values']);
        $this->assertSame(['First-time visitors', 'Returning visitors'], $payload['charts']['visitor_summary']['labels']);
        $this->assertSame([2, 1], $payload['charts']['visitor_summary']['values']);
        $this->assertSame('Kabankalan City, Negros Occidental, PH', $payload['top_visitor_locations'][0]['label']);
        $this->assertSame('3', $payload['top_visitor_locations'][0]['count']);
    }

    private function settings(): void
    {
        SystemSetting::query()->create([
            'business_name' => 'EAJ Laundry',
            'currency' => 'PHP',
            'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV',
            'primary_color' => '#2E7D32',
            'is_completed' => true,
        ]);
    }
}
