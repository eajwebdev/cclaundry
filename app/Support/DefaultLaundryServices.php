<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\ServicePreset;

class DefaultLaundryServices
{
    public static function all(): array
    {
        return [
            // Full Service
            ['name' => 'Regular Laundry',                         'pricing_type' => 'kilo', 'price' => 30,  'category' => 'Full Service', 'report_category' => 'wash',    'landing' => ['pinned' => true, 'icon' => 'scale', 'order' => 1, 'blurb' => 'Washed, dried and folded everyday clothes. Minimum order of 5 kg.']],
            ['name' => 'Comforters, Blankets & Drapes',           'pricing_type' => 'kilo', 'price' => 55,  'category' => 'Full Service', 'report_category' => 'special', 'landing' => ['pinned' => true, 'icon' => 'bed-double', 'order' => 2, 'blurb' => 'Bulky and heavy fabrics, cleaned with extra care. Minimum order of 4 kg.']],
            ['name' => 'Bed Sheets, Duvets & Towels (max 10kg)',  'pricing_type' => 'load', 'price' => 350, 'category' => 'Full Service', 'report_category' => 'special', 'landing' => ['pinned' => true, 'icon' => 'bed-double', 'order' => 3, 'blurb' => 'A full load of linens, up to 10 kg per load.']],
            ['name' => 'Bed Sheets, Towels & Curtains (max 4kg)', 'pricing_type' => 'load', 'price' => 150, 'category' => 'Full Service', 'report_category' => 'special', 'landing' => ['pinned' => true, 'icon' => 'bed-double', 'order' => 4, 'blurb' => 'A smaller linen cycle, up to 4 kg per cycle.']],

            // Extra Services
            ['name' => 'Uniform Steaming (per pair)', 'pricing_type' => 'piece', 'price' => 80, 'category' => 'Extra Services', 'report_category' => 'other', 'landing' => ['pinned' => true, 'icon' => 'flame', 'order' => 5, 'blurb' => 'Uniforms steamed crisp and ready to wear, priced per pair.']],

            // Add-ons: the customer's choice of detergent and fabric conditioner.
            ['name' => 'Ariel Detergent',            'pricing_type' => 'custom', 'price' => 20, 'category' => 'Add-ons', 'report_category' => 'detergent'],
            ['name' => 'Tide Detergent',             'pricing_type' => 'custom', 'price' => 18, 'category' => 'Add-ons', 'report_category' => 'detergent'],
            ['name' => 'Downy Mystique Fabcon',      'pricing_type' => 'custom', 'price' => 10, 'category' => 'Add-ons', 'report_category' => 'fabcon'],
            ['name' => 'Downy Sunrise Fresh Fabcon', 'pricing_type' => 'custom', 'price' => 10, 'category' => 'Add-ons', 'report_category' => 'fabcon'],
        ];
    }

    public static function presets(): array
    {
        // The price list sells no bundles. Returning none also clears the old
        // machine-based presets from each branch on re-seed.
        return [];
    }

    public static function seedForBranch(Branch $branch): void
    {
        self::ensureCategories();

        $categoryMap = LaundryServiceCategory::pluck('id', 'name')->all();
        $names = array_column(self::all(), 'name');

        LaundryService::where('branch_id', $branch->id)
            ->whereNotIn('name', $names)
            ->delete();

        foreach (self::all() as $service) {
            $existing = LaundryService::withTrashed()
                ->where('branch_id', $branch->id)
                ->where('name', $service['name'])
                ->first();

            $attributes = [
                'pricing_type'        => $service['pricing_type'],
                'service_category_id' => $categoryMap[$service['category']] ?? null,
                'report_category'     => $service['report_category'],
                'price'               => $service['price'],
                'is_active'           => true,
                'deleted_at'          => null,
            ];

            if ($existing) {
                // Landing-page presentation is deliberately left alone here, so
                // re-running the seeder never undoes what staff have pinned.
                $existing->forceFill($attributes)->save();

                continue;
            }

            $landing = $service['landing'] ?? [];

            LaundryService::create($attributes + [
                'branch_id'          => $branch->id,
                'name'               => $service['name'],
                'show_on_landing'    => (bool) ($landing['pinned'] ?? false),
                'landing_blurb'      => $landing['blurb'] ?? null,
                'landing_icon'       => $landing['icon'] ?? null,
                'landing_sort_order' => (int) ($landing['order'] ?? 0),
            ]);
        }

        self::seedPresetsForBranch($branch, $categoryMap);
    }

    private static function ensureCategories(): void
    {
        $categories = collect(self::all())
            ->pluck('category')
            ->unique()
            ->values();

        foreach ($categories as $index => $name) {
            LaundryServiceCategory::updateOrCreate(
                ['name' => $name],
                ['visibility' => 'all', 'sort_order' => $index + 1, 'is_active' => true]
            );
        }
    }

    private static function seedPresetsForBranch(Branch $branch, array $categoryMap): void
    {
        $serviceMap = LaundryService::query()
            ->where('branch_id', $branch->id)
            ->get()
            ->keyBy('name');
        $presetNames = array_column(self::presets(), 'name');

        ServicePreset::query()
            ->where('branch_id', $branch->id)
            ->whereNotIn('name', $presetNames)
            ->delete();

        foreach (self::presets() as $definition) {
            $preset = ServicePreset::query()
                ->where('branch_id', $branch->id)
                ->where('name', $definition['name'])
                ->first();

            $attributes = [
                'service_category_id' => $categoryMap[$definition['category']] ?? null,
                'sort_order' => $definition['sort_order'],
                'is_active' => true,
            ];

            if ($preset) {
                // Landing presentation is left alone on re-seed.
                $preset->forceFill($attributes)->save();
            } else {
                $landing = $definition['landing'] ?? [];

                $preset = ServicePreset::create($attributes + [
                    'branch_id' => $branch->id,
                    'name' => $definition['name'],
                    'show_on_landing' => (bool) ($landing['pinned'] ?? false),
                    'landing_blurb' => $landing['blurb'] ?? null,
                    'landing_icon' => $landing['icon'] ?? null,
                    'landing_sort_order' => (int) ($landing['order'] ?? 0),
                ]);
            }

            $serviceIds = [];

            foreach ($definition['items'] as $serviceName => $quantity) {
                $service = $serviceMap->get($serviceName);

                if (! $service) {
                    continue;
                }

                $serviceIds[] = $service->id;
                $preset->items()->updateOrCreate(
                    ['laundry_service_id' => $service->id],
                    ['quantity' => $quantity]
                );
            }

            $preset->items()->whereNotIn('laundry_service_id', $serviceIds)->delete();
        }
    }
}
