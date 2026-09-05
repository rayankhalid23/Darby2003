<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول المستخدمين — المفتاح الوحيد للشخص في النظام.
 *
 * استوعب الجدولين المحذوفين:
 *   parents → is_trusted, booking_blocked
 *   admins  → created_by (مرجع ذاتي على users)
 *
 * وحُذف custom_permissions (JSON) — نفس مخالفة roles.permissions.
 * لو احتاج مشرف بعينه مجموعة صلاحيات مختلفة، يُنشأ له دور.
 *
 * وأُعيد password_hash إلى password ليطابق اصطلاح Laravel،
 * فلم تعد هناك حاجة لتجاوز getAuthPassword().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                  ->comment('دور واحد لكل مستخدم — علاقة N:1')
                  ->constrained('roles')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();   // لا يُحذف دور له مستخدمون

            $table->string('full_name', 150);
            $table->string('email', 100)->unique();
            $table->string('phone_number', 20)->unique();
            $table->string('alternative_phone', 20)->nullable();

            $table->string('password');
            $table->string('avatar_url', 500)->nullable();
            $table->enum('gender', ['male', 'female'])->nullable()
                  ->comment('صفة شخص — كانت في جدول drivers');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_trusted')->default(true)
                  ->comment('من جدول parents المحذوف');

            // المرجع الذاتي يُضاف بعد إنشاء الجدول حتى يوجد users.id فعلاً
            $table->unsignedBigInteger('created_by')->nullable()
                  ->comment('من جدول admins المحذوف — مَن أنشأ هذا الحساب');

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('created_by')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
