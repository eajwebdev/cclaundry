<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $this->restoreDownyAsFabricConditioner($now);

        DB::table('branches')
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function ($branch) use ($now): void {
                $inventoryId = $this->finishingSprayInventoryId((int) $branch->id, $now);
                $serviceId = $this->finishingSprayServiceId((int) $branch->id, $now);

                DB::table('service_inventory_usages')->updateOrInsert(
                    [
                        'laundry_service_id' => $serviceId,
                        'inventory_id' => $inventoryId,
                    ],
                    [
                        'quantity' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            });
    }

    private function restoreDownyAsFabricConditioner($now): void
    {
        $variants = [
            'SUP-FINISH-MYSTIQUE' => [
                'name' => 'Downy Mystique Fabric Conditioner',
                'sku' => 'SUP-FABCON-MYSTIQUE',
            ],
            'SUP-FINISH-SUNRISE' => [
                'name' => 'Downy Sunrise Fabric Conditioner',
                'sku' => 'SUP-FABCON-SUNRISE',
            ],
        ];

        foreach ($variants as $oldSku => $variant) {
            DB::table('inventories')->where('sku', $oldSku)->update([
                'name' => $variant['name'],
                'sku' => $variant['sku'],
                'unit' => 'sachet',
                'updated_at' => $now,
            ]);
        }
    }

    private function finishingSprayInventoryId(int $branchId, $now): int
    {
        $inventory = DB::table('inventories')
            ->where('branch_id', $branchId)
            ->where(function ($query): void {
                $query->where('sku', 'SUP-FINISHING-SPRAY')
                    ->orWhere('name', 'Finishing Spray');
            })
            ->first();

        if ($inventory) {
            DB::table('inventories')->where('id', $inventory->id)->update([
                'name' => 'Finishing Spray',
                'sku' => 'SUP-FINISHING-SPRAY',
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return (int) $inventory->id;
        }

        return DB::table('inventories')->insertGetId([
            'branch_id' => $branchId,
            'name' => 'Finishing Spray',
            'sku' => 'SUP-FINISHING-SPRAY',
            'unit' => 'use',
            'quantity' => 0,
            'reorder_level' => 10,
            'unit_cost' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function finishingSprayServiceId(int $branchId, $now): int
    {
        $categoryId = $this->addonCategoryId($branchId, $now);
        $service = DB::table('laundry_services')
            ->where('branch_id', $branchId)
            ->where('name', 'Finishing Spray')
            ->whereNull('deleted_at')
            ->first();

        if ($service) {
            DB::table('laundry_services')->where('id', $service->id)->update([
                'service_category_id' => $categoryId,
                'report_category' => 'finishing_spray',
                'is_active' => true,
                'show_on_landing' => true,
                'updated_at' => $now,
            ]);

            return (int) $service->id;
        }

        return DB::table('laundry_services')->insertGetId([
            'branch_id' => $branchId,
            'service_category_id' => $categoryId,
            'name' => 'Finishing Spray',
            'report_category' => 'finishing_spray',
            'pricing_type' => 'custom',
            'price' => 0,
            'price_unit_label' => 'per load',
            'is_active' => true,
            'show_on_landing' => true,
            'landing_blurb' => 'Optional finishing spray for your load.',
            'landing_icon' => 'spray-can',
            'landing_sort_order' => 12,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function addonCategoryId(int $branchId, $now): int
    {
        $categoryId = DB::table('laundry_service_categories')
            ->where('is_addon', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
            ->value('id');

        if ($categoryId) {
            return (int) $categoryId;
        }

        return DB::table('laundry_service_categories')->insertGetId([
            'name' => 'Add-ons',
            'visibility' => 'all',
            'branch_id' => null,
            'sort_order' => 4,
            'is_addon' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Preserve services, stock and recipes because staff may have renamed
        // or used this placeholder after it became available.
    }
};
