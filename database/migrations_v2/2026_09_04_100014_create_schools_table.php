<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول المدارس (schools).
 * يحتوي على المدارس ومواقعها الجغرافية ومناطقها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);

            $table->foreignId('zone_id')
                  ->nullable()
                  ->comment('المنطقة الجغرافية التي تقع فيها المدرسة')
                  ->constrained('zones')
                  ->nullOnDelete();

            $table->decimal('lat', 10, 8);
            $table->decimal('lng', 11, 8);
            $table->string('address', 255)->nullable();
            $table->enum('status', ['Approved', 'Pending', 'Inactive'])->default('Approved');

            $table->timestamps();

            $table->index('zone_id');
            $table->index(['lat', 'lng']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
