<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->date('visited_on');
            $table->char('visitor_hash', 64);
            $table->string('path', 255)->default('/');
            $table->string('referrer_host')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->timestamps();

            $table->unique(['visited_on', 'visitor_hash'], 'site_visits_daily_visitor_unique');
            $table->index(['visited_on', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
