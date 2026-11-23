<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private const PAGES = ['overview', 'supplies', 'profit'];

    /** The data-od hooks owner-dashboard.js fills, per page. Renaming one must break this test. */
    private const HOOKS = [
        'overview' => ['kpis', 'payments', 'intake', 'closings', 'closing-full', 'busiest', 'hourly', 'services'],
        'supplies' => ['kpis', 'usage', 'items', 'purchases', 'adjustments', 'addons'],
        'profit' => ['equation', 'money-chart', 'per-order', 'break-even', 'staff', 'bills', 'other'],
    ];

    /** The other hooks the JS reads: sub-heading slots, cards it hides or shows, and link attributes. */
    private const SUBS = [
        'overview' => ['services'],
        'supplies' => ['purchases', 'adjustments', 'addons'],
        'profit' => ['per-order', 'bills', 'other', 'supplies-used', 'supplies-items'],
    ];

    private const CARDS = ['overview' => ['busiest', 'hourly'], 'supplies' => [], 'profit' => []];

    private const LINK_ATTRS = [
        'overview' => ['data-profit-url' => 'profit', 'data-supplies-url' => 'supplies'],
        'supplies' => [],
        'profit' => [],
    ];

    private User $owner;

    private Branch $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->owner = $this->admin();
        $this->a = $this->seedBranch('Alpha');
    }

    public function test_admin_sees_each_page_and_staff_get_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'access' => []]);
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->a->id, 'access' => ['owner_dashboard', 'dashboard']]);

        foreach (self::PAGES as $page) {
            $this->actingAs($this->owner)->get(route("owner-dashboard.$page"))->assertOk();
            $this->actingAs($admin)->get(route("owner-dashboard.$page"))->assertOk();
            $this->actingAs($staff)->get(route("owner-dashboard.$page"))->assertForbidden();
        }
    }

    public function test_bad_filter_on_a_page_is_400(): void
    {
        foreach (self::PAGES as $page) {
            $this->actingAs($this->owner)->get(route("owner-dashboard.$page", ['from' => 'nonsense']))->assertStatus(400);
            $this->actingAs($this->owner)->get(route("owner-dashboard.$page", ['from' => '2026-11-10', 'to' => '2026-11-01']))->assertStatus(400);
            $this->actingAs($this->owner)->get(route("owner-dashboard.$page", ['branch' => '999']))->assertStatus(400);
        }
    }

    public function test_tab_links_keep_the_range_and_branch(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner-dashboard.overview', ['from' => '2026-09-01', 'to' => '2026-09-30', 'branch' => 'all']))
            ->assertOk()
            ->assertSee(route('owner-dashboard.profit').'?from=2026-09-01&amp;to=2026-09-30&amp;branch=all', false)
            ->assertSee(route('owner-dashboard.supplies').'?from=2026-09-01&amp;to=2026-09-30&amp;branch=all', false)
            ->assertSeeText('From Sep 1, 2026 → To Sep 30, 2026')
            ->assertSee('30 days');
    }

    public function test_default_range_is_first_of_month_to_today(): void
    {
        $this->actingAs($this->owner)->get(route('owner-dashboard.overview'))
            ->assertOk()
            ->assertSee('Nov 1, 2026')
            ->assertSee('Nov 23, 2026')
            ->assertSee('23 days')
            ->assertSee('data-from="2026-11-01"', false)
            ->assertSee('data-to="2026-11-23"', false);
    }

    public function test_single_day_range_shows_one_day(): void
    {
        $this->actingAs($this->owner)->get(route('owner-dashboard.overview', ['from' => '2026-11-20', 'to' => '2026-11-20']))
            ->assertOk()
            ->assertSee('1 day')
            ->assertDontSee('1 days');
    }

    public function test_branch_select_lists_only_active_branches_with_the_selected_one_marked(): void
    {
        $b = $this->seedBranch('Bravo');
        Branch::query()->create(['name' => 'Closed Charlie', 'code' => 'CC', 'branch_type' => 'full_service', 'is_active' => false]);

        $this->actingAs($this->owner)->get(route('owner-dashboard.supplies', ['branch' => (string) $b->id]))
            ->assertOk()
            ->assertSee('All branches')
            ->assertSee('Alpha')
            ->assertSee('<option value="'.$b->id.'" selected>Bravo</option>', false)
            ->assertDontSee('value="'.$this->a->id.'" selected', false)
            ->assertDontSee('value="all" selected', false)
            ->assertDontSee('Closed Charlie')
            ->assertSee('data-branch="'.$b->id.'"', false);
    }

    public function test_each_page_carries_its_endpoint_and_widget_hooks(): void
    {
        foreach (self::HOOKS as $page => $hooks) {
            $response = $this->actingAs($this->owner)->get(route("owner-dashboard.$page"))
                ->assertOk()
                ->assertSee('id="owner-dashboard"', false)
                ->assertSee('data-page="'.$page.'"', false)
                ->assertSee('data-endpoint="'.route("owner-dashboard.api.$page").'"', false)
                ->assertSee('id="od-date-button"', false)
                ->assertSee('id="od-branch"', false)
                ->assertSee('id="od-error"', false)
                ->assertSee('id="od-retry"', false)
                ->assertSee('id="od-widgets"', false);
            foreach ($hooks as $hook) {
                $response->assertSee('data-od="'.$hook.'"', false);
            }
            foreach (self::SUBS[$page] as $sub) {
                $response->assertSee('data-od-sub="'.$sub.'"', false);
            }
            foreach (self::CARDS[$page] as $card) {
                $response->assertSee('data-card="'.$card.'"', false);
            }
            foreach (self::LINK_ATTRS[$page] as $attr => $target) {
                $response->assertSee($attr.'="'.route("owner-dashboard.$target").'"', false);
            }
        }
    }

    public function test_pages_carry_no_fixed_period_wording(): void
    {
        foreach (self::PAGES as $page) {
            $html = $this->actingAs($this->owner)->get(route("owner-dashboard.$page"))->assertOk()->getContent();
            // The page's own markup only (the app chrome around it has its own widgets), without script bodies.
            $doc = new \DOMDocument;
            @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
            $pageHtml = $doc->saveHTML($doc->getElementById('owner-dashboard'));
            $visible = mb_strtolower(preg_replace('#<script\b[^>]*>.*?</script>#is', '', $pageHtml));
            $this->assertStringContainsString('dates', $visible);
            foreach (['today', 'this week', 'this month', 'last 7 days', 'vs last', 'next payday', 'still to pay'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $visible, "$page shows \"$phrase\"");
            }
        }
    }

    public function test_page_scripts_carry_no_fixed_period_wording(): void
    {
        $files = [resource_path('js/owner-dashboard.js'), ...glob(resource_path('js/owner-dashboard/*.js'))];
        $this->assertGreaterThan(1, count($files));

        foreach ($files as $file) {
            // The one allowed occurrence: flatpickr's config value, never shown.
            $source = mb_strtolower(str_replace("maxDate: 'today'", '', file_get_contents($file)));
            foreach (['today', 'this week', 'this month', 'last 7 days', 'vs last', 'next payday', 'still to pay'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $source, basename($file)." contains \"$phrase\"");
            }
        }
    }

    public function test_menu_shows_owner_dashboard_to_admins_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'access' => ['dashboard']]);
        $manager = User::factory()->create(['role' => 'branch_manager', 'branch_id' => $this->a->id, 'access' => ['dashboard', 'owner_dashboard']]);

        $this->assertArrayHasKey('owner_dashboard', $this->owner->accessibleMenuItems());
        $this->assertArrayHasKey('owner_dashboard', $admin->accessibleMenuItems());
        $this->assertArrayNotHasKey('owner_dashboard', $manager->accessibleMenuItems());

        $this->actingAs($admin)->get(route('owner-dashboard.overview'))
            ->assertSee('Owner Dashboard')
            ->assertSee('href="'.route('owner-dashboard.overview').'"', false);
    }

    public function test_owner_dashboard_is_never_assignable_to_other_roles(): void
    {
        foreach (['branch_manager', 'cashier', 'staff', 'admin'] as $role) {
            $this->assertNotContains('owner_dashboard', Menu::assignableKeysForRole($role), $role);
        }

        $this->actingAs($this->owner)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('value="dashboard"', false)
            ->assertDontSee('value="owner_dashboard"', false);
    }

    public function test_sidebar_highlights_owner_dashboard_on_all_three_pages_only(): void
    {
        $active = fn (string $url) => '#href="'.preg_quote($url, '#').'"\s+class="[^"]*app-sidebar-menu-link-active#';

        foreach (self::PAGES as $page) {
            $html = $this->actingAs($this->owner)->get(route("owner-dashboard.$page"))->assertOk()->getContent();
            $this->assertMatchesRegularExpression($active(route('owner-dashboard.overview')), $html, $page);
            $this->assertDoesNotMatchRegularExpression($active(route('dashboard')), $html, $page);
        }
    }
}
