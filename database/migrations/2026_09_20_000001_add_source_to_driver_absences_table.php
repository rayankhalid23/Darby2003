<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_absences', function (Blueprint $table) {
            if (!Schema::hasColumn('driver_absences', 'source')) {
                // manual: سجّلها السائق بنفسه مسبقاً. auto_no_show: اكتشفها النظام تلقائياً
                // لعدم بدء الرحلة خلال 90 دقيقة من موعدها المحدد.
                $table->string('source', 20)->default('manual')->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('driver_absences', function (Blueprint $table) {
            if (Schema::hasColumn('driver_absences', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
