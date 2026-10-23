<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('driver_seat_slots', 'date') && Schema::hasColumn('driver_seat_slots', 'booked')) {
            return;
        }

        try {
            Schema::table('driver_seat_slots', function (Blueprint $table) {
                $table->dropIndex('idx_driver_slot_seats');
            });
        } catch (\Throwable $e) {}

        try {
            Schema::table('driver_seat_slots', function (Blueprint $table) {
                $table->dropUnique('uq_driver_slot');
            });
        } catch (\Throwable $e) {}

        if (!Schema::hasColumn('driver_seat_slots', 'date')) {
            Schema::table('driver_seat_slots', function (Blueprint $table) {
                // أضف عمود التاريخ
                $table->date('date')->after('slot')->nullable();
            });
        }

        // احذف كل الصفوف القديمة (reserved_seats نموذج لا يتوافق مع الجديد)
        DB::table('driver_seat_slots')->truncate();

        Schema::table('driver_seat_slots', function (Blueprint $table) {
            // الآن date غير nullable
            $table->date('date')->nullable(false)->change();

            // احذف الأعمدة القديمة
            $columnsToDrop = [];
            if (Schema::hasColumn('driver_seat_slots', 'total_seats')) {
                $columnsToDrop[] = 'total_seats';
            }
            if (Schema::hasColumn('driver_seat_slots', 'reserved_seats')) {
                $columnsToDrop[] = 'reserved_seats';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }

            // أضف عمود booked
            if (!Schema::hasColumn('driver_seat_slots', 'booked')) {
                $table->unsignedTinyInteger('booked')->default(0)->comment('مقاعد محجوزة في هذا اليوم لهذا الـ slot');
            }

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
