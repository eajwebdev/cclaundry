<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional receipt/bill photo to expenses. Existing expenses stay
 * NULL (no receipt); no other data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branch_expenses', 'receipt_path')) {
            return;
        }

        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('branch_expenses', 'receipt_path')) {
            return;
        }

        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });
    }
};
