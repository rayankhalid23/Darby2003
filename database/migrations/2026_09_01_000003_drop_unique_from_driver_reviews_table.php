<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('driver_reviews', function (Blueprint $table) {
                $table->index('parent_id');
            });
        } catch (\Throwable $e) {
            // Index already exists
        }

        try {
            Schema::table('driver_reviews', function (Blueprint $table) {
                // إدراج فهرس عادي لـ parent_id حتى يتم تحرير المفتاح الخارجي قبل إسقاط القيد الفريد المركب
                $table->dropUnique('driver_reviews_parent_id_driver_id_unique');
            });
        } catch (\Throwable $e) {
            // Unique index already dropped
        }
    }

    public function down(): void
    {
    }
};
