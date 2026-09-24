<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $skus = ['SUP-FABCON-MYSTIQUE', 'SUP-FABCON-SUNRISE'];

        DB::table('inventories')
            ->whereIn('sku', $skus)
            ->update([
                'unit' => 'sachet',
                'reorder_level' => 10,
                'updated_at' => $now,
            ]);

        DB::table('service_inventory_usages')
            ->whereIn('inventory_id', DB::table('inventories')
                ->select('id')
                ->whereIn('sku', $skus))
            ->update([
                'quantity' => 1,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Preserve the sachet-based fabcon stock counts and recipes recorded by staff.
    }
};
