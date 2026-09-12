<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the customer intends to pay, chosen when they book.
 *
 * Distinct from `collected_payment_method`, which is what the rider actually
 * took at the door: this one lets the rider set off knowing whether to expect
 * cash or a GCash transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('cash')->after('is_rush');
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
