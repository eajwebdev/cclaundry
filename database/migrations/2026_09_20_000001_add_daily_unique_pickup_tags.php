<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->date('tag_date')->nullable()->after('tag_code');
            $table->unique(['tag_code', 'tag_date'], 'pickup_requests_daily_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->dropUnique('pickup_requests_daily_tag_unique');
            $table->dropColumn('tag_date');
        });
    }
};
