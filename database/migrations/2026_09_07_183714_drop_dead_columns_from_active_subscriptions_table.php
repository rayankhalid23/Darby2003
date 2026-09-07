<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * start_date/end_date/school_id ليست في ActiveSubscription::$fillable ولا يقرأها أي كود
 * عبر Eloquent (فقط 2026_09_07_000004 عبّأتها بـ SQL خام). القيمة الحية الفعلية دائماً
 * تُقرأ من subscriptionRequest أو request_children عبر request_child_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn(['start_date', 'end_date', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::table('active_subscriptions', function (Blueprint $table) {
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('school_id')->nullable()->after('route_id')->constrained('schools')->nullOnDelete()->cascadeOnUpdate();
        });

        DB::statement(<<<'SQL'
            UPDATE active_subscriptions a
            JOIN request_children rc ON rc.id = a.request_child_id
            SET a.start_date = rc.start_date,
                a.end_date   = rc.end_date,
                a.school_id  = rc.school_id
            WHERE a.request_child_id IS NOT NULL
        SQL);
    }
};
