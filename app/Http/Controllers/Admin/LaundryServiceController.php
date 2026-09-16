<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\ServicePreset;
use App\Support\Activity;
use App\Support\ServiceCategories;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class LaundryServiceController extends Controller
{
    private const PRICING_TYPES = ['kilo', 'load', 'piece', 'custom'];
    private const STATUS_FILTERS = ['active', 'inactive'];

    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $this->canChooseBranch($user);

        $branches = Branch::where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $selectedBranchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;

        $services = LaundryService::with(['branch', 'inventoryUsages'])
            ->where('branch_id', $selectedBranchId)
            ->when(in_array($request->pricing_type, self::PRICING_TYPES, true), fn ($query) => $query->where('pricing_type', $request->pricing_type))
            ->when(in_array($request->status, self::STATUS_FILTERS, true), fn ($query) => $query->where('is_active', $request->status === 'active'))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(10)
            ->withQueryString();
        $inventoryItems = Inventory::query()
            ->where('branch_id', $selectedBranchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'quantity']);
        // Inactive services already inside a bundle stay listed, or saving the
        // bundle would silently drop them from it.
        $bundledServiceIds = DB::table('service_preset_items')
            ->join('service_presets', 'service_presets.id', '=', 'service_preset_items.service_preset_id')
            ->where('service_presets.branch_id', $selectedBranchId)
            ->pluck('service_preset_items.laundry_service_id');
        $presetServices = LaundryService::query()
            ->where('branch_id', $selectedBranchId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $bundledServiceIds))
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'pricing_type', 'is_active']);

        $serviceCategories = LaundryServiceCategory::where('is_active', true)
            // Another branch's private categories cannot be used here.
            ->where(fn ($query) => $query->where('visibility', 'all')->orWhere('branch_id', $selectedBranchId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'visibility', 'branch_id']);
        $servicePresets = ServicePreset::with(['items.service', 'serviceCategory'])
            ->where('branch_id', $selectedBranchId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.services.index', compact('services', 'branches', 'selectedBranchId', 'canChooseBranch', 'inventoryItems', 'presetServices', 'serviceCategories', 'servicePresets'));
    }

    public function store(Request $request)
    {
        $branchId = $this->submittedBranchId($request);
        $validated = $this->onlyForItsPricing($request->validate($this->rules($branchId)));
        $validated['branch_id'] = $branchId;
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['show_on_landing'] = $request->boolean('show_on_landing');

        $service = DB::transaction(function () use ($validated) {
            $service = LaundryService::create(collect($validated)->except('inventory_usages')->all());
            $this->syncInventoryUsages($service, $validated['inventory_usages'] ?? []);

            return $service;
        });

        Activity::log($request, 'service_created', $service, [
            'name' => $service->name,
            'price' => $service->price,
        ], $service->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $service->branch_id])->with('success', 'Service created successfully.');
    }

    public function update(Request $request, LaundryService $service)
    {
        $this->authorizeService($service);

        // A service stays in its branch: its inventory recipe, bundles and
        // sales history all belong to that branch.
        $validated = $this->onlyForItsPricing($request->validate($this->rules((int) $service->branch_id, $service)));
        $validated['branch_id'] = $service->branch_id;
        $validated['is_active'] = $request->boolean('is_active');
        $validated['show_on_landing'] = $request->boolean('show_on_landing');

        DB::transaction(function () use ($service, $validated) {
            $service->update(collect($validated)->except('inventory_usages')->all());
            $this->syncInventoryUsages($service, $validated['inventory_usages'] ?? []);
        });

        Activity::log($request, 'service_updated', $service, [
            'name' => $service->name,
            'price' => $service->price,
        ], $service->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $service->branch_id])->with('success', 'Service updated successfully.');
    }

    public function destroy(Request $request, LaundryService $service)
    {
        $this->authorizeService($service);

        // Deleting a bundled service would quietly lower the bundle's price.
        $presetNames = ServicePreset::query()
            ->whereHas('items', fn ($query) => $query->where('laundry_service_id', $service->id))
            ->orderBy('name')
            ->pluck('name');

        if ($presetNames->isNotEmpty()) {
            $single = $presetNames->count() === 1;

            return redirect()
                ->route('admin.services.index', ['branch_id' => $service->branch_id])
                ->with('error', $service->name.' is part of the preset'.($single ? ' ' : 's ').$presetNames->join(', ', ' and ')
                    .'. Remove it from '.($single ? 'that preset' : 'those presets').' first, or mark the service inactive instead.');
        }

        $service->delete();

        Activity::log($request, 'service_deleted', $service, [
            'name' => $service->name,
        ], $service->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $service->branch_id])->with('success', 'Service deleted successfully.');
    }

    public function storePreset(Request $request)
    {
        $branchId = $this->submittedBranchId($request);
        $validated = $request->validate($this->presetRules($branchId));

        $preset = DB::transaction(function () use ($request, $validated, $branchId) {
            $preset = ServicePreset::create([
                'branch_id' => $branchId,
                'service_category_id' => $validated['service_category_id'] ?? null,
                'name' => $validated['name'],
                'sort_order' => $validated['sort_order'] ?? 0,
                'is_active' => $request->boolean('is_active', true),
                'show_on_landing' => $request->boolean('show_on_landing'),
                'landing_blurb' => $validated['landing_blurb'] ?? null,
                'landing_icon' => $validated['landing_icon'] ?? null,
                'landing_sort_order' => (int) ($validated['landing_sort_order'] ?? 0),
            ]);

            $this->syncPresetItems($preset, $validated['items'] ?? []);

            return $preset;
        });

        Activity::log($request, 'service_preset_created', $preset, ['name' => $preset->name], $preset->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $preset->branch_id])->with('success', 'Preset created successfully.');
    }

    public function showPreset(ServicePreset $preset)
    {
        $this->authorizePreset($preset);

        return redirect()->route('admin.services.index', [
            'branch_id' => $preset->branch_id,
            'edit_preset' => $preset->id,
        ]);
    }

    public function updatePreset(Request $request, ServicePreset $preset)
    {
        $this->authorizePreset($preset);

        // Like a service, a preset stays in its branch: its services are that
        // branch's services.
        $validated = $request->validate($this->presetRules((int) $preset->branch_id, $preset));

        DB::transaction(function () use ($request, $preset, $validated) {
            $preset->update([
                'service_category_id' => $validated['service_category_id'] ?? null,
                'name' => $validated['name'],
                'sort_order' => $validated['sort_order'] ?? 0,
                'is_active' => $request->boolean('is_active'),
                'show_on_landing' => $request->boolean('show_on_landing'),
                'landing_blurb' => $validated['landing_blurb'] ?? null,
                'landing_icon' => $validated['landing_icon'] ?? null,
                'landing_sort_order' => (int) ($validated['landing_sort_order'] ?? 0),
            ]);

            $this->syncPresetItems($preset, $validated['items'] ?? []);
        });

        Activity::log($request, 'service_preset_updated', $preset, ['name' => $preset->name], $preset->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $preset->branch_id])->with('success', 'Preset updated successfully.');
    }

    public function destroyPreset(Request $request, ServicePreset $preset)
    {
        $this->authorizePreset($preset);
        $branchId = $preset->branch_id;
        $preset->delete();

        Activity::log($request, 'service_preset_deleted', $preset, ['name' => $preset->name], $branchId);

        return redirect()->route('admin.services.index', ['branch_id' => $branchId])->with('success', 'Preset deleted successfully.');
    }

    private function rules(int $branchId, ?LaundryService $service = null): array
    {
        return [
            'name'                => [
                'required', 'string', 'max:255',
                // Two services with one name make the POS, the price list and
                // the reports ambiguous.
                Rule::unique('laundry_services', 'name')
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->ignore($service?->id),
            ],
            'report_category'     => ['nullable', 'string', Rule::in(ServiceCategories::keys())],
            'service_category_id' => ['nullable', $this->categoryRule($branchId)],
            'pricing_type'        => ['required', Rule::in(['kilo', 'load', 'piece', 'custom'])],
            'price'               => ['required', 'numeric', 'min:0'],
            // Only weighed services have one; anything else ignores it.
            'minimum_kilos'       => ['nullable', 'numeric', 'min:0', 'max:9999'],
            // Load-priced services only: what one load holds. Blank is 10 kg.
            'kilos_per_load'      => ['nullable', 'numeric', 'min:1', 'max:100'],
            'price_unit_label'    => ['nullable', 'string', 'max:40'],
            'is_active'           => ['nullable', 'boolean'],
            'show_on_landing'     => ['nullable', 'boolean'],
            'landing_blurb'       => ['nullable', 'string', 'max:255'],
            'landing_icon'        => ['nullable', 'string', Rule::in(array_keys(LaundryService::LANDING_ICONS))],
            'landing_sort_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'inventory_usages'    => ['nullable', 'array'],
            'inventory_usages.*'  => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
        ];
    }

    /**
     * A load size means nothing on a service sold by the kilo, and would quietly
     * come back if the service were switched to per load later.
     */
    private function onlyForItsPricing(array $validated): array
    {
        if ($validated['pricing_type'] !== 'load') {
            $validated['kilos_per_load'] = null;
        }

        return $validated;
    }

    private function presetRules(int $branchId, ?ServicePreset $preset = null): array
    {
        return [
            'service_category_id' => ['nullable', $this->categoryRule($branchId)],
            'show_on_landing' => ['nullable', 'boolean'],
            'landing_blurb' => ['nullable', 'string', 'max:255'],
            'landing_icon' => ['nullable', 'string', Rule::in(array_keys(LaundryService::LANDING_ICONS))],
            'landing_sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('service_presets', 'name')->where('branch_id', $branchId)->ignore($preset?->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    private function syncInventoryUsages(LaundryService $service, array $usages): void
    {
        $quantities = collect($usages)
            ->filter(fn ($quantity) => is_numeric($quantity) && (float) $quantity > 0)
            ->mapWithKeys(fn ($quantity, $inventoryId) => [(int) $inventoryId => (float) $quantity]);

        $validInventoryIds = Inventory::query()
            ->where('branch_id', $service->branch_id)
            ->whereIn('id', $quantities->keys())
            ->pluck('id');

        if ($validInventoryIds->count() !== $quantities->count()) {
            throw ValidationException::withMessages([
                'inventory_usages' => 'Every inventory usage must belong to the selected service branch.',
            ]);
        }

        $service->inventoryUsages()->whereNotIn('inventory_id', $validInventoryIds)->delete();

        foreach ($validInventoryIds as $inventoryId) {
            $service->inventoryUsages()->updateOrCreate(
                ['inventory_id' => $inventoryId],
                ['quantity' => $quantities->get($inventoryId)]
            );
        }
    }

    private function syncPresetItems(ServicePreset $preset, array $items): void
    {
        $quantities = collect($items)
            ->filter(fn ($quantity) => is_numeric($quantity) && (float) $quantity > 0)
            ->mapWithKeys(fn ($quantity, $serviceId) => [(int) $serviceId => (float) $quantity]);

        if ($quantities->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Choose at least one service for this preset.',
            ]);
        }

        $validServiceIds = LaundryService::query()
            ->where('branch_id', $preset->branch_id)
            ->whereIn('id', $quantities->keys())
            ->pluck('id');

        if ($validServiceIds->count() !== $quantities->count()) {
            throw ValidationException::withMessages([
                'items' => 'Every preset service must belong to the selected branch.',
            ]);
        }

        $preset->items()->whereNotIn('laundry_service_id', $validServiceIds)->delete();

        foreach ($validServiceIds as $serviceId) {
            $preset->items()->updateOrCreate(
                ['laundry_service_id' => $serviceId],
                ['quantity' => $quantities->get($serviceId)]
            );
        }
    }

    /**
     * The branch a new service or preset goes into. Branch staff always add to
     * their own branch; admins pick one. Resolved before the other rules run,
     * because the name and category checks depend on it.
     */
    private function submittedBranchId(Request $request): int
    {
        $user = $request->user();

        if (! $this->canChooseBranch($user)) {
            if (! $user->branch_id) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Your account is not assigned to a branch yet.',
                ]);
            }

            return (int) $user->branch_id;
        }

        $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);

        return $request->integer('branch_id');
    }

    /** An active category shared with every branch, or private to this one. */
    private function categoryRule(int $branchId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($branchId) {
            $category = LaundryServiceCategory::query()->where('is_active', true)->find($value);

            if (! $category || ! $category->isAvailableFor($branchId)) {
                $fail('Choose a category that is active and available to this branch.');
            }
        };
    }

    private function authorizeService(LaundryService $service): void
    {
        $user = auth()->user();

        if ($this->canChooseBranch($user)) {
            return;
        }

        abort_unless((int) $service->branch_id === (int) $user->branch_id, 403);
    }

    private function authorizePreset(ServicePreset $preset): void
    {
        $user = auth()->user();

        if ($this->canChooseBranch($user)) {
            return;
        }

        abort_unless((int) $preset->branch_id === (int) $user->branch_id, 403);
    }

    private function canChooseBranch($user): bool
    {
        return $user->isSuperAdmin() || $user->role === 'admin';
    }
}
