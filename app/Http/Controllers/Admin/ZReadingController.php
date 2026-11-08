<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchExpense;
use App\Models\Branch;
use App\Models\CashOpening;
use App\Models\CycleRecord;
use App\Models\InventoryMovement;
use App\Support\Activity;
use App\Support\FinancialReconciliation;
use App\Support\MachineClass;
use App\Support\ShopCash;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\LaundryServiceCategory;
use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ZReadingController extends Controller
{
    private const DENOMINATIONS = [
        '1000' => '₱1,000',
        '500' => '₱500',
        '200' => '₱200',
        '100' => '₱100',
        '50' => '₱50',
        '20' => '₱20',
        '10' => '₱10',
        '5' => '₱5',
        '1' => '₱1',
        '0.25' => '25¢',
    ];

    public const NON_JOB_CYCLE_REASONS = [
        'not_recorded' => 'Customer wash/dry not clicked in POS',
        'rewash_quality_check' => 'Rewash / quality check (free)',
        'tub_cleaning' => 'Tub cleaning',
        'testing_maintenance' => 'Testing / maintenance',
        'accidental_start' => 'Accidental start',
        'power_restart' => 'Power interruption / restart',
        'other' => 'Other (explain)',
    ];

    /** Cycles created from a Z-Reading "not clicked in POS" reason carry this note prefix. */
    private const AUTO_CYCLE_NOTE = 'Z-Reading ';

    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $branchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;
        $businessDate = $request->date('business_date')?->toDateString() ?: today()->toDateString();

        abort_unless($branchId, 403);

        $branch = $branches->firstWhere('id', $branchId) ?: Branch::query()->findOrFail($branchId);
        if (! $canChooseBranch) {
            abort_unless((int) $user->branch_id === (int) $branch->id, 403);
        }

        $readings = ZReading::query()
            ->with(['branch', 'preparer'])
            ->when(! $canChooseBranch, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($canChooseBranch && $request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->branch_id))
            ->latest('business_date')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.z-readings.index', [
            'branch' => $branch,
            'branches' => $branches,
            'businessDate' => $businessDate,
            'canChooseBranch' => $canChooseBranch,
            'readings' => $readings,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $branchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;
        $businessDate = $request->date('business_date')?->toDateString() ?: today()->toDateString();

        abort_unless($branchId, 403);

        $branch = $branches->firstWhere('id', $branchId) ?: Branch::query()->findOrFail($branchId);
        if (! $canChooseBranch) {
            abort_unless((int) $user->branch_id === (int) $branch->id, 403);
        }

        $summary = $this->summary((int) $branch->id, $businessDate);
        $reading = ZReading::query()
            ->with(['branch', 'preparer', 'closer'])
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate)
            ->first();
        $closing = $this->closing($branch, $businessDate, $summary);

        return view('admin.z-readings.create', [
            'branch' => $branch,
            'branches' => $branches,
            'businessDate' => $businessDate,
            'canChooseBranch' => $canChooseBranch,
            'denominations' => self::DENOMINATIONS,
            'reading' => $reading,
            'summary' => $summary,
            'closing' => $closing,
            'machines' => $this->machineRowsForForm($branch, $closing, $reading, $request->old('machine_counters')),
            'nonJobCycleReasons' => self::NON_JOB_CYCLE_REASONS,
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $validated = $request->validate([
            'branch_id' => [$isAdmin ? 'required' : 'nullable', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
            'cash_count' => ['nullable', 'array'],
            'cash_count.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'actual_gcash_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'gcash_opening' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'gcash_transfer' => ['nullable', 'boolean'],
            'gcash_transfer_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'gcash_transfer_fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'gcash_transfer_ref' => ['nullable', 'string', 'max:50'],
            'deposit_carried_over' => ['nullable', 'boolean'],
            'deposit_slip_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'deposit_slip_ref' => ['nullable', 'string', 'max:50'],
            'float_for_tomorrow' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'machine_counters' => ['nullable', 'array'],
            'machine_counters.*.*.beginning' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'machine_counters.*.*.ending' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'machine_counters.*.*.non_job_cycles' => ['nullable', 'array'],
            'machine_counters.*.*.non_job_cycles.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'machine_counters.*.*.notes' => ['nullable', 'string', 'max:500'],
            'machine_counters.*.*.jo_ref' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'edit_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $branchId = $isAdmin ? (int) $validated['branch_id'] : (int) $user->branch_id;
        abort_unless($branchId, 403);
        $branch = Branch::query()->findOrFail($branchId);
        $businessDate = Carbon::parse($validated['business_date'])->toDateString();
        $existing = ZReading::query()->where('branch_id', $branchId)->whereDate('business_date', $businessDate)->first();
        $remarks = filled($validated['remarks'] ?? null) ? trim($validated['remarks']) : null;

        // Saved Zs are locked: only an admin may change one, and must say why.
        if ($existing && ! $isAdmin) {
            throw ValidationException::withMessages(['locked' => 'This Z-Reading is already saved. Only an admin can change it.']);
        }
        if ($existing && ! filled($validated['edit_reason'] ?? null)) {
            throw ValidationException::withMessages(['edit_reason' => 'Give a reason for changing a saved Z-Reading.']);
        }

        $summary = $this->summary($branchId, $businessDate);
        $shopCash = $summary['shop_cash'];
        if (! $shopCash['opening'] && ! $existing) {
            throw ValidationException::withMessages(['shop_cash' => 'Confirm the Shop Cash opening count for this date first.']);
        }
        if ($shopCash['pending']->isNotEmpty()) {
            throw ValidationException::withMessages(['shop_cash' => $shopCash['pending']->count().' drawer expense(s) still wait for confirmation in Shop Cash.']);
        }

        $closing = $this->closing($branch, $businessDate, $summary);
        if ($closing['first'] && ! $isAdmin) {
            throw ValidationException::withMessages(['setup' => 'The first Z-Reading of this branch (or the first after the new Z-Reading went live) sets the GCash starting balance. An admin must do it.']);
        }
        if ($closing['missed_date']) {
            $missed = Carbon::parse($closing['missed_date'])->format('M d, Y');
            if (! $isAdmin) {
                throw ValidationException::withMessages(['missed_day' => "No Z-Reading was saved for {$missed}. Close that day first, or ask an admin."]);
            }
            if (! $remarks) {
                throw ValidationException::withMessages(['remarks' => "Explain in Remarks why {$missed} has no Z-Reading."]);
            }
        }

        $steps = $this->steps($branch, $closing, $validated);
        if ($steps['errors'] !== []) {
            throw ValidationException::withMessages($steps['errors']);
        }
        if ($steps['status'] === 'with_over_short' && ! $remarks) {
            throw ValidationException::withMessages(['remarks' => 'Explain the over/short in Remarks.']);
        }
        if (! $steps['sales']['matches']) {
            // A sales mismatch is a POS bug, not a staff error: show it, log it for the developer.
            Log::warning('Z-Reading sales check failed', ['branch_id' => $branchId, 'date' => $businessDate, 'sales' => $steps['sales']]);
        }

        try {
            [$reading, $changed] = DB::transaction(function () use ($request, $validated, $branchId, $businessDate, $existing, $steps, $summary, $closing, $remarks, $user, $shopCash): array {
            $reading = $existing ? ZReading::query()->lockForUpdate()->findOrFail($existing->id) : new ZReading([
                'branch_id' => $branchId,
                'business_date' => $businessDate,
                'reading_number' => $this->nextReadingNumber($branchId, $businessDate),
                'prepared_by' => $user->id,
            ]);

            $reading->fill([
                'cash_count' => $steps['cash_count'],
                'payment_breakdown' => $summary['payment_breakdown'],
                'expense_breakdown' => $summary['expense_breakdown'],
                'machine_counters' => $steps['machines'],
                'expected_cash_amount' => $summary['expected_cash_amount'],
                'cash_expense_amount' => $summary['cash_expense_amount'],
                'opening_float' => $closing['opening_float'],
                'expected_cash_drawer_amount' => $steps['cash']['expected'],
                'actual_cash_amount' => $steps['cash']['actual'],
                'cash_diff' => $steps['cash']['diff'],
                'gcash_opening' => $steps['gcash']['opening'],
                'expected_gcash_amount' => $steps['gcash']['expected'],
                'actual_gcash_amount' => $steps['gcash']['actual'],
                'gcash_diff' => $steps['gcash']['diff'],
                'gcash_transfer_amount' => $steps['gcash']['transfer_amount'],
                'gcash_transfer_fee' => $steps['gcash']['transfer_fee'],
                'gcash_transfer_ref' => $steps['gcash']['transfer_ref'],
                // No bank step: staff never enter or see a bank balance.
                'expected_bank_amount' => 0,
                'actual_bank_amount' => 0,
                'bag_due' => $closing['bag_due'],
                'deposit_carried_over' => $steps['deposit']['carried'],
                'deposit_slip_amount' => $steps['deposit']['slip_amount'],
                'deposit_slip_ref' => $steps['deposit']['slip_ref'],
                'deposit_diff' => $steps['deposit']['diff'],
                'expected_total_amount' => round($steps['cash']['expected'] + $steps['gcash']['expected'], 2),
                'actual_total_amount' => round($steps['cash']['actual'] + $steps['gcash']['actual'], 2),
                'over_short_amount' => round($steps['cash']['diff'] + $steps['gcash']['diff'], 2),
                'transaction_count' => $summary['transaction_count'],
                'first_job_order_number' => $summary['first_job_order_number'],
                'last_job_order_number' => $summary['last_job_order_number'],
                'signature_name' => $reading->signature_name ?? $user->name,
                'remarks' => $remarks,
                'status' => $steps['status'],
                // An admin edit keeps who closed the day; the edit itself is in the activity log.
                'closed_by' => $existing ? $reading->closed_by : $user->id,
                'closed_at' => $existing ? $reading->closed_at : now(),
                'float_for_tomorrow' => $steps['float_for_tomorrow'],
                'deposit_bag_amount' => $steps['deposit_bag_amount'],
                'cash_opening_id' => $shopCash['opening']?->id ?? $reading->cash_opening_id,
            ]);
            $reading->save();
            $this->createMissingCycles($reading, $steps['missing_cycles'], $user->id);
            if ($existing) {
                Activity::log($request, 'z_reading_edited', $reading, [
                    'reason' => trim($validated['edit_reason']),
                    'before' => collect($existing->getAttributes())->only(array_keys($reading->getChanges()))->except(['updated_at'])->all(),
                ], $branchId);
            }

            // Also runs when an earlier, missed day is saved after later Zs exist.
            return [$reading, $this->recalculateFollowing($request, $reading)];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['locked' => 'This Z-Reading was just saved. Open it again to see it.']);
        }

        $message = "Z-Reading {$reading->reading_number} saved.";
        if ($changed !== []) {
            $message .= ' Openings recalculated for: '.implode(', ', $changed).'.';
        }
        if ($steps['status'] === 'with_over_short') {
            ShopCash::alert($request, $reading, $branchId, "Z-Reading {$reading->reading_number} saved with over/short: ".$steps['off_summary'].'.');
        }

        return redirect()
            ->route('admin.z-readings.index', ['branch_id' => $branchId, 'business_date' => $businessDate])
            ->with('success', $message);
    }

    public function pdf(Request $request, ZReading $zReading)
    {
        $this->authorizeReading($request, $zReading);

        $zReading->load(['branch', 'preparer', 'closer']);
        $details = $this->summary((int) $zReading->branch_id, $zReading->business_date->toDateString());
        $pdf = Pdf::loadView('admin.z-readings.pdf', [
            'denominations' => self::DENOMINATIONS,
            'details' => $details,
            'reading' => $zReading,
            'settings' => SystemSetting::current(),
            'signatories' => $this->signatories((int) $zReading->branch_id),
            'nonJobCycleReasons' => self::NON_JOB_CYCLE_REASONS,
            'showBank' => $request->user()->isAdmin(),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream($zReading->reading_number.'.pdf');
    }

    private function summary(int $branchId, string $businessDate): array
    {
        $financial = FinancialReconciliation::forPeriod($branchId, $businessDate, $businessDate);
        $moneyMovements = MoneyMovement::query()
            ->with('recorder')
            ->where('branch_id', $branchId)
            ->whereDate('movement_date', $businessDate)
            ->latest()
            ->get();

        $jobOrders = JobOrder::query()
            ->with([
                'customer:id,name,address,billing_type',
                'items:id,job_order_id,laundry_service_id,description,service_category,quantity,unit_price,total',
                'items.service:id,name,report_category,service_category_id',
                'items.service.serviceCategory:id,name',
                'payments' => fn ($query) => $query
                    ->where('collected_branch_id', $branchId)
                    ->whereDate('paid_at', $businessDate)
                    ->orderBy('paid_at'),
            ])
            ->where('branch_id', $branchId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('created_at', $businessDate)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $payments = Payment::query()
            ->with(['customer:id,name', 'jobOrder:id,job_order_number,created_at'])
            ->where('collected_branch_id', $branchId)
            ->whereDate('paid_at', $businessDate)
            ->whereIn('payment_type', ['cash', 'gcash', 'bank'])
            ->orderBy('paid_at')
            ->get();

        $currentSalesPayments = $payments
            ->filter(fn (Payment $payment) => $payment->jobOrder?->created_at?->toDateString() === $businessDate)
            ->values();
        $previousPayments = $payments
            ->reject(fn (Payment $payment) => $payment->jobOrder?->created_at?->toDateString() === $businessDate)
            ->values();

        $paymentTotals = fn ($items) => $items
            ->groupBy('payment_type')
            ->map(fn ($group) => round((float) $group->sum('amount'), 2))
            ->all();
        $regularUnpaidTotal = fn ($items) => round((float) $items
            ->sum('balance'), 2);

        $expenses = BranchExpense::query()
            ->with('creator:id,name')
            ->where('branch_id', $branchId)
            ->whereDate('expense_date', $businessDate)
            ->orderBy('id')
            ->get();

        $serviceTotals = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->leftJoin('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
            ->leftJoin('laundry_service_categories', 'laundry_service_categories.id', '=', 'laundry_services.service_category_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->where('job_orders.branch_id', $branchId)
            ->whereDate('job_orders.created_at', $businessDate)
            ->groupByRaw('COALESCE(laundry_service_categories.name, job_order_items.service_category, laundry_services.report_category, "Uncategorized")')
            ->groupByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->orderByRaw('COALESCE(laundry_service_categories.name, job_order_items.service_category, laundry_services.report_category, "Uncategorized")')
            ->orderByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->get([
                DB::raw('COALESCE(laundry_service_categories.name, job_order_items.service_category, laundry_services.report_category, "Uncategorized") as category_name'),
                DB::raw('COALESCE(laundry_services.name, job_order_items.description) as service_name'),
                DB::raw('SUM(job_order_items.quantity) as quantity'),
                DB::raw('SUM(job_order_items.total) as total_amount'),
            ]);

        $inventoryUsage = InventoryMovement::query()
            ->with(['inventory:id,branch_id,name,unit', 'user:id,name'])
            ->where('movement_type', 'out')
            ->whereDate('created_at', $businessDate)
            ->whereHas('inventory', fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('id')
            ->get();

        $machineCycles = DB::table('cycle_records')
            ->join('job_orders', 'job_orders.id', '=', 'cycle_records.job_order_id')
            ->whereNull('job_orders.deleted_at')
            ->whereIn('cycle_records.cycle_type', ['wash', 'dry'])
            ->whereNotNull('cycle_records.machine_number')
            // Cycles a Z-Reading added for "not clicked in POS" are already counted as its reasons.
            ->where(fn ($query) => $query->whereNull('cycle_records.notes')->orWhere('cycle_records.notes', 'not like', self::AUTO_CYCLE_NOTE.'%'))
            ->whereRaw('COALESCE(job_orders.processing_branch_id, job_orders.branch_id) = ?', [$branchId])
            ->whereDate('cycle_records.started_at', $businessDate)
            ->groupBy('cycle_records.machine_number', 'cycle_records.cycle_type')
            ->orderBy('cycle_records.machine_number')
            ->get([
                'cycle_records.machine_number',
                'cycle_records.cycle_type',
                DB::raw('COUNT(*) as cycle_count'),
            ]);

        $jobOrderItems = $jobOrders->map(fn (JobOrder $order) => [
            'job_order_number' => $order->job_order_number,
            'customer_name' => $order->customer?->name,
            'address' => $order->customer?->address,
            'billing_type' => $order->customer?->billing_type,
            'transaction_type' => $order->transaction_type,
            'created_at' => $order->created_at?->toDateTimeString(),
            'total' => round((float) $order->total, 2),
            'balance' => round((float) $order->balance, 2),
            'notes' => $order->notes,
            'services' => $order->items->map(fn ($item) => [
                'name' => $item->service?->name ?: $item->description,
                'quantity' => round((float) $item->quantity, 2),
                'unit_price' => round((float) $item->unit_price, 2),
                'total' => round((float) $item->total, 2),
            ])->values()->all(),
            'service_amounts' => $order->items
                ->groupBy(fn ($item) => $this->serviceCategoryLabel($item))
                ->map(fn ($items) => round((float) $items->sum('total'), 2))
                ->all(),
            'payments' => $order->payments
                ->whereIn('payment_type', ['cash', 'gcash', 'bank'])
                ->map(fn (Payment $payment) => [
                'type' => $payment->payment_type,
                'amount' => round((float) $payment->amount, 2),
                'reference_no' => $payment->reference_no,
            ])->values()->all(),
        ])->values()->all();

        return [
            'expected_cash_amount' => $financial['cash_collections'],
            'cash_expense_amount' => $financial['store_cash_expenses'],
            'expected_cash_drawer_amount' => $financial['expected_cash_drawer'],
            'expected_gcash_amount' => $financial['expected_gcash'],
            'expected_bank_amount' => $financial['expected_bank'],
            'expected_total_amount' => $financial['expected_total'],
            'payment_breakdown' => [
                'amounts' => collect($financial['payment_amounts'])->only(['cash', 'gcash', 'bank', 'unpaid', 'po', 'monthly_billing'])->all(),
                'counts' => collect($financial['payment_counts'])->only(['cash', 'gcash', 'bank', 'unpaid', 'po', 'monthly_billing'])->all(),
                'current_sales' => $paymentTotals($currentSalesPayments),
                'previous_payments' => $paymentTotals($previousPayments),
                'previous_payment_items' => $previousPayments->map(fn (Payment $payment) => [
                    'paid_at' => $payment->paid_at?->toDateTimeString(),
                    'payment_number' => $payment->payment_number,
                    'job_order_number' => $payment->jobOrder?->job_order_number,
                    'customer_name' => $payment->customer?->name,
                    'type' => $payment->payment_type,
                    'reference_no' => $payment->reference_no,
                    'amount' => round((float) $payment->amount, 2),
                ])->all(),
                'unpaid_amount' => 0,
                'po_amount' => $financial['po_collections'],
                'monthly_billing_amount' => $financial['monthly_billing_collections'],
            ],
            'expense_breakdown' => [
                'store_cash' => $financial['store_cash_expenses'],
                'store_gcash' => $financial['store_gcash_expenses'],
                'store_bank' => $financial['store_bank_expenses'],
                'owner' => $financial['owner_paid_expenses'],
                'items' => $expenses->map(fn (BranchExpense $expense) => [
                    'category' => $expense->category,
                    'title' => $expense->title,
                    'payment_method' => $expense->payment_method,
                    'paid_from' => $expense->paid_from,
                    'reference_no' => $expense->reference_no,
                    'remarks' => $expense->remarks,
                    'amount' => round((float) $expense->amount, 2),
                    'created_by' => $expense->creator?->name,
                ])->all(),
                'accounts_payable' => [
                    'gcash_funding' => $financial['gcash_owner_funding'],
                    'gcash_repayments' => $financial['gcash_payable_repayments'],
                    'bank_funding' => $financial['bank_owner_funding'],
                    'bank_repayments' => $financial['bank_payable_repayments'],
                ],
                'money_movements' => [
                    'cash_in' => $financial['cash_in'],
                    'cash_out' => $financial['cash_out'],
                    'bank_deposits' => $financial['bank_deposits'],
                    'items' => $moneyMovements->map(fn (MoneyMovement $movement) => [
                        'id' => $movement->id,
                        'type' => $movement->type,
                        'label' => $movement->type_label,
                        'direction' => $movement->direction,
                        'amount' => round((float) $movement->amount, 2),
                        'reference_no' => $movement->reference_no,
                        'bank_name' => $movement->bank_name,
                        'description' => $movement->description,
                        'recorded_by' => $movement->recorder?->name,
                    ])->values()->all(),
                ],
            ],
            'daily_total_sales' => round((float) $jobOrders->sum('total'), 2),
            'daily_unpaid_amount' => $regularUnpaidTotal($jobOrders),
            'current_sales_payment_total' => round((float) $currentSalesPayments->sum('amount'), 2),
            'previous_payment_total' => round((float) $previousPayments->sum('amount'), 2),
            'job_order_items' => $jobOrderItems,
            'service_totals' => $serviceTotals->map(fn ($row) => [
                'category_name' => $this->humanCategoryLabel($row->category_name),
                'service_name' => $row->service_name,
                'quantity' => round((float) $row->quantity, 2),
                'total_amount' => round((float) $row->total_amount, 2),
            ])->all(),
            'sales_columns' => $this->salesColumns($serviceTotals),
            'inventory_usage' => $inventoryUsage->map(fn (InventoryMovement $movement) => [
                'item_name' => $movement->inventory?->name,
                'quantity' => round((float) $movement->quantity, 4),
                'unit' => $movement->inventory?->unit,
                'remarks' => $movement->remarks,
                'recorded_by' => $movement->user?->name,
            ])->all(),
            'machine_cycles' => $machineCycles->map(fn ($row) => [
                'machine_number' => (int) $row->machine_number,
                'cycle_type' => $row->cycle_type,
                'cycle_count' => (int) $row->cycle_count,
            ])->all(),
            'shop_cash' => ShopCash::day($branchId, $businessDate),
            'transaction_count' => $jobOrders->count(),
            'first_job_order_number' => $jobOrders->first()?->job_order_number,
            'last_job_order_number' => $jobOrders->last()?->job_order_number,
        ];
    }

    private function salesColumns($serviceTotals): array
    {
        $categories = LaundryServiceCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $used = collect($serviceTotals)
            ->pluck('category_name')
            ->map(fn ($value) => $this->humanCategoryLabel($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return collect($categories)
            ->merge($used)
            ->unique()
            ->values()
            ->all();
    }

    private function serviceCategoryLabel($item): string
    {
        return $this->humanCategoryLabel(
            $item->service?->serviceCategory?->name
            ?: $item->service_category
            ?: $item->service?->report_category
            ?: 'Uncategorized'
        );
    }

    private function humanCategoryLabel(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'Uncategorized';
        }

        return str($value)->replace('_', ' ')->title()->toString();
    }

    private function normalizedCashCount(array $cashCount): array
    {
        return collect(self::DENOMINATIONS)
            ->mapWithKeys(fn (string $label, string $value) => [$value => max(0, (int) ($cashCount[$value] ?? 0))])
            ->all();
    }

    private function cashCountTotal(array $cashCount): float
    {
        $total = 0.0;

        foreach ($cashCount as $value => $quantity) {
            $total += (float) $value * (int) $quantity;
        }

        return round($total, 2);
    }

    /** Everything the 5 steps need that staff do not type: carried openings, POS totals, gates. */
    private function closing(Branch $branch, string $date, array $summary): array
    {
        $branchId = (int) $branch->id;
        $shopCash = $summary['shop_cash'];
        $previous = ZReading::query()
            ->where('branch_id', $branchId)
            ->whereDate('business_date', '<', $date)
            ->latest('business_date')
            ->latest('id')
            ->first();

        // Missed day: a date with sales after the last Z that never got its own Z.
        $missedDate = null;
        if ($previous) {
            $after = $previous->business_date->toDateString();
            $firstSale = collect([
                JobOrder::query()->where('branch_id', $branchId)->where('status', '!=', 'cancelled')
                    ->whereDate('created_at', '>', $after)->whereDate('created_at', '<', $date)->min('created_at'),
                Payment::query()->where('collected_branch_id', $branchId)
                    ->whereDate('paid_at', '>', $after)->whereDate('paid_at', '<', $date)->min('paid_at'),
            ])->filter()->min();
            $missedDate = $firstSale ? Carbon::parse($firstSale)->toDateString() : null;
        }

        $bagDeposits = MoneyMovement::query()
            ->where('branch_id', $branchId)
            ->whereDate('movement_date', $date)
            ->where('type', 'bank_deposit')
            ->whereNull('voided_at')
            ->where('bag_amount', '>', 0)
            ->get(['bag_amount', 'reference_no']);

        // Today's orders charged to a customer account (PO / monthly billing) are paid, but not in cash or GCash.
        $account = round((float) Payment::query()
            ->where('collected_branch_id', $branchId)
            ->whereDate('paid_at', $date)
            ->whereIn('payment_type', ['po', 'monthly_billing'])
            ->whereHas('jobOrder', fn ($query) => $query->where('branch_id', $branchId)->whereDate('created_at', $date))
            ->sum('amount'), 2);
        $cash = (float) data_get($summary, 'payment_breakdown.current_sales.cash', 0);
        $gcash = (float) data_get($summary, 'payment_breakdown.current_sales.gcash', 0);
        $bank = (float) data_get($summary, 'payment_breakdown.current_sales.bank', 0);
        $unpaid = (float) $summary['daily_unpaid_amount'];
        $total = (float) $summary['daily_total_sales'];
        $salesDiff = round($cash + $gcash + $bank + $account + $unpaid - $total, 2);
        // Zs saved before the rebuild have no trusted wallet balance: the admin enters it once.
        $setup = $previous === null || $previous->status === null;

        return [
            'previous' => $previous,
            'first' => $setup,
            'opening_float' => (float) $shopCash['opening_float'],
            'should_be' => (float) $shopCash['should_be'],
            'gcash_opening' => $setup ? null : (float) $previous->actual_gcash_amount,
            'gcash_payments' => (float) data_get($summary, 'payment_breakdown.amounts.gcash', 0),
            'gcash_expenses' => (float) data_get($summary, 'expense_breakdown.store_gcash', 0),
            // Owner money into the wallet, and payables paid out of it (Accounts Payable).
            'gcash_funding' => (float) data_get($summary, 'expense_breakdown.accounts_payable.gcash_funding', 0),
            'gcash_repayments' => (float) data_get($summary, 'expense_breakdown.accounts_payable.gcash_repayments', 0),
            'bag_due' => (float) $shopCash['bag_due'],
            'bag_deposited' => round((float) $bagDeposits->sum('bag_amount'), 2),
            'bag_refs' => $bagDeposits->pluck('reference_no')->filter()->implode(', '),
            'previous_counters' => $previous?->machine_counters ?? [],
            'system_cycles' => $this->machineCycleCounts($summary),
            'missed_date' => $missedDate,
            'sales' => [
                'job_orders' => (int) $summary['transaction_count'],
                'total' => $total,
                'cash' => $cash,
                'gcash' => $gcash,
                'bank' => $bank,
                'account' => $account,
                'old_orders_paid' => (float) $summary['previous_payment_total'],
                'unpaid' => $unpaid,
                'diff' => $salesDiff,
                'matches' => abs($salesDiff) < 0.005,
            ],
        ];
    }

    /**
     * Server copy of the page's step math (never trust the client).
     * Returns the computed step results plus the blockers as validation errors.
     */
    private function steps(Branch $branch, array $closing, array $input): array
    {
        $errors = [];
        $balanced = fn (float $diff) => abs($diff) < 0.005;
        $number = fn ($value) => is_numeric($value) ? round((float) $value, 2) : null;

        // Step 2 · Machines
        $machines = [];
        $missingCycles = [];
        foreach ($this->machineNumbers($branch, $closing) as $machine) {
            foreach (['wash', 'dry'] as $type) {
                $in = (array) data_get($input, "machine_counters.{$machine}.{$type}", []);
                $previousEnding = data_get($closing['previous_counters'], "{$machine}.{$type}.ending");
                $begin = is_numeric($previousEnding) ? (int) $previousEnding : (is_numeric($in['beginning'] ?? null) ? (int) $in['beginning'] : null);
                $end = is_numeric($in['ending'] ?? null) ? (int) $in['ending'] : null;
                $row = $this->machineRow($begin, $end, (int) data_get($closing['system_cycles'], "{$machine}.{$type}", 0), (array) ($in['non_job_cycles'] ?? []), $in['notes'] ?? null, $in['jo_ref'] ?? null);
                $machines[$machine][$type] = $row;

                $key = "machine_counters.{$machine}.{$type}";
                $label = ucfirst($type)." {$machine}";
                $extra = (int) $row['total'] - $row['system_cycles'];
                if ($begin === null) {
                    $errors["{$key}.beginning"] = "{$label}: enter the Start counter.";
                } elseif ($row['reconciliation_status'] === 'empty') {
                    $errors["{$key}.ending"] = "{$label}: enter the End counter.";
                } elseif ($row['reconciliation_status'] === 'end_low') {
                    $errors["{$key}.ending"] = "{$label}: End is lower than Start.";
                } elseif ($row['reconciliation_status'] === 'counter_low') {
                    $errors["{$key}.ending"] = "{$label}: counter is lower than the job orders. Check the machine on the JOs or re-read the counter.";
                } elseif ($row['non_job_total'] !== max(0, $extra)) {
                    $errors["{$key}.non_job_cycles"] = "{$label}: the reasons must add up to {$extra} extra cycle(s).";
                } elseif ($row['non_job_cycles']['not_recorded'] > 0 && ! $row['jo_ref']) {
                    $errors["{$key}.jo_ref"] = "{$label}: give the job order # for the wash/dry not clicked in POS.";
                } elseif ($row['non_job_cycles']['other'] > 0 && ! $row['notes']) {
                    $errors["{$key}.notes"] = "{$label}: explain the cycles marked Other.";
                } elseif ($row['non_job_cycles']['not_recorded'] > 0) {
                    $order = JobOrder::query()
                        ->where('job_order_number', $row['jo_ref'])
                        // Same rule the machine counts use, so the cycle lands on this branch's machine.
                        ->whereRaw('COALESCE(processing_branch_id, branch_id) = ?', [$branch->id])
                        ->first(['id']);
                    if (! $order) {
                        $errors["{$key}.jo_ref"] = "{$label}: no job order {$row['jo_ref']} at this branch.";
                    } else {
                        $missingCycles[] = ['job_order_id' => $order->id, 'machine' => $machine, 'type' => $type, 'count' => $row['non_job_cycles']['not_recorded']];
                    }
                }
            }
        }

        // Step 3 · Cash drawer
        if (collect((array) ($input['cash_count'] ?? []))->filter(fn ($quantity) => $quantity !== null && $quantity !== '')->isEmpty()) {
            $errors['cash_count'] = 'Count the cash in the drawer (type 0 for a bill or coin you do not have).';
        }
        $cashCount = $this->normalizedCashCount((array) ($input['cash_count'] ?? []));
        $actualCash = $this->cashCountTotal($cashCount);
        $cashDiff = round($actualCash - $closing['should_be'], 2);

        // Step 4 · GCash wallet
        $gcashOpening = $closing['first'] ? $number($input['gcash_opening'] ?? null) : $closing['gcash_opening'];
        if ($gcashOpening === null) {
            $errors['gcash_opening'] = 'Enter the GCash balance at the start (first Z-Reading).';
        }
        $actualGcash = $number($input['actual_gcash_amount'] ?? null);
        if ($actualGcash === null) {
            $errors['actual_gcash_amount'] = 'Enter the balance shown in the GCash app.';
        }
        $transfer = filter_var($input['gcash_transfer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $transferAmount = $transfer ? $number($input['gcash_transfer_amount'] ?? null) : null;
        $transferFee = $transfer ? ($number($input['gcash_transfer_fee'] ?? null) ?? 0.0) : 0.0;
        $transferRef = $transfer && filled($input['gcash_transfer_ref'] ?? null) ? trim($input['gcash_transfer_ref']) : null;
        if ($transfer && ! $transferAmount) {
            $errors['gcash_transfer_amount'] = 'Enter the amount sent to the bank.';
        }
        if ($transfer && ! $transferRef) {
            $errors['gcash_transfer_ref'] = 'Enter the GCash reference #.';
        }
        $expectedGcash = round((float) $gcashOpening + $closing['gcash_payments'] + $closing['gcash_funding'] - $closing['gcash_expenses'] - $closing['gcash_repayments'] - (float) $transferAmount - $transferFee, 2);
        $gcashDiff = round((float) $actualGcash - $expectedGcash, 2);

        // Step 5 · Bank deposit (last night's sealed bag)
        $bagDue = $closing['bag_due'];
        $carried = $bagDue > 0 && filter_var($input['deposit_carried_over'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $slipAmount = $bagDue > 0 && ! $carried ? $number($input['deposit_slip_amount'] ?? null) : null;
        $slipRef = $bagDue > 0 && ! $carried && filled($input['deposit_slip_ref'] ?? null) ? trim($input['deposit_slip_ref']) : null;
        $depositDiff = 0.0;
        if ($bagDue > 0 && ! $carried) {
            if ($slipAmount === null) {
                $errors['deposit_slip_amount'] = 'Enter the amount on the deposit slip, or tick "Not deposited today".';
            }
            if (! $slipRef) {
                $errors['deposit_slip_ref'] = 'Enter the slip / reference #.';
            }
            $depositDiff = round((float) $slipAmount - $bagDue, 2);
        }

        // Close the day
        $float = $number($input['float_for_tomorrow'] ?? null);
        if ($float === null) {
            $errors['float_for_tomorrow'] = 'Enter the float left in the drawer for tomorrow.';
        } elseif ($float != floor($float)) {
            // Tomorrow's opening count has no 25¢ coin.
            $errors['float_for_tomorrow'] = 'Leave a whole-peso float (no centavos).';
        } elseif ($float > $actualCash) {
            $errors['float_for_tomorrow'] = 'The float for tomorrow cannot be more than the cash counted ('.ShopCash::money($actualCash).').';
        }

        $off = collect([
            'Cash' => $cashDiff,
            'GCash' => $gcashDiff,
            'Deposit slip' => $depositDiff,
        ])->reject(fn ($diff) => $balanced($diff))
            ->map(fn ($diff, $step) => $step.' '.($diff > 0 ? 'over ' : 'short ').ShopCash::money(abs($diff)));

        return [
            'errors' => $errors,
            'machines' => $machines,
            'missing_cycles' => $missingCycles,
            'cash_count' => $cashCount,
            'cash' => ['expected' => $closing['should_be'], 'actual' => $actualCash, 'diff' => $cashDiff],
            'gcash' => [
                'opening' => $gcashOpening,
                'expected' => $expectedGcash,
                'actual' => (float) $actualGcash,
                'diff' => $gcashDiff,
                'transfer_amount' => $transferAmount,
                'transfer_fee' => $transferFee,
                'transfer_ref' => $transferRef,
            ],
            'deposit' => ['carried' => $carried, 'slip_amount' => $slipAmount, 'slip_ref' => $slipRef, 'diff' => $depositDiff],
            'float_for_tomorrow' => (float) $float,
            'deposit_bag_amount' => round($actualCash - (float) $float + ($carried ? $bagDue : 0), 2),
            'status' => $off->isEmpty() ? 'balanced' : 'with_over_short',
            'off_summary' => $off->implode(', '),
            'sales' => $closing['sales'],
        ];
    }

    /** One machine counter row. Reasons only count when the counter moved more than the job orders. */
    private function machineRow(?int $begin, ?int $end, int $system, array $reasons, ?string $notes, ?string $joRef): array
    {
        $used = $begin !== null && $end !== null ? $end - $begin : null;
        $diff = $used === null ? null : $used - $system;
        $reasons = collect(self::NON_JOB_CYCLE_REASONS)
            ->map(fn ($label, $reason) => $diff > 0 ? max(0, (int) ($reasons[$reason] ?? 0)) : 0)
            ->all();
        $reasonTotal = array_sum($reasons);
        $notes = filled($notes) ? trim($notes) : null;
        $joRef = $reasons['not_recorded'] > 0 && filled($joRef) ? trim($joRef) : null;

        return [
            'beginning' => $begin,
            'ending' => $end,
            'total' => $used === null ? null : max(0, $used),
            'system_cycles' => $system,
            'non_job_cycles' => $reasons,
            'non_job_total' => $reasonTotal,
            'unexplained_cycles' => $diff === null ? null : $diff - $reasonTotal,
            'notes' => $notes,
            'jo_ref' => $joRef,
            'reconciliation_status' => match (true) {
                $used === null => 'empty',
                $used < 0 => 'end_low',
                $diff === 0 => 'matched',
                $diff < 0 => 'counter_low',
                $reasonTotal === $diff && ($reasons['not_recorded'] === 0 || $joRef) && ($reasons['other'] === 0 || $notes) => 'explained',
                default => 'off',
            },
        ];
    }

    /** @return list<int> */
    private function machineNumbers(Branch $branch, array $closing): array
    {
        $count = max(
            (int) $branch->machine_count,
            (int) collect(array_keys($closing['system_cycles']))->max(),
        );

        return $count > 0 ? range(1, $count) : [];
    }

    /** Rows for the Step 2 table: Wash 1→n, then Dry 1→n. */
    private function machineRowsForForm(Branch $branch, array $closing, ?ZReading $reading, mixed $old): array
    {
        $rows = [];
        foreach (['wash', 'dry'] as $type) {
            foreach ($this->machineNumbers($branch, $closing) as $machine) {
                $source = is_array($old) ? (array) data_get($old, "{$machine}.{$type}", []) : (array) data_get($reading?->machine_counters, "{$machine}.{$type}", []);
                $previousEnding = data_get($closing['previous_counters'], "{$machine}.{$type}.ending");
                $class = MachineClass::forMachine($branch, $machine);
                $begin = is_numeric($previousEnding) ? (int) $previousEnding : (is_numeric($source['beginning'] ?? null) ? (int) $source['beginning'] : null);
                $system = (int) data_get($closing['system_cycles'], "{$machine}.{$type}", 0);

                $rows[] = [
                    'machine' => $machine,
                    'type' => $type,
                    'label' => ucfirst($type)." {$machine}",
                    'model' => MachineClass::label($class).' '.MachineClass::definitions()[$class]['rated_kg'].' kg',
                    'begin' => $begin,
                    'begin_editable' => ! is_numeric($previousEnding),
                    // Client: End fills in as Start + job order cycles; staff change it if the machine shows otherwise.
                    'end' => is_numeric($source['ending'] ?? null) ? (int) $source['ending'] : ($begin !== null ? $begin + $system : null),
                    'system' => $system,
                    'reasons' => collect(self::NON_JOB_CYCLE_REASONS)->map(fn ($label, $reason) => (int) data_get($source, "non_job_cycles.{$reason}", 0))->all(),
                    'notes' => $source['notes'] ?? '',
                    'jo_ref' => $source['jo_ref'] ?? '',
                ];
            }
        }

        return $rows;
    }

    /** Put the "not clicked in POS" cycles on their job orders so job-order cycles stay true. */
    private function createMissingCycles(ZReading $reading, array $missing, int $userId): void
    {
        $note = self::AUTO_CYCLE_NOTE.$reading->reading_number.': not clicked in POS';
        CycleRecord::query()->where('notes', $note)->delete();
        $at = Carbon::parse($reading->business_date->toDateString().' '.now()->format('H:i:s'));

        foreach ($missing as $cycle) {
            $last = (int) CycleRecord::query()->where('job_order_id', $cycle['job_order_id'])->where('cycle_type', $cycle['type'])->max('cycle_number');
            for ($i = 1; $i <= $cycle['count']; $i++) {
                CycleRecord::query()->create([
                    'job_order_id' => $cycle['job_order_id'],
                    'user_id' => $userId,
                    'cycle_type' => $cycle['type'],
                    'machine_number' => $cycle['machine'],
                    'cycle_number' => $last + $i,
                    'started_at' => $at,
                    'ended_at' => $at,
                    'notes' => $note,
                ]);
            }
        }
    }

    /**
     * After an admin edits a Z, every later Z's openings (counters, GCash, bag) and the
     * Shop Cash openings that expect its float are carried forward again.
     *
     * @return list<string> reading numbers that changed
     */
    private function recalculateFollowing(Request $request, ZReading $edited): array
    {
        $changed = [];
        $previous = $edited;
        $later = ZReading::query()
            ->where('branch_id', $edited->branch_id)
            ->whereDate('business_date', '>', $edited->business_date->toDateString())
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        foreach ($later as $reading) {
            $this->syncShopCashOpenings($previous, $reading->business_date->toDateString());

            if ($reading->status !== null) {
                // After a pre-rebuild Z the admin typed this Z's GCash opening; keep it.
                $gcashOpening = $previous->status === null ? (float) $reading->gcash_opening : (float) $previous->actual_gcash_amount;
                $bagDue = (float) ($previous->deposit_bag_amount ?? 0);
                $expectedGcash = round((float) $reading->expected_gcash_amount - (float) $reading->gcash_opening + $gcashOpening, 2);
                $gcashDiff = round((float) $reading->actual_gcash_amount - $expectedGcash, 2);
                $depositDiff = $reading->deposit_carried_over || $bagDue <= 0 ? 0.0 : round((float) $reading->deposit_slip_amount - $bagDue, 2);
                $counters = collect($reading->machine_counters ?? [])->map(fn ($types, $machine) => collect($types)->map(fn ($row, $type) => $this->machineRow(
                    is_numeric($ending = data_get($previous->machine_counters, "{$machine}.{$type}.ending")) ? (int) $ending : ($row['beginning'] ?? null),
                    $row['ending'] ?? null,
                    (int) ($row['system_cycles'] ?? 0),
                    $row['non_job_cycles'] ?? [],
                    $row['notes'] ?? null,
                    $row['jo_ref'] ?? null,
                ))->all())->all();

                $reading->fill([
                    'gcash_opening' => $gcashOpening,
                    'expected_gcash_amount' => $expectedGcash,
                    'gcash_diff' => $gcashDiff,
                    'bag_due' => $bagDue,
                    'deposit_diff' => $depositDiff,
                    'deposit_bag_amount' => round((float) $reading->deposit_bag_amount + ($reading->deposit_carried_over ? $bagDue - (float) $reading->bag_due : 0), 2),
                    'machine_counters' => $counters,
                    'expected_total_amount' => round((float) $reading->expected_cash_drawer_amount + $expectedGcash, 2),
                    'over_short_amount' => round((float) $reading->cash_diff + $gcashDiff, 2),
                    'status' => collect([(float) $reading->cash_diff, $gcashDiff, $depositDiff])->every(fn ($diff) => abs($diff) < 0.005) ? 'balanced' : 'with_over_short',
                ]);
                if ($reading->isDirty()) {
                    $becameOff = $reading->isDirty('status') && $reading->status === 'with_over_short';
                    $reading->save();
                    $changed[] = $reading->reading_number;
                    if ($becameOff) {
                        ShopCash::alert($request, $reading, (int) $reading->branch_id, "Z-Reading {$reading->reading_number} now shows an over/short after an earlier Z-Reading was changed.");
                    }
                }
            }

            $previous = $reading;
        }
        $this->syncShopCashOpenings($previous, null);

        return $changed;
    }

    private function syncShopCashOpenings(ZReading $previous, ?string $untilDate): void
    {
        if ($previous->float_for_tomorrow === null) {
            return;
        }

        CashOpening::query()
            ->where('branch_id', $previous->branch_id)
            ->whereDate('business_date', '>', $previous->business_date->toDateString())
            ->when($untilDate, fn ($query) => $query->whereDate('business_date', '<=', $untilDate))
            ->get()
            ->each(fn (CashOpening $opening) => $opening->update([
                'expected_float' => $previous->float_for_tomorrow,
                'difference' => round((float) $opening->counted_amount - (float) $previous->float_for_tomorrow, 2),
                'bag_due' => $previous->deposit_bag_amount ?? 0,
            ]));
    }

    private function machineCycleCounts(array $summary): array
    {
        return collect($summary['machine_cycles'] ?? [])
            ->groupBy('machine_number')
            ->map(fn ($rows) => collect($rows)->mapWithKeys(fn ($row) => [
                $row['cycle_type'] => (int) $row['cycle_count'],
            ])->all())
            ->all();
    }

    private function nextReadingNumber(int $branchId, string $businessDate): string
    {
        $branchCode = Branch::query()->whereKey($branchId)->value('code') ?: 'BR'.$branchId;
        $count = ZReading::query()
            ->whereDate('created_at', today())
            ->count() + 1;

        return 'ZR-'.$branchCode.'-'.Carbon::parse($businessDate)->format('Ymd').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function signatories(int $branchId): array
    {
        $users = User::query()
            ->where('branch_id', $branchId)
            ->whereIn('role', ['branch_manager', 'cashier'])
            ->where('status', 'active')
            ->orderByRaw("CASE role WHEN 'branch_manager' THEN 0 WHEN 'cashier' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get(['name', 'role']);

        return [
            'branch_manager' => $users->where('role', 'branch_manager')->pluck('name')->values()->all(),
            'cashier' => $users->where('role', 'cashier')->pluck('name')->values()->all(),
        ];
    }

    private function authorizeReading(Request $request, ZReading $reading): void
    {
        if ($request->user()->canManageAllBranches()) {
            return;
        }

        abort_unless((int) $request->user()->branch_id === (int) $reading->branch_id, 403);
    }
}
