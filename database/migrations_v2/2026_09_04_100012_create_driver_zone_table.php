<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول الربط المتعدد بين السائق والمناطق الجغرافية التي يغطيها (driver_zone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_zone', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_id')
                  ->constrained('drivers')
                  ->cascadeOnDelete();

            $table->foreignId('zone_id')
                  ->constrained('zones')
                  ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['driver_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_zone');
    }
};
