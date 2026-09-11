<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.role is a MySQL ENUM, so a new role is a schema change rather than
     * just an application constant. Without this, creating a rider fails with
     * "Data truncated for column 'role'".
     *
     * Guarded by driver: SQLite (used by the test suite) stores the column as
     * text and needs nothing, and running the raw ALTER there would fail.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE users MODIFY role ENUM('super_admin','admin','branch_manager','cashier','staff','rider') NOT NULL DEFAULT 'staff'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Nothing may be left pointing at a value the column can no longer
        // hold, so demote any rider before narrowing the enum back.
        if (Schema::hasTable('users')) {
            DB::table('users')->where('role', 'rider')->update(['role' => 'staff']);
        }

        DB::statement(
            "ALTER TABLE users MODIFY role ENUM('super_admin','admin','branch_manager','cashier','staff') NOT NULL DEFAULT 'staff'"
        );
    }
};
