<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول الأدوار.
 *
 * تغييران عن الجدول القديم:
 *   1. حُذف العمود permissions (JSON) — الصلاحيات صارت في جدول مستقل
 *      مرتبط عبر permission_role. السبب: قيمة واحدة لكل خانة (1NF).
 *   2. أُضيف kind للفصل بين نوع الحساب (ولي أمر/سائق) والدور الإداري،
 *      وهما كانا مختلطين في نفس العمود بشكلين غير متوافقين من JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table->string('name', 50)->unique()
                  ->comment('المفتاح البرمجي للدور: super_admin, finance_officer …');
            $table->string('display_name', 100)
                  ->comment('الاسم المعروض بالعربية');

            $table->enum('kind', ['account', 'staff'])
                  ->comment('account = نوع حساب (ولي أمر / سائق) · staff = دور إداري داخل اللوحة');

            $table->boolean('is_super')->default(false)
                  ->comment('يتجاوز كل فحوص الصلاحيات — بديل القيمة ["*"] القديمة');

            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
