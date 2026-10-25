<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vinegar: a free odor treatment for smelly laundry, measured in ml. When an
 * order has it, it takes the place of the fabric conditioner (the default
 * Yen Yen and any fabcon add-on).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('branches')
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function ($branch) use ($now): void {
                $inventoryId = $this->vinegarInventoryId((int) $branch->id, $now);
                $serviceId = $this->vinegarServiceId((int) $branch->id, $now);

                // One ml sold uses one ml of stock.
                DB::table('service_inventory_usages')->updateOrInsert(
                    ['laundry_service_id' => $serviceId, 'inventory_id' => $inventoryId],
                    ['quantity' => 1, 'created_at' => $now, 'updated_at' => $now]
                );
            });
    }

    private function vinegarInventoryId(int $branchId, $now): int
    {
        $inventory = DB::table('inventories')
            ->where('branch_id', $branchId)
            ->where(fn ($query) => $query->where('sku', 'SUP-VINEGAR')->orWhere('name', 'Vinegar'))
            ->first();

        if ($inventory) {
            DB::table('inventories')->where('id', $inventory->id)->update([
                'name' => 'Vinegar',
                'sku' => 'SUP-VINEGAR',
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return (int) $inventory->id;
        }

        return DB::table('inventories')->insertGetId([
            'branch_id' => $branchId,
            'name' => 'Vinegar',
            'sku' => 'SUP-VINEGAR',
            'unit' => 'ml',
            'quantity' => 0,
            'reorder_level' => 500,
            'unit_cost' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function vinegarServiceId(int $branchId, $now): int
    {
        $categoryId = $this->addonCategoryId($branchId, $now);
        $service = DB::table('laundry_services')
            ->where('branch_id', $branchId)
            ->where('name', 'Vinegar')
            ->whereNull('deleted_at')
            ->first();

        $attributes = [
            'service_category_id' => $categoryId,
            'report_category' => 'vinegar',
            'pricing_type' => 'ml',
            'price' => 0,
            'price_unit_label' => 'per ml',
            'is_active' => true,
            'updated_at' => $now,
        ];

        if ($service) {
            DB::table('laundry_services')->where('id', $service->id)->update($attributes);

            return (int) $service->id;
        }

        return DB::table('laundry_services')->insertGetId($attributes + [
            'branch_id' => $branchId,
            'name' => 'Vinegar',
            'show_on_landing' => false,
            'created_at' => $now,
        ]);
    }

    private function addonCategoryId(int $branchId, $now): int
    {
        $categoryId = DB::table('laundry_service_categories')
            ->where('is_addon', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
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
        // Keep the service, stock and recipe: orders may already use them.
    }
};
