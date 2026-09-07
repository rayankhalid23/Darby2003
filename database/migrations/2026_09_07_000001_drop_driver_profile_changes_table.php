<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف جدول driver_profile_changes.
 *
 * ⚠️ النموذج DriverProfileChange كان يحمل تعليقاً صريحاً بأن هذا الجدول
 * دُمج في driver_approvals الموحّد، ولا يوجد أي كود في المشروع يقرأ منه
 * أو يكتب فيه (تحقّقنا بحث شامل: صفر مراجع خارج ملف النموذج نفسه، وصفر
 * مسارات API تصل إليه). البيانات المتبقية فيه (إن وُجدت) غير مُستخدمة عملياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('driver_profile_changes');
    }

    public function down(): void
    {
        Schema::create('driver_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade')->onUpdate('cascade');
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('action_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->timestamp('action_at')->nullable();

            $table->index('driver_id');
            $table->index('status');
        });
    }
};
