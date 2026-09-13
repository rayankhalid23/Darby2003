<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدة نتيجة التصنيف الآلي من خدمة FastAPI (POST /classify) على تعليقات الأولياء.
 * ai_severity موجود من قبل ويُعاد استعماله (0|1|2). الجديد: ai_label + ai_category
 * + وسم زمن التصنيف لعزل التعليقات المصنَّفة عن غير المصنَّفة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('driver_reviews', 'ai_label')) {
                $table->string('ai_label', 20)->nullable()->after('ai_analysis_message')
                      ->comment('Positive|Negative|Neutral|Mixed|Irrelevant');
            }

            if (!Schema::hasColumn('driver_reviews', 'ai_category')) {
                $table->string('ai_category', 30)->nullable()->after('ai_label')
                      ->comment('Safety|Punctuality|Behavior|Vehicle_Condition|General|Off_Topic');
            }

            if (!Schema::hasColumn('driver_reviews', 'ai_classified_at')) {
                $table->timestamp('ai_classified_at')->nullable()->after('ai_category');
            }
        });

        Schema::table('driver_reviews', function (Blueprint $table) {
            $table->index(['driver_id', 'ai_label'], 'driver_reviews_driver_label_idx');
            $table->index(['driver_id', 'ai_category', 'ai_severity'], 'driver_reviews_driver_cat_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::table('driver_reviews', function (Blueprint $table) {
            $table->dropIndex('driver_reviews_driver_label_idx');
            $table->dropIndex('driver_reviews_driver_cat_sev_idx');
            $table->dropColumn(['ai_label', 'ai_category', 'ai_classified_at']);
        });
    }
};
