<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the pickup flow was missing between the rider's hands and the
 * counter:
 *
 *  - a tag code. The rider writes it on the bag at collection and it travels
 *    with the laundry, so a load can be matched back to one booking and one
 *    customer rather than by name and hope.
 *  - what the rider collected in payment at the door, since payment is taken
 *    on pickup. The counter needs to see it before pricing the job order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('pickup_requests', 'tag_code')) {
                $table->string('tag_code', 24)->nullable()->after('reference_no')->index();
            }

            if (! Schema::hasColumn('pickup_requests', 'collected_amount')) {
                $table->decimal('collected_amount', 12, 2)->nullable()->after('estimated_total');
            }

            if (! Schema::hasColumn('pickup_requests', 'collected_payment_method')) {
                $table->string('collected_payment_method', 20)->nullable()->after('collected_amount');
            }
        });

        Schema::table('system_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('system_settings', 'facebook_url')) {
                $table->string('facebook_url')->nullable()->after('business_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            foreach (['tag_code', 'collected_amount', 'collected_payment_method'] as $column) {
                if (Schema::hasColumn('pickup_requests', $column)) {
                    if ($column === 'tag_code') {
                        $table->dropIndex(['tag_code']);
                    }

                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('system_settings', function (Blueprint $table) {
            if (Schema::hasColumn('system_settings', 'facebook_url')) {
                $table->dropColumn('facebook_url');
            }
        });
    }
};
