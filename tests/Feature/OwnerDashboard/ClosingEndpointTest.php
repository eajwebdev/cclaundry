<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\User;
use App\Support\OwnerDashboard\ClosingView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClosingEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOwnerDashboard;

    private Branch $a;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->owner = $this->admin();
        $this->a = $this->seedBranch('A');
    }

    /** A machine_counters row shaped like ZReadingController::machineRow. */
    private function row(string $status, int $booked, int $counted, int $unexplained): array
    {
        return [
            'beginning' => 100, 'ending' => 100 + $counted, 'total' => $counted, 'system_cycles' => $booked,
            'non_job_cycles' => ['not_recorded' => 0, 'other' => 0], 'non_job_total' => 0,
            'unexplained_cycles' => $unexplained, 'fault' => null, 'notes' => null, 'jo_ref' => null,
            'reconciliation_status' => $status,
        ];
    }

    private function dryerOver(): array
    {
        return [3 => ['wash' => $this->row('matched', 4, 4, 0), 'dry' => $this->row('off', 4, 5, 1)]];
    }

    private function api(string $route, array $query): TestResponse
    {
        return $this->actingAs($this->owner)->getJson(route($route, $query));
    }

    public function test_dryer_one_cycle_over_is_a_difference_naming_the_machine(): void
    {
        $view = ClosingView::from($this->closing($this->a, '2026-11-20', [
            'cash_diff' => 0, 'gcash_diff' => 0, 'machine_counters' => $this->dryerOver(),
        ]));

        $this->assertSame('difference', $view['status']);
        $this->assertSame(['Dryer #3: 1 cycle more than loads booked'], $view['failed_checks']);
        $this->assertSame('Dryer #3: 1 cycle more than loads booked', $view['what_to_check']);
        $this->assertSame([
            ['machine_no' => 3, 'type' => 'wash', 'label' => 'Washer #3', 'booked' => 4, 'counted' => 4, 'difference' => 0],
            ['machine_no' => 3, 'type' => 'dry', 'label' => 'Dryer #3', 'booked' => 4, 'counted' => 5, 'difference' => 1],
        ], $view['machines']);
    }

    public function test_cash_short_message(): void
    {
        $view = ClosingView::from($this->closing($this->a, '2026-11-20', [
            'expected_cash_drawer_amount' => 2000, 'actual_cash_amount' => 1880, 'cash_diff' => -120,
        ]));

        $this->assertSame('difference', $view['status']);
        $this->assertSame(['Cash ₱120 short — recount, check expenses'], $view['failed_checks']);
        $this->assertEquals(['expected' => 2000, 'counted' => 1880, 'difference' => -120], $view['shop_cash']);
    }

    public function test_messages_join_in_order_with_gcash_and_cycles_fewer(): void
    {
        $view = ClosingView::from($this->closing($this->a, '2026-11-20', [
            'cash_diff' => 1250, 'gcash_diff' => -40,
            'machine_counters' => [
                2 => ['dry' => $this->row('counter_low', 6, 4, -2), 'wash' => $this->row('end_low', 3, 0, -5)],
                1 => ['wash' => $this->row('empty', 0, 0, 0), 'dry' => $this->row('matched', 2, 2, 0)],
            ],
        ]));

        $this->assertSame([
            'Cash ₱1,250 over — recount, check payments',
            'GCash ₱40 short — check the app balance',
            'Washer #2: counter reads lower than at opening',
            'Dryer #2: 2 cycles fewer than loads booked',
        ], $view['failed_checks']);
        $this->assertSame(implode(' · ', $view['failed_checks']), $view['what_to_check']);
        $this->assertSame(['1dry', '2wash', '2dry'], array_map(fn ($m) => $m['machine_no'].$m['type'], $view['machines']));
    }

    public function test_bank_deposit_message(): void
    {
        $view = ClosingView::from($this->closing($this->a, '2026-11-20', ['deposit_diff' => -300]));

        $this->assertSame('difference', $view['status']);
        $this->assertSame(['Bank deposit ₱300 short — check the deposit slip'], $view['failed_checks']);
    }

    public function test_titan_label(): void
    {
        $this->a->update(['titan_machine_numbers' => [2]]);
        $view = ClosingView::from($this->closing($this->a, '2026-11-20', [
            'machine_counters' => [2 => ['wash' => $this->row('off', 3, 5, 2)]],
        ]));
        $this->assertSame('Titan washer #2', $view['machines'][0]['label']);
        $this->assertSame(['Washer #2: 2 cycles more than loads booked'], $view['failed_checks']);

        $b = $this->seedBranch('B');
        $view = ClosingView::from($this->closing($b, '2026-11-20', [
            'machine_counters' => [5 => ['dry' => $this->row('matched', 1, 1, 0)], 4 => ['dry' => $this->row('matched', 1, 1, 0)]],
        ]));
        $this->assertSame(['Dryer #4', 'Titan dryer #5'], array_column($view['machines'], 'label'));
    }

    public function test_explained_extra_cycle_stays_balanced(): void
    {
        $closer = User::factory()->create(['name' => 'Ana']);
        $view = ClosingView::from($this->closing($this->a, '2026-11-20', [
            'cash_diff' => 0.004, 'closed_by' => $closer->id,
            'machine_counters' => [3 => ['dry' => $this->row('explained', 4, 5, 0)]],
        ]));

        $this->assertSame('balanced', $view['status']);
        $this->assertSame([], $view['failed_checks']);
        $this->assertSame('', $view['what_to_check']);
        $this->assertSame('Ana', $view['closed_by']);
    }

    public function test_overview_summary_counts_and_sums(): void
    {
        $this->closing($this->a, '2026-11-18', ['deposit_bag_amount' => 5000]);
        $this->closing($this->a, '2026-11-19', ['deposit_bag_amount' => 5000, 'cash_diff' => -120, 'status' => 'with_over_short']);
        $this->closing($this->a, '2026-11-20', ['deposit_bag_amount' => 5000, 'gcash_transfer_amount' => 1000, 'machine_counters' => $this->dryerOver()]);

        $this->api('owner-dashboard.api.overview', ['from' => '2026-11-18', 'to' => '2026-11-20'])
            ->assertOk()
            ->assertJsonPath('closings.summary', [
                'count' => 3, 'balanced' => 1, 'with_difference' => 2, 'cash_over_short' => -120,
                'cash_deposited' => 15000, 'gcash_sent_to_bank' => 1000,
            ]);
    }

    public function test_overview_lists_only_unbalanced_days_newest_first(): void
    {
        $b = $this->seedBranch('B');
        $this->closing($this->a, '2026-11-18', ['cash_diff' => -120]);
        $this->closing($this->a, '2026-11-19');
        $this->closing($this->a, '2026-11-20', ['machine_counters' => $this->dryerOver()]);
        $this->closing($b, '2026-11-20');

        $res = $this->api('owner-dashboard.api.overview', ['from' => '2026-11-18', 'to' => '2026-11-20'])->assertOk()
            ->assertJsonPath('closings.full', null);
        $this->assertSame(['2026-11-20', '2026-11-18'], array_column($res->json('closings.unbalanced'), 'date'));
        $this->assertSame('Dryer #3: 1 cycle more than loads booked', $res->json('closings.unbalanced.0.what_to_check'));

        $this->assertSame([
            ['date' => '2026-11-20', 'branch' => 'A', 'closed_by' => null, 'status' => 'difference', 'what_to_check' => 'Dryer #3: 1 cycle more than loads booked'],
            ['date' => '2026-11-20', 'branch' => 'B', 'closed_by' => null, 'status' => 'balanced', 'what_to_check' => ''],
            ['date' => '2026-11-19', 'branch' => 'A', 'closed_by' => null, 'status' => 'balanced', 'what_to_check' => ''],
            ['date' => '2026-11-18', 'branch' => 'A', 'closed_by' => null, 'status' => 'difference', 'what_to_check' => 'Cash ₱120 short — recount, check expenses'],
        ], $res->json('closings.all'));
    }

    public function test_single_day_overview_returns_the_full_closing(): void
    {
        $preparer = User::factory()->create(['name' => 'Ben']);
        $this->closing($this->a, '2026-11-20', [
            'cash_diff' => -120, 'deposit_bag_amount' => 5000, 'float_for_tomorrow' => 500, 'gcash_transfer_amount' => 1000,
            'expected_gcash_amount' => 3000, 'actual_gcash_amount' => 3000, 'gcash_diff' => 0, 'prepared_by' => $preparer->id,
        ]);

        $res = $this->api('owner-dashboard.api.overview', ['from' => '2026-11-20', 'to' => '2026-11-20'])->assertOk()
            ->assertJsonPath('closings.unbalanced', [])
            ->assertJsonPath('closings.full.0.date', '2026-11-20')
            ->assertJsonPath('closings.full.0.branch', 'A')
            ->assertJsonPath('closings.full.0.closed_by', 'Ben')
            ->assertJsonPath('closings.full.0.status', 'difference')
            ->assertJsonPath('closings.full.0.gcash', ['expected' => 3000, 'counted' => 3000, 'difference' => 0])
            ->assertJsonPath('closings.full.0.envelope_amount', 5000)
            ->assertJsonPath('closings.full.0.shop_cash_left', 500)
            ->assertJsonPath('closings.full.0.gcash_sent_to_bank', 1000)
            ->assertJsonPath('closings.full.0.failed_checks', ['Cash ₱120 short — recount, check expenses']);
        $this->assertCount(1, $res->json('closings.full'));
    }

    public function test_closing_endpoint_returns_one_view_per_branch_for_all(): void
    {
        $c = $this->seedBranch('C');
        $b = $this->seedBranch('B');
        $this->closing($c, '2026-11-20');
        $this->closing($this->a, '2026-11-20', ['machine_counters' => $this->dryerOver()]);
        $this->closing($b, '2026-11-20');
        $this->closing($b, '2026-11-19');

        $res = $this->api('owner-dashboard.api.closing', ['date' => '2026-11-20', 'branch' => 'all'])->assertOk()
            ->assertJsonPath('date', '2026-11-20')
            ->assertJsonPath('branch', 'all')
            ->assertJsonPath('closings.0.status', 'difference');
        $this->assertSame(['A', 'B', 'C'], array_column($res->json('closings'), 'branch'));

        $this->api('owner-dashboard.api.closing', ['date' => '2026-11-20', 'branch' => (string) $b->id])->assertOk()
            ->assertJsonPath('branch', $b->id)
            ->assertJsonCount(1, 'closings')
            ->assertJsonPath('closings.0.branch', 'B');
    }

    public function test_closing_endpoint_day_without_closing_is_empty(): void
    {
        $this->api('owner-dashboard.api.closing', ['date' => '2026-11-21'])->assertOk()->assertJsonPath('closings', []);
    }

    public function test_closing_endpoint_rejects_bad_date_and_non_admin(): void
    {
        foreach ([[], ['date' => '2026-11-31'], ['date' => 'nonsense'], ['date' => '2026-11-24'], ['date' => '2026-11-20', 'branch' => '999']] as $query) {
            $this->api('owner-dashboard.api.closing', $query)->assertStatus(400);
        }

        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->a->id]);
        $this->actingAs($staff)->getJson(route('owner-dashboard.api.closing', ['date' => 'nonsense']))->assertForbidden();
    }
}
