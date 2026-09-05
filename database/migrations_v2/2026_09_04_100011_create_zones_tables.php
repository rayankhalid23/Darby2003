<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول التقسيم الإداري والجغرافي (بلديات، بلديات فرعية، مناطق).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('sub_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')
                  ->constrained('municipalities')
                  ->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['municipality_id', 'name']);
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_municipality_id')
                  ->constrained('sub_municipalities')
                  ->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['sub_municipality_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('sub_municipalities');
        Schema::dropIfExists('municipalities');
    }
};
