<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_request_id')->constrained('requests')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete()->cascadeOnUpdate();
            
            // مواقع الانطلاق والوصول الحالية للطفل ضمن الاشتراك
            $table->decimal('pickup_lat', 10, 8)->nullable();
            $table->decimal('pickup_lng', 11, 8)->nullable();
            $table->string('pickup_label', 255)->nullable();
            $table->decimal('dropoff_lat', 10, 8)->nullable();
            $table->decimal('dropoff_lng', 11, 8)->nullable();
            $table->string('dropoff_label', 255)->nullable();
            
            // المواعيد وترتيب الصعود في المسار
            $table->time('pickup_time')->nullable();
            $table->time('dropoff_time')->nullable();
            $table->integer('sort_order')->default(0)->comment('ترتيب صعود الطفل في خط سير السائق');
            
            $table->enum('status', ['active', 'pending', 'completed', 'cancelled', 'suspended_unpaid', 'terminated'])
                  ->default('active');
            $table->timestamps();

            // فهارس سريعة
            $table->index('subscription_request_id');
            $table->index('child_id');
            $table->index('driver_id');
            $table->index('parent_id');
            $table->index('route_id');
            $table->index('status');
            $table->index(['driver_id', 'status']);
            $table->index(['parent_id', 'status']);
            $table->index(['child_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_subscriptions');
    }
};
