<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('active_subscription_id')->constrained('active_subscriptions')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete()->cascadeOnUpdate();
            
            $table->enum('point_type', ['pickup', 'dropoff']);
            $table->date('change_date')->nullable();
            $table->boolean('is_single_day')->default(true);
            
            $table->foreignId('new_address_id')->nullable()->constrained('addresses')->nullOnDelete()->cascadeOnUpdate();
            $table->decimal('new_lat', 10, 8);
            $table->decimal('new_lng', 11, 8);
            $table->string('new_label', 255)->nullable();
            
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->string('fee_tier', 20)->nullable();
            $table->decimal('fee_amount', 8, 2)->default(5.00);
            $table->decimal('commission_rate', 5, 2)->default(0.00);
            $table->decimal('platform_commission_amount', 8, 2)->default(0.00);
            $table->decimal('driver_net_fee', 8, 2)->default(0.00);
            
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->boolean('is_settled')->default(false);
            $table->timestamps();

            // فهارس تسريع الاستعلامات
            $table->index(['driver_id', 'status']);
            $table->index(['parent_id', 'status']);
            $table->index(['active_subscription_id', 'change_date', 'status'], 'lcr_sub_date_status_idx');
            $table->index(['child_id', 'change_date', 'status'], 'lcr_child_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_change_requests');
    }
};
