<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول trip_tracking غير موجود أصلاً بقاعدة البيانات رغم أن الموديل والخدمة
 * (TripTrackingService::updateDriverLocation) وميغريشن تعديل لاحقة (2026_07_08_110235)
 * تفترض جميعها وجوده — على الأرجح كانت ميغريشن الإنشاء الأصلية حُذفت من مجلد
 * migrations بعد تسجيلها كمنفَّذة، ثم أُسقط الجدول لاحقاً فلم يُعَد إنشاؤه أبداً.
 * الأثر الفعلي المُتحقَّق منه: كل نداء POST /trips/{tripId}/location (التتبع
 * الحي أثناء الرحلة) وكل استدعاء لإنهاء رحلة (حساب المسافة المقطوعة) يفشلان
 * بخطأ SQL "Base table or view not found" فوراً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_tracking')) {
            return;
        }

        Schema::create('trip_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->onDelete('cascade');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed', 5, 2)->nullable();
            $table->decimal('accuracy', 5, 2)->nullable();
            $table->timestamp('recorded_at');

            $table->index(['trip_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_tracking');
    }
};
