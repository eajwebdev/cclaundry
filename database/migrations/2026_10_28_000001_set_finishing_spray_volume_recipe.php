<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only empty, unused placeholders can safely change units automatically.
        DB::table('inventories')->where('sku', 'SUP-FINISHING-SPRAY')
            ->where('unit', 'use')->where('quantity', 0)
            ->whereNotIn('id', DB::table('inventory_movements')->select('inventory_id'))
            ->update(['unit' => 'ml', 'updated_at' => now()]);

        DB::table('service_inventory_usages')
            ->whereIn('laundry_service_id', DB::table('laundry_services')->select('id')->where('report_category', 'finishing_spray'))
            ->whereIn('inventory_id', DB::table('inventories')->select('id')->where('sku', 'SUP-FINISHING-SPRAY'))
            ->update(['quantity' => 3.5, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve measured stock units and corrected consumption.
    }
};
