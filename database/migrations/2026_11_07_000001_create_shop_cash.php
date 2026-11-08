<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('expected_float', 12, 2)->default(0);
            // Sealed bag from the previous Z, copied at opening (typed by the admin on the first day).
            $table->decimal('bag_due', 12, 2)->default(0);
            $table->json('denominations');
            $table->decimal('counted_amount', 12, 2);
            $table->decimal('difference', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamps();
            $table->unique(['branch_id', 'business_date']);
        });

        Schema::table('money_movements', function (Blueprint $table) {
            $table->decimal('bag_amount', 12, 2)->default(0);
            $table->decimal('drawer_amount', 12, 2)->default(0);
            $table->string('person_name')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
        });

        // Old bank deposits came out of the drawer, so keep that effect.
        DB::table('money_movements')->where('type', 'bank_deposit')->update(['drawer_amount' => DB::raw('amount')]);

        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->decimal('drawer_amount', 12, 2)->default(0);
            $table->decimal('gcash_amount', 12, 2)->default(0);
            $table->decimal('bank_amount', 12, 2)->default(0);
            $table->decimal('owner_amount', 12, 2)->default(0);
            $table->string('drawer_status', 20)->default('none');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('dispute_note')->nullable();
        });

        // Same buckets the old reconciliation used, so past Z-Readings recalculate unchanged.
        DB::table('branch_expenses')->where('paid_from', 'owner')->update(['owner_amount' => DB::raw('amount')]);
        DB::table('branch_expenses')->where('paid_from', 'store_cash')
            ->whereRaw("COALESCE(LOWER(payment_method), 'cash') = 'cash'")
            ->update(['drawer_amount' => DB::raw('amount'), 'drawer_status' => 'confirmed']);
        DB::table('branch_expenses')->where('paid_from', 'store_cash')
            ->whereRaw("LOWER(payment_method) = 'gcash'")
            ->update(['gcash_amount' => DB::raw('amount')]);
        DB::table('branch_expenses')->where('paid_from', 'store_cash')
            ->whereRaw("LOWER(payment_method) = 'bank'")
            ->update(['bank_amount' => DB::raw('amount')]);

        Schema::table('z_readings', function (Blueprint $table) {
            $table->decimal('float_for_tomorrow', 12, 2)->nullable();
            $table->decimal('deposit_bag_amount', 12, 2)->nullable();
            $table->foreignId('cash_opening_id')->nullable()->constrained('cash_openings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('z_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_opening_id');
            $table->dropColumn(['float_for_tomorrow', 'deposit_bag_amount']);
        });

        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['drawer_amount', 'gcash_amount', 'bank_amount', 'owner_amount', 'drawer_status', 'confirmed_at', 'dispute_note']);
        });

        Schema::table('money_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['bag_amount', 'drawer_amount', 'person_name', 'photo_path', 'voided_at', 'void_reason']);
        });

        Schema::dropIfExists('cash_openings');
    }
};
