<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "ml" and "liter" as pricing types. The column keeps every value it
 * already allows, so no existing service changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_services', function (Blueprint $table) {
            $table->enum('pricing_type', ['kilo', 'load', 'piece', 'ml', 'liter', 'custom'])->change();
        });
    }

    public function down(): void
    {
        // Narrowing the enum would truncate any service already priced by
        // volume, so refuse rather than lose data.
        if (DB::table('laundry_services')->whereIn('pricing_type', ['ml', 'liter'])->exists()) {
            throw new RuntimeException('Services priced per ml or liter exist; change them before rolling back.');
        }

        Schema::table('laundry_services', function (Blueprint $table) {
            $table->enum('pricing_type', ['kilo', 'load', 'piece', 'custom'])->change();
        });
    }
};
