@extends('layouts.app')

@section('page_title', 'Z-Reading')

@section('content')
@php
    $shopCash = $summary['shop_cash'];
    $sales = $closing['sales'];
    $peso = fn ($value) => \App\Support\ShopCash::money($value);
    $readOnly = $reading && ! $isAdmin;
    $editing = $reading && $isAdmin;
    $gates = array_values(array_filter([
        $shopCash['opening'] ? null : 'The Shop Cash opening count for this date is not confirmed yet.',
        $shopCash['pending']->isNotEmpty() ? $shopCash['pending']->count().' drawer expense(s) still wait for confirmation in Shop Cash.' : null,
        $closing['first'] && ! $isAdmin ? 'This is the first Z-Reading of this branch with the new screen. An admin must do it once to set the GCash starting balance.' : null,
        $closing['missed_date'] && ! $isAdmin ? 'No Z-Reading was saved for '.\Illuminate\Support\Carbon::parse($closing['missed_date'])->format('M d, Y').'. Close that day first, or ask an admin.' : null,
    ]));
    $cashCount = old('cash_count', $reading?->cash_count ?? []);
    $state = [
        'counts' => collect($denominations)->mapWithKeys(fn ($label, $value) => [$value => ($cashCount[$value] ?? '') === '' ? '' : (int) $cashCount[$value]])->all(),
        'shouldBe' => $closing['should_be'],
        'first' => $closing['first'],
        'gcashOpening' => $closing['first'] ? old('gcash_opening', $reading?->gcash_opening ?? '') : $closing['gcash_opening'],
        'gcashPayments' => $closing['gcash_payments'],
        'gcashExpenses' => $closing['gcash_expenses'],
        'gcashFunding' => $closing['gcash_funding'],
        'gcashRepayments' => $closing['gcash_repayments'],
        'actualGcash' => old('actual_gcash_amount', $reading?->actual_gcash_amount ?? ''),
        'transfer' => (bool) old('gcash_transfer', (float) $reading?->gcash_transfer_amount > 0),
        'transferAmount' => old('gcash_transfer_amount', $reading?->gcash_transfer_amount ?? ''),
        'transferFee' => old('gcash_transfer_fee', $reading && (float) $reading->gcash_transfer_fee > 0 ? $reading->gcash_transfer_fee : ''),
        'transferRef' => old('gcash_transfer_ref', $reading?->gcash_transfer_ref ?? ''),
        'bagDue' => $closing['bag_due'],
        'carried' => (bool) old('deposit_carried_over', $reading?->deposit_carried_over ?? false),
        'slipAmount' => old('deposit_slip_amount', $reading?->deposit_slip_amount ?? ($closing['bag_deposited'] > 0 ? $closing['bag_deposited'] : '')),
        'slipRef' => old('deposit_slip_ref', $reading?->deposit_slip_ref ?? $closing['bag_refs']),
        'floatForTomorrow' => old('float_for_tomorrow', $reading?->float_for_tomorrow ?? ''),
        'remarks' => old('remarks', $reading?->remarks ?? ''),
        'editReason' => old('edit_reason', ''),
        'editing' => (bool) $editing,
        'missed' => (bool) $closing['missed_date'],
        'blocked' => $gates !== [] || $readOnly,
        'salesMatch' => $sales['matches'],
        'machines' => $machines,
    ];
    $chip = [
        'green' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
        'grey' => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300',
        'red' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    ];
    $input = 'h-11 w-full rounded-md border border-border bg-white px-3 text-base font-semibold dark:border-gray-800 dark:bg-gray-950';
@endphp

<script>
function zReading(state, chips) {
    return {
        ...state,
        money(value) {
            return '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        tone(name) { return chips[name]; },
        empty(value) { return value === '' || value === null || value === undefined; },
        balanced(diff) { return Math.abs(diff) < 0.005; },
        overShort(diff) {
            return (diff > 0 ? 'Over ' : 'Short ') + this.money(Math.abs(diff));
        },
        signed(n) { return n > 0 ? '+' + n : String(n); },
        field(row, name) { return `machine_counters[${row.machine}][${row.type}][${name}]`; },
        used(row) { return this.empty(row.begin) || this.empty(row.end) ? null : Number(row.end) - Number(row.begin); },
        diff(row) { const used = this.used(row); return used === null ? 0 : used - row.system; },
        machineState(row) {
            const used = this.used(row);
            if (used === null) return { tone: 'grey', text: 'Enter End' };
            if (used < 0) return { tone: 'red', text: 'End is lower than start' };
            const diff = used - row.system;
            if (diff === 0) return { tone: 'green', text: 'Matches' };
            if (diff < 0) return { tone: 'red', text: 'Counter lower than job orders' };
            const sum = Object.values(row.reasons).reduce((total, count) => total + Math.max(0, Number(count || 0)), 0);
            const notRecorded = Number(row.reasons.not_recorded || 0) > 0;
            const other = Number(row.reasons.other || 0) > 0;
            if (sum === diff && (! notRecorded || String(row.jo_ref).trim()) && (! other || String(row.notes).trim())) return { tone: 'amber', text: 'Explained' };
            return { tone: 'red', text: 'Off by ' + diff };
        },
        totalsLine() {
            return ['wash', 'dry'].map(type => {
                const rows = this.machines.filter(row => row.type === type);
                return type.charAt(0).toUpperCase() + type.slice(1) + ': counters ' + rows.reduce((t, row) => t + Math.max(0, this.used(row) || 0), 0) + ' · job orders ' + rows.reduce((t, row) => t + row.system, 0);
            }).join('   ');
        },
        actualCash() {
            return Object.entries(this.counts).reduce((total, [value, count]) => total + Number(value) * Number(count || 0), 0);
        },
        expectedGcash() {
            return Number(this.gcashOpening || 0) + this.gcashPayments + this.gcashFunding - this.gcashExpenses - this.gcashRepayments
                - (this.transfer ? Number(this.transferAmount || 0) + Number(this.transferFee || 0) : 0);
        },
        depositBag() {
            return this.actualCash() - Number(this.floatForTomorrow || 0) + (this.carried && this.bagDue > 0 ? this.bagDue : 0);
        },
        floatError() {
            if (this.empty(this.floatForTomorrow)) return '';
            if (Number(this.floatForTomorrow) < 0) return 'The float cannot be negative.';
            if (Number(this.floatForTomorrow) % 1 !== 0) return 'Leave a whole-peso float (no centavos).';
            if (Number(this.floatForTomorrow) > this.actualCash() + 0.004) return 'The float cannot be more than the cash counted (' + this.money(this.actualCash()) + ').';
            return '';
        },
        steps() {
            const states = this.machines.map(row => this.machineState(row));
            const toFix = states.filter(state => state.tone === 'red').length;
            const machines = this.machines.length === 0 ? { tone: 'green', text: 'No machines' }
                : toFix ? { tone: 'red', text: toFix + ' machine(s) to fix' }
                : states.some(state => state.tone === 'grey') ? { tone: 'grey', text: 'Enter counters' }
                : { tone: 'green', text: 'All machines OK' };

            const cashDiff = this.actualCash() - this.shouldBe;
            const counted = Object.values(this.counts).some(count => ! this.empty(count));
            const cash = ! counted ? { tone: 'grey', text: 'Enter cash count' }
                : this.balanced(cashDiff) ? { tone: 'green', text: 'Balanced' } : { tone: 'red', text: this.overShort(cashDiff) };

            const gcashDiff = Number(this.actualGcash || 0) - this.expectedGcash();
            const gcash = (this.first && this.empty(this.gcashOpening)) ? { tone: 'grey', text: 'Enter starting balance' }
                : this.empty(this.actualGcash) ? { tone: 'grey', text: 'Enter GCash balance' }
                : (this.transfer && (! Number(this.transferAmount) || ! String(this.transferRef).trim())) ? { tone: 'grey', text: 'Finish transfer details' }
                : this.balanced(gcashDiff) ? { tone: 'green', text: 'Balanced' } : { tone: 'red', text: this.overShort(gcashDiff) };

            const depositDiff = Number(this.slipAmount || 0) - this.bagDue;
            const deposit = this.bagDue <= 0 ? { tone: 'green', text: 'No bag to deposit' }
                : this.carried ? { tone: 'amber', text: 'Carried to tomorrow' }
                : this.empty(this.slipAmount) ? { tone: 'grey', text: 'Enter slip amount' }
                : ! String(this.slipRef).trim() ? { tone: 'grey', text: 'Add slip #' }
                : this.balanced(depositDiff) ? { tone: 'green', text: 'Deposited ' + this.money(this.slipAmount) } : { tone: 'red', text: 'Slip ' + (depositDiff > 0 ? 'over ' : 'short ') + this.money(Math.abs(depositDiff)) };

            return {
                sales: this.salesMatch ? { tone: 'green', text: 'Matches' } : { tone: 'red', text: 'Does not add up' },
                machines, cash, gcash, deposit,
                cashDiff, gcashDiff, depositDiff: this.bagDue > 0 && ! this.carried ? depositDiff : 0,
            };
        },
        stepList() {
            const steps = this.steps();
            return [
                { name: 'Sales', ...steps.sales },
                { name: 'Machines', ...steps.machines },
                { name: 'Cash drawer', ...steps.cash },
                { name: 'GCash', ...steps.gcash },
                { name: 'Bank deposit', ...steps.deposit },
            ];
        },
        off() {
            const steps = this.steps();
            return ! this.balanced(steps.cashDiff) || ! this.balanced(steps.gcashDiff) || ! this.balanced(steps.depositDiff);
        },
        remarksRequired() { return this.off() || this.missed; },
        stillNeeded() {
            const steps = this.steps();
            const needed = [];
            if (['red', 'grey'].includes(steps.machines.tone)) needed.push('machines');
            if (! Object.values(this.counts).some(count => ! this.empty(count))) needed.push('cash count');
            if (this.first && this.empty(this.gcashOpening)) needed.push('GCash starting balance');
            if (this.empty(this.actualGcash)) needed.push('GCash balance');
            if (steps.gcash.text === 'Finish transfer details') needed.push('GCash transfer details');
            if (steps.deposit.tone === 'grey') needed.push('deposit slip');
            if (this.empty(this.floatForTomorrow) || this.floatError()) needed.push("tomorrow's float");
            if (this.off() && ! String(this.remarks).trim()) needed.push('an explanation for the over/short');
            if (this.missed && ! String(this.remarks).trim()) needed.push('a remark for the missed day');
            if (this.editing && ! String(this.editReason).trim()) needed.push('a reason for the change');
            return needed;
        },
    };
}
</script>

<div x-data="zReading(@js($state), @js($chip))" class="space-y-4">
    {{-- Header + steps bar --}}
    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">End-of-day closing · {{ $branch->name }}</p>
                <h1 class="text-xl font-semibold">Z-Reading — {{ \Illuminate\Support\Carbon::parse($businessDate)->format('D, M d, Y') }}</h1>
                <p class="text-sm text-muted">Do the 5 steps in order. Each step turns green when it matches. If one turns red, the mistake is in that step.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.z-readings.index', ['branch_id' => $branch->id, 'business_date' => $businessDate]) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:bg-gray-950">
                    <span data-lucide="arrow-left" class="h-4 w-4"></span> Back
                </a>
                @if($reading)
                    <a href="{{ route('admin.z-readings.pdf', $reading) }}" target="_blank" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:bg-gray-950">
                        <span data-lucide="file-text" class="h-4 w-4"></span> PDF
                    </a>
                @endif
            </div>
        </div>

        <form method="GET" action="{{ route('admin.z-readings.create') }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
            @if($canChooseBranch)
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    @foreach($branches as $optionBranch)
                        <option value="{{ $optionBranch->id }}" @selected((int) $branch->id === (int) $optionBranch->id)>{{ $optionBranch->name }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            @endif
            <input name="business_date" value="{{ $businessDate }}" type="date" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <button type="submit" class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90" aria-label="Open date">
                <span data-lucide="search" class="h-4 w-4"></span>
            </button>
        </form>

        <nav class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5" aria-label="Steps">
            <template x-for="(step, index) in stepList()" :key="index">
                <a :href="'#step-' + (index + 1)" class="rounded-md border px-3 py-2" :class="tone(step.tone)">
                    <span class="block text-[11px] font-semibold uppercase" x-text="'Step ' + (index + 1)"></span>
                    <span class="block text-sm font-semibold" x-text="step.name"></span>
                    <span class="block text-xs" x-text="step.text"></span>
                </a>
            </template>
        </nav>
    </div>

    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <p class="font-semibold">The Z-Reading was not saved.</p>
            <ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    @if($gates !== [])
        <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <p class="font-semibold">This Z-Reading can't be saved yet.</p>
            @foreach($gates as $gate)<p>{{ $gate }}</p>@endforeach
            <a href="{{ route('admin.petty-cash.index', ['branch_id' => $branch->id, 'movement_date' => $businessDate]) }}" class="mt-1 inline-block font-semibold underline">Open Shop Cash</a>
        </div>
    @endif

    @if($readOnly)
        <div class="rounded-md border border-border bg-smoke px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950">
            Saved by {{ $reading->closer?->name ?? $reading->signature_name }} on {{ $reading->closed_at?->format('M d, Y h:i A') }}. Only an admin can change it.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.z-readings.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
        <input type="hidden" name="business_date" value="{{ $businessDate }}">

        <fieldset @disabled($readOnly) class="space-y-4">
        {{-- Step 1 --}}
        <section id="step-1" class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">Step 1 · Today's sales</h2>
                    <p class="text-xs text-muted">Filled in automatically from the POS. Nothing to type — just check the job-order count is right.</p>
                </div>
                <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="tone(steps().sales.tone)" x-text="steps().sales.text"></span>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                @foreach(array_filter([
                    'Job orders' => number_format($sales['job_orders']),
                    'Total sales' => $peso($sales['total']),
                    'Paid in cash' => $peso($sales['cash']),
                    'Paid by GCash' => $peso($sales['gcash']),
                    'Paid by bank transfer' => $sales['bank'] > 0 ? $peso($sales['bank']) : null,
                    'Charged to account (PO / monthly)' => $sales['account'] > 0 ? $peso($sales['account']) : null,
                    'Old orders paid today' => $peso($sales['old_orders_paid']),
                    'Still unpaid' => $peso($sales['unpaid']),
                ]) as $label => $value)
                    <div class="rounded-md bg-smoke p-2.5 dark:bg-gray-950">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 rounded-md bg-smoke px-3 py-2 text-xs text-muted dark:bg-gray-950">
                Check: Cash {{ $peso($sales['cash']) }} + GCash {{ $peso($sales['gcash']) }}{{ $sales['bank'] > 0 ? ' + Bank transfer '.$peso($sales['bank']) : '' }}{{ $sales['account'] > 0 ? ' + Account '.$peso($sales['account']) : '' }} + Unpaid {{ $peso($sales['unpaid']) }} = {{ $peso($sales['cash'] + $sales['gcash'] + $sales['bank'] + $sales['account'] + $sales['unpaid']) }} (total sales {{ $peso($sales['total']) }})
                @unless($sales['matches'])<span class="font-semibold text-red-700"> — this does not add up. It is a POS problem, not your mistake; it has been reported.</span>@endunless
            </p>
        </section>

        {{-- Step 2 --}}
        <section id="step-2" class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">Step 2 · Machine counters</h2>
                    <p class="text-xs text-muted">"End" fills in as Start + job order cycles. Check each machine and change End if its counter shows a different number.</p>
                </div>
                <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="tone(steps().machines.tone)" x-text="steps().machines.text"></span>
            </div>
            @if($machines === [])
                <p class="text-sm text-muted">This branch has no machines.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-smoke text-xs uppercase text-muted dark:bg-gray-950">
                            <tr><th class="px-2 py-2">Machine</th><th class="px-2 py-2">Type</th><th class="px-2 py-2">Start</th><th class="px-2 py-2">End</th><th class="px-2 py-2">Counter used</th><th class="px-2 py-2">Job orders</th><th class="px-2 py-2">Diff.</th><th class="px-2 py-2">Status</th></tr>
                        </thead>
                        <template x-for="row in machines" :key="row.label">
                            <tbody class="border-t border-border dark:border-gray-800">
                                <tr>
                                    <td class="px-2 py-2 font-semibold" x-text="row.label"></td>
                                    <td class="px-2 py-2 text-xs text-muted" x-text="row.model"></td>
                                    <td class="px-2 py-2">
                                        <template x-if="row.begin_editable">
                                            <input :name="field(row, 'beginning')" x-model.number="row.begin" type="number" min="0" step="1" inputmode="numeric" class="h-11 w-24 rounded-md border border-border bg-white px-2 text-right font-semibold dark:border-gray-800 dark:bg-gray-950" :aria-label="'Start ' + row.label">
                                        </template>
                                        <span x-show="! row.begin_editable" class="text-muted" x-text="row.begin"></span>
                                    </td>
                                    <td class="px-2 py-2"><input :name="field(row, 'ending')" x-model.number="row.end" type="number" min="0" step="1" inputmode="numeric" class="h-11 w-24 rounded-md border border-border bg-white px-2 text-right font-semibold dark:border-gray-800 dark:bg-gray-950" :aria-label="'End ' + row.label"></td>
                                    <td class="px-2 py-2" x-text="used(row) ?? '—'"></td>
                                    <td class="px-2 py-2" x-text="row.system"></td>
                                    <td class="px-2 py-2 font-semibold" x-text="used(row) === null ? '—' : signed(diff(row))"></td>
                                    <td class="px-2 py-2"><span class="inline-block rounded-full border px-3 py-1 text-xs font-semibold" :class="tone(machineState(row).tone)" x-text="machineState(row).text"></span></td>
                                </tr>
                                <tr x-show="diff(row) > 0" x-cloak>
                                    <td colspan="8" class="px-2 pb-3">
                                        <div class="rounded-md border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                                            <p class="text-xs font-semibold" x-text="'Why did the counter move ' + diff(row) + ' extra time(s)? Put each cycle under a reason.'"></p>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                                @foreach($nonJobCycleReasons as $reason => $reasonLabel)
                                                    <label class="text-xs text-muted">{{ $reasonLabel }}
                                                        <input :name="field(row, 'non_job_cycles') + '[{{ $reason }}]'" x-model.number="row.reasons['{{ $reason }}']" type="number" min="0" step="1" inputmode="numeric" class="mt-1 h-11 w-full rounded-md border border-border bg-white px-2 text-right font-semibold dark:border-gray-800 dark:bg-gray-900">
                                                    </label>
                                                @endforeach
                                            </div>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                <label x-show="Number(row.reasons.not_recorded) > 0" class="text-xs text-muted">Job order # (required)
                                                    <input :name="field(row, 'jo_ref')" x-model="row.jo_ref" maxlength="50" placeholder="JO-0412" class="mt-1 h-11 w-full rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-900">
                                                </label>
                                                <label class="text-xs text-muted"><span x-text="Number(row.reasons.other) > 0 ? 'Explain (required)' : 'Notes (optional)'"></span>
                                                    <input :name="field(row, 'notes')" x-model="row.notes" maxlength="500" class="mt-1 h-11 w-full rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-900">
                                                </label>
                                            </div>
                                            <p x-show="Number(row.reasons.not_recorded) > 0 && row.jo_ref" class="mt-2 text-xs text-muted" x-text="'The cycle will be added to job order ' + row.jo_ref + ' so the job-order cycles stay true.'"></p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                    </table>
                </div>
                <p class="mt-2 text-xs text-muted" x-text="totalsLine()"></p>
            @endif
        </section>

        {{-- Step 3 --}}
        <section id="step-3" class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">Step 3 · Cash drawer</h2>
                    <p class="text-xs text-muted">Count every bill and coin in the drawer, including the float. Type how many pieces of each.</p>
                </div>
                <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="tone(steps().cash.tone)" x-text="steps().cash.text"></span>
            </div>
            <div class="grid gap-4 lg:grid-cols-[3fr_2fr]">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach($denominations as $value => $label)
                        <label class="grid grid-cols-[4rem_1fr_6rem] items-center gap-2 rounded-md bg-smoke p-2 dark:bg-gray-950">
                            <span class="text-sm font-semibold">{{ $label }}</span>
                            <input name="cash_count[{{ $value }}]" x-model.number="counts['{{ $value }}']" type="number" min="0" step="1" inputmode="numeric" class="h-11 w-full rounded-md border border-border bg-white px-2 text-right font-semibold dark:border-gray-800 dark:bg-gray-900" aria-label="{{ $label }} pieces">
                            <span class="text-right text-sm" x-text="money(Number('{{ $value }}') * Number(counts['{{ $value }}'] || 0))"></span>
                        </label>
                    @endforeach
                </div>
                <div class="divide-y divide-border rounded-md border border-border text-sm dark:divide-gray-800 dark:border-gray-800">
                    @foreach(array_filter([
                        ['Float at opening (from Shop Cash)', $shopCash['opening_float'], true],
                        ['+ Cash payments today', $shopCash['cash_payments'], true],
                        ['+ Owner added cash', $shopCash['owner_added'], $shopCash['owner_added'] > 0],
                        ['− Expenses paid from drawer', $shopCash['drawer_expenses'], true],
                        ['− Deposited to bank during the day', $shopCash['extra_deposited'], true],
                        ['− Owner took cash', $shopCash['owner_took'], $shopCash['owner_took'] > 0],
                    ], fn ($line) => $line[2]) as [$label, $amount])
                        <div class="flex justify-between px-3 py-2"><span class="text-muted">{{ $label }}</span><span>{{ $peso($amount) }}</span></div>
                    @endforeach
                    <div class="flex justify-between bg-smoke px-3 py-2 font-semibold dark:bg-gray-950"><span>= Should be in drawer</span><span>{{ $peso($closing['should_be']) }}</span></div>
                    <div class="flex justify-between px-3 py-2 font-semibold"><span>You counted</span><span x-text="money(actualCash())"></span></div>
                    <div class="flex justify-between px-3 py-2 font-semibold" :class="tone(steps().cash.tone)"><span>Difference</span><span x-text="steps().cash.text + (steps().cash.tone === 'green' ? ' ' + money(0) : '')"></span></div>
                </div>
            </div>
        </section>

        {{-- Step 4 --}}
        <section id="step-4" class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">Step 4 · GCash wallet</h2>
                    <p class="text-xs text-muted">Open the GCash app and type the wallet balance you see. The wallet keeps growing until the owner transfers it — that is normal.</p>
                </div>
                <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="tone(steps().gcash.tone)" x-text="steps().gcash.text"></span>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="divide-y divide-border rounded-md border border-border text-sm dark:divide-gray-800 dark:border-gray-800">
                    <div class="flex justify-between px-3 py-2"><span class="text-muted">Balance at last Z-Reading</span>@if($closing['first'])<span x-text="money(gcashOpening)"></span>@else<span>{{ $peso($closing['gcash_opening']) }}</span>@endif</div>
                    <div class="flex justify-between px-3 py-2"><span class="text-muted">+ GCash payments today</span><span>{{ $peso($closing['gcash_payments']) }}</span></div>
                    @if($closing['gcash_funding'] > 0)<div class="flex justify-between px-3 py-2"><span class="text-muted">+ Owner added to GCash</span><span>{{ $peso($closing['gcash_funding']) }}</span></div>@endif
                    <div class="flex justify-between px-3 py-2"><span class="text-muted">− Paid with GCash (expenses)</span><span>{{ $peso($closing['gcash_expenses']) }}</span></div>
                    @if($closing['gcash_repayments'] > 0)<div class="flex justify-between px-3 py-2"><span class="text-muted">− Payables paid with GCash</span><span>{{ $peso($closing['gcash_repayments']) }}</span></div>@endif
                    <div class="flex justify-between px-3 py-2"><span class="text-muted">− Transferred to bank</span><span x-text="money(transfer ? transferAmount : 0)"></span></div>
                    <div class="flex justify-between px-3 py-2"><span class="text-muted">− GCash transfer fee</span><span x-text="money(transfer ? transferFee : 0)"></span></div>
                    <div class="flex justify-between bg-smoke px-3 py-2 font-semibold dark:bg-gray-950"><span>= Should be in GCash</span><span x-text="money(expectedGcash())"></span></div>
                    <div class="flex justify-between px-3 py-2 font-semibold" :class="tone(steps().gcash.tone)"><span>Difference</span><span x-text="steps().gcash.text"></span></div>
                </div>
                <div class="space-y-3">
                    @if($closing['first'])
                        <label class="block text-sm font-medium">GCash balance at the start (first Z-Reading, admin only)
                            <input name="gcash_opening" x-model="gcashOpening" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 {{ $input }}">
                        </label>
                    @endif
                    <label class="block text-sm font-medium">Balance shown in the GCash app now
                        <input name="actual_gcash_amount" x-model="actualGcash" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 {{ $input }}">
                    </label>
                    <p class="text-xs text-muted">Paid a supplier with GCash today? Record it in Expenses first. Sent GCash to the bank? Record it just below.</p>
                    <div class="rounded-md border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                        <label class="flex items-center gap-2 text-sm font-semibold">
                            <input type="hidden" name="gcash_transfer" value="0">
                            <input type="checkbox" name="gcash_transfer" value="1" x-model="transfer" class="h-5 w-5 rounded border-border">
                            GCash was transferred to the bank today (owner)
                        </label>
                        <div x-show="transfer" x-cloak class="mt-3 grid gap-2 sm:grid-cols-2">
                            <label class="text-xs text-muted">Amount sent to bank
                                <input name="gcash_transfer_amount" x-model="transferAmount" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 {{ $input }}">
                            </label>
                            <label class="text-xs text-muted">GCash fee charged
                                <input name="gcash_transfer_fee" x-model="transferFee" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0" class="mt-1 {{ $input }}">
                            </label>
                            <label class="text-xs text-muted sm:col-span-2">GCash reference #
                                <input name="gcash_transfer_ref" x-model="transferRef" maxlength="50" class="mt-1 {{ $input }}">
                            </label>
                            <p class="text-xs text-muted sm:col-span-2" x-text="'GCash goes down by ' + money(Number(transferAmount || 0) + Number(transferFee || 0)) + ' (transfer + GCash fee). The owner checks the bank side separately.'"></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Step 5 --}}
        <section id="step-5" class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">Step 5 · Bank deposit</h2>
                    <p class="text-xs text-muted">The deposit bag from the last closing must go to the bank. Type the amount printed on the deposit slip and the slip number, and keep the slip with the Z-Reading.</p>
                </div>
                <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="tone(steps().deposit.tone)" x-text="steps().deposit.text"></span>
            </div>
            @if($closing['bag_due'] <= 0)
                <p class="text-sm text-muted">There is no deposit bag from the last closing. Nothing to deposit today.</p>
            @else
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="divide-y divide-border rounded-md border border-border text-sm dark:divide-gray-800 dark:border-gray-800">
                        <div class="flex justify-between px-3 py-2"><span class="text-muted">Bag from {{ $closing['previous']?->business_date?->format('M d') ?? 'last' }} closing (sealed amount)</span><span>{{ $peso($closing['bag_due']) }}</span></div>
                        <div class="flex justify-between px-3 py-2"><span class="text-muted">Amount on deposit slip</span><span x-text="carried ? '—' : money(slipAmount)"></span></div>
                        <div class="flex justify-between px-3 py-2 font-semibold" :class="tone(steps().deposit.tone)"><span>Difference</span><span x-text="steps().deposit.text"></span></div>
                    </div>
                    <div class="space-y-3">
                        <label class="flex items-start gap-2 text-sm">
                            <input type="hidden" name="deposit_carried_over" value="0">
                            <input type="checkbox" name="deposit_carried_over" value="1" x-model="carried" class="mt-0.5 h-5 w-5 rounded border-border">
                            Not deposited today (bank closed / holiday) — bag stays in the safe
                        </label>
                        <div x-show="! carried" class="space-y-3">
                            <label class="block text-sm font-medium">Amount on deposit slip
                                <input name="deposit_slip_amount" x-model="slipAmount" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 {{ $input }}">
                            </label>
                            <label class="block text-sm font-medium">Slip / reference #
                                <input name="deposit_slip_ref" x-model="slipRef" maxlength="50" class="mt-1 {{ $input }}">
                            </label>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        {{-- Close the day --}}
        <section id="close" class="rounded-lg border-2 border-primary/60 bg-white p-3 shadow-sm dark:bg-gray-900">
            <h2 class="text-base font-semibold">Close the day</h2>
            <p class="text-xs text-muted">Decide how much cash stays in the drawer as tomorrow's float. The rest goes in the deposit bag.</p>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <div class="space-y-3">
                    <label class="block text-sm font-medium">Float left in drawer for tomorrow (to Shop Cash)
                        <input name="float_for_tomorrow" x-model="floatForTomorrow" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 {{ $input }}">
                    </label>
                    <p x-show="floatError()" x-cloak class="text-sm font-medium text-red-700" x-text="floatError()"></p>
                    <div class="rounded-md bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
                        <div class="flex justify-between font-semibold"><span>Put in the deposit bag</span><span x-text="money(depositBag())"></span></div>
                        <p class="text-xs text-muted" x-text="carried && bagDue > 0 ? 'All drawer cash above the float, plus the bag that was not deposited today (' + money(bagDue) + ').' : 'All drawer cash above the float.'"></p>
                        <p class="mt-1 text-xs text-muted">Write this amount and today's date on the bag, seal it, and lock it in the safe. Tomorrow the attendant deposits it and enters the slip in Step 5.</p>
                    </div>
                    <label class="block text-sm font-medium"><span x-text="remarksRequired() ? 'Explain the over/short (required)' : 'Remarks (optional)'"></span>
                        <textarea name="remarks" x-model="remarks" rows="3" maxlength="1000" class="mt-1 w-full rounded-md border border-border bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950"></textarea>
                    </label>
                    @if($editing)
                        <label class="block text-sm font-medium">Reason for changing this saved Z-Reading (required)
                            <textarea name="edit_reason" x-model="editReason" rows="2" maxlength="500" class="mt-1 w-full rounded-md border border-border bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950"></textarea>
                        </label>
                    @endif
                </div>
                <div class="space-y-2">
                    <template x-for="step in stepList()" :key="step.name">
                        <div class="flex justify-between rounded-md border px-3 py-2 text-sm font-semibold" :class="tone(step.tone)"><span x-text="step.name"></span><span x-text="step.text"></span></div>
                    </template>
                </div>
            </div>
            @unless($readOnly)
                <div class="mt-4 flex flex-col gap-2 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                    <p class="text-sm text-muted" x-text="stillNeeded().length ? 'Still needed: ' + stillNeeded().join(', ') + '.' : (off() ? 'Saving with an over/short. The owner will be notified.' : 'Everything matches. Ready to close.')"></p>
                    <button type="submit" :disabled="blocked || stillNeeded().length > 0" class="inline-flex h-11 items-center justify-center gap-2 rounded-md px-6 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50" :class="off() ? 'bg-red-700' : 'bg-primary'">
                        <span x-text="off() ? 'Save with over/short' : 'Save Z-Reading'"></span>
                    </button>
                </div>
            @endunless
        </section>
        </fieldset>
    </form>
</div>

@endsection
