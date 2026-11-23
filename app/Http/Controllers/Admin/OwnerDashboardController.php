<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZReading;
use App\Support\OwnerDashboard\ClosingView;
use App\Support\OwnerDashboard\OverviewStats;
use App\Support\OwnerDashboard\Period;
use App\Support\OwnerDashboard\SuppliesStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only JSON for the owner dashboard pages. Admin only. */
class OwnerDashboardController extends Controller
{
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
