<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 1/ب من تطبيع جداول الاشتراك — إضافات غير هدّامة على request_children.
 *
 * هذا الجدول يحمل ما يختلف بين أطفال الطلب الواحد: المدرسة والمسافة وحصة كل طفل
 * من المبلغ. المضاف هنا يسدّ ثلاث فجوات:
 *
 *  1. school_id — اللقطة النصية (school_label/lat/lng) محفوظة بلا رابط بجدول
 *     schools، فلا يمكن التجميع أو إخراج تقرير حسب المدرسة.
 *  2. billable_distance_km — حدّ الأربعة كيلومترات مطبَّق في PricingCalculator
 *     وغير مخزَّن، فلا أحد يستطيع إعادة إنتاج السعر من الصف نفسه: مسافة 2.18 كم
 *     تُنتج سعراً مبنياً على 4 كم دون أي أثر يشرح الفارق.
 *  3. trip_price_after_discount و daily_price — عمود trip_price اليوم يحمل سعر
 *     الاتجاه الواحد بعد الخصم، بينما اسمه ووجوده بجانب total_amount_after_discount
 *     يوحيان بأنه قبله. فصل القيمتين ينهي الالتباس، وdaily_price يوقف الخلط
 *     المتكرر بين «سعر الرحلة» و«سعر اليوم» في الاشتراكات ذات الاتجاهين.
 *
 * ⚠️ عمود timing يبقى: هو الفترة المدرسية (صباحية/مسائية) ومصدرها
 * children.preferred_time_slot لكل طفل، وDriverSeatSlot::resolveSlots يضربها في
 * الاتجاه لتحديد خانات المقاعد. الخلل كان في تثبيته على 'BOTH' عند الكتابة —
 * وهو إصلاح كود لا إصلاح مخطط.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstDuplicateChildren();

        Schema::table('request_children', function (Blueprint $table) {
            if (!Schema::hasColumn('request_children', 'school_id')) {
                $table->foreignId('school_id')
                      ->nullable()
                      ->after('child_id')
                      ->constrained('schools')
                      ->nullOnDelete()
                      ->cascadeOnUpdate();
            }

            if (!Schema::hasColumn('request_children', 'billable_distance_km')) {
                $table->decimal('billable_distance_km', 8, 2)->nullable()->after('distance_km');
            }

            if (!Schema::hasColumn('request_children', 'trip_price_after_discount')) {
                $table->decimal('trip_price_after_discount', 10, 2)->nullable()->after('trip_price');
            }

            if (!Schema::hasColumn('request_children', 'daily_price')) {
                $table->decimal('daily_price', 10, 2)->nullable()->after('trip_price_after_discount');
            }

            // children_acceptance_mode = 'individual' موجود في جدول requests منذ
            // مهاجرة 2026_08_05، ولا شيء يسجّل قرار السائق لكل طفل على حدة — فالقبول
            // الفردي غير قابل للتنفيذ عملياً بدون هذا العمود.
            if (!Schema::hasColumn('request_children', 'child_status')) {
                $table->string('child_status', 20)->default('pending')->after('driver_net_price');
            }
        });

        $this->addUniqueChildPerRequest();
    }

    /**
     * الحماية الوحيدة اليوم من تكرار الطفل في نفس الطلب هي أن sync() يفهرس مصفوفة
     * الـpivot بمعرّف الطفل — سلوك كود، لا قيد قاعدة. أي كتابة مباشرة (استيراد،
     * تصليح يدوي، مسار جديد) تستطيع إنشاء صفّين للطفل نفسه فيُحتسب سعره مرتين.
     */
    private function addUniqueChildPerRequest(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM request_children'))
            ->pluck('Key_name')
            ->unique();

        if (!$indexes->contains('request_children_request_id_child_id_unique')) {
            Schema::table('request_children', function (Blueprint $table) {
                $table->unique(['request_id', 'child_id'], 'request_children_request_id_child_id_unique');
            });
        }

        // الفهرس المركّب غير الفريد صار زائداً: الفريد الجديد يخدم نفس الاستعلامات،
        // وكل من request_id و child_id له فهرسه المفرد الذي تحتاجه المفاتيح الأجنبية.
        if ($indexes->contains('request_children_request_id_child_id_index')) {
            try {
                Schema::table('request_children', function (Blueprint $table) {
                    $table->dropIndex('request_children_request_id_child_id_index');
                });
            } catch (\Throwable $e) {
                // فهرس زائد باقٍ لا يضر البيانات؛ الفشل هنا لا يبرّر إسقاط المهاجرة.
            }
        }
    }

    /**
     * إضافة قيد فريد فوق بيانات مكرّرة تفشل برسالة MySQL غامضة. الفحص المسبق يجعل
     * سبب التوقف واضحاً ويترك قرار الدمج أو الحذف لمن يعرف البيانات — لا للمهاجرة.
     */
    private function guardAgainstDuplicateChildren(): void
    {
        $duplicates = DB::table('request_children')
            ->select('request_id', 'child_id', DB::raw('COUNT(*) as total'))
            ->groupBy('request_id', 'child_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(5)
                ->map(fn ($row) => "request_id={$row->request_id} child_id={$row->child_id} ({$row->total} صفوف)")
                ->implode(' · ');

            throw new \RuntimeException(
                "تعذّر إضافة القيد الفريد على (request_id, child_id): توجد {$duplicates->count()} حالة تكرار. "
                . "عالِج التكرار يدوياً ثم أعد تشغيل المهاجرة. أمثلة: {$sample}"
            );
        }
    }

    public function down(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM request_children'))
            ->pluck('Key_name')
            ->unique();

        Schema::table('request_children', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('request_children_request_id_child_id_unique')) {
                $table->dropUnique('request_children_request_id_child_id_unique');
            }

            if (!$indexes->contains('request_children_request_id_child_id_index')) {
                $table->index(['request_id', 'child_id'], 'request_children_request_id_child_id_index');
            }

            if (Schema::hasColumn('request_children', 'school_id')) {
                $table->dropForeign(['school_id']);
            }

            $columns = array_values(array_filter([
                'school_id',
                'billable_distance_km',
                'trip_price_after_discount',
                'daily_price',
                'child_status',
            ], fn ($column) => Schema::hasColumn('request_children', $column)));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
