<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_request_id')->nullable()->constrained('requests')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete()->cascadeOnUpdate();
            
            $table->string('route_name', 150);
            $table->enum('route_type', ['Morning', 'Afternoon']);
            $table->enum('shift_slot', ['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'])
                  ->nullable()
                  ->comment('الفترة والاتجاه الدقيق للمسار (Master Route)');
            
            $table->time('start_time')->nullable();
            $table->json('optimized_points')->nullable();
            $table->decimal('total_distance', 8, 2)->nullable();
            $table->integer('estimated_duration')->nullable()->comment('المدة التقديرية بالدقائق');
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();

            $table->index('driver_id');
            $table->index('status');
            $table->index(['driver_id', 'shift_slot', 'status'], 'idx_routes_driver_shift_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
