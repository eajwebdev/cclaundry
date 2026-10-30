<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\DailyTask;
use App\Support\BusinessDefaults;
use App\Support\DefaultInventoryItems;
use App\Support\DefaultLaundryServices;
use App\Support\DefaultServiceInventoryUsages;
use App\Support\MachineClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $branches = Branch::query()
            ->when(! $user->canManageAllBranches(), fn ($query) => $query->whereKey($user->branch_id))
            ->when(in_array($request->status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $request->status === 'active'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('contact_number', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            })
            ->with(['dailyTasks' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->withCount('users')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.branches.index', [
            'branches' => $branches,
            'canCreateBranch' => $user->isSuperAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $validated = $request->validate($this->rules());
        $validated['branch_type'] = $validated['branch_type'] ?? 'full_service';
        $validated['machine_count'] = $validated['branch_type'] === 'pickup_dropoff'
            ? 0
            : ($validated['machine_count'] ?? BusinessDefaults::MACHINE_COUNT);
        $validated = $this->withTitanMachines($request, $validated, (int) $validated['machine_count']);

        DB::transaction(function () use ($request, $validated) {
            $branch = Branch::create($validated + ['is_active' => $request->boolean('is_active')]);

            DefaultLaundryServices::seedForBranch($branch);
            DefaultInventoryItems::seedForBranch($branch);
            DefaultServiceInventoryUsages::seedForBranch($branch);
            BranchSetting::firstOrCreate(
                ['branch_id' => $branch->id],
                [
                    'job_order_prefix' => $branch->code,
                    'invoice_prefix' => 'INV-'.$branch->code,
                ]
            );
        });

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch created successfully.');
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorizeBranch($branch);

        $validated = $request->validate($this->rules($branch));
        $validated['branch_type'] = $validated['branch_type'] ?? 'full_service';
        $validated['is_active'] = $request->boolean('is_active');
        $validated['latitude'] = null;
        $validated['longitude'] = null;
        $validated['attendance_radius_meters'] = null;
        $validated = $this->withTitanMachines(
            $request,
            $validated,
            (int) (array_key_exists('machine_count', $validated) ? $validated['machine_count'] : $branch->machine_count)
        );

        $branch->update($validated);

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        if ($branch->users()->exists()) {
            return back()->with('error', 'Branch has users and cannot be deleted.');
        }

        $branch->delete();

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch deleted successfully.');
    }

    public function storeTask(Request $request, Branch $branch)
    {
        $this->authorizeBranch($branch);

        $validated = $request->validate($this->taskRules($request, $branch));
        $validated['branch_id'] = $branch->id;
        $validated['requires_photo'] = $request->boolean('requires_photo', true);
        $validated['is_active'] = $request->boolean('is_active', true);

        DailyTask::create($validated);

        return back()->with('success', 'Branch task added successfully.');
    }

    public function updateTask(Request $request, Branch $branch, DailyTask $task)
    {
        $this->authorizeBranchTask($branch, $task);

        $validated = $request->validate($this->taskRules($request, $branch, $task));
        if (isset($validated['category']) && $validated['category'] !== $task->category && $task->completions()->exists()) {
            throw ValidationException::withMessages(['category' => 'Create a new task to change the schedule of a task with completion history.']);
        }
        $validated['requires_photo'] = $request->boolean('requires_photo');
        $validated['is_active'] = $request->boolean('is_active');

        $task->update($validated);

        return back()->with('success', 'Branch task updated successfully.');
    }

    public function destroyTask(Branch $branch, DailyTask $task)
    {
        $this->authorizeBranchTask($branch, $task);
        $task->update(['is_active' => false]);

        return back()->with('success', 'Branch task removed.');
    }

    private function rules(?Branch $branch = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')->ignore($branch?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'branch_type' => ['nullable', Rule::in(['full_service', 'pickup_dropoff'])],
            'machine_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'titan_machines' => ['nullable', 'array', 'max:100'],
            'titan_machines.*' => ['integer', 'min:1', 'max:100'],
            'qr_pay_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Save the Titan washer numbers only when the form sent the picker, so
     * older forms and scripts leave the saved machine sizes alone.
     */
    private function withTitanMachines(Request $request, array $validated, int $machineCount): array
    {
        unset($validated['titan_machines']);

        if ($request->has('titan_machines_present')) {
            $validated['titan_machine_numbers'] = MachineClass::normalizeNumbers($request->input('titan_machines', []), $machineCount) ?: null;
        }

        return $validated;
    }

    private function authorizeBranch(Branch $branch): void
    {
        $user = auth()->user();

        if ($user->canManageAllBranches()) {
            return;
        }

        abort_unless((int) $user->branch_id === (int) $branch->id, 403);
    }

    private function authorizeBranchTask(Branch $branch, DailyTask $task): void
    {
        $this->authorizeBranch($branch);
        abort_unless((int) $task->branch_id === (int) $branch->id, 403);
    }

    private function taskRules(Request $request, Branch $branch, ?DailyTask $task = null): array
    {
        $category = $request->input('category', $task?->category ?? 'end_of_day');
        $category = is_string($category) ? $category : '';
        $nameRule = Rule::unique('daily_tasks', 'name')
            ->where(fn ($query) => $query
                ->where('branch_id', $branch->id)
                ->where('category', $category)
                ->where('is_active', true));

        if ($task) {
            $nameRule->ignore($task->id);
        }

        return [
            'name' => ['required', 'string', 'max:255', $nameRule],
            'category' => ['sometimes', 'required', Rule::in(array_keys(DailyTask::CATEGORIES))],
            'requires_photo' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
