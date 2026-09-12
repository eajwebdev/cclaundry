<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An override for the unit a price is quoted in.
 *
 * Steaming is priced per item but sold per pair, and "per piece" is wrong on
 * the price list. The pricing type still drives the billing; this only changes
 * the words next to the amount, so shoe cleaning can stay "per piece" while
 * uniforms read "per pair".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->string('price_unit_label', 40)->nullable()->after('minimum_kilos');
        });
    }

    public function down(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->dropColumn('price_unit_label');
        });
    }
};
