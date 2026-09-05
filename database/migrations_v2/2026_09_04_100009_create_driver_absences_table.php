<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول أرشيف غياب السائقين.
 * مبسط ليكون مجرد سجل تاريخي، دون الحاجة لسير عمل موافقة/رفض الإدارة المعقد،
 * ودون الحاجة لجدول الربط driver_absence_trips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_absences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_id')
                  ->constrained('drivers')
                  ->cascadeOnDelete();

            $table->date('absence_date');
            $table->string('reason', 500)->nullable();

            $table->timestamps();

            $table->unique(['driver_id', 'absence_date'], 'uq_driver_absence_date');
            $table->index(['driver_id', 'absence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_absences');
    }
};
