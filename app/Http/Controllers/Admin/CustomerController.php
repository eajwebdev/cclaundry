<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CycleRecord;
use App\Support\CustomerInsights;
use App\Support\MachineClass;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    private const BILLING_TYPES = ['regular', 'po', 'monthly_billing'];
    private const STATUS_FILTERS = ['active', 'inactive'];
    private const SORT_COLUMNS = [
        'name' => 'customers.name',
        'branch' => 'branch',
        'unpaid_limit' => 'customers.unpaid_limit',
        'visits' => 'job_orders_count',
        'spent' => 'total_spent',
        'status' => 'customers.is_active',
        'created_at' => 'customers.created_at',
    ];

    public function index(Request $request)
    {
        $sorting = $request->validate([
            'sort' => ['nullable', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'period' => ['nullable', Rule::in(array_keys(CustomerInsights::PERIODS))],
        ]);
        $period = $sorting['period'] ?? 'month';
        $sort = $sorting['sort'] ?? 'created_at';
        $direction = $sorting['direction'] ?? (in_array($sort, ['unpaid_limit', 'visits', 'spent', 'created_at'], true) ? 'desc' : 'asc');
        $sortColumn = $sort === 'branch'
            ? Branch::query()->select('name')->whereColumn('branches.id', 'customers.branch_id')->limit(1)
            : self::SORT_COLUMNS[$sort];
        $user = $request->user();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $this->canChooseBranch($user), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        // Everyone this user may see in the chosen branch; the insights panel
        // and the table both start from here.
        $visibleCustomers = Customer::query()
            ->when(! $this->canChooseBranch($user), fn ($query) => $query->where('customers.branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && $this->canChooseBranch($user), fn ($query) => $query->where('customers.branch_id', $request->branch_id));
        $insights = (new CustomerInsights(clone $visibleCustomers, $period))->toArray();

        $visits = fn ($orders) => $orders->where('status', '!=', 'cancelled');
        $customers = (clone $visibleCustomers)
            ->with('branch')
            ->withCount(['jobOrders' => $visits])
            ->withSum(['jobOrders as total_spent' => $visits], 'total')
            ->withMax(['jobOrders as last_visit_at' => $visits], 'created_at')
            ->when(in_array($request->status, self::STATUS_FILTERS, true), fn ($query) => $query->where('is_active', $request->status === 'active'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $direction)
            ->orderBy('customers.id', $direction)
            ->paginate(10)
            ->withQueryString();

        $topSpenderIds = $insights['topSpenders']->pluck('customer.id')->all();

        return view('admin.customers.index', compact('customers', 'branches', 'sort', 'direction', 'period', 'insights', 'topSpenderIds'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $validated = $this->normalizeBranch($validated);
        $validated['is_active'] = $request->boolean('is_active', true);
        unset($validated['redirect_to']);

        $customer = Customer::create($validated);

        if ($request->input('redirect_to') === 'pos') {
            return redirect()
                ->route('admin.job-orders.create', [
                    'branch_id' => $customer->branch_id,
                    'customer_id' => $customer->id,
                ])
                ->with('success', 'Customer created successfully.');
        }

        return redirect()
            ->route('admin.customers.index')
            ->with('success', 'Customer created successfully.');
    }

    public function history(Request $request, Customer $customer)
    {
        $this->authorizeCustomer($customer);
        $query = $customer->jobOrders()
            ->when(! $this->canChooseBranch($request->user()), fn ($orders) => $orders->where('branch_id', $request->user()->branch_id));
        $visits = (clone $query)->where('status', '!=', 'cancelled');
        $visitCount = (clone $visits)->count();
        $firstVisit = (clone $visits)->oldest('created_at')->value('created_at');
        $lastVisit = (clone $visits)->latest('created_at')->value('created_at');
        $orders = (clone $query)
            ->with([
                'items',
                'branch:id,name,machine_count,titan_machine_numbers',
                'processingBranch:id,name,machine_count,titan_machine_numbers',
                // Only cycles run on a numbered machine say which machine was used.
                'cycles' => fn ($cycles) => $cycles->whereNotNull('machine_number')->orderBy('started_at')->orderBy('id'),
            ])
            ->latest('created_at')->orderByDesc('id')->paginate(10);

        // Every washer and dryer this customer's laundry has gone through,
        // across all their orders, most used first.
        $machineUsage = CycleRecord::query()
            ->join('job_orders', 'job_orders.id', '=', 'cycle_records.job_order_id')
            ->whereIn('cycle_records.job_order_id', (clone $query)->select('job_orders.id'))
            ->whereNull('job_orders.deleted_at')
            ->whereNotNull('cycle_records.machine_number')
            ->whereIn('cycle_records.cycle_type', ['wash', 'dry'])
            ->selectRaw('COALESCE(job_orders.processing_branch_id, job_orders.branch_id) as machine_branch_id')
            ->selectRaw('cycle_records.machine_number, cycle_records.cycle_type, COUNT(*) as uses, MAX(cycle_records.started_at) as last_used_at')
            ->groupByRaw('COALESCE(job_orders.processing_branch_id, job_orders.branch_id), cycle_records.machine_number, cycle_records.cycle_type')
            ->orderByDesc('uses')->orderBy('cycle_records.machine_number')
            ->get();
        $machineBranches = Branch::query()
            ->whereIn('id', $machineUsage->pluck('machine_branch_id')->unique())
            ->get(['id', 'name', 'machine_count', 'titan_machine_numbers'])
            ->keyBy('id');
        $machineUsage->each(function ($row) use ($machineBranches) {
            $row->branch = $machineBranches->get((int) $row->machine_branch_id);
            $row->machine_class = MachineClass::label(MachineClass::forMachine($row->branch, (int) $row->machine_number));
        });
        $showMachineBranch = $machineBranches->count() > 1;

        return view('admin.customers.partials.history', compact('customer', 'visitCount', 'firstVisit', 'lastVisit', 'orders', 'machineUsage', 'showMachineBranch'));
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorizeCustomer($customer);

        $validated = $request->validate($this->rules($customer));
        $validated = $this->normalizeBranch($validated);
        $validated['is_active'] = $request->boolean('is_active');

        $customer->update($validated);

        return redirect()
            ->route('admin.customers.index')
            ->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer)
    {
        $this->authorizeCustomer($customer);

        if ($customer->jobOrders()->exists()) {
            return back()->with('error', 'Customer has job orders and cannot be deleted.');
        }

        $customer->delete();

        return redirect()
            ->route('admin.customers.index')
            ->with('success', 'Customer deleted successfully.');
    }

    private function rules(?Customer $customer = null): array
    {
        return [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('customers', 'email')->ignore($customer?->id),
            ],
            'address' => ['nullable', 'string'],
            'unpaid_limit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'redirect_to' => ['nullable', Rule::in(['pos'])],
        ];
    }

    private function normalizeBranch(array $validated): array
    {
        $user = auth()->user();

        if (! $this->canChooseBranch($user)) {
            if (! $user->branch_id) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Your account is not assigned to a branch yet.',
                ]);
            }

            $validated['branch_id'] = $user->branch_id;
            return $validated;
        }

        if (empty($validated['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => 'Please choose a branch.',
            ]);
        }

        return $validated;
    }

    private function authorizeCustomer(Customer $customer): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin() || $user->role === 'admin') {
            return;
        }

        abort_unless((int) $user->branch_id === (int) $customer->branch_id, 403);
    }

    private function canChooseBranch($user): bool
    {
        return $user->isSuperAdmin() || $user->role === 'admin';
    }
}
