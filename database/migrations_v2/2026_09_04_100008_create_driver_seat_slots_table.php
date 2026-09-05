<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول مقاعد السائق حسب الورديات والاتجاهات (3NF).
 * يلغي أعمدة morning_go/return المكررة من جدول drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_seat_slots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_id')
                  ->constrained('drivers')
                  ->cascadeOnDelete();

            $table->enum('slot', ['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return']);
            $table->unsignedTinyInteger('total_seats')->default(0)->comment('السعة الكلية للوردية');
            $table->unsignedTinyInteger('reserved_seats')->default(0)->comment('المقاعد المحجوزة حالياً');

            $table->timestamps();

            $table->unique(['driver_id', 'slot'], 'uq_driver_seat_slot');
            $table->index(['driver_id', 'slot', 'reserved_seats'], 'idx_driver_slot_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_seat_slots');
    }
};
