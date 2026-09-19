<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedInteger('suspension_count')->default(0)->after('status');
            $table->timestamp('suspended_until')->nullable()->after('suspension_count');
            $table->unsignedInteger('active_warnings_count')->default(0)->after('suspended_until');
            $table->timestamp('last_incident_at')->nullable()->after('active_warnings_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'suspension_count',
                'suspended_until',
                'active_warnings_count',
                'last_incident_at',
            ]);
        });
    }
};
