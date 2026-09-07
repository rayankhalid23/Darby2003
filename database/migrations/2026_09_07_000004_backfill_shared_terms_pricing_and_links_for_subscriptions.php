<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة 2 من تطبيع جداول الاشتراك — تعبئة الأعمدة المضافة في المهاجرات الثلاث
 * السابقة (2026_09_07_000001..3) من البيانات القائمة، دون لمس أي عمود قديم.
 *
 * كل خطوة تحترم COALESCE/شرط IS NULL فتُعبِّئ الفراغ فقط ولا تدهس قيمة موجودة،
 * فتبقى المهاجرة آمنة للتشغيل أكثر من مرة (على سبيل الاحتياط، لا كاعتماد).
 *
 * الترتيب إلزامي: أ) الحقول المشتركة في requests من نسخة request_children ←
 * ب) ما يُشتق منها (trips_per_day، العمولة، السعر) ← ج) روابط request_children
 * (school_id) ← د) active_subscriptions.request_child_id يعتمد على (ج) ليكتمل.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── أ) الحقول المشتركة في requests من نسخة request_children ─────────
        // كل أطفال الطلب الواحد يحملون نفس القيمة المشتركة حرفياً (SubscriptionRequestService
        // يكتبها مكرَّرة لكل طفل)، فـ MIN/MAX هنا مجرد اختيار أي قيمة واحدة من نسخ متطابقة.
        DB::statement(<<<'SQL'
            UPDATE requests r
            JOIN (
                SELECT
                    request_id,
                    MIN(subscription_type)  AS subscription_type,
                    MIN(trip_direction)     AS trip_direction,
                    MIN(timing)             AS timing,
                    MIN(start_date)         AS start_date,
                    MAX(end_date)           AS end_date,
                    MIN(working_days_count) AS working_days_count,
                    MIN(home_label)         AS home_label,
                    MIN(home_lat)           AS home_lat,
                    MIN(home_lng)           AS home_lng,
                    MIN(created_at)         AS created_at,
                    MAX(updated_at)         AS updated_at
                FROM request_children
                GROUP BY request_id
            ) rc ON rc.request_id = r.id
            SET
                r.subscription_type  = COALESCE(r.subscription_type, rc.subscription_type),
                r.trip_direction     = COALESCE(r.trip_direction, rc.trip_direction),
                r.timing             = COALESCE(r.timing, rc.timing),
                r.start_date         = COALESCE(r.start_date, rc.start_date),
                r.end_date           = COALESCE(r.end_date, rc.end_date),
                r.working_days_count = COALESCE(r.working_days_count, rc.working_days_count),
                r.home_label         = COALESCE(r.home_label, rc.home_label),
                r.home_lat           = COALESCE(r.home_lat, rc.home_lat),
                r.home_lng           = COALESCE(r.home_lng, rc.home_lng),
                r.created_at         = COALESCE(r.created_at, rc.created_at),
                r.updated_at         = COALESCE(r.updated_at, rc.updated_at)
        SQL);

        // ── ب.1) trips_per_day مشتق من الاتجاه ────────────────────────────
        DB::statement(<<<'SQL'
            UPDATE requests
            SET trips_per_day = CASE WHEN LOWER(trip_direction) IN ('both', 'two_way') THEN 2 ELSE 1 END
            WHERE trips_per_day IS NULL AND trip_direction IS NOT NULL
        SQL);

        // ── ب.2) pricing_setting_id — صف واحد فقط موجود اليوم في pricing_settings ─
        DB::statement(<<<'SQL'
            UPDATE requests
            SET pricing_setting_id = (SELECT MIN(id) FROM pricing_settings)
            WHERE pricing_setting_id IS NULL
              AND EXISTS (SELECT 1 FROM pricing_settings)
        SQL);

        // ── ب.3) نسبة الخصم على مستوى الطلب = discount_amount / total_price ────
        DB::statement(<<<'SQL'
            UPDATE requests
            SET discount_percent = ROUND(discount_amount / NULLIF(total_price, 0) * 100, 2)
            WHERE discount_percent IS NULL AND total_price > 0
        SQL);
        DB::statement(<<<'SQL'
            UPDATE requests SET discount_percent = 0 WHERE discount_percent IS NULL
        SQL);

        // ── ب.4) نسبة عمولة المنصة — تُستنتج عكسياً من driver_net_price المحفوظة ─
        // فعلاً في صف طفل واحد على الأقل. مثال حقيقي من القاعدة: 1067.20 / 1160.00
        // → نسبة عمولة 8.00%، وهي القيمة الحالية في pricing_settings.
        DB::statement(<<<'SQL'
            UPDATE requests r
            JOIN (
                SELECT
                    request_id,
                    MIN(ROUND((1 - driver_net_price / NULLIF(total_amount_after_discount, 0)) * 100, 2)) AS commission_rate
                FROM request_children
                WHERE total_amount_after_discount > 0
                GROUP BY request_id
            ) c ON c.request_id = r.id
            SET r.platform_commission_rate = c.commission_rate
            WHERE r.platform_commission_rate IS NULL
        SQL);
        // احتياطي: طلبات بمبلغ صفري لا تكشف نسبة العمولة حسابياً — تُستعمل نسبة
        // pricing_settings الحالية بدل ترك العمود فارغاً بلا تفسير.
        DB::statement(<<<'SQL'
            UPDATE requests r
            JOIN pricing_settings ps ON ps.id = COALESCE(r.pricing_setting_id, (SELECT MIN(id) FROM pricing_settings))
            SET r.platform_commission_rate = ps.platform_commission_rate
            WHERE r.platform_commission_rate IS NULL
        SQL);

        // ── ب.5) حصة المنصة وحصة السائق على مستوى الطلب ────────────────────
        DB::statement(<<<'SQL'
            UPDATE requests
            SET platform_commission_amount = ROUND(total_amount_after_discount * platform_commission_rate / 100, 2)
            WHERE platform_commission_amount IS NULL AND platform_commission_rate IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            UPDATE requests
            SET driver_net_amount = GREATEST(0, ROUND(total_amount_after_discount - platform_commission_amount, 2))
            WHERE driver_net_amount IS NULL AND platform_commission_amount IS NOT NULL
        SQL);

        // ── ب.6) price_per_km — يُستنتج عكسياً من trip_price (اتجاه واحد بعد
        // الخصم) والمسافة المحتسبة (بحد أدنى 4 كم) ونسبة الخصم أعلاه. ────────
        DB::statement(<<<'SQL'
            UPDATE requests r
            JOIN (
                SELECT request_id,
                       MIN(trip_price)   AS trip_price_after_discount,
                       MIN(distance_km)  AS distance_km
                FROM request_children
                GROUP BY request_id
            ) rc ON rc.request_id = r.id
            SET r.price_per_km = ROUND(
                    (rc.trip_price_after_discount / NULLIF(1 - (r.discount_percent / 100), 0))
                    / GREATEST(COALESCE(rc.distance_km, 0), 4.00)
                , 2)
            WHERE r.price_per_km IS NULL AND rc.trip_price_after_discount IS NOT NULL
        SQL);

        // ── ب.7) vehicle_has_ac — يُقارَن السعر المستنتج بسعرَي التسعيرة الحاليين
        // ويُختار الأقرب. تقريب معقول عند غياب أي سجل تاريخي لحالة المركبة. ────
        DB::statement(<<<'SQL'
            UPDATE requests r
            JOIN pricing_settings ps ON ps.id = COALESCE(r.pricing_setting_id, (SELECT MIN(id) FROM pricing_settings))
            SET r.vehicle_has_ac = (ABS(r.price_per_km - ps.price_per_km_ac) <= ABS(r.price_per_km - ps.price_per_km_non_ac))
            WHERE r.vehicle_has_ac IS NULL AND r.price_per_km IS NOT NULL
        SQL);

        // ── ب.8) home_address_id — مطابقة أفضل جهد بعنوان مسجَّل لنفس ولي الأمر
        // بنفس الإحداثيات المحفوظة كلقطة. طلبات بلا عنوان مطابق تبقى NULL،
        // وهذا سليم: لم يكن هناك رابط بعنوان مسجَّل أصلاً وقت إنشائها. ──────────
        DB::statement(<<<'SQL'
            UPDATE requests r
            JOIN addresses a
              ON a.lat = r.home_lat
             AND a.lng = r.home_lng
             AND a.user_id = r.parent_id
             AND a.deleted_at IS NULL
            SET r.home_address_id = a.id
            WHERE r.home_address_id IS NULL
              AND r.home_lat IS NOT NULL
              AND r.home_lng IS NOT NULL
        SQL);

        // ── ج.1) request_children.school_id من مدرسة الطفل المسجَّلة ───────────
        DB::statement(<<<'SQL'
            UPDATE request_children rc
            JOIN children c ON c.id = rc.child_id
            SET rc.school_id = c.school_id
            WHERE rc.school_id IS NULL AND c.school_id IS NOT NULL
        SQL);

        // ── ج.2) billable_distance_km — نفس حدّ الأربعة كيلومترات المطبَّق في
        // PricingCalculator::MIN_BILLABLE_DISTANCE_KM ──────────────────────────
        DB::statement(<<<'SQL'
            UPDATE request_children
            SET billable_distance_km = GREATEST(COALESCE(distance_km, 0), 4.00)
            WHERE billable_distance_km IS NULL
        SQL);

        // ── ج.3) trip_price_after_discount — عمود trip_price القديم يحمل هذه
        // القيمة فعلياً (سعر الاتجاه الواحد بعد الخصم)؛ هذا نسخ توضيحي لا تصحيح. ─
        DB::statement(<<<'SQL'
            UPDATE request_children
            SET trip_price_after_discount = trip_price
            WHERE trip_price_after_discount IS NULL
        SQL);

        // ── ج.4) daily_price = price_per_child (الإجمالي قبل الخصم) ÷ أيام العمل ─
        DB::statement(<<<'SQL'
            UPDATE request_children
            SET daily_price = ROUND(price_per_child / NULLIF(working_days_count, 0), 2)
            WHERE daily_price IS NULL AND working_days_count > 0
        SQL);

        // ── ج.5) child_status من حالة الطلب الحالية — العمود جديد بقيمة افتراضية
        // 'pending' موحّدة على كل الصفوف القديمة، فنشتقّها من الواقع الفعلي. ───────
        DB::statement(<<<'SQL'
            UPDATE request_children rc
            JOIN requests r ON r.id = rc.request_id
            SET rc.child_status = CASE
                WHEN r.status = 'rejected'                                    THEN 'rejected'
                WHEN r.status = 'cancelled'                                   THEN 'cancelled'
                WHEN r.status IN ('accepted', 'acquired', 'contract_offered') THEN 'accepted'
                ELSE 'pending'
            END
            WHERE rc.child_status = 'pending'
        SQL);

        // ── د.1) الرابط المفصلي: active_subscriptions.request_child_id ─────────
        DB::statement(<<<'SQL'
            UPDATE active_subscriptions a
            JOIN request_children rc
              ON rc.request_id = a.subscription_request_id
             AND rc.child_id   = a.child_id
            SET a.request_child_id = rc.id
            WHERE a.request_child_id IS NULL
        SQL);

        // ── د.2) start_date/end_date — من الطفل المرتبط، مع احتياط من الطلب نفسه ─
        DB::statement(<<<'SQL'
            UPDATE active_subscriptions a
            JOIN request_children rc ON rc.id = a.request_child_id
            SET a.start_date = COALESCE(a.start_date, rc.start_date),
                a.end_date   = COALESCE(a.end_date, rc.end_date)
            WHERE a.start_date IS NULL OR a.end_date IS NULL
        SQL);
        DB::statement(<<<'SQL'
            UPDATE active_subscriptions a
            JOIN requests r ON r.id = a.subscription_request_id
            SET a.start_date = COALESCE(a.start_date, r.start_date),
                a.end_date   = COALESCE(a.end_date, r.end_date)
            WHERE a.start_date IS NULL OR a.end_date IS NULL
        SQL);

        // ── د.3) school_id عبر الرابط المفصلي ──────────────────────────────────
        DB::statement(<<<'SQL'
            UPDATE active_subscriptions a
            JOIN request_children rc ON rc.id = a.request_child_id
            SET a.school_id = rc.school_id
            WHERE a.school_id IS NULL AND rc.school_id IS NOT NULL
        SQL);
    }

    /**
     * تعبئة بيانات فحسب — لا مخطط يتغيّر هنا (أُضيف في المهاجرات 000001..3 التي
     * تملك down() خاصاً بها). التراجع الحقيقي الوحيد المُجدي هو إفراغ الأعمدة التي
     * عبّأتها هذه المهاجرة، فقط إن أراد أحد إعادة تشغيل التعبئة من الصفر.
     */
    public function down(): void
    {
        DB::table('requests')->update([
            'subscription_type'           => null,
            'trip_direction'              => null,
            'timing'                      => null,
            'start_date'                  => null,
            'end_date'                    => null,
            'working_days_count'          => null,
            'trips_per_day'               => null,
            'home_label'                  => null,
            'home_lat'                    => null,
            'home_lng'                    => null,
            'home_address_id'             => null,
            'pricing_setting_id'          => null,
            'price_per_km'                => null,
            'vehicle_has_ac'              => null,
            'discount_percent'            => null,
            'platform_commission_rate'    => null,
            'platform_commission_amount'  => null,
            'driver_net_amount'           => null,
        ]);

        DB::table('request_children')->update([
            'school_id'                 => null,
            'billable_distance_km'      => null,
            'trip_price_after_discount' => null,
            'daily_price'               => null,
            'child_status'              => 'pending',
        ]);

        DB::table('active_subscriptions')->update([
            'request_child_id' => null,
            'start_date'       => null,
            'end_date'         => null,
            'school_id'        => null,
        ]);
    }
};
