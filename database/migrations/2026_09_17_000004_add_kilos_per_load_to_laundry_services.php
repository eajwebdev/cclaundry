<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many kilos one load of a load-priced service holds.
 *
 * A ₱350 load takes up to 10 kg: 1 to 10 kg is one load, 11 to 20 kg is two.
 * The booking form used a fixed 7 kg for every service, which charged a 10 kg
 * bag as two loads. Null means Booking::DEFAULT_KILOS_PER_LOAD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->decimal('kilos_per_load', 6, 2)->nullable()->after('minimum_kilos');
        });
    }

    public function down(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->dropColumn('kilos_per_load');
        });
    }
};
