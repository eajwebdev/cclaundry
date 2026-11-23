<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a run interrupted after the DDL (MySQL commits it at once) can be re-run.
        Schema::table('job_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('job_orders', 'kg_weighed')) {
                $table->decimal('kg_weighed', 8, 2)->default(0)->after('is_rush');
            }
            if (! Schema::hasColumn('job_orders', 'intake_type')) {
                $table->string('intake_type', 20)->default('walk_in')->after('kg_weighed');
            }
            if (! Schema::hasColumn('job_orders', 'return_type')) {
                $table->string('return_type', 20)->default('shop_pickup')->after('intake_type');
            }
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        // DB::table (not the model) so soft-deleted orders are backfilled too.
        DB::table('job_orders')
            ->whereIn('id', DB::table('pickup_requests')->whereNotNull('job_order_id')->select('job_order_id'))
            ->update(['intake_type' => 'house_pickup']);

        DB::table('job_orders')->where('transaction_type', 'delivery')->update(['return_type' => 'delivery']);
        // Same rule as JobOrderController::store: a booking that wants its laundry delivered.
        DB::table('job_orders')
            ->whereIn('id', DB::table('pickup_requests')->whereNotNull('job_order_id')->where('delivery_preference', 'deliver')->select('job_order_id'))
            ->update(['return_type' => 'delivery']);

        // Same rule as JobOrder::kgFromItems, summed in PHP so it runs the same on SQLite and MySQL.
        DB::table('job_orders')->orderBy('id')->chunkById(200, function ($orders) {
            $items = DB::table('job_order_items')
                ->join('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
                ->whereIn('job_order_items.job_order_id', $orders->pluck('id'))
                ->whereIn('laundry_services.pricing_type', ['kilo', 'load'])
                ->get(['job_order_items.job_order_id', 'job_order_items.quantity', 'laundry_services.pricing_type', 'laundry_services.kilos_per_load'])
                ->groupBy('job_order_id');

            foreach ($items as $orderId => $lines) {
                $kg = $lines->sum(fn ($line) => $line->pricing_type === 'kilo'
                    ? (float) $line->quantity
                    : (float) $line->quantity * (float) $line->kilos_per_load);

                DB::table('job_orders')->where('id', $orderId)->update(['kg_weighed' => round($kg, 2)]);
            }
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(['kg_weighed', 'intake_type', 'return_type'], fn ($column) => Schema::hasColumn('job_orders', $column)));
        if ($existing) {
            Schema::table('job_orders', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
