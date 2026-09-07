<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->date('date')->comment('التاريخ المحدد لهذه الخانة');
            $table->unsignedTinyInteger('booked')->default(0)->comment('المقاعد المحجوزة في هذا اليوم لهذا الـ slot');
            $table->timestamps();

            $table->unique(['driver_id', 'slot', 'date'], 'uq_driver_slot_date');
            $table->index(['driver_id', 'slot', 'date'], 'idx_driver_slot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_seat_slots');
    }
};
