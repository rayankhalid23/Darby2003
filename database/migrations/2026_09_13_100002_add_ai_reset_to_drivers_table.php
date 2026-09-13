<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاريخ آخر Reset إداري لسائق — نقطة بداية نافذة 30 يوم التي يحسب منها المحرك.
 * إذا كان NULL يُعتمد "قبل 30 يوم من الآن" كحد افتراضي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            if (!Schema::hasColumn('drivers', 'ai_last_reset_at')) {
                $table->timestamp('ai_last_reset_at')->nullable()->after('rating_avg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            if (Schema::hasColumn('drivers', 'ai_last_reset_at')) {
                $table->dropColumn('ai_last_reset_at');
            }
        });
    }
};
