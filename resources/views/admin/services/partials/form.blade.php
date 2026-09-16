@php
    // Old input belongs only to the form that was submitted; every other form
    // on the page keeps showing its own saved values.
    $isFailedForm = $errors->any() && old('_form') === $formKey;
    $value = fn (string $key, $saved) => $isFailedForm ? old($key, $saved) : $saved;
    $checked = fn (string $key, $saved) => $isFailedForm ? (bool) old($key) : (bool) $saved;
    $formBranchId = (int) ($service->branch_id ?: $selectedBranchId);
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4"
      x-data="{ branchId: '{{ $value('branch_id', $formBranchId) }}', pricingType: @js($value('pricing_type', $service->pricing_type)) }">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium">Name</label>
            <input name="name" value="{{ $value('name', $service->name) }}" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Branch</label>
            {{-- A saved service stays in its branch: its stock recipe, presets and
                 sales history belong there. Only a new one can be placed. --}}
            @if($service->exists || ! $canChooseBranch)
                <input value="{{ $service->branch?->name ?? $branches->firstWhere('id', $formBranchId)?->name ?? auth()->user()->branch?->name }}" disabled class="h-9 w-full rounded-md border border-border bg-smoke px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            @else
                <select name="branch_id" required x-model="branchId" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $value('branch_id', $formBranchId) === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Pricing Type</label>
            <select name="pricing_type" x-model="pricingType" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                @foreach(['kilo', 'load', 'piece', 'custom'] as $type)
                    <option value="{{ $type }}" @selected($value('pricing_type', $service->pricing_type) === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Category</label>
            <select name="service_category_id" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                <option value="">No Category</option>
                @foreach($serviceCategories as $cat)
                    <option value="{{ $cat->id }}" @selected((int) $value('service_category_id', $service->service_category_id) === (int) $cat->id)>
                        {{ $cat->name }}{{ $cat->visibility === 'branch' ? ' (Branch only)' : '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Price</label>
            <input type="number" step="0.01" min="0" name="price" value="{{ $value('price', $service->price ?? 0) }}" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>

        {{-- How the kilos a customer declares become loads on the bill. --}}
        <div x-show="pricingType === 'load'" x-cloak>
            <label class="mb-1.5 block text-sm font-medium">Max kg per Load</label>
            <input type="number" step="0.5" min="1" max="100" name="kilos_per_load" placeholder="{{ \App\Support\Booking::DEFAULT_KILOS_PER_LOAD }}"
                   value="{{ $value('kilos_per_load', $service->kilos_per_load !== null ? (float) $service->kilos_per_load : null) }}"
                   class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            <p class="mt-1 text-xs text-muted">At 10 kg, 1&ndash;10 kg is one load and 11&ndash;20 kg is two. Blank uses {{ \App\Support\Booking::DEFAULT_KILOS_PER_LOAD }} kg.</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Minimum Kilos</label>
            <input type="number" step="0.5" min="0" name="minimum_kilos" placeholder="No minimum"
                   value="{{ $value('minimum_kilos', $service->minimum_kilos) }}"
                   class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            <p class="mt-1 text-xs text-muted">Kilo-priced services only. A booking below this is charged at the minimum.</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Price Unit Label</label>
            <input type="text" name="price_unit_label" maxlength="40" placeholder="{{ $service->exists ? $service->priceUnitLabel() : 'per kilo' }}"
                   value="{{ $value('price_unit_label', $service->price_unit_label) }}"
                   class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            <p class="mt-1 text-xs text-muted">Overrides the wording beside the price, e.g. &ldquo;per pair&rdquo;. Blank uses the pricing type.</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Z Reading Column</label>
            <select name="report_category" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                @foreach(\App\Support\ServiceCategories::LABELS as $key => $label)
                    <option value="{{ $key }}" @selected($value('report_category', $service->report_category ?: \App\Support\ServiceCategories::infer($service->name)) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="inline-flex h-9 items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="is_active" value="1" @checked($checked('is_active', $service->is_active)) class="rounded border-border text-primary">
                Active service
            </label>
        </div>
    </div>

    {{-- Controls whether customers can pick this service on the public site. --}}
    <div x-data="{ pinned: {{ $checked('show_on_landing', $service->show_on_landing) ? 'true' : 'false' }} }"
         class="rounded-md border border-border p-3 dark:border-gray-800">
        <label class="flex cursor-pointer items-start gap-2.5">
            <input type="checkbox" name="show_on_landing" value="1" x-model="pinned" class="mt-0.5 rounded border-border text-primary">
            <span>
                <span class="block text-sm font-semibold">Show on landing page</span>
                <span class="mt-0.5 block text-xs text-muted">
                    Pinned services are what customers can choose when booking a pickup online, at the price set above.
                </span>
            </span>
        </label>

        <div x-show="pinned" x-cloak x-transition class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-[1fr_11rem_7rem]">
            <div>
                <label class="mb-1.5 block text-sm font-medium">Customer-facing description</label>
                <input name="landing_blurb" maxlength="255" value="{{ $value('landing_blurb', $service->landing_blurb) }}"
                       placeholder="Our everyday load. Sorted, washed, tumble dried and folded."
                       class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium">Icon</label>
                <select name="landing_icon" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">Auto (from name)</option>
                    @foreach(\App\Models\LaundryService::LANDING_ICONS as $iconKey => $iconLabel)
                        <option value="{{ $iconKey }}" @selected($value('landing_icon', $service->landing_icon) === $iconKey)>{{ $iconLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium">Order</label>
                <input type="number" min="0" max="9999" name="landing_sort_order"
                       value="{{ $value('landing_sort_order', $service->landing_sort_order ?? 0) }}"
                       class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>
        </div>
    </div>

    <div>
        <div class="mb-2">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold">Inventory Consumption</h3>
                <a href="{{ route('admin.inventory.index', ['branch_id' => $service->branch_id ?: $selectedBranchId]) }}" target="_blank" class="text-xs font-medium text-primary hover:underline">View Inventory</a>
            </div>
            <p class="text-xs text-muted">Quantity deducted from stock for every 1 service quantity sold. Leave zero when no stock is consumed.</p>
        </div>
        {{-- The stock listed is this page's branch. A new service placed in
             another branch gets its recipe from that branch's page. --}}
        <p x-show="branchId !== '{{ $selectedBranchId }}'" x-cloak class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            This stock belongs to another branch. Save the service first, then set its inventory from the chosen branch's page.
        </p>
        <div class="grid max-h-56 gap-2 overflow-y-auto rounded-md border border-border p-2 dark:border-gray-800 sm:grid-cols-2"
             :class="branchId !== '{{ $selectedBranchId }}' && 'pointer-events-none opacity-50'">
            @forelse($inventoryItems as $inventory)
                @php
                    $savedQuantity = $service->inventoryUsages?->firstWhere('inventory_id', $inventory->id)?->quantity ?? 0;
                @endphp
                <label class="grid grid-cols-[minmax(0,1fr)_7rem] items-center gap-2 rounded-md bg-smoke p-2 dark:bg-gray-950">
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">{{ $inventory->name }}</span>
                        <span class="text-xs text-muted">{{ number_format((float) $inventory->quantity, 2) }} {{ $inventory->unit }} in stock</span>
                    </span>
                    <input
                        name="inventory_usages[{{ $inventory->id }}]"
                        value="{{ $value('inventory_usages.'.$inventory->id, $savedQuantity) }}"
                        :disabled="branchId !== '{{ $selectedBranchId }}'"
                        type="number"
                        min="0"
                        step="0.0001"
                        class="h-9 w-full rounded-md border border-border bg-white px-2 text-right text-sm dark:border-gray-800 dark:bg-gray-900"
                    >
                </label>
            @empty
                <p class="col-span-full p-3 text-center text-sm text-muted">No active inventory items are configured for this branch.</p>
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
        <button type="submit" class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">Save Service</button>
    </div>
</form>
