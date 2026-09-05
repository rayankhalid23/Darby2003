<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول اعتمادات السائقين - يسجل قرارات القبول والرفض التاريخية للمشرفين.
 * 
 * في V2: تم توجيه admin_id ليشير إلى جدول users الموحد بدلاً من جدول admins الملغي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_id')
                  ->constrained('drivers')
                  ->cascadeOnDelete();

            $table->enum('request_type', ['Registration', 'ProfileChange', 'VehicleUpdate'])
                  ->default('Registration')
                  ->comment('نوع الطلب: تسجيل جديد، تعديل بيانات، تحديث مركبة');

            $table->enum('status', ['Pending', 'Approved', 'Rejected'])
                  ->default('Pending')
                  ->comment('حالة القرار');

            $table->json('old_values')->nullable()->comment('البيانات القديمة قبل التعديل');
            $table->json('new_values')->nullable()->comment('البيانات المقترحة أو المرفوعة');

            $table->foreignId('admin_id')
                  ->nullable()
                  ->comment('المشرف المسؤول عن القرار — يشير لجدول users')
                  ->constrained('users')
                  ->nullOnDelete();

            $table->text('rejection_reason')->nullable()->comment('سبب الرفض في حال رفض الطلب');
            $table->timestamp('reviewed_at')->nullable()->comment('وقت اتخاذ القرار');

            $table->timestamps();

            $table->index('driver_id');
            $table->index('status');
            $table->index('request_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_approvals');
    }
};
