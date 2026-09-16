<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the category holding the extras (detergent, fabric conditioner).
 *
 * The booking form used to find it by the exact name "Add-ons", so renaming
 * the category quietly turned sachets into bookable laundry services. The
 * existing category keeps its role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_service_categories', function (Blueprint $table) {
            $table->boolean('is_addon')->default(false)->after('visibility');
        });

        DB::table('laundry_service_categories')->where('name', 'Add-ons')->update(['is_addon' => true]);
    }

    public function down(): void
    {
        Schema::table('laundry_service_categories', function (Blueprint $table) {
            $table->dropColumn('is_addon');
        });
    }
};
