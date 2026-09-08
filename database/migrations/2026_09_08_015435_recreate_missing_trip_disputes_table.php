<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول trip_disputes كان جزءاً من migration 2026_08_08_000001_create_financial_ledger_tables
 * (نفّذت بنجاح فعلاً) لكنه غير موجود حالياً بقاعدة التطوير — على الأرجح حُذف يدوياً
 * خارج نطاق الـ migrations المتتبَّعة. هذا يكسر /api/admin/financial/summary وكل
 * مسارات النزاعات المالية (disputesList, disputeDetail, resolveDispute).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('trip_disputes')) {
            Schema::create('trip_disputes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
                $table->unsignedBigInteger('parent_id')->index();
                $table->unsignedBigInteger('driver_id')->index();
                $table->text('reason');
                $table->string('status')->default('open')->index();
                $table->text('resolution_notes')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_disputes');
    }
};
