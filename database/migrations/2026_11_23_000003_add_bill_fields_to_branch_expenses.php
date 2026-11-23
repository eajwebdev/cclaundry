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
        // Guarded so a run interrupted after the DDL (MySQL commits it at once) can be re-run.
        Schema::table('branch_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('branch_expenses', 'bill_type')) {
                $table->string('bill_type', 20)->nullable()->after('expense_type');
            }
            if (! Schema::hasColumn('branch_expenses', 'period_start')) {
                $table->date('period_start')->nullable()->after('bill_type');
            }
            if (! Schema::hasColumn('branch_expenses', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start');
            }
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        $bills = ['rent', 'utilities', 'government_fees'];

        // Rows that already have a bill type are left alone, so a second run changes nothing.
        // Rows from before the `operating` remap (2026_06_14_000003) keep their real type only in `category`.
        DB::table('branch_expenses')
            ->whereNull('bill_type')
            ->where(fn ($q) => $q->whereIn('expense_type', $bills)
                ->orWhereIn(DB::raw("replace(lower(trim(category)), ' ', '_')"), $bills))
            ->chunkById(200, function ($expenses) use ($bills) {
                foreach ($expenses as $expense) {
                    $type = in_array($expense->expense_type, $bills, true)
                        ? $expense->expense_type
                        : str_replace(' ', '_', strtolower(trim((string) $expense->category)));
                    $date = Carbon::parse($expense->expense_date);
                    $text = strtolower($expense->title.' '.$expense->category);
                    $has = fn (string ...$words) => collect($words)->contains(fn ($word) => str_contains($text, $word));

                    $billType = match ($type) {
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
        $existing = array_values(array_filter(['bill_type', 'period_start', 'period_end'], fn ($column) => Schema::hasColumn('branch_expenses', $column)));
        if ($existing) {
            Schema::table('branch_expenses', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
