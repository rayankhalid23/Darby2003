<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * هذه الأعمدة تُكتب متطابقة لكل أطفال نفس الطلب (SubscriptionRequestService::createRequest)
 * وتم عمل backfill لها في requests عبر 2026_09_07_000004، فالمصدر الوحيد لها الآن هو requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_children', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_type',
                'trip_direction',
                'start_date',
                'end_date',
                'home_label',
                'home_lat',
                'home_lng',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('request_children', function (Blueprint $table) {
            $table->string('subscription_type', 50)->nullable();
            $table->string('trip_direction', 50)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('home_label')->nullable();
            $table->decimal('home_lat', 10, 8)->nullable();
            $table->decimal('home_lng', 11, 8)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE request_children rc
            JOIN requests r ON r.id = rc.request_id
            SET rc.subscription_type = r.subscription_type,
                rc.trip_direction    = r.trip_direction,
                rc.start_date        = r.start_date,
                rc.end_date          = r.end_date,
                rc.home_label        = r.home_label,
                rc.home_lat          = r.home_lat,
                rc.home_lng          = r.home_lng
        SQL);
    }
};
