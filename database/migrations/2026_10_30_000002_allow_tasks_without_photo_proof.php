<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_task_completions', fn (Blueprint $table) => $table->string('photo_path')->nullable()->change());
    }

    public function down(): void
    {
        DB::table('daily_task_completions')->whereNull('photo_path')->update(['photo_path' => '']);
        Schema::table('daily_task_completions', fn (Blueprint $table) => $table->string('photo_path')->nullable(false)->change());
    }
};
