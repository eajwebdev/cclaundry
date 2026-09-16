<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weight promises made on the public site: the smallest pickup and the
 * weight from which pickup and delivery is free. They were typed into the page
 * as "5 kg"; both start at 5 so nothing a customer sees changes until the
 * business edits them. Null hides the line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->decimal('booking_minimum_kilos', 6, 2)->nullable()->default(5);
            $table->decimal('free_delivery_minimum_kilos', 6, 2)->nullable()->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['booking_minimum_kilos', 'free_delivery_minimum_kilos']);
        });
    }
};
