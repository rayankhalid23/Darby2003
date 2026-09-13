<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول complaints كان موجوداً فعلياً في قواعد البيانات التي طُبّقت عليها هجرات
 * 2026_07_08_000002_alter_complaints_table و2026_08_12_000001_add_ai_columns_to_complaints_table
 * و2026_08_28_050000_fix_complaints_status_column (وكلها معدَّلة على جدول قائم، بلا أي
 * Schema::create مقابل). أي قاعدة بيانات جديدة يُشغَّل عليها migrate من الصفر لا تملك هذا
 * الجدول إطلاقاً رغم أن الكود الحي (ComplaintController/ComplaintService/Complaint model) يعتمد
 * عليه بالكامل. هذه الهجرة تنشئه بالشكل النهائي الذي تفترضه الهجرات الثلاث أعلاه (وكلها مسجَّلة
 * كمنفَّذة مسبقاً ولن تُعاد)، بدل أن تُنشئه بشكله الأصلي القديم ثم تعتمد على تلك الهجرات لتحديثه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->string('against_type');
            $table->unsignedBigInteger('against_id');
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->text('description');
            $table->string('status', 50)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->string('action_taken', 50)->default('none');
            $table->text('action_details')->nullable();
            // نتيجة التصنيف الآلي: suspend_driver, notify_driver, log_only, no_action
            $table->string('ai_action', 30)->nullable();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->unsignedTinyInteger('ai_severity')->nullable();
            $table->text('ai_analysis_message')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('submitted_by');
            $table->index('driver_id');
            $table->index('status');
            $table->index('action_taken');
            $table->index('ai_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
