<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ZReading;
use App\Support\OwnerDashboard\ClosingView;
use App\Support\OwnerDashboard\OverviewStats;
use App\Support\OwnerDashboard\Period;
use App\Support\OwnerDashboard\ProfitStats;
use App\Support\OwnerDashboard\SuppliesStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The owner dashboard pages and the read-only JSON behind them. Admin only. */
class OwnerDashboardController extends Controller
{
    public function overviewPage(Request $request): View
    {
        return $this->page($request, 'overview');
    }

    public function suppliesPage(Request $request): View
    {
        return $this->page($request, 'supplies');
    }

    public function profitPage(Request $request): View
    {
        return $this->page($request, 'profit');
    }

    /** The server renders the filter bar; the page's script fetches the widgets. */
    private function page(Request $request, string $page): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $period = Period::fromRequest($request);
        $query = $request->only('from', 'to', 'branch');

        return view("admin.owner-dashboard.$page", [
            'page' => $page,
            'period' => $period,
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'links' => collect(['overview', 'supplies', 'profit'])->mapWithKeys(fn ($p) => [$p => route("owner-dashboard.$p", $query)])->all(),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(OverviewStats::for(Period::fromRequest($request)));
    }

    public function supplies(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(SuppliesStats::for(Period::fromRequest($request)));
    }

    public function profit(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(ProfitStats::for(Period::fromRequest($request)));
    }

    public function closing(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $date = $request->query('date');
        abort_unless(is_string($date), 400, 'Dates must be valid YYYY-MM-DD.');
        // A one-day Period reuses its date and branch validation.
        $period = Period::fromRequest($request->duplicate(['from' => $date, 'to' => $date, 'branch' => $request->query('branch', 'all')]));

        $closings = ZReading::query()
            ->whereDate('business_date', $date)
            ->when($period->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->with(['branch', 'closer', 'preparer'])
            ->get()
            ->map(fn ($z) => ClosingView::from($z))
            ->sortBy('branch')
            ->values();

        return response()->json(['date' => $date, 'branch' => $period->branchId ?? 'all', 'closings' => $closings]);
    }
}
