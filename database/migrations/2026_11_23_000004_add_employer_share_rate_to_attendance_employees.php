<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Percent of wages covering SSS, PhilHealth and Pag-IBIG.
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->decimal('employer_share_rate', 5, 2)->default(14.50));
    }

    public function down(): void
    {
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropColumn('employer_share_rate'));
    }
};
