<?php

namespace App\Services\Trip;

use App\Models\Shared\Trip;
use App\Models\Shared\TripEvent;
use App\Models\Shared\AbsenceLog;
use App\Models\Driver\DriverAbsence;
use App\Models\Shared\ActiveSubscription;
use App\Services\Shared\OsrmRoutingService;
use App\Services\Notification\NotificationService;
use App\Models\Driver\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Cache; // ✅ استيراد الفيساد الصحيح للكاش
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class TripLifecycleException extends Exception
{
    protected string $errorCode;

    public function __construct(string $message, string $errorCode = 'TRIP_LIFECYCLE_ERROR', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

class TripLifecycleService
{
    protected OsrmRoutingService $osrmService;

    protected NotificationService $notificationService;

    /**
     * حقن خدمة الـ OSRM التي قمنا بإعدادها مسبقاً
     */
    public function __construct(OsrmRoutingService $osrmService, NotificationService $notificationService)
    {
        $this->osrmService = $osrmService;
        $this->notificationService = $notificationService;
    }

    /**
     * الدالة 1: startTrip (بدء الرحلة اليومية للسائق مع الاستثناءات المحددة)
     */
   /**
     * بدء رحلة جديدة للسائق مع التحقق من حالات التضارب
     * $driverLat/$driverLng: الموقع الحي للسائق لحظة الضغط على "بدء الرحلة" (Live Lead-In)
     */
    public function startTrip(int $driverId, string $tripType, ?float $driverLat = null, ?float $driverLng = null)
    {
        \Illuminate\Support\Facades\Log::info("Attempting to start trip for driver: $driverId");
        $today = Carbon::today()->toDateString();

        // [استثناء 1]: التحقق مما إذا كان السائق مسجل كغائب اليوم
        $isDriverAbsent = \App\Models\Driver\DriverAbsence::where('driver_id', $driverId)
            ->whereDate('absence_date', $today)
            ->exists();

        if ($isDriverAbsent) {
            throw new \Exception('لا يمكن بدء الرحلة؛ السائق مسجل كغائب لهذا اليوم.');
        }

        // [استثناء 2]: منع التضارب إذا كانت هناك رحلة بدأت بالفعل ولم تُغلق
        $activeTrip = Trip::where('driver_id', $driverId)
            ->where('status', 'in_progress')
            ->first();
            
        if ($activeTrip) {
            return $activeTrip; // إرجاع الرحلة المفتوحة حالياً لتجنب الازدواجية
        }

        return DB::transaction(function () use ($driverId, $tripType, $driverLat, $driverLng) {

    // 🛡️ حماية وتوحيد النص القادم ليتوافق تماماً مع الـ Enum في قاعدة البيانات ('Morning', 'Afternoon')
    $incomingType = strtolower($tripType);
    $dbTripType = (in_array($incomingType, ['morning', 'صباحية', 'صباح', 'morning']) || str_contains($incomingType, 'صباح'))
        ? 'Morning'
        : 'Afternoon';

    // البحث عن المسار باستخدام القيمة الموحدة
    $route = \App\Models\Shared\Route::where('driver_id', $driverId)
        ->where('route_type', $dbTripType)
        ->where('status', 'Active')
        ->first();

    $trip = Trip::create([
        'driver_id'           => $driverId,
        'trip_type'           => $dbTripType, // هنا نضمن تخزين القيمة الصحيحة تماماً الإنجليزية وبحرف كبير
        'shift_slot'          => $route?->shift_slot,
        'status'              => 'in_progress',
        'route_id'            => $route?->id ?? 0,
        'scheduled_start_time'=> Carbon::now(),
        'actual_start_time'   => Carbon::now(),
        'scheduled_at'        => Carbon::now(),
        'start_lat'           => $driverLat,
        'start_lng'           => $driverLng,
        'trip_date'           => Carbon::today()->toDateString(),
    ]);

    // حساب المسار والتواقيت المبدئية عند الانطلاق فوراً
    $this->calculateInitialRoute($trip->id);

    // حساب "الوصلة الأولى" (Lead-In) والـ ETAs الحية لكل محطات trip_stops إذا توفر موقع السائق الحي
    if ($driverLat !== null && $driverLng !== null) {
        try {
            $this->computeLiveEtas($trip, $driverLat, $driverLng);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("فشل حساب ETAs الحية عند بدء الرحلة ID: {$trip->id} - " . $e->getMessage());
        }
    }

    return $trip->fresh();
});

    }

    /**
     * يحسب الوصلة الأولى (Lead-In) من موقع السائق الحي إلى أول محطة، ثم يتابع حساب
     * الـ ETA التراكمي لبقية محطات trip_stops بالترتيب، ويحفظها على كل محطة.
     */
    public function computeLiveEtas(Trip $trip, float $driverLat, float $driverLng): void
    {
        $stops = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
            ->where('sequence_order', '>', 0)
            ->orderBy('sequence_order')
            ->get();

        if ($stops->isEmpty()) {
            return;
        }

        $currentLat = $driverLat;
        $currentLng = $driverLng;
        $currentMinutes = Carbon::now()->hour * 60 + Carbon::now()->minute;

        foreach ($stops as $stop) {
            $distanceKm = \App\Support\GeoEstimator::haversineKm($currentLat, $currentLng, (float) $stop->lat, (float) $stop->lng);
            $travelMinutes = \App\Support\GeoEstimator::estimateMinutes($distanceKm);

            $currentMinutes += $travelMinutes;

            $stop->eta_minutes = $currentMinutes;
            $stop->eta = sprintf('%02d:%02d:00', intdiv($currentMinutes, 60) % 24, $currentMinutes % 60);
            $stop->save();

            $currentLat = (float) $stop->lat;
            $currentLng = (float) $stop->lng;
        }
    }

    /**
     * الدالة 2: calculateInitialRoute (حساب المسار وتوليده من محرك OSRM باستثناء الأطفال الغائبين)
     */
    public function calculateInitialRoute(int $tripId): ?array
    {
        $trip = Trip::findOrFail($tripId);
        $today = Carbon::today()->toDateString();

        // جلب إحداثيات موقع السائق الحالي لتبدأ خريطة السير من عنده
        $driver = Driver::findOrFail($trip->driver_id);
        $coordinates = [
            ['lat' => $driver->current_lat, 'lng' => $driver->current_lng]
        ];

        // جلب الاشتراكات الفعالة المقترنة بالسائق مرتبة هندسياً حسب حقل الترتيب
        $subscriptions = ActiveSubscription::forDriver($trip->driver_id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $validSubCount = 0;
        $schoolCoords = null;

        foreach ($subscriptions as $sub) {
            // [اللقطة الذكية]: استثناء الطفل إذا حددت الأم غيابه اليوم بالتواريخ
            $isChildAbsent = AbsenceLog::where('child_id', $sub->child_id)
                ->whereDate('absence_date', $today)
                ->exists();

            if ($isChildAbsent) {
                continue; // تخطي المسار وتجاوزه فوراً
            }

            // تحديد نقطة التوقف بناءً على نوع الرحلة (صباحية للحوش / مسائية للمدرسة)
            if ($trip->trip_type === 'morning') {
                $coordinates[] = ['lat' => $sub->pickup_lat, 'lng' => $sub->pickup_lng];
            } else {
                $coordinates[] = ['lat' => $sub->dropoff_lat, 'lng' => $sub->dropoff_lng];
            }

            // تأمين إحداثيات المدرسة باعتبارها المحطة الأخيرة للكل
            if (!$schoolCoords && $sub->school) {
                $schoolCoords = ['lat' => $sub->school->latitude, 'lng' => $sub->school->longitude];
            }
            
            $validSubCount++;
        }

        // حماية: إذا كان كل الأطفال غائبين اليوم، لا نرسل طلباً فارغاً لـ OSRM
        if ($validSubCount === 0) {
            return null;
        }

        // إغلاق مصفوفة المسار بالمدرسة
        if ($schoolCoords) {
            $coordinates[] = $schoolCoords;
        }

        // إرسال البيانات لمحرك OSRM المحلي وتوليد خط السير والأوقات التقديرية
        $routeData = $this->osrmService->calculateRoute($coordinates);

        return $routeData;
    }

    /**
     * الدالة 3: reorderRouteSequence (تمكين السائق من الترتيب اليدوي للمحطات)
     */
    public function reorderRouteSequence(int $driverId, array $orderedSubscriptionIds): void
    {
        DB::transaction(function () use ($orderedSubscriptionIds) {
            foreach ($orderedSubscriptionIds as $index => $subId) {
                ActiveSubscription::where('id', $subId)->update([
                    'sort_order' => $index + 1
                ]);
            }
        });
    }

    /**
     * الدالة 11 (محدثة): setChildAbsence (تحديد الأم لتواريخ غياب طفلها مع دعم نوع الغياب)
     * absence_type: pickup (ذهاب فقط) | dropoff (عودة فقط) | both (الاثنين، الافتراضي)
     */
    public function setChildAbsence(int $childId, array $dates, string $absenceType = 'both'): void
    {
        $today = Carbon::today()->toDateString();
        $validTypes = ['pickup', 'dropoff', 'both'];
        $absenceType = in_array($absenceType, $validTypes) ? $absenceType : 'both';
        
        DB::transaction(function () use ($childId, $dates, $absenceType) {
            foreach ($dates as $date) {
                $formattedDate = Carbon::parse($date)->toDateString();

                // [استثناء حماية]: منع التلاعب بأيام قد مضت وانتهت
                if ($formattedDate < Carbon::today()->toDateString()) {
                    continue;
                }

                // البحث عن سجل غياب موجود لنفس الطفل ونفس اليوم وتحديثه، أو إنشاء سجل جديد
                AbsenceLog::updateOrCreate(
                    ['child_id' => $childId, 'absence_date' => $formattedDate],
                    ['absence_type' => $absenceType]
                );
            }
        });

        // [تحديث لحظي فوري]: لو سُجّل غياب اليوم وكانت رحلة الحافلة بدأت فعلاً، نعيد حساب المسار
        if (in_array($today, $dates)) {
            $this->recalculateActiveTripsForChild($childId);
        }
    }

    /**
     * دالة إضافية: removeChildAbsence (تراجع الأم عن طلب الغياب وإعادة الطفل للمسار)
     * يمكن تمرير absence_type لإلغاء نوع غياب محدد فقط بدل حذف كل الغياب في اليوم
     */
    public function removeChildAbsence(int $childId, array $dates, ?string $absenceType = null): void
    {
        $formattedDates = collect($dates)->map(fn($d) => Carbon::parse($d)->toDateString());

        $query = AbsenceLog::where('child_id', $childId)
            ->whereIn('absence_date', $formattedDates);

        // لو حدد ولي الأمر نوع غياب معين، نحذف ذلك النوع فقط وليس كل الغياب
        if ($absenceType && in_array($absenceType, ['pickup', 'dropoff', 'both'])) {
            $query->where('absence_type', $absenceType);
        }

        $query->delete();

        $today = Carbon::today()->toDateString();
        if ($formattedDates->contains($today)) {
            $this->recalculateActiveTripsForChild($childId);
        }
    }

    /**
     * يعرض على السائق قائمة رحلاته القادمة (لم تكتمل/تُلغَ بعد، ولم يُنزَع منها بغياب سابق)
     * ليختار منها الرحلة أو الرحلات التي يريد تسجيل غيابه عنها عبر setDriverAbsence.
     */
    public function getUpcomingTripsForAbsence(int $driverId, int $lookaheadDays = 14): \Illuminate\Support\Collection
    {
        $today = Carbon::today()->toDateString();
        $lastDate = Carbon::today()->addDays($lookaheadDays)->toDateString();

        return Trip::where('driver_id', $driverId)
            ->whereBetween('trip_date', [$today, $lastDate])
            ->whereNotIn('status', ['completed', 'cancelled', 'suspended_breakdown'])
            ->with('route:id,route_name,shift_slot,route_type')
            ->orderBy('trip_date')
            ->orderBy('scheduled_start_time')
            ->get()
            ->map(function (Trip $trip) {
                return [
                    'id'                   => $trip->id,
                    'trip_type'            => $trip->trip_type,
                    'shift_slot'           => $trip->shift_slot,
                    'trip_date'            => $trip->trip_date ? Carbon::parse($trip->trip_date)->toDateString() : null,
                    'scheduled_start_time' => $trip->scheduled_start_time,
                    'status'               => $trip->status,
                    'route_name'           => $trip->route?->route_name,
                ];
            });
    }

    /**
     * دالة setDriverAbsence: تسجيل طلب غياب السائق عن رحلات محددة أو تواريخ محددة
     */
    public function setDriverAbsence(int $driverId, array|string $dates, array $tripIds = [], ?string $reason = null): DriverAbsence|array
    {
        if (!empty($tripIds)) {
            $targetDate = is_array($dates) ? Carbon::parse($dates[0])->toDateString() : Carbon::parse($dates)->toDateString();

            return DB::transaction(function () use ($driverId, $targetDate, $tripIds, $reason) {
                // يُطبَّق الغياب فوراً دون انتظار مراجعة إدارية: السائق اختار الرحلات
                // بنفسه من قائمة رحلاته القادمة، والتحقق الأمني الكامل (ملكية الرحلة،
                // مطابقة التاريخ، عدم وجود سائق بديل مكلَّف مسبقاً...) تم بالفعل ضمن
                // DriverAbsenceRequest قبل الوصول لهذه الدالة.
                $absence = DriverAbsence::create([
                    'driver_id'    => $driverId,
                    'absence_date' => $targetDate,
                    'reason'       => $reason,
                    'status'       => DriverAbsence::STATUS_APPROVED,
                    'reviewed_at'  => now(),
                ]);

                $absence->trips()->sync($tripIds);

                // نزع السائق فوراً من الرحلات المحددة فقط (وليس كامل يومه) حتى يستطيع
                // النظام تدبير سائق بديل، ولمنع مولّد الرحلات اليومية من إعادة إسناده
                // تلقائياً لهذه الرحلة بعينها فيما بعد.
                $trips = Trip::whereIn('id', $tripIds)->get();
                foreach ($trips as $trip) {
                    $trip->update(['driver_id' => null, 'status' => 'pending']);
                }

                // إشعار السائق بتأكيد تسجيل الغياب
                $driverUser = User::whereHas('driver', fn($q) => $q->where('id', $driverId))->first();
                if ($driverUser) {
                    $this->notificationService->sendToUser($driverUser, 'driver_absence_confirmed', [
                        'title'   => '✅ تم تسجيل غيابك',
                        'message' => "تم تسجيل غيابك عن الرحلات المحددة بتاريخ ({$targetDate}) وفصلك عنها فوراً، وجارٍ تدبير سائق بديل.",
                        'entity_type' => 'driver_absence',
                        'entity_id'   => (string) $absence->id,
                    ]);
                }

                // إشعار جميع أولياء أمور الأطفال الموجودين في هذه الرحلات تحديداً (وليس كل مشتركي السائق)
                try {
                    $childIds = \App\Models\Shared\TripStop::whereIn('trip_id', $tripIds)
                        ->whereNotNull('child_id')
                        ->pluck('child_id')
                        ->unique();

                    if ($childIds->isEmpty()) {
                        // لم تُبنَ محطات هذه الرحلة بعد (لم تدخل نافذة التوليد اليومي بعد)،
                        // فنعتمد على أطفال الاشتراكات النشطة على نفس مسار هذه الرحلات.
                        $routeIds = $trips->pluck('route_id')->filter()->unique();
                        $childIds = ActiveSubscription::whereIn('route_id', $routeIds)
                            ->where('status', 'active')
                            ->with('requestChild')
                            ->get()
                            ->pluck('child_id')
                            ->unique();
                    }

                    $parentUserIds = \App\Models\Parent\Child::whereIn('id', $childIds)
                        ->pluck('parent_id')
                        ->unique();

                    $usersToNotify = User::whereIn('id', $parentUserIds)->get();
                    if ($usersToNotify->isNotEmpty()) {
                        $this->notificationService->sendToUsers($usersToNotify, 'driver_absence', [
                            'title'       => 'تنبيه: غياب سائق رحلة طفلكم',
                            'message'     => "نفيدكم علماً بأن سائق رحلة طفلكم غداً/بتاريخ ({$targetDate}) قد سجّل غيابه. يعمل النظام حالياً على تدبير سائق بديل وسنوافيكم بالتفاصيل فور توفره.",
                            'entity_type' => 'driver_absence',
                            'entity_id'   => (string) $absence->id,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("فشل إرسال إشعار غياب السائق لأولياء الأمور: " . $e->getMessage());
                }

                return $absence->load('trips');
            });
        }

        // المسار القديم للتوافقية مع قائمة التواريخ
        $datesArray = (array) $dates;
        $createdAbsences = [];

        DB::transaction(function () use ($driverId, $datesArray, $reason, &$createdAbsences) {
            foreach ($datesArray as $date) {
                $formattedDate = Carbon::parse($date)->toDateString();

                if ($formattedDate < Carbon::today()->toDateString()) {
                    continue;
                }

                $absence = DriverAbsence::create([
                    'driver_id'    => $driverId,
                    'absence_date' => $formattedDate,
                    'reason'       => $reason,
                    'status'       => DriverAbsence::STATUS_APPROVED,
                ]);

                $createdAbsences[] = $absence;
            }
        });

        // جلب جميع أولياء الأمور (Users) المرتبطين باشتراكات هذا السائق لإشعارهم
        $parentUserIds = ActiveSubscription::forDriver($driverId)
            ->with('subscriptionRequest')
            ->get()
            ->pluck('parent_id')
            ->unique();

        $usersToNotify = User::whereIn('id', $parentUserIds)->get();

        // إطلاق وإيداع الإشعارات في جدول notifications
        $datesString = implode(', ', $datesArray);
        $this->notificationService->sendToUsers($usersToNotify, 'driver_absence', [
            'title'   => 'تنبيه: غياب السائق اليومي',
            'message' => "نفيدكم علماً بأن السائق حدد أيام غياب له في التواريخ التالية: ({$datesString})، ولن يتم تفعيل مسار الرحلة في هذه الأيام.",
            'entity_id' => $driverId . '_' . $datesString,
        ]);

        return $createdAbsences;
    }

    /**
     * دالة حماية داخلية (Helper) لإعادة الحساب اللحظي الفوري للمسار إذا تغيرت حالة الطفل والرحلة قائمة
     */
    protected function recalculateActiveTripsForChild(int $childId): void
    {
        $activeTrip = Trip::where('status', 'in_progress')
            ->whereHas('driver.activeSubscriptions', function ($query) use ($childId) {
                $query->where('child_id', $childId);
            })->first();

        if ($activeTrip) {
            $this->calculateInitialRoute($activeTrip->id);
        }
    }

    /**
     * 🛡️ صمام أمان الأطفال (Zero Forgotten Children Guard):
     * يمنع إنهاء الرحلة إذا وُجد أي طفل بمحطة منزل في حالة غير نهائية (خصوصاً boarded — لا يزال داخل الحافلة).
     *
     * @throws TripLifecycleException بكود 422 وerror_code = FORGOTTEN_CHILDREN_ON_BUS
     */
    public function assertNoForgottenChildren(Trip $trip): void
    {
        $forgottenStops = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
            ->where('stop_type', \App\Models\Shared\TripStop::TYPE_HOME)
            ->whereIn('status', \App\Models\Shared\TripStop::NON_FINAL_STATUSES)
            ->with('child')
            ->get();

        if ($forgottenStops->isNotEmpty()) {
            $names = $forgottenStops
                ->map(fn($s) => $s->child->full_name ?? $s->child->name ?? ('طفل #' . $s->child_id))
                ->implode('، ');

            throw new TripLifecycleException(
                "لا يمكن إنهاء الرحلة: يوجد أطفال لم تُحسم حالتهم بعد ({$names}). يجب تأكيد نزولهم أو تسجيل غيابهم أولاً.",
                'FORGOTTEN_CHILDREN_ON_BUS',
                422
            );
        }
    }

    /**
     * الدالة 12: completeTrip (إنهاء الرحلة مع فحص صمام أمان الأطفال، وتصفير الكاش لحفظ ذاكرة السيرفر)
     *
     * @throws TripLifecycleException إذا وُجد طفل بحالة غير نهائية (انظر assertNoForgottenChildren)
     */
    public function completeTrip(int $tripId): array
    {
        $trip = Trip::findOrFail($tripId);

        if ($trip->status === 'completed') {
            return ['status' => 'already_completed', 'message' => 'الرحلة مغلقة بالفعل.'];
        }

        // 🛡️ صمام أمان الأطفال — يرمي TripLifecycleException (422) عند وجود طفل بحالة boarded/pending
        $this->assertNoForgottenChildren($trip);

        return DB::transaction(function () use ($trip) {
            // 1. تحديث حالة الرحلة في قاعدة البيانات
            $trip->update([
                'status' => 'completed',
                'completed_at' => Carbon::now(),
            ]);

            // 1.5 إغلاق محطات المدارس المتبقية.
            // ⚠️ كانت تبقى pending للأبد بعد اكتمال الرحلة، فيظل resolveNextStop يعيدها
            // كـ"المحطة التالية" حتى بعد إنزال آخر طفل، وتبقى بيانات ميتة في trip_stops.
            \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->where('stop_type', \App\Models\Shared\TripStop::TYPE_SCHOOL)
                ->where('sequence_order', '>', 0)
                ->whereIn('status', \App\Models\Shared\TripStop::NON_FINAL_STATUSES)
                ->update(['status' => \App\Models\Shared\TripStop::STATUS_DROPPED_OFF_SCHOOL]);

            // 2. تصفير وتنظيف الـ Cache الخاص بهذه الرحلة تماماً للحفاظ على موارد الخادم
            $driverId = $trip->driver_id;
            Cache::forget("driver_last_loc_{$driverId}");
            
            // جلب الأطفال لتنظيف كاش العدادات الخاص بهم
            $childIds = ActiveSubscription::forDriver($driverId)->with('requestChild')->get()->pluck('child_id');
            foreach ($childIds as $childId) {
                Cache::forget("trip_waiting_{$trip->id}_{$childId}");
                Cache::forget("proximity_alert_sent_{$trip->id}_{$childId}");
                Cache::forget("automatic_arrival_logged_{$trip->id}_{$childId}");
            }

            // 3. إشعار أولياء الأمور المشتركين في هذه الرحلة بنهاية الرحلة والوصول الآمن للوجهة
            $parentUserIds = ActiveSubscription::forDriver($driverId)
                ->with('subscriptionRequest')
                ->get()
                ->pluck('parent_id')
                ->unique();

            $usersToNotify = User::whereIn('id', $parentUserIds)->get();

            $isGoTrip = \App\Models\Driver\DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
                || strtolower($trip->trip_type ?? '') === 'morning';
            $destination = $isGoTrip ? 'المدرسة' : 'المنزل';
            $this->notificationService->sendToUsers($usersToNotify, 'trip_completed', [
                'title'   => 'وصلت الحافلة بسلام 🏁',
                'message' => "أنهى السائق الرحلة بنجاح، ووصل جميع الأطفال إلى {$destination} بسلامة الله.",
                'trip_id' => (string) $trip->id,
            ]);

            // 4. تسوية الأمانات المالية وتحويل مستحقات السائق واقتطاع عمولة المنصة
            $this->settlePlatformFinancesForCompletedTrip($trip);

            // 4.1 تحصيل حجوزات الرحلات اليومية المفتوحة على هذه الرحلة.
            // ⚠️ كانت captureTripOnCompletion() معرّفة ولا تُستدعى من أي مكان، فيبقى
            // كل مبلغ محجوز عبر /wallet/hold-trip في حالة `held` إلى الأبد: لا يصل
            // السائق ولا يعود لولي الأمر. الآن ينتقل إلى المستحقات المعلّقة، ومنها
            // إلى رصيد السائق المتاح بعد انقضاء نافذة النزاع عبر المهمة المجدولة.
            try {
                app(\App\Services\Shared\FinancialLedgerService::class)->captureTripOnCompletion($trip);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // لا حجز يومي مفتوح على هذه الرحلة — الحالة الطبيعية للاشتراكات الشهرية.
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "فشل تحصيل حجز الرحلة اليومية ID {$trip->id}: " . $e->getMessage()
                );
            }

            // 5. تسوية مستحقات السائق البديل إن كانت هذه الرحلة رحلة إنقاذ طارئة
            $this->settleEmergencyBreakdownDispatchesForTrip($trip);

            return [
                'status' => 'success',
                'message' => 'تم إنهاء الرحلة وتصفير سجلات الكاش المؤقتة بنجاح.'
            ];
        });
    }

    /**
     * تسوية المستحقات المالية المحجوزة للرحلة المكتملة
     */
    /**
     * تحديد أرقام طلبات الاشتراك التي خدمتها هذه الرحلة تحديداً.
     *
     * ⚠️ بدون هذا الحصر كانت التسوية تشمل كل المبالغ المحجوزة للسائق مهما كان مصدرها،
     * فيؤدي إكمال رحلة واحدة إلى صرف أموال اشتراكات أولياء أمور آخرين لم تُنفَّذ رحلاتهم بعد.
     * الترتيب: أطفال محطات هذه الرحلة أولاً (الأدق)، ثم اشتراكات مسار الرحلة كبديل.
     */
    protected function resolveSettleableSubscriptionRequestIds(Trip $trip): \Illuminate\Support\Collection
    {
        $childIds = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
            ->whereNotNull('child_id')
            ->pluck('child_id')
            ->unique();

        if ($childIds->isNotEmpty()) {
            $ids = \App\Models\Shared\ActiveSubscription::forDriver($trip->driver_id)
                ->forChildren($childIds->all())
                ->pluck('subscription_request_id')
                ->filter()
                ->unique()
                ->values();
        } elseif ($trip->route_id) {
            $ids = \App\Models\Shared\ActiveSubscription::where('route_id', $trip->route_id)
                ->pluck('subscription_request_id')
                ->filter()
                ->unique()
                ->values();
        } else {
            return collect();
        }

        // ⚠️ حصر إضافي إلزامي: لا يُصرف إلا من اشتراك تقع فترته فعلياً على تاريخ هذه الرحلة.
        // بدونه كان ولي أمر لديه حجزان مع نفس السائق (اليوم وغداً) يتسبب في صرف أمانة
        // حجز الغد كاملة عند إنهاء رحلة اليوم — أي دفع مقابل رحلة لم تُنفَّذ بعد،
        // ويستحيل استرجاعها لو أُلغيت لاحقاً لأن الأمانة أُغلقت.
        return $ids->filter(fn($id) => $this->subscriptionCoversTripDate((int) $id, $trip))->values();
    }

    /**
     * هل تغطي فترة طلب الاشتراك تاريخ هذه الرحلة؟ (يُفحص على مستوى الطفل ثم الطلب).
     */
    protected function subscriptionCoversTripDate(int $subscriptionRequestId, Trip $trip): bool
    {
        $tripDate = $trip->trip_date
            ? Carbon::parse($trip->trip_date)->toDateString()
            : Carbon::today()->toDateString();

        // ⚠️ start_date/end_date انتقلا من request_children إلى requests (المصدر الوحيد
        // الآن) عبر ميغريشن 2026_09_07_183713 — نفس خلل DailyTripGenerationService
        // ::routeHasCoverageOn() بالضبط لكن هنا بمسار تسوية أموال الرحلة المكتملة: كان
        // يفشل بخطأ SQL "Unknown column" لأي رحلة نُقل فيها طفل فعلياً، أي أن إنهاء أي
        // رحلة حقيقية كان سيفشل تماماً في اللحظة التي يُفترض أن يُصرف فيها مستحق السائق.
        $covers = DB::table('requests')
            ->where('id', $subscriptionRequestId)
            ->whereDate('start_date', '<=', $tripDate)
            ->whereDate('end_date', '>=', $tripDate)
            ->exists();

        if ($covers) {
            return true;
        }

        $req = \App\Models\Shared\SubscriptionRequest::find($subscriptionRequestId);
        if (!$req || !$req->start_date || !$req->end_date) {
            return true;
        }

        return false;
    }

    /**
     * هل نُقل في هذه الرحلة طفل واحد على الأقل فعلياً؟
     * (الغياب المسبق/المتأخر وتجاوز المحطة لا تُعدّ خدمة منفّذة).
     */
    protected function tripActuallyServedAnyChild(Trip $trip): bool
    {
        return \App\Models\Shared\TripStop::where('trip_id', $trip->id)
            ->where('stop_type', \App\Models\Shared\TripStop::TYPE_HOME)
            ->whereIn('status', [
                \App\Models\Shared\TripStop::STATUS_DROPPED_OFF_SCHOOL,
                \App\Models\Shared\TripStop::STATUS_DELIVERED_HOME,
                \App\Models\Shared\TripStop::STATUS_DROPOFF_FAILED,
                \App\Models\Shared\TripStop::STATUS_DIRECT_PARENT_HANDLING,
            ])
            ->exists();
    }

    /**
     * تسوية حصة هذه الرحلة فقط من أمانة الاشتراك (وليس الاشتراك كاملاً).
     *
     * ⚠️ سابقاً كان إنهاء أول رحلة يصرف كامل مبلغ الاشتراك للسائق، فيتقاضى أجر شهر
     * كامل مقابل يوم واحد، ويصبح استرجاع أي مبلغ لولي الأمر مستحيلاً لأن الأمانة أُغلقت.
     * الآن تُصرف حصة رحلة واحدة = المبلغ ÷ عدد الرحلات المتوقعة، وتُغلق الأمانة فقط
     * بعد تنفيذ كل الرحلات، مع منح الرحلة الأخيرة الباقي لتفادي ضياع كسور التقريب.
     */
    public function settlePlatformFinancesForCompletedTrip(Trip $trip): int
    {
        // ⚠️ لا تُصرف حصة رحلة لم تُنقل فيها أي طفل فعلياً (مثلاً كل الأطفال مسجل غيابهم
        // مسبقاً). بدون هذا الفحص كان مجرد "إنهاء" رحلة فارغة يخصم حصة من أمانة ولي الأمر
        // ويودعها للسائق، فيمكن استنزاف اشتراك كامل دون تنفيذ أي رحلة حقيقية.
        if (!$this->tripActuallyServedAnyChild($trip)) {
            \Illuminate\Support\Facades\Log::info(
                "تخطّي التسوية المالية للرحلة ID {$trip->id}: لم يُنقل فيها أي طفل فعلياً."
            );
            return 0;
        }

        $subscriptionRequestIds = $this->resolveSettleableSubscriptionRequestIds($trip);

        // لا نصرف شيئاً إذا تعذّر ربط الرحلة بأي اشتراك — الصمت أأمن من صرف أموال خاطئة.
        if ($subscriptionRequestIds->isEmpty()) {
            \Illuminate\Support\Facades\Log::warning(
                "تخطّي التسوية المالية للرحلة ID {$trip->id}: لا يمكن تحديد الاشتراكات التي خدمتها هذه الرحلة."
            );
            return 0;
        }

        $finances = \App\Models\Shared\PlatformFinance::where('driver_id', $trip->driver_id)
            ->where('status', \App\Models\Shared\PlatformFinance::STATUS_HELD)
            ->whereIn('subscription_request_id', $subscriptionRequestIds)
            ->get();

        $settledCount = 0;
        $driver = Driver::find($trip->driver_id);
        $ledger = app(\App\Services\Shared\FinancialLedgerService::class);

        foreach ($finances as $finance) {
            // القيد الفريد (platform_finance_id, trip_id) هو الحارس النهائي ضد الصرف المزدوج:
            // أي إعادة إرسال أو تسابق سيفشل هنا على مستوى قاعدة البيانات لا على مستوى الكود.
            $alreadySettled = DB::table('platform_finance_trip_settlements')
                ->where('platform_finance_id', $finance->id)
                ->where('trip_id', $trip->id)
                ->exists();

            if ($alreadySettled) {
                continue;
            }

            $expectedTrips = max(1, (int) ($finance->expected_trips_count ?? 1));
            $settledTrips  = (int) ($finance->settled_trips_count ?? 0);

            if ($settledTrips >= $expectedTrips) {
                continue;
            }

            $totalCents        = (int) round(((float) $finance->total_amount) * 100);
            $alreadyPaidCents  = (int) round(((float) ($finance->settled_amount ?? 0)) * 100);
            $remainingCents    = max(0, $totalCents - $alreadyPaidCents);
            $isLastTrip        = ($settledTrips + 1) >= $expectedTrips;

            // الرحلة الأخيرة تأخذ كل المتبقي حتى لا تضيع كسور القسمة داخل الخزينة
            $shareCents = $isLastTrip
                ? $remainingCents
                : min($remainingCents, intdiv($totalCents, $expectedTrips));

            if ($shareCents <= 0) {
                continue;
            }

            $rate            = (float) ($finance->platform_commission_rate ?? 0);
            $commissionCents = (int) round($shareCents * $rate / 100);
            $driverNetCents  = max(0, $shareCents - $commissionCents);

            DB::transaction(function () use (
                $finance, $trip, $driver, $ledger, $shareCents,
                $commissionCents, $driverNetCents, $settledTrips, $expectedTrips, $alreadyPaidCents
            ) {
                DB::table('platform_finance_trip_settlements')->insert([
                    'platform_finance_id' => $finance->id,
                    'trip_id'             => $trip->id,
                    'gross_amount'        => round($shareCents / 100, 2),
                    'commission_amount'   => round($commissionCents / 100, 2),
                    'driver_net_amount'   => round($driverNetCents / 100, 2),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

                $vault = \App\Models\Shared\MasterEscrowVault::getVault();
                $vault->decrement('parents_escrow_pool', $shareCents);
                $vault->increment('platform_revenue_pool', $commissionCents);

                if ($driver) {
                    $driver->deposit($driverNetCents);
                    // ⚠️ إلزامي مع كل إيداع في محفظة سائق: كان هذا السطر غائباً عن
                    // المسار المالي الرئيسي للنظام، فيرتفع رصيد المحفظة بينما يبقى
                    // driver_available_pool ثابتاً، وينحرف فحص السلامة المالية مع
                    // كل رحلة تُنفَّذ حتى يصبح رقم الفرق بلا معنى.
                    $vault->increment('driver_available_pool', $driverNetCents);
                }

                $newSettledTrips  = $settledTrips + 1;
                $newSettledAmount = round(($alreadyPaidCents + $shareCents) / 100, 2);
                $isFullySettled   = $newSettledTrips >= $expectedTrips;

                $finance->update([
                    'trip_id'             => $trip->id,
                    'settled_trips_count' => $newSettledTrips,
                    'settled_amount'      => $newSettledAmount,
                    'status'              => $isFullySettled
                        ? \App\Models\Shared\PlatformFinance::STATUS_COMPLETED
                        : \App\Models\Shared\PlatformFinance::STATUS_HELD,
                    'settled_at'          => $isFullySettled ? now() : null,
                ]);

                try {
                    $ledger->recordLedgerEntry(
                        'parents_escrow_pool',
                        \App\Services\Shared\FinancialLedgerService::driverAccount($trip->driver_id),
                        $driverNetCents,
                        'driver_payout',
                        0,
                        (int) ($driver?->balance ?? 0),
                        "PAYOUT-TRIP-{$trip->id}-{$finance->id}",
                        [
                            'platform_finance_id' => $finance->id,
                            'trip_id'             => $trip->id,
                            'trip_share_of'       => $expectedTrips,
                        ]
                    );

                    $ledger->recordLedgerEntry(
                        'parents_escrow_pool',
                        'platform_revenue_pool',
                        $commissionCents,
                        'platform_commission',
                        0,
                        $commissionCents,
                        "COMMISSION-TRIP-{$trip->id}-{$finance->id}"
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("فشل تسجيل حركات السجل المالي لاكتمال الرحلة ID {$trip->id}: " . $e->getMessage());
                }
            });

            $settledCount++;
        }

        return $settledCount;
    }

    /**
     * تسوية المستحقات المالية للسائق البديل لطلبات الإنقاذ المرتبطة بهذه الرحلة
     */
    public function settleEmergencyBreakdownDispatchesForTrip(Trip $trip): void
    {
        try {
            $dispatches = \App\Models\Shared\TripBreakdownDispatch::where(function ($q) use ($trip) {
                $q->where('substitute_trip_id', $trip->id)
                  ->orWhere(function ($sq) use ($trip) {
                      $sq->where('substitute_driver_id', $trip->driver_id)
                         ->where('status', \App\Models\Shared\TripBreakdownDispatch::STATUS_ACCEPTED);
                  });
            })
            ->where('financial_settled', false)
            ->get();

            $emergencyService = app(EmergencyBreakdownService::class);
            foreach ($dispatches as $dispatch) {
                $emergencyService->settleBreakdownFinancialTransfer($dispatch);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("فشل تسوية المستحقات الطارئة للرحلة ID {$trip->id}: " . $e->getMessage());
        }
    }
}