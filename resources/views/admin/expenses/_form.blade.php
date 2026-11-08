{{--
    The one Record Expense form, used by the Expenses page and Shop Cash.
    Open it with $dispatch('expense-form') for a new expense, or $dispatch('expense-form', {...expense}) to edit.
    Needs: $branches, $canChooseBranch, $categories, $employees. Optional: $drawerNow (cash in the drawer now), $formBranchId, $formDate.
--}}
@php
    $isAdmin = auth()->user()->isAdmin();
    $drawerNow = $drawerNow ?? null;
    // Payroll runs Saturday to Friday and is paid on Saturday.
    $salaryPeriodStart = today()->previous(\Illuminate\Support\Carbon::SATURDAY)->toDateString();
    $salaryPeriodEnd = today()->previous(\Illuminate\Support\Carbon::SATURDAY)->addDays(6)->toDateString();
    $formBranchId = (string) ($formBranchId ?? request('branch_id', auth()->user()->branch_id ?: ($branches->first()?->id ?? '')));
    $formDate = $formDate ?? today()->toDateString();
    $sources = collect(\App\Models\BranchExpense::SOURCES)->when(! $isAdmin, fn ($sources) => $sources->except('bank'));
    $expenseDefaults = [
        'branch_id' => $formBranchId,
        'expense_date' => $formDate,
        'category' => 'supplies',
        'amount' => '',
        'paid_with' => 'drawer',
        'split' => ['drawer' => '', 'gcash' => '', 'bank' => ''],
        'attendance_employee_id' => '',
        'salary_period_start' => $salaryPeriodStart,
        'salary_period_end' => $salaryPeriodEnd,
        'title' => '',
        'reference_no' => '',
        'remarks' => '',
    ];
@endphp

<div
    x-data="{
        open: @js($errors->any() && old('_expense_form')),
        editId: @js(old('_edit_id') ? (int) old('_edit_id') : null),
        storeUrl: @js(route('admin.expenses.store')),
        updateUrl: @js(route('admin.expenses.update', '__ID__')),
        defaults: @js($expenseDefaults),
        isAdmin: @js($isAdmin),
        drawerNow: @js($drawerNow),
        originalDrawer: 0,
        expenseCategory: @js(old('category', 'supplies')),
        expenseBranchId: @js((string) old('branch_id', $formBranchId)),
        salaryEmployeeId: @js((string) old('attendance_employee_id', '')),
        expenseAmount: @js(old('amount', '')),
        paidWith: @js(old('paid_with', 'drawer')),
        split: @js(array_merge($expenseDefaults['split'], (array) old('split', []))),
        confirmDrawer: @js((bool) old('confirm_drawer')),
        expenseTitle: @js(old('title', '')),
        expenseDate: @js(old('expense_date', $formDate)),
        salaryPeriodStart: @js(old('salary_period_start', $salaryPeriodStart)),
        salaryPeriodEnd: @js(old('salary_period_end', $salaryPeriodEnd)),
        referenceNo: @js(old('reference_no', '')),
        remarks: @js(old('remarks', '')),
        receiptUrl: null,
        receiptPreview: null,
        receiptSizeKb: null,
        receiptBusy: false,
        removeReceipt: false,
        get isPayroll() { return this.expenseCategory === 'payroll'; },
        get drawerPart() {
            return this.isPayroll ? Number(this.split.drawer || 0) : (this.paidWith === 'drawer' ? Number(this.expenseAmount || 0) : 0);
        },
        get payrollTotal() {
            return Number(this.split.drawer || 0) + Number(this.split.gcash || 0) + (this.isAdmin ? Number(this.split.bank || 0) : 0);
        },
        get needsReceipt() {
            return this.isPayroll ? this.payrollTotal > 0 : this.paidWith !== 'owner';
        },
        get hasReceipt() { return !!this.receiptPreview || (!!this.receiptUrl && !this.removeReceipt); },
        get overDrawer() {
            return !this.editId && this.drawerNow !== null && this.drawerPart > Number(this.drawerNow);
        },
        get problem() {
            if (this.overDrawer) return `That is more than the cash in the drawer (${this.money(this.drawerNow)}). Check the amount.`;
            if (this.needsReceipt && !this.hasReceipt) return this.isPayroll ? 'Add a photo of the signed payslips or payroll sheet.' : 'Add a photo of the receipt. No receipt? Take a photo of the item and explain in the remarks.';
            if (this.drawerPart > 0 && !this.isAdmin && Number(this.drawerPart) !== this.originalDrawer && !this.confirmDrawer) return `Tick the box to confirm ${this.money(this.drawerPart)} was taken out of this drawer.`;
            return '';
        },
        money(value) {
            return '₱' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        resetReceipt(currentUrl = null) {
            this.receiptUrl = currentUrl;
            this.receiptPreview = null;
            this.receiptSizeKb = null;
            this.removeReceipt = false;
            if (this.$refs.receiptInput) this.$refs.receiptInput.value = '';
        },
        // Shrink the photo in the browser before upload (the server also
        // compresses it to under 200 KB, so this only speeds up uploading).
        async pickReceipt(event) {
            const input = event.target;
            const file = input.files[0];
            this.removeReceipt = false;
            this.receiptPreview = null;
            this.receiptSizeKb = null;
            if (!file) return;

            this.receiptBusy = true;
            try {
                const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                const scale = Math.min(1, 1400 / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(bitmap.width * scale);
                canvas.height = Math.round(bitmap.height * scale);
                const context = canvas.getContext('2d');
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

                let blob = null;
                for (const quality of [0.72, 0.6, 0.5, 0.4]) {
                    blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
                    if (blob && blob.size <= 200 * 1024) break;
                }

                if (blob && blob.size < file.size) {
                    const files = new DataTransfer();
                    files.items.add(new File([blob], 'receipt.jpg', { type: 'image/jpeg' }));
                    input.files = files.files;
                }
            } catch (error) {
                // This browser cannot shrink the photo; the server still will.
            }

            this.receiptSizeKb = Math.max(1, Math.round(input.files[0].size / 1024));
            this.receiptPreview = URL.createObjectURL(input.files[0]);
            this.receiptBusy = false;
        },
        fill(values) {
            this.expenseBranchId = String(values.branch_id ?? '');
            this.expenseDate = values.expense_date ?? '';
            this.expenseCategory = values.category ?? 'supplies';
            this.expenseAmount = values.amount ?? '';
            this.paidWith = values.paid_with || 'drawer';
            this.split = Object.assign({ drawer: '', gcash: '', bank: '' }, values.split || {});
            this.originalDrawer = Number(values.drawer_amount || 0);
            this.confirmDrawer = false;
            this.salaryEmployeeId = String(values.attendance_employee_id ?? '');
            this.salaryPeriodStart = values.salary_period_start || this.defaults.salary_period_start;
            this.salaryPeriodEnd = values.salary_period_end || this.defaults.salary_period_end;
            this.expenseTitle = values.title ?? '';
            this.referenceNo = values.reference_no ?? '';
            this.remarks = values.remarks ?? '';
            this.resetReceipt(values.receipt_url ?? null);
        },
        show(expense) {
            if (expense && expense.id) {
                if (expense.employee && !this.employees.some(item => item.id === expense.employee.id)) {
                    this.employees.push(expense.employee);
                }
                this.editId = expense.id;
                this.fill(expense);
            } else {
                this.editId = null;
                this.fill(this.defaults);
            }
            this.open = true;
        },
        get formAction() {
            return this.editId ? this.updateUrl.replace('__ID__', this.editId) : this.storeUrl;
        },
        employees: @js($employees->map(fn ($employee) => [
            'id' => (string) $employee->id,
            'branch_id' => (string) $employee->branch_id,
            'name' => $employee->name,
            'branch' => $employee->branch?->name,
            'monthly_salary' => $employee->configured_monthly_salary,
        ])->values()),
        get salaryEmployees() {
            return this.employees.filter(employee => !this.expenseBranchId || employee.branch_id === String(this.expenseBranchId));
        },
        salaryEmployeeChanged() {
            const employee = this.employees.find(item => item.id === String(this.salaryEmployeeId));
            if (!employee) return;
            // Suggest one week's pay (payroll is weekly).
            if (!this.payrollTotal) this.split.drawer = (Number(employee.monthly_salary || 0) * 12 / 52).toFixed(2);
            if (!this.expenseTitle) this.expenseTitle = `Salary - ${employee.name}`;
        }
    }"
    @expense-form.window="show($event.detail)"
>
    <div x-cloak x-show="open" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div @click.outside="open = false" class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="expense" class="h-4 w-4 text-primary"></span><span x-text="editId ? 'Edit Expense' : 'Record Expense'">Record Expense</span></h2>
                <button type="button" @click="open = false" aria-label="Close" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
            </div>
            <form method="POST" :action="formAction" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <input type="hidden" name="_expense_form" value="1">
                <template x-if="editId">
                    <div>
                        <input type="hidden" name="_method" value="PUT">
                        <input type="hidden" name="_edit_id" :value="editId">
                    </div>
                </template>
                @if($errors->any() && old('_expense_form'))
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900/60 dark:bg-red-500/10 dark:text-red-200">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @if($canChooseBranch)
                        <label class="text-sm font-medium">Branch
                            <select name="branch_id" x-model="expenseBranchId" @change="salaryEmployeeId = ''" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                    @endif
                    <label class="text-sm font-medium">Expense Date<input type="date" name="expense_date" x-model="expenseDate" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
                    <label class="text-sm font-medium">What did you buy / pay?
                        <select name="category" x-model="expenseCategory" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                            @foreach($categories as $category)
                                <option value="{{ $category }}">{{ \App\Support\StatusBadge::label($category) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <template x-if="!isPayroll">
                        <div class="grid grid-cols-1 gap-4 md:col-span-2 md:grid-cols-2">
                            <label class="text-sm font-medium">Paid with
                                <select name="paid_with" x-model="paidWith" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                                    @foreach($sources as $source => $label)
                                        <option value="{{ $source }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm font-medium">Amount<input type="number" min="0.01" step="0.01" inputmode="decimal" name="amount" x-model="expenseAmount" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
                        </div>
                    </template>

                    <template x-if="isPayroll">
                        <div class="grid grid-cols-1 gap-4 rounded-md bg-smoke p-3 md:col-span-2 md:grid-cols-3 dark:bg-gray-950">
                            <label class="text-sm font-medium">Employee
                                <select name="attendance_employee_id" x-model="salaryEmployeeId" @change="salaryEmployeeChanged()" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                                    <option value="">Select employee</option>
                                    <template x-for="employee in salaryEmployees" :key="employee.id">
                                        <option :value="employee.id" x-text="employee.name"></option>
                                    </template>
                                </select>
                            </label>
                            <label class="text-sm font-medium">Week start (Sat)<input type="date" name="salary_period_start" x-model="salaryPeriodStart" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                            <label class="text-sm font-medium">Week end (Fri)<input type="date" name="salary_period_end" x-model="salaryPeriodEnd" required class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                            <p class="text-xs text-muted md:col-span-3">How much from each source?</p>
                            <label class="text-sm font-medium">Cash from the drawer<input type="number" min="0" step="0.01" inputmode="decimal" name="split[drawer]" x-model="split.drawer" class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                            <label class="text-sm font-medium">Shop GCash<input type="number" min="0" step="0.01" inputmode="decimal" name="split[gcash]" x-model="split.gcash" class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                            @if($isAdmin)
                                <label class="text-sm font-medium">Shop bank<input type="number" min="0" step="0.01" inputmode="decimal" name="split[bank]" x-model="split.bank" class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                            @endif
                            <p class="text-sm font-semibold md:col-span-3">Total payroll: <span x-text="money(payrollTotal)"></span></p>
                            @unless($isAdmin)
                                <p class="text-xs text-muted md:col-span-3">Part of the pay sent from the shop bank? The owner records that part.</p>
                            @endunless
                        </div>
                    </template>

                    <label class="text-sm font-medium md:col-span-2">Short note<input name="title" x-model="expenseTitle" required :placeholder="isPayroll ? 'Weekly payroll, 4 staff' : 'Detergent stock, gas, utilities...'" class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
                    <label class="text-sm font-medium md:col-span-2">Reference<input name="reference_no" x-model="referenceNo" class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
                    <label class="text-sm font-medium md:col-span-2">Remarks<textarea name="remarks" x-model="remarks" rows="2" class="mt-1.5 w-full rounded-md border border-border bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea></label>

                    <div x-show="drawerPart > 0 && Number(drawerPart) !== originalDrawer" class="md:col-span-2">
                        @if($isAdmin)
                            <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The drawer part (<span x-text="money(drawerPart)"></span>) waits in Shop Cash until the attendant confirms the cash left the drawer.</p>
                        @else
                            <label class="flex min-h-11 items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">
                                <input type="checkbox" name="confirm_drawer" value="1" x-model="confirmDrawer" class="h-5 w-5 rounded border-border text-primary">
                                <span>I confirm <span x-text="money(drawerPart)"></span> was taken out of this drawer.</span>
                            </label>
                        @endif
                    </div>

                    <div class="md:col-span-2">
                        <p class="text-sm font-medium">Receipt / Bill Photo <span class="font-normal text-muted" x-text="needsReceipt ? '(required)' : '(optional)'"></span></p>
                        <div class="mt-1.5 flex flex-wrap items-start gap-3">
                            <label class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-border bg-white px-3 text-sm font-medium hover:bg-smoke dark:border-gray-700 dark:bg-gray-950 dark:hover:bg-gray-800">
                                <span data-lucide="receipt" class="h-4 w-4"></span>
                                <span x-text="hasReceipt ? 'Change photo' : 'Add photo'">Add photo</span>
                                <input x-ref="receiptInput" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,image/*" @change="pickReceipt($event)" class="sr-only">
                            </label>
                            <template x-if="receiptPreview">
                                <div class="flex items-center gap-2">
                                    <img :src="receiptPreview" alt="New receipt photo" class="h-16 w-16 rounded-md border border-border object-cover dark:border-gray-700">
                                    <span class="text-xs text-muted" x-text="`New photo · about ${receiptSizeKb} KB`"></span>
                                </div>
                            </template>
                            <template x-if="!receiptPreview && receiptUrl">
                                <div class="flex items-center gap-2">
                                    <a :href="receiptUrl" target="_blank" rel="noopener" :class="removeReceipt ? 'opacity-40' : ''">
                                        <img :src="receiptUrl" alt="Current receipt photo" class="h-16 w-16 rounded-md border border-border object-cover dark:border-gray-700">
                                    </a>
                                    <label class="inline-flex items-center gap-1.5 text-xs text-muted">
                                        <input type="checkbox" name="remove_receipt" value="1" x-model="removeReceipt" class="rounded border-border text-primary">
                                        Remove photo
                                    </label>
                                </div>
                            </template>
                        </div>
                        <p class="mt-1 text-xs text-muted">Payroll: photo of the signed payslips. It is saved as a small photo (under 200 KB) that only staff can open.</p>
                    </div>
                </div>
                <p x-show="problem" x-text="problem" class="text-sm text-red-700"></p>
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-emerald-700" x-show="drawerNow !== null && !editId">Drawer after saving: <span class="font-semibold" x-text="money(Number(drawerNow) - (isAdmin ? 0 : drawerPart))"></span></p>
                    <button :disabled="receiptBusy || !!problem" class="ml-auto h-11 rounded-md bg-primary px-5 text-sm font-medium text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60" x-text="receiptBusy ? 'Preparing photo…' : (editId ? 'Update Expense' : 'Save Expense')">Save Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>
