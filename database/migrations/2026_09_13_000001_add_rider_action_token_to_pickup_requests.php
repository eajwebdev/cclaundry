<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token from the rider's last accepted collect or deliver.
 *
 * A phone in a dead spot cannot tell a request that never arrived from a reply
 * that never came back, so it resends. Remembering the token of what was
 * applied lets the resend be answered "already done" instead of "that job has
 * already moved on", which is the same outcome but reads as a failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->string('rider_action_token', 64)->nullable()->after('collected_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->dropColumn('rider_action_token');
        });
    }
};
