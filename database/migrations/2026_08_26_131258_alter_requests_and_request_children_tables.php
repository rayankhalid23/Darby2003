<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. إزالة المفتاح الأجنبي والعمودين من جدول requests
        try {
            Schema::table('requests', function (Blueprint $table) {
                if (Schema::hasColumn('requests', 'school_id')) {
                    $table->dropForeign(['school_id']);
                }
            });
        } catch (\Exception $e) {}
        try {
            Schema::table('requests', function (Blueprint $table) {
                if (Schema::hasColumn('requests', 'school_id')) {
                    $table->dropColumn('school_id');
                }
                if (Schema::hasColumn('requests', 'timing')) {
                    $table->dropColumn('timing');
                }
            });
        } catch (\Exception $e) {}

        // 2. إضافة عمود timing إلى جدول request_children (أو الجدول المسمى لديك)
        // ملاحظة: تأكد من اسم الجدول في قاعدة البيانات إذا كان request_children أو غيره
        Schema::table('request_children', function (Blueprint $table) {
            $table->string('timing', 50)->nullable()->after('trip_direction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('timing', 50)->nullable();
        });

        Schema::table('request_children', function (Blueprint $table) {
            $table->dropColumn('timing');
        });
    }
};