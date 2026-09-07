<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('addresses', 'zone_id')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->foreignId('zone_id')
                      ->nullable()
                      ->after('user_id')
                      ->comment('المنطقة الجغرافية التابع لها العنوان')
                      ->constrained('zones')
                      ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('addresses', 'zone_id')) {
            Schema::table('addresses', function (Blueprint $table) {
                try {
                    $table->dropForeign(['zone_id']);
                } catch (\Throwable $e) {}
                $table->dropColumn('zone_id');
            });
        }
    }
};
