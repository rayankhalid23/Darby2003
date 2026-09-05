<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول المركبات.
 * المركبة تتبع السائق، وتحدد السعة والمواصفات الفنية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_id')
                  ->constrained('drivers')
                  ->cascadeOnDelete();

            $table->string('plate_number', 20)->unique();
            $table->string('brand', 50);
            $table->string('model', 50);
            $table->year('year');
            $table->string('color', 30);
            $table->enum('type', ['Bus', 'Sedan', 'Van'])->default('Bus');
            $table->unsignedSmallInteger('capacity_manual')->comment('السعة الركابية القصوى للطلاب');
            $table->boolean('has_ac')->default(true);
            $table->enum('status', ['Active', 'Maintenance', 'Retired'])->default('Active')->comment('الحالة التشغيلية للمركبة');
            $table->string('vehicle_image_url', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('driver_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
