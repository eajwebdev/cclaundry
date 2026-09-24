<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $sprays = [
            'Downy Mystique' => [
                'name' => 'Downy Mystique Finishing Spray',
                'sku' => 'SUP-FINISH-MYSTIQUE',
            ],
            'Downy Sunrise' => [
                'name' => 'Downy Sunrise Finishing Spray',
                'sku' => 'SUP-FINISH-SUNRISE',
            ],
        ];

        DB::table('laundry_services')
            ->whereNull('deleted_at')
            ->whereIn('name', array_keys($sprays))
            ->orderBy('id')
            ->each(function ($service) use ($now, $sprays): void {
                $definition = $sprays[$service->name];
                $inventory = DB::table('inventories')
                    ->where('branch_id', $service->branch_id)
                    ->where(function ($query) use ($definition): void {
                        $query->where('sku', $definition['sku'])
                            ->orWhere('name', $definition['name']);
                    })
                    ->first();

                if ($inventory) {
                    DB::table('inventories')->where('id', $inventory->id)->update([
                        'name' => $definition['name'],
                        'sku' => $definition['sku'],
                        'unit' => 'liter',
                        'is_active' => true,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);
                    $inventoryId = $inventory->id;
                } else {
                    // Opening stock must come from a real stock count; migrations
                    // must not manufacture inventory that is not on the shelf.
                    $inventoryId = DB::table('inventories')->insertGetId([
                        'branch_id' => $service->branch_id,
                        'name' => $definition['name'],
                        'sku' => $definition['sku'],
                        'unit' => 'liter',
                        'quantity' => 0,
                        'reorder_level' => 5,
                        'unit_cost' => 95,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('service_inventory_usages')->updateOrInsert(
                    [
                        'laundry_service_id' => $service->id,
                        'inventory_id' => $inventoryId,
                    ],
                    [
                        'quantity' => 0.08,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                // These add-ons used to share the generic conditioner stock.
                // Keep that stock for Regular Laundry, but stop these selected
                // finishing sprays from consuming it as well.
                DB::table('service_inventory_usages')
                    ->where('laundry_service_id', $service->id)
                    ->whereIn('inventory_id', DB::table('inventories')
                        ->select('id')
                        ->where('branch_id', $service->branch_id)
                        ->where('sku', 'SUP-CONDITIONER'))
                    ->delete();
            });
    }

    public function down(): void
    {
        // Preserve stock and recipes because both may have been adjusted or
        // used by staff after this migration was applied.
    }
};
