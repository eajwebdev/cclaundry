<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Supplies are counted per mL: ₱0.042/mL needs four decimals (two would save 0.04, five percent short). */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        // Rounds every cost back to centavos.
        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->default(0)->change();
        });
    }
};
