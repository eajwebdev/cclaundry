<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional list of the branch's Titan washer numbers. Existing rows
 * stay NULL, which means every machine is a Giant; no other data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branches', 'titan_machine_numbers')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->json('titan_machine_numbers')->nullable()->after('machine_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('branches', 'titan_machine_numbers')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('titan_machine_numbers');
        });
    }
};
