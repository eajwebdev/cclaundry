<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MoneyMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PettyCashController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'movement_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'type' => ['nullable', 'string', Rule::in(array_keys(MoneyMovement::typeOptions()))],
        ]);
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        $businessDate = $request->date('movement_date')?->toDateString() ?: today()->toDateString();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $branchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;

        abort_unless($branchId, 403);

        $branch = $branches->firstWhere('id', $branchId) ?: Branch::query()->findOrFail($branchId);
        if (! $canChooseBranch) {
            abort_unless((int) $user->branch_id === (int) $branch->id, 403);
        }

        $baseQuery = MoneyMovement::query()
            ->with(['branch', 'recorder'])
            ->where('branch_id', $branch->id)
            ->whereDate('movement_date', $businessDate)
            ->when($request->filled('type') && array_key_exists($request->type, MoneyMovement::typeOptions()), function ($query) use ($request) {
                $query->where(fn ($query) => $query
                    ->where('type', $request->type)
                    ->orWhereIn('type', match ($request->type) {
                        'deposit' => ['owner_cash_in', 'cash_float_in'],
                        'withdraw' => ['sales_withdrawal', 'cash_out'],
                        default => [],
                    }));
            });

        $summary = (clone $baseQuery)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as cash_in, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as cash_out, COALESCE(SUM(CASE WHEN type = 'bank_deposit' THEN amount ELSE 0 END), 0) as bank_deposits")
            ->first();

        $movements = $baseQuery
            ->latest('movement_date')
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.petty-cash.index', [
            'branch' => $branch,
            'branches' => $branches,
            'businessDate' => $businessDate,
            'canChooseBranch' => $canChooseBranch,
            'movements' => $movements,
            'movementTypes' => MoneyMovement::typeOptions(),
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();

        $validated = $request->validate([
            'branch_id' => [$canChooseBranch ? 'required' : 'nullable', 'exists:branches,id'],
            'movement_date' => ['required', 'date'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(MoneyMovement::typeOptions()))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'reference_no' => ['required_if:type,bank_deposit', 'nullable', 'string', 'max:255'],
            'bank_name' => ['required_if:type,bank_deposit', 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = $canChooseBranch ? (int) $validated['branch_id'] : (int) $user->branch_id;
        abort_unless($branchId, 403);

        MoneyMovement::query()->create([
            'branch_id' => $branchId,
            'recorded_by' => $user->id,
            'movement_date' => Carbon::parse($validated['movement_date'])->toDateString(),
            'type' => $validated['type'],
            'direction' => MoneyMovement::typeOptions()[$validated['type']]['direction'],
            'amount' => round((float) $validated['amount'], 2),
            'reference_no' => $validated['reference_no'] ?? null,
            'bank_name' => $validated['type'] === 'bank_deposit' ? $validated['bank_name'] : null,
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()
            ->route('admin.petty-cash.index', [
                'branch_id' => $branchId,
                'movement_date' => Carbon::parse($validated['movement_date'])->toDateString(),
            ])
            ->with('success', 'Money movement recorded successfully.');
    }

    public function destroy(Request $request, MoneyMovement $moneyMovement)
    {
        if (! $request->user()->canManageAllBranches()) {
            abort_unless((int) $request->user()->branch_id === (int) $moneyMovement->branch_id, 403);
        }

        $branchId = $moneyMovement->branch_id;
        $movementDate = $moneyMovement->movement_date?->toDateString();
        $moneyMovement->delete();

        return redirect()
            ->route('admin.petty-cash.index', [
                'branch_id' => $branchId,
                'movement_date' => $movementDate,
            ])
            ->with('success', 'Money movement removed successfully.');
    }
}
