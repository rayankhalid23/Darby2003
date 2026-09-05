<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الجدول الوسيط الذي يحلّ علاقة M:N بين الأدوار والصلاحيات.
 *
 * لماذا لزم جدول ثالث؟ لأن كل طرف يرتبط بأكثر من واحد من الطرف الآخر:
 * الدور له صلاحيات كثيرة، والصلاحية الواحدة في أدوار كثيرة
 * (dashboard.view_stats عندنا في خمسة أدوار) — فلا يوجد مكان
 * يستوعب المفتاح الأجنبي، لا في roles ولا في permissions.
 *
 * الاسم permission_role لا role_permission: Laravel يستنتج اسم الـ pivot
 * من الاسمين المفردين مرتّبين أبجدياً، فتكفي belongsToMany(Permission::class)
 * بلا تمرير اسم الجدول.
 *
 * بلا عمود id — المفتاح مركّب من العمودين، وهو ما يمنع تكرار
 * نفس الارتباط بنيوياً بلا أي فحص في الكود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->cascadeOnDelete();   // حذف الدور يحذف ارتباطاته لا الصلاحيات

            $table->foreignId('permission_id')
                  ->constrained('permissions')
                  ->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);

            // لا نضيف index('permission_id') يدوياً: المفتاح المركّب يغطّي
            // البحث بـ role_id، و MySQL ينشئ فهرساً تلقائياً للمفتاح الأجنبي
            // permission_id — فيخدم سؤال «أي أدوار تملك هذه الصلاحية؟».
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
    }
};
