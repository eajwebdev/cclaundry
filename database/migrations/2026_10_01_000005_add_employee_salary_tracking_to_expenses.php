<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add employee-level salary tracking without rewriting historical expenses.
 * Existing employees receive a zero salary until the owner configures one,
 * and existing expense rows remain unassigned to an employee/pay period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->decimal('monthly_salary', 12, 2)->default(0)->after('password');
        });

        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->foreignId('attendance_employee_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('attendance_employees')
                ->nullOnDelete();
            $table->date('salary_period_start')->nullable()->after('expense_date');
            $table->date('salary_period_end')->nullable()->after('salary_period_start');
            $table->index(
                ['attendance_employee_id', 'salary_period_start', 'salary_period_end'],
                'branch_expenses_employee_salary_period_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->dropIndex('branch_expenses_employee_salary_period_index');
            $table->dropConstrainedForeignId('attendance_employee_id');
            $table->dropColumn(['salary_period_start', 'salary_period_end']);
        });

        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->dropColumn('monthly_salary');
        });
    }
};
