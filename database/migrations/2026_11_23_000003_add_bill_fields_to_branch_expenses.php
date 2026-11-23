<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->string('bill_type', 20)->nullable()->after('expense_type');
            $table->date('period_start')->nullable()->after('bill_type');
            $table->date('period_end')->nullable()->after('period_start');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        // Rows that already have a bill type are left alone, so a second run changes nothing.
        DB::table('branch_expenses')
            ->whereNull('bill_type')
            ->whereIn('expense_type', ['rent', 'utilities', 'government_fees'])
            ->chunkById(200, function ($expenses) {
                foreach ($expenses as $expense) {
                    $date = Carbon::parse($expense->expense_date);
                    $text = strtolower($expense->title.' '.$expense->category);
                    $has = fn (string ...$words) => collect($words)->contains(fn ($word) => str_contains($text, $word));

                    $billType = match ($expense->expense_type) {
                        'rent' => 'rent',
                        'government_fees' => 'taxes',
                        default => match (true) {
                            $has('water') => 'water',
                            $has('lpg', 'gas') => 'lpg',
                            $has('wifi', 'internet') => 'wifi',
                            $has('phone', 'load') => 'cell_phone',
                            default => 'electricity',
                        },
                    };

                    DB::table('branch_expenses')->where('id', $expense->id)->update([
                        'bill_type' => $billType,
                        'period_start' => $date->copy()->startOfMonth()->toDateString(),
                        'period_end' => $date->copy()->endOfMonth()->toDateString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->dropColumn(['bill_type', 'period_start', 'period_end']);
        });
    }
};
