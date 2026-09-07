<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كلا العمودين كانا تحضيراً لميزة "قبول/رفض كل طفل على حدة" لم تُبنَ أبداً — لا شيء
 * بالتطبيق يقرأهما أو يكتبهما. القرار الحالي: كل أطفال الطلب يُقبلون أو يُرفضون معاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_children', function (Blueprint $table) {
            $table->dropColumn('child_status');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('children_acceptance_mode');
        });
    }

    public function down(): void
    {
        Schema::table('request_children', function (Blueprint $table) {
            $table->string('child_status', 20)->default('pending');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->enum('children_acceptance_mode', ['all', 'individual'])->default('all');
        });
    }
};
