<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers become login-capable so they can book a pickup themselves.
        // Staff-created walk-in customers keep a null password until the person
        // claims the record from the public site.
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'password')) {
                $table->string('password')->nullable()->after('address');
            }

            if (! Schema::hasColumn('customers', 'remember_token')) {
                $table->rememberToken()->after('password');
            }

            if (! Schema::hasColumn('customers', 'registered_at')) {
                $table->timestamp('registered_at')->nullable()->after('remember_token');
            }

            if (! Schema::hasColumn('customers', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('registered_at');
            }
        });

        if (! Schema::hasTable('pickup_requests')) {
            Schema::create('pickup_requests', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('laundry_service_id')->nullable()->constrained('laundry_services')->nullOnDelete();

                $table->string('service_type')->default('wash_dry_fold');
                $table->decimal('estimated_kilos', 8, 2)->nullable();

                $table->string('contact_name');
                $table->string('contact_phone');
                $table->string('contact_email')->nullable();

                $table->text('pickup_address');
                $table->string('pickup_landmark')->nullable();
                $table->date('pickup_date');
                $table->string('pickup_slot')->default('morning');

                // 'deliver' returns the laundry to the customer, 'branch_pickup'
                // means they will collect it at the branch themselves.
                $table->string('delivery_preference')->default('deliver');
                $table->text('delivery_address')->nullable();
                $table->date('delivery_date')->nullable();
                $table->string('delivery_slot')->nullable();

                $table->boolean('is_rush')->default(false);
                $table->text('notes')->nullable();
                $table->decimal('estimated_total', 12, 2)->nullable();

                $table->string('status')->default('pending');
                $table->foreignId('job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();
                $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'status']);
                $table->index(['customer_id', 'status']);
                $table->index('pickup_date');
            });
        }

        // Retune the stored theme colour to the Cane & Cotton logo brown for
        // installs that still carry the old Spin Klean green.
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('primary_color', '#2E7D32')
                ->update(['primary_color' => '#A07148']);
        }

        // Anyone who already handles job orders should see the online bookings
        // that turn into them, otherwise the new screen ships invisible.
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->select('id', 'access')
                ->whereNotNull('access')
                ->orderBy('id')
                ->chunk(200, function ($users) {
                    foreach ($users as $user) {
                        $access = json_decode((string) $user->access, true);

                        if (! is_array($access)
                            || ! in_array('job_orders', $access, true)
                            || in_array('pickup_requests', $access, true)) {
                            continue;
                        }

                        $access[] = 'pickup_requests';

                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['access' => json_encode(array_values($access))]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_requests');

        Schema::table('customers', function (Blueprint $table) {
            foreach (['last_login_at', 'registered_at', 'remember_token', 'password'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
