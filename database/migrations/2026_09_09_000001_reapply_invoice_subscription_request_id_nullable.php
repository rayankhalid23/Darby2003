<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * إعادة تطبيق 2026_08_28_070000_make_invoice_subscription_request_id_nullable.
 *
 * جدول migrations يسجّل تلك الميغريشن كمُنفَّذة (batch 10) بينما
 * 2026_07_08_000004_create_invoices_table نُفِّذت لاحقاً (batch 11) — يعني جدول
 * invoices أُعيد إنشاؤه من الصفر بعد تطبيق التعديل، فرجع العمود NOT NULL بصمت
 * ولم يُعِد Laravel تشغيل الميغريشن الأصلية لأنها مُسجَّلة كمُنفَّذة أصلاً.
 * النتيجة: أي فاتورة شحن محفظة (لا ترتبط باشتراك) تفشل بخطأ SQL فوراً ويتوقف
 * شحن المحفظة بالكامل. هذه الميغريشن idempotent وتُصلح الحالة الفعلية للعمود
 * بغض النظر عن سجل migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'subscription_request_id')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `invoices` MODIFY COLUMN `subscription_request_id` BIGINT UNSIGNED NULL");
            } else {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->unsignedBigInteger('subscription_request_id')->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // لا حاجة للتراجع — استعادة NOT NULL تكسر فواتير الشحن الموجودة فعلياً.
    }
};
