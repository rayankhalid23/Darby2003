<?php

namespace App\Services\Shared;

use App\Models\Shared\SubscriptionRequest;
use App\Models\Parent\ParentModel;
use App\Models\Driver\Driver;
use App\Models\Shared\ActiveSubscription;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Database\QueryException;

use App\Models\Shared\PricingSetting;
use App\Models\Driver\DriverAbsence;
use App\Models\Parent\Child;
use Throwable;

use Carbon\Carbon;
use Exception;

class SubscriptionRequestService
{
    protected \App\Services\Trip\MasterRouteStopSyncService $masterRouteStopSyncService;
    protected NotificationService $notificationService;
    protected PricingCalculator $pricingCalculator;

    public function __construct(
        \App\Services\Trip\MasterRouteStopSyncService $masterRouteStopSyncService,
        NotificationService $notificationService,
        ?PricingCalculator $pricingCalculator = null
    ) {
        $this->masterRouteStopSyncService = $masterRouteStopSyncService;
        $this->notificationService = $notificationService;
        $this->pricingCalculator = $pricingCalculator ?? app(PricingCalculator::class);
    }
    public function createRequest(array $data, $user): SubscriptionRequest
    {
        // ─── استخراج الحقول المشتركة على مستوى الطلب كله ───────────────────────
        $sharedSubscriptionType = $data['subscription_type'] ?? null;
        $sharedTripDirection    = $data['trip_direction']    ?? 'both';
        $sharedStartDate        = $data['start_date']        ?? null;
        $sharedEndDate          = $data['end_date']          ?? $sharedStartDate;
        $sharedHomeAddress      = $data['home_address']      ?? [];
        $sharedHomeAddressId    = $data['home_address_id']   ?? null;
        // الفترة اختيارية على مستوى الطلب؛ غيابها يعني «افترض تفضيل كل طفل المسجَّل».
        $sharedTiming           = $data['timing']            ?? null;

        // التحقق من عدم وجود تعارض مع اشتراكات نشطة سارية للأطفال
        // نُمرر الحقول المشتركة داخل كل طفل مؤقتاً لاستخدامها في دالة التحقق
        if (isset($data['children']) && is_array($data['children'])) {
            $childrenWithDates = array_map(function ($child) use ($sharedStartDate, $sharedEndDate) {
                return array_merge($child, [
                    'start_date' => $sharedStartDate,
                    'end_date'   => $sharedEndDate,
                ]);
            }, $data['children']);
            $this->validateChildActiveSubscriptionConflicts($childrenWithDates);
        }

        $subscriptionRequest = DB::transaction(function () use (
            $data, $user,
            $sharedSubscriptionType, $sharedTripDirection,
            $sharedStartDate, $sharedEndDate, $sharedHomeAddress, $sharedHomeAddressId, $sharedTiming
        ) {
            $parentId = null;

            if (is_object($user)) {
                if (method_exists($user, 'parent') && $user->parent) {
                    $parentId = $user->parent->id;
                } else {
                    $parentId = (int) $user->id;
                }
            } elseif (is_numeric($user)) {
                $parentId = (int) $user;
            }

            if (!$parentId) {
                throw new \InvalidArgumentException("حساب ولي الأمر (Parent Profile) غير مكتمل أو غير موجود لهذا المستخدم.");
            }

            // عنوان محفوظ (home_address_id) له الأولوية على الإحداثيات الخام
            if ($sharedHomeAddressId) {
                $savedAddress = \App\Models\Parent\Address::where('id', $sharedHomeAddressId)
                    ->where('user_id', $parentId)
                    ->first();
                if ($savedAddress) {
                    $sharedHomeAddress = [
                        'label' => $savedAddress->label,
                        'lat'   => $savedAddress->lat,
                        'lng'   => $savedAddress->lng,
                    ];
                }
            }

            // 0. تحميل بيانات الأطفال مع مدارسهم (المنزل أصبح مشتركاً من $sharedHomeAddress)
            $childModels = Child::with(['address', 'school'])
                ->whereIn('id', collect($data['children'])->pluck('child_id')->filter()->all())
                ->get()
                ->keyBy('id');

            // 1. جلب إعدادات التسعير
            $pricingSetting = PricingSetting::first();

            $childrenCount   = count($data['children']);
            $discountPercent = $this->pricingCalculator
                ->discountPercentForChildrenCount($childrenCount, $pricingSetting);

            $driverModel = Driver::with('vehicles')->find($data['driver_id']);

            if (!$driverModel) {
                throw new Exception('السائق المحدد غير موجود.', 422);
            }

            $totalOrderRawPrice            = 0.0;
            $totalOrderDiscount            = 0.0;
            $totalOrderAmountAfterDiscount = 0.0;
            $childrenPivotData             = [];
            $seatSpecs                     = [];
            $sharedWorkingDaysCount        = null;

            foreach ($data['children'] as $child) {
                $childId    = (int) $child['child_id'];
                $childModel = $childModels->get($childId);

                // ─── فترة هذا الطفل ────────────────────────────────────────
                // ⚠️ كانت مثبَّتة على 'BOTH' لكل طلب، فكان طفلٌ يركب صباحاً فقط
                // يحجز مقعداً في الفترة المسائية أيضاً — أي ضعف استهلاك طاقة
                // السائق وإغلاق رحلات لا علاقة له بها. المصدر الصحيح هو تفضيله
                // المسجَّل، وما يرسله ولي الأمر صراحةً يعلو عليه.
                $childTiming = $sharedTiming
                    ?? \App\Models\Driver\DriverSeatSlot::timingFromPreferredSlot($childModel?->preferred_time_slot);

                if (!$childTiming) {
                    $childName = $childModel?->full_name ?? "#{$childId}";
                    throw new Exception(
                        "تعذّر تحديد فترة الاشتراك للطفل [{$childName}]. يرجى تحديد الفترة (صباحي/مسائي/كلاهما) في بياناته أو في الطلب.",
                        422
                    );
                }

                $seatSpecs[] = [
                    'timing'     => $childTiming,
                    'direction'  => $sharedTripDirection,
                    'start_date' => Carbon::parse($sharedStartDate)->toDateString(),
                    'end_date'   => Carbon::parse($sharedEndDate ?: $sharedStartDate)->toDateString(),
                ];

                // ─── حساب السعر باستخدام الحقول المشتركة ───────────────────
                $pricing = $this->pricingCalculator->calculateForChild(
                    child:            $childModel,
                    driver:           $driverModel,
                    subscriptionType: $sharedSubscriptionType,
                    direction:        $sharedTripDirection,
                    startDate:        $sharedStartDate,
                    endDate:          $sharedEndDate,
                    distanceKm:       isset($child['distance_km']) ? (float) $child['distance_km'] : null,
                    discountPercent:  $discountPercent,
                    settings:         $pricingSetting,
                );

                $totalOrderRawPrice            += $pricing['raw_total'];
                $totalOrderDiscount            += $pricing['discount_amount'];
                $totalOrderAmountAfterDiscount += $pricing['total_after_discount'];
                $sharedWorkingDaysCount       ??= $pricing['working_days'];

                // لقطة مدرسة الطفل فقط — عنوان المنزل مشترك على مستوى الطلب
                $schoolSnapshot = $this->resolveChildSchoolSnapshot($child, $childModel);

                $childrenPivotData[$childId] = [
                    ...$schoolSnapshot,
                    // الحقول المشتركة (subscription_type/trip_direction/start_date/end_date/home_*)
                    // موجودة فقط على مستوى الطلب requests الآن — تُقرأ من هناك لا من هذا الـpivot.
                    'timing'                      => $childTiming,
                    // التسعير
                    'distance_km'                 => $pricing['distance_km'],
                    'trip_price'                  => $pricing['trip_price_after_discount'],
                    'price_per_child'             => $pricing['raw_total'],
                    'discount_amount'             => $pricing['discount_amount'],
                    'total_amount_after_discount' => $pricing['total_after_discount'],
                    'driver_net_price'            => $pricing['driver_net'],
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ];
            }

            $totalOrderRawPrice            = round($totalOrderRawPrice, 2);
            $totalOrderDiscount            = round($totalOrderDiscount, 2);
            $totalOrderAmountAfterDiscount = round($totalOrderAmountAfterDiscount, 2);

            // ─── جدوى الطلب تُفحص قبل لمس المال ────────────────────────────
            // ⚠️ كان الخصم يسبق كل فحص للمقاعد والفترات: يُخصم من ولي الأمر ثم
            // يفشل الطلب عند القبول لأن السائق لا يعمل في الفترة أو لا مقعد لديه،
            // فيدفع مقابل اشتراك مستحيل من لحظة إنشائه.
            foreach (array_unique(array_column($seatSpecs, 'timing')) as $timingToCheck) {
                $this->validateDriverShiftCompatibility($driverModel, $timingToCheck, $sharedTripDirection);
            }

            $this->assertSeatCellsAvailable($driverModel, $this->buildSeatCells($seatSpecs));

            // فحص رصيد المحفظة قبل إنشاء الطلب
            $parentModel = ParentModel::with('wallet')->find($parentId);
            if ($totalOrderAmountAfterDiscount > 0) {
                $this->validateAndDeductWalletBalance($parentModel, $totalOrderAmountAfterDiscount);
            }

            // 2. إنشاء الطلب الرئيسي مع الحقول المشتركة
            $subscriptionRequest = SubscriptionRequest::create([
                'parent_id'                   => $parentId,
                'driver_id'                   => $data['driver_id'],
                'status'                      => defined(SubscriptionRequest::class . '::STATUS_PENDING') ? SubscriptionRequest::STATUS_PENDING : 'pending',
                'total_price'                 => $totalOrderRawPrice,
                'discount_amount'             => $totalOrderDiscount,
                'total_amount_after_discount' => $totalOrderAmountAfterDiscount,
                'notes'                       => $data['notes'] ?? null,
                // ── الحقول المشتركة الجديدة ──────────────────────────────
                'subscription_type'           => $sharedSubscriptionType,
                'trip_direction'              => $sharedTripDirection,
                'start_date'                  => $sharedStartDate,
                'end_date'                    => $sharedEndDate,
                'working_days_count'          => $sharedWorkingDaysCount,
                'home_label'                  => $sharedHomeAddress['label'] ?? null,
                'home_lat'                    => isset($sharedHomeAddress['lat']) ? (float) $sharedHomeAddress['lat'] : null,
                'home_lng'                    => isset($sharedHomeAddress['lng']) ? (float) $sharedHomeAddress['lng'] : null,
                'home_address_id'             => $sharedHomeAddressId,
            ]);

            // 3. ربط الأطفال بجدول الـ Pivot
            $subscriptionRequest->children()->sync($childrenPivotData);

            return $subscriptionRequest->load(['children.school', 'parent', 'driver.user', 'driver.vehicle']);
        });

        // 🔔 إرسال إشعار لحظي للسائق بوجود طلب اشتراك جديد
        try {
            $driverUser = $subscriptionRequest->driver?->user;
            if ($driverUser) {
                $parentName = $subscriptionRequest->parent?->user?->full_name ?? 'ولي الأمر';
                $this->notifyUser(
                    $driverUser,
                    'طلب اشتراك جديد 🆕',
                    "لديك طلب اشتراك جديد رقم #{$subscriptionRequest->id} من [{$parentName}] بانتظار المراجعة.",
                    'new_subscription_request',
                    (string) $subscriptionRequest->id,
                    ['request_id' => (string) $subscriptionRequest->id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار طلب الاشتراك الجديد للسائق: " . $e->getMessage());
        }

        return $subscriptionRequest;
    }
   

    /**
     * تجهيز لقطة (Snapshot) لاسم وإحداثيات منزل الطفل ومدرسته لتُحفظ في request_children.
     * (تُستخدم في الطلبات القديمة فقط — الطلبات الجديدة تستخدم resolveChildSchoolSnapshot)
     *
     * @param  array  $childInput  عنصر الطفل كما ورد في payload الطلب
     * @param  \App\Models\Parent\Child|null  $childModel  الطفل مع علاقتَي address و school
     * @return array<string, mixed>
     */
    private function resolveChildLocationSnapshot(array $childInput, ?Child $childModel): array
    {
        $address = $childModel?->address;
        $school  = $childModel?->school;

        $pick = function (array $keys, $fallback) use ($childInput) {
            foreach ($keys as $key) {
                $value = $childInput[$key] ?? null;
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return $fallback;
        };

        $toCoordinate = fn ($value) => is_numeric($value) ? (float) $value : null;

        return [
            'home_label'   => $pick(['home_label', 'pickup_label'], $address?->label),
            'home_lat'     => $toCoordinate($pick(['home_lat', 'pickup_lat'], $address?->lat)),
            'home_lng'     => $toCoordinate($pick(['home_lng', 'pickup_lng'], $address?->lng)),
            'school_label' => $pick(['school_label', 'school_name', 'dropoff_label'], $school?->name),
            'school_lat'   => $toCoordinate($pick(['school_lat', 'dropoff_lat'], $school?->lat)),
            'school_lng'   => $toCoordinate($pick(['school_lng', 'dropoff_lng'], $school?->lng)),
        ];
    }

    /**
     * تجهيز لقطة (Snapshot) لاسم وإحداثيات مدرسة الطفل فقط لتُحفظ في request_children.
     *
     * الفرق عن resolveChildLocationSnapshot: عنوان المنزل أصبح مشتركاً على مستوى الطلب
     * كله (جدول requests) وليس لكل طفل على حدة، لذا هذه الدالة تعيد مدرسة الطفل فحسب.
     *
     * @param  array  $childInput  عنصر الطفل كما ورد في payload الطلب
     * @param  \App\Models\Parent\Child|null  $childModel  الطفل مع علاقة school
     * @return array<string, mixed>
     */
    private function resolveChildSchoolSnapshot(array $childInput, ?Child $childModel): array
    {
        $school = $childModel?->school;

        $pick = function (array $keys, $fallback) use ($childInput) {
            foreach ($keys as $key) {
                $value = $childInput[$key] ?? null;
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
            return $fallback;
        };

        $toCoordinate = fn ($value) => is_numeric($value) ? (float) $value : null;

        return [
            'school_label' => $pick(['school_label', 'school_name', 'dropoff_label'], $school?->name),
            'school_lat'   => $toCoordinate($pick(['school_lat', 'dropoff_lat'], $school?->lat)),
            'school_lng'   => $toCoordinate($pick(['school_lng', 'dropoff_lng'], $school?->lng)),
        ];
    }


    /**
     * حساب عدد أيام العمل الفعلية (استثناء الجمعة والسبت)
     */
    public function calculateWorkingDays(string $startDateStr, string $endDateStr): int
    {
        $start = Carbon::parse($startDateStr)->startOfDay();
        $end   = Carbon::parse($endDateStr)->startOfDay();

        if ($start->gt($end)) {
            return 0;
        }

        $workingDays = 0;
        $current = $start->copy();

        while ($current->lte($end)) {
            if (!$current->isFriday() && !$current->isSaturday()) {
                $workingDays++;
            }
            $current->addDay();
        }

        return $workingDays;
    }

    /**
     * التحقق من عدم وجود تعارض بين أيام الطلب الجديد وأي اشتراك نشط للأطفال،
     * مع استثناء الأيام التي سجل فيها سائق الاشتراك النشط غيابه.
     *
     * @param array $childrenData
     * @throws Exception
     */
    public function validateChildActiveSubscriptionConflicts(array $childrenData): void
    {
        foreach ($childrenData as $childItem) {
            $childId = (int) ($childItem['child_id'] ?? 0);
            if (!$childId) {
                continue;
            }

            $reqStartDateStr = $childItem['start_date'] ?? null;
            $reqEndDateStr   = $childItem['end_date'] ?? $reqStartDateStr;

            if (!$reqStartDateStr) {
                continue;
            }

            $reqStart = Carbon::parse($reqStartDateStr)->startOfDay();
            $reqEnd   = Carbon::parse($reqEndDateStr)->startOfDay();

            if ($reqStart->gt($reqEnd)) {
                continue;
            }

            // تجميع كافة الأيام المطلوبة لهذا الطفل
            $requestedDaysOfWeek = isset($childItem['days_of_week']) && is_array($childItem['days_of_week']) && count($childItem['days_of_week']) > 0
                ? array_map('strtolower', $childItem['days_of_week'])
                : null;

            $requestedDates = [];
            $cur = $reqStart->copy();
            while ($cur->lte($reqEnd)) {
                $dayName = strtolower($cur->englishDayOfWeek);
                if ($requestedDaysOfWeek !== null) {
                    if (in_array($dayName, $requestedDaysOfWeek)) {
                        $requestedDates[] = $cur->toDateString();
                    }
                } else {
                    if (!$cur->isFriday() && !$cur->isSaturday()) {
                        $requestedDates[] = $cur->toDateString();
                    } elseif ($reqStart->equalTo($reqEnd)) {
                        $requestedDates[] = $cur->toDateString();
                    }
                }
                $cur->addDay();
            }

            if (empty($requestedDates)) {
                $requestedDates[] = $reqStart->toDateString();
            }

            // البحث عن أي اشتراكات نشطة سارية لهذا الطفل
            $activeSubs = ActiveSubscription::forChild($childId)
                ->where('status', 'active')
                ->with(['subscriptionRequest.children', 'driver.user', 'child'])
                ->get();

            foreach ($activeSubs as $activeSub) {
                $subReq = $activeSub->subscriptionRequest;
                if (!$subReq || in_array($subReq->status, [SubscriptionRequest::STATUS_CANCELLED, SubscriptionRequest::STATUS_REJECTED, 'completed'])) {
                    continue;
                }

                $childPivot = $subReq->children?->firstWhere('id', $childId)?->pivot;
                $activeStartDateStr = $childPivot?->start_date ?? $subReq->start_date;
                $activeEndDateStr   = $childPivot?->end_date ?? $subReq->end_date ?? $activeStartDateStr;

                if (!$activeStartDateStr) {
                    continue;
                }

                $actStart = Carbon::parse($activeStartDateStr)->startOfDay();
                $actEnd   = Carbon::parse($activeEndDateStr)->startOfDay();

                // التحقق من وجود تداخل بين الأيام المطلوبة وفترة الاشتراك النشط
                $conflictingDates = [];
                $driverId = $activeSub->driver_id;

                foreach ($requestedDates as $reqDateStr) {
                    $reqDate = Carbon::parse($reqDateStr)->startOfDay();
                    if ($reqDate->betweenIncluded($actStart, $actEnd)) {
                        // اليوم يقع ضمن فترة الاشتراك النشط — نفحص هل السائق مسجل غياباً في هذا اليوم
                        $isAbsent = DriverAbsence::where('driver_id', $driverId)
                            ->whereDate('absence_date', $reqDateStr)
                            ->exists();

                        if (!$isAbsent) {
                            $conflictingDates[] = $reqDateStr;
                        }
                    }
                }

                if (!empty($conflictingDates)) {
                    $childName = $activeSub->child?->full_name 
                        ?? Child::find($childId)?->full_name 
                        ?? "الطفل (#{$childId})";
                    $driverName = $activeSub->driver?->user?->full_name 
                        ?? "السائق (#{$driverId})";
                    $datesFormatted = implode(', ', array_unique($conflictingDates));

                    throw new Exception(
                        "لا يمكن إرسال طلب الاشتراك: الطفل [{$childName}] لديه اشتراك نشط بالفعل مع السائق [{$driverName}] خلال الأيام ({$datesFormatted})، والسائق غير مسجل كغائب في هذه الأيام. يُسمح بطلب اشتراك جديد فقط في الأيام التي يُسجل فيها السائق غيابه.",
                        422
                    );
                }
            }
        }
    }

    
    



    // ============================================================
    // تحقق الفلتر 1: تطابق الفترة/الاتجاه مع تفضيلات السائق
    // ============================================================

    private function validateDriverShiftCompatibility(Driver $driver, string $timing, string $direction): void
    {
        // تحديد الـ slots المطلوبة حسب الطلب
        $requiredSlots = \App\Models\Driver\DriverSeatSlot::resolveSlots($timing, $direction);
        $slotLabels    = \App\Models\Driver\DriverSeatSlot::slotLabels();

        foreach ($requiredSlots as $slot) {
            if (!$driver->$slot) {
                throw new Exception(
                    "السائق لا يعمل في فترة [{$slotLabels[$slot]}]. يرجى اختيار سائق يغطي هذه الفترة."
                );
            }
        }
    }

    // ============================================================
    // تحقق المقاعد مع الوعي الزمني
    // ============================================================

    /**
     * يتحقق من توفر المقاعد عبر كل الخانات (slot × date) في فترة الاشتراك.
     * مقبول ⟺ minAvailable ≥ childrenCount
     */
    private function validateSeatAvailabilityForPeriod(
        Driver $driver,
        string $timing,
        string $direction,
        int    $childrenCount,
        string $startDate,
        string $endDate
    ): void {
        $requiredSlots = \App\Models\Driver\DriverSeatSlot::resolveSlots($timing, $direction);
        $slotLabels    = \App\Models\Driver\DriverSeatSlot::slotLabels();

        if (empty($requiredSlots)) {
            return;
        }

        $capacity = (int) ($driver->vehicle?->capacity_manual ?? $driver->vehicles?->where('status','Active')->first()?->capacity_manual ?? 0);

        $minAvail = \App\Models\Driver\DriverSeatSlot::minAvailableOverPeriod(
            $driver->id,
            $requiredSlots,
            $startDate,
            $endDate,
            $capacity
        );

        if ($minAvail < $childrenCount) {
            $slotsLabel = implode(' + ', array_map(fn($s) => $slotLabels[$s] ?? $s, $requiredSlots));
            throw new \Exception(
                "لا توجد مقاعد كافية في [{$slotsLabel}] خلال فترة الاشتراك. المتاح: {$minAvail}، المطلوب: {$childrenCount}."
            );
        }
    }

    // ============================================================
    // تحقق المقاعد بالخانات (سائق · تاريخ · فترة)
    // ============================================================

    /**
     * طاقة السائق = سعة مركبته **النشطة**.
     *
     * ⚠️ العلاقة `vehicle()` هي hasOne بلا فلتر حالة، فكانت تُرجع أحياناً مركبة
     * مرفوضة أو قديمة سعتها أكبر، بينما القبول لا يتم إلا بمركبة Active — فيُقبل
     * الطلب على سعة لا وجود لها على الأرض.
     */
    private function activeVehicleCapacity(Driver $driver): int
    {
        $driver->loadMissing('vehicles');

        return (int) ($driver->vehicles->firstWhere('status', 'Active')?->capacity_manual ?? 0);
    }

    /**
     * خانات حجز طفل واحد: أيام مداه × فترات اشتراكه.
     *
     * الجمعة والسبت إجازة فلا تُحجز — تماماً كما يفعل createActiveSubscriptions،
     * وإلا اختلف ما يُفحص عما يُحجز.
     *
     * @return array<string, int>  مفتاح "Y-m-d|slot" وقيمته عدد المقاعد المطلوبة
     */
    private function seatCellsForChild(
        string $timing,
        string $direction,
        string $startDate,
        string $endDate,
        int    $seats = 1
    ): array {
        $slots = \App\Models\Driver\DriverSeatSlot::resolveSlots($timing, $direction);
        if (empty($slots)) {
            return [];
        }

        $cells = [];
        $cursor = Carbon::parse($startDate)->startOfDay();
        $last   = Carbon::parse($endDate)->startOfDay();

        while ($cursor->lte($last)) {
            if (!$cursor->isFriday() && !$cursor->isSaturday()) {
                $day = $cursor->toDateString();
                foreach ($slots as $slot) {
                    $key = $day . '|' . $slot;
                    $cells[$key] = ($cells[$key] ?? 0) + $seats;
                }
            }
            $cursor->addDay();
        }

        return $cells;
    }

    /**
     * تجميع خانات كل أطفال الطلب.
     *
     * ⚠️ الجمع لكل طفل على حدة لا اعتماداً على أول طفل: طفلان بفترتين مختلفتين
     * يتقاسمان بعض الرحلات ويستقل كل منهما بأخرى، والفحص على أول طفل وحده كان
     * يمرّر طلباً يتجاوز الطاقة في الرحلات التي لا يشترك فيها الأول.
     *
     * @param  array<int, array{timing: string, direction: string, start_date: string, end_date: string}>  $specs
     * @return array<string, int>
     */
    private function buildSeatCells(array $specs): array
    {
        $cells = [];

        foreach ($specs as $spec) {
            foreach ($this->seatCellsForChild(
                $spec['timing'],
                $spec['direction'],
                $spec['start_date'],
                $spec['end_date'],
            ) as $key => $seats) {
                $cells[$key] = ($cells[$key] ?? 0) + $seats;
            }
        }

        return $cells;
    }

    /**
     * يرمي عند أول رحلة لا تتسع — باسم اليوم والفترة، لأن «لا توجد مقاعد كافية»
     * بلا تاريخ لا تدل ولي الأمر ولا السائق على اليوم المتعطل.
     *
     * @param  array<string, int>  $cells
     */
    private function assertSeatCellsAvailable(Driver $driver, array $cells): void
    {
        if (empty($cells)) {
            throw new Exception(
                'تعذّر تحديد رحلات الاشتراك من الفترة والاتجاه والتواريخ المطلوبة، فلا يمكن التحقق من المقاعد.',
                422
            );
        }

        $capacity = $this->activeVehicleCapacity($driver);

        if ($capacity <= 0) {
            throw new Exception(
                'لا توجد مركبة نشطة بسعة معتمدة لهذا السائق، فلا يمكن حجز مقاعد معه.',
                422
            );
        }

        $dates = array_values(array_unique(array_map(
            fn($key) => explode('|', $key)[0],
            array_keys($cells)
        )));

        // أيام الغياب: طاقة اليوم صفر.
        $absentDates = DriverAbsence::where('driver_id', $driver->id)
            ->whereIn('absence_date', $dates)
            ->pluck('absence_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->flip()
            ->all();

        // المحجوز حالياً لكل خانة — استعلام واحد لكل الطلب لا استعلام لكل يوم.
        $booked = \App\Models\Driver\DriverSeatSlot::where('driver_id', $driver->id)
            ->whereIn('date', $dates)
            ->get(['slot', 'date', 'booked'])
            ->mapWithKeys(fn($row) => [
                Carbon::parse($row->date)->toDateString() . '|' . $row->slot => (int) $row->booked,
            ])
            ->all();

        $labels = \App\Models\Driver\DriverSeatSlot::slotLabels();

        foreach ($cells as $key => $needed) {
            [$date, $slot] = explode('|', $key);
            $label = $labels[$slot] ?? $slot;

            if (isset($absentDates[$date])) {
                throw new Exception(
                    "السائق مسجَّل كغائب يوم [{$date}]، فلا يمكن حجز رحلة [{$label}] في ذلك اليوم.",
                    422
                );
            }

            $available = max(0, $capacity - ($booked[$key] ?? 0));

            if ($available < $needed) {
                throw new Exception(
                    "لا توجد مقاعد كافية في رحلة [{$label}] يوم [{$date}]. المتاح: {$available}، المطلوب: {$needed}.",
                    422
                );
            }
        }
    }

    /**
     * فحص مقاعد طلب قائم انطلاقاً من صف كل طفل في request_children.
     */
    private function assertSeatsAvailableForRequest(SubscriptionRequest $req): void
    {
        $req->loadMissing(['children', 'driver.vehicles']);

        $specs = [];

        foreach ($req->children as $child) {
            $pivot = $child->pivot;

            $timing = $pivot->timing
                ?? \App\Models\Driver\DriverSeatSlot::timingFromPreferredSlot($child->preferred_time_slot);

            $direction = $req->trip_direction;
            $start     = $req->start_date;
            $end       = $req->end_date ?? $start;

            if (!$timing || !$direction || !$start) {
                throw new Exception(
                    "بيانات الاشتراك ناقصة للطفل [{$child->full_name}] (الفترة/الاتجاه/التاريخ)، فلا يمكن التحقق من المقاعد.",
                    422
                );
            }

            $specs[] = [
                'timing'     => (string) $timing,
                'direction'  => (string) $direction,
                'start_date' => Carbon::parse($start)->toDateString(),
                'end_date'   => Carbon::parse($end)->toDateString(),
            ];
        }

        $this->assertSeatCellsAvailable($req->driver, $this->buildSeatCells($specs));
    }

    // ============================================================
    // 1. تحديث حالة الطلب (نقطة الدخول الرئيسية)
    // ============================================================

    public function updateStatus(
        SubscriptionRequest $subscriptionRequest,
        string $status,
        ?string $rejectionReason = null
    ): SubscriptionRequest {

        return DB::transaction(function () use ($subscriptionRequest, $status, $rejectionReason) {
            
            // تحميل العلاقات المطلوبة مسبقاً لتجنب استعلامات N+1
            $subscriptionRequest->loadMissing(['parent.user', 'children', 'school', 'driver.user']);
            $parent = $subscriptionRequest->parent;

            if ($status === SubscriptionRequest::STATUS_ACCEPTED) {
                return $this->handleAcceptance($subscriptionRequest, $parent);
            }

            if ($status === SubscriptionRequest::STATUS_REJECTED) {
                return $this->handleRejection($subscriptionRequest, $parent, $rejectionReason);
            }

            throw new Exception("الحالة المطلوبة '{$status}' غير مدعومة.");
        });
    }

    // ============================================================
    // 2. منطق القبول
    // ============================================================

    private function handleAcceptance(SubscriptionRequest $req, User|ParentModel|null $parent): SubscriptionRequest
    {
        // 0. إعادة التحقق من عدم تعارض أيام هذا الطلب مع أي اشتراك نشط أصبح موجوداً
        //    لنفس الطفل بعد إنشاء هذا الطلب (مثال: طلب آخر تم قبوله للطفل نفسه في نفس
        //    الفترة أثناء انتظار هذا الطلب). الفحص عند الإنشاء فقط لا يمنع هذا السباق.
        $this->validateChildActiveSubscriptionConflicts(
            $req->children->map(function ($child) use ($req) {
                return [
                    'child_id'   => $child->id,
                    'start_date' => $req->start_date,
                    'end_date'   => $req->end_date,
                ];
            })->all()
        );

        // 1. التحقق من وجود وحالة مركبة السائق وسعتها قبل أي تعديل
        $vehicle = \App\Models\Driver\Vehicle::where('driver_id', $req->driver_id)
            ->where('status', 'Active')
            ->first();

        if (!$vehicle) {
            throw new Exception("تعذر إتمام العملية: لا توجد مركبة نشطة مرتبطة بالسائق.");
        }

        // التحقق من توفر المقاعد مع الوعي الزمني الكامل بفترة الاشتراك
        $firstChild      = $req->children?->first();
        $firstChildPivot = $firstChild?->pivot;

        // ⚠️ جدول requests لا يحوي عمودَي timing و direction أصلاً (الموجود هو
        // trip_direction)، فكان $req->timing يساوي null دائماً وتسقط القيمة على
        // 'MORNING' الثابتة مهما كان الاشتراك. المصدر الصحيح هو صف الطفل في
        // request_children، ثم تفضيله المسجَّل، ولا قيمة افتراضية بعدهما.
        $timing = $firstChildPivot?->timing
            ?? \App\Models\Driver\DriverSeatSlot::timingFromPreferredSlot($firstChild?->preferred_time_slot);

        $direction = $firstChildPivot?->trip_direction ?? $req->trip_direction;

        // ⚠️ فترة/اتجاه غير معروفين ينتجان قائمة slots فارغة، فيُقبل الطلب ويُحجز المال
        // بينما يُنشأ مسار بلا shift_slot وبلا محطات لا يولّد له النظام أي رحلة أبداً.
        // الرفض هنا يمنع "اشتراكاً ميتاً" صامتاً.
        if (!$timing || !$direction) {
            throw new Exception(
                'تعذّر تحديد فترة أو اتجاه الرحلة من بيانات الطلب. لا يمكن قبول الطلب لأنه لن تُولَّد له أي رحلة.'
            );
        }

        // أقفل صفوف المقاعد المعنية لمنع السباق (race condition) عند القبول المتزامن
        $requiredSlots = \App\Models\Driver\DriverSeatSlot::resolveSlots($timing, $direction);

        if (empty($requiredSlots)) {
            throw new Exception(
                "تعذّر تحديد فترة/اتجاه الرحلة من بيانات الطلب (الفترة: {$timing}، الاتجاه: {$direction}). لا يمكن قبول الطلب لأنه لن تُولَّد له أي رحلة."
            );
        }

        \App\Models\Driver\DriverSeatSlot::where('driver_id', $req->driver_id)
            ->whereIn('slot', $requiredSlots)
            ->lockForUpdate()
            ->get();

        // الفحص يجمع خانات كل طفل على حدة لا خانات أول طفل مضروبة في عددهم،
        // فطفلان بفترتين مختلفتين لا يستهلكان نفس الرحلات.
        $this->assertSeatsAvailableForRequest($req);

        // 2. تحديث حالة الطلب الحالي إلى مقبول مع توثيق وقت الاستجابة
        $req->update([
            'status'       => SubscriptionRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        // 3. إلغاء الطلبات الأخرى المعلقة لنفس العميل ونفس التوقيت
        SubscriptionRequest::where('parent_id', $req->parent_id)
            ->where('status', SubscriptionRequest::STATUS_PENDING)
            ->where('id', '!=', $req->id)
            ->whereHas('children', function ($q) use ($timing) {
                $q->where('request_children.timing', $timing);
            })
            ->update(['status' => SubscriptionRequest::STATUS_CANCELLED]);

        // 4. تحديد الـ slot الأساسي (الفترة/الاتجاه) بناءً على تفضيلات السائق الثابتة
        $slots = \App\Models\Driver\DriverSeatSlot::resolveSlots($timing, $direction);
        $primarySlot = $slots[0] ?? null;

        // 5. البحث عن مسار رئيسي (Master Route) نشط بالفعل لنفس السائق لنفس الفترة/الاتجاه
        //    حتى لا يُنشأ مسار جديد مع كل طلب اشتراك مقبول، بل يُضاف الطفل إلى المسار الثابت الموجود.
        $route = $primarySlot
            ? \App\Models\Shared\Route::where('driver_id', $req->driver_id)
                ->where('shift_slot', $primarySlot)
                ->where('status', 'Active')
                ->first()
            : null;

        if ($route) {
            // مسار موجود بالفعل لهذا السائق/الفترة: نعيد استخدامه فقط ونحدّث المركبة إن تغيّرت
            if ($route->vehicle_id !== $vehicle->id) {
                $route->vehicle_id = $vehicle->id;
                $route->save();
            }
        } else {
            // لا يوجد مسار سابق لهذا السائق/الفترة: ننشئه لأول مرة فقط
            $distanceKm = 0;
            $durationMinutes = 0;
            $routeData = null;

            try {
                $osrm = new \App\Services\Shared\OsrmRoutingService();

                $driverPos = ['lat' => (float)($req->driver->current_lat ?? 0), 'lng' => (float)($req->driver->current_lng ?? 0)];
                $childPos  = ['lat' => (float)($req->home_lat ?? $req->pickup_lat ?? 0), 'lng' => (float)($req->home_lng ?? $req->pickup_lng ?? 0)];
                $schoolPos = ['lat' => (float)($req->school->lat ?? $req->school->latitude ?? $req->dropoff_lat ?? 0), 'lng' => (float)($req->school->lng ?? $req->school->longitude ?? $req->dropoff_lng ?? 0)];

                $routeData = $osrm->calculateRoute([$driverPos, $childPos, $schoolPos]);

                if ($routeData) {
                    $distanceInMeters = $routeData['routes'][0]['distance'] ?? 0;
                    $durationInSeconds = $routeData['routes'][0]['duration'] ?? 0;

                    $distanceKm = round($distanceInMeters / 1000, 2);
                    $durationMinutes = (int) ceil($durationInSeconds / 60);
                }
            } catch (\Exception $e) {
                Log::warning("فشل حساب المسار عبر OSRM للطلب ID: {$req->id} - " . $e->getMessage());
            }

            // ⚠️ كان يقرأ $req->timing (عمود غير موجود) فينتهي كل مسار في النظام
            // بنوع 'Morning' حتى المسارات المسائية. الفترة المحسوبة أعلاه هي المصدر.
            $timingUpper = strtoupper($timing);
            $routeType = ($timingUpper === 'EVENING' || $timingUpper === 'AFTERNOON') ? 'Afternoon' : 'Morning';

            $route = \App\Models\Shared\Route::create([
                'subscription_request_id' => $req->id,
                'driver_id'               => $req->driver_id,
                'vehicle_id'              => $vehicle->id,
                'route_name'              => \App\Models\Shared\Route::generateGenericRouteName($timing, $direction),
                'route_type'              => $routeType,
                'shift_slot'              => $primarySlot,
                // ⚠️ وقت الانطلاق يتبع اتجاه الـ slot: الذهاب من وقت الاصطحاب،
                // والإياب من وقت الانصراف — لا من وقت الاصطحاب الصباحي.
                'start_time'              => $this->masterRouteStopSyncService->resolveSlotStartTime($req, $primarySlot),
                'optimized_points'        => $routeData ? json_encode($routeData) : null,
                'total_distance'          => $distanceKm,
                'estimated_duration'      => $durationMinutes,
                'status'                  => 'Active'
            ]);
        }

        // 6. تفعيل اشتراكات الأطفال لجدول active_subscriptions وتفريغ المقاعد
        $this->createActiveSubscriptions($req, $route);

        // 6.2 حجز مبلغ الاشتراك في الأمانات — لكل أنواع الاشتراكات وليس اليومي فقط.
        // ⚠️ سابقاً كان الحجز محصوراً بـ single_day، فكانت الاشتراكات الشهرية (multi_day)
        // بلا أي حركة مالية إطلاقاً: لا يُخصم من ولي الأمر ولا يُصرف للسائق مهما نفّذ من رحلات.
        if ((float) $req->total_amount_after_discount > 0) {
            $this->holdSubscriptionFundsOnAcceptance($req, $parent);
        }

        // 6.3 إصدار الفاتورة المبدئية للاشتراك.
        // ⚠️ لم تكن تُصدر من أي مسار حيّ إطلاقاً، فبقيت شاشات الفواتير لولي الأمر
        // والسائق والأدمن فارغة، وبقي أمر التسوية بلا مدخلات. الفشل هنا لا يُسقط
        // قبول الطلب: الفاتورة مستند عرض، والحركة المالية تمت وسُجّلت قبله.
        try {
            app(\App\Services\Shared\FinancialService::class)->generateProformaInvoice($req);
        } catch (\Throwable $e) {
            Log::warning("فشل إصدار الفاتورة المبدئية للطلب ID {$req->id}: " . $e->getMessage());
        }

        // 6.5 مزامنة المسار الرئيسي (Master Route) لكل فترة/اتجاه مطلوبة (route_stops)
        // ⚠️ لا يُبتلع الخطأ هنا: القبول كله داخل DB::transaction، وقبول اشتراك ومعه
        // حجز مالي بينما المسار نصف مبني (أو بلا محطات) أسوأ من رفض القبول وإبلاغ السائق.
        $this->masterRouteStopSyncService->syncOnAcceptance($req, $route, $slots);

        // 7. إرسال إشعار القبول مع حمايته من إلغاء الـ Transaction
        try {
            $targetUser = ($parent instanceof User) ? $parent : ($parent?->user ?? User::find($req->parent_id));
            if ($targetUser) {
                $this->notifyUser(
                    $targetUser,
                    'تم قبول طلب الاشتراك',
                    "تم قبول طلبك مع السائق " . ($req->driver->user->full_name ?? 'السائق') . ". رقم الطلب: #{$req->id}",
                    'request_accepted',
                    (string) $req->id,
                    ['request_id' => (string) $req->id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار FCM عند قبول الطلب ID {$req->id}: " . $e->getMessage());
        }

        return $req->refresh()->load(['children', 'driver.user', 'parent.user']);
    }

    // ============================================================
    // 3. منطق الرفض
    // ============================================================

    private function handleRejection(SubscriptionRequest $req, User|ParentModel|null $parent, ?string $reason): SubscriptionRequest
    {
        $req->update([
            'status'           => SubscriptionRequest::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        try {
            $targetUser = ($parent instanceof User) ? $parent : ($parent?->user ?? User::find($req->parent_id));
            if ($targetUser) {
                $this->notifyUser(
                    $targetUser,
                    'تم رفض طلب الاشتراك',
                    "عذراً، تم رفض طلبك. السبب: " . ($reason ?? 'لم يحدد السائق سبباً.'),
                    'request_rejected',
                    (string) $req->id
                );
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار FCM عند رفض الطلب ID {$req->id}: " . $e->getMessage());
        }

        return $req->refresh();
    }

    /**
     * التحقق من الرصيد في محفظة ولي الأمر ومنع إرسال الطلب في حال عدم كفايته
     * (لا يتم حجز أو خصم المبلغ إلا عند قبول السائق للطلب)
     */
    protected function validateAndDeductWalletBalance(User|ParentModel|null $parent, float $totalPrice): void
    {
        if (!$parent) {
            throw new Exception("حساب ولي الأمر غير موجود.");
        }

        $requiredCents = (int) round($totalPrice * 100);
        $balanceCents  = (int) ($parent->balance ?? $parent->wallet?->balance ?? 0);

        if ($balanceCents < $requiredCents) {
            throw new Exception('عذراً، رصيد المحفظة غير كافٍ. يرجى شحن محفظتك بقيمة الرحلة لإتمام الطلب.');
        }
    }

    protected function holdSubscriptionFundsOnAcceptance(SubscriptionRequest $req, User|ParentModel|null $parent): void
    {
        if (!$parent) {
            $parent = User::find($req->parent_id) ?? ParentModel::find($req->parent_id);
        }
        if (!$parent) {
            throw new Exception("تعذر العثور على حساب ولي الأمر لحجز قيمة الرحلة.");
        }

        $amountDinar = (float) $req->total_amount_after_discount;
        $amountCents = (int) round($amountDinar * 100);

        $currentBalance = (int) ($parent->balance ?? $parent->wallet?->balance ?? 0);
        if ($currentBalance < $amountCents) {
            throw new Exception("تعذر قبول الطلب: رصيد محفظة ولي الأمر غير كافٍ لحجز مبلغ الرحلة.");
        }

        $balBefore = $currentBalance;
        $parent->withdraw($amountCents);
        $balAfter = (int) $parent->balance;

        $pricingSetting = PricingSetting::first();
        $commissionRate = (float) ($pricingSetting->platform_commission_rate ?? 8.00);
        $commissionAmount = round(($amountDinar * $commissionRate) / 100, 2);
        $driverNetAmount = max(0, round($amountDinar - $commissionAmount, 2));

        $driverNetAmountCents = (int) round($driverNetAmount * 100);
        $commissionCents = (int) round($commissionAmount * 100);

        $vault = \App\Models\Shared\MasterEscrowVault::getVault();
        $ledgerService = app(\App\Services\Shared\FinancialLedgerService::class);

        if ($req->subscription_type === 'single_day') {
            $vault->increment('parents_escrow_pool', $amountCents);

            $platformFinance = \App\Models\Shared\PlatformFinance::create([
                'subscription_request_id'    => $req->id,
                'parent_id'                  => $parent->id,
                'driver_id'                  => $req->driver_id,
                'total_amount'               => $amountDinar,
                'platform_commission_rate'   => $commissionRate,
                'platform_commission_amount' => $commissionAmount,
                'driver_net_amount'          => $driverNetAmount,
                'expected_trips_count'       => $this->resolveExpectedTripsCount($req),
                'settled_trips_count'        => 0,
                'settled_amount'             => 0,
                'status'                     => \App\Models\Shared\PlatformFinance::STATUS_HELD,
                'held_at'                    => now(),
            ]);

            $ledgerService->recordLedgerEntry(
                \App\Services\Shared\FinancialLedgerService::parentAccount($parent),
                "parents_escrow_pool",
                $amountCents,
                'subscription_hold',
                $balBefore,
                $balAfter,
                "REQ-HOLD-{$req->id}",
                [
                    'subscription_request_id' => $req->id,
                    'platform_finance_id'     => $platformFinance->id,
                ]
            );
        } else {
            if ($commissionCents > 0) {
                $vault->increment('platform_revenue_pool', $commissionCents);
            }

            $driver = Driver::find($req->driver_id) ?? Driver::where('user_id', $req->driver_id)->first();
            if ($driver) {
                $driverBalBefore = (int) $driver->balance;
                $driver->deposit($driverNetAmountCents);
                $driverBalAfter = (int) $driver->balance;

                if ($commissionCents > 0) {
                    $ledgerService->recordLedgerEntry(
                        \App\Services\Shared\FinancialLedgerService::parentAccount($parent),
                        'platform_revenue_pool',
                        $commissionCents,
                        'platform_commission',
                        $balBefore,
                        $balBefore - $commissionCents,
                        "COMMISSION-MULTI-{$req->id}",
                        ['subscription_request_id' => $req->id]
                    );
                }

                $ledgerService->recordLedgerEntry(
                    \App\Services\Shared\FinancialLedgerService::parentAccount($parent),
                    \App\Services\Shared\FinancialLedgerService::driverAccount($driver),
                    $driverNetAmountCents,
                    'subscription_payment',
                    $balBefore - $commissionCents,
                    $driverBalAfter,
                    "REQ-PAY-{$req->id}",
                    ['subscription_request_id' => $req->id]
                );
            }

            \App\Models\Shared\PlatformFinance::create([
                'subscription_request_id'    => $req->id,
                'parent_id'                  => $parent->id,
                'driver_id'                  => $req->driver_id,
                'total_amount'               => $amountDinar,
                'platform_commission_rate'   => $commissionRate,
                'platform_commission_amount' => $commissionAmount,
                'driver_net_amount'          => $driverNetAmount,
                'expected_trips_count'       => $this->resolveExpectedTripsCount($req),
                'settled_trips_count'        => $this->resolveExpectedTripsCount($req),
                'settled_amount'             => $amountDinar,
                'status'                     => \App\Models\Shared\PlatformFinance::STATUS_COMPLETED,
                'held_at'                    => now(),
                'settled_at'                 => now(),
            ]);
        }
    }

    /**
     * عدد الرحلات التي يغطيها الاشتراك = أيام العمل × عدد الرحلات في اليوم.
     * يُستخدم لتوزيع الأمانة تناسبياً على الرحلات بدل صرفها كاملة على أول رحلة.
     */
    protected function resolveExpectedTripsCount(SubscriptionRequest $req): int
    {
        $total = 0;

        foreach ($req->children as $child) {
            $workingDays  = max(1, (int) ($req->working_days_count ?? 1));
            $direction    = strtolower((string) ($req->trip_direction ?? 'both'));
            $tripsPerDay  = in_array($direction, ['one_way_morning', 'one_way_evening', 'go', 'return'], true) ? 1 : 2;

            $total = max($total, $workingDays * $tripsPerDay);
        }

        return max(1, $total);
    }

    /**
     * معالجة استرجاع المبالغ المحجوزة عند إلغاء الاشتراك وفق سياسة التعويض بعد تحرك السائق
     *
     * ⚠️ العملية بأكملها داخل معاملة واحدة إلزامياً: هي تخصم من مسبح الأمانات ثم تودع
     * في محفظة ولي الأمر ومحفظة السائق وتحدّث السجل المالي. أي فشل في المنتصف بدون
     * معاملة كان يعني خروج المال من الخزينة دون وصوله لأحد.
     * (اثنان من مستدعيي هذه الدالة — الإلغاء التلقائي من الكرون وإلغاء ولي الأمر —
     * لم يكونا يوفران أي معاملة.) معاملات Laravel المتداخلة آمنة عبر savepoints.
     */
    public function refundHeldFundsOnCancellation(int $requestId, string $cancelledBy, ?int $driverId = null): ?array
    {
        return DB::transaction(function () use ($requestId, $cancelledBy, $driverId) {
            return $this->performRefundHeldFundsOnCancellation($requestId, $cancelledBy, $driverId);
        });
    }

    /**
     * جسم عملية الاسترجاع — يُستدعى دائماً من داخل معاملة عبر الدالة العامة أعلاه.
     */
    protected function performRefundHeldFundsOnCancellation(int $requestId, string $cancelledBy, ?int $driverId = null): ?array
    {
        $finance = \App\Models\Shared\PlatformFinance::where('subscription_request_id', $requestId)
            ->where('status', \App\Models\Shared\PlatformFinance::STATUS_HELD)
            ->lockForUpdate()
            ->first();

        if (!$finance) {
            return null;
        }

        $ledgerService = app(\App\Services\Shared\FinancialLedgerService::class);
        $parent = $ledgerService->resolveParent($finance->parent_id, preferUserId: false);
        $driver = Driver::find($finance->driver_id) ?? Driver::where('user_id', $finance->driver_id)->first();
        $vault = \App\Models\Shared\MasterEscrowVault::getVault();

        $totalDinar = (float) $finance->total_amount;
        $totalCents = (int) round($totalDinar * 100);
        $commissionCents = (int) round((float)$finance->platform_commission_amount * 100);
        $driverNetCents = (int) round((float)$finance->driver_net_amount * 100);

        if ($cancelledBy === 'parent') {
            // Parent cancelled: give money to driver & platform
            $vault->decrement('parents_escrow_pool', $totalCents);
            
            if ($driverNetCents > 0 && $driver) {
                $driver->deposit($driverNetCents);
            }
            if ($commissionCents > 0) {
                $vault->increment('platform_revenue_pool', $commissionCents);
            }

            $finance->update([
                'status'            => \App\Models\Shared\PlatformFinance::STATUS_COMPLETED,
                'compensation_fee'  => $totalDinar,
                'settled_amount'    => $totalDinar,
                'settled_at'        => now(),
                'notes'             => 'تم إلغاء الرحلة من قِبل ولي الأمر. تم رفع المبلغ للسائق وخصم عمولة المنصة.',
            ]);

            try {
                if ($driverNetCents > 0 && $driver && $parent) {
                    $ledgerService->recordLedgerEntry(
                        'parents_escrow_pool',
                        \App\Services\Shared\FinancialLedgerService::driverAccount($driver),
                        $driverNetCents,
                        'subscription_payment', // Using standard payment since driver gets paid fully
                        0,
                        (int) $driver->balance,
                        "PAY-DRIVER-CANC-{$requestId}",
                        ['subscription_request_id' => $requestId, 'cancelled_by' => $cancelledBy]
                    );
                }
                if ($commissionCents > 0) {
                    $ledgerService->recordLedgerEntry(
                        'parents_escrow_pool',
                        'platform_revenue_pool',
                        $commissionCents,
                        'platform_commission',
                        0,
                        $commissionCents, // approximation for log
                        "COMMISSION-CANC-{$requestId}"
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("فشل تسجيل حركات السجل المالي للإلغاء من ولي الأمر ID {$requestId}: " . $e->getMessage());
            }

            return [
                'refund_amount'    => 0.0,
                'compensation_fee' => $totalDinar,
                'driver_net_pay'   => (float)$finance->driver_net_amount,
                'platform_fee'     => (float)$finance->platform_commission_amount,
                'settled_amount'   => $totalDinar,
                'status'           => 'completed',
            ];
        } else {
            // Driver or system cancelled: 100% refund to parent
            $vault->decrement('parents_escrow_pool', $totalCents);

            if ($parent) {
                $parent->deposit($totalCents);
            }

            $finance->update([
                'status'            => \App\Models\Shared\PlatformFinance::STATUS_REFUNDED,
                'refunded_amount'   => $totalDinar,
                'compensation_fee'  => 0.00,
                'refunded_at'       => now(),
                'notes'             => 'تم استرجاع كامل المبلغ لولي الأمر (إلغاء من السائق أو النظام).',
            ]);

            if ($parent) {
                $ledgerService->recordLedgerEntry(
                    'parents_escrow_pool',
                    \App\Services\Shared\FinancialLedgerService::parentAccount($parent),
                    $totalCents,
                    'subscription_refund',
                    0,
                    (int) $parent->balance,
                    "REFUND-FULL-{$requestId}",
                    [
                        'subscription_request_id' => $requestId,
                        'cancelled_by'            => $cancelledBy,
                    ]
                );
            }

            return [
                'refund_amount'    => $totalDinar,
                'compensation_fee' => 0.00,
                'driver_net_pay'   => 0.00,
                'platform_fee'     => 0.00,
                'settled_amount'   => 0.0,
                'status'           => 'refunded',
            ];
        }
    }









    // ============================================================
    // 4. إنشاء سجلات الاشتراكات النشطة (مطابق لجدول active_subscriptions)
    // ============================================================

    private function createActiveSubscriptions(SubscriptionRequest $req, ?\App\Models\Shared\Route $route = null): void
    {
        $pickupTime  = $req->pickup_time  ?? '07:00:00';
        $dropoffTime = $req->dropoff_time ?? '14:00:00';

        foreach ($req->children as $child) {
            // لقطة العنوان (home_label/home_lat/home_lng) موجودة فقط على مستوى الطلب requests
            // الآن (واحدة لكل أطفال الطلب)، فهي المصدر الأول قبل العلاقة الحية بعنوان الطفل.
            $childAddress = $child->address ?? null;
            $childSchool  = $child->school  ?? null;

            $pickupLat  = $req->home_lat   ?? $childAddress?->lat   ?? $req->pickup_lat   ?? null;
            $pickupLng  = $req->home_lng   ?? $childAddress?->lng   ?? $req->pickup_lng   ?? null;
            $pickupLbl  = $req->home_label ?? $childAddress?->label ?? $req->pickup_label ?? 'الموقع السكني';

            $dropoffLat = $child->pivot->school_lat   ?? $childSchool?->lat  ?? $req->school->lat       ?? $req->school->latitude  ?? $req->dropoff_lat ?? null;
            $dropoffLng = $child->pivot->school_lng   ?? $childSchool?->lng  ?? $req->school->lng       ?? $req->school->longitude ?? $req->dropoff_lng ?? null;
            $dropoffLbl = $child->pivot->school_label ?? $childSchool?->name ?? $req->school->name      ?? $req->dropoff_label     ?? 'المدرسة';

            $requestChildId = \App\Models\Shared\RequestChild::where('request_id', $req->id)
                ->where('child_id', $child->id)
                ->value('id');

            ActiveSubscription::create([
                'subscription_request_id' => $req->id,
                'request_child_id'        => $requestChildId,
                'route_id'                => $route?->id,
                'status'                  => 'active',
                'pickup_lat'              => $pickupLat,
                'pickup_lng'              => $pickupLng,
                'pickup_label'            => $pickupLbl,
                'pickup_time'             => $pickupTime,
                'dropoff_lat'             => $dropoffLat,
                'dropoff_lng'             => $dropoffLng,
                'dropoff_label'           => $dropoffLbl,
                'dropoff_time'            => $dropoffTime,
            ]);

            // زيادة عداد المقاعد لكل (slot × date) خاصة بهذا الطفل
            // ⚠️ $req->timing و $req->direction عمودان غير موجودين في جدول requests
            // (الموجود trip_direction فقط)؛ كانا يساويان null دائماً فيسقط الحجز على
            // 'MORNING'/'both' الثابتتين. المصدر الصحيح بعد pivot->timing هو تفضيل
            // الطفل المسجَّل، ثم trip_direction الحقيقي على الطلب.
            $childTiming    = $child->pivot->timing
                ?? \App\Models\Driver\DriverSeatSlot::timingFromPreferredSlot($child->preferred_time_slot)
                ?? 'MORNING';
            $childDirection = $req->trip_direction ?? 'both';
            $childSlots     = \App\Models\Driver\DriverSeatSlot::resolveSlots($childTiming, $childDirection);
            $childStart     = \Carbon\Carbon::parse($req->start_date ?? now())->startOfDay();
            $childEnd       = \Carbon\Carbon::parse($req->end_date ?? $childStart)->startOfDay();

            $cur = $childStart->copy();
            while ($cur->lte($childEnd)) {
                if (!$cur->isFriday() && !$cur->isSaturday()) {
                    $dayStr = $cur->toDateString();
                    foreach ($childSlots as $slot) {
                        \App\Models\Driver\DriverSeatSlot::incrementBooked($req->driver_id, $slot, $dayStr);
                    }
                }
                $cur->addDay();
            }
        }
    }

    // ============================================================
    // 5. تغيير حالة الاشتراك النشط (مفعل، معلق، مكتمل، ملغي)
    // ============================================================

    public function updateActiveSubscriptionStatus(int $activeSubscriptionId, string $status): ActiveSubscription
    {
        $allowedStatuses = ['active', 'pending', 'completed', 'cancelled'];
        if (!in_array($status, $allowedStatuses)) {
            throw new Exception("حالة غير صالحة. المسموح: " . implode(', ', $allowedStatuses));
        }

        return DB::transaction(function () use ($activeSubscriptionId, $status) {
            $activeSub = ActiveSubscription::lockForUpdate()->find($activeSubscriptionId);
            if (!$activeSub) {
                throw new Exception('الاشتراك النشط غير موجود.');
            }

            if (in_array($activeSub->status, ['cancelled', 'completed'])) {
                throw new Exception("لا يمكن تعديل اشتراك بحالة [{$activeSub->status}].");
            }

            $activeSub->update(['status' => $status]);

            if (in_array($status, ['cancelled', 'completed'])) {
                $this->releaseSeatsForSubscription($activeSub);
                try {
                    $this->masterRouteStopSyncService->removeChildFromDriverRoutes($activeSub);
                } catch (\Throwable $e) {
                    Log::warning("فشل تحديث المسار ID: {$activeSub->id} — " . $e->getMessage());
                }
            }

            return $activeSub->load(['subscriptionRequest', 'child', 'driver.user']);
        });
    }

    // ============================================================
    // إلغاء الاشتراك النشط — ولي الأمر
    // ============================================================

    public function cancelActiveSubscriptionByParent(int $activeSubscriptionId, int $userId): ActiveSubscription
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            throw new Exception('هذا الحساب غير مسجل كولي أمر في النظام.');
        }

        $activeSub = ActiveSubscription::where('id', $activeSubscriptionId)
            ->whereHas('subscriptionRequest', function ($q) use ($userId, $parent) {
                $q->where('parent_id', $parent->id)
                  ->orWhere('parent_id', $userId);
            })
            ->first();

        if (!$activeSub) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        if ($activeSub->status === 'cancelled') {
            throw new Exception('هذا الاشتراك ملغى بالفعل.');
        }
        if ($activeSub->status === 'completed') {
            throw new Exception('لا يمكن إلغاء اشتراك مكتمل.');
        }

        return DB::transaction(function () use ($activeSub) {
            $activeSub->update(['status' => 'cancelled']);

            $this->releaseSeatsForSubscription($activeSub);

            // معالجة استرجاع الرصيد المحجوز إن وُجد وفق سياسة التعويض
            if ($activeSub->subscription_request_id) {
                $this->refundHeldFundsOnCancellation($activeSub->subscription_request_id, 'parent');
            }

            try {
                $this->masterRouteStopSyncService->removeChildFromDriverRoutes($activeSub);
            } catch (\Throwable $e) {
                Log::warning("فشل تحديث المسار (إلغاء ولي الأمر) ID: {$activeSub->id} — " . $e->getMessage());
            }

            // إشعار السائق
            $activeSub->loadMissing(['driver.user', 'child']);
            $driverUser = $activeSub->driver?->user;
            if ($driverUser) {
                $childName = $activeSub->child?->full_name ?? 'الطفل';
                $this->notifyUser(
                    $driverUser,
                    'إلغاء اشتراك من قِبل ولي الأمر',
                    "قام ولي الأمر بإلغاء اشتراك الطفل [{$childName}].",
                    'subscription_cancelled_by_parent',
                    (string) $activeSub->id
                );
            }

            return $activeSub->fresh(['subscriptionRequest', 'child', 'driver.user']);
        });
    }

    // ============================================================
    // إلغاء الاشتراك النشط — السائق
    // ============================================================

    public function cancelActiveSubscriptionByDriver(int $activeSubscriptionId, int $driverId, ?string $reason = null): ActiveSubscription
    {
        $activeSub = ActiveSubscription::where('id', $activeSubscriptionId)
            ->forDriver($driverId)
            ->first();

        if (!$activeSub) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        if ($activeSub->status === 'cancelled') {
            throw new Exception('هذا الاشتراك ملغى بالفعل.');
        }
        if ($activeSub->status === 'completed') {
            throw new Exception('لا يمكن إلغاء اشتراك مكتمل.');
        }

        return DB::transaction(function () use ($activeSub, $driverId, $reason) {
            $activeSub->update(['status' => 'cancelled']);

            $this->releaseSeatsForSubscription($activeSub);

            // استرجاع كامل المبلغ لولي الأمر عند إلغاء السائق
            if ($activeSub->subscription_request_id) {
                $this->refundHeldFundsOnCancellation($activeSub->subscription_request_id, 'driver', $driverId);
            }

            try {
                $this->masterRouteStopSyncService->removeChildFromDriverRoutes($activeSub);
            } catch (\Throwable $e) {
                Log::warning("فشل تحديث المسار (إلغاء السائق) ID: {$activeSub->id} — " . $e->getMessage());
            }

            // إشعار ولي الأمر عبر parent_id (users.id)
            $activeSub->loadMissing(['parent', 'child', 'driver.user']);
            $parentUser = $activeSub->parent; // العلاقة ترجع User مباشرة
            if ($parentUser) {
                $driverName = $activeSub->driver?->user?->full_name ?? 'السائق';
                $childName  = $activeSub->child?->full_name ?? 'الطفل';
                $body       = "أعلمك السائق [{$driverName}] بإلغاء اشتراك طفلك [{$childName}].";
                if ($reason) {
                    $body .= " السبب: {$reason}";
                }
                $this->notifyUser(
                    $parentUser,
                    'إلغاء اشتراك من قِبل السائق',
                    $body,
                    'subscription_cancelled_by_driver',
                    (string) $activeSub->id,
                    ['reason' => $reason]
                );
            }

            return $activeSub->fresh(['subscriptionRequest', 'child', 'driver.user']);
        });
    }

    // ============================================================
    // مساعد: تحرير مقاعد السائق عند الإلغاء/الإتمام
    // ============================================================

    private function releaseSeatsForSubscription(ActiveSubscription $activeSub): void
    {
        $activeSub->loadMissing(['subscriptionRequest.children']);
        $subReq = $activeSub->subscriptionRequest;
        if (!$subReq) {
            return;
        }

        // ⚠️ نفس عمودَي $subReq->timing/direction غير الموجودين في requests (راجع
        // createActiveSubscriptions أعلاه). خطؤهما هنا أخطر: تحرير مقعد بفترة غير
        // التي حُجزت بها فعلاً يُبقي المقعد الحقيقي محجوزاً للأبد (تسريب مقاعد صامت).
        $child      = $subReq->children?->firstWhere('id', $activeSub->child_id);
        $childPivot = $child?->pivot;
        $timing     = $childPivot?->timing
            ?? \App\Models\Driver\DriverSeatSlot::timingFromPreferredSlot($child?->preferred_time_slot)
            ?? 'MORNING';
        $direction  = $childPivot?->trip_direction ?? $subReq->trip_direction ?? 'both';

        $slots = \App\Models\Driver\DriverSeatSlot::resolveSlots(
            $timing,
            $direction
        );

        $subReq->loadMissing('children');
        $childPivotForDates = $subReq->children?->firstWhere('id', $activeSub->child_id)?->pivot;
        $releaseStart = \Carbon\Carbon::parse($childPivotForDates?->start_date ?? $subReq->start_date ?? now())->startOfDay();
        $releaseEnd   = \Carbon\Carbon::parse($childPivotForDates?->end_date ?? $subReq->end_date ?? $releaseStart)->startOfDay();

        $cur = $releaseStart->copy();
        while ($cur->lte($releaseEnd)) {
            if (!$cur->isFriday() && !$cur->isSaturday()) {
                $dayStr = $cur->toDateString();
                foreach ($slots as $slot) {
                    \App\Models\Driver\DriverSeatSlot::decrementBooked($activeSub->driver_id, $slot, $dayStr);
                }
            }
            $cur->addDay();
        }
    }

    // ============================================================
    // فحص الطلبات المعلقة وإلغاء غير القابلة للتنفيذ
    // ============================================================

    /**
     * يُنفَّذ كل 6 ساعات تلقائياً — يفحص كل الطلبات المعلقة:
     *   1. تاريخ البدء فات دون قبول → إلغاء تلقائي
     *   2. السائق لا يملك مقاعد كافية → إلغاء تلقائي
     * وفي الحالتين يُرسل إشعاراً لولي الأمر وآخر للسائق.
     */
    public function cancelStaleAndOvercapacityRequests(): array
    {
        $stats = ['cancelled_expired' => 0, 'cancelled_no_seats' => 0, 'healthy' => 0];

        $pending = SubscriptionRequest::where('status', SubscriptionRequest::STATUS_PENDING)
            ->with(['driver.user', 'parent.user', 'children'])
            ->get();

        foreach ($pending as $req) {
            // ── السيناريو 1: تاريخ البدء انتهى دون قبول ──────────
            if ($req->start_date && \Carbon\Carbon::parse($req->start_date)->lt(now()->startOfDay())) {
                $this->autoCancelRequest(
                    $req,
                    'انتهى تاريخ بدء الطلب دون قبول السائق.',
                    'subscription_request_expired'
                );
                $stats['cancelled_expired']++;
                continue;
            }

            // ── السيناريو 2: لا تتوفر مقاعد كافية خلال فترة الطلب ──────────
            try {
                $this->assertSeatsAvailableForRequest($req);
                $stats['healthy']++;
            } catch (Exception $e) {
                $this->autoCancelRequest(
                    $req,
                    'لا تتوفر مقاعد كافية لدى السائق خلال فترة الاشتراك.',
                    'subscription_request_no_seats'
                );
                $stats['cancelled_no_seats']++;
            }
        }

        Log::info('CheckPendingSubscriptions', $stats);

        return $stats;
    }

    private function autoCancelRequest(SubscriptionRequest $req, string $reason, string $notificationType): void
    {
        $req->update(['status' => SubscriptionRequest::STATUS_CANCELLED]);

        // استرجاع المبالغ المحجوزة إن وُجدت
        $this->refundHeldFundsOnCancellation($req->id, 'system');

        $driverName = $req->driver?->user?->full_name ?? 'السائق';

        // إشعار ولي الأمر
        $parentUser = $req->parent?->user;
        if ($parentUser) {
            $this->notifyUser(
                $parentUser,
                'إلغاء تلقائي لطلب اشتراك',
                "تم إلغاء طلب اشتراكك مع السائق [{$driverName}] تلقائياً. السبب: {$reason}",
                $notificationType,
                (string) $req->id
            );
        }

        // إشعار السائق
        $driverUser = $req->driver?->user;
        if ($driverUser) {
            $parentName = $req->parent?->user?->full_name ?? 'ولي الأمر';
            $this->notifyUser(
                $driverUser,
                'إلغاء تلقائي لطلب اشتراك',
                "تم إلغاء طلب الاشتراك من [{$parentName}] تلقائياً. السبب: {$reason}",
                $notificationType . '_driver',
                (string) $req->id
            );
        }
    }

    // ============================================================
    // نظام إشعارات موحد
    // ============================================================

    private function notifyUser($user, string $title, string $message, string $type, ?string $entityId = null, array $extra = []): void
    {
        if ($user) {
            try {
                $this->notificationService->sendToUser($user, $type, array_merge([
                    'title'       => $title,
                    'message'     => $message,
                    'entity_type' => 'subscription_request',
                    'entity_id'   => $entityId,
                ], $extra));
            } catch (Exception $e) {
                Log::error("فشل إرسال الإشعار لـ {$user->id}: " . $e->getMessage());
            }
        }
    }
    /**
     * جلب تفاصيل اشتراك نشط واحد خاص بالسائق مع العلاقات الكاملة
     */
    public function getDriverActiveSubscriptionDetails(int $activeSubscriptionId, int $driverId)
    {
        $activeSub = ActiveSubscription::where('id', $activeSubscriptionId)
            ->forDriver($driverId)
            ->with([
                'subscriptionRequest',
                'child.school',
                'child.address',
                'parent',
                'school'
            ])
            ->first();

        if (!$activeSub) {
            throw new Exception('الاشتراك النشط غير موجود أو ليس لديك صلاحية للوصول إليه.');
        }

        return $activeSub;
    }

    // ============================================================
    // جلب طلبات الاشتراك الخاصة بولي الأمر
    // ============================================================
    public function getParentSubscriptions(int $userId, ?string $filter = null)
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            throw new Exception('هذا الحساب غير مسجل كولي أمر في النظام.');
        }

        $query = SubscriptionRequest::where('parent_id', $parent->id)
            ->with([
                'driver.user',
                'school:id,name',
                'children.school',
            ]);

        switch ($filter) {
            case 'pending':
                $query->where('status', SubscriptionRequest::STATUS_PENDING);
                break;
            case 'accepted':
                $query->where('status', SubscriptionRequest::STATUS_ACCEPTED);
                break;
            case 'rejected':
                $query->where('status', SubscriptionRequest::STATUS_REJECTED);
                break;
            case 'cancelled':
                $query->where('status', SubscriptionRequest::STATUS_CANCELLED);
                break;
        }

        return $query->orderBy('id', 'desc')->get();
    }
    
    /**
     * إلغاء طلب الاشتراك (pending / acquired) بواسطة ولي الأمر
     */
    public function cancelSubscriptionByParent(int $id, int $userId): SubscriptionRequest
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            throw new Exception('هذا الحساب غير مسجل كولي أمر في النظام.');
        }

        $subscription = SubscriptionRequest::where('id', $id)
            ->where('parent_id', $parent->id)
            ->first();

        if (!$subscription) {
            throw new Exception('طلب الاشتراك غير موجود، أو لا تملك صلاحية الوصول إليه.');
        }

        $cancellable = [SubscriptionRequest::STATUS_PENDING, SubscriptionRequest::STATUS_ACQUIRED];
        if (!in_array($subscription->status, $cancellable)) {
            throw new Exception('لا يمكن إلغاء هذا الطلب في حالته الحالية: ' . $subscription->status);
        }

        $subscription->update(['status' => SubscriptionRequest::STATUS_CANCELLED]);

        // استرجاع المبالغ المحجوزة إن وُجدت
        $this->refundHeldFundsOnCancellation($subscription->id, 'parent');

        // إشعار السائق إن وُجد
        $subscription->loadMissing('driver.user');
        $driverUser = $subscription->driver?->user;
        if ($driverUser) {
            $this->notifyUser(
                $driverUser,
                'إلغاء طلب اشتراك',
                'قام ولي الأمر بإلغاء طلب اشتراكه.',
                'subscription_request_cancelled_by_parent',
                (string) $subscription->id
            );
        }

        return $subscription;
    }

    /**
     * جلب الاشتراكات المفعّلة لولي الأمر والموافَق عليها مقسمة بالفلاتر الذكية وبنفس صيغة طلبات الاشتراك
     */
    public function getParentActiveSubscriptions(int $userId, ?string $filter = null)
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        $parentId = $parent ? $parent->id : $userId;

        $query = SubscriptionRequest::query()
            ->with([
                'driver.user',
                'driver.vehicle',
                'children' => function ($query) {
                    $query->withPivot([
                        'timing',
                        'distance_km',
                        'school_label',
                        'school_lat',
                        'school_lng',
                        'price_per_child',
                        'trip_price',
                        'discount_amount',
                        'total_amount_after_discount',
                        'driver_net_price'
                    ]);
                },
                'children.school',
                'children.address',
                'activeSubscriptions'
            ])
            ->where(function ($q) use ($parentId, $userId) {
                $q->where('parent_id', $parentId)
                  ->orWhere('parent_id', $userId);
            });

        if (!empty($filter)) {
            $filter = strtolower($filter);
            if ($filter === 'active') {
                $query->whereIn('status', ['accepted', 'active']);
            } elseif ($filter === 'pending') {
                $query->where('status', 'pending');
            } elseif ($filter === 'completed') {
                $query->where('status', 'completed');
            } elseif ($filter === 'cancelled') {
                $query->where('status', 'cancelled');
            } else {
                $query->where('status', $filter);
            }
        } else {
            $query->whereIn('status', ['accepted', 'active']);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /**
     * جلب طلبات الاشتراك المبدئية الواردة للسائق مع الفلترة الذكية
     */
    public function getDriverSubscriptionRequests(int $userId, ?string $filter = null)
    {
        $driver = Driver::where('user_id', $userId)->first();
        if (!$driver) {
            throw new Exception('لم يتم العثور على ملف السائق الخاص بك.', 403);
        }

        $query = SubscriptionRequest::where('driver_id', $driver->id)
            ->with([
                'parent.user',
                'school:id,name',
                'children'
            ]);

        switch ($filter) {
            case 'pending':
                $query->where('status', SubscriptionRequest::STATUS_PENDING);
                break;
            case 'cancelled':
                $query->where('status', SubscriptionRequest::STATUS_CANCELLED);
                break;
            case 'rejected':
                $query->where('status', SubscriptionRequest::STATUS_REJECTED);
                break;
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function parentHasSubscriptionWithDriver(int $userId, int $driverId): bool
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            return false;
        }

        return ActiveSubscription::whereHas('subscriptionRequest', function ($q) use ($userId, $parent, $driverId) {
            $q->where(function ($q2) use ($userId, $parent) {
                $q2->where('parent_id', $parent->id)
                   ->orWhere('parent_id', $userId);
            })->where('driver_id', $driverId);
        })
          ->whereIn('status', ['active', 'completed', 'cancelled']) // الحالات المطلوبة
          ->exists();
    }


    public function getDriverActiveSubscriptions(int $userId, ?string $filter = null)
    {
        try {
            $userExists = User::where('id', $userId)->exists();
            if (!$userExists) {
                throw new Exception("السبب: المستخدم رقم ({$userId}) غير موجود تماماً في جدول المستخدمين (users).");
            }

            $driver = Driver::where('user_id', $userId)->first();
            if (!$driver) {
                throw new Exception("السبب: المستخدم ({$userId}) موجود، ولكن ليس لديه سجل مرادف في جدول السائقين (drivers).");
            }

            $query = SubscriptionRequest::query()
                ->with([
                    'parent',
                    'children' => function ($query) {
                        $query->withPivot([
                            'timing',
                            'distance_km',
                            'school_label',
                            'school_lat',
                            'school_lng',
                            'price_per_child',
                            'trip_price',
                            'discount_amount',
                            'total_amount_after_discount',
                            'driver_net_price'
                        ]);
                    },
                    'children.school',
                    'children.address',
                    'activeSubscriptions'
                ])
                ->where('driver_id', $driver->id);

            if (!empty($filter)) {
                $filter = strtolower($filter);
                if ($filter === 'active' || $filter === 'current_active') {
                    $query->whereIn('status', ['accepted', 'active']);
                } elseif ($filter === 'pending_start') {
                    $query->whereIn('status', ['accepted', 'active'])
                          ->where('start_date', '>', now()->toDateString());
                } elseif ($filter === 'completed') {
                    $query->where('status', 'completed');
                } elseif ($filter === 'cancelled') {
                    $query->where('status', 'cancelled');
                } else {
                    $query->where('status', $filter);
                }
            } else {
                $query->whereIn('status', ['accepted', 'active']);
            }

            return $query->orderBy('id', 'desc')->get();

        } catch (QueryException $e) {
            throw new Exception("خطأ قاعدة البيانات (DB Error): " . $e->getMessage());
        } catch (Throwable $e) {
            throw new Exception("خطأ أثناء التنفيذ: " . $e->getMessage());
        }
    }

    public function getSubscriptionDetails($id)
    {
        return SubscriptionRequest::with([
            'parent.user',
            'driver.user',
            'children.school',
            'children.address',
        ])->findOrFail($id);
    }
    /**
     * جلب أIDs جميع السائقين المشترك معهم ولي الأمر
     */
    public function getParentSubscribedDriverIds(int $userId): array
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            return [];
        }

        return ActiveSubscription::whereHas('subscriptionRequest', function ($q) use ($userId, $parent) {
                $q->where('parent_id', $parent->id)
                  ->orWhere('parent_id', $userId);
            })
            ->whereIn('status', ['active', 'completed', 'cancelled'])
            ->with('subscriptionRequest')
            ->get()
            ->pluck('driver_id')
            ->unique()
            ->values()
            ->toArray();
    }

    public function getParentChats(int $userId): array
    {
        $parentId = $userId;

        // جلب جميع الاشتراكات النشطة لولي الأمر
        $subscriptions = ActiveSubscription::with(['driver.user'])
            ->forParent($parentId)
            ->get();

        // دعم إضافي: جلب طلبات الاشتراكات أيضاً في حال كانت تحت الإجراء أو العقد
        $requestSubs = SubscriptionRequest::with(['driver.user'])
            ->where('parent_id', $parentId)
            ->whereIn('status', ['accepted', 'contract_offered', 'pending', 'active'])
            ->get();

        $processedDrivers = [];
        $chats = [];

        foreach ($subscriptions as $sub) {
            $driver = $sub->driver;
            if (!$driver || !$driver->user) continue;

            if (in_array($driver->id, $processedDrivers)) continue;
            $processedDrivers[] = $driver->id;

            $driverUser = $driver->user;
            $canChat = in_array(strtolower($sub->status ?? 'active'), ['active', 'approved']);

            $chats[] = [
                "chat_room_id"        => "parent_" . $parentId . "_driver_" . $driver->id,
                "driver_id"           => $driver->id,
                "driver_user_id"      => $driverUser->id,
                "driver_name"         => $driverUser->full_name,
                "driver_phone"        => $driverUser->phone_number,
                "driver_photo"        => $driverUser->avatar_url,
                "can_chat"            => $canChat,
                "subscription_status" => $sub->status ?? 'active'
            ];
        }

        foreach ($requestSubs as $req) {
            $driver = $req->driver;
            if (!$driver || !$driver->user) continue;

            if (in_array($driver->id, $processedDrivers)) continue;
            $processedDrivers[] = $driver->id;

            $driverUser = $driver->user;

            $chats[] = [
                "chat_room_id"        => "parent_" . $parentId . "_driver_" . $driver->id,
                "driver_id"           => $driver->id,
                "driver_user_id"      => $driverUser->id,
                "driver_name"         => $driverUser->full_name,
                "driver_phone"        => $driverUser->phone_number,
                "driver_photo"        => $driverUser->avatar_url,
                "can_chat"            => true,
                "subscription_status" => $req->status
            ];
        }

        return $chats;
    }

    /**
     * جلب قائمة محادثات السائق بالكامل متوافقة مع كافة الهياكل (بدون parent_id في بيانات الإخراج)
     */
    public function getDriverChats(int $userId): array
    {
        $driver = Driver::where('user_id', $userId)->first();
        $driverId = $driver ? $driver->id : $userId;

        $subscriptions = ActiveSubscription::with(['parent'])
            ->whereHas('subscriptionRequest', function ($q) use ($driverId, $userId) {
                $q->where('driver_id', $driverId)->orWhere('driver_id', $userId);
            })
            ->get();

        $requestSubs = SubscriptionRequest::with(['parent'])
            ->where(function ($q) use ($driverId, $userId) {
                $q->where('driver_id', $driverId)->orWhere('driver_id', $userId);
            })
            ->whereIn('status', ['accepted', 'contract_offered', 'pending', 'active'])
            ->get();

        $processedParents = [];
        $chats = [];

        foreach ($subscriptions as $sub) {
            $parentUser = $sub->parent;
            if (!$parentUser) continue;
            if (in_array($parentUser->id, $processedParents)) continue;
            $processedParents[] = $parentUser->id;

            $chats[] = [
                "chat_room_id"        => "parent_" . $parentUser->id . "_driver_" . $driverId,
                "parent_user_id"      => $parentUser->id,
                "parent_name"         => $parentUser->full_name,
                "parent_phone"        => $parentUser->phone_number,
                "parent_photo"        => $parentUser->avatar_url,
                "can_chat"            => in_array(strtolower($sub->status ?? 'active'), ['active', 'approved']),
                "subscription_status" => $sub->status ?? 'active'
            ];
        }

        foreach ($requestSubs as $req) {
            $parentUser = $req->parent;
            if (!$parentUser) continue;
            if (in_array($parentUser->id, $processedParents)) continue;
            $processedParents[] = $parentUser->id;

            $chats[] = [
                "chat_room_id"        => "parent_" . $parentUser->id . "_driver_" . $driverId,
                "parent_user_id"      => $parentUser->id,
                "parent_name"         => $parentUser->full_name,
                "parent_phone"        => $parentUser->phone_number,
                "parent_photo"        => $parentUser->avatar_url,
                "can_chat"            => true,
                "subscription_status" => $req->status
            ];
        }

        return $chats;
    }

    /**
     * جلب ملخص طلبات تغيير الموقع المعتمدة ورسومها لاشتراك معين
     */
    public function getLocationChangeSummary(int $subscriptionRequestId): array
    {
        $activeSubIds = ActiveSubscription::where('subscription_request_id', $subscriptionRequestId)->pluck('id');
        $approvedChanges = \App\Models\Shared\LocationChangeRequest::whereIn('active_subscription_id', $activeSubIds)
            ->where('status', \App\Models\Shared\LocationChangeRequest::STATUS_APPROVED)
            ->get();

        return [
            'count'      => $approvedChanges->count(),
            'total_fees' => (float) $approvedChanges->sum('fee_amount'),
            'requests'   => $approvedChanges,
        ];
    }
}