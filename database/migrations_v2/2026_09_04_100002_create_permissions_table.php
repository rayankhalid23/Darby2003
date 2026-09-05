<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول الصلاحيات — الكيان الذي كان محشوراً داخل roles.permissions كـ JSON.
 *
 * key هو نفس النص المستعمل في الوسيط: middleware('permission:financial.view_ledger')
 * ونفس ثوابت App\Constants\PermissionConstants — الصنف يبقى مصدر المبرمج،
 * وهذا الجدول مصدر وقت التشغيل، ويربطهما PermissionSeeder.
 *
 * ملاحظة: key كلمة محجوزة في MySQL. Laravel يقتبسها تلقائياً في الـ Blueprint
 * وفي Eloquent، لكن أي DB::raw مكتوب يدوياً يحتاج backticks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            $table->string('key', 100)->unique()
                  ->comment('المفتاح البرمجي: financial.view_ledger');
            $table->string('group_key', 50)
                  ->comment('مجموعة العرض في واجهة إدارة الصلاحيات: financial');

            $table->string('name_ar', 150);
            $table->text('description_ar')->nullable();

            $table->timestamps();

            $table->index('group_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
