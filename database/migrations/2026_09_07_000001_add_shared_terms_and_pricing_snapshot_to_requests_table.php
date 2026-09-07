<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 1/أ من تطبيع جداول الاشتراك — إضافات غير هدّامة على جدول requests.
 *
 * جدول requests هو مستودع «الاتفاق المشترك» للطلب بأكمله: النوع والاتجاه والمدة
 * والمنزل الرئيسي. هذه المهاجرة تُكمل الناقص من ذلك المستودع وتضيف إليه لقطة
 * مدخلات التسعير.
 *
 * ⚠️ سبب لقطة التسعير: نسبة العمولة وسعر الكيلومتر يُقرآن حيّاً من pricing_settings
 * في لحظتين مختلفتين — عند إنشاء الطلب (تُكتب في request_children.driver_net_price)
 * وعند قبول السائق (تُكتب في platform_finances.platform_commission_rate). تعديل
 * الأدمن للنسبة بين اللحظتين يجعل الرقمين يتناقضان في نفس الطلب بلا أي أثر يُمكّن
 * من معرفة أيهما كان سارياً. تجميد المدخلات هنا وقت الإنشاء ينهي ذلك: كل حسابات
 * الطلب تُصبح قابلة لإعادة الإنتاج من صفّه وحده.
 *
 * كل الأعمدة nullable: لا تكسر أي كتابة قائمة، والتعبئة في مهاجرة منفصلة
 * (2026_09_07_000004). إعادة تسمية total_price → gross_amount مؤجَّلة إلى ما بعد
 * تعديل الكود؛ إجراؤها الآن يكسر كل مسارات القراءة فوراً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // ── شروط الاشتراك المشتركة الناقصة ──────────────────────────────
            // الفترة المدرسية المشتركة. اختيارية عمداً: عند غيابها تُشتق لكل طفل من
            // children.preferred_time_slot، لأن إخوة في نفس الطلب قد يدرسون في
            // فترتين مختلفتين. وجودها هنا يعني «طبِّق هذه الفترة على الجميع».
            if (!Schema::hasColumn('requests', 'timing')) {
                $table->string('timing', 20)->nullable()->after('trip_direction');
            }

            // عدد أيام العمل واحد للجميع لأن المدة واحدة للجميع؛ حسابه لكل طفل تكرار.
            if (!Schema::hasColumn('requests', 'working_days_count')) {
                $table->unsignedSmallInteger('working_days_count')->nullable()->after('end_date');
            }

            // 1 للاتجاه الواحد و2 للذهاب والإياب. مشتق من trip_direction، لكن تخزينه
            // يُبقي حساب أي طلب قديم قابلاً لإعادة الإنتاج لو تغيّرت قاعدة الاشتقاق.
            if (!Schema::hasColumn('requests', 'trips_per_day')) {
                $table->unsignedTinyInteger('trips_per_day')->nullable()->after('working_days_count');
            }

            // ── ربط لقطة المنزل بعنوان مسجَّل ──────────────────────────────
            // اليوم يصل المنزل كإحداثيات حرة من الـpayload بلا رابط بجدول addresses،
            // فلا يمكن معرفة أي عنوان مسجَّل اختاره ولي الأمر ولا تتبّع تغييره لاحقاً.
            if (!Schema::hasColumn('requests', 'home_address_id')) {
                $table->foreignId('home_address_id')
                      ->nullable()
                      ->after('home_lng')
                      ->constrained('addresses')
                      ->nullOnDelete()
                      ->cascadeOnUpdate();
            }

            // ── لقطة مدخلات التسعير وقت إنشاء الطلب ────────────────────────
            if (!Schema::hasColumn('requests', 'pricing_setting_id')) {
                $table->foreignId('pricing_setting_id')
                      ->nullable()
                      ->after('home_address_id')
                      ->constrained('pricing_settings')
                      ->nullOnDelete()
                      ->cascadeOnUpdate();
            }

            if (!Schema::hasColumn('requests', 'price_per_km')) {
                $table->decimal('price_per_km', 8, 2)->nullable()->after('pricing_setting_id');
            }

            // يوثّق سبب اختيار سعر الكيلومتر أعلاه (المكيَّف أغلى من غير المكيَّف)،
            // فلا يبقى الفرق بين طلبين بنفس المسافة غامضاً.
            if (!Schema::hasColumn('requests', 'vehicle_has_ac')) {
                $table->boolean('vehicle_has_ac')->nullable()->after('price_per_km');
            }

            // شريحة الخصم الجماعي المطبَّقة فعلياً حسب عدد الأطفال وقت الطلب.
            if (!Schema::hasColumn('requests', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('vehicle_has_ac');
            }

            if (!Schema::hasColumn('requests', 'platform_commission_rate')) {
                $table->decimal('platform_commission_rate', 5, 2)->nullable()->after('discount_percent');
            }

            // ── حصة المنصة وحصة السائق على مستوى الطلب ─────────────────────
            // غير مخزَّنتين اليوم إطلاقاً على هذا المستوى، بل تُجمَعان يدوياً من صفوف
            // الأطفال في كل مرة تُعرض فيها تفاصيل الطلب.
            if (!Schema::hasColumn('requests', 'platform_commission_amount')) {
                $table->decimal('platform_commission_amount', 10, 2)
                      ->nullable()
                      ->after('total_amount_after_discount');
            }

            if (!Schema::hasColumn('requests', 'driver_net_amount')) {
                $table->decimal('driver_net_amount', 10, 2)
                      ->nullable()
                      ->after('platform_commission_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            foreach (['home_address_id', 'pricing_setting_id'] as $fkColumn) {
                if (Schema::hasColumn('requests', $fkColumn)) {
                    $table->dropForeign(['' . $fkColumn]);
                }
            }

            $columns = array_values(array_filter([
                'timing',
                'working_days_count',
                'trips_per_day',
                'home_address_id',
                'pricing_setting_id',
                'price_per_km',
                'vehicle_has_ac',
                'discount_percent',
                'platform_commission_rate',
                'platform_commission_amount',
                'driver_net_amount',
            ], fn ($column) => Schema::hasColumn('requests', $column)));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
