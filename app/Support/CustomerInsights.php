<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\JobOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customer page's "who are our best customers" panel: headline numbers
 * for a period and short top-five lists. A visit is one job order that was not
 * cancelled, the same rule the visit counts and history use.
 */
class CustomerInsights
{
    public const PERIODS = [
        'month' => 'This month',
        '30d' => 'Last 30 days',
        'year' => 'This year',
        'all' => 'All time',
    ];

    /** Regulars who have not been in for this long land on the win-back list. */
    public const LAPSED_DAYS = 30;

    public const REGULAR_VISITS = 3;

    private const LIST_SIZE = 5;

    /**
     * @param  Builder<Customer>  $customers  the customers this user may see, already branch-filtered
     */
    public function __construct(private Builder $customers, private string $period) {}

    public static function periodStart(string $period): ?Carbon
    {
        return match ($period) {
            'month' => now()->startOfMonth(),
            '30d' => now()->subDays(30)->startOfDay(),
            'year' => now()->startOfYear(),
            default => null,
        };
    }

    public function toArray(): array
    {
        $start = self::periodStart($this->period);
        $inPeriod = $this->visits()->when($start, fn ($orders) => $orders->where('created_at', '>=', $start));

        $visitCount = (clone $inPeriod)->count();
        $revenue = (float) (clone $inPeriod)->sum('total');

        // Repeat rate, over all time: of everyone who has come in, how many came back.
        $visitsPerCustomer = $this->visits()->select('customer_id')->groupBy('customer_id')->selectRaw('COUNT(*) as visits');
        $visited = DB::query()->fromSub($visitsPerCustomer, 'per_customer')->count();
        $returned = DB::query()->fromSub($visitsPerCustomer, 'per_customer')->where('visits', '>=', 2)->count();

        $unpaid = $this->visits()->where('balance', '>', 0);

        return [
            'period' => $this->period,
            'periodLabel' => self::PERIODS[$this->period],
            'activeCustomers' => (clone $inPeriod)->distinct()->count('customer_id'),
            'newCustomers' => (clone $this->customers)->when($start, fn ($query) => $query->where('customers.created_at', '>=', $start))->count(),
            'repeatRate' => $visited > 0 ? round($returned / $visited * 100) : null,
            'returnedCustomers' => $returned,
            'visitedCustomers' => $visited,
            'averageSpend' => $visitCount > 0 ? $revenue / $visitCount : null,
            'visitCount' => $visitCount,
            'unpaidTotal' => (float) (clone $unpaid)->sum('balance'),
            'unpaidCustomers' => (clone $unpaid)->distinct()->count('customer_id'),
            'mostVisits' => $this->ranked($inPeriod, 'visits'),
            'topSpenders' => $this->ranked($inPeriod, 'spent'),
            'unpaidBalances' => $this->ranked($unpaid, 'owed'),
            'lapsedRegulars' => $this->lapsedRegulars(),
        ];
    }

    /** Job orders that count as visits, for the customers in view. */
    private function visits(): Builder
    {
        return JobOrder::query()
            ->where('status', '!=', 'cancelled')
            ->whereIn('customer_id', (clone $this->customers)->select('customers.id'));
    }

    /** Top customers by visits, money spent, or balance owed. */
    private function ranked(Builder $orders, string $by): Collection
    {
        $rows = (clone $orders)
            ->select('customer_id')
            ->selectRaw('COUNT(*) as visits, SUM(total) as spent, SUM(balance) as owed, MAX(created_at) as last_visit_at')
            ->groupBy('customer_id')
            ->orderByDesc($by)
            ->orderByDesc('last_visit_at')
            ->limit(self::LIST_SIZE)
            ->get();

        return $this->withCustomers($rows);
    }

    /** Regulars whose last visit was a while ago, most loyal first. */
    private function lapsedRegulars(): Collection
    {
        $rows = $this->visits()
            ->select('customer_id')
            ->selectRaw('COUNT(*) as visits, SUM(total) as spent, SUM(balance) as owed, MAX(created_at) as last_visit_at')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= ?', [self::REGULAR_VISITS])
            ->havingRaw('MAX(created_at) < ?', [now()->subDays(self::LAPSED_DAYS)])
            ->orderByDesc('visits')
            ->orderBy('last_visit_at')
            ->limit(self::LIST_SIZE)
            ->get();

        return $this->withCustomers($rows);
    }

    private function withCustomers(Collection $rows): Collection
    {
        $customers = Customer::query()
            ->whereIn('id', $rows->pluck('customer_id'))
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        return $rows
            ->map(fn ($row) => (object) [
                'customer' => $customers->get($row->customer_id),
                'visits' => (int) $row->visits,
                'spent' => (float) $row->spent,
                'owed' => (float) $row->owed,
                'last_visit_at' => $row->last_visit_at ? Carbon::parse($row->last_visit_at) : null,
            ])
            ->filter(fn ($row) => $row->customer)
            ->values();
    }
}
