<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة الحقول المشتركة للعائلة إلى جدول requests.
 *
 * المنطق الجديد: عنوان المنزل ونوع الاشتراك والاتجاه والفترة الزمنية
 * أصبحت مشتركة على مستوى الطلب بأكمله، بدلاً من تكرارها لكل طفل في request_children.
 *
 * ملاحظة: أعمدة request_children القائمة (home_lat/lng/label, subscription_type,
 * trip_direction, start_date, end_date) تُبقى كـ fallback للطلبات القديمة ولا تُحذف.
 * جميع الأعمدة الجديدة nullable لضمان التوافق العكسي الكامل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // نوع الاشتراك المشترك (single_day | multi_day)
            if (!Schema::hasColumn('requests', 'subscription_type')) {
                $table->string('subscription_type', 50)->nullable()->after('notes');
            }

            // اتجاه الرحلة المشترك (go | return | both)
            if (!Schema::hasColumn('requests', 'trip_direction')) {
                $table->string('trip_direction', 50)->nullable()->after('subscription_type');
            }

            // الفترة الزمنية المشتركة لجميع الأطفال في الطلب
            if (!Schema::hasColumn('requests', 'start_date')) {
                $table->date('start_date')->nullable()->after('trip_direction');
            }
            if (!Schema::hasColumn('requests', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }

            // لقطة عنوان المنزل المشترك وقت إنشاء الطلب
            if (!Schema::hasColumn('requests', 'home_label')) {
                $table->string('home_label', 255)->nullable()->after('end_date');
            }
            if (!Schema::hasColumn('requests', 'home_lat')) {
                $table->decimal('home_lat', 10, 8)->nullable()->after('home_label');
            }
            if (!Schema::hasColumn('requests', 'home_lng')) {
                $table->decimal('home_lng', 11, 8)->nullable()->after('home_lat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $columns = [
                'subscription_type', 'trip_direction',
                'start_date', 'end_date',
                'home_label', 'home_lat', 'home_lng',
            ];
            $existing = array_values(array_filter(
                $columns,
                fn ($col) => Schema::hasColumn('requests', $col)
            ));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
