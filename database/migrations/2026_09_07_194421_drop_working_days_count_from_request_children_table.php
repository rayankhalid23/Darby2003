<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * القيمة كانت متطابقة لكل أطفال نفس الطلب (المدة مشتركة الآن على مستوى requests)،
 * فانتقلت إلى requests.working_days_count كمصدر وحيد؛ الحسابات المالية التي كانت
 * تقرأ نسخة كل طفل (توزيع الأمانة على الرحلات، تعويض العطل) عدّلت لتقرأ من الطلب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_children', function (Blueprint $table) {
            $table->dropColumn('working_days_count');
        });
    }

    public function down(): void
    {
        Schema::table('request_children', function (Blueprint $table) {
            $table->integer('working_days_count')->default(1);
        });

        DB::statement(<<<'SQL'
            UPDATE request_children rc
            JOIN requests r ON r.id = rc.request_id
            SET rc.working_days_count = COALESCE(r.working_days_count, 1)
        SQL);
    }
};
