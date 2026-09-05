<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete()->cascadeOnUpdate();
            
            $table->enum('status', ['pending', 'acquired', 'accepted', 'rejected', 'cancelled'])
                  ->default('pending');
            
            $table->decimal('total_price', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('total_amount_after_discount', 10, 2)->default(0.00);
            
            $table->integer('children_count')->default(1);
            $table->enum('children_acceptance_mode', ['all', 'individual'])
                  ->default('all')
                  ->comment('all=يجب قبولهم معاً / individual=كل طفل يُقبل منفرداً');
            
            $table->time('pickup_time')->nullable();
            $table->time('dropoff_time')->nullable();
            $table->integer('max_waiting_time')->default(15);
            
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('responded_at')->nullable()->comment('لحظة قبول أو رفض السائق للطلب');
            $table->timestamps();

            // فهارس تسريع الاستعلامات
            $table->index('parent_id');
            $table->index('driver_id');
            $table->index('status');
            $table->index(['parent_id', 'status']);
            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
