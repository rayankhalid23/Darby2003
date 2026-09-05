<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول العناوين الموحد (addresses).
 * يدمج عناوين أولياء الأمور وعناوين السائقين (driver_addresses الملغي) في جدول واحد
 * مرتبط بالمستخدم (user_id)، ويدعم الحذف الناعم وتحديد المنطقة الجغرافية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->comment('المستخدم صاحب العنوان (سواء ولي أمر أو سائق)')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->foreignId('zone_id')
                  ->nullable()
                  ->comment('المنطقة الجغرافية التابع لها العنوان')
                  ->constrained('zones')
                  ->nullOnDelete();

            $table->string('label', 100)->nullable()->comment('وصف العنوان: البيت، العمل، نقطة الانطلاق');
            $table->decimal('lat', 10, 8);
            $table->decimal('lng', 11, 8);
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('zone_id');
            $table->index(['lat', 'lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
