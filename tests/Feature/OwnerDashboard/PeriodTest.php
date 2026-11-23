<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\Branch;
use App\Models\ZReading;
use App\Support\OwnerDashboard\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
    }

    private function branch(string $code, bool $active = true): Branch
    {
        return Branch::query()->create(['name' => $code, 'code' => $code, 'machine_count' => 1, 'is_active' => $active]);
    }

    private function closing(Branch $branch, string $date): void
    {
        ZReading::query()->create([
            'branch_id' => $branch->id, 'reading_number' => "ZR-{$branch->code}-{$date}", 'business_date' => $date,
            'signature_name' => 'Cashier', 'status' => 'balanced',
        ]);
    }

    private function fromRequest(array $query): Period
    {
        return Period::fromRequest(Request::create('/x', 'GET', $query));
    }

    public function test_bucket_rule(): void
    {
        $this->assertSame('day', Period::make('2026-09-01', '2026-10-01', null)->bucket);
        $this->assertSame('week', Period::make('2026-09-01', '2026-10-02', null)->bucket);
        $this->assertSame('week', Period::make('2026-06-01', '2026-09-28', null)->bucket);
        $this->assertSame('month', Period::make('2026-06-01', '2026-09-29', null)->bucket);
    }

    public function test_weeks_run_saturday_to_friday_clipped_to_range(): void
    {
        $p = Period::make('2026-09-21', '2026-10-30', null);
        $b = $p->buckets();

        $this->assertSame(['2026-09-21', '2026-09-25'], [$b[0]['start'], $b[0]['end']]);
        $this->assertSame(['2026-09-26', '2026-10-02'], [$b[1]['start'], $b[1]['end']]);
        $this->assertSame('Sep 21–25', $b[0]['label']);
        $this->assertSame('Sep 26 – Oct 2', $b[1]['label']);
        $this->assertSame('2026-10-30', end($b)['end']);

        // The last week stops at the range end, not at its Friday.
        $clipped = Period::make('2026-09-21', '2026-10-28', null)->buckets();
        $this->assertSame(['label' => 'Oct 24–28', 'start' => '2026-10-24', 'end' => '2026-10-28'], end($clipped));
    }

    public function test_day_and_month_buckets(): void
    {
        $d = Period::make('2026-09-01', '2026-09-03', null)->buckets();
        $this->assertCount(3, $d);
        $this->assertSame(['label' => '2', 'start' => '2026-09-02', 'end' => '2026-09-02'], $d[1]);

        $m = Period::make('2026-06-15', '2026-11-10', null)->buckets();
        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026'], array_column($m, 'label'));
        $this->assertSame(['2026-06-15', '2026-06-30'], [$m[0]['start'], $m[0]['end']]);
        $this->assertSame('2026-11-10', $m[5]['end']);
    }

    public function test_open_days_are_distinct_closing_dates_in_scope(): void
    {
        $a = $this->branch('A');
        $b = $this->branch('B');
        $this->closing($a, '2026-09-01');
        $this->closing($a, '2026-09-03');
        $this->closing($b, '2026-09-03');

        $all = Period::make('2026-09-01', '2026-09-03', null);
        $this->assertSame(2, $all->openDays);
        $this->assertSame(['2026-09-01', '2026-09-03'], $all->openDates());
        $this->assertSame(3, $all->calendarDays);
        $this->assertSame(1, Period::make('2026-09-01', '2026-09-03', $b->id)->openDays);
        $this->assertSame(250.0, $all->perOpenDay(500));
        $this->assertSame(0.0, Period::make('2026-08-01', '2026-08-02', null)->perOpenDay(500));
    }

    public function test_to_array(): void
    {
        $b = $this->branch('A');

        $this->assertSame(
            ['from' => '2026-09-01', 'to' => '2026-09-03', 'calendar_days' => 3, 'open_days' => 0, 'bucket' => 'day', 'branch' => 'all'],
            Period::make('2026-09-01', '2026-09-03', null)->toArray(),
        );
        $this->assertSame($b->id, Period::make('2026-09-01', '2026-09-03', $b->id)->toArray()['branch']);
    }

    public function test_from_request_defaults(): void
    {
        $p = $this->fromRequest([]);

        $this->assertSame(['2026-11-01', '2026-11-23', null], [$p->from, $p->to, $p->branchId]);
        $this->assertNull($this->fromRequest(['branch' => 'all'])->branchId);
    }

    public function test_from_request_accepts_active_branch(): void
    {
        $b = $this->branch('A');

        $this->assertSame($b->id, $this->fromRequest(['branch' => (string) $b->id])->branchId);
    }

    public function test_from_request_rejects_bad_filters(): void
    {
        $inactive = $this->branch('X', false);

        foreach ([
            'to before from' => ['from' => '2026-11-10', 'to' => '2026-11-09'],
            'to after today' => ['from' => '2026-11-01', 'to' => '2026-11-24'],
            'malformed date' => ['from' => '2026-13-45', 'to' => '2026-11-20'],
            'not a date' => ['from' => 'abc'],
            'unknown branch' => ['branch' => '999999'],
            'inactive branch' => ['branch' => (string) $inactive->id],
            'garbage branch' => ['branch' => 'abc'],
        ] as $case => $query) {
            try {
                $this->fromRequest($query);
                $this->fail("Expected 400 for: {$case}");
            } catch (HttpException $e) {
                $this->assertSame(400, $e->getStatusCode(), $case);
            }
        }
    }
}
