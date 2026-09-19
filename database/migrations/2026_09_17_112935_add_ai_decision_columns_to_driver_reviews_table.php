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
        Schema::table('driver_reviews', function (Blueprint $table) {
            $table->tinyInteger('ai_sentiment_pred')->nullable()->after('ai_classified_at');
            $table->decimal('ai_sentiment_confidence', 4, 2)->nullable()->after('ai_sentiment_pred');
            $table->tinyInteger('ai_category_pred')->nullable()->after('ai_sentiment_confidence');
            $table->decimal('ai_category_confidence', 4, 2)->nullable()->after('ai_category_pred');
            $table->tinyInteger('ai_decision_code')->nullable()->after('ai_category_confidence');
            $table->decimal('ai_decision_confidence', 4, 2)->nullable()->after('ai_decision_code');
            $table->boolean('is_processed_in_decision')->default(false)->after('ai_decision_confidence');
            $table->boolean('is_flagged_malicious')->default(false)->after('is_processed_in_decision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_reviews', function (Blueprint $table) {
            $table->dropColumn([
                'ai_sentiment_pred',
                'ai_sentiment_confidence',
                'ai_category_pred',
                'ai_category_confidence',
                'ai_decision_code',
                'ai_decision_confidence',
                'is_processed_in_decision',
                'is_flagged_malicious',
            ]);
        });
    }
};
