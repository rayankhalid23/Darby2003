<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'status')) {
            DB::statement("ALTER TABLE `vehicles` MODIFY COLUMN `status` ENUM('Active', 'Pending', 'Maintenance', 'Retired') NOT NULL DEFAULT 'Pending'");
        }

        if (Schema::hasTable('driver_documents') && Schema::hasColumn('driver_documents', 'status')) {
            DB::statement("ALTER TABLE `driver_documents` MODIFY COLUMN `status` ENUM('pending', 'approved', 'rejected', 'active') NOT NULL DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'status')) {
            DB::statement("ALTER TABLE `vehicles` MODIFY COLUMN `status` ENUM('Active', 'Maintenance', 'Retired') NOT NULL DEFAULT 'Active'");
        }

        if (Schema::hasTable('driver_documents') && Schema::hasColumn('driver_documents', 'status')) {
            DB::statement("ALTER TABLE `driver_documents` MODIFY COLUMN `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved'");
        }
    }
};
