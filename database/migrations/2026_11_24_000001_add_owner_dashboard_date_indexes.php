<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indexes for the owner dashboard's date filters (each table is filtered by one date column). */
return new class extends Migration
{
    /** table => [column, index name]; the names are this migration's own, so down() never drops an index made elsewhere. */
    private const INDEXES = [
        'job_orders' => ['created_at', 'job_orders_dashboard_created_at_index'],
        'inventory_movements' => ['created_at', 'inventory_movements_dashboard_created_at_index'],
        'payments' => ['paid_at', 'payments_dashboard_paid_at_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$column, $name]) {
            // Skip when any index on exactly this column exists already, so a re-run is safe.
            if (! Schema::hasIndex($table, [$column])) {
                Schema::table($table, fn (Blueprint $t) => $t->index($column, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [, $name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
