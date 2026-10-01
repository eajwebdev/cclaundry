<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds optional dosing configuration without changing any existing service,
 * recipe, stock quantity, movement, booking, or job-order record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->string('dosing_profile', 40)->nullable()->after('kilos_per_load');
        });
    }

    public function down(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->dropColumn('dosing_profile');
        });
    }
};
