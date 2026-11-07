@extends('layouts.app')

@section('page_title', 'Shop Cash')

@section('content')
@php
    $money = fn ($value) => \App\Support\ShopCash::money($value);
    $opening = $day['opening'];
    $isAdmin = auth()->user()->isAdmin();
    $canAct = $opening && ! $locked;
    $dayQuery = ['branch_id' => $branch->id, 'movement_date' => $businessDate];
    $card = 'rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900';
    $input = 'mt-1 h-11 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950';
    $bagLabel = [
        'none' => ['Nothing to deposit', 'text-muted'],
        'not_deposited' => ['Not deposited yet', 'text-amber-700'],
        'full' => ['Deposited in full', 'text-emerald-700'],
        'short' => ['SHORT '.$money($day['bag_difference']).' vs the bag · owner alerted', 'text-red-600'],
        'over' => ['Deposited '.$money($day['bag_difference']).' more than the bag', 'text-amber-700'],
    ][$day['bag_status']];
    $zLines = [
        ['Float left at last Z-Reading', $day['expected_float'] === null ? '—' : $money($day['expected_float']), 'text-muted'],
        ['Opening difference', $day['opening_difference'] == 0 ? 'Matches '.$money(0) : ($day['opening_difference'] > 0 ? 'OVER +' : 'SHORT −').$money(abs($day['opening_difference'])), 'text-muted'],
        ['Float counted at opening', $money($day['opening_float']), 'font-semibold'],
        ['+ Cash payments (POS)', $money($day['cash_payments']), ''],
        ['+ Owner added cash', $money($day['owner_added']), ''],
        ['− Expenses paid with drawer cash (from Expenses)', $money($day['drawer_expenses']), ''],
        ['− Extra deposited from today\'s drawer', $money($day['extra_deposited']), ''],
        ['− Owner took cash', $money($day['owner_took']), ''],
    ];
@endphp

<div class="space-y-4">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $branch->name }} · {{ \Illuminate\Support\Carbon::parse($businessDate)->format('D, M d, Y') }}</p>
            <h1 class="text-2xl font-semibold tracking-normal">Shop Cash</h1>
            <p class="max-w-2xl text-sm text-muted">Every time cash goes IN or OUT of the drawer (except giving change to customers), record it here right away. The Z-Reading uses these numbers tonight.</p>
        </div>
        <form method="GET" action="{{ route('admin.petty-cash.index') }}" class="flex flex-wrap gap-2">
            @if($canChooseBranch)
                <select name="branch_id" aria-label="Branch" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    @foreach($branches as $optionBranch)
                        <option value="{{ $optionBranch->id }}" @selected((int) $branch->id === (int) $optionBranch->id)>{{ $optionBranch->name }}</option>
                    @endforeach
                </select>
            @endif
            <input name="movement_date" value="{{ $businessDate }}" type="date" aria-label="Business date" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90"><span data-lucide="search" class="h-4 w-4"></span>Go</button>
        </form>
    </div>

    @if($errors->any() && ! old('_expense_form'))
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if($day['z_reading'])
        <div class="rounded-md border border-border bg-smoke px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950">
            Tonight's Z-Reading ({{ $day['z_reading']->reading_number }}) is saved, so this day is read-only{{ $isAdmin ? '. As admin you can still record a late change; then re-save the Z-Reading.' : '. Ask the admin to record late changes.' }}
        </div>
    @elseif(! $opening && $day['cash_payments'] > 0)
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-800">The shop is selling but has not been opened. Count the float now — no movement or Z-Reading can be saved until you do.</div>
    @endif

    {{-- Step 1: opening count --}}
    <div class="{{ $card }} {{ $opening ? '' : 'border-primary' }}">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-base font-semibold">Step 1 · Open the shop: count the float</h2>
                <p class="text-sm text-muted">
                    Before the first customer, count all the cash in the drawer.
                    @if($day['expected_float'] !== null) Last night's Z-Reading left {{ $money($day['expected_float']) }}. @endif
                </p>
            </div>
            @if($opening)
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $day['opening_difference'] == 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Opened · {{ $day['opening_difference'] == 0 ? 'float matches' : 'difference '.$money($day['opening_difference']) }}</span>
            @else
                <span class="rounded-full bg-smoke px-3 py-1 text-xs font-semibold text-muted dark:bg-gray-950">Not opened yet</span>
            @endif
        </div>

        @if($opening)
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                <p>Opened at {{ $opening->opened_at->format('g:i A') }} by {{ $opening->opener?->name }} with {{ $money($opening->counted_amount) }} in the drawer.{{ $opening->reason ? ' Reason: '.$opening->reason : '' }}</p>
                @if($isAdmin && ! $day['z_reading'])
                    <form method="POST" action="{{ route('admin.petty-cash.reopen', $opening) }}" onsubmit="return confirm('Remove this opening so the float can be counted again?')">
                        @csrf @method('DELETE')
                        <button class="h-9 rounded-md border border-border px-3 text-xs font-medium hover:bg-smoke dark:border-gray-700">Reopen (admin)</button>
                    </form>
                @endif
            </div>
        @elseif($day['expected_float'] === null && ! $isAdmin)
            <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">There is no Z-Reading float yet. Ask the admin to open the first day; after that everything carries forward.</p>
        @elseif(! $locked)
            <form method="POST" action="{{ route('admin.petty-cash.open') }}"
                x-data="{
                    expected: @js($day['expected_float']),
                    startingFloat: @js(old('starting_float', '')),
                    counts: @js(collect($denominations)->mapWithKeys(fn ($value) => [$value => (int) old('denominations.'.$value, 0)])->all()),
                    reason: @js(old('reason', '')),
                    get counted() { return Object.entries(this.counts).reduce((total, [value, qty]) => total + Number(value) * Number(qty || 0), 0); },
                    get expectedFloat() { return this.expected === null ? Number(this.startingFloat || 0) : Number(this.expected); },
                    get difference() { return Math.round((this.counted - this.expectedFloat) * 100) / 100; },
                    money(value) { return '₱' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
                }"
                class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                <input type="hidden" name="movement_date" value="{{ $businessDate }}">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach($denominations as $value)
                        <label class="grid grid-cols-[4rem_auto_1fr_auto] items-center gap-2 rounded-md bg-smoke p-2 dark:bg-gray-950">
                            <span class="text-sm font-semibold">₱{{ number_format((int) $value) }}</span>
                            <span class="text-xs text-muted">×</span>
                            <input type="number" name="denominations[{{ $value }}]" x-model.number="counts['{{ $value }}']" min="0" step="1" inputmode="numeric" aria-label="Number of ₱{{ $value }}" class="h-11 w-full rounded-md border border-border bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <span class="w-20 text-right text-xs text-muted" x-text="money(Number('{{ $value }}') * Number(counts['{{ $value }}'] || 0))"></span>
                        </label>
                    @endforeach
                </div>
                <div class="space-y-3">
                    @if($day['expected_float'] === null)
                        <div class="grid grid-cols-2 gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <p class="col-span-2">First day: enter the starting float and the deposit bag once. After this, the Z-Reading carries them forward.</p>
                            <label>Starting float<input type="number" name="starting_float" x-model="startingFloat" min="0" step="0.01" required class="{{ $input }}"></label>
                            <label>Deposit bag<input type="number" name="starting_bag" value="{{ old('starting_bag') }}" min="0" step="0.01" class="{{ $input }}"></label>
                        </div>
                    @endif
                    <div class="overflow-hidden rounded-md border border-border text-sm dark:border-gray-800">
                        <div class="flex justify-between px-3 py-2"><span>Float left at last Z-Reading</span><span x-text="money(expectedFloat)"></span></div>
                        <div class="flex justify-between border-t border-border px-3 py-2 font-semibold dark:border-gray-800"><span>You counted now</span><span x-text="money(counted)"></span></div>
                        <div class="flex justify-between border-t border-border px-3 py-2 font-semibold dark:border-gray-800" :class="difference === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
                            <span>Difference</span>
                            <span x-text="difference === 0 ? 'Matches ' + money(0) : (difference > 0 ? 'OVER +' : 'SHORT −') + money(Math.abs(difference))"></span>
                        </div>
                    </div>
                    <label x-show="difference !== 0" class="block text-sm font-medium">Why is it different? (required — the owner is notified)
                        <textarea name="reason" x-model="reason" rows="2" class="mt-1 w-full rounded-md border border-border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                    </label>
                    <button type="submit" :disabled="difference !== 0 && !reason.trim()"
                        class="h-11 w-full rounded-md text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                        :class="difference === 0 ? 'bg-primary' : 'bg-red-600'"
                        x-text="difference === 0 ? 'Confirm & open the shop' : 'Confirm with difference'">Confirm &amp; open the shop</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Everything below stays locked until the opening is confirmed. --}}
    <div class="space-y-4 {{ $opening ? '' : 'pointer-events-none select-none opacity-50' }}" @if(! $opening) aria-disabled="true" @endif>
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <div class="rounded-lg bg-[#2A211B] p-5 text-white shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-white/70">Cash that should be in the drawer now</p>
                <p class="mt-1 text-4xl font-bold">{{ $money($day['should_be']) }}</p>
                <p class="mt-1 text-xs text-white/70">
                    Float {{ $money($day['opening_float']) }} + cash sales {{ $money($day['cash_payments']) }}
                    @if($day['owner_added'] > 0) + owner added {{ $money($day['owner_added']) }} @endif
                    @if($day['drawer_expenses'] > 0) − expenses {{ $money($day['drawer_expenses']) }} @endif
                    @if($day['extra_deposited'] > 0) − deposited {{ $money($day['extra_deposited']) }} @endif
                    @if($day['owner_took'] > 0) − owner took {{ $money($day['owner_took']) }} @endif
                </p>
            </div>
            <div class="{{ $card }}">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">Bank deposit to do today</p>
                <p class="mt-1 text-2xl font-bold">{{ $money($day['bag_left']) }}</p>
                <p class="text-sm font-medium {{ $bagLabel[1] }}">From last night's Z-Reading bag {{ $money($day['bag_due']) }} · {{ $bagLabel[0] }}</p>
            </div>
        </div>

        {{-- Step 2: the four actions --}}
        @php
            $old = old('type');
            $actions = [
                'expense' => ['EXPENSE', 'Record an expense', 'Opens the Expenses form: supplies, payroll, fare, bills…', 'border-t-red-700'],
                'bank_deposit' => ['CASH OUT', 'Bank deposit', 'Sealed bag from last Z-Reading (+ any extra)', 'border-t-red-700'],
                'owner_out' => ['CASH OUT', 'Owner took cash', 'Cash handed to the owner / manager', 'border-t-red-700'],
                'owner_in' => ['CASH IN', 'Owner added cash', 'Extra cash given to the shop (e.g. for change)', 'border-t-emerald-700'],
            ];
        @endphp
        <div x-data="{
                action: @js($old),
                bag: @js(old('bag_amount', $day['bag_left'] ?: '')),
                extra: @js(old('drawer_amount', '')),
                amount: @js(old('amount', '')),
                drawerNow: @js($day['should_be']),
                bagLeft: @js($day['bag_left']),
                money(value) { return '₱' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                get fromDrawer() { return this.action === 'bank_deposit' ? Number(this.extra || 0) : (this.action === 'owner_out' ? Number(this.amount || 0) : 0); },
                get drawerAfter() { return this.drawerNow - this.fromDrawer + (this.action === 'owner_in' ? Number(this.amount || 0) : 0); },
                get tooMuch() { return this.fromDrawer > this.drawerNow; }
            }" class="{{ $card }}">
            <h2 class="text-base font-semibold">Step 2 · During the day: what happened?</h2>
            <p class="text-sm text-muted">Tap one. Customer payments are already counted from the POS — never record them here.</p>
            <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                @foreach($actions as $key => [$kicker, $title, $hint, $accent])
                    <button type="button" @if(! $canAct) disabled @endif
                        @click="{{ $key === 'expense' ? "\$dispatch('expense-form'); action = null" : "action = action === '{$key}' ? null : '{$key}'" }}"
                        :class="action === '{{ $key }}' ? 'bg-smoke ring-1 ring-primary dark:bg-gray-950' : ''"
                        class="min-h-11 rounded-md border border-border border-t-4 {{ $accent }} p-3 text-left hover:bg-smoke disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:hover:bg-gray-950">
                        <span class="block text-[11px] font-semibold text-muted">{{ $kicker }}</span>
                        <span class="block text-sm font-semibold">{{ $title }}</span>
                        <span class="block text-xs text-muted">{{ $hint }}</span>
                    </button>
                @endforeach
            </div>

            @if($canAct)
                <form x-cloak x-show="action && action !== 'expense'" method="POST" action="{{ route('admin.petty-cash.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3 rounded-md border border-primary/40 p-4">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                    <input type="hidden" name="movement_date" value="{{ $businessDate }}">
                    <input type="hidden" name="type" :value="action">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold" x-text="{ bank_deposit: 'Cash OUT · Bank deposit', owner_out: 'Cash OUT · Owner took cash', owner_in: 'Cash IN · Owner added cash' }[action]"></h3>
                        <button type="button" @click="action = null" class="h-9 rounded-md border border-border px-3 text-sm dark:border-gray-700">Cancel</button>
                    </div>

                    <template x-if="action === 'bank_deposit'">
                        <div class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-[1fr_2fr]">
                                <label class="text-sm font-medium">Deposit slip reference #<input name="reference_no" value="{{ old('reference_no') }}" required maxlength="255" class="{{ $input }}"></label>
                                <label class="text-sm font-medium">Short note<input name="description" value="{{ old('description') }}" maxlength="255" :required="Number(bag || 0) < bagLeft" class="{{ $input }}"></label>
                            </div>
                            <div class="rounded-md bg-smoke p-3 text-sm dark:bg-gray-950">
                                <p class="text-xs text-muted">The deposit bag from last night's Z-Reading holds {{ $money($day['bag_left']) }}. It is NOT in the drawer, so depositing it does not change the drawer.</p>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                    <label class="font-medium">From last night's deposit bag (₱)<input type="number" name="bag_amount" x-model="bag" min="0" step="0.01" inputmode="decimal" class="{{ $input }}"></label>
                                    <label class="font-medium">Extra cash taken from today's drawer (₱)<input type="number" name="drawer_amount" x-model="extra" min="0" step="0.01" inputmode="decimal" class="{{ $input }}"></label>
                                </div>
                                <p class="mt-2 font-semibold">Total on the deposit slip: <span x-text="money(Number(bag || 0) + Number(extra || 0))"></span></p>
                                <p x-show="Number(bag || 0) < bagLeft" class="mt-1 font-medium text-red-700">Bag amount is <span x-text="money(bagLeft - Number(bag || 0))"></span> LESS than what the Z-Reading put in the bag. Explain in the note — the owner will be alerted.</p>
                                <p x-show="Number(bag || 0) > bagLeft" class="mt-1 font-medium text-amber-700">That is more than the bag. Put extra drawer cash under "Extra".</p>
                            </div>
                            <label class="block text-sm font-medium">Photo of deposit slip (required)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/*" required class="mt-1 block w-full text-sm"></label>
                        </div>
                    </template>

                    <template x-if="action === 'owner_out' || action === 'owner_in'">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="text-sm font-medium">Amount (₱)<input type="number" name="amount" x-model="amount" min="0.01" step="0.01" inputmode="decimal" required class="{{ $input }}"></label>
                            <label class="text-sm font-medium"><span x-text="action === 'owner_out' ? 'Who took it' : 'Who gave it'"></span><input name="person_name" value="{{ old('person_name') }}" required maxlength="255" class="{{ $input }}"></label>
                            <label class="text-sm font-medium">Note<input name="description" value="{{ old('description') }}" maxlength="255" class="{{ $input }}"></label>
                        </div>
                    </template>

                    <p x-show="tooMuch" class="text-sm font-medium text-red-700">That is more than the cash in the drawer (<span x-text="money(drawerNow)"></span>). Check the amount.</p>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm text-emerald-700">Drawer after saving: <span class="font-semibold" x-text="money(drawerAfter)"></span></p>
                        <button type="submit" :disabled="tooMuch" class="h-11 rounded-md bg-primary px-6 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">Save</button>
                    </div>
                </form>
            @endif
        </div>

        {{-- Expenses waiting for confirmation --}}
        @if($day['pending']->isNotEmpty())
            <div class="rounded-lg border border-amber-300 bg-white p-4 shadow-sm dark:bg-gray-900">
                <h2 class="text-base font-semibold">Expenses waiting for your confirmation</h2>
                <p class="text-sm text-muted">Someone recorded these on the Expenses page as paid with drawer cash. Confirm only if the cash really left this drawer. The Z-Reading can't be saved until every line is answered.</p>
                <span class="mt-2 inline-block rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">{{ $day['pending']->count() }} to confirm</span>
                <div class="mt-3 space-y-2">
                    @foreach($day['pending'] as $expense)
                        <div class="flex flex-col gap-2 rounded-md border border-border p-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                            <div>
                                <p class="font-semibold">{{ \App\Support\StatusBadge::label($expense->category) }} · {{ $money($expense->drawer_amount) }}</p>
                                <p class="text-xs text-muted">{{ $expense->title }} · entered by {{ $expense->creator?->name ?? 'System' }} on the Expenses page · Drawer cash{{ $expense->remarks ? ' · '.$expense->remarks : '' }}{{ $expense->receipt_path ? ' · receipt ✓' : '' }}</p>
                            </div>
                            @if($canAct)
                                <div class="flex flex-wrap gap-2" x-data="{ disputing: false }">
                                    <form method="POST" action="{{ route('admin.petty-cash.expenses.confirm', $expense) }}">
                                        @csrf
                                        <button class="h-11 rounded-md bg-emerald-700 px-4 text-sm font-semibold text-white">Yes, cash left the drawer</button>
                                    </form>
                                    <button type="button" x-show="!disputing" @click="disputing = true" class="h-11 rounded-md border border-red-300 px-4 text-sm font-semibold text-red-700">Not from this drawer</button>
                                    <form x-cloak x-show="disputing" method="POST" action="{{ route('admin.petty-cash.expenses.dispute', $expense) }}" class="flex gap-2">
                                        @csrf
                                        <input name="dispute_note" maxlength="255" placeholder="Note (optional)" class="h-11 rounded-md border border-border px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                                        <button class="h-11 rounded-md bg-red-600 px-4 text-sm font-semibold text-white">Confirm: not from this drawer</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Today's drawer --}}
        <div class="{{ $card }}">
            <h2 class="text-base font-semibold">Today's drawer</h2>
            <p class="text-sm text-muted">Grey lines are filled in by the system. You can't change them here.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                        <tr>
                            <th class="px-3 py-2">Time</th>
                            <th class="px-3 py-2">What</th>
                            <th class="px-3 py-2">By</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2 text-right">Drawer after</th>
                            @if($isAdmin)<th class="px-3 py-2"><span class="sr-only">Actions</span></th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border dark:divide-gray-800">
                        @foreach($lines as $line)
                            <tr class="{{ $line['system'] ? 'bg-smoke/60 dark:bg-gray-950/60' : '' }} {{ $line['tone'] === 'disputed' ? 'text-red-700' : '' }} {{ $line['tone'] === 'void' ? 'text-muted line-through' : '' }}">
                                <td class="whitespace-nowrap px-3 py-2 text-muted">{{ $line['time'] }}</td>
                                <td class="px-3 py-2">
                                    <p class="font-medium">{{ $line['what'] }}</p>
                                    @if($line['note'])<p class="text-xs text-muted">{{ $line['note'] }}</p>@endif
                                    @if(! empty($line['movement']?->photo_path))
                                        <a href="{{ route('admin.petty-cash.photo', $line['movement']) }}" target="_blank" rel="noopener" class="text-xs font-medium text-primary hover:underline">View slip photo</a>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $line['by'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold {{ $line['amount'] > 0 ? 'text-emerald-700' : ($line['amount'] < 0 ? 'text-red-600' : '') }}">{{ $line['amount'] > 0 ? '+' : ($line['amount'] < 0 ? '−' : '') }}{{ $money(abs($line['amount'])) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold">{{ $money($line['after']) }}</td>
                                @if($isAdmin)
                                    <td class="px-3 py-2 text-right">
                                        @if(! empty($line['movement']) && ! $line['movement']->voided_at)
                                            <form method="POST" action="{{ route('admin.petty-cash.destroy', $line['movement']) }}" x-data @submit="const reason = prompt('Why void this line?'); if (!reason) { $event.preventDefault(); return; } $el.querySelector('[name=void_reason]').value = reason">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="void_reason">
                                                <button class="h-8 rounded-md border border-border px-2 text-xs dark:border-gray-700">Void</button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Sent to tonight's Z-Reading --}}
        <div class="rounded-lg border border-primary bg-white p-4 shadow-sm dark:bg-gray-900">
            <h2 class="text-base font-semibold">Sent to tonight's Z-Reading</h2>
            <p class="text-sm text-muted">Step 3 (Cash drawer) uses these totals. Staff only count the cash.</p>
            <div class="mt-3 divide-y divide-border overflow-hidden rounded-md border border-border text-sm dark:divide-gray-800 dark:border-gray-800">
                @foreach($zLines as [$label, $value, $class])
                    <div class="flex justify-between px-3 py-2 {{ $class }}"><span>{{ $label }}</span><span>{{ $value }}</span></div>
                @endforeach
                <div class="flex justify-between bg-smoke px-3 py-2 font-bold dark:bg-gray-950"><span>= Should be in drawer</span><span>{{ $money($day['should_be']) }}</span></div>
                <div class="flex justify-between px-3 py-2 text-xs text-muted"><span>Other expenses today (GCash / owner) — not in drawer</span><span>{{ $money($day['other_expenses']) }}</span></div>
                <div class="flex justify-between px-3 py-2 font-medium {{ $day['pending']->isNotEmpty() ? 'bg-amber-50 text-amber-800' : '' }}">
                    <span>Expenses waiting for confirmation</span>
                    <span>{{ $day['pending']->count() }}{{ $day['pending']->isNotEmpty() ? ' — Z-Reading blocked' : '' }}</span>
                </div>
            </div>
            <p class="mt-2 text-xs text-muted">After the Z-Reading is saved, the float left in the drawer becomes tomorrow's first line here automatically. Nobody types it.</p>
        </div>
    </div>

    @if($canAct)
        @include('admin.expenses._form', ['drawerNow' => $day['should_be'], 'formBranchId' => $branch->id, 'formDate' => $businessDate])
    @endif
</div>
@endsection
