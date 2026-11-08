<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\CashOpening;
use App\Models\MoneyMovement;
use App\Support\Activity;
use App\Support\ExpenseReceipt;
use App\Support\ShopCash;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shop Cash (was Petty Cash): opening count, owner in/out, bank deposit, drawer-expense confirmations. */
class PettyCashController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'movement_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
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

        $day = ShopCash::day((int) $branch->id, $businessDate);

        return view('admin.petty-cash.index', [
            'branch' => $branch,
            'branches' => $branches,
            'businessDate' => $businessDate,
            'canChooseBranch' => $canChooseBranch,
            'day' => $day,
            'lines' => $this->drawerLines((int) $branch->id, $businessDate, $day, $user->isAdmin()),
            'locked' => $day['z_reading'] && ! $user->isAdmin(),
            'denominations' => CashOpening::DENOMINATIONS,
            'categories' => ExpenseController::CATEGORIES,
            'employees' => AttendanceEmployee::query()
                ->with(['branch:id,name', 'user:id,monthly_salary'])
                ->where('status', 'active')
                ->where('branch_id', $branch->id)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
        ]);
    }

    public function open(Request $request)
    {
        $user = $request->user();
        [$branchId, $date] = $this->target($request);
        $validated = $request->validate([
            'denominations' => ['required', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'starting_float' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'starting_bag' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $day = ShopCash::day($branchId, $date);
        $this->guardClosed($request, $day);
        if ($day['opening']) {
            throw ValidationException::withMessages(['opening' => 'The shop is already open for this date.']);
        }

        $expected = $day['expected_float'];
        $bagDue = $day['bag_due'];
        if ($expected === null) {
            // First day after go-live: no Z float to compare with yet.
            if (! $user->isAdmin() || ! isset($validated['starting_float'])) {
                throw ValidationException::withMessages(['starting_float' => 'There is no Z-Reading float yet. The admin enters the starting float and deposit bag once.']);
            }
            $expected = round((float) $validated['starting_float'], 2);
            $bagDue = round((float) ($validated['starting_bag'] ?? 0), 2);
        }

        $counts = collect(CashOpening::DENOMINATIONS)
            ->mapWithKeys(fn ($value) => [$value => (int) ($validated['denominations'][$value] ?? 0)])
            ->all();
        $counted = round(collect($counts)->map(fn ($qty, $value) => $qty * (int) $value)->sum(), 2);
        $difference = round($counted - $expected, 2);
        $reason = trim((string) ($validated['reason'] ?? ''));

        if ($difference != 0 && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'The count does not match the float. Explain the difference before opening.']);
        }

        try {
            $opening = CashOpening::query()->create([
            'branch_id' => $branchId,
            'business_date' => $date,
            'expected_float' => $expected,
            'bag_due' => $bagDue,
            'denominations' => $counts,
            'counted_amount' => $counted,
            'difference' => $difference,
            'reason' => $reason ?: null,
            'opened_by' => $user->id,
            'opened_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['opening' => 'The shop is already open for this date.']);
        }

        Activity::log($request, 'cash_opening_confirmed', $opening, ['counted' => $counted, 'expected' => $expected, 'difference' => $difference], $branchId);
        if ($difference != 0) {
            ShopCash::alert($request, $opening, $branchId, sprintf(
                'Opening float %s %s on %s (%s): %s',
                $difference > 0 ? 'OVER' : 'SHORT',
                ShopCash::money(abs($difference)),
                $date,
                Branch::query()->whereKey($branchId)->value('name'),
                $reason
            ));
        }

        return $this->backToDay($branchId, $date, 'Shop opened.');
    }

    public function reopen(Request $request, CashOpening $cashOpening)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $branchId = (int) $cashOpening->branch_id;
        $date = $cashOpening->business_date->toDateString();
        $day = ShopCash::day($branchId, $date);
        if ($day['z_reading']) {
            throw ValidationException::withMessages(['opening' => 'The Z-Reading for this day is saved. The opening can no longer be removed.']);
        }
        if (MoneyMovement::query()->where('branch_id', $branchId)->whereDate('movement_date', $date)->whereNull('voided_at')->exists()) {
            throw ValidationException::withMessages(['opening' => 'Void this day\'s cash movements first, then count the float again.']);
        }

        Activity::log($request, 'cash_opening_reopened', $cashOpening, ['before' => $cashOpening->only(['counted_amount', 'expected_float', 'difference', 'reason', 'opened_by'])], $branchId);
        $cashOpening->delete();

        return $this->backToDay($branchId, $date, 'Opening removed. Count the float again.');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        [$branchId, $date] = $this->target($request);
        $type = $request->input('type');
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(MoneyMovement::TYPES))],
            'amount' => [Rule::requiredIf($type !== 'bank_deposit'), 'nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'person_name' => [Rule::requiredIf($type !== 'bank_deposit'), 'nullable', 'string', 'max:255'],
            'bag_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'drawer_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'reference_no' => array_filter([Rule::requiredIf($type === 'bank_deposit'), 'nullable', 'string', 'max:255',
                // The same slip saved twice (double tap) would double the deposit.
                $type === 'bank_deposit' ? Rule::unique('money_movements', 'reference_no')->where(fn ($query) => $query
                    ->where('branch_id', $branchId)->where('type', 'bank_deposit')->whereNull('voided_at')) : null]),
            'photo' => [Rule::requiredIf($type === 'bank_deposit'), 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'reference_no.required' => 'Enter the deposit slip reference number.',
            'reference_no.unique' => 'This deposit slip is already recorded.',
            'photo.required' => 'Add a photo of the deposit slip.',
            'person_name.required' => 'Enter who took or gave the cash.',
        ]);

        $day = $this->openDay($request, $branchId, $date);
        $bag = round((float) ($validated['bag_amount'] ?? 0), 2);
        $fromDrawer = match ($type) {
            'bank_deposit' => round((float) ($validated['drawer_amount'] ?? 0), 2),
            'owner_out' => round((float) $validated['amount'], 2),
            default => 0.0,
        };
        $amount = $type === 'bank_deposit' ? round($bag + $fromDrawer, 2) : round((float) $validated['amount'], 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['bag_amount' => 'The deposit slip total must be more than ₱0.00.']);
        }
        $this->guardDrawer($fromDrawer, $day);

        $bagShort = $type === 'bank_deposit' && $bag < $day['bag_left'];
        if ($bagShort && blank($validated['description'] ?? null)) {
            throw ValidationException::withMessages(['description' => 'The bag amount is less than what the Z-Reading put in the bag. Explain in the note.']);
        }

        try {
            $photoPath = $request->hasFile('photo') ? ExpenseReceipt::store($request->file('photo')) : null;
        } catch (ValidationException) {
            throw ValidationException::withMessages(['photo' => 'The slip photo could not be read. Please use a JPG, PNG or WEBP picture.']);
        }
        $movement = MoneyMovement::query()->create([
            'branch_id' => $branchId,
            'recorded_by' => $user->id,
            'movement_date' => $date,
            'type' => $type,
            'direction' => MoneyMovement::TYPES[$type]['direction'],
            'amount' => $amount,
            'bag_amount' => $type === 'bank_deposit' ? $bag : 0,
            'drawer_amount' => $type === 'bank_deposit' ? $fromDrawer : 0,
            'person_name' => $type === 'bank_deposit' ? null : $validated['person_name'],
            'reference_no' => $validated['reference_no'] ?? null,
            'photo_path' => $photoPath,
            'description' => $validated['description'] ?? null,
        ]);

        Activity::log($request, 'shop_cash_movement', $movement, $movement->only(['type', 'amount', 'bag_amount', 'drawer_amount', 'person_name']), $branchId);
        if ($bagShort) {
            ShopCash::alert($request, $movement, $branchId, sprintf(
                'Bank deposit bag SHORT %s vs the bag on %s: %s',
                ShopCash::money($day['bag_left'] - $bag),
                $date,
                $validated['description']
            ));
        }

        return $this->backToDay($branchId, $date, MoneyMovement::TYPES[$type]['label'].' saved.');
    }

    public function photo(Request $request, MoneyMovement $moneyMovement)
    {
        $this->authorizeBranch($request, (int) $moneyMovement->branch_id);
        abort_unless(ExpenseReceipt::exists($moneyMovement->photo_path), 404);

        return ExpenseReceipt::response($moneyMovement->photo_path);
    }

    /** No silent edits: an admin voids with a reason, and the line stays visible. */
    public function destroy(Request $request, MoneyMovement $moneyMovement)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $validated = $request->validate(['void_reason' => ['required', 'string', 'max:255']]);
        abort_if($moneyMovement->voided_at !== null, 422, 'This line is already voided.');

        $moneyMovement->update([
            'voided_at' => now(),
            'voided_by' => $request->user()->id,
            'void_reason' => $validated['void_reason'],
        ]);
        Activity::log($request, 'shop_cash_movement_voided', $moneyMovement, ['reason' => $validated['void_reason']], (int) $moneyMovement->branch_id);

        return $this->backToDay((int) $moneyMovement->branch_id, $moneyMovement->movement_date->toDateString(), 'Line voided.');
    }

    public function confirmExpense(Request $request, BranchExpense $expense)
    {
        $day = $this->pendingExpenseDay($request, $expense);
        $this->guardDrawer((float) $expense->drawer_amount, $day);

        $expense->update(['drawer_status' => 'confirmed', 'confirmed_by' => $request->user()->id, 'confirmed_at' => now()]);
        Activity::log($request, 'expense_drawer_confirmed', $expense, ['drawer_amount' => $expense->drawer_amount], (int) $expense->branch_id);

        return $this->backToDay((int) $expense->branch_id, $expense->expense_date->toDateString(), 'Expense confirmed.');
    }

    public function disputeExpense(Request $request, BranchExpense $expense)
    {
        $validated = $request->validate(['dispute_note' => ['nullable', 'string', 'max:255']]);
        $this->pendingExpenseDay($request, $expense);

        $expense->update([
            'drawer_status' => 'disputed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'dispute_note' => $validated['dispute_note'] ?? null,
        ]);
        ShopCash::alert($request, $expense, (int) $expense->branch_id, sprintf(
            'Expense "%s" (%s drawer cash) on %s was marked NOT from the drawer by %s.',
            $expense->title,
            ShopCash::money($expense->drawer_amount),
            $expense->expense_date->toDateString(),
            $request->user()->name
        ));

        return $this->backToDay((int) $expense->branch_id, $expense->expense_date->toDateString(), 'Expense marked as not from this drawer.');
    }

    private function pendingExpenseDay(Request $request, BranchExpense $expense): array
    {
        $this->authorizeBranch($request, (int) $expense->branch_id);
        abort_unless($expense->drawer_status === 'pending', 422, 'This expense is not waiting for confirmation.');

        return $this->openDay($request, (int) $expense->branch_id, $expense->expense_date->toDateString());
    }

    /** Every movement needs the opening count first, and a saved Z locks the date for staff. */
    private function openDay(Request $request, int $branchId, string $date): array
    {
        $day = ShopCash::day($branchId, $date);
        $this->guardClosed($request, $day);
        if (! $day['opening']) {
            throw ValidationException::withMessages(['opening' => 'Open the shop first: count the float in Step 1.']);
        }

        return $day;
    }

    private function guardClosed(Request $request, array $day): void
    {
        // shortcut: an admin may still record after the Z is saved; the Z must then be re-saved to pick it up.
        if ($day['z_reading'] && ! $request->user()->isAdmin()) {
            throw ValidationException::withMessages(['opening' => 'Tonight\'s Z-Reading is already saved. Ask the admin to record late changes.']);
        }
    }

    private function guardDrawer(float $fromDrawer, array $day): void
    {
        if ($fromDrawer > $day['should_be']) {
            throw ValidationException::withMessages(['amount' => 'That is more than the cash in the drawer ('.ShopCash::money($day['should_be']).'). Check the amount.']);
        }
    }

    private function target(Request $request): array
    {
        $user = $request->user();
        $validated = $request->validate([
            'branch_id' => [$user->canManageAllBranches() ? 'required' : 'nullable', 'integer', 'exists:branches,id'],
            'movement_date' => ['required', 'date'],
        ]);
        $branchId = $user->canManageAllBranches() ? (int) $validated['branch_id'] : (int) $user->branch_id;
        abort_unless($branchId, 403);

        return [$branchId, Carbon::parse($validated['movement_date'])->toDateString()];
    }

    private function authorizeBranch(Request $request, int $branchId): void
    {
        if (! $request->user()->canManageAllBranches()) {
            abort_unless((int) $request->user()->branch_id === $branchId, 403);
        }
    }

    private function backToDay(int $branchId, string $date, string $message)
    {
        return redirect()
            ->route('admin.petty-cash.index', ['branch_id' => $branchId, 'movement_date' => $date])
            ->with('success', $message);
    }

    /** Today's drawer list with a running balance; bank-paid expenses never reach staff. */
    private function drawerLines(int $branchId, string $date, array $day, bool $isAdmin): array
    {
        $lines = [];
        if ($day['opening']) {
            $diff = (float) $day['opening']->difference;
            $lines[] = [
                'time' => $day['opening']->opened_at->format('g:i A'),
                'what' => 'Opening float (counted)',
                'note' => $diff == 0 ? 'Matches last Z-Reading' : ($diff > 0 ? 'OVER +' : 'SHORT −').ShopCash::money(abs($diff)).' · '.$day['opening']->reason,
                'by' => $day['opening']->opener?->name,
                'amount' => (float) $day['opening']->counted_amount,
                'system' => true,
                'tone' => null,
            ];
        }
        $lines[] = ['time' => 'All day', 'what' => 'Customer cash payments', 'note' => 'Added automatically from POS payments', 'by' => 'System', 'amount' => $day['cash_payments'], 'system' => true, 'tone' => null];

        $movements = MoneyMovement::query()->with('recorder:id,name')
            ->where('branch_id', $branchId)->whereDate('movement_date', $date)->get()
            ->map(fn (MoneyMovement $movement) => [
                'at' => $movement->created_at,
                'what' => $movement->type_label,
                'note' => $movement->voided_at
                    ? 'VOIDED: '.$movement->void_reason
                    : collect([
                        $movement->type === 'bank_deposit' ? 'Bag '.ShopCash::money($movement->bag_amount).' + extra '.ShopCash::money($movement->drawer_amount) : null,
                        $movement->person_name,
                        $movement->reference_no,
                        $movement->description,
                    ])->filter()->implode(' · '),
                'by' => $movement->recorder?->name ?? 'System',
                'amount' => $movement->drawerEffect(),
                'system' => false,
                'tone' => $movement->voided_at ? 'void' : null,
                'movement' => $movement,
            ]);

        $expenses = BranchExpense::query()->with('creator:id,name')
            ->where('branch_id', $branchId)->whereDate('expense_date', $date)
            ->when(! $isAdmin, fn ($query) => $query->where('bank_amount', 0))
            ->get()
            ->map(fn (BranchExpense $expense) => [
                'at' => $expense->created_at,
                'what' => \App\Support\StatusBadge::label($expense->category).' · '.$expense->title,
                'note' => match ($expense->drawer_status) {
                    'pending' => 'From Expenses · waiting for confirmation',
                    'disputed' => 'Disputed: not from this drawer'.($expense->dispute_note ? ' · '.$expense->dispute_note : ''),
                    'none' => 'From Expenses · not drawer',
                    default => 'From Expenses · drawer cash',
                },
                'by' => $expense->creator?->name,
                'amount' => $expense->drawerEffect(),
                'system' => true,
                'tone' => $expense->drawer_status === 'disputed' ? 'disputed' : null,
            ]);

        $running = 0.0;
        $timed = $movements->concat($expenses)->sortBy('at')->map(function ($line) {
            $line['time'] = $line['at']?->format('g:i A');
            unset($line['at']);

            return $line;
        });

        return collect($lines)->concat($timed)->map(function ($line) use (&$running) {
            $running = round($running + $line['amount'], 2);

            return $line + ['after' => $running];
        })->values()->all();
    }
}
