<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete()->cascadeOnUpdate();
            
            // تفاصيل الاشتراك الخاصة بكل طفل (تطبيع 3NF)
            $table->string('subscription_type', 50)->nullable();
            $table->string('trip_direction', 50)->nullable();
            $table->string('timing', 50)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('working_days_count')->default(1);
            $table->decimal('distance_km', 8, 2)->nullable();
            
            // لقطة (Snapshot) لعناوين وإحداثيات المنزل والمدرسة وقت تقديم الطلب
            $table->string('home_label', 255)->nullable();
            $table->decimal('home_lat', 10, 8)->nullable();
            $table->decimal('home_lng', 11, 8)->nullable();
            $table->string('school_label', 255)->nullable();
            $table->decimal('school_lat', 10, 8)->nullable();
            $table->decimal('school_lng', 11, 8)->nullable();
            
            // التفاصيل المالية الفردية للطفل
            $table->decimal('price_per_child', 10, 2)->default(0.00);
            $table->decimal('trip_price', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('total_amount_after_discount', 10, 2)->default(0.00);
            $table->decimal('driver_net_price', 10, 2)->default(0.00)->comment('صافي حصة السائق بعد خصم عمولة المنصة');
            
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('request_id');
            $table->index('child_id');
            $table->index(['request_id', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_children');
    }
};
