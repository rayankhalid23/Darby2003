<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول driver_profile_changes كان قد حُذف عبر
 * 2026_09_07_000001_drop_driver_profile_changes_table بحجة أنه "دُمج في
 * driver_approvals الموحّد ولا يوجد أي كود يقرأ منه أو يكتب فيه". هذا الافتراض
 * غير صحيح: DriverProfileService (updateProfile عند تغيير الاسم/الهاتف،
 * updateDriverDocuments، updateVehicleDetails) لا يزال يكتب مباشرة في
 * driver_profile_changes حتى الآن، وAdminDriverService/AdminDriverController
 * (getPendingChanges, getPendingChangeDetails, reviewProfileChangeRequest) لا
 * يزالان يقرآن منه حصرياً. نتيجة الحذف: أي طلب تعديل بيانات/وثائق/مركبة من
 * سائق يفشل فعلياً بخطأ SQL "Table doesn't exist"، وشاشة "طلبات تعديل بيانات
 * السائقين" في لوحة الأدمن فارغة دائماً رغم وجود كود كامل لها.
 *
 * هذه الهجرة تعيد إنشاء الجدول بنفس البنية الأصلية (بلا أي حذف أو مساس ببيانات
 * أخرى) ليعمل المسار الحي فعلياً، تماماً كما فعلت
 * 2026_09_08_015435_recreate_missing_trip_disputes_table مع trip_disputes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('driver_profile_changes')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profile_changes');
    }
};
