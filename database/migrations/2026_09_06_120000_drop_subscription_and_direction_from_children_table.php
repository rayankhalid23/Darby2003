<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إزالة عمودَي subscription_type و trip_direction من جدول children.
 *
 * نوع الاشتراك واتجاه الرحلة يخصّان الطلب لا الطفل، وهما مخزّنان أصلاً في
 * request_children (لكل طفل داخل الطلب)، فوجودهما على children كان تكراراً
 * لا يُقرأ إلا كقيمة افتراضية قديمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            if (Schema::hasColumn('children', 'subscription_type')) {
                $table->dropColumn('subscription_type');
            }

            if (Schema::hasColumn('children', 'trip_direction')) {
                $table->dropColumn('trip_direction');
            }
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            if (!Schema::hasColumn('children', 'trip_direction')) {
                $table->enum('trip_direction', ['go', 'return', 'both'])
                      ->default('both')
                      ->after('dropoff_time')
                      ->comment('اتجاه الرحلة: ذهاب، عودة، كلاهما');
            }

            if (!Schema::hasColumn('children', 'subscription_type')) {
                $table->enum('subscription_type', ['daily', 'monthly', 'seasonal', 'single_day', 'multi_day'])
                      ->default('multi_day')
                      ->after('trip_direction')
                      ->comment('نوع الاشتراك المفضل');
            }
        });
    }
};
