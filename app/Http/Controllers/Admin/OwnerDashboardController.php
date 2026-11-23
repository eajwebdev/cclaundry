<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\OwnerDashboard\OverviewStats;
use App\Support\OwnerDashboard\Period;
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
}
