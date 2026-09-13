<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة قيمة 'cancelled' لعمود status، فولي الأمر يستطيع الآن إلغاء طلب معلّق
 * قبل رد السائق.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('location_change_requests')) {
            return;
        }
        DB::statement("ALTER TABLE location_change_requests MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('location_change_requests')) {
            return;
        }
        // نُحوِّل أي طلبات ملغاة إلى مرفوضة قبل إعادة تضييق الـ enum، حتى لا تسقط بيانات.
        DB::statement("UPDATE location_change_requests SET status='rejected' WHERE status='cancelled'");
        DB::statement("ALTER TABLE location_change_requests MODIFY COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
};
