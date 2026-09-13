<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ إسقاط قيمة 'both' من children.preferred_time_slot:
 * الطفل الواحد يذهب لمدرسة صباحية أو مسائية فقط، ولا يوجد طفل ينتمي للفترتين
 * فعلياً؛ وجود 'both' في التعداد كان يفتح باباً لبيانات غير منطقية تُربك فلتر
 * السائقين لاحقاً. أي صفوف قائمة بالقيمة 'both' تُطبَّع إلى 'morning' كتعامل
 * محافظ (المدارس الصباحية هي الغالبية العظمى)، ثم تُقلَّص خانة enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE children SET preferred_time_slot = 'morning' WHERE preferred_time_slot = 'both'");
        DB::statement("ALTER TABLE children MODIFY COLUMN preferred_time_slot ENUM('morning','evening') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE children MODIFY COLUMN preferred_time_slot ENUM('morning','evening','both') NOT NULL");
    }
};
