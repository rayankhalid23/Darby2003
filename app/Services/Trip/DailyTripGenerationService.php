<?php

namespace App\Services\Trip;

use App\Models\Driver\DriverAbsence;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\Child;
use App\Models\Shared\AbsenceLog;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * يولّد الرحلة اليومية التشغيلية (Daily Trip Instance) من المسار الهيكلي المرجعي (Master Route)
 * قبل موعد الوردية بـ 30 دقيقة، مع استبعاد الأطفال المسجل غيابهم مسبقاً (absent_pre).
 */
class DailyTripGenerationService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * ينشئ (أو يعيد) رحلة اليوم لمسار معين — عملية idempotent، آمنة للاستدعاء المتكرر
     * (سواء من الـ cron أو من السحب اللحظي في todayTrips()).
     */
    public function generateForRoute(Route $route, ?Carbon $date = null): ?Trip
    {
        $date = $date ?? Carbon::today();
        $dateString = $date->toDateString();

        // يُستبعد هذا المسار من التوليد إن كان للسائق غياب معتمد يشمل هذا اليوم بالكامل
        // (المسار القديم بدون تحديد رحلات)، أو غياب معتمد مرتبط تحديداً برحلة على هذا
        // المسار بالذات (المسار الجديد القائم على اختيار رحلات محددة) — دون التأثير على
        // بقية ورديات نفس السائق في نفس اليوم التي لم يطلب الغياب عنها.
        $isDriverAbsent = DriverAbsence::where('driver_id', $route->driver_id)
            ->whereDate('absence_date', $dateString)
            ->where(function ($q) use ($route) {
                $q->whereDoesntHave('trips')
                  ->orWhereHas('trips', function ($tq) use ($route) {
                      $tq->where('route_id', $route->id);
                  });
            })
            ->exists();

        if ($isDriverAbsent) {
            return null;
        }

        // ⚠️ لا تُولَّد رحلة لتاريخ لا يغطيه أي اشتراك فعّال على هذا المسار.
        // بدون هذا الفحص كانت الرحلات تُولَّد إلى ما لا نهاية بعد انتهاء الاشتراك
        // (المسار يبقى Active ولا توجد وظيفة تُعطّله)، فتتضخم الجداول والتقارير
        // برحلات لا يقابلها أي التزام مالي أو تعاقدي.
        if (!$this->routeHasCoverageOn($route, $dateString)) {
            return null;
        }

        // البحث بمعرّف المسار والتاريخ والنوع فقط (وليس معرّف السائق)، لأن الرحلة قد تكون
        // بلا سائق مُسنَد حالياً (driver_id = null) نتيجة فصل السائق عنها بعد تسجيل غيابه؛
        // الاعتماد على driver_id هنا كان يجعل هذا الفحص يفشل في إيجادها فيُعاد إنشاؤها من
        // جديد بنفس السائق الغائب، فيُلغي أثر الغياب فعلياً.
        $trip = Trip::where('route_id', $route->id)
            ->where('trip_date', $dateString)
            ->where('trip_type', $route->route_type)
            ->first();

        $isNew = false;

        if (!$trip) {
            $trip = Trip::create([
                'driver_id'            => $route->driver_id,
                'route_id'             => $route->id,
                'trip_type'            => $route->route_type,
                'shift_slot'           => $route->shift_slot,
                'status'               => 'pending',
                'scheduled_at'         => now(),
                'scheduled_start_time' => $route->start_time,
                'trip_date'            => $dateString,
            ]);
            $isNew = true;
        }

        if (TripStop::where('trip_id', $trip->id)->doesntExist()) {
            $this->buildTripStops($trip, $route, $dateString);
        }

        if ($isNew) {
            $this->notifyDriverAndParents($trip, $route);
        }

        return $trip;
    }

    /**
     * يفحص كل المسارات النشطة ويولّد رحلة اليوم لأي مسار دخل نافذة T-30 ولم تُولَّد رحلته بعد.
     */
    public function generateDueTrips(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $today = $now->copy()->startOfDay();

        $routes = Route::where('status', 'Active')
            ->whereNotNull('shift_slot')
            ->whereNotNull('start_time')
            ->get();

        $generated = 0;
        $skipped = 0;

        foreach ($routes as $route) {
            // بمعرّف المسار والتاريخ فقط، بغض النظر عن driver_id الحالي للرحلة (قد تكون
            // بلا سائق مُسنَد بعد فصل سائق غائب عنها) — لتفادي إعادة إنشاء رحلة مكرّرة.
            $alreadyExists = Trip::where('route_id', $route->id)
                ->where('trip_date', $today->toDateString())
                ->exists();

            if ($alreadyExists) {
                $skipped++;
                continue;
            }

            $startDateTime = Carbon::parse($today->toDateString() . ' ' . $route->start_time);
            $windowStart = $startDateTime->copy()->subMinutes(30);

            if (!$this->routeHasCoverageOn($route, $today->toDateString())) {
                $skipped++;
                continue;
            }

            if ($now->greaterThanOrEqualTo($windowStart) && $now->lessThanOrEqualTo($startDateTime)) {
                $trip = $this->generateForRoute($route, $today);
                $trip ? $generated++ : $skipped++;
            } else {
                $skipped++;
            }
        }

        return [
            'checked'   => $routes->count(),
            'generated' => $generated,
            'skipped'   => $skipped,
        ];
    }

    /**
     * هل يوجد اشتراك فعّال على هذا المسار تغطي فترته التاريخ المطلوب؟
     */
    private function routeHasCoverageOn(Route $route, string $dateString): bool
    {
        $subscriptionRequestIds = \App\Models\Shared\ActiveSubscription::where('route_id', $route->id)
            ->where('status', '!=', 'cancelled')
            ->pluck('subscription_request_id')
            ->filter()
            ->unique();

        if ($subscriptionRequestIds->isEmpty()) {
            // مسار بلا اشتراكات مرتبطة — نتركه للسلوك القديم بدل حجب رحلات مشروعة
            return true;
        }

        $covers = \Illuminate\Support\Facades\DB::table('request_children')
            ->whereIn('request_id', $subscriptionRequestIds)
            ->whereDate('start_date', '<=', $dateString)
            ->whereDate('end_date', '>=', $dateString)
            ->exists();

        if ($covers) {
            return true;
        }

        // توافقية: اشتراكات قديمة بلا تواريخ على مستوى الطفل
        $hasAnyChildDates = \Illuminate\Support\Facades\DB::table('request_children')
            ->whereIn('request_id', $subscriptionRequestIds)
            ->whereNotNull('start_date')
            ->exists();

        return !$hasAnyChildDates;
    }

    private function buildTripStops(Trip $trip, Route $route, string $dateString): void
    {
        $routeStops = RouteStop::where('route_id', $route->id)->orderBy('sequence_order')->get();
        if ($routeStops->isEmpty()) {
            return;
        }

        $isGo = DriverSeatSlot::isGoSlot($route->shift_slot ?? '');
        $absenceTypesToExclude = $isGo
            ? [AbsenceLog::TYPE_PICKUP, AbsenceLog::TYPE_BOTH]
            : [AbsenceLog::TYPE_DROPOFF, AbsenceLog::TYPE_BOTH];

        $absentChildIds = AbsenceLog::whereDate('absence_date', $dateString)
            ->whereIn('absence_type', $absenceTypesToExclude)
            ->pluck('child_id')
            ->all();

        // المرحلة 1: تحديد الأطفال المتبقين (غير الغائبين) اليوم
        $keptChildIds = $routeStops->where('stop_type', RouteStop::TYPE_HOME)
            ->pluck('child_id')
            ->reject(fn($id) => in_array($id, $absentChildIds))
            ->values()
            ->all();

        $keptSchoolIds = !empty($keptChildIds)
            ? Child::whereIn('id', $keptChildIds)->pluck('school_id')->unique()->all()
            : [];

        // المرحلة 1.5: جلب أي طلبات تغيير موقع معتمدة لتاريخ اليوم لتطبيقها على محطات الرحلة
        $approvedLocationChanges = \App\Models\Shared\LocationChangeRequest::where('driver_id', $route->driver_id)
            ->where('status', \App\Models\Shared\LocationChangeRequest::STATUS_APPROVED)
            ->whereDate('change_date', $dateString)
            ->get()
            ->keyBy(function ($item) {
                return $item->child_id . '_' . $item->point_type;
            });

        // المرحلة 2: بناء المحطات بنفس الترتيب الأصلي، مع تطبيق أي تغيير موقع مؤقت لليوم وإعادة ترقيم المحطات المتبقية فقط
        $sequence = 1;

        foreach ($routeStops as $stop) {
            $isHome = $stop->stop_type === RouteStop::TYPE_HOME;
            $isKept = $isHome
                ? in_array($stop->child_id, $keptChildIds)
                : in_array($stop->school_id, $keptSchoolIds);

            $pointType = $isHome ? 'pickup' : 'dropoff';
            $changeKey = ($stop->child_id ?? 0) . '_' . $pointType;
            $locChange = $approvedLocationChanges->get($changeKey);

            $lat   = $locChange ? $locChange->new_lat : $stop->lat;
            $lng   = $locChange ? $locChange->new_lng : $stop->lng;
            $label = $locChange ? $locChange->new_label : $stop->label;

            TripStop::create([
                'trip_id'        => $trip->id,
                'route_stop_id'  => $stop->id,
                'stop_type'      => $stop->stop_type,
                'child_id'       => $stop->child_id,
                'school_id'      => $stop->school_id,
                'lat'            => $lat,
                'lng'            => $lng,
                'label'          => $label,
                'sequence_order' => $isKept ? $sequence : 0,
                'status'         => $isKept ? TripStop::STATUS_PENDING : TripStop::STATUS_ABSENT_PRE,
            ]);

            if ($isKept) {
                $sequence++;
            }
        }
    }

    private function notifyDriverAndParents(Trip $trip, Route $route): void
    {
        try {
            $driverUser = $route->driver?->user;
            if ($driverUser) {
                $this->notificationService->sendToUser($driverUser, 'trip_ready', [
                    'title'   => 'رحلتك القادمة تبدأ بعد 30 دقيقة',
                    'message' => 'تم تجهيز رحلتك القادمة. انقر لمعاينة الطلاب.',
                    'trip_id' => (string) $trip->id,
                ]);
            }

            $pendingChildIds = TripStop::where('trip_id', $trip->id)
                ->where('stop_type', TripStop::TYPE_HOME)
                ->where('status', TripStop::STATUS_PENDING)
                ->pluck('child_id');

            if ($pendingChildIds->isEmpty()) {
                return;
            }

            $parentUserIds = Child::whereIn('id', $pendingChildIds)
                ->pluck('parent_id')
                ->unique();

            $users = User::whereIn('id', $parentUserIds)->get();

            if ($users->isNotEmpty()) {
                $this->notificationService->sendToUsers($users, 'trip_upcoming', [
                    'title'   => 'تذكير: انطلاق رحلة طفلكم بعد 30 دقيقة',
                    'message' => 'رحلة طفلكم ستنطلق قريباً، يرجى الاستعداد.',
                    'trip_id' => (string) $trip->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعارات توليد الرحلة اليومية للرحلة ID: {$trip->id} - " . $e->getMessage());
        }
    }
}
