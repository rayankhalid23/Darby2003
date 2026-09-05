<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول السائقين - ملف شخصي موسع لـ users (دور السائق).
 *
 * التغييرات المعمارية للتطبيع (3NF):
 *   1. حُذف is_searchable و hidden_from_search (الاعتماد على users.is_trusted و drivers.status).
 *   2. حُذف gender (تم نقله إلى جدول users الموحد).
 *   3. حُذف morning_go, morning_return, afternoon_go, afternoon_return (تدار في driver_seat_slots).
 *   4. حُذفت العدادات المحسوبة المخالفة لـ 3NF (completed_trips, total_subs, etc.).
 *   5. أُضيفت بيانات ورخصة السائق مباشرة: license_number, license_expiry, license_image_url.
 *   6. استوعب جدول driver_approvals الملغي: reviewed_by, rejection_reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->unique()
                  ->constrained('users')
                  ->cascadeOnDelete();

            // 1. الوثائق والبيانات الشخصية للسائق
            $table->string('national_id', 50)->unique()->nullable();
            $table->string('license_number', 50)->unique()->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('license_image_url', 500)->nullable();

            // 2. الحالة التشغيلية والاعتماد (بديل جدول driver_approvals)
            $table->enum('status', ['Pending', 'Approved', 'Suspended', 'Rejected', 'Offline', 'ON_TRIP'])
                  ->default('Offline');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            // 3. التفضيلات والورديات
            $table->enum('shift', ['morning', 'evening', 'both'])->default('both');
            $table->enum('subscription_type', ['daily', 'monthly', 'both'])->default('both');
            $table->enum('accepted_gender', ['male', 'female', 'both'])->default('both');
            $table->json('school_stages')->nullable()->comment('المراحل الدراسية: ابتدائي، إعدادي، ثانوي');

            // 4. الرادار والموقع اللحظي GPS
            $table->decimal('current_lat', 10, 8)->nullable();
            $table->decimal('current_lng', 11, 8)->nullable();
            $table->timestamp('last_ping_at')->nullable();

            // 5. التقييم العام (محفوظ للسرعة والفلترة)
            $table->decimal('rating_avg', 3, 2)->default(5.00);

            $table->timestamps();

            // الفهارس
            $table->index('status');
            $table->index(['current_lat', 'current_lng']);
            $table->index('rating_avg');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
