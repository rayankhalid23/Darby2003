<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول الأبناء (children) - بعد دمج جدول child_logistics بداخله.
 * يربط الابن بولي أمره ومدرسته وعنوان انطلاقه، ويشمل تفاصيل النقل والتفضيلات مباشرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                  ->comment('ولي الأمر (يشير لجدول users)')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->foreignId('school_id')
                  ->nullable()
                  ->comment('المدرسة التي يدرس بها')
                  ->constrained('schools')
                  ->nullOnDelete();

            $table->foreignId('address_id')
                  ->nullable()
                  ->comment('عنوان السكن الافتراضي للانطلاق')
                  ->constrained('addresses')
                  ->nullOnDelete();

            // البيانات الشخصية للطفل
            $table->string('full_name', 150);
            $table->date('birth_date');
            $table->enum('gender', ['male', 'female']);
            $table->unsignedTinyInteger('grade')->comment('المرحلة / الصف الدراسي');
            $table->string('photo_url', 500)->nullable();
            $table->text('medical_notes')->nullable()->comment('ملاحظات صحية خاصة بالطفل');

            // التنبيه الذكي ورمز الحضور والانصراف
            $table->integer('notification_radius')->default(500)->comment('مسافة التنبيه بالأمتار قبل وصول السائق');
            $table->string('qr_code_token', 100)->unique()->comment('رمز QR الفريد للحضور والانصراف');

            // الحقول اللوجستية المدمجة من child_logistics الملغي
            $table->enum('preferred_time_slot', ['morning', 'evening', 'both'])->default('both')->comment('الفترة المفضلة: صباحي، مسائي، كلاهما');
            $table->time('pickup_time')->nullable()->comment('وقت الانطلاق من البيت');
            $table->time('dropoff_time')->nullable()->comment('وقت النزول من المدرسة');
            $table->boolean('is_active')->default(true)->comment('حالة تفعيل الطفل للنقل');

            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('school_id');
            $table->index('address_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
