<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الأعمدة الثلاثة مشتقة بالكامل الآن: child_id عبر request_child_id -> request_children،
 * driver_id/parent_id عبر subscription_request_id -> requests. الموديل ActiveSubscription
 * يعوّضها بـaccessors (getChildIdAttribute/getDriverIdAttribute/getParentIdAttribute)
 * تحترمها كل علاقة belongsTo وكل eager-load قديم تلقائياً؛ فقط استعلامات SQL المباشرة
 * (::where/whereHas/pluck) كانت تحتاج تعديل يدوي، وتم قبل هذه المهاجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['child_id']);
            $table->dropForeign(['driver_id']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['child_id', 'driver_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('active_subscriptions', function (Blueprint $table) {
            $table->foreignId('child_id')->nullable()->after('request_child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->after('child_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE active_subscriptions a
            JOIN request_children rc ON rc.id = a.request_child_id
            JOIN requests r ON r.id = a.subscription_request_id
            SET a.child_id = rc.child_id,
                a.driver_id = r.driver_id,
                a.parent_id = r.parent_id
        SQL);
    }
};
