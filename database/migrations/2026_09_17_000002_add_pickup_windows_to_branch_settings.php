<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each branch's pickup windows, as a list of {start, end} times. Null means
 * the branch has not set its own and uses Booking::DEFAULT_PICKUP_WINDOWS, the
 * five hourly windows that used to be fixed in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_settings', function (Blueprint $table) {
            $table->json('pickup_windows')->nullable()->after('operating_hours');
        });
    }

    public function down(): void
    {
        Schema::table('branch_settings', function (Blueprint $table) {
            $table->dropColumn('pickup_windows');
        });
    }
};
