<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->decimal('daily_rate', 10, 2)->nullable();
        });

        // Only the known sample rates are converted. Real daily rates are set by the owner.
        DB::table('attendance_employees')->where('username', 'like', 'demo-schedule-%')
            ->where('first_name', 'like', 'Demo %')->whereNotNull('hourly_rate')
            ->update(['daily_rate' => DB::raw('hourly_rate * 8')]);
    }

    public function down(): void
    {
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropColumn('daily_rate'));
    }
};
