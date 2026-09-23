<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * يعيد إنشاء جدولين فشل إنشاؤهما أصلاً في هجرتيهما الأصليتين لأنهما يشيران
     * بمفتاح أجنبي إلى جدول admins الذي لم يعد موجوداً (النظام موحَّد على users).
     * كلا الجدولين مسجَّلان كـ "Ran" في migrations لكنهما غير موجودين فعلياً في القاعدة.
     */
    public function up(): void
    {
        if (!Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table) {
                $table->id();

                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_admin')->default(false);
                $table->text('message');

                $table->timestamps();
            });
        }

        if (!Schema::hasTable('trip_student_attendance')) {
            Schema::create('trip_student_attendance', function (Blueprint $table) {
                $table->id();
                $table->foreignId('trip_id')->constrained('trips')->onDelete('cascade');
                $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
                $table->enum('attendance_status', ['present', 'absent', 'late'])->default('present');
                $table->timestamps();

                $table->unique(['trip_id', 'child_id']);
                $table->index('attendance_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_student_attendance');
        Schema::dropIfExists('support_ticket_messages');
    }
};
