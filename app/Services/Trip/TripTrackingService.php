<?php

namespace App\Services\Trip;

use App\Models\Shared\Trip;
use App\Models\Shared\TripEvent;
use App\Models\Shared\TripTracking;
use App\Models\Shared\ActiveSubscription;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\Shared\TripStop;
use App\Models\Driver\DriverSeatSlot;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Kreait\Firebase\Factory;
use Throwable;

class TripTrackingService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * استقبال إحداثيات السائق وتخزينها ذكياً
     */
    public function updateDriverLocation(int $tripId, float $lat, float $lng, float $speed = 0, ?float $heading = null): array
    {
        $trip = Trip::findOrFail($tripId);

        // 1. الاستشعار التلقائي للبدء (Auto-Start)
        if ($trip->status === 'pending' && $speed > 10) {
            $this->handleAutoStart($trip);
        }

        $driverId = $trip->driver_id;

        // تحديث الموقع اللحظي للسائق
        Driver::where('id', $driverId)->update([
            'current_lat' => $lat,
            'current_lng' => $lng
        ]);

        // 2. هندسة الكاش لمنع التكرار في قاعدة البيانات
        $cacheKey = "driver_last_loc_{$driverId}";
        $lastLocation = Cache::get($cacheKey);
        $shouldSaveToDb = (!$lastLocation || $this->calculateHaversineDistance($lastLocation['lat'], $lastLocation['lng'], $lat, $lng) > 0.015);

        if ($shouldSaveToDb) {
            TripTracking::create([
                'trip_id' => $tripId,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed' => $speed,
                'recorded_at' => Carbon::now()
            ]);
        }

        // نحدّث موقع الكاش + الطابع الزمني في كل نبضة (وليس فقط عند تغيّر الموقع بأكثر من 15م)
        // حتى يعكس is_online عند ولي الأمر آخر وقت اتصال فعلي من السائق، لا آخر نقطة محفوظة بقاعدة البيانات
        Cache::put($cacheKey, [
            'lat'        => $lat,
            'lng'        => $lng,
            'timestamp'  => now()->timestamp,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        // 3. مزامنة الموقع اللحظي مع Firestore (trips_tracking/{tripId}) ليقرأها تطبيق ولي الأمر مباشرة
        $this->pushLocationToFirestore($tripId, $driverId, $lat, $lng, $speed, $heading, $trip->status === 'in_progress');

        return [
            'status' => 'success',
            'proximity' => $this->checkProximityAndArrival($trip, $lat, $lng)
        ];
    }

    /**
     * كتابة/تحديث موقع الرحلة اللحظي في Firestore بنفس صيغة الـ Document المتفق عليها مع الفرونت
     * (Collection: trips_tracking, Document ID = trip_id) — لا يوقف تدفق تحديث الموقع إذا فشل.
     *
     * الحقول الموحدة (Standard Schema المتفق عليه مع الفرونت):
     *   trip_id, driver_id, driver_lat, driver_lng, heading, speed, is_online, status, updated_at
     */
    protected function pushLocationToFirestore(int $tripId, int $driverId, float $lat, float $lng, float $speed = 0, ?float $heading = null, bool $isOnline = true): void
    {
        $serviceAccountPath = config('firebase.credentials.file', storage_path('app/firebase/firebase-service-account.json'));

        if (!file_exists($serviceAccountPath) && file_exists(base_path($serviceAccountPath))) {
            $serviceAccountPath = base_path($serviceAccountPath);
        }

        if (!file_exists($serviceAccountPath)) {
            return;
        }

        try {
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $database = $factory->createFirestore()->database();

            $database->collection('trips_tracking')->document((string) $tripId)->set([
                'trip_id'    => $tripId,
                'driver_id'  => $driverId,
                'driver_lat' => (float) $lat,
                'driver_lng' => (float) $lng,
                'speed'      => (float) $speed,
                'heading'    => (float) ($heading ?? 0.0),
                'status'     => 'active',
                'is_online'  => (bool) $isOnline,
                'updated_at' => now()->toIso8601String(),
            ], ['merge' => true]);
        } catch (Throwable $e) {
            Log::warning("فشل مزامنة موقع الرحلة رقم {$tripId} مع Firestore - " . $e->getMessage());
        }
    }


    /**
     * تعليم وثيقة الرحلة في Firestore كـ "غير متصلة" عند إنهائها (أو إلغائها).
     *
     * بدون هذا، تبقى وثيقة trips_tracking/{tripId} على آخر حالة وصلتها من آخر نبضة GPS
     * قبل إغلاق الرحلة (is_online: true + آخر موقع) للأبد — لأن pushLocationToFirestore()
     * لا تُستدعى إلا من نبضات GPS الفعلية، وما فماش نبضات بعد إغلاق الرحلة. أي تطبيق
     * ولي الأمر يستمع مباشرة على هذه الوثيقة (Firebase Listener) يبقى يشوف الحافلة "أونلاين"
     * في آخر نقطة حتى بعد سويعات من وصولها فعلياً.
     *
     * merge:true عمداً — تحدّث فقط is_online/last_updated بلا ما تحذف أو تكتب فوق باقي
     * الحقول (lat/lng/heading) اللي تبقى كآخر موقع معروف "تاريخياً" لغرض التصحيح لاحقاً.
     */
    public function markTripOfflineInFirestore(int $tripId): void
    {
        $serviceAccountPath = config('firebase.credentials.file', storage_path('app/firebase/firebase-service-account.json'));

        if (!file_exists($serviceAccountPath) && file_exists(base_path($serviceAccountPath))) {
            $serviceAccountPath = base_path($serviceAccountPath);
        }

        if (!file_exists($serviceAccountPath)) {
            return;
        }

        try {
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $database = $factory->createFirestore()->database();

            $database->collection('trips_tracking')->document((string) $tripId)->set([
                'status'       => 'completed',
                'is_online'    => false,
                'last_updated' => now()->toIso8601String(),
            ], ['merge' => true]);
        } catch (Throwable $e) {
            Log::warning("فشل تحديث حالة الأوفلاين للرحلة رقم {$tripId} في Firestore - " . $e->getMessage());
        }
    }

    /**
     * معالجة بدء الرحلة التلقائي والتصحيح اللاحق (Back-dating)
     */
    protected function handleAutoStart(Trip $trip)
    {
        // جلب وقت أول إحداثية مسجلة للرحلة (دقة الـ Back-dating)
        $firstTracking = TripTracking::where('trip_id', $trip->id)->orderBy('recorded_at', 'asc')->first();
        $startTime = $firstTracking ? $firstTracking->recorded_at : Carbon::now();

        $trip->update([
            'status' => 'in_progress',
            'started_at' => $startTime,
            'actual_start_time' => $startTime
        ]);

        // إخطار الأهالي المعنيين فقط
        $this->notifyPendingParents($trip);
    }

    /**
     * إخطار الأهالي الذين لم يركب أطفالهم بعد (تجنب الإزعاج)
     */
    protected function notifyPendingParents(Trip $trip)
    {
        $today = Carbon::today()->toDateString();

        $pendingSubscriptions = ActiveSubscription::forDriver($trip->driver_id)
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))
                ->from('absence_logs')
                ->join('request_children', 'request_children.child_id', '=', 'absence_logs.child_id')
                ->whereColumn('request_children.id', 'active_subscriptions.request_child_id')
                ->whereDate('absence_logs.absence_date', $today))
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))
                ->from('trip_events')
                ->join('request_children', 'request_children.child_id', '=', 'trip_events.child_id')
                ->whereColumn('request_children.id', 'active_subscriptions.request_child_id')
                ->where('trip_events.trip_id', $trip->id)
                ->whereIn('trip_events.action_type', ['picked_up', 'skipped']))
            ->get();

        foreach ($pendingSubscriptions as $sub) {
            if ($user = $sub->child->parent->user ?? null) {
                $this->notificationService->sendToUser($user, 'trip_started', [
                    'title'   => 'بدأت الرحلة 🚌',
                    'message' => 'الحافلة في طريقها إليكم الآن.',
                    'trip_id' => (string) $trip->id,
                ]);
            }
        }
    }

    protected function checkProximityAndArrival(Trip $trip, float $driverLat, float $driverLng): array
    {
        $alerts = [];
        $isGoTrip = DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
            || (!$trip->shift_slot && ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب'));

        // جلب جميع محطات الرحلة مرتبة
        $stops = TripStop::where('trip_id', $trip->id)
            ->with(['child.parent.user', 'school'])
            ->get();

        $driverUser = $trip->driver?->user;

        foreach ($stops as $stop) {
            if (!$stop->lat || !$stop->lng) {
                continue;
            }

            $distKm = $this->calculateHaversineDistance($driverLat, $driverLng, (float) $stop->lat, (float) $stop->lng);
            $distMeters = $distKm * 1000;

            if ($stop->stop_type === TripStop::TYPE_HOME) {
                $child = $stop->child;
                $childName = $child?->full_name ?? $child?->name ?? 'الطفل';
                $parentUser = $child?->parent?->user;

                // 1️⃣ إشعار الاقتراب (أقل من 200 متر) لولي الأمر
                if ($distMeters <= 200) {
                    $shouldAlertProximity = $isGoTrip
                        ? ($stop->status === TripStop::STATUS_PENDING)
                        : in_array($stop->status, [TripStop::STATUS_PENDING, TripStop::STATUS_BOARDED]);

                    if ($shouldAlertProximity) {
                        $proxCacheKey = "proximity_alert_sent_{$trip->id}_{$stop->child_id}";
                        if (!Cache::has($proxCacheKey)) {
                            Cache::put($proxCacheKey, true, now()->addHours(6));

                            if ($parentUser) {
                                $this->notificationService->sendToUser($parentUser, NotificationFormatter::TYPE_DRIVER_APPROACHING, [
                                    'title'      => 'الحافلة تقترب 🚏',
                                    'message'    => "السائق على بُعد أقل من 200 متر من منزلكم (الطفل: {$childName})، يرجى التجهز.",
                                    'child_name' => $childName,
                                    'trip_id'    => (string) $trip->id,
                                    'child_id'   => $stop->child_id,
                                    'entity_id'  => $trip->id . '_prox_' . $stop->child_id,
                                ]);
                            }

                            $alerts[] = [
                                'type'            => 'approaching_home',
                                'child_id'        => $stop->child_id,
                                'child_name'      => $childName,
                                'distance_meters' => round($distMeters, 1),
                            ];
                        }
                    }
                }

                // 2️⃣ إشعار الوصول لموقع الطفل (50-60 متر) لولي الأمر + للسائق
                if ($distMeters <= 60) {
                    $shouldAlertArrival = $isGoTrip
                        ? ($stop->status === TripStop::STATUS_PENDING)
                        : in_array($stop->status, [TripStop::STATUS_PENDING, TripStop::STATUS_BOARDED]);

                    if ($shouldAlertArrival) {
                        // إشعار لولي الأمر
                        $parentArrKey = "arrival_parent_home_sent_{$trip->id}_{$stop->child_id}";
                        if (!Cache::has($parentArrKey)) {
                            Cache::put($parentArrKey, true, now()->addHours(6));

                            if ($parentUser) {
                                $arrMsg = $isGoTrip
                                    ? "وصل السائق الآن إلى موقع منزلكم لاصطحاب الطفل ({$childName})."
                                    : "وصل السائق الآن إلى موقع منزلكم لإيصال ونزول الطفل ({$childName}).";

                                $this->notificationService->sendToUser($parentUser, NotificationFormatter::TYPE_DRIVER_ARRIVED, [
                                    'title'      => 'وصل السائق للمنزل 📍',
                                    'message'    => $arrMsg,
                                    'child_name' => $childName,
                                    'trip_id'    => (string) $trip->id,
                                    'child_id'   => $stop->child_id,
                                    'entity_id'  => $trip->id . '_arr_' . $stop->child_id,
                                ]);
                            }
                        }

                        // إشعار للسائق
                        $driverArrKey = "arrival_driver_home_sent_{$trip->id}_{$stop->child_id}";
                        if (!Cache::has($driverArrKey)) {
                            Cache::put($driverArrKey, true, now()->addHours(6));

                            if ($driverUser) {
                                $driverMsg = $isGoTrip
                                    ? "لقد وصلت إلى موقع منزل الطفل ({$childName})، يرجى انتظار الطالب (الحد الأقصى للانتظار 10 دقائق)."
                                    : "لقد وصلت إلى موقع منزل الطفل ({$childName})، يرجى تأكيد تسليم ونزول الطالب.";

                                $this->notificationService->sendToUser($driverUser, NotificationFormatter::TYPE_DRIVER_ARRIVED_HOME, [
                                    'title'      => 'وصلت إلى موقع الطالب 🏠',
                                    'message'    => $driverMsg,
                                    'child_name' => $childName,
                                    'trip_id'    => (string) $trip->id,
                                    'child_id'   => $stop->child_id,
                                    'entity_id'  => $trip->id . '_driver_home_' . $stop->child_id,
                                ]);
                            }

                            // تفعيل عداد الانتظار في الكاش (10 دقائق)
                            Cache::put("trip_waiting_{$trip->id}_{$stop->child_id}", [
                                'start_time'  => now()->toIso8601String(),
                                'max_minutes' => 10,
                            ], now()->addHours(2));

                            $alerts[] = [
                                'type'                => 'arrived_home',
                                'child_id'            => $stop->child_id,
                                'child_name'          => $childName,
                                'distance_meters'     => round($distMeters, 1),
                                'max_waiting_minutes' => 10,
                            ];
                        }
                    }
                }
            } elseif ($stop->stop_type === TripStop::TYPE_SCHOOL) {
                $schoolName = $stop->school?->name ?? $stop->label ?? 'المدرسة';

                // 3️⃣ إشعار وصول السائق إلى المدرسة (مسافة 80 متر أو أقل)
                if ($distMeters <= 80) {
                    $driverSchoolArrKey = "arrival_driver_school_sent_{$trip->id}_{$stop->school_id}";
                    if (!Cache::has($driverSchoolArrKey)) {
                        Cache::put($driverSchoolArrKey, true, now()->addHours(6));

                        if ($driverUser) {
                            $schoolMsg = $isGoTrip
                                ? "لقد وصلت إلى ({$schoolName})، يرجى تأكيد نزول الطلاب في المدرسة."
                                : "لقد وصلت إلى ({$schoolName})، يرجى انتظار صعود الطلاب (الحد الأقصى للانتظار 15 دقيقة).";

                            $this->notificationService->sendToUser($driverUser, NotificationFormatter::TYPE_DRIVER_ARRIVED_SCHOOL, [
                                'title'       => 'وصلت إلى المدرسة 🏫',
                                'message'     => $schoolMsg,
                                'school_name' => $schoolName,
                                'trip_id'     => (string) $trip->id,
                                'school_id'   => $stop->school_id,
                                'entity_id'   => $trip->id . '_driver_school_' . $stop->school_id,
                            ]);
                        }

                        // تفعيل عداد انتظار المدرسة في الكاش (15 دقيقة)
                        Cache::put("trip_school_waiting_{$trip->id}_{$stop->school_id}", [
                            'start_time'  => now()->toIso8601String(),
                            'max_minutes' => 15,
                        ], now()->addHours(2));

                        $alerts[] = [
                            'type'                => 'arrived_school',
                            'school_id'           => $stop->school_id,
                            'school_name'         => $schoolName,
                            'distance_meters'     => round($distMeters, 1),
                            'max_waiting_minutes' => 15,
                        ];
                    }
                }
            }
        }

        return [
            'status' => 'tracking',
            'alerts' => $alerts,
        ];
    }

    protected function calculateHaversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}