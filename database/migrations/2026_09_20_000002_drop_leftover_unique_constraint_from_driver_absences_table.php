<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ⚠️ الهجرة 2026_08_30_000003 حاولت حذف القيد الفريد (driver_id, absence_date) عبر
     * dropUnique(['driver_id', 'absence_date'])، لكن هذا يستهدف الاسم التقليدي
     * (driver_absences_driver_id_absence_date_unique)، بينما القيد الفعلي في قاعدة البيانات
     * اسمه uq_driver_absence_date — فالحذف فشل بصمت (التقط الخطأ try/catch الخاص به) وبقي
     * القيد فعلياً حتى الآن. النتيجة: أي سائق يسجّل غياباً لرحلتين مختلفتين في نفس اليوم
     * (يدوياً أو تلقائياً) يصطدم بخطأ Duplicate entry، رغم أن الهدف المعلن صراحة في تلك
     * الهجرة كان السماح بهذا بالضبط.
     */
    public function up(): void
    {
        Schema::table('driver_absences', function (Blueprint $table) {
            try {
                $table->dropUnique('uq_driver_absence_date');
            } catch (\Throwable $e) {
                // تم حذفه مسبقاً أو غير موجود بهذا الاسم
            }
        });
    }

    public function down(): void
    {
        // لا تُعاد استعادة القيد الفريد عمداً: الهدف المعلن هو السماح لسائق واحد بتسجيل
        // أكثر من غياب في نفس اليوم لرحلات مختلفة.
    }
};
