@php
    // Old input belongs only to the form that was submitted; every other form
    // on the page keeps showing its own saved values.
    $isFailedForm = $errors->any() && old('_form') === $formKey;
    $value = fn (string $key, $saved) => $isFailedForm ? old($key, $saved) : $saved;
    $checked = fn (string $key, $saved) => $isFailedForm ? (bool) old($key) : (bool) $saved;
    $formBranchId = (int) ($preset->branch_id ?: $selectedBranchId);
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium">Preset Name</label>
            <input name="name" value="{{ $value('name', $preset->name) }}" required placeholder="e.g. Full Wash Package" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Branch</label>
            {{-- A preset can only bundle services from one branch, and the services
                 listed below are this page's branch, so the branch is fixed here.
                 Switch branch with the filter above to build another branch's. --}}
            @if(! $preset->exists)
                <input type="hidden" name="branch_id" value="{{ $formBranchId }}">
            @endif
            <input value="{{ $preset->branch?->name ?? $branches->firstWhere('id', $formBranchId)?->name }}" disabled class="h-9 w-full rounded-md border border-border bg-smoke px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Catalog Category</label>
            <select name="service_category_id" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                <option value="">No category</option>
                @foreach($serviceCategories as $cat)
                    <option value="{{ $cat->id }}" @selected((int) $value('service_category_id', $preset->service_category_id) === (int) $cat->id)>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Sort Order</label>
            <input type="number" name="sort_order" min="0" value="{{ $value('sort_order', $preset->sort_order ?? 0) }}" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>
    </div>

    {{-- A pinned bundle is offered to customers on the public landing page. --}}
    <div x-data="{ pinned: {{ $checked('show_on_landing', $preset->show_on_landing ?? false) ? 'true' : 'false' }} }"
         class="rounded-md border border-border p-3 dark:border-gray-800">
        <label class="flex cursor-pointer items-start gap-2.5">
            <input type="checkbox" name="show_on_landing" value="1" x-model="pinned" class="mt-0.5 rounded border-border text-primary">
            <span>
                <span class="block text-sm font-semibold">Show on landing page</span>
                <span class="mt-0.5 block text-xs text-muted">
                    Customers can book this bundle online. Its price is the sum of the services below, so it stays in step with your rates.
                    It is hidden automatically while any of those services is inactive.
                </span>
            </span>
        </label>

        <div x-show="pinned" x-cloak x-transition class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-[1fr_11rem_7rem]">
            <div>
                <label class="mb-1.5 block text-sm font-medium">Customer-facing description</label>
                <input name="landing_blurb" maxlength="255" value="{{ $value('landing_blurb', $preset->landing_blurb ?? '') }}"
                       placeholder="Everything handled in one go, washed, dried and folded."
                       class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium">Icon</label>
                <select name="landing_icon" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">Auto (from name)</option>
                    @foreach(\App\Models\LaundryService::LANDING_ICONS as $iconKey => $iconLabel)
                        <option value="{{ $iconKey }}" @selected($value('landing_icon', $preset->landing_icon ?? '') === $iconKey)>{{ $iconLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium">Order</label>
                <input type="number" min="0" max="9999" name="landing_sort_order"
                       value="{{ $value('landing_sort_order', $preset->landing_sort_order ?? 0) }}"
                       class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>
        </div>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold">Included Services</h3>
            <label class="inline-flex h-8 items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="is_active" value="1" @checked($checked('is_active', $preset->is_active ?? true)) class="rounded border-border text-primary">
                Active
            </label>
        </div>
        <div class="grid max-h-64 gap-2 overflow-y-auto rounded-md border border-border p-2 dark:border-gray-800 sm:grid-cols-2">
            @forelse($presetServices as $serviceOption)
                @php
                    $savedQuantity = $preset->items?->firstWhere('laundry_service_id', $serviceOption->id)?->quantity ?? 0;
                @endphp
                <label class="grid grid-cols-[minmax(0,1fr)_6rem] items-center gap-2 rounded-md bg-smoke p-2 dark:bg-gray-950">
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">
                            {{ $serviceOption->name }}
                            @unless($serviceOption->is_active)
                                <span class="text-xs font-normal text-red-600">(inactive)</span>
                            @endunless
                        </span>
                        <span class="text-xs text-muted">{{ ucfirst($serviceOption->pricing_type) }} · {{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $serviceOption->price, 2) }}</span>
                    </span>
                    <input
                        name="items[{{ $serviceOption->id }}]"
                        value="{{ $value('items.'.$serviceOption->id, $savedQuantity) }}"
                        type="number"
                        min="0"
                        step="0.01"
                        class="h-9 w-full rounded-md border border-border bg-white px-2 text-right text-sm dark:border-gray-800 dark:bg-gray-900"
                    >
                </label>
            @empty
                <p class="col-span-full p-3 text-center text-sm text-muted">Add services for this branch before creating a preset.</p>
            @endforelse
        </div>
    </div>

    @if($isFailedForm)
        <ul class="list-disc space-y-0.5 rounded-md border border-red-200 bg-red-50 py-2 pr-3 pl-7 text-sm text-red-700">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="flex justify-end">
        <button type="submit" class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">Save Preset</button>
    </div>
</form>
