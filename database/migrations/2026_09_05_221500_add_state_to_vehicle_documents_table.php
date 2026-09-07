<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_documents') && !Schema::hasColumn('vehicle_documents', 'state')) {
            Schema::table('vehicle_documents', function (Blueprint $table) {
                $table->enum('state', ['pending', 'active', 'expired', 'rejected'])
                      ->default('pending')
                      ->after('is_verified')
                      ->comment('حالة الوثيقة: معلقة، مفعلة، منتهية الصلاحية، مرفوضة');

                $table->index('state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_documents') && Schema::hasColumn('vehicle_documents', 'state')) {
            Schema::table('vehicle_documents', function (Blueprint $table) {
                $table->dropIndex(['state']);
                $table->dropColumn('state');
            });
        }
    }
};
