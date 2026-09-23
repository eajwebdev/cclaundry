<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('laundry_services')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', ['regular laundry'])
            ->orderBy('id')
            ->each(function ($service) use ($now): void {
                $inventory = DB::table('inventories')
                    ->where('branch_id', $service->branch_id)
                    ->whereNull('deleted_at')
                    ->whereIn('sku', ['SUP-DETERGENT', 'SUP-CONDITIONER'])
                    ->get()
                    ->keyBy('sku');

                foreach (['SUP-DETERGENT' => 0.04, 'SUP-CONDITIONER' => 0.02] as $sku => $quantity) {
                    $stock = $inventory->get($sku);

                    if (! $stock) {
                        continue;
                    }

                    DB::table('service_inventory_usages')->updateOrInsert(
                        [
                            'laundry_service_id' => $service->id,
                            'inventory_id' => $stock->id,
                        ],
                        [
                            'quantity' => $quantity,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        // Preserve inventory recipes on rollback: these rows may have been
        // adjusted by staff after the migration was applied.
    }
};
