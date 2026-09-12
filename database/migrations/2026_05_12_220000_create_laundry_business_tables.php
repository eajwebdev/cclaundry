<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();

                // Customer portal login. Null for walk-in records created at the
                // counter, until the person claims the record by signing up.
                $table->string('password')->nullable();
                $table->rememberToken();
                $table->timestamp('registered_at')->nullable();
                $table->timestamp('last_login_at')->nullable();

                $table->enum('billing_type', ['regular', 'po', 'monthly_billing'])->default('regular');
                $table->decimal('credit_limit', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'billing_type']);
            });
        }

        if (! Schema::hasTable('laundry_services')) {
            Schema::create('laundry_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('name');
                $table->enum('pricing_type', ['kilo', 'load', 'piece', 'custom']);
                $table->decimal('price', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);

                // Services pinned here are what customers can book on the public
                // landing page, at the price above. The blurb and icon exist
                // because an internal name ("Wash 7kg") rarely reads as copy.
                $table->boolean('show_on_landing')->default(false)->index();
                $table->string('landing_blurb')->nullable();
                $table->string('landing_icon', 40)->nullable();
                $table->unsignedInteger('landing_sort_order')->default(0);

                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('job_orders')) {
            Schema::create('job_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('job_order_number')->unique();
                $table->enum('status', ['pending', 'washing', 'drying', 'folding', 'ready_for_pickup', 'completed', 'cancelled'])->default('pending');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('tax', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('paid_amount', 12, 2)->default(0);
                $table->decimal('balance', 12, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'status']);
            });
        }

        if (! Schema::hasTable('job_order_items')) {
            Schema::create('job_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
                $table->foreignId('laundry_service_id')->nullable()->constrained('laundry_services')->nullOnDelete();
                $table->string('description');
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->text('instructions')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cycle_records')) {
            Schema::create('cycle_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('cycle_type', ['wash', 'dry', 'fold', 'iron']);
                $table->unsignedInteger('machine_number')->nullable();
                $table->unsignedInteger('cycle_number')->default(1);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('payment_number')->unique();
                $table->enum('payment_type', ['cash', 'gcash', 'bank', 'credit', 'po', 'monthly_billing']);
                $table->decimal('amount', 12, 2);
                $table->text('remarks')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_ledgers')) {
            Schema::create('customer_ledgers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->enum('entry_type', ['debit', 'credit']);
                $table->decimal('amount', 12, 2);
                $table->decimal('running_balance', 12, 2)->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('contact_number')->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('inventories')) {
            Schema::create('inventories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->string('name');
                $table->string('sku')->nullable();
                $table->string('unit')->default('pcs');
                $table->decimal('quantity', 12, 2)->default(0);
                $table->decimal('reorder_level', 12, 2)->default(0);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('inventory_movements')) {
            Schema::create('inventory_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('movement_type', ['in', 'out', 'adjustment']);
                $table->decimal('quantity', 12, 2);
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attendances')) {
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('work_date');
                $table->timestamp('time_in')->nullable();
                $table->timestamp('time_out')->nullable();
                $table->enum('status', ['present', 'late', 'absent', 'leave'])->default('present');
                $table->timestamps();

                $table->unique(['user_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('payrolls')) {
            Schema::create('payrolls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('gross_pay', 12, 2)->default(0);
                $table->decimal('deductions', 12, 2)->default(0);
                $table->decimal('net_pay', 12, 2)->default(0);
                $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_logs')) {
            Schema::create('sms_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('recipient');
                $table->text('message');
                $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
                $table->text('response')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('action');
                $table->string('subject_type')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->json('properties')->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
            });
        }

        // Pickup & delivery bookings made by customers on the public site.
        // Staff confirm these and turn them into job orders at the counter.
        if (! Schema::hasTable('pickup_requests')) {
            Schema::create('pickup_requests', function (Blueprint $table) {
                $table->id();
                $table->string('reference_no')->unique();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

                // The service booked, plus a snapshot of it: a later rename or
                // price change must not rewrite an existing booking.
                $table->foreignId('laundry_service_id')->nullable()->constrained('laundry_services')->nullOnDelete();
                $table->string('service_name')->nullable();
                $table->decimal('service_price', 12, 2)->nullable();
                $table->string('service_pricing_type', 20)->nullable();
                $table->decimal('estimated_kilos', 8, 2)->nullable();

                $table->string('contact_name');
                $table->string('contact_phone');
                $table->string('contact_email')->nullable();

                $table->text('pickup_address');
                $table->string('pickup_landmark')->nullable();
                $table->date('pickup_date');
                $table->string('pickup_slot')->default('08_09');

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
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_requests');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customer_ledgers');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cycle_records');
        Schema::dropIfExists('job_order_items');
        Schema::dropIfExists('job_orders');
        Schema::dropIfExists('laundry_services');
        Schema::dropIfExists('customers');
    }
};
