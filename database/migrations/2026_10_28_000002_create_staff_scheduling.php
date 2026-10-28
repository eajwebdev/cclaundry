<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->string('schedule_role', 30)->default('all_around');
        });
        Schema::create('schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('opens_at')->default('08:00');
            $table->time('closes_at')->default('19:00');
            $table->unsignedSmallInteger('minimum_staff')->default(2);
            $table->unsignedSmallInteger('minimum_riders')->default(0);
            $table->unsignedSmallInteger('early_minutes')->default(15);
            $table->unsignedSmallInteger('grace_minutes')->default(5);
            $table->unsignedSmallInteger('overtime_minutes')->default(15);
            $table->timestamps();
        });
        Schema::create('schedule_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('template', 30);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->time('break_starts_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->text('note')->nullable();
            $table->boolean('overtime_confirmed')->default(false);
            $table->timestamp('published_at')->nullable();
            // Staff retain their last published plan while managers edit a draft.
            $table->json('published_data')->nullable();
            $table->timestamps();
            $table->unique(['attendance_employee_id', 'work_date']);
            $table->index(['branch_id', 'work_date']);
        });
        Schema::create('schedule_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('schedule_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->timestamps();
        });
        Schema::create('schedule_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_employee_id')->constrained()->cascadeOnDelete();
            $table->string('deduplication_key')->unique();
            $table->string('title');
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::table('employee_attendance_records', function (Blueprint $table) {
            $table->json('schedule_flags')->nullable();
        });
        Schema::create('attendance_punch_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('attendance_employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_attendance_record_id')->constrained()->cascadeOnDelete();
            $table->string('action', 10);
            $table->timestamp('captured_at');
            $table->timestamps();
        });
        DB::table('users')->whereIn('role', ['admin', 'branch_manager'])->orderBy('id')->each(function ($user) {
            $access = json_decode($user->access ?? '[]', true) ?: [];
            if (in_array('attendance', $access, true) && ! in_array('scheduling', $access, true)) {
                $access[] = 'scheduling';
                DB::table('users')->where('id', $user->id)->update(['access' => json_encode($access)]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punch_events');
        Schema::table('employee_attendance_records', fn (Blueprint $table) => $table->dropColumn('schedule_flags'));
        foreach (['schedule_notices', 'schedule_holidays', 'schedule_leave_requests', 'schedule_shifts', 'schedule_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropColumn(['hourly_rate', 'schedule_role']));
    }
};
