<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_seat_slots', function (Blueprint $table) {
            // أولاً: أسقط الفهارس القديمة والـ unique constraint
            $table->dropIndex('idx_driver_slot_seats');
            $table->dropUnique('uq_driver_slot');

            // أضف عمود التاريخ
            $table->date('date')->after('slot')->nullable();
        });

        // احذف كل الصفوف القديمة (reserved_seats نموذج لا يتوافق مع الجديد)
        DB::table('driver_seat_slots')->truncate();

        Schema::table('driver_seat_slots', function (Blueprint $table) {
            // الآن date غير nullable
            $table->date('date')->nullable(false)->change();

            // احذف الأعمدة القديمة
            $table->dropColumn(['total_seats', 'reserved_seats']);

            // أضف عمود booked
            $table->unsignedTinyInteger('booked')->default(0)->comment('مقاعد محجوزة في هذا اليوم لهذا الـ slot');

            // unique جديد
            $table->unique(['driver_id', 'slot', 'date'], 'uq_driver_slot_date');
            $table->index(['driver_id', 'slot', 'date'], 'idx_driver_slot_date');
        });
    }

    public function down(): void
    {
        Schema::table('driver_seat_slots', function (Blueprint $table) {
            $table->dropUnique('uq_driver_slot_date');
            $table->dropIndex('idx_driver_slot_date');
            $table->dropColumn(['date', 'booked']);

            $table->unsignedTinyInteger('total_seats')->default(0);
            $table->unsignedTinyInteger('reserved_seats')->default(0);
            $table->unique(['driver_id', 'slot'], 'uq_driver_slot');
            $table->index(['driver_id', 'slot', 'reserved_seats'], 'idx_driver_slot_seats');
        });
    }
};
