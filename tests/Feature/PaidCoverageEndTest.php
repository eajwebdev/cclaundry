<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchBillingRecord;
use App\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaidCoverageEndTest extends TestCase
{
    use RefreshDatabase;

    private function branch(): Branch
    {
        return Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Kabankalan City',
            'is_active' => true,
            'machine_count' => 3,
        ]);
    }

    private function paid(Branch $branch, string $start, string $end): BranchBillingRecord
    {
        return BranchBillingRecord::query()->create([
            'branch_id' => $branch->id,
            'billing_month' => Carbon::parse($start)->month,
            'billing_year' => Carbon::parse($start)->year,
            'subscription_start_date' => $start,
            'subscription_end_date' => $end,
            'due_date' => $start,
            'amount' => 1399,
            'status' => 'paid',
            'payment_date' => $start,
            'payment_method' => 'Cash',
        ]);
    }

    /** Monthly Oct 13 – Nov 12 plus a yearly Oct 13 – Oct 12 2027: covered until 2027. */
    public function test_overlapping_yearly_payment_extends_coverage(): void
    {
        $branch = $this->branch();
        $this->paid($branch, '2026-09-13', '2026-10-12');
        $this->paid($branch, '2026-10-13', '2026-11-12');
        $this->paid($branch, '2026-10-13', '2027-10-12');

        $end = app(SubscriptionBillingService::class)->paidCoverageEnd($branch, Carbon::parse('2026-11-11'));

        $this->assertSame('2027-10-12', $end->toDateString());
    }

    public function test_back_to_back_advance_payment_extends_coverage(): void
    {
        $branch = $this->branch();
        $this->paid($branch, '2026-10-13', '2026-11-12');
        $this->paid($branch, '2026-11-13', '2026-12-12');

        $end = app(SubscriptionBillingService::class)->paidCoverageEnd($branch, Carbon::parse('2026-11-11'));

        $this->assertSame('2026-12-12', $end->toDateString());
    }

    public function test_a_gap_stops_the_coverage(): void
    {
        $branch = $this->branch();
        $this->paid($branch, '2026-10-13', '2026-11-12');
        $this->paid($branch, '2027-01-13', '2027-02-12');

        $end = app(SubscriptionBillingService::class)->paidCoverageEnd($branch, Carbon::parse('2026-11-11'));

        $this->assertSame('2026-11-12', $end->toDateString());
    }
}
