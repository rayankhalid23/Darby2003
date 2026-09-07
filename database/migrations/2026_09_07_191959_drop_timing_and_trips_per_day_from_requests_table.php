<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كلا العمودين ميتان فعلياً: لا يكتبهما أي كود حي (createRequest لا يكتب فيهما)،
 * و trips_per_day لا يقرأه أي كود إطلاقاً؛ timing تُقرأ فقط كـfallback بعد
 * request_children.timing (المصدر الحقيقي لكل طفل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['timing', 'trips_per_day']);
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('timing', 20)->nullable();
            $table->tinyInteger('trips_per_day')->unsigned()->nullable();
        });
    }
};
