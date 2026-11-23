<?php

use App\Models\JobOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESTORE_PREFIX = 'Auto restored for ';

    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 4)->nullable()->after('quantity');
            $table->decimal('change_qty', 12, 4)->nullable()->after('unit_cost');
            $table->string('reason', 30)->nullable()->after('change_qty');
        });

        DB::table('inventory_movements')->update([
            'unit_cost' => DB::raw('(select inventories.unit_cost from inventories where inventories.id = inventory_movements.inventory_id)'),
            'change_qty' => DB::raw("case movement_type when 'in' then quantity when 'out' then -quantity else 0 end"),
        ]);

        DB::table('inventory_movements')
            ->where('remarks', 'like', self::RESTORE_PREFIX.'%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $number = substr($row->remarks, strlen(self::RESTORE_PREFIX));
                    $status = JobOrder::withTrashed()->where('job_order_number', $number)->value('status');
                    DB::table('inventory_movements')->where('id', $row->id)
                        ->update(['reason' => $status === 'cancelled' ? 'cancelled_order' : 'order_edit']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'change_qty', 'reason']);
        });
    }
};
