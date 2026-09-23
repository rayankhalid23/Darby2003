<?php

namespace App\Services\Parent;

use App\Models\Parent\Child;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\Zone;
use App\Services\Shared\PricingCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DriverMatchingService
{
    /**
     * نوع الاشتراك واتجاه الرحلة وفترة البحث اختيارية من ولي الأمر عند البحث؛
     * غيابها يعني تقدير سعر ليوم عمل واحد ذهاباً فقط (أقل تقدير ممكن لا أكبره).
     */
    private const DEFAULT_TRIP_DIRECTION    = 'go';
    private const DEFAULT_SUBSCRIPTION_TYPE = 'multi_day';

    public function __construct(
        private readonly PricingCalculator $pricingCalculator
    ) {}

    private function resolveChildren(array $childIds, int $parentId): Collection
    {
        $query = Child::with(['school.zone.subMunicipality', 'address.zone'])
            ->where('parent_id', $parentId);

        if (!empty($childIds)) {
            $query->whereIn('id', $childIds);
        }

        return $query->get();
    }

    /**
     * @return array{drivers: LengthAwarePaginator, context: array}
     */
    public function matchDrivers(array $filters, int $parentId): array
    {
        // 1. استرجاع بيانات الأطفال المحددين بناءً على child_ids
        $children = $this->resolveChildren($filters['child_ids'] ?? [], $parentId);

        // بيانات الاشتراك المشتركة لكل الأطفال المحددين بهذا البحث (نفس حقول
        // الطلب الفعلي عند الإنشاء) — تُستخدم بالتسعير التقديري وتُعاد كما هي
        // في context الاستجابة حتى تعرف الواجهة بالضبط شنو انطبق.
        $subscriptionType = $filters['subscription_type'] ?? self::DEFAULT_SUBSCRIPTION_TYPE;
        $tripDirection    = $filters['trip_direction']    ?? self::DEFAULT_TRIP_DIRECTION;
        $startDate        = $filters['start_date']        ?? now()->toDateString();
        $endDate          = $filters['end_date']          ?? $startDate;

        $context = [
            'subscription_type' => $subscriptionType,
            'trip_direction'    => $tripDirection,
            'start_date'        => $startDate,
            'end_date'          => $endDate,
            'child_ids'         => $children->pluck('id')->values()->all(),
        ];

        // 2. الاستعلام الأساسي: السائق معتمد وموثوق ورخصته سارية وغير محجوب مؤقتاً
        $query = Driver::query()
            ->select('drivers.*')
            ->whereIn('drivers.status', ['Approved', 'Active'])
            ->where(function ($q) {
                $q->whereNull('drivers.suspended_until')
                  ->orWhere('drivers.suspended_until', '<=', now());
            })
            ->whereHas('user', fn($u) => $u->where('is_trusted', true))
            ->where('drivers.license_expiry', '>=', now()->toDateString())
            ->with(['user', 'vehicles', 'zones']);

       // 3. التحقق من وجود بحث بالاسم أو رقم الهاتف
       $hasSearchQuery = !empty($filters['search_query']);

       if ($hasSearchQuery) {
           // عند البحث بالاسم أو الهاتف: نكتفي بالبحث النصي وتجاهل جميع الفلاتر الأخرى تماماً
           $this->applyTextSearch($query, $filters['search_query']);
       } else {
           // في حالة عدم وجود بحث نصي: نطبق فلاتر الجنس، التكييف، والفلترة الذكية للأطفال
           // ⚠️ 'both' من الواجهة تعني «لا فرق» → لا نُطبِّق فلتراً، لأن users.gender
           // enum('male','female') فقط ولا يحتوي 'both' فيرجع صفراً.
           if (!empty($filters['driver_gender']) && $filters['driver_gender'] !== 'both') {
               $query->whereHas('user', fn($u) => $u->where('gender', $filters['driver_gender']));
           }

           if (isset($filters['has_ac']) && $filters['has_ac'] !== null && $filters['has_ac'] !== '') {
               $hasAc = filter_var($filters['has_ac'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
               if ($hasAc !== null) {
                   // نتحقق من المركبة النشطة فقط: مركبة Retired/Maintenance لا يجوز
                   // أن تُقرِّر أهلية السائق (فلتر seat_availability أصلاً يقيّد status=Active).
                   $query->whereHas('vehicles', fn($q) => $q->where('status', 'Active')->where('has_ac', $hasAc));
               }
           }

           // 4. تطبيق الفلترة الذكية للأطفال (المناطق، المقاعد، الجنس المقبول)
           if ($children->isNotEmpty()) {
               $this->applyChildrenSmartFilters($query, $children, $filters);
           }
       }

        // 5. الترتيب والتصفح (دعم نموذج الذكاء الاصطناعي مع التراجع التلقائي للترتيب العادي)
        $matchingDrivers = $query->get();

        $aiRanked = false;
        if ($matchingDrivers->isNotEmpty()) {
            $aiRanked = $this->applyAiRankingIfAvailable($matchingDrivers);
        }

        if (!$aiRanked) {
            $matchingDrivers = $matchingDrivers->sortByDesc(fn($d) => [$d->rating_avg ?? 0, $d->id])->values();
        }

        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage('page');
        $perPage = 15;
        $items = $matchingDrivers->forPage($page, $perPage)->values();
        $drivers = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $matchingDrivers->count(),
            $perPage,
            $page,
            [
                'path'     => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );

        // 6. حساب مسافات الأطفال والتسعير الفعلي لكل سائق
        if ($children->isNotEmpty()) {
            $childrenDistances = [];
            foreach ($children as $child) {
                if ($child->address?->lat && $child->school?->lat) {
                    $childrenDistances[$child->id] = $this->getRouteDistance(
                        $child->address->lat,
                        $child->address->lng,
                        $child->school->lat,
                        $child->school->lng
                    );
                } else {
                    $childrenDistances[$child->id] = 0.0;
                }
            }

            $drivers->getCollection()->transform(function (Driver $driver) use ($children, $childrenDistances, $subscriptionType, $tripDirection, $startDate, $endDate) {
                $priceDetails = $this->calculatePricingForDriver(
                    $driver,
                    $children,
                    $childrenDistances,
                    $subscriptionType,
                    $tripDirection,
                    $startDate,
                    $endDate
                );
                $driver->estimated_total_price = $priceDetails['total'];
                $driver->pricing_breakdown      = $priceDetails['breakdown'];
                $driver->platform_fee           = $priceDetails['platform_fee'];
                $driver->driver_net_amount      = $priceDetails['driver_net_amount'];
                $driver->children_context       = $children;
                return $driver;
            });
        } else {
            $settings     = rescue(fn() => PricingSetting::first(), null, false);
            $priceKmAc    = (float) ($settings->price_per_km_ac ?? 2.50);
            $priceKmNonAc = (float) ($settings->price_per_km_non_ac ?? 2.00);

            $drivers->getCollection()->transform(function (Driver $driver) use ($priceKmAc, $priceKmNonAc) {
                $activeVehicle = $driver->vehicles->where('status', 'Active')->first() ?? $driver->vehicles->first();
                $hasAc = $activeVehicle ? (bool) $activeVehicle->has_ac : false;
                $driverPriceKm = $hasAc ? $priceKmAc : $priceKmNonAc;

                $driver->estimated_total_price = 0.0;
                $driver->pricing_breakdown      = [];
                $driver->platform_fee           = 0.0;
                $driver->driver_net_amount      = 0.0;
                $driver->children_context       = collect();
                $driver->price_per_km           = $driverPriceKm;
                return $driver;
            });
        }

        return [
            'drivers'      => $drivers,
            'context'      => $context,
            'ranking_mode' => $aiRanked ? 'AI_LIGHTGBM_RANKER' : 'DEFAULT_RATING',
        ];
    }

    private function applyTextSearch($query, string $keyword): void
    {
        $keyword = trim($keyword);
        $normalizedKeyword = str_replace(['أ', 'إ', 'آ'], 'ا', $keyword);
        $phoneDigits = ltrim(preg_replace('/[^0-9]/', '', $keyword), '0');

        $query->whereHas('user', function ($q) use ($keyword, $normalizedKeyword, $phoneDigits) {
            $q->where(function ($sub) use ($keyword, $normalizedKeyword, $phoneDigits) {
                $sub->where('users.full_name', 'like', "%{$keyword}%")
                    ->orWhere('users.full_name', 'like', "%{$normalizedKeyword}%")
                    ->orWhere('users.phone_number', 'like', "%{$keyword}%")
                    ->orWhere('users.alternative_phone', 'like', "%{$keyword}%");

                if (!empty($phoneDigits) && strlen($phoneDigits) >= 3) {
                    $sub->orWhere('users.phone_number', 'like', "%{$phoneDigits}%")
                        ->orWhere('users.alternative_phone', 'like', "%{$phoneDigits}%");
                }
            });
        });
    }

    private function applyChildrenSmartFilters($query, Collection $children, array $filters = []): void
    {
        $genders = $children->pluck('gender')->unique()->values()->toArray();
        if (count($genders) > 1) {
            $query->where('drivers.accepted_gender', 'both');
        } else {
            $query->whereIn('drivers.accepted_gender', [$genders[0] ?? 'both', 'both']);
        }

        $this->applySeatsAvailabilityFilter($query, $children, $filters);
        $this->applyZoneFilter($query, $children);
    }

    private function applySeatsAvailabilityFilter($query, Collection $children, array $filters = []): void
    {
        // فترة البحث تأتي من ولي الأمر؛ غيابها يعني بحثاً ليوم واحد هو اليوم.
        // ⚠️ قبل تمرير start_date/end_date عبر SearchDriversRequest كان الفلتر يفحص
        // اليوم الحالي دائماً، فيمر سائق ممتلئ طوال فترة الاشتراك المطلوبة.
        $startDate = $filters['start_date'] ?? now()->toDateString();
        $endDate   = $filters['end_date']   ?? $startDate;

        // الاتجاه والفترة يجب أن يكونا نفسهما اللذين سيُحجزان لاحقاً في طلب الاشتراك،
        // وإلا فُلتِر على رحلة وحُجزت أخرى. الفترة تُشتق من بيانات الطفل الحقيقية
        // (children.preferred_time_slot) لا من قيمة افتراضية.
        $direction = $filters['trip_direction'] ?? self::DEFAULT_TRIP_DIRECTION;

        // حساب طلب المقاعد لكل slot — الفترة خاصة بكل طفل بروحه دائماً (تُشتق من
        // تفضيله المسجَّل)، لا يوجد أي override موحّد يفرض نفس الفترة على كل الأطفال.
        $slotDemand = [];
        foreach ($children as $child) {
            $timing = DriverSeatSlot::timingFromPreferredSlot($child->preferred_time_slot);

            if ($timing === null) {
                continue;
            }

            foreach (DriverSeatSlot::resolveSlots($timing, $direction) as $slot) {
                $slotDemand[$slot] = ($slotDemand[$slot] ?? 0) + 1;
            }
        }

        if (empty($slotDemand)) {
            return;
        }

        // استبعاد السائقين الغائبين في أي يوم من فترة البحث.
        // نأخذ بعين الاعتبار طلبات الغياب المُعتمَدة أو التي لم تُراجَع بعد فقط
        // (approved أو pending). الطلبات المرفوضة (rejected) لا تحجب السائق لأنه
        // لم يُثبَت غيابه فعلاً — بقاؤها في الفلتر كان يُخفي سائقاً متاحاً بسبب طلب
        // رُفض إدارياً.
        $query->whereDoesntHave('absences', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('absence_date', [$startDate, $endDate])
              ->whereIn('status', [
                  \App\Models\Driver\DriverAbsence::STATUS_APPROVED,
                  \App\Models\Driver\DriverAbsence::STATUS_PENDING,
              ]);
        });

        // فلتر المقاعد: لكل slot، تحقق أن السائق يُشغّل هذه الخانة أصلاً ولديه
        // طاقة كافية عبر الفترة. الشرط الأول (drivers.{slot}=1) هو الفاصل الذي
        // كان مفقوداً: بدونه أي سائق ما اشتغلش سابقاً في هذه الخانة يمر لأنه
        // ماكوش أي صف في driver_seat_slots فيرجع MAX(booked)=NULL.
        // خارطة الخانة إلى عمود السائق الذي يفعّلها.
        $slotToDriverFlag = [
            \App\Models\Driver\DriverSeatSlot::MORNING_GO       => 'morning_go',
            \App\Models\Driver\DriverSeatSlot::MORNING_RETURN   => 'morning_return',
            \App\Models\Driver\DriverSeatSlot::AFTERNOON_GO     => 'afternoon_go',
            \App\Models\Driver\DriverSeatSlot::AFTERNOON_RETURN => 'afternoon_return',
        ];

        foreach ($slotDemand as $slot => $needed) {
            $slotCopy   = $slot;
            $neededCopy = $needed;
            $driverFlag = $slotToDriverFlag[$slot] ?? null;

            if ($driverFlag !== null) {
                // السائق لازم يكون قد اختار العمل في هذه الفترة/الاتجاه أصلاً.
                $query->where("drivers.$driverFlag", true);
            }

            $query->where(function ($q) use ($slotCopy, $neededCopy, $startDate, $endDate) {
                // السائق مقبول إذا:
                // (طاقة مركبته - أكبر حجز في أي يوم لهذا الـ slot) >= needed
                // أو لا يوجد أي حجز في هذه الفترة (خانة فارغة = متاح كامل)
                $q->whereHas('vehicles', function ($vq) use ($slotCopy, $neededCopy, $startDate, $endDate) {
                    $vq->where('status', 'Active')
                       ->where(function ($sub) use ($slotCopy, $neededCopy, $startDate, $endDate) {
                           // max booked في الفترة لهذا الـ slot
                           $sub->whereRaw(
                               '(vehicles.capacity_manual - COALESCE((
                                   SELECT MAX(dss.booked)
                                   FROM driver_seat_slots dss
                                   WHERE dss.driver_id = drivers.id
                                     AND dss.slot = ?
                                     AND dss.date BETWEEN ? AND ?
                                     AND DAYOFWEEK(dss.date) NOT IN (6, 7)
                               ), 0)) >= ?',
                               [$slotCopy, $startDate, $endDate, $neededCopy]
                           );
                       });
                });
            });
        }
    }

    /**
     * الفلترة الجغرافية الذكية بالمناطق (Dual-Zone Matching):
     * تطابق مناطق مدارس الأطفال (School Zones) ومناطق سكنهم (Home Zones) مع نطاق تغطية السائق (Driver Zones).
     */
    private function applyZoneFilter($query, Collection $children): void
    {
        // 1. استخراج مناطق المدارس
        $schoolZoneIds = $children->map(fn($c) => optional($c->school)->zone_id)
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // 2. استخراج مناطق السكن (عناوين منازل الأطفال)
        $homeZoneIds = $children->map(fn($c) => optional($c->address)->zone_id)
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // يجب أن يغطي السائق كل مناطق المدارس المطلوبة (AND، وليس OR).
        // whereIn سابقاً كان AND-ي على مستوى «موجود منطقة ضمن القائمة» فقط،
        // فيمر سائق يغطي 1 من 3 مناطق ثم يفشل عملياً عند التوصيل للمدرسة الثانية.
        foreach ($schoolZoneIds as $zoneId) {
            $query->whereHas('zones', fn($q) => $q->where('zones.id', $zoneId));
        }

        // نفس المنطق لمناطق السكن (رغم أن أطفال ولي واحد عادةً بنفس العنوان،
        // فقد تختلف عناوينهم لاحقاً - نضمن التغطية الكاملة).
        foreach ($homeZoneIds as $zoneId) {
            $query->whereHas('zones', fn($q) => $q->where('zones.id', $zoneId));
        }
    }

    /**
     * حساب التسعير الشامل بعمولة منفصلة لكل طفل ودعم خلط (يومي + شهري)
     */
    private function calculatePricingForDriver(
        Driver $driver,
        Collection $children,
        array $childrenDistances = [],
        string $subscriptionType = self::DEFAULT_SUBSCRIPTION_TYPE,
        string $tripDirection = self::DEFAULT_TRIP_DIRECTION,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        // أيام العمل والاتجاه محسوبان مرة واحدة لكل الأطفال (نفس معادلة PricingCalculator
        // المستخدمة فعلياً عند إنشاء الاشتراك)، حتى يطابق السعر التقديري هنا السعر الحقيقي لاحقاً.
        $workingDays   = strtolower(trim($subscriptionType)) === 'single_day'
            ? 1
            : $this->pricingCalculator->workingDays($startDate, $endDate);
        $tripMultiplier = $this->pricingCalculator->tripsPerDay($tripDirection);

        // 1. جلب إعدادات الأسعار من قاعدة البيانات
        $settings = rescue(fn() => PricingSetting::first(), null, false);
        $priceKmAc         = $settings->price_per_km_ac ?? 2.50;
        $priceKmNonAc      = $settings->price_per_km_non_ac ?? 2.00;
        $discountOne       = $settings->discount_one_child ?? 0.00;
        $discountTwo       = $settings->discount_two_children ?? 10.00;
        $discountThreePlus = $settings->discount_three_plus_children ?? 15.00;
        $commissionRate    = $settings->platform_commission_rate ?? 8.00; // 8%

        // 2. تحديد نوع تكييف السيارة وسعر الكيلومتر
        $activeVehicle = $driver->vehicles->where('status', 'Active')->first() ?? $driver->vehicles->first();
        $hasAc = $activeVehicle ? (bool) $activeVehicle->has_ac : false;
        $pricePerKm = $hasAc ? $priceKmAc : $priceKmNonAc;

        // 3. تحديد نسبة الخصم الجماعي بناءً على إجمالي عدد الأطفال بالطلب
        $childrenCount = $children->count();
        $discountPercent = match (true) {
            $childrenCount === 1 => $discountOne,
            $childrenCount === 2 => $discountTwo,
            $childrenCount >= 3  => $discountThreePlus,
            default              => 0.0,
        };

        $grandSubtotal     = 0.0;
        $grandDiscount     = 0.0;
        $grandTotal        = 0.0;
        $grandPlatformFee  = 0.0;
        $grandDriverNet    = 0.0;
        $breakdown         = [];

        foreach ($children as $child) {
            $logistics = $child->logistics;

            $childEntry = [
                'child_id'            => $child->id,
                'child_name'          => $child->full_name ?? '',
                'gender'              => $child->gender,
                'school_stage'        => $child->school_stage ?? null,
                'school_stage_label'  => $child->school_stage_label ?? ($child->school_stage ? (string) $child->school_stage : 'ابتدائي'),
                'school_name'         => $child->school?->name ?? '',
                'school_address'      => $child->school?->address ?? '',
                'school_location'     => [
                    'lat' => (float) ($child->school?->lat ?? 0),
                    'lng' => (float) ($child->school?->lng ?? 0),
                ],
                'home_label'          => $child->address?->label ?? '',
                'home_location'       => [
                    'lat' => (float) ($child->address?->lat ?? 0),
                    'lng' => (float) ($child->address?->lng ?? 0),
                ],
                'subscription_type'   => $subscriptionType,
                'preferred_time_slot' => $logistics?->preferred_time_slot ?? 'morning',
                'trip_direction'      => $tripDirection,
                'start_date'          => $startDate,
                'end_date'            => $endDate,
            ];

            if (!$child->address || !$child->school || !$child->address->lat || !$child->school->lat) {
                $childEntry['error'] = 'بيانات الموقع أو إحداثيات الإقامة/المدرسة ناقصة';
                $childEntry['child_final_total'] = 0.0;
                $breakdown[] = $childEntry;
                continue;
            }

            $distanceKm = $childrenDistances[$child->id] ?? $this->getRouteDistance(
                $child->address->lat, $child->address->lng, $child->school->lat, $child->school->lng
            );

            $effectiveDistance = max($distanceKm, 4.0);
            $singleLegPrice    = round($effectiveDistance * $pricePerKm, 2);
            $dailyPrice        = round($singleLegPrice * $tripMultiplier, 2);

            // --- الحسابات الخاصة بهذا الطفل بفرده ---
            $childRawSubtotal  = round($dailyPrice * $workingDays, 2);                      // الإجمالي قبل الخصم
            $childDiscountAmt  = round(($childRawSubtotal * $discountPercent) / 100, 2);    // قيمة خصم الطفل
            $childFinalTotal   = round($childRawSubtotal - $childDiscountAmt, 2);          // صافي المطلوب للطفل

            // --- عمولة المنصة لكل طفل بروحه (8%) ---
            $childPlatformFee  = round(($childFinalTotal * $commissionRate) / 100, 2);     // عمولة المنصة من هذا الطفل
            $childDriverNet    = round($childFinalTotal - $childPlatformFee, 2);           // صافي السائق من هذا الطفل

            // تجميع الإجماليات العامة للطلب
            $grandSubtotal    += $childRawSubtotal;
            $grandDiscount    += $childDiscountAmt;
            $grandTotal       += $childFinalTotal;
            $grandPlatformFee += $childPlatformFee;
            $grandDriverNet   += $childDriverNet;

            // تفاصيل الطفل في الفاتورة
            $childEntry['distance_km']           = round($distanceKm, 2);
            $childEntry['effective_distance_km'] = round($effectiveDistance, 2);
            $childEntry['price_per_km']          = $pricePerKm;
            $childEntry['working_days']          = $workingDays;
            $childEntry['subtotal']              = $childRawSubtotal;
            $childEntry['discount_percent']      = $discountPercent;
            $childEntry['discount_amount']       = $childDiscountAmt;
            $childEntry['final_total']           = $childFinalTotal;      // السعر النهائي للطفل
            $childEntry['platform_fee']          = $childPlatformFee;     // عمولة المنصة للطفل (8%)
            $childEntry['driver_net']            = $childDriverNet;       // صافي السائق للطفل

            $breakdown[] = $childEntry;
        }

        return [
            'subtotal'          => round($grandSubtotal, 2),
            'discount_percent'  => $discountPercent,
            'discount_amount'   => round($grandDiscount, 2),
            'total'             => round($grandTotal, 2),             // إجمالي المطلوب دفعه من ولي الأمر
            'platform_fee'      => round($grandPlatformFee, 2),      // مجموع عمولات المنصة لكل الأطفال (8%)
            'driver_net_amount' => round($grandDriverNet, 2),        // مجموع صافي السائق
            'breakdown'         => $breakdown,
        ];
    }

    public function getRouteGeometry(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): array
    {
        if (is_null($lat1) || is_null($lon1) || is_null($lat2) || is_null($lon2)) {
            return [];
        }

        try {
            $baseUrl = config('services.osrm.url', 'http://localhost:5001');
            $osrmUrl = "{$baseUrl}/route/v1/driving/{$lon1},{$lat1};{$lon2},{$lat2}";

            $response = Http::timeout(3)->get($osrmUrl, [
                'overview'   => 'full',
                'geometries' => 'geojson',
            ]);

            if ($response->successful()) {
                $geometry = $response->json('routes.0.geometry');
                if ($geometry && isset($geometry['coordinates'])) {
                    return $geometry;
                }
            }

            Log::warning("OSRM geometry empty response: Status {$response->status()}");
        } catch (\Throwable $e) {
            Log::error("OSRM Connection Exception: {$e->getMessage()}");
        }

        return [
            'type' => 'LineString',
            'coordinates' => [
                [$lon1, $lat1],
                [$lon2, $lat2]
            ]
        ];
    }

    private function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2): float
    {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return 0.0;
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return round($earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a))), 2);
    }

    /**
     * جلب المسافة الفردية بين نقطتين بالـ (Kilometers) عبر OSRM مع Fallback لـ Haversine
     */
    public function getRouteDistance(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): float
    {
        if (is_null($lat1) || is_null($lon1) || is_null($lat2) || is_null($lon2)) {
            return 0.0;
        }

        try {
            $baseUrl = config('services.osrm.url', 'http://localhost:5001');
            $osrmUrl = "{$baseUrl}/route/v1/driving/{$lon1},{$lat1};{$lon2},{$lat2}?overview=false";

            $response = Http::timeout(3)->get($osrmUrl);

            if ($response->successful()) {
                $distanceInMeters = $response->json('routes.0.distance', 0);
                return round($distanceInMeters / 1000, 2);
            }
        } catch (\Throwable $e) {
            Log::error("OSRM Distance Calculation Failed: {$e->getMessage()}");
        }

        // استخدام Haversine كـ Fallback عند انقطاع الاتصال بـ OSRM
        return $this->calculateHaversineDistance($lat1, $lon1, $lat2, $lon2);
    }

    /**
     * محاولة ترتيب السائقين باستخدام نموذج الذكاء الاصطناعي (LightGBM LambdaRank).
     * في حال كانت خدمة الـ AI متوقفة أو غير متاحة، يتم التراجع تلقائياً دون أي تعطيل أو خطأ.
     */
    protected function applyAiRankingIfAvailable(Collection &$drivers): bool
    {
        try {
            $aiBaseUrl = config('services.ai_classifier.base_url', 'http://127.0.0.1:8001');
            $payload = $this->buildBatchRankingPayload($drivers);

            $response = Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($aiBaseUrl, '/') . '/rank', [
                    'drivers' => $payload,
                ]);

            if ($response->successful() && isset($response->json()['drivers'])) {
                $rankedData = $response->json()['drivers'];
                $rankMap = [];
                foreach ($rankedData as $item) {
                    $rankMap[$item['driver_id']] = [
                        'score'   => $item['ai_score'],
                        'rank'    => $item['ai_rank'],
                        'reasons' => $item['reasons'] ?? [],
                    ];
                }

                $drivers = $drivers->sortBy(fn($d) => $rankMap[$d->id]['rank'] ?? 9999)->values();

                foreach ($drivers as $driver) {
                    if (isset($rankMap[$driver->id])) {
                        $driver->ai_score   = $rankMap[$driver->id]['score'];
                        $driver->ai_rank    = $rankMap[$driver->id]['rank'];
                        $driver->ai_reasons = $rankMap[$driver->id]['reasons'];
                    }
                }

                return true;
            }
        } catch (\Throwable $e) {
            // خدمة الـ AI غير شغالة أو أغلقت في التيرمينال — تراجع تلقائي وصامت للترتيب العادي
            Log::info('DriverMatchingService: AI Ranker unavailable, falling back to rating: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * تجميع الخصائص التشغيلية الثمانية لنموذج LightGBM Ranker دفعة واحدة (Batch)
     */
    protected function buildBatchRankingPayload(Collection $drivers): array
    {
        $driverIds = $drivers->pluck('id')->all();

        // 1. تقييمات آخر 30 يوماً
        $recentReviews = \App\Models\Shared\DriverReview::whereIn('driver_id', $driverIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('driver_id')
            ->selectRaw('driver_id, AVG(rating) as avg_recent')
            ->pluck('avg_recent', 'driver_id');

        // 2. إحصائيات الرحلات (المكتملة مقابل الإجمالي)
        $tripStats = \App\Models\Shared\Trip::whereIn('driver_id', $driverIds)
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as total_trips, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_trips')
            ->get()
            ->keyBy('driver_id');

        // 3. نسبة الالتزام بالمواعيد
        $punctualityStats = \App\Models\Shared\Trip::whereIn('driver_id', $driverIds)
            ->where('status', 'completed')
            ->whereNotNull('scheduled_start_time')
            ->whereNotNull('actual_start_time')
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as scheduled_trips, SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, scheduled_start_time, actual_start_time) <= 15 THEN 1 ELSE 0 END) as on_time_trips')
            ->get()
            ->keyBy('driver_id');

        // 4. الشكاوى المؤكدة
        $complaints = \App\Models\Shared\Complaint::whereIn('driver_id', $driverIds)
            ->whereIn('status', ['approved', 'resolved', 'under_investigation'])
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as cnt')
            ->pluck('cnt', 'driver_id');

        // 5. الأعطال
        $breakdowns = \App\Models\Shared\Trip::whereIn('driver_id', $driverIds)
            ->where(function ($q) {
                $q->where('status', 'suspended_breakdown')->orWhereNotNull('suspension_reason');
            })
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as cnt')
            ->pluck('cnt', 'driver_id');

        // 6. الغيابات
        $absences = \App\Models\Driver\DriverAbsence::whereIn('driver_id', $driverIds)
            ->where('absence_date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as cnt')
            ->pluck('cnt', 'driver_id');

        $payload = [];
        foreach ($drivers as $driver) {
            $rawRating = (float) ($driver->rating_avg ?? 5.0);
            $normRating = round($rawRating / 5.0, 4);

            $recentAvg = $recentReviews[$driver->id] ?? null;
            $normRecent = $recentAvg ? round(((float)$recentAvg) / 5.0, 4) : $normRating;

            $tStat = $tripStats[$driver->id] ?? null;
            $totalT = $tStat ? (int) $tStat->total_trips : 0;
            $compT  = $tStat ? (int) $tStat->completed_trips : 0;
            $tripComp = $totalT > 0 ? round($compT / $totalT, 4) : 1.0;

            $pStat = $punctualityStats[$driver->id] ?? null;
            $schedT = $pStat ? (int) $pStat->scheduled_trips : 0;
            $onTimeT = $pStat ? (int) $pStat->on_time_trips : 0;
            $punct = $schedT > 0 ? round($onTimeT / $schedT, 4) : 1.0;

            $compCnt = (int) ($complaints[$driver->id] ?? 0);
            $normComp = round(min(1.0, $compCnt / 5.0), 4);

            $bkCnt = (int) ($breakdowns[$driver->id] ?? 0);
            $normBk = round(min(1.0, $bkCnt / 3.0), 4);

            $absCnt = (int) ($absences[$driver->id] ?? 0);
            $normAbs = round(min(1.0, $absCnt / 5.0), 4);

            $payload[] = [
                'driver_id'            => $driver->id,
                'rating'               => $normRating,
                'recent_rating'        => $normRecent,
                'trip_completion'      => $tripComp,
                'successful_stops'     => 1.0,
                'punctuality'          => $punct,
                'confirmed_complaints' => $normComp,
                'breakdown'            => $normBk,
                'driver_absence'       => $normAbs,
            ];
        }

        return $payload;
    }
}