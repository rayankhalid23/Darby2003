<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول وثائق المركبة (بديل driver_documents).
 * ترتبط الوثائق بالمركبة مباشرة (كتيب، تأمين، فحص)، بينما رخصة السائق في جدول drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')
                  ->constrained('vehicles')
                  ->cascadeOnDelete();

            $table->enum('doc_type', ['LOGBOOK', 'INSURANCE', 'INSPECTION', 'OPERATING_PERMIT'])
                  ->comment('نوع الوثيقة: كتيب، تأمين، فحص فني، تصريح تشغيل');

            $table->string('file_url', 500);
            $table->date('expiry_date')->nullable();
            $table->boolean('is_verified')->default(false)->comment('هل الوثيقة معتمدة حالياً للتشغيل');

            $table->timestamps();

            $table->index(['vehicle_id', 'doc_type']);
            $table->index('is_verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
