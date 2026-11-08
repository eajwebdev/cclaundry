<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Z-Reading rebuild: one pass/fail result per money step (cash, GCash, deposit),
 * with the carried openings stored so the PDF and history never recalculate.
 * expected_cash_drawer_amount / actual_cash_amount / expected_gcash_amount /
 * actual_gcash_amount keep holding the cash and GCash expected/actual values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('z_readings', function (Blueprint $table) {
            $table->decimal('opening_float', 12, 2)->nullable();
            $table->decimal('cash_diff', 12, 2)->nullable();
            $table->decimal('gcash_opening', 12, 2)->nullable();
            $table->decimal('gcash_diff', 12, 2)->nullable();
            $table->decimal('gcash_transfer_amount', 12, 2)->nullable();
            $table->decimal('gcash_transfer_fee', 12, 2)->default(0);
            $table->string('gcash_transfer_ref', 50)->nullable();
            $table->decimal('bag_due', 12, 2)->nullable();
            $table->boolean('deposit_carried_over')->default(false);
            $table->decimal('deposit_slip_amount', 12, 2)->nullable();
            $table->string('deposit_slip_ref', 50)->nullable();
            $table->decimal('deposit_diff', 12, 2)->nullable();
            $table->string('status', 20)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('z_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'opening_float', 'cash_diff', 'gcash_opening', 'gcash_diff', 'gcash_transfer_amount',
                'gcash_transfer_fee', 'gcash_transfer_ref', 'bag_due', 'deposit_carried_over',
                'deposit_slip_amount', 'deposit_slip_ref', 'deposit_diff', 'status',
            ]);
        });
    }
};
