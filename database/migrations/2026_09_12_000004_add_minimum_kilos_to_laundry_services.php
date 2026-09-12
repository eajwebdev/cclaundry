<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A minimum charge for the services sold by weight. Until now "Minimum order
 * of 5 kg" lived only in the blurb a customer reads, so a 3 kg booking was
 * quoted 3 x the kilo rate and the counter had to correct it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->decimal('minimum_kilos', 8, 2)->nullable()->after('price');
        });

        // The minimums already exist as prose. Lift them out rather than
        // asking anyone to retype what the cards have been promising.
        foreach (DB::table('laundry_services')->whereNotNull('landing_blurb')->get() as $service) {
            if (preg_match('/minimum[^0-9]{0,20}([0-9]+(?:\.[0-9]+)?)\s*kg/i', $service->landing_blurb, $match)) {
                DB::table('laundry_services')
                    ->where('id', $service->id)
                    ->update(['minimum_kilos' => (float) $match[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->dropColumn('minimum_kilos');
        });
    }
};
