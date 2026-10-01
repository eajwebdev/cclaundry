<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the cash physically handed to the cashier separately from the
 * amount applied to the order. Existing payments remain unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tendered_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('change_amount', 12, 2)->default(0)->after('tendered_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['tendered_amount', 'change_amount']);
        });
    }
};
