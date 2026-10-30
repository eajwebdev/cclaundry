<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->string('category')->default('end_of_day');
        });
        Schema::table('money_movements', function (Blueprint $table) {
            $table->string('bank_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_tasks', fn (Blueprint $table) => $table->dropColumn('category'));
        Schema::table('money_movements', fn (Blueprint $table) => $table->dropColumn('bank_name'));
    }
};
