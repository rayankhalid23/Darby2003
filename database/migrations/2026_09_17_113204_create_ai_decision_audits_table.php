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
        Schema::dropIfExists('ai_decision_audits');

        Schema::create('ai_decision_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->foreignId('review_id')->nullable()->constrained('driver_reviews')->nullOnDelete();
            $table->decimal('current_rating', 3, 2);
            $table->unsignedInteger('previous_warnings')->default(0);
            $table->unsignedInteger('trips_count')->default(0);
            $table->tinyInteger('sentiment_pred')->default(1);
            $table->decimal('sentiment_confidence', 4, 2)->default(0.00);
            $table->tinyInteger('category_pred')->default(0);
            $table->decimal('category_confidence', 4, 2)->default(0.00);
            $table->tinyInteger('decision_code');
            $table->string('decision_name', 50);
            $table->decimal('decision_confidence', 4, 2)->default(0.00);
            $table->json('probabilities')->nullable();
            $table->string('action_applied', 100);
            $table->json('action_details')->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->boolean('admin_override')->default(false);
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('driver_id');
            $table->index('decision_code');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_decision_audits');
    }
};
