@php
    $canChooseBranch = auth()->user()->isSuperAdmin() || auth()->user()->role === 'admin';
    $formContext = $customer->exists ? (string) $customer->getKey() : 'create';
    $restoreInput = (string) old('_customer_form', '') === $formContext;
    $formValue = fn ($field, $fallback = null) => $restoreInput ? old($field, $fallback) : $fallback;
    $errors = $restoreInput ? $errors : new \Illuminate\Support\ViewErrorBag;
    $fieldPrefix = 'customer-'.($customer->getKey() ?? 'new');
    $inputClasses = fn ($field) => 'w-full rounded-xl border bg-white px-3.5 text-sm text-dark shadow-xs transition placeholder:text-muted/60 focus:border-primary focus:outline-none focus:ring-3 focus:ring-primary/10 dark:bg-gray-950 dark:text-gray-100 '.($errors->has($field) ? 'border-red-400' : 'border-border dark:border-gray-700');
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    <input type="hidden" name="_customer_form" value="{{ $formContext }}">
    @if($method !== 'POST')
        @method($method)
    @endif
    @if(! empty($redirectTo))
        <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
    @endif

    <section aria-labelledby="{{ $fieldPrefix }}-contact-title" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 id="{{ $fieldPrefix }}-contact-title" class="text-xs font-semibold uppercase tracking-wider text-muted">Contact details</h3>
            <p class="text-xs text-muted"><span class="text-primary">*</span> Required fields</p>
        </div>

        <div>
            <label for="{{ $fieldPrefix }}-name" class="mb-1.5 block text-sm font-semibold">Full name <span class="text-primary" aria-hidden="true">*</span></label>
            <input id="{{ $fieldPrefix }}-name" name="name" value="{{ $formValue('name', $customer->name) }}" required autocomplete="name" placeholder="e.g. Juan Dela Cruz" @error('name') aria-invalid="true" aria-describedby="{{ $fieldPrefix }}-name-error" @enderror class="h-11 {{ $inputClasses('name') }}">
            @error('name')<p id="{{ $fieldPrefix }}-name-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="{{ $fieldPrefix }}-phone" class="mb-1.5 block text-sm font-semibold">Phone number <span class="text-primary" aria-hidden="true">*</span></label>
                <input id="{{ $fieldPrefix }}-phone" type="tel" name="phone" required value="{{ $formValue('phone', $customer->phone) }}" autocomplete="tel" placeholder="e.g. 0917 123 4567" @error('phone') aria-invalid="true" aria-describedby="{{ $fieldPrefix }}-phone-error" @enderror class="h-11 {{ $inputClasses('phone') }}">
                @error('phone')<p id="{{ $fieldPrefix }}-phone-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="{{ $fieldPrefix }}-email" class="mb-1.5 flex items-center justify-between gap-2 text-sm font-semibold">Email <span class="text-xs font-normal text-muted">Optional</span></label>
                <input id="{{ $fieldPrefix }}-email" type="email" name="email" value="{{ $formValue('email', $customer->email) }}" autocomplete="email" placeholder="name@example.com" @error('email') aria-invalid="true" aria-describedby="{{ $fieldPrefix }}-email-error" @enderror class="h-11 {{ $inputClasses('email') }}">
                @error('email')<p id="{{ $fieldPrefix }}-email-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label for="{{ $fieldPrefix }}-address" class="mb-1.5 block text-sm font-semibold">Address <span class="text-primary" aria-hidden="true">*</span></label>
            <textarea id="{{ $fieldPrefix }}-address" name="address" required rows="2" autocomplete="street-address" placeholder="House / building, street, barangay, city" @error('address') aria-invalid="true" aria-describedby="{{ $fieldPrefix }}-address-error" @enderror class="min-h-20 resize-y py-3 {{ $inputClasses('address') }}">{{ $formValue('address', $customer->address) }}</textarea>
            @error('address')<p id="{{ $fieldPrefix }}-address-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </section>

    <section aria-labelledby="{{ $fieldPrefix }}-settings-title" class="space-y-4 rounded-xl border border-border bg-smoke/70 p-4 dark:border-gray-800 dark:bg-gray-950/50">
        <h3 id="{{ $fieldPrefix }}-settings-title" class="text-xs font-semibold uppercase tracking-wider text-muted">Store settings</h3>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="{{ $fieldPrefix }}-branch" class="mb-1.5 block text-sm font-semibold">Branch</label>
                @if(! $canChooseBranch)
                    <input type="hidden" name="branch_id" @if(! empty($branchModel)) :value="{{ $branchModel }}" @else value="{{ auth()->user()->branch_id }}" @endif>
                    <input id="{{ $fieldPrefix }}-branch" value="{{ auth()->user()->branch?->name }}" disabled class="h-11 {{ $inputClasses('branch_id') }} opacity-75">
                @else
                    <select id="{{ $fieldPrefix }}-branch" name="branch_id" @if(! empty($branchModel)) x-model="{{ $branchModel }}" @endif required class="h-11 {{ $inputClasses('branch_id') }}">
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($formValue('branch_id', $customer->branch_id) == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                @endif
                @error('branch_id')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="{{ $fieldPrefix }}-limit" class="mb-1.5 block text-sm font-semibold">Unpaid limit</label>
                <input id="{{ $fieldPrefix }}-limit" type="number" step="0.01" min="0" name="unpaid_limit" value="{{ $formValue('unpaid_limit', $customer->unpaid_limit ?? 0) }}" class="h-11 {{ $inputClasses('unpaid_limit') }}">
                @error('unpaid_limit')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <label for="{{ $fieldPrefix }}-active" class="flex cursor-pointer items-center justify-between gap-4 border-t border-border pt-3 dark:border-gray-800">
            <span class="text-sm font-medium">Active customer</span>
            <span class="relative inline-flex shrink-0">
                <input type="hidden" name="is_active" value="0">
                <input id="{{ $fieldPrefix }}-active" type="checkbox" name="is_active" value="1" @checked($formValue('is_active', $customer->is_active)) class="peer sr-only">
                <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-primary peer-focus-visible:ring-3 peer-focus-visible:ring-primary/30 dark:bg-gray-700"></span>
                <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></span>
            </span>
        </label>
    </section>

    <div class="flex items-center justify-end gap-3 border-t border-border pt-4 dark:border-gray-800">
        <button type="button" @click="{{ $cancelAction ?? ($method === 'POST' ? 'createOpen = false' : 'editOpen = null') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-border px-5 text-sm font-semibold transition hover:bg-smoke focus-visible:outline-2 focus-visible:outline-primary dark:border-gray-700 dark:hover:bg-gray-800">Cancel</button>
        <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <span data-lucide="{{ $method === 'POST' ? 'user-plus' : 'check' }}" class="h-4 w-4" aria-hidden="true"></span>
            {{ $method === 'POST' ? 'Save Customer' : 'Save Changes' }}
        </button>
    </div>
</form>
