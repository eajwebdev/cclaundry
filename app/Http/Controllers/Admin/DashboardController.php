<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\Customer;
use App\Models\DailyTask;
use App\Models\DailyTaskCompletion;
use App\Models\EmployeeAttendanceRecord;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\InventoryMovement;
use App\Models\LaundryService;
use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Models\SiteVisit;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;
use App\Models\AccountsPayable;
use App\Models\AttendanceEmployee;
use App\Support\StatusBadge;
use App\Support\FinancialReconciliation;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->isAdmin();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        return view('dashboard', [
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'dashboardData' => $this->payload($request),
            'dateRangeValue' => $this->dateRangeValue($request),
            'selectedBranchId' => $this->branchId($request),
            'settings' => SystemSetting::current(),
            'activeTab' => $request->query('tab', 'today'),
            'currentPeriod' => $this->currentPeriod($request),
        ]);
    }

    public function data(Request $request)
    {
        return response()->json($this->payload($request));
    }

    public function assistant(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'admin', 'branch_manager'], true), 403);

        $validated = $request->validate([
            'preset' => ['nullable', 'string'],
            'question' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_range' => ['nullable', 'string', 'max:50'],
        ]);

        $preset = $validated['preset'] ?? $this->inferAssistantPreset($validated['question'] ?? '');
        abort_unless(array_key_exists($preset, $this->assistantPresets()), 422);

        return response()->json($this->assistantAnswer($request, $preset, $validated['question'] ?? null));
    }

    public function assistantOptions(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'admin', 'branch_manager'], true), 403);

        $canChooseBranch = $request->user()->canManageAllBranches();

        return response()->json([
            'can_choose_branch' => $canChooseBranch,
            'branches' => Branch::query()
                ->where('is_active', true)
                ->when(! $canChooseBranch, fn ($query) => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name])
                ->values(),
        ]);
    }

    private function payload(Request $request): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchId = $this->branchId($request);
        $currency = SystemSetting::current()->currency ?: 'PHP';
        $financial = FinancialReconciliation::forPeriod($branchId, $dateFrom, $dateTo);

        $orders = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        $ordersInRange = (clone $orders)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        $payments = Payment::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $collections = Payment::query()
            ->when($branchId, fn ($query) => $query->where('collected_branch_id', $branchId))
            ->whereIn('payment_type', ['cash', 'gcash', 'bank'])
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $salesTotal = (float) (clone $payments)->sum('amount');
        $collectionsTotal = $financial['physical_collections'];
        $ordersCount = (clone $ordersInRange)->count();
        $openOrders = (clone $orders)->whereNotIn('status', ['completed', 'cancelled'])->count();
        $readyForPickup = (clone $orders)->where('status', 'ready_for_pickup')->count();
        $readyForDelivery = (clone $orders)->where('status', 'ready_for_delivery')->count();
        $receivables = $financial['unpaid_balance'];
        $lowStock = Inventory::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->count();
        $lowStockItems = Inventory::query()
            ->with(['branch:id,name', 'supplier:id,name'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (Inventory $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'branch' => $item->branch?->name ?? 'N/A',
                'supplier' => $item->supplier?->name ?? 'No supplier',
                'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.'),
                'reorder_level' => rtrim(rtrim(number_format((float) $item->reorder_level, 4, '.', ''), '0'), '.'),
                'unit' => $item->unit,
            ])
            ->values();
        $accountsPayable = $financial['accounts_payable'];

        $salesByDate = (clone $payments)
            ->selectRaw('DATE(paid_at) as paid_date, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('paid_date')
            ->pluck('total_amount', 'paid_date');

        $visitsByDate = collect();
        $topVisitorLocations = collect();
        $uniqueBrowserCount = 0;
        $newVisitorCount = 0;
        if (Schema::hasTable('site_visits')) {
            $visitsByDate = SiteVisit::query()
                ->whereDate('visited_on', '>=', $dateFrom)
                ->whereDate('visited_on', '<=', $dateTo)
                ->selectRaw('DATE(visited_on) as visit_date, COUNT(DISTINCT visitor_hash) as total')
                ->groupBy('visit_date')
                ->pluck('total', 'visit_date');

            $uniqueBrowserCount = SiteVisit::query()
                ->whereDate('visited_on', '>=', $dateFrom)
                ->whereDate('visited_on', '<=', $dateTo)
                ->distinct()
                ->count('visitor_hash');

            // A standard period-level visitor total counts a browser once for
            // the whole range. Classify it as first-time only when its earliest
            // recorded visit falls inside the selected range; otherwise it is
            // returning from an earlier period.
            $firstVisitByBrowser = SiteVisit::query()
                ->selectRaw('visitor_hash, MIN(visited_on) as first_visited_on')
                ->groupBy('visitor_hash');

            $newVisitorCount = DB::query()
                ->fromSub($firstVisitByBrowser, 'first_visits')
                ->whereDate('first_visited_on', '>=', $dateFrom)
                ->whereDate('first_visited_on', '<=', $dateTo)
                ->count();

            $topVisitorLocations = SiteVisit::query()
                ->whereDate('visited_on', '>=', $dateFrom)
                ->whereDate('visited_on', '<=', $dateTo)
                ->where(fn ($query) => $query
                    ->whereNotNull('city')
                    ->orWhereNotNull('region')
                    ->orWhereNotNull('country_code'))
                ->select(['city', 'region', 'country_code', DB::raw('COUNT(DISTINCT visitor_hash) as total')])
                ->groupBy('city', 'region', 'country_code')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn (SiteVisit $visit) => [
                    'label' => collect([$visit->city, $visit->region, $visit->country_code])->filter()->unique()->implode(', '),
                    'count' => number_format((int) $visit->total),
                ])
                ->values();
        }

        $salesLabels = [];
        $salesValues = [];
        $visitorValues = [];
        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $key = $date->toDateString();
            $salesLabels[] = $date->format('M d');
            $salesValues[] = round((float) ($salesByDate[$key] ?? 0), 2);
            $visitorValues[] = (int) ($visitsByDate[$key] ?? 0);
        }
        $totalDailyUniqueVisits = array_sum($visitorValues);
        $returningVisitorCount = max(0, $uniqueBrowserCount - $newVisitorCount);

        $statusRows = (clone $ordersInRange)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = ['pending', 'washing', 'drying', 'folding', 'ready_for_pickup', 'ready_for_delivery', 'completed', 'cancelled'];
        $statusLabels = array_map(fn ($status) => StatusBadge::label($status), $statuses);
        $statusValues = array_map(fn ($status) => (int) ($statusRows[$status] ?? 0), $statuses);

        $paymentMixRows = (clone $collections)
            ->selectRaw('payment_type, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('payment_type')
            ->orderByDesc('total_amount')
            ->get();

        $topServices = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->leftJoin('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->groupByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->orderByDesc('total_amount')
            ->limit(8)
            ->get([
                DB::raw('COALESCE(laundry_services.name, job_order_items.description) as label'),
                DB::raw('COALESCE(SUM(job_order_items.quantity), 0) as quantity'),
                DB::raw('COALESCE(SUM(job_order_items.total), 0) as total_amount'),
            ]);

        $topPresets = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->join('service_presets', 'service_presets.id', '=', 'job_order_items.service_preset_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->groupBy('service_presets.id', 'service_presets.name')
            ->orderByDesc('total_amount')
            ->limit(8)
            ->get([
                'service_presets.name as label',
                DB::raw('COUNT(DISTINCT job_orders.id) as orders_count'),
                DB::raw('COALESCE(SUM(job_order_items.total), 0) as total_amount'),
            ]);

        $transactionRows = (clone $ordersInRange)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('transaction_type, COUNT(*) as total')
            ->groupBy('transaction_type')
            ->pluck('total', 'transaction_type');

        $branchSales = Payment::query()
            ->join('branches', 'payments.branch_id', '=', 'branches.id')
            ->when($branchId, fn ($query) => $query->where('payments.branch_id', $branchId))
            ->whereDate('payments.paid_at', '>=', $dateFrom)
            ->whereDate('payments.paid_at', '<=', $dateTo)
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('total_amount')
            ->limit(8)
            ->get([
                'branches.name as label',
                DB::raw('COALESCE(SUM(payments.amount), 0) as total_amount'),
            ]);

        $recentOrders = (clone $orders)
            ->with(['customer', 'branch'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (JobOrder $order) => [
                'id' => $order->id,
                'number' => $order->job_order_number,
                'customer' => $order->customer?->name ?? 'Walk-in',
                'branch' => $order->branch?->name ?? 'N/A',
                'status' => StatusBadge::label($order->status),
                'status_badge' => StatusBadge::classes($order->status),
                'total' => $this->money($currency, (float) $order->total),
                'url' => route('admin.job-orders.show', $order),
            ])
            ->values();

        $trustedCustomers = Customer::query()
            ->with('branch')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas(
                'jobOrders',
                fn ($query) => $query->when($branchId, fn ($query) => $query->where('branch_id', $branchId)),
                '>=',
                10
            )
            ->withCount(['jobOrders as orders_count' => fn ($query) => $query
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))])
            ->withMax(['jobOrders as latest_order_at' => fn ($query) => $query
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))], 'created_at')
            ->orderByDesc('orders_count')
            ->orderByDesc('latest_order_at')
            ->limit(6)
            ->get()
            ->map(function (Customer $customer) use ($branchId) {
                $latestOrder = $customer->jobOrders()
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->latest()
                    ->first();

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone ?: 'No phone provided',
                    'branch' => $customer->branch?->name ?? 'N/A',
                    'orders_count' => number_format($customer->orders_count),
                    'latest_order' => $latestOrder?->created_at?->format('M d, Y') ?? 'N/A',
                    'status' => $latestOrder ? StatusBadge::label($latestOrder->status) : 'No orders',
                    'status_badge' => $latestOrder ? StatusBadge::classes($latestOrder->status) : StatusBadge::classes('pending'),
                ];
            })
            ->values();

        return [
            'currency' => $currency,
            'generated_at' => now()->format('M d, Y h:i:s A'),
            'stats' => [
                'sales' => $this->money($currency, $financial['sales_owned']),
                'collections' => $this->money($currency, $collectionsTotal),
                'cash_drawer' => $this->money($currency, $financial['expected_cash_drawer']),
                'gcash' => $this->money($currency, $financial['expected_gcash']),
                'expenses' => $this->money($currency, $financial['expenses_total']),
                'orders' => number_format($ordersCount),
                'open_orders' => number_format($openOrders),
                'ready_for_pickup' => number_format($readyForPickup),
                'ready_for_delivery' => number_format($readyForDelivery),
                'receivables' => $this->money($currency, $receivables),
                'low_stock' => number_format($lowStock),
                'accounts_payable' => $this->money($currency, $accountsPayable),
                'over_short' => $this->money($currency, $financial['over_short']),
                'unique_site_visits' => number_format($uniqueBrowserCount),
                'daily_unique_site_visits' => number_format($totalDailyUniqueVisits),
            ],
            'charts' => [
                'sales' => [
                    'labels' => $salesLabels,
                    'values' => $salesValues,
                ],
                'site_visits' => [
                    'labels' => $salesLabels,
                    'values' => $visitorValues,
                ],
                'visitor_summary' => [
                    'labels' => ['First-time visitors', 'Returning visitors'],
                    'values' => [$newVisitorCount, $returningVisitorCount],
                ],
                'status' => [
                    'labels' => $statusLabels,
                    'values' => $statusValues,
                ],
                'payment_mix' => [
                    'labels' => $paymentMixRows->map(fn ($row) => StatusBadge::label($row->payment_type))->values(),
                    'values' => $paymentMixRows->map(fn ($row) => round((float) $row->total_amount, 2))->values(),
                ],
                'top_services' => [
                    'labels' => $topServices->pluck('label')->values(),
                    'values' => $topServices->map(fn ($row) => round((float) $row->total_amount, 2))->values(),
                ],
                'top_presets' => [
                    'labels' => $topPresets->pluck('label')->values(),
                    'values' => $topPresets->map(fn ($row) => round((float) $row->total_amount, 2))->values(),
                ],
                'transaction_types' => [
                    'labels' => ['Walk-in / Drop Off', 'Delivery / Pick-up'],
                    'values' => [(int) ($transactionRows['walk_in'] ?? 0), (int) ($transactionRows['delivery'] ?? 0)],
                ],
                'branch_sales' => [
                    'labels' => $branchSales->pluck('label')->values(),
                    'values' => $branchSales->map(fn ($row) => round((float) $row->total_amount, 2))->values(),
                ],
                'financial_snapshot' => [
                    'labels' => ['Collections', 'Expenses', 'Receivables', 'Accounts Payable'],
                    'values' => [
                        round((float) $financial['physical_collections'], 2),
                        round((float) $financial['expenses_total'], 2),
                        round((float) $financial['unpaid_balance'], 2),
                        round((float) $financial['accounts_payable'], 2),
                    ],
                ],
            ],
            'top_services' => $topServices->map(fn ($row) => [
                'label' => $row->label,
                'quantity' => number_format((float) $row->quantity, 2),
                'amount' => $this->money($currency, (float) $row->total_amount),
            ])->values(),
            'top_presets' => $topPresets->map(fn ($row) => [
                'label' => $row->label,
                'orders_count' => number_format((int) $row->orders_count),
                'amount' => $this->money($currency, (float) $row->total_amount),
            ])->values(),
            'recent_orders' => $recentOrders,
            'low_stock_items' => $lowStockItems,
            'top_visitor_locations' => $topVisitorLocations,
            'trusted_customers' => $trustedCustomers,
            'current_month_name' => now()->format('F'),
            'current_month_year' => now()->format('F Y'),
            'today' => $this->buildTodayData($request, $branchId, $dateFrom, $dateTo, $currency, $financial, $orders, $payments, $collections),
            'supplies' => $this->buildSuppliesData($request, $branchId, $dateFrom, $dateTo, $currency),
            'monthly_costs' => $this->buildMonthlyCostsData($request, $branchId, $dateFrom, $dateTo, $currency),
            'active_tab' => $request->query('tab', 'today'),
            'current_period' => $this->currentPeriod($request),
        ];
    }

    private function buildTodayData(
        Request $request,
        ?int $branchId,
        string $dateFrom,
        string $dateTo,
        string $currency,
        array $financial,
        $orders,
        $payments,
        $collections
    ): array {
        $branchName = 'Main Branch';
        if ($branchId) {
            $branchName = Branch::query()->whereKey($branchId)->value('name') ?: 'Main Branch';
        } elseif ($request->user() && $request->user()->branch) {
            $branchName = $request->user()->branch->name;
        }

        $ordersInRange = (clone $orders)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        $ordersCount = (clone $ordersInRange)->count();
        $salesOwned = (float) $financial['sales_owned'];
        $avgPerOrder = $ordersCount > 0 ? round($salesOwned / $ordersCount, 2) : 0;

        $prevDateFrom = Carbon::parse($dateFrom)->subDays(7)->toDateString();
        $prevDateTo = Carbon::parse($dateTo)->subDays(7)->toDateString();
        $prevSales = (float) Payment::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $prevDateFrom)
            ->whereDate('paid_at', '<=', $prevDateTo)
            ->sum('amount');

        $vsLastWeek = '[—]';
        if ($prevSales > 0) {
            $diffPct = round((($salesOwned - $prevSales) / $prevSales) * 100);
            $vsLastWeek = ($diffPct >= 0 ? "+{$diffPct}%" : "{$diffPct}%") . ' vs last week';
        }

        $collectionsTotal = (float) $financial['physical_collections'];
        $collectionPercent = $salesOwned > 0 ? min(100, (int) round(($collectionsTotal / $salesOwned) * 100)) : 0;
        $unpaidBalance = (float) $financial['unpaid_balance'];

        $expensesTotal = (float) $financial['expenses_total'];
        $netForDay = round($collectionsTotal - $expensesTotal, 2);

        $billsDue = (float) AccountsPayable::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', $dateTo)
            ->sum('balance');

        $washing = (clone $ordersInRange)->where('status', 'washing')->count();
        $drying = (clone $ordersInRange)->where('status', 'drying')->count();
        $folding = (clone $ordersInRange)->where('status', 'folding')->count();
        $readyPickup = (clone $ordersInRange)->where('status', 'ready_for_pickup')->count();
        $readyDelivery = (clone $ordersInRange)->where('status', 'ready_for_delivery')->count();
        $completed = (clone $ordersInRange)->where('status', 'completed')->count();
        $cancelled = (clone $ordersInRange)->where('status', 'cancelled')->count();
        $openCount = (clone $ordersInRange)->whereNotIn('status', ['completed', 'cancelled'])->count();

        $finishedCount = $readyPickup + $readyDelivery;
        if ($finishedCount > 0) {
            $deliveriesText = $readyDelivery === 1 ? '1 delivery' : "{$readyDelivery} deliveries";
            $pickupsText = $readyPickup === 1 ? '1 customer' : "{$readyPickup} customers";
            $ordersText = $finishedCount === 1 ? '1 order is' : "{$finishedCount} orders are";
            $actionNotice = "{$ordersText} finished and waiting to leave the shop. Send the rider for {$deliveriesText} and text {$pickupsText} to pick up.";
        } else {
            $actionNotice = "No finished orders waiting to leave the shop right now.";
        }

        $cashDrawer = (float) $financial['expected_cash_drawer'];
        $gcash = (float) $financial['expected_gcash'];
        $bank = (float) $financial['expected_bank'];
        $totalExpected = round($cashDrawer + $gcash + $bank, 2);

        $itemsQuery = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->leftJoin('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($q) => $q->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->groupByRaw('COALESCE(laundry_services.name, job_order_items.description), laundry_services.pricing_type, laundry_services.minimum_kilos, laundry_services.kilos_per_load')
            ->orderByDesc(DB::raw('SUM(job_order_items.total)'))
            ->get([
                DB::raw('COALESCE(laundry_services.name, job_order_items.description) as service_name'),
                DB::raw('SUM(job_order_items.total) as total_amount'),
                DB::raw('SUM(job_order_items.quantity) as total_qty'),
                'laundry_services.pricing_type',
                'laundry_services.minimum_kilos',
                'laundry_services.kilos_per_load',
            ]);

        $orderedServices = [];
        $totalKg = 0;
        foreach ($itemsQuery as $item) {
            $qty = (float) $item->total_qty;
            $amt = (float) $item->total_amount;
            $minKilos = (float) ($item->minimum_kilos ?: 5);
            $maxLoads = (float) ($item->kilos_per_load ?: 10);

            $detail = "{$qty} kg · min {$minKilos} kg";
            if ($item->pricing_type === 'load') {
                $detail = "{$qty} loads · max {$maxLoads} kg";
                $totalKg += ($qty * $maxLoads);
            } else {
                $totalKg += max($qty, $minKilos);
            }

            $orderedServices[] = [
                'name' => $item->service_name,
                'amount' => $this->money($currency, $amt),
                'amount_raw' => $amt,
                'detail' => $detail,
            ];
        }

        $walkInCount = (int) (clone $ordersInRange)->where('status', '!=', 'cancelled')->where('transaction_type', 'walk_in')->count();
        $deliveryCount = (int) (clone $ordersInRange)->where('status', '!=', 'cancelled')->where('transaction_type', 'delivery')->count();
        $totalMethods = $walkInCount + $deliveryCount;
        $deliveryPct = $totalMethods > 0 ? (int) round(($deliveryCount / $totalMethods) * 100) : 0;
        $walkInPct = $totalMethods > 0 ? (100 - $deliveryPct) : 0;

        $lowStockQuery = Inventory::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'reorder_level');
        $lowStockCount = (clone $lowStockQuery)->count();
        $lowStockNames = (clone $lowStockQuery)->limit(3)->pluck('name')->implode(', ');

        $suppliesTitle = $lowStockCount === 0 ? 'Supplies OK' : 'Low Stock Alert';
        $suppliesDesc = $lowStockCount === 0 
            ? 'Detergent, softener & bags above reorder level' 
            : "{$lowStockCount} items at or below reorder level" . ($lowStockNames ? " ({$lowStockNames})" : '');

        $visitorCount = 0;
        if (Schema::hasTable('site_visits')) {
            $visitorCount = SiteVisit::query()
                ->whereDate('visited_on', '>=', $dateFrom)
                ->whereDate('visited_on', '<=', $dateTo)
                ->distinct()
                ->count('visitor_hash');
        }

        $repeatCount = Customer::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->has('jobOrders', '>=', 3)
            ->count();

        $recentOrders = (clone $ordersInRange)
            ->with(['customer', 'branch'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(function (JobOrder $order) use ($currency) {
                $nextStep = match ($order->status) {
                    'ready_for_delivery' => 'Assign rider',
                    'ready_for_pickup' => 'Text customer',
                    'washing' => 'Move to dryer',
                    'drying' => 'Fold laundry',
                    'folding' => 'Bag & complete',
                    'pending' => 'Start washing',
                    'completed' => '—',
                    'cancelled' => '—',
                    default => 'Review order',
                };

                $statusLabel = match ($order->status) {
                    'ready_for_delivery' => 'For delivery',
                    'ready_for_pickup' => 'For pickup',
                    'completed' => 'Completed',
                    'washing' => 'Washing',
                    'drying' => 'Drying',
                    'folding' => 'Folding',
                    'pending' => 'Pending',
                    'cancelled' => 'Cancelled',
                    default => StatusBadge::label($order->status),
                };

                return [
                    'id' => $order->id,
                    'number' => $order->job_order_number,
                    'customer' => $order->customer?->name ?: '[Customer]',
                    'status' => $order->status,
                    'status_label' => $statusLabel,
                    'next_step' => $nextStep,
                    'total' => $this->money($currency, (float) $order->total),
                    'url' => route('admin.job-orders.show', $order),
                ];
            })
            ->values();

        return [
            'header' => [
                'branch_name' => $branchName,
                'date_formatted' => Carbon::parse($dateTo)->format('l, F j'),
                'range_label' => $this->rangeLabel($dateFrom, $dateTo),
                'time_formatted' => now()->format('g:i A'),
            ],
            'sales_today' => $this->money($currency, $salesOwned),
            'orders_count' => $ordersCount,
            'avg_per_order' => $this->money($currency, $avgPerOrder),
            'sales_vs_last_week' => $vsLastWeek,
            'money_collected' => $this->money($currency, $collectionsTotal),
            'collection_percent' => $collectionPercent,
            'customers_owe' => $this->money($currency, $unpaidBalance),
            'net_for_day' => $this->money($currency, $netForDay),
            'expenses_day' => $this->money($currency, $expensesTotal),
            'bills_due' => $this->money($currency, $billsDue),
            'pipeline' => [
                'washing' => $washing,
                'drying' => $drying,
                'folding' => $folding,
                'ready_pickup' => $readyPickup,
                'ready_delivery' => $readyDelivery,
                'completed' => $completed,
                'open_count' => $openCount,
                'done_count' => $completed,
                'cancelled_count' => $cancelled,
                'notice' => $actionNotice,
            ],
            'cash_check' => [
                'cash_drawer' => $this->money($currency, $cashDrawer),
                'cash_drawer_raw' => $cashDrawer,
                'gcash' => $this->money($currency, $gcash),
                'gcash_raw' => $gcash,
                'total_expected' => $this->money($currency, $totalExpected),
                'total_expected_raw' => $totalExpected,
            ],
            'ordered_services' => $orderedServices,
            'total_kg_washed' => round($totalKg, 1),
            'order_sources' => [
                'delivery_pct' => $deliveryPct,
                'walk_in_pct' => $walkInPct,
                'delivery_count' => $deliveryCount,
                'walk_in_count' => $walkInCount,
            ],
            'supplies' => [
                'is_ok' => $lowStockCount === 0,
                'title' => $suppliesTitle,
                'description' => $suppliesDesc,
            ],
            'website' => [
                'visitors_count' => $visitorCount,
                'subtitle' => $visitorCount > 0 ? 'Mostly first-time visitors' : 'No visitors recorded today',
                'repeat_text' => $repeatCount > 0 ? "Repeat customers (3+ orders): {$repeatCount}" : 'Repeat customers (3+ orders): none yet',
            ],
            'recent_orders' => $recentOrders,
        ];
    }

    private function buildSuppliesData(
        Request $request,
        ?int $branchId,
        string $dateFrom,
        string $dateTo,
        string $currency
    ): array {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $dayOfMonth = max(1, now()->day);

        $inventoryQuery = Inventory::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->get();

        $items = [];
        $reorderCount = 0;
        $lowestDays = 999;
        $urgentItemName = 'Items';

        $totalSuppliesUsed = 0;
        $totalSuppliesRestocked = 0;

        foreach ($inventoryQuery as $item) {
            $qty = (float) $item->quantity;
            $reorder = (float) $item->reorder_level;
            $unitCost = (float) ($item->unit_cost ?: 0);

            $used = (float) InventoryMovement::query()
                ->where('inventory_id', $item->id)
                ->where('movement_type', 'out')
                ->whereDate('created_at', '>=', $monthStart)
                ->whereDate('created_at', '<=', $monthEnd)
                ->sum('quantity');

            $restocked = (float) InventoryMovement::query()
                ->where('inventory_id', $item->id)
                ->where('movement_type', 'in')
                ->whereDate('created_at', '>=', $monthStart)
                ->whereDate('created_at', '<=', $monthEnd)
                ->sum('quantity');

            $totalSuppliesUsed += ($used * $unitCost);
            $totalSuppliesRestocked += ($restocked * $unitCost);

            $dailyUse = $dayOfMonth > 0 ? round($used / $dayOfMonth, 1) : 0;
            $daysLeft = $dailyUse > 0 ? (int) round($qty / $dailyUse) : ($qty > 0 ? '90+' : '0');

            $status = 'OK';
            if ($qty <= $reorder) {
                $status = 'Reorder now';
                $reorderCount++;
                if ($daysLeft !== '90+' && (int)$daysLeft < $lowestDays) {
                    $lowestDays = (int)$daysLeft;
                    $urgentItemName = $item->name;
                } elseif ($lowestDays === 999) {
                    $lowestDays = 0;
                    $urgentItemName = $item->name;
                }
            } elseif ($qty <= $reorder * 1.5) {
                $status = 'Low soon';
            }

            $maxBar = max($reorder * 2, $qty, 1);
            $pct = min(100, (int) round(($qty / $maxBar) * 100));
            $markerPct = min(100, (int) round(($reorder / $maxBar) * 100));

            $items[] = [
                'name' => $item->name,
                'unit' => $item->unit ?: 'units',
                'quantity' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' ' . ($item->unit ?: 'units'),
                'reorder_level' => rtrim(rtrim(number_format($reorder, 2, '.', ''), '0'), '.') . ' ' . ($item->unit ?: 'units'),
                'used_in_sep' => rtrim(rtrim(number_format($used, 2, '.', ''), '0'), '.') . ' ' . ($item->unit ?: 'units'),
                'used_this_month' => rtrim(rtrim(number_format($used, 2, '.', ''), '0'), '.') . ' ' . ($item->unit ?: 'units'),
                'daily_use' => rtrim(rtrim(number_format($dailyUse, 2, '.', ''), '0'), '.') . ' ' . ($item->unit ?: 'units'),
                'days_left' => $daysLeft,
                'status' => $status,
                'percent' => $pct,
                'marker_pct' => $markerPct,
            ];
        }

        if ($reorderCount > 0) {
            $urgentWarning = ($lowestDays <= 0)
                ? "{$urgentItemName} is out of stock (0 remaining)"
                : "{$urgentItemName} runs out in ~{$lowestDays} days";
        } else {
            $urgentWarning = 'All items above reorder level';
        }

        $totalKgMonth = (float) JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->leftJoin('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($q) => $q->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $monthStart)
            ->whereDate('job_orders.created_at', '<=', $monthEnd)
            ->sum('job_order_items.quantity') ?: 0;

        $costPerKg = $totalKgMonth > 0 ? round($totalSuppliesUsed / $totalKgMonth, 2) : 0.00;

        $monthlySales = (float) Payment::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $monthStart)
            ->whereDate('paid_at', '<=', $monthEnd)
            ->sum('amount');

        $addonsQuery = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($q) => $q->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $monthStart)
            ->whereDate('job_orders.created_at', '<=', $monthEnd)
            ->where(function ($q) {
                $q->whereIn('job_order_items.service_category', ['fabcon', 'detergent', 'dry_extend', 'finishing_spray', 'rush', 'other'])
                  ->orWhere('job_order_items.description', 'like', '%fabcon%')
                  ->orWhere('job_order_items.description', 'like', '%detergent%')
                  ->orWhere('job_order_items.description', 'like', '%stain%')
                  ->orWhere('job_order_items.description', 'like', '%hanger%')
                  ->orWhere('job_order_items.description', 'like', '%booster%');
            })
            ->groupBy('job_order_items.description')
            ->orderByDesc(DB::raw('SUM(job_order_items.total)'))
            ->get([
                'job_order_items.description as name',
                DB::raw('SUM(job_order_items.total) as revenue'),
                DB::raw('SUM(job_order_items.quantity) as sold'),
            ]);

        $addonsList = [];
        $totalAddonsRevenue = 0;
        $totalAddonsUnits = 0;
        $topAddonName = null;
        foreach ($addonsQuery as $addon) {
            $rev = (float) $addon->revenue;
            $cnt = (int) $addon->sold;
            $totalAddonsRevenue += $rev;
            $totalAddonsUnits += $cnt;
            if (! $topAddonName) {
                $topAddonName = $addon->name;
            }
            $addonsList[] = [
                'name' => $addon->name,
                'rate_label' => $cnt > 0 ? $this->money($currency, round($rev / $cnt, 2)) : '',
                'revenue' => $this->money($currency, $rev),
                'revenue_raw' => $rev,
                'sold' => $cnt,
            ];
        }

        $addonPctOfSales = $monthlySales > 0 ? round(($totalAddonsRevenue / $monthlySales) * 100) : 0;
        $upsellTip = $topAddonName
            ? "{$topAddonName} is the best seller. Offer it at the counter on every order — it is the easiest upsell."
            : 'Offer add-on services like extra fabcon or stain removal at the counter on every order — it is the easiest upsell.';

        $movements = InventoryMovement::query()
            ->with('inventory')
            ->when($branchId, fn ($q) => $q->whereHas('inventory', fn ($iq) => $iq->where('branch_id', $branchId)))
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (InventoryMovement $m) use ($currency) {
                $qtySign = match ($m->movement_type) {
                    'in' => '+',
                    'out' => '-',
                    'adjust' => ($m->quantity >= 0 ? '+' : ''),
                    default => '',
                };
                $unit = $m->inventory?->unit ?: 'units';
                $unitCost = (float) ($m->inventory?->unit_cost ?: 0);
                $value = round(abs((float) $m->quantity) * $unitCost, 2);

                return [
                    'date' => $m->created_at->format('M j'),
                    'type' => ucfirst($m->movement_type),
                    'type_badge' => match ($m->movement_type) {
                        'in' => 'bg-[#DCFCE7] text-[#15803D]',
                        'out' => 'bg-[#FDF1E2] text-[#C2410C]',
                        default => 'bg-[#F3E8FF] text-[#7E22CE]',
                    },
                    'item' => $m->inventory?->name ?: 'Item',
                    'qty' => $qtySign . rtrim(rtrim(number_format(abs((float) $m->quantity), 2, '.', ''), '0'), '.') . ' ' . $unit,
                    'note' => $m->remarks ?: 'Stock update',
                    'value' => $this->money($currency, $value),
                ];
            });

        $month = now();
        $weeks = [
            ['label' => $month->format('M').' 1–7', 'start' => $month->copy()->startOfMonth()->toDateString(), 'end' => $month->copy()->startOfMonth()->addDays(6)->toDateString()],
            ['label' => $month->format('M').' 8–14', 'start' => $month->copy()->startOfMonth()->addDays(7)->toDateString(), 'end' => $month->copy()->startOfMonth()->addDays(13)->toDateString()],
            ['label' => $month->format('M').' 15–21', 'start' => $month->copy()->startOfMonth()->addDays(14)->toDateString(), 'end' => $month->copy()->startOfMonth()->addDays(20)->toDateString()],
            ['label' => $month->format('M').' 22–'.$month->daysInMonth, 'start' => $month->copy()->startOfMonth()->addDays(21)->toDateString(), 'end' => $month->copy()->endOfMonth()->toDateString()],
        ];

        $currentStockValue = (float) Inventory::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->sum(DB::raw('quantity * unit_cost'));

        $stockMovementByWeek = [];
        $maxWeeklyVal = 1;
        foreach ($weeks as $w) {
            $inVal = (float) InventoryMovement::query()
                ->join('inventories', 'inventories.id', '=', 'inventory_movements.inventory_id')
                ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId))
                ->where('inventory_movements.movement_type', 'in')
                ->whereDate('inventory_movements.created_at', '>=', $w['start'])
                ->whereDate('inventory_movements.created_at', '<=', $w['end'])
                ->selectRaw('SUM(inventory_movements.quantity * inventories.unit_cost) as total')
                ->value('total') ?: 0;

            $outVal = (float) InventoryMovement::query()
                ->join('inventories', 'inventories.id', '=', 'inventory_movements.inventory_id')
                ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId))
                ->where('inventory_movements.movement_type', 'out')
                ->whereDate('inventory_movements.created_at', '>=', $w['start'])
                ->whereDate('inventory_movements.created_at', '<=', $w['end'])
                ->selectRaw('SUM(inventory_movements.quantity * inventories.unit_cost) as total')
                ->value('total') ?: 0;

            if ($inVal > $maxWeeklyVal) $maxWeeklyVal = $inVal;
            if ($outVal > $maxWeeklyVal) $maxWeeklyVal = $outVal;

            $stockMovementByWeek[] = [
                'week' => $w['label'],
                'in' => round($inVal, 2),
                'out' => round($outVal, 2),
                'stock' => round($currentStockValue, 2),
            ];
        }

        $movementCaption = ($totalSuppliesUsed > 0 || $totalSuppliesRestocked > 0)
            ? "Weekly stock movement based on real inventory logs. On-hand value: {$this->money($currency, $currentStockValue)}."
            : "Total on-hand stock value is currently {$this->money($currency, $currentStockValue)}. Weekly restock and usage will display here as movements are recorded.";

        $netDiff = $totalSuppliesRestocked - $totalSuppliesUsed;
        $netSign = $netDiff >= 0 ? '+' : '';

        return [
            'reorder_count' => $reorderCount,
            'urgent_warning' => $urgentWarning,
            'supplies_used_month' => $this->money($currency, $totalSuppliesUsed),
            'restocked_note' => "Restocked {$this->money($currency, $totalSuppliesRestocked)} · net {$netSign}{$this->money($currency, $netDiff)}",
            'cost_per_kg' => $this->money($currency, $costPerKg),
            'cost_per_kg_sub' => number_format($totalKgMonth, 1) . ' kg washed · target under ₱1.40',
            'addon_sales_total' => $this->money($currency, $totalAddonsRevenue),
            'addon_sales_sub' => "{$totalAddonsUnits} add-ons sold · {$addonPctOfSales}% of sales",
            'consumables' => $items,
            'addons_sold' => $addonsList,
            'upsell_tip' => $upsellTip,
            'stock_movements' => $movements,
            'stock_movement_by_week' => $stockMovementByWeek,
            'max_weekly_val' => $maxWeeklyVal,
            'movement_caption' => $movementCaption,
        ];
    }

    private function buildMonthlyCostsData(
        Request $request,
        ?int $branchId,
        string $dateFrom,
        string $dateTo,
        string $currency
    ): array {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $billsExpenses = BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $monthEnd)
            ->whereIn('expense_type', ['utilities', 'rent', 'government_fees'])
            ->get();

        $billsTotal = (float) $billsExpenses->sum('amount');

        $lastMonthStart = now()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd = now()->subMonth()->endOfMonth()->toDateString();
        $lastMonthName = now()->subMonth()->format('F');
        $lastMonthBills = (float) BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $lastMonthStart)
            ->whereDate('expense_date', '<=', $lastMonthEnd)
            ->whereIn('expense_type', ['utilities', 'rent', 'government_fees'])
            ->sum('amount');

        $billsDiff = $billsTotal - $lastMonthBills;
        if ($lastMonthBills > 0) {
            $billsVsLastMonth = ($billsDiff >= 0 ? "↑ {$this->money($currency, abs($billsDiff))}" : "↓ {$this->money($currency, abs($billsDiff))}") . " vs {$lastMonthName}";
        } elseif ($billsTotal > 0) {
            $billsVsLastMonth = $this->money($currency, $billsTotal) . " this month";
        } else {
            $billsVsLastMonth = "No bills recorded";
        }

        $staffEmployees = AttendanceEmployee::query()
            ->with('user:id,monthly_salary,role')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $salaryExpenses = BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('expense_type', 'payroll')
            ->whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $monthEnd)
            ->get();

        // Status follows the salary period, even when payroll was released a
        // day early or late. "Paid this month" below still follows payment date.
        $salaryPeriodPayments = BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('expense_type', 'payroll')
            ->whereNotNull('attendance_employee_id')
            ->whereDate('salary_period_start', '<=', $monthEnd)
            ->whereDate('salary_period_end', '>=', $monthStart)
            ->get();

        $firstPeriodStart = now()->startOfMonth();
        $firstPeriodEnd = now()->startOfMonth()->addDays(14);
        $secondPeriodStart = now()->startOfMonth()->addDays(15);
        $secondPeriodEnd = now()->endOfMonth();
        $currentDay = (int) now()->day;
        $currentPeriodStart = $currentDay <= 15 ? $firstPeriodStart : $secondPeriodStart;
        $currentPeriodEnd = $currentDay <= 15 ? $firstPeriodEnd : $secondPeriodEnd;
        $currentPeriodLabel = $currentPeriodStart->format('M j').' - '.$currentPeriodEnd->format('M j');

        $wasPaidForPeriod = static function ($expenses, int $employeeId, Carbon $periodStart, Carbon $periodEnd): bool {
            return $expenses->contains(function (BranchExpense $expense) use ($employeeId, $periodStart, $periodEnd): bool {
                if ((int) $expense->attendance_employee_id !== $employeeId) {
                    return false;
                }

                if (! $expense->salary_period_start || ! $expense->salary_period_end) {
                    return false;
                }

                return $expense->salary_period_start->lte($periodEnd)
                    && $expense->salary_period_end->gte($periodStart);
            });
        };

        $employeesList = [];
        $unpaidEmployees = [];
        $unconfiguredEmployees = [];
        $wagesSum = 0;
        $totalDays = 0;
        $pendingCurrentPeriod = 0;

        foreach ($staffEmployees as $staff) {
            $salary = $staff->configured_monthly_salary;
            $dailyRate = $salary > 0 ? round($salary / 26, 2) : 0;
            $days = $salary > 0 ? 26 : 0;
            $wages = round($dailyRate * $days, 2);
            $totalCost = $wages;

            $wagesSum += $wages;
            $totalDays += $days;

            $firstPaid = $wasPaidForPeriod($salaryPeriodPayments, $staff->id, $firstPeriodStart, $firstPeriodEnd);
            $secondPaid = $wasPaidForPeriod($salaryPeriodPayments, $staff->id, $secondPeriodStart, $secondPeriodEnd);
            $currentPaid = $wasPaidForPeriod($salaryPeriodPayments, $staff->id, $currentPeriodStart, $currentPeriodEnd);
            $paidAmount = (float) $salaryExpenses
                ->where('attendance_employee_id', $staff->id)
                ->sum('amount');

            if ($salary <= 0) {
                $unconfiguredEmployees[] = [
                    'id' => $staff->id,
                    'name' => $staff->name,
                ];
            } elseif (! $currentPaid) {
                $pendingAmount = round($salary / 2, 2);
                $pendingCurrentPeriod += $pendingAmount;
                $unpaidEmployees[] = [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'branch_id' => $staff->branch_id,
                    'expected' => $this->money($currency, $pendingAmount),
                ];
            }

            $parts = explode(' ', trim($staff->name));
            $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? ($parts[0] ?? 'S'), 0, 1));

            $employeesList[] = [
                'initials' => $initials,
                'name' => $staff->name,
                'role' => ucfirst(str_replace('_', ' ', $staff->user?->role ?? 'employee')),
                'rate' => $this->money($currency, $dailyRate),
                'days' => $days,
                'wages' => $this->money($currency, $wages),
                'total_cost' => $this->money($currency, $totalCost),
                'paid_amount' => $this->money($currency, $paidAmount),
                'pay_status_1' => $firstPaid ? '15th: Paid' : ($salary <= 0 ? '15th: Salary not set' : ($currentDay >= 15 ? '15th: Unpaid' : '15th: Upcoming')),
                'pay_status_2' => $secondPaid ? 'Month-end: Paid' : ($salary <= 0 ? 'Month-end: Salary not set' : ($currentDay >= now()->daysInMonth ? 'Month-end: Unpaid' : 'Month-end: Upcoming')),
            ];
        }

        $totalPayrollCost = $wagesSum;
        $staffCount = count($employeesList);

        $vendorStillToPay = (float) AccountsPayable::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', 'paid')
            ->sum('balance');
        $stillToPay = $vendorStillToPay + $pendingCurrentPeriod;

        $upcomingNotice = [];
        if ($wagesSum > 0) {
            $payoutDay = now()->day <= 15 ? '15' : now()->endOfMonth()->day;
            $upcomingNotice[] = count($unpaidEmployees).' employee salary payment'.(count($unpaidEmployees) === 1 ? '' : 's')." pending for ".now()->format('M')." {$payoutDay}";
        }
        $nextPayable = AccountsPayable::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->first();
        if ($nextPayable) {
            $upcomingNotice[] = ($nextPayable->creditor_name ?: $nextPayable->description) . ' ' . Carbon::parse($nextPayable->due_date)->format('M j');
        }
        $stillToPaySub = !empty($upcomingNotice) ? implode(' · ', $upcomingNotice) : 'Unpaid vendor payables';

        $billsList = [];
        $paidCount = 0;

        // 1. Paid bills from BranchExpense
        foreach ($billsExpenses as $exp) {
            $paidCount++;
            $billsList[] = [
                'title' => $exp->title ?: ucfirst(str_replace('_', ' ', $exp->expense_type)),
                'due_info' => 'Paid ' . Carbon::parse($exp->expense_date)->format('M j'),
                'amount' => $this->money($currency, (float) $exp->amount),
                'is_paid' => true,
            ];
        }

        // 2. Pending bills from AccountsPayable
        $payablesList = AccountsPayable::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', 'paid')
            ->latest('due_date')
            ->limit(10)
            ->get();

        foreach ($payablesList as $payable) {
            $dueDate = $payable->due_date ? Carbon::parse($payable->due_date) : null;
            $dueInfo = $dueDate ? 'Due ' . $dueDate->format('M j') . ($dueDate->isPast() ? ' (Overdue)' : ' · in ' . max(0, now()->diffInDays($dueDate, false)) . ' days') : 'Due';
            $billsList[] = [
                'title' => $payable->creditor_name ?: $payable->description,
                'due_info' => $dueInfo,
                'amount' => $this->money($currency, (float) ($payable->balance ?: $payable->original_amount)),
                'is_paid' => false,
            ];
        }

        $otherExpenses = BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $monthEnd)
            ->whereNotIn('expense_type', ['utilities', 'rent', 'government_fees', 'payroll', 'supplies', 'inventory_purchase'])
            ->get();

        $otherPurchasesTotal = (float) $otherExpenses->sum('amount');

        $lastMonthOtherPurchases = (float) BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $lastMonthStart)
            ->whereDate('expense_date', '<=', $lastMonthEnd)
            ->whereNotIn('expense_type', ['utilities', 'rent', 'government_fees', 'payroll', 'supplies', 'inventory_purchase'])
            ->sum('amount');

        if ($lastMonthOtherPurchases > 0) {
            $otherDiffPct = round((($otherPurchasesTotal - $lastMonthOtherPurchases) / $lastMonthOtherPurchases) * 100);
            $sign = $otherDiffPct >= 0 ? '+' : '';
            $vsLastMonthText = "{$sign}{$otherDiffPct}% vs {$lastMonthName}";
        } elseif ($otherPurchasesTotal > 0) {
            $vsLastMonthText = "Recorded this month";
        } else {
            $vsLastMonthText = "None recorded";
        }

        $otherBreakdown = [];
        $grouped = (clone $otherExpenses)->groupBy('category');
        foreach ($grouped as $catName => $catItems) {
            $catSum = (float) $catItems->sum('amount');
            $pct = $otherPurchasesTotal > 0 ? (int) round(($catSum / $otherPurchasesTotal) * 100) : 0;
            $otherBreakdown[] = [
                'label' => ucfirst(str_replace('_', ' ', $catName ?: 'Miscellaneous')),
                'amount' => $this->money($currency, $catSum),
                'pct' => $pct,
            ];
        }

        $latestReceipts = BranchExpense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('expense_date')
            ->limit(4)
            ->get()
            ->map(fn ($e) => [
                'date' => Carbon::parse($e->expense_date)->format('M j'),
                'title' => $e->title ?: ucfirst(str_replace('_', ' ', $e->expense_type)),
                'amount' => $this->money($currency, (float) $e->amount),
            ]);

        $monthlySales = (float) Payment::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $monthStart)
            ->whereDate('paid_at', '<=', $monthEnd)
            ->sum('amount');

        $suppliesUsedCost = (float) InventoryMovement::query()
            ->join('inventories', 'inventories.id', '=', 'inventory_movements.inventory_id')
            ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId))
            ->where('inventory_movements.movement_type', 'out')
            ->whereDate('inventory_movements.created_at', '>=', $monthStart)
            ->whereDate('inventory_movements.created_at', '<=', $monthEnd)
            ->selectRaw('SUM(inventory_movements.quantity * inventories.unit_cost) as total')
            ->value('total') ?: 0;

        $leftForShop = round($monthlySales - $totalPayrollCost - $billsTotal - $suppliesUsedCost - $otherPurchasesTotal, 2);
        $marginPct = $monthlySales > 0 ? round(($leftForShop / $monthlySales) * 100) : 0;

        // 6-Month Stacked History
        $sixMonths = [];
        $sixMonthsTotals = [];
        $sixMonthsBreakdown = [];
        $maxMonthBill = 1;
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $monthLabel = $m->format('M');
            $sixMonths[] = $monthLabel;
            $mStart = $m->copy()->startOfMonth()->toDateString();
            $mEnd = $m->copy()->endOfMonth()->toDateString();

            $monthExpenses = BranchExpense::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('expense_date', '>=', $mStart)
                ->whereDate('expense_date', '<=', $mEnd)
                ->get();

            $mTotal = (float) $monthExpenses->sum('amount');
            if ($mTotal > $maxMonthBill) {
                $maxMonthBill = $mTotal;
            }

            $rentAmt = (float) $monthExpenses->where('expense_type', 'rent')->sum('amount');
            $taxesAmt = (float) $monthExpenses->where('expense_type', 'government_fees')->sum('amount');
            $elecAmt = 0;
            $waterAmt = 0;
            $lpgAmt = 0;
            foreach ($monthExpenses->where('expense_type', 'utilities') as $exp) {
                $t = strtolower($exp->title . ' ' . $exp->category);
                if (str_contains($t, 'water')) {
                    $waterAmt += (float) $exp->amount;
                } elseif (str_contains($t, 'lpg') || str_contains($t, 'gas')) {
                    $lpgAmt += (float) $exp->amount;
                } else {
                    $elecAmt += (float) $exp->amount;
                }
            }

            $sixMonthsTotals[] = $mTotal >= 1000 ? '₱' . round($mTotal / 1000, 1) . 'k' : $this->money($currency, $mTotal);
            $sixMonthsBreakdown[] = [
                'total' => $mTotal,
                'rent' => $rentAmt,
                'electricity' => $elecAmt,
                'water' => $waterAmt,
                'lpg' => $lpgAmt,
                'taxes' => $taxesAmt,
            ];
        }

        $billsRangeLabel = !empty($sixMonths) ? ($sixMonths[0] . ' – ' . end($sixMonths) . ' ' . now()->year) : '';

        $totalKgMonth = (float) JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($q) => $q->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $monthStart)
            ->whereDate('job_orders.created_at', '<=', $monthEnd)
            ->sum('job_order_items.quantity') ?: 0;

        $payrollPerKg = $totalKgMonth > 0 ? round($totalPayrollCost / $totalKgMonth, 2) : 0.00;

        $attendanceDays = EmployeeAttendanceRecord::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('work_date', '>=', $monthStart)
            ->whereDate('work_date', '<=', $monthEnd)
            ->count();

        $attendancePct = ($totalDays > 0 && $attendanceDays > 0)
            ? round(($attendanceDays / $totalDays) * 100) . '%'
            : '0%';

        $nextPayoutDay = now()->day <= 15 ? '15' : now()->endOfMonth()->day;
        $daysUntilPayout = max(0, (int)$nextPayoutDay - now()->day);

        // Waterfall raw values
        $maxWaterfall = max(
            $monthlySales,
            $totalPayrollCost,
            $billsTotal,
            $suppliesUsedCost,
            $otherPurchasesTotal,
            abs($leftForShop),
            1
        );

        if ($monthlySales > 0) {
            $staffPer100 = round(($totalPayrollCost / $monthlySales) * 100);
            $billsPer100 = round(($billsTotal / $monthlySales) * 100);
            $suppliesPer100 = round(($suppliesUsedCost / $monthlySales) * 100);
            $otherPer100 = round(($otherPurchasesTotal / $monthlySales) * 100);
            $staysPer100 = round(($leftForShop / $monthlySales) * 100);
            $breakEven = round($totalPayrollCost + $billsTotal + $suppliesUsedCost + $otherPurchasesTotal, 2);
            $dailyBreakEven = round($breakEven / max(1, now()->daysInMonth), 2);
            $ruleOfThumb = "For every ₱100 of laundry sold: ₱{$staffPer100} pays staff, ₱{$billsPer100} pays bills, ₱{$suppliesPer100} buys supplies, ₱{$otherPer100} goes to other purchases, ₱{$staysPer100} stays. Break-even: about {$this->money($currency, $breakEven)} a month ({$this->money($currency, $dailyBreakEven)} a day).";
        } else {
            $ruleOfThumb = 'For every ₱100 of laundry sold: track real staff, bills, supplies, and other purchases to monitor store operating margin.';
        }

        $billsInsight1 = ($billsTotal > 0)
            ? 'Monitor utility consumption and dryer loads to optimize electric and water bills.'
            : 'No utility bills recorded in the last 6 months. Track electric and water bills to monitor dryer and wash efficiency.';

        $billsInsight2 = 'Quarterly taxes and scheduled vendor payables will show here when recorded.';

        return [
            'bills_this_month' => $this->money($currency, $billsTotal),
            'bills_vs_aug' => $billsVsLastMonth,
            'bills_vs_last_month' => $billsVsLastMonth,
            'payroll_this_month' => $this->money($currency, (float) $salaryExpenses->sum('amount')),
            'payroll_sub' => $this->money($currency, $wagesSum)." projected for {$staffCount} employees",
            'still_to_pay' => $this->money($currency, $stillToPay),
            'still_to_pay_sub' => $stillToPaySub,
            'left_for_shop' => $this->money($currency, $leftForShop),
            'left_margin_sub' => "{$marginPct}% margin · after payroll & all costs",
            'bills_history_6m' => [
                'months' => $sixMonths,
                'range_label' => $billsRangeLabel,
                'totals' => $sixMonthsTotals,
                'breakdown' => $sixMonthsBreakdown,
                'max_bill' => $maxMonthBill,
                'insight_1' => $billsInsight1,
                'insight_2' => $billsInsight2,
            ],
            'bills_list' => $billsList,
            'paid_bills_count' => count($billsList) > 0 ? "{$paidCount} of " . count($billsList) . " paid" : "0 bills",
            'payroll_summary' => [
                'total_cost' => $this->money($currency, $totalPayrollCost),
                'breakdown' => $this->money($currency, (float) $salaryExpenses->sum('amount')).' paid of '.$this->money($currency, $wagesSum).' projected wages',
                'attendance_pct' => $attendancePct,
                'attendance_sub' => "{$attendanceDays} of {$totalDays} work days",
                'payroll_per_kg' => $this->money($currency, $payrollPerKg),
                'per_kg_sub' => number_format($totalKgMonth, 1) . ' kg this month',
                'next_payout' => $this->money($currency, round($wagesSum / 2, 2)),
                'next_payout_sub' => "Next wages in {$daysUntilPayout} days",
            ],
            'employees' => $employeesList,
            'salary_period_label' => $currentPeriodLabel,
            'unpaid_employees' => $unpaidEmployees,
            'unpaid_employee_count' => count($unpaidEmployees),
            'unconfigured_salary_employees' => $unconfiguredEmployees,
            'unconfigured_salary_count' => count($unconfiguredEmployees),
            'salary_paid_this_month' => $this->money($currency, (float) $salaryExpenses->sum('amount')),
            'salary_pending_current_period' => $this->money($currency, $pendingCurrentPeriod),
            'total_employee_days' => $totalDays,
            'total_employee_wages' => $this->money($currency, $wagesSum),
            'total_employee_cost' => $this->money($currency, $totalPayrollCost),
            'waterfall' => [
                'sales' => $this->money($currency, $monthlySales),
                'sales_raw' => $monthlySales,
                'payroll' => '-' . $this->money($currency, $totalPayrollCost),
                'payroll_raw' => $totalPayrollCost,
                'bills' => '-' . $this->money($currency, $billsTotal),
                'bills_raw' => $billsTotal,
                'supplies' => '-' . $this->money($currency, $suppliesUsedCost),
                'supplies_raw' => $suppliesUsedCost,
                'other' => '-' . $this->money($currency, $otherPurchasesTotal),
                'other_raw' => $otherPurchasesTotal,
                'left' => $this->money($currency, $leftForShop),
                'left_raw' => $leftForShop,
                'max_val' => $maxWaterfall,
            ],
            'rule_of_thumb' => $ruleOfThumb,
            'other_purchases' => [
                'total' => $this->money($currency, $otherPurchasesTotal),
                'vs_aug' => $vsLastMonthText,
                'vs_last_month' => $vsLastMonthText,
                'breakdown' => $otherBreakdown,
                'latest_receipts' => $latestReceipts,
            ],
        ];
    }

    private function assistantPresets(): array
    {
        return [
            'daily_sales' => 'Daily sales summary',
            'payment_mix' => 'Payment method mix',
            'expenses' => 'Expenses summary',
            'accounts_payable' => 'Accounts payable summary',
            'cash_drawer' => 'Expected cash drawer',
            'petty_cash' => 'Petty cash movement',
            'receivables' => 'Receivables risk',
            'unpaid_orders' => 'Unpaid job orders',
            'active_cycles' => 'Active laundry cycles',
            'ready_pickup' => 'Ready for pickup',
            'low_stock' => 'Low stock items',
            'top_customers' => 'Top customers',
            'branch_compare' => 'Branch comparison',
            'attendance_today' => 'Attendance today',
            'eod_tasks' => 'End-of-day tasks',
            'z_reading' => 'Latest Z Reading variance',
        ];
    }

    private function assistantAnswer(Request $request, string $preset, ?string $question = null): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchId = $this->assistantBranchId($request);
        $currency = SystemSetting::current()->currency ?: 'PHP';
        $scope = $this->assistantScopeLabel($branchId);
        $period = Carbon::parse($dateFrom)->format('M d, Y').' to '.Carbon::parse($dateTo)->format('M d, Y');

        $orders = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        $payments = Payment::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $collections = Payment::query()
            ->when($branchId, fn ($query) => $query->where('collected_branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $expenses = BranchExpense::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo);

        $movements = MoneyMovement::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('movement_date', '>=', $dateFrom)
            ->whereDate('movement_date', '<=', $dateTo);

        $answer = match ($preset) {
            'daily_sales' => $this->salesAssistant($payments, $collections, $orders, $currency),
            'payment_mix' => $this->paymentMixAssistant($collections, $currency),
            'expenses' => $this->expenseAssistant($expenses, $currency),
            'accounts_payable' => $this->accountsPayableAssistant($branchId, $currency),
            'cash_drawer' => $this->cashDrawerAssistant($branchId, $dateFrom, $dateTo, $currency),
            'petty_cash' => $this->pettyCashAssistant($movements, $currency),
            'receivables' => $this->receivablesAssistant($branchId, $currency),
            'unpaid_orders' => $this->unpaidOrdersAssistant($branchId, $currency),
            'active_cycles' => $this->activeCyclesAssistant($branchId),
            'ready_pickup' => $this->readyPickupAssistant($branchId),
            'low_stock' => $this->lowStockAssistant($branchId),
            'top_customers' => $this->topCustomersAssistant($branchId, $dateFrom, $dateTo, $currency),
            'branch_compare' => $this->branchCompareAssistant($request, $dateFrom, $dateTo, $currency),
            'attendance_today' => $this->attendanceAssistant($branchId),
            'eod_tasks' => $this->dailyTaskAssistant($branchId),
            'z_reading' => $this->zReadingAssistant($branchId, $currency),
        };

        return $answer + [
            'scope' => $scope,
            'period' => $period,
            'preset' => $preset,
            'question' => $question,
            'generated_at' => now()->format('M d, Y h:i A'),
        ];
    }

    private function inferAssistantPreset(string $question): string
    {
        $question = str($question)->lower()->toString();

        return match (true) {
            str_contains($question, 'payment') || str_contains($question, 'gcash') || str_contains($question, 'bank') => 'payment_mix',
            str_contains($question, 'expense') || str_contains($question, 'cash advance') => 'expenses',
            str_contains($question, 'payable') || str_contains($question, 'owe owner') || str_contains($question, 'owner funding') => 'accounts_payable',
            str_contains($question, 'drawer') || str_contains($question, 'cash count') || str_contains($question, 'cash drawer') => 'cash_drawer',
            str_contains($question, 'petty') || str_contains($question, 'deposit') || str_contains($question, 'withdraw') => 'petty_cash',
            str_contains($question, 'receivable') || str_contains($question, 'balance') || str_contains($question, 'utang') => 'receivables',
            str_contains($question, 'unpaid') => 'unpaid_orders',
            str_contains($question, 'cycle') || str_contains($question, 'washing') || str_contains($question, 'drying') => 'active_cycles',
            str_contains($question, 'pickup') || str_contains($question, 'ready') => 'ready_pickup',
            str_contains($question, 'stock') || str_contains($question, 'inventory') => 'low_stock',
            str_contains($question, 'customer') || str_contains($question, 'top') => 'top_customers',
            str_contains($question, 'branch') || str_contains($question, 'compare') => 'branch_compare',
            str_contains($question, 'attendance') || str_contains($question, 'clock') => 'attendance_today',
            str_contains($question, 'task') || str_contains($question, 'cleaning') || str_contains($question, 'end of day') => 'eod_tasks',
            str_contains($question, 'z reading') || str_contains($question, 'over') || str_contains($question, 'short') => 'z_reading',
            default => 'daily_sales',
        };
    }

    private function salesAssistant($payments, $collections, $orders, string $currency): array
    {
        $sales = (float) (clone $payments)->sum('amount');
        $collectionsTotal = (float) (clone $collections)->sum('amount');
        $count = (clone $collections)->count();
        $ordersCount = (clone $orders)->count();

        return [
            'title' => 'Daily Sales Summary',
            'summary' => "Sales ownership is {$this->money($currency, $sales)}. Physical collections counted in this branch are {$this->money($currency, $collectionsTotal)} from {$count} payment(s), with {$ordersCount} job order(s) created in the selected period.",
            'metrics' => [
                ['label' => 'Sales Owned', 'value' => $this->money($currency, $sales)],
                ['label' => 'Physical Collections', 'value' => $this->money($currency, $collectionsTotal)],
                ['label' => 'Collection Count', 'value' => number_format($count)],
                ['label' => 'Job Orders', 'value' => number_format($ordersCount)],
            ],
        ];
    }

    private function paymentMixAssistant($payments, string $currency): array
    {
        $rows = (clone $payments)
            ->selectRaw('payment_type, COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('payment_type')
            ->orderByDesc('total_amount')
            ->get();
        $top = $rows->first();

        return [
            'title' => 'Physical Collection Mix',
            'summary' => $top ? StatusBadge::label($top->payment_type).' is the leading collected payment method at '.$this->money($currency, (float) $top->total_amount).'.' : 'No payments found for the selected period.',
            'metrics' => $rows->map(fn ($row) => [
                'label' => StatusBadge::label($row->payment_type).' ('.$row->payments_count.')',
                'value' => $this->money($currency, (float) $row->total_amount),
            ])->values()->all(),
        ];
    }

    private function expenseAssistant($expenses, string $currency): array
    {
        $total = (float) (clone $expenses)->sum('amount');
        $storeCash = (float) (clone $expenses)->where('paid_from', 'store_cash')->sum('amount');
        $owner = (float) (clone $expenses)->where('paid_from', 'owner')->sum('amount');

        return [
            'title' => 'Expenses Summary',
            'summary' => "Recorded {$this->money($currency, $total)} in expenses. New expense records are store-funded and affect the drawer; owner-paid totals are legacy records only.",
            'metrics' => [
                ['label' => 'Total Expenses', 'value' => $this->money($currency, $total)],
                ['label' => 'Store Cash', 'value' => $this->money($currency, $storeCash)],
                ['label' => 'Legacy Owner-Paid Records', 'value' => $this->money($currency, $owner)],
            ],
        ];
    }

    private function accountsPayableAssistant(?int $branchId, string $currency): array
    {
        $query = AccountsPayable::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $original = (float) (clone $query)->sum('original_amount');
        $paid = (float) (clone $query)->sum('paid_amount');
        $balance = (float) (clone $query)->sum('balance');
        $open = (clone $query)->where('balance', '>', 0)->count();

        return [
            'title' => 'Accounts Payable Summary',
            'summary' => "{$open} payable(s) remain open. Cash repayments reduce the branch drawer; cashless repayments do not.",
            'metrics' => [
                ['label' => 'Total Obligations', 'value' => $this->money($currency, $original)],
                ['label' => 'Repaid', 'value' => $this->money($currency, $paid)],
                ['label' => 'Outstanding', 'value' => $this->money($currency, $balance)],
            ],
        ];
    }

    private function cashDrawerAssistant(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $financial = FinancialReconciliation::forPeriod($branchId, $dateFrom, $dateTo);

        return [
            'title' => 'Expected Cash Drawer',
            'summary' => 'Expected drawer uses cash physically collected in this branch, minus store-cash expenses, plus deposits, minus withdrawals/remittances.',
            'metrics' => [
                ['label' => 'Cash Collected Here', 'value' => '+ '.$this->money($currency, $financial['cash_collections'])],
                ['label' => 'Cash Deposits / Owner Funding', 'value' => '+ '.$this->money($currency, $financial['cash_in'])],
                ['label' => 'Store-Cash Expenses', 'value' => '- '.$this->money($currency, $financial['store_cash_expenses'])],
                ['label' => 'Withdrawals / Repayments', 'value' => '- '.$this->money($currency, $financial['cash_out'])],
                ['label' => 'Expected Drawer', 'value' => $this->money($currency, $financial['expected_cash_drawer'])],
            ],
        ];
    }

    private function pettyCashAssistant($movements, string $currency): array
    {
        $cashIn = (float) (clone $movements)->where('direction', 'in')->sum('amount');
        $cashOut = (float) (clone $movements)->where('direction', 'out')->sum('amount');

        return [
            'title' => 'Petty Cash Movement',
            'summary' => 'Deposits increase branch cash; withdrawals reduce branch cash.',
            'metrics' => [
                ['label' => 'Deposits', 'value' => '+ '.$this->money($currency, $cashIn)],
                ['label' => 'Withdrawals', 'value' => '- '.$this->money($currency, $cashOut)],
                ['label' => 'Net Movement', 'value' => $this->money($currency, $cashIn - $cashOut)],
            ],
        ];
    }

    private function receivablesAssistant(?int $branchId, string $currency): array
    {
        $query = JobOrder::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->where('balance', '>', 0)->where('status', '!=', 'cancelled');
        $balance = (float) (clone $query)->sum('balance');
        $count = (clone $query)->count();

        return [
            'title' => 'Receivables Risk',
            'summary' => "There are {$count} job order(s) with remaining balance.",
            'metrics' => [
                ['label' => 'Open Receivables', 'value' => number_format($count)],
                ['label' => 'Total Balance', 'value' => $this->money($currency, $balance)],
            ],
        ];
    }

    private function unpaidOrdersAssistant(?int $branchId, string $currency): array
    {
        $query = JobOrder::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->where('balance', '>', 0)->where('status', '!=', 'cancelled')->latest();

        return [
            'title' => 'Unpaid Job Orders',
            'summary' => 'Newest unpaid job orders are listed below for collection follow-up.',
            'metrics' => $query->limit(5)->get()->map(fn (JobOrder $order) => [
                'label' => $order->job_order_number,
                'value' => $this->money($currency, (float) $order->balance),
            ])->values()->all(),
        ];
    }

    private function activeCyclesAssistant(?int $branchId): array
    {
        $rows = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where(fn ($query) => $query
                ->where('branch_id', $branchId)
                ->orWhere(fn ($query) => $query
                    ->where('processing_branch_id', $branchId)
                    ->whereNotNull('production_accepted_at'))))
            ->whereIn('status', ['washing', 'drying', 'folding'])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'title' => 'Active Laundry Cycles',
            'summary' => 'Active cycle counts show current work in progress.',
            'metrics' => collect(['washing', 'drying', 'folding'])->map(fn ($status) => [
                'label' => StatusBadge::label($status),
                'value' => number_format((int) ($rows[$status] ?? 0)),
            ])->all(),
        ];
    }

    private function readyPickupAssistant(?int $branchId): array
    {
        $count = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where(fn ($query) => $query
                ->where('branch_id', $branchId)
                ->orWhere('release_branch_id', $branchId)
                ->orWhere('current_branch_id', $branchId)))
            ->whereIn('status', ['ready_for_pickup', 'ready_for_delivery'])
            ->count();

        return [
            'title' => 'Ready for Pickup or Delivery',
            'summary' => "{$count} job order(s) are ready and should be released or followed up.",
            'metrics' => [['label' => 'Ready Orders', 'value' => number_format($count)]],
        ];
    }

    private function lowStockAssistant(?int $branchId): array
    {
        $items = Inventory::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return [
            'title' => 'Low Stock Items',
            'summary' => $items->count().' item(s) are at or below reorder level.',
            'metrics' => $items->map(fn (Inventory $item) => [
                'label' => $item->name,
                'value' => number_format((float) $item->quantity, 2).' '.$item->unit,
            ])->values()->all(),
        ];
    }

    private function topCustomersAssistant(?int $branchId, string $dateFrom, string $dateTo, string $currency): array
    {
        $rows = JobOrder::query()
            ->join('customers', 'job_orders.customer_id', '=', 'customers.id')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->selectRaw('customers.name, COALESCE(SUM(job_orders.total), 0) as total_amount')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();

        return [
            'title' => 'Top Customers',
            'summary' => 'Top customers are ranked by job order total in the selected period.',
            'metrics' => $rows->map(fn ($row) => [
                'label' => $row->name,
                'value' => $this->money($currency, (float) $row->total_amount),
            ])->values()->all(),
        ];
    }

    private function branchCompareAssistant(Request $request, string $dateFrom, string $dateTo, string $currency): array
    {
        if (! $request->user()->canManageAllBranches()) {
            return $this->salesAssistant(
                Payment::query()->where('branch_id', $request->user()->branch_id)->whereDate('paid_at', '>=', $dateFrom)->whereDate('paid_at', '<=', $dateTo),
                Payment::query()->where('collected_branch_id', $request->user()->branch_id)->whereIn('payment_type', ['cash', 'gcash', 'bank'])->whereDate('paid_at', '>=', $dateFrom)->whereDate('paid_at', '<=', $dateTo),
                JobOrder::query()->where('branch_id', $request->user()->branch_id)->whereDate('created_at', '>=', $dateFrom)->whereDate('created_at', '<=', $dateTo),
                $currency
            );
        }

        $rows = Payment::query()
            ->join('branches', 'payments.branch_id', '=', 'branches.id')
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo)
            ->selectRaw('branches.name, COALESCE(SUM(payments.amount), 0) as total_amount')
            ->groupBy('branches.name')
            ->orderByDesc('total_amount')
            ->limit(6)
            ->get();

        return [
            'title' => 'Branch Comparison',
            'summary' => 'Branches are ranked by sales-owner payments in the selected period. Use Payment Audit or Z Reading for physical collections.',
            'metrics' => $rows->map(fn ($row) => [
                'label' => $row->name,
                'value' => $this->money($currency, (float) $row->total_amount),
            ])->values()->all(),
        ];
    }

    private function attendanceAssistant(?int $branchId): array
    {
        $records = EmployeeAttendanceRecord::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('work_date', today())
            ->get();
        $clockIns = $records->sum(fn (EmployeeAttendanceRecord $record) => count($record->clock_in ?? []));
        $clockOuts = $records->sum(fn (EmployeeAttendanceRecord $record) => count($record->clock_out ?? []));

        return [
            'title' => 'Attendance Today',
            'summary' => 'Attendance is based on employee kiosk clock-in and clock-out records for today.',
            'metrics' => [
                ['label' => 'Employees With Logs', 'value' => number_format($records->count())],
                ['label' => 'Clock Ins', 'value' => number_format($clockIns)],
                ['label' => 'Clock Outs', 'value' => number_format($clockOuts)],
            ],
        ];
    }

    private function dailyTaskAssistant(?int $branchId): array
    {
        $tasks = DailyTask::query()
            ->when($branchId, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('branch_id')
                ->orWhere('branch_id', $branchId)))
            ->where('is_active', true)
            ->count();
        $completed = DailyTaskCompletion::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->whereDate('work_date', today())->count();

        return [
            'title' => 'End-of-Day Tasks',
            'summary' => 'Completions are counted for today only.',
            'metrics' => [
                ['label' => 'Required Active Tasks', 'value' => number_format($tasks)],
                ['label' => 'Completed Today', 'value' => number_format($completed)],
                ['label' => 'Remaining', 'value' => number_format(max(0, $tasks - $completed))],
            ],
        ];
    }

    private function zReadingAssistant(?int $branchId, string $currency): array
    {
        $reading = ZReading::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->latest('business_date')->latest()->first();

        return [
            'title' => 'Latest Z Reading Variance',
            'summary' => $reading ? 'Latest cash count variance from '.$reading->business_date?->format('M d, Y').'.' : 'No Z Reading has been submitted yet.',
            'metrics' => $reading ? [
                ['label' => 'Expected Total', 'value' => $this->money($currency, (float) $reading->expected_total_amount)],
                ['label' => 'Actual Total', 'value' => $this->money($currency, (float) $reading->actual_total_amount)],
                ['label' => 'Over / Short', 'value' => $this->money($currency, (float) $reading->over_short_amount)],
            ] : [],
        ];
    }

    private function assistantBranchId(Request $request): ?int
    {
        if (! $request->user()->canManageAllBranches()) {
            return $request->user()->branch_id;
        }

        return $request->filled('branch_id') ? (int) $request->branch_id : null;
    }

    private function assistantScopeLabel(?int $branchId): string
    {
        if (! $branchId) {
            return 'All branches';
        }

        return Branch::query()->whereKey($branchId)->value('name') ?: 'Selected branch';
    }

    private function branchId(Request $request): ?int
    {
        if (! $request->user()->isAdmin()) {
            return $request->user()->branch_id;
        }

        return $request->filled('branch_id') ? (int) $request->branch_id : null;
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('period')) {
            $period = $request->period;
            if ($period === 'today') {
                return [today()->toDateString(), today()->toDateString()];
            } elseif ($period === 'this_week') {
                return [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()];
            } elseif ($period === 'this_month') {
                return [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
            }
        }

        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);
            $from = $this->parseDate($parts[0] ?? null);
            $to = $this->parseDate($parts[1] ?? $parts[0] ?? null);

            return $from <= $to ? [$from, $to] : [$to, $from];
        }

        return [today()->toDateString(), today()->toDateString()];
    }

    /** Which period button is lit: a picked date range shows as "custom". */
    private function currentPeriod(Request $request): string
    {
        if ($request->filled('period')) {
            return (string) $request->query('period');
        }

        return $request->filled('date_range') ? 'custom' : 'today';
    }

    private function rangeLabel(string $dateFrom, string $dateTo): string
    {
        $from = Carbon::parse($dateFrom);
        $to = Carbon::parse($dateTo);

        if ($from->isSameDay($to)) {
            return $to->format('l, F j');
        }

        return $from->isSameYear($to)
            ? $from->format('M j').' – '.$to->format('M j, Y')
            : $from->format('M j, Y').' – '.$to->format('M j, Y');
    }

    private function dateRangeValue(Request $request): string
    {
        [$from, $to] = $this->dateRange($request);

        return $from.' to '.$to;
    }

    private function parseDate(?string $date): string
    {
        if (! $date) {
            return today()->toDateString();
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
    }

    private function money(string $currency, float $value): string
    {
        return $currency.' '.number_format($value, 2);
    }
}
