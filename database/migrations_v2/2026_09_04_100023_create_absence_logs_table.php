<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete()->cascadeOnUpdate();
            $table->date('absence_date');
            $table->enum('absence_type', ['pickup', 'dropoff', 'both'])
                  ->default('both')
                  ->comment('نوع الغياب: pickup=ذهاب فقط، dropoff=عودة فقط، both=ذهاب وعودة');
            $table->timestamps();

            $table->unique(['child_id', 'absence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_logs');
    }
};
