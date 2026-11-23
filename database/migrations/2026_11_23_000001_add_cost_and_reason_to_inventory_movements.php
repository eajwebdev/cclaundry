<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESTORE_PREFIX = 'Auto restored for ';

    private const COLUMNS = ['unit_cost', 'change_qty', 'reason'];

    public function up(): void
    {
        // Guarded so a run interrupted after the DDL (MySQL commits it at once) can be re-run.
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_movements', 'unit_cost')) {
                $table->decimal('unit_cost', 12, 4)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('inventory_movements', 'change_qty')) {
                $table->decimal('change_qty', 12, 4)->nullable()->after('unit_cost');
            }
            if (! Schema::hasColumn('inventory_movements', 'reason')) {
                $table->string('reason', 30)->nullable()->after('change_qty');
            }
        });

        $this->backfill();
    }

    /** Fills only empty fields, so a second run, or rows the app wrote since, change nothing. */
    public function backfill(): void
    {
        DB::table('inventory_movements')->whereNull('unit_cost')->update([
            'unit_cost' => DB::raw('(select inventories.unit_cost from inventories where inventories.id = inventory_movements.inventory_id)'),
        ]);
        // Old adjustment rows stored the counted level, not the change.
        DB::table('inventory_movements')->whereNull('change_qty')->update([
            'change_qty' => DB::raw("case movement_type when 'in' then quantity when 'out' then -quantity else 0 end"),
        ]);

        DB::table('inventory_movements')->whereNull('reason')
            ->where('movement_type', 'adjustment')->where('remarks', 'Opening stock')
            ->update(['reason' => 'opening_stock']);

        DB::table('inventory_movements')
            ->whereNull('reason')
            ->where('remarks', 'like', self::RESTORE_PREFIX.'%')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $number = substr($row->remarks, strlen(self::RESTORE_PREFIX));
                    // DB::table (not the model) so soft-deleted orders are found too.
                    $status = DB::table('job_orders')->where('job_order_number', $number)->value('status');
                    // Legacy edge: a restore from an edit of an order that was cancelled later is tagged cancelled_order too.
                    DB::table('inventory_movements')->where('id', $row->id)
                        ->update(['reason' => $status === 'cancelled' ? 'cancelled_order' : 'order_edit']);
                }
            });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(self::COLUMNS, fn ($column) => Schema::hasColumn('inventory_movements', $column)));
        if ($existing) {
            Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
