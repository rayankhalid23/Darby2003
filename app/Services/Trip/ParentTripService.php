<?php

namespace App\Services\Trip;

use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ParentTripService
{
    private DailyTripGenerationService $tripGenerationService;

    public function __construct(DailyTripGenerationService $tripGenerationService)
    {
        $this->tripGenerationService = $tripGenerationService;
    }

    private function resolveRequestedDate(?string $requestedDate): string
    {
        if ($requestedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
            try {
                return Carbon::parse($requestedDate)->toDateString();
            } catch (\Throwable) {
                // fallback
            }
        }
        return Carbon::now(config('app.timezone', 'Africa/Tripoli'))->toDateString();
    }

    /**
     * يدمج تاريخ الرحلة الفعلي مع وقت انطلاقها المجدول في طابع زمني واحد صحيح.
     */
    private function resolveTripScheduledFor(Trip $trip): ?string
    {
        if (!$trip->scheduled_start_time) {
            return null;
        }

        $time = Carbon::parse($trip->scheduled_start_time)->format('H:i:s');
        $date = $trip->trip_date
            ? Carbon::parse($trip->trip_date)->toDateString()
            : Carbon::parse($trip->scheduled_start_time)->toDateString();

        return Carbon::parse($date . ' ' . $time)->toIso8601String();
    }

    /**
     * سعر الرحلة الواحدة للطفل — مصدر واحد تستخدمه كل الشاشات.
     * الأولوية لـ trip_price المخزّن (سعر الرحلة المفردة بعد التخفيض)، وإن لم يوجد
     * يُشتق من إجمالي الاشتراك ÷ (أيام العمل × عدد الرحلات في اليوم).
     */
    private function resolvePerTripCost($childPivot, $subscriptionRequest = null): float
    {
        if (!$childPivot) {
            return 0.0;
        }

        $tripPrice = (float) ($childPivot->trip_price ?? 0);
        if ($tripPrice > 0) {
            return round($tripPrice, 2);
        }

        $totalAmount = (float) ($childPivot->total_amount_after_discount ?? $childPivot->price_per_child ?? 0);
        if ($totalAmount <= 0) {
            return 0.0;
        }

        $workingDays = max(1, (int) ($subscriptionRequest->working_days_count ?? 1));
        $tripsPerDay = in_array($subscriptionRequest?->trip_direction, ['one_way_morning', 'one_way_evening'], true) ? 1 : 2;

        return round($totalAmount / max(1, $workingDays * $tripsPerDay), 2);
    }

    private function resolveParentIds(int $userId): array
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if ($parent) {
            return [$parent->id];
        }
        return [$userId];
    }

    /**
     * 1. GET /api/parent/trips/active
     * جلب الرحلات المفعلة حالياً لأطفال ولي الأمر مجمعة بحسب الرحلة
     */
    public function getActiveTripsForParent(int $userId, ?string $date = null): array
    {
        $targetDate = $this->resolveRequestedDate($date);
        $parentIds = $this->resolveParentIds($userId);

        $childIds = DB::table('children')
            ->whereIn('parent_id', $parentIds)
            ->pluck('id')
            ->toArray();

        if (empty($childIds)) {
            return [];
        }

        $subscriptions = ActiveSubscription::forChildren($childIds)
            ->where('status', 'active')
            ->with(['child.address', 'child.school', 'driver.user', 'driver.vehicles', 'school'])
            ->get();

        $driverIds = $subscriptions->pluck('driver_id')->unique()->toArray();

        $activeTrips = Trip::whereIn('driver_id', $driverIds)
            ->where('status', 'in_progress')
            ->whereDate('trip_date', $targetDate)
            ->with(['driver.user', 'driver.vehicles'])
            ->get();

        $result = [];

        foreach ($activeTrips as $trip) {
            $subChildren = $subscriptions->where('driver_id', $trip->driver_id);
            $direction = strtolower($trip->trip_type) === 'afternoon' ? 'to_home' : 'to_school';
            $childrenArray = [];
            $destinationsByKey = [];

            // احتساب إشغال الحافلة وعدد الأطفال الراكبين حالياً
            $tripStops = DB::table('trip_stops')->where('trip_id', $trip->id)->get();
            if ($tripStops->isNotEmpty()) {
                $totalTripChildren = $tripStops->whereNotNull('child_id')->unique('child_id')->count();
                $currentOnboardCount = $tripStops->whereNotNull('child_id')
                    ->whereIn('status', ['boarded', 'picked_up'])
                    ->unique('child_id')
                    ->count();
            } else {
                $totalTripChildren = ActiveSubscription::forDriver($trip->driver_id)
                    ->where('status', 'active')
                    ->count();

                $boardedCount = DB::table('trip_events')
                    ->where('trip_id', $trip->id)
                    ->whereIn('action_type', ['boarded', 'picked_up'])
                    ->distinct('child_id')
                    ->count('child_id');

                $droppedCount = DB::table('trip_events')
                    ->where('trip_id', $trip->id)
                    ->whereIn('action_type', ['dropped_off', 'dropped_off_school', 'delivered_home'])
                    ->distinct('child_id')
                    ->count('child_id');

                $currentOnboardCount = max(0, $boardedCount - $droppedCount);
            }

            foreach ($subChildren as $sub) {
                $childObj = $sub->child;
                if (!$childObj) continue;

                // trip_stops هو مصدر الحقيقة الدقيق (boarded/absent_late/dropped_off_school/...)؛
                // نرجع لـ trip_events/absence_logs فقط للرحلات القديمة التي لا تملك trip_stops بعد
                $stop = DB::table('trip_stops')
                    ->where('trip_id', $trip->id)
                    ->where('child_id', $childObj->id)
                    ->where('stop_type', 'home')
                    ->first();

                if ($stop) {
                    $childStatus = $stop->status;
                } else {
                    $event = DB::table('trip_events')
                        ->where('trip_id', $trip->id)
                        ->where('child_id', $childObj->id)
                        ->latest('scanned_at')
                        ->first();

                    $isAbsent = DB::table('absence_logs')
                        ->where('child_id', $childObj->id)
                        ->whereDate('absence_date', $targetDate)
                        ->exists();

                    $childStatus = 'waiting';
                    if ($isAbsent) {
                        $childStatus = 'absent';
                    } elseif ($event) {
                        $childStatus = $event->action_type;
                    }
                }

                // تحويل حالة الطفل إلى الحالات القياسية الموحدة (onboard, waiting, dropped_off, absent)
                $mappedChildStatus = match ($childStatus) {
                    'boarded', 'picked_up', 'onboard'                      => 'onboard',
                    'dropped_off', 'dropped_off_school', 'delivered_home' => 'dropped_off',
                    'absent', 'absent_pre', 'absent_late'                  => 'absent',
                    'waiting', 'pending'                                   => 'waiting',
                    default                                                => $childStatus ?? 'waiting',
                };

                $rawPhoto = $childObj->photo_url ?? null;
                $photoUrl = $rawPhoto ? (str_starts_with($rawPhoto, 'http') ? $rawPhoto : Storage::url($rawPhoto)) : asset('assets/images/default-child.png');

                $childSchool = $sub->school ?? $childObj->school;

                // استخراج أحداث أوقات الركوب والنزول
                $pickupEvent = DB::table('trip_events')
                    ->where('trip_id', $trip->id)
                    ->where('child_id', $childObj->id)
                    ->whereIn('action_type', ['picked_up', 'boarded'])
                    ->latest('scanned_at')
                    ->first();

                $dropoffEvent = DB::table('trip_events')
                    ->where('trip_id', $trip->id)
                    ->where('child_id', $childObj->id)
                    ->whereIn('action_type', ['dropped_off', 'dropped_off_school', 'delivered_home'])
                    ->latest('scanned_at')
                    ->first();

                $pickupTime = $pickupEvent 
                    ? Carbon::parse($pickupEvent->scanned_at)->format('h:i A') 
                    : ($sub->pickup_time ? Carbon::parse($sub->pickup_time)->format('h:i A') : null);

                $dropoffTime = $dropoffEvent 
                    ? Carbon::parse($dropoffEvent->scanned_at)->format('h:i A') 
                    : null;

                // بيانات منزل وسكن الطفل
                $childAddress = $childObj->address;
                $homeLat = $childAddress?->lat ?? $sub->pickup_lat;
                $homeLng = $childAddress?->lng ?? $sub->pickup_lng;
                $homeAddressData = [
                    'title'  => $childAddress?->label ?? $sub->pickup_label ?? null,
                    'street' => $childAddress?->label ?? $sub->pickup_label ?? null,
                    'lat'    => $homeLat !== null ? (float)$homeLat : null,
                    'lng'    => $homeLng !== null ? (float)$homeLng : null,
                ];

                // بيانات مدرسة الطفل
                $schoolLat = $childSchool?->lat ?? $sub->dropoff_lat;
                $schoolLng = $childSchool?->lng ?? $sub->dropoff_lng;
                $schoolData = [
                    'id'      => $childSchool?->id,
                    'name'    => $childSchool?->name ?? null,
                    'branch'  => $childSchool?->branch ?? null,
                    'address' => $childSchool?->address ?? null,
                    'lat'     => $schoolLat !== null ? (float)$schoolLat : null,
                    'lng'     => $schoolLng !== null ? (float)$schoolLng : null,
                ];

                $childrenArray[] = [
                    'child_id'     => $childObj->id,
                    'child_name'   => $childObj->full_name ?? $childObj->name,
                    'child_photo'  => $photoUrl,
                    'child_status' => $mappedChildStatus,
                    'pickup_time'  => $pickupTime,
                    'dropoff_time' => $dropoffTime,
                    'home_address' => $homeAddressData,
                    'school'       => $schoolData,
                ];
            }

            $driver = $trip->driver;
            $driverUser = $driver?->user;
            $vehicle = optional($driver?->vehicles)->first();

            $driverAvatar = optional($driverUser)->avatar_url ?? optional($driverUser)->photo_url;
            $driverPhotoUrl = $driverAvatar ? (str_starts_with($driverAvatar, 'http') ? $driverAvatar : Storage::url($driverAvatar)) : asset('assets/images/default-driver.png');

            $vehicleInfo = $vehicle
                ? trim("{$vehicle->brand} {$vehicle->model} {$vehicle->year}" . ($vehicle->color ? " - {$vehicle->color}" : ''))
                : null;

            $result[] = [
                'trip_id'       => $trip->id,
                'trip_type'     => strtolower($trip->trip_type ?? 'morning'),
                'direction'     => $direction,
                'status'        => strtolower($trip->status ?? 'in_progress'),
                'started_at'    => $trip->actual_start_time ? Carbon::parse($trip->actual_start_time)->format('h:i A') : null,
                'driver'        => [
                    'id'    => $driver?->id,
                    'name'  => $driverUser?->full_name ?? $driverUser?->name ?? null,
                    'phone' => $driverUser?->phone_number ?? $driverUser?->phone ?? null,
                    'photo' => $driverPhotoUrl,
                ],
                'vehicle' => [
                    'info'         => $vehicleInfo,
                    'plate_number' => $vehicle?->plate_number ?? null,
                    'capacity'     => $vehicle?->capacity_manual ?? $vehicle?->capacity ?? null,
                ],
                'bus_occupancy' => [
                    'current_onboard_count' => $currentOnboardCount,
                    'total_trip_children'   => $totalTripChildren,
                ],
                'children' => $childrenArray,
            ];
        }

        return $result;
    }

    /**
     * 2. GET /api/parent/trips/{tripId}/track
     * التتبع اللحظي للرحلة بناءً على موقع السائق
     */
    /**
     * 🛡️ حارس الملكية: يتأكد أن لهذا الولي طفلاً فعلياً في هذه الرحلة قبل كشف أي بيانات.
     *
     * ⚠️ بدون هذا الحارس كانت نقاط النهاية تكتفي بـ findOrFail على رقم الرحلة، فيستطيع
     * أي ولي أمر مسجّل قراءة رحلة عائلة أخرى: اسم السائق ورقمه ولوحة المركبة والموقع
     * الحي للحافلة، بل والأسماء الكاملة لأطفال آخرين عبر الـ timeline.
     *
     * @return array{trip: Trip, child_ids: array}
     */
    private function authorizeParentTripAccess(int $userId, int $tripId): array
    {
        $trip = Trip::with(['driver.user', 'driver.vehicles'])->findOrFail($tripId);

        $parentIds = $this->resolveParentIds($userId);
        $childIds  = DB::table('children')->whereIn('parent_id', $parentIds)->pluck('id')->toArray();

        if (empty($childIds)) {
            throw new \Exception('غير مصرح لك بالاطلاع على هذه الرحلة.');
        }

        // الطفل مرتبط بالرحلة إما عبر محطاتها الفعلية، أو عبر اشتراك نشط على نفس مسارها
        $hasStop = DB::table('trip_stops')
            ->where('trip_id', $trip->id)
            ->whereIn('child_id', $childIds)
            ->exists();

        $hasSubscription = ActiveSubscription::forChildren($childIds)
            ->forDriver($trip->driver_id)
            ->when($trip->route_id, fn($q) => $q->where('route_id', $trip->route_id))
            ->where('status', '!=', 'cancelled')
            ->exists();

        if (!$hasStop && !$hasSubscription) {
            throw new \Exception('غير مصرح لك بالاطلاع على هذه الرحلة.');
        }

        return ['trip' => $trip, 'child_ids' => $childIds];
    }

    public function getLiveTracking(int $userId, int $tripId): array
    {
        ['trip' => $trip] = $this->authorizeParentTripAccess($userId, $tripId);

        $cacheKey = "driver_last_loc_{$trip->driver_id}";
        $cachedLoc = Cache::get($cacheKey);

        $driverLat = $cachedLoc['lat'] ?? $trip->driver->current_lat ?? null;
        $driverLng = $cachedLoc['lng'] ?? $trip->driver->current_lng ?? null;
        $lastUpdated = $cachedLoc['updated_at'] ?? null;

        $isOnline = false;
        if (isset($cachedLoc['timestamp']) && (time() - $cachedLoc['timestamp'] <= 300)) {
            $isOnline = true;
        }

        $firstSub = ActiveSubscription::forDriver($trip->driver_id)->where('status', 'active')->with('school')->first();
        $school = optional($firstSub?->school);
        $direction = strtolower($trip->trip_type) === 'afternoon' ? 'to_home' : 'to_school';

        $destLat = $direction === 'to_school' ? ($school->lat ?? $firstSub->dropoff_lat ?? null) : ($firstSub->pickup_lat ?? null);
        $destLng = $direction === 'to_school' ? ($school->lng ?? $firstSub->dropoff_lng ?? null) : ($firstSub->pickup_lng ?? null);

        // ⚠️ كان الاسم يُرسل null دائماً رغم توفره، فيظهر للتطبيق مربّع وجهة بلا عنوان
        $destName = $direction === 'to_school'
            ? ($school->name ?? $firstSub?->dropoff_label ?? 'المدرسة')
            : ($firstSub?->pickup_label ?? 'المنزل');

        $parentIds = $this->resolveParentIds($userId);
        $childIds = DB::table('children')->whereIn('parent_id', $parentIds)->pluck('id')->toArray();

        $childrenArray = [];
        if (!empty($childIds)) {
            $subscriptions = ActiveSubscription::forChildren($childIds)
                ->forDriver($trip->driver_id)
                ->where('status', 'active')
                ->with(['child.address', 'child.school', 'school'])
                ->get();

            foreach ($subscriptions as $sub) {
                $childrenArray[] = $this->buildChildLocations($sub);
            }
        }

        return [
            'trip_id' => $trip->id,
            'status'  => $trip->status,
            'driver_location' => [
                'lat' => $driverLat !== null ? (float)$driverLat : null,
                'lng' => $driverLng !== null ? (float)$driverLng : null,
            ],
            'destination' => [
                'name' => $destName,
                'type' => $direction === 'to_school' ? 'school' : 'home',
                'lat'  => $destLat !== null ? (float)$destLat : null,
                'lng'  => $destLng !== null ? (float)$destLng : null,
            ],
            'last_updated' => $lastUpdated,
            'is_online'    => $isOnline,
            'children'     => $childrenArray,
        ];
    }

    /**
     * يبني بيانات المنزل والمدرسة الحقيقية لطفل واحد من اشتراكه الفعّال
     */
    private function buildChildLocations(ActiveSubscription $sub): array
    {
        $childObj = $sub->child;

        $childAddress = $childObj?->address;
        $homeLat = $childAddress?->lat ?? $sub->pickup_lat;
        $homeLng = $childAddress?->lng ?? $sub->pickup_lng;

        $childSchool = $childObj?->school ?? $sub->school;
        $schoolLat = $childSchool?->lat ?? $sub->dropoff_lat;
        $schoolLng = $childSchool?->lng ?? $sub->dropoff_lng;

        return [
            'child_id'   => $sub->child_id,
            'child_name' => $childObj?->full_name ?? $childObj?->name ?? null,
            'home' => [
                'title'   => $childAddress?->label ?? $sub->pickup_label ?? null,
                'address' => $childAddress?->label ?? $sub->pickup_label ?? null,
                'lat'     => $homeLat !== null ? (float)$homeLat : null,
                'lng'     => $homeLng !== null ? (float)$homeLng : null,
            ],
            'school' => [
                'id'      => $childSchool?->id,
                'name'    => $childSchool?->name ?? null,
                'address' => $childSchool?->address ?? null,
                'lat'     => $schoolLat !== null ? (float)$schoolLat : null,
                'lng'     => $schoolLng !== null ? (float)$schoolLng : null,
            ],
        ];
    }

    /**
     * 3. GET /api/parent/trips/upcoming
     * عرض الرحلات القادمة المجمعة على مستوى الرحلة مع دعم ?date=YYYY-MM-DD
     */
    public function getUpcomingTrips(int $userId, ?string $date = null): array
    {
        $today = $this->resolveRequestedDate($date);
        $parentIds = $this->resolveParentIds($userId);

        $subscriptions = ActiveSubscription::whereHas('requestChild.child', function ($q) use ($parentIds) {
            $q->whereIn('parent_id', $parentIds);
        })
        ->where('status', 'active')
        ->with([
            'child.address',
            'child.school',
            'driver.user',
            'school',
            'subscriptionRequest.children'
        ])
        ->get();

        $childIds = $subscriptions->pluck('child_id')->unique()->toArray();

        $upcoming = [];

        $subsByDriver = $subscriptions->groupBy('driver_id');

        foreach ($subsByDriver as $driverId => $subs) {
            $isDriverAbsentToday = \App\Models\Driver\DriverAbsence::where('driver_id', $driverId)
                ->whereDate('absence_date', $today)
                ->exists();

            if ($isDriverAbsentToday) {
                continue;
            }

            $driver = $subs->first()?->driver;
            $driverUser = $driver?->user;
            $driverName = $driverUser?->full_name ?? $driverUser?->name ?? null;

            $completedToday = Trip::where('driver_id', $driverId)
                ->whereDate('trip_date', $today)
                ->where('status', 'completed')
                ->pluck('trip_type')
                ->map(fn($type) => strtolower($type))
                ->toArray();

            foreach (['morning' => 'رحلة الذهاب للمدرسة', 'afternoon' => 'رحلة العودة من المدرسة'] as $shift => $title) {
                if (in_array($shift, $completedToday)) {
                    continue;
                }

                $route = Route::where('driver_id', $driverId)
                    ->whereRaw('LOWER(route_type) = ?', [$shift])
                    ->where('status', 'Active')
                    ->first();

                if (!$route) {
                    // لا يوجد مسار حقيقي مفعل لهذا السائق/الفترة — لا نصطنع رحلة وهمية
                    continue;
                }

                // idempotent: يعيد الرحلة إن كانت مولّدة مسبقاً اليوم، أو ينشئها الآن من المسار الحقيقي
                $trip = $this->tripGenerationService->generateForRoute($route, Carbon::parse($today));
                if (!$trip) {
                    continue;
                }

                $stops = TripStop::where('trip_id', $trip->id)
                    ->whereIn('child_id', $childIds)
                    ->get();

                $homeStopsByChild = $stops->where('stop_type', TripStop::TYPE_HOME)->keyBy('child_id');
                $schoolStopsBySchool = $stops->where('stop_type', TripStop::TYPE_SCHOOL)->keyBy('school_id');

                $childrenArr = [];
                $totalCost = 0.0;

                foreach ($subs as $s) {
                    $c = $s->child;
                    if (!$c) continue;

                    $homeStop = $homeStopsByChild->get($c->id);
                    if (!$homeStop) {
                        // الطفل غير مدرج فعلياً في محطات هذه الرحلة — لا يظهر في الرحلة القادمة
                        continue;
                    }

                    // ⚠️ محطة الغياب المسبق تبقى موجودة بحالة absent_pre وترتيب 0، فكان الطفل
                    // الغائب يظهر لولي أمره ضمن "الرحلات القادمة" رغم استبعاده فعلياً من الرحلة.
                    if (in_array($homeStop->status, [TripStop::STATUS_ABSENT_PRE, TripStop::STATUS_ABSENT_LATE], true)) {
                        continue;
                    }

                    $childPivot = $s->subscriptionRequest?->children?->firstWhere('id', $c->id)?->pivot;

                    // سعر الرحلة الواحدة — بنفس ترتيب الاشتقاق المستخدم في السجل تماماً،
                    // وإلا رأى ولي الأمر سعرين مختلفين لنفس الرحلة في شاشتين.
                    $costPerChildNum = $this->resolvePerTripCost($childPivot, $s->subscriptionRequest);
                    $hasCost = $costPerChildNum > 0;
                    if ($hasCost) {
                        $totalCost += $costPerChildNum;
                    }

                    $rawPhoto = $c->photo_url ?? null;
                    $photoUrl = $rawPhoto ? (str_starts_with($rawPhoto, 'http') ? $rawPhoto : Storage::url($rawPhoto)) : asset('assets/images/default-child.png');

                    $homeLocation = [
                        'title' => $homeStop->label,
                        'lat'   => $homeStop->lat,
                        'lng'   => $homeStop->lng,
                    ];

                    $childSchool = $s->school ?? $c->school;
                    $schoolStop = $childSchool ? $schoolStopsBySchool->get($childSchool->id) : null;
                    $schoolLocation = [
                        'id'      => $childSchool?->id,
                        'name'    => $childSchool?->name ?? null,
                        'address' => $childSchool?->address ?? null,
                        'lat'     => $schoolStop?->lat ?? $childSchool?->lat,
                        'lng'     => $schoolStop?->lng ?? $childSchool?->lng,
                    ];

                    $childrenArr[] = [
                        'child_id'        => (int)$c->id,
                        'child_name'      => $c->full_name ?? $c->name,
                        'school_name'     => $schoolLocation['name'],
                        'child_photo'     => $photoUrl,
                        'cost_per_child'  => $hasCost ? number_format($costPerChildNum, 2, '.', '') : null,
                        'home_location'   => $homeLocation,
                        'school_location' => $schoolLocation,
                    ];
                }

                if (!empty($childrenArr)) {
                    $childrenWithCost = array_filter($childrenArr, fn($ch) => $ch['cost_per_child'] !== null);
                    $costPerChildFormatted = !empty($childrenWithCost)
                        ? number_format($totalCost / count($childrenWithCost), 2, '.', '')
                        : null;

                    $upcoming[] = [
                        'trip_id'        => (int) $trip->id,
                        'trip_type'      => $shift,
                        'title'          => $title,
                        // ⚠️ scheduled_start_time يخزّن الوقت فقط، فكان Carbon يفسّره على أنه
                        // "اليوم" — فتظهر رحلة الغد لولي الأمر بتاريخ اليوم.
                        'scheduled_for'  => $this->resolveTripScheduledFor($trip),
                        'total_children' => count($childrenArr),
                        'driver'         => [
                            'name' => $driverName,
                        ],
                        'children'       => $childrenArr,
                        'pricing'        => [
                            'total_trip_cost' => !empty($childrenWithCost) ? number_format($totalCost, 2, '.', '') : null,
                            'cost_per_child'  => $costPerChildFormatted,
                            'currency'        => 'LYD',
                        ],
                    ];
                }
            }
        }

        return $upcoming;
    }

    /**
     * 4. GET /api/parent/trips/history
     * أرشيف كل الرحلات السابقة مجمعة على مستوى الرحلة الواحدة مع دعم فلترة اختيارية ?date=YYYY-MM-DD
     */
    public function getTripHistory(int $userId, int $perPage = 15, ?string $date = null): array
    {
        $parentIds = $this->resolveParentIds($userId);
        $childIds = DB::table('children')->whereIn('parent_id', $parentIds)->pluck('id')->toArray();

        if (empty($childIds)) {
            return [
                'current_page' => 1,
                'per_page'     => $perPage,
                'total'        => 0,
                'data'         => [],
            ];
        }

        $driverIds = ActiveSubscription::forChildren($childIds)->with('subscriptionRequest')->get()->pluck('driver_id')->filter()->unique()->toArray();

        $paginatedTrips = Trip::where(function ($query) use ($childIds, $driverIds) {
                $query->whereHas('events', function ($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds);
                })
                ->orWhereHas('stops', function ($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds);
                });

                if (!empty($driverIds)) {
                    $query->orWhere(function ($q2) use ($driverIds, $childIds) {
                        $q2->whereIn('driver_id', $driverIds)
                           ->whereHas('activeSubscriptions', function ($q3) use ($childIds) {
                               $q3->forChildren($childIds);
                           });
                    });
                }
            })
            ->when($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), function ($q) use ($date) {
                $q->whereDate('trip_date', $date);
            })
            ->with(['driver.user', 'driver.vehicles'])
            ->orderBy('trip_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $transformedTrips = [];

        foreach ($paginatedTrips->items() as $trip) {
            $eventChildIds = DB::table('trip_events')
                ->where('trip_id', $trip->id)
                ->whereIn('child_id', $childIds)
                ->pluck('child_id')
                ->toArray();

            $stopChildIds = DB::table('trip_stops')
                ->where('trip_id', $trip->id)
                ->whereIn('child_id', $childIds)
                ->pluck('child_id')
                ->toArray();

            $subChildIds = ActiveSubscription::forChildren($childIds)
                ->forDriver($trip->driver_id)
                ->where(function($sq) use ($trip) {
                    if ($trip->route_id) {
                        $sq->where('route_id', $trip->route_id)->orWhereNull('route_id');
                    }
                })
                ->with('requestChild')
                ->get()
                ->pluck('child_id')
                ->toArray();

            $tripChildIds = array_values(array_unique(array_merge($eventChildIds, $stopChildIds, $subChildIds)));
            $tripChildIds = array_values(array_intersect($tripChildIds, $childIds));

            if (empty($tripChildIds)) {
                continue;
            }

            $childrenModels = Child::whereIn('id', $tripChildIds)->with(['address', 'school'])->get()->keyBy('id');
            $subsModels = ActiveSubscription::forChildren($tripChildIds)
                ->forDriver($trip->driver_id)
                ->with(['school', 'subscriptionRequest.children', 'requestChild'])
                ->get()
                ->keyBy('child_id');

            $events = DB::table('trip_events')
                ->where('trip_id', $trip->id)
                ->whereIn('child_id', $tripChildIds)
                ->orderBy('scanned_at', 'asc')
                ->get();

            $stops = DB::table('trip_stops')
                ->where('trip_id', $trip->id)
                ->whereIn('child_id', $tripChildIds)
                ->get();

            $childrenArr = [];
            $totalTripCostNum = 0.0;
            $childrenWithCostCount = 0;
            $latestScannedAt = $events->whereNotNull('scanned_at')->max('scanned_at');
            $primaryActionType = 'picked_up';

            foreach ($tripChildIds as $cId) {
                $childObj = $childrenModels->get($cId);
                if (!$childObj) continue;

                $activeSub = $subsModels->get($cId);
                $childEvents = $events->where('child_id', $cId);
                $childStops = $stops->where('child_id', $cId);

                // حساب سعر الرحلة للطفل الواحد — null إن لم توجد بيانات تسعير حقيقية
                $costPerChildNum = 0.0;
                $eventWithCost = $childEvents->first(fn($ev) => (float)($ev->trip_cost ?? 0) > 0);
                if ($eventWithCost) {
                    $costPerChildNum = (float)$eventWithCost->trip_cost;
                }

                if ($costPerChildNum <= 0 && $activeSub?->subscriptionRequest) {
                    $childPivot = $activeSub->subscriptionRequest->children->firstWhere('id', $cId)?->pivot;
                    $costPerChildNum = $this->resolvePerTripCost($childPivot, $activeSub->subscriptionRequest);
                }

                $hasCost = $costPerChildNum > 0;
                if ($hasCost) {
                    $totalTripCostNum += $costPerChildNum;
                    $childrenWithCostCount++;
                }

                $rawPhoto = $childObj->photo_url ?? null;
                $photoUrl = $rawPhoto ? (str_starts_with($rawPhoto, 'http') ? $rawPhoto : Storage::url($rawPhoto)) : asset('assets/images/default-child.png');

                // موقع المنزل
                $childAddress = $childObj->address;
                $homeLat = $childAddress?->lat ?? $activeSub?->pickup_lat;
                $homeLng = $childAddress?->lng ?? $activeSub?->pickup_lng;
                $homeLocation = [
                    'title'   => $childAddress?->label ?? $activeSub?->pickup_label ?? null,
                    'address' => $childAddress?->label ?? $activeSub?->pickup_label ?? null,
                    'lat'     => $homeLat !== null ? (float)$homeLat : null,
                    'lng'     => $homeLng !== null ? (float)$homeLng : null,
                ];

                // موقع المدرسة
                $childSchool = $childObj->school ?? $activeSub?->school;
                $schoolLat = $childSchool?->lat ?? $activeSub?->dropoff_lat;
                $schoolLng = $childSchool?->lng ?? $activeSub?->dropoff_lng;
                $schoolLocation = [
                    'id'      => $childSchool?->id,
                    'name'    => $childSchool?->name ?? null,
                    'address' => $childSchool?->address ?? null,
                    'lat'     => $schoolLat !== null ? (float)$schoolLat : null,
                    'lng'     => $schoolLng !== null ? (float)$schoolLng : null,
                ];

                // أوقات وتفاصيل الصعود والنزول
                $pickupEvent = $childEvents->first(fn($ev) => in_array($ev->action_type, ['picked_up', 'boarded']));
                $dropoffEvent = $childEvents->first(fn($ev) => in_array($ev->action_type, ['dropped_off', 'dropped_off_school', 'delivered_home']));

                $pickupTime = $pickupEvent 
                    ? Carbon::parse($pickupEvent->scanned_at)->format('h:i A') 
                    : ($activeSub?->pickup_time ? Carbon::parse($activeSub->pickup_time)->format('h:i A') : null);

                $dropoffTime = $dropoffEvent 
                    ? Carbon::parse($dropoffEvent->scanned_at)->format('h:i A') 
                    : ($activeSub?->dropoff_time ? Carbon::parse($activeSub->dropoff_time)->format('h:i A') : null);

                $stop = $childStops->firstWhere('stop_type', 'home') ?? $childStops->first();
                $rawStatus = $stop?->status ?? ($dropoffEvent ? 'dropped_off' : ($pickupEvent ? 'picked_up' : 'completed'));

                $childStatus = match ($rawStatus) {
                    'boarded', 'picked_up', 'onboard'                      => 'boarded',
                    'dropped_off', 'dropped_off_school', 'delivered_home' => 'dropped_off',
                    'absent', 'absent_pre', 'absent_late'                  => 'absent',
                    default                                                => $rawStatus ?? 'completed',
                };

                if ($dropoffEvent) {
                    $primaryActionType = 'dropped_off';
                } elseif ($pickupEvent && $primaryActionType !== 'dropped_off') {
                    $primaryActionType = 'picked_up';
                }

                $childrenArr[] = [
                    'child_id'        => (int)$childObj->id,
                    'child_name'      => $childObj->full_name ?? $childObj->name,
                    'child_photo'     => $photoUrl,
                    'school_name'     => $schoolLocation['name'],
                    'trip_cost'       => $hasCost ? number_format($costPerChildNum, 2, '.', '') : null,
                    'cost_per_child'  => $hasCost ? number_format($costPerChildNum, 2, '.', '') : null,
                    'status'          => $childStatus,
                    'pickup_time'     => $pickupTime,
                    'dropoff_time'    => $dropoffTime,
                    'home_location'   => $homeLocation,
                    'school_location' => $schoolLocation,
                ];
            }

            if (empty($childrenArr)) {
                continue;
            }

            $driver = $trip->driver;
            $driverUser = $driver?->user;
            $driverAvatar = optional($driverUser)->avatar_url ?? optional($driverUser)->photo_url;
            $driverPhotoUrl = $driverAvatar ? (str_starts_with($driverAvatar, 'http') ? $driverAvatar : Storage::url($driverAvatar)) : asset('assets/images/default-driver.png');

            $driverData = [
                'id'    => $driver?->id,
                'name'  => $driverUser?->full_name ?? $driverUser?->name ?? null,
                'phone' => $driverUser?->phone_number ?? $driverUser?->phone ?? null,
                'photo' => $driverPhotoUrl,
            ];

            $scannedAtFormatted = $latestScannedAt
                ? Carbon::parse($latestScannedAt)->format('Y-m-d H:i:s')
                : ($trip->actual_start_time
                    ? Carbon::parse($trip->actual_start_time)->format('Y-m-d H:i:s')
                    : null);

            $transformedTrips[] = [
                'trip_id'        => (int)$trip->id,
                'trip_type'      => $trip->trip_type ?? 'Morning',
                'trip_date'      => $trip->trip_date ? Carbon::parse($trip->trip_date)->format('Y-m-d') : null,
                'status'         => strtolower($trip->status ?? 'completed'),
                'driver'         => $driverData,
                'total_children' => count($childrenArr),
                'action_type'    => $primaryActionType,
                'scanned_at'     => $scannedAtFormatted,
                'children'       => $childrenArr,
                'pricing'        => [
                    'total_trip_cost' => $childrenWithCostCount > 0 ? number_format($totalTripCostNum, 2, '.', '') : null,
                    'cost_per_child'  => $childrenWithCostCount > 0 ? number_format($totalTripCostNum / $childrenWithCostCount, 2, '.', '') : null,
                    'currency'        => 'LYD',
                ],
            ];
        }

        return [
            'current_page' => $paginatedTrips->currentPage(),
            'per_page'     => $paginatedTrips->perPage(),
            'total'        => $paginatedTrips->total(),
            'data'         => $transformedTrips,
        ];
    }

    /**
     * 5. GET /api/parent/trips/{tripId}
     * تفاصيل رحلة معينة بكل بيانات السائق والمركبة والأبناء
     */
    public function getTripDetails(int $userId, int $tripId): array
    {
        ['trip' => $trip, 'child_ids' => $childIds] = $this->authorizeParentTripAccess($userId, $tripId);

        $subscriptions = ActiveSubscription::forChildren($childIds)
            ->forDriver($trip->driver_id)
            ->with(['child.school', 'child.address', 'school'])
            ->get()
            ->unique('child_id');

        $direction = strtolower($trip->trip_type) === 'afternoon' ? 'to_home' : 'to_school';

        $childrenArray = [];
        $destinationsByKey = [];
        foreach ($subscriptions as $sub) {
            $c = $sub->child;
            if (!$c) continue;

            $stop = DB::table('trip_stops')
                ->where('trip_id', $trip->id)
                ->where('child_id', $c->id)
                ->first();

            $event = DB::table('trip_events')
                ->where('trip_id', $trip->id)
                ->where('child_id', $c->id)
                ->latest('scanned_at')
                ->first();

            $childStatus = $stop?->status ?? ($event?->action_type ?? 'waiting');

            $rawPhoto = $c->photo_url ?? null;
            $photoUrl = $rawPhoto ? (str_starts_with($rawPhoto, 'http') ? $rawPhoto : Storage::url($rawPhoto)) : asset('assets/images/default-child.png');

            // بيانات مدرسة الطفل
            $childSchool = $c->school ?? $sub->school;
            $schoolLat = $childSchool?->lat ?? $sub->dropoff_lat;
            $schoolLng = $childSchool?->lng ?? $sub->dropoff_lng;
            $schoolLocation = [
                'id'      => $childSchool?->id,
                'name'    => $childSchool?->name ?? 'المدرسة',
                'address' => $childSchool?->address ?? null,
                'lat'     => $schoolLat !== null ? (float)$schoolLat : 32.890000,
                'lng'     => $schoolLng !== null ? (float)$schoolLng : 13.180000,
            ];

            // بيانات منزل وسكن الطفل
            $childAddress = $c->address;
            $homeLat = $childAddress?->lat ?? $sub->pickup_lat;
            $homeLng = $childAddress?->lng ?? $sub->pickup_lng;
            $homeLocation = [
                'title'   => $childAddress?->label ?? $sub->pickup_label ?? 'المنزل الرئيسي',
                'address' => $childAddress?->label ?? $sub->pickup_label ?? null,
                'lat'     => $homeLat !== null ? (float)$homeLat : 32.875210,
                'lng'     => $homeLng !== null ? (float)$homeLng : 13.165420,
            ];

            $destLat = $direction === 'to_school' ? $schoolLocation['lat'] : $homeLocation['lat'];
            $destLng = $direction === 'to_school' ? $schoolLocation['lng'] : $homeLocation['lng'];

            $childDestination = [
                'name' => $direction === 'to_school' ? $schoolLocation['name'] : $homeLocation['title'],
                'type' => $direction === 'to_school' ? 'school' : 'home',
                'lat'  => $destLat,
                'lng'  => $destLng,
            ];

            $childrenArray[] = [
                'child_id'        => $c->id,
                'child_name'      => $c->full_name ?? $c->name,
                'child_photo'     => $photoUrl,
                'child_status'    => $childStatus,
                'school_name'     => $schoolLocation['name'],
                'direction'       => $direction,
                'home_location'   => $homeLocation,
                'school_location' => $schoolLocation,
                'destination'     => $childDestination,
            ];

            // نجمع الوجهات الفريدة بدل الاكتفاء بوجهة أول طفل فقط، لأن أطفال نفس الولي
            // قد يكونوا في مدارس مختلفة على نفس خط السائق
            $destKey = ($childDestination['name'] ?? '') . '|'
                . ($childDestination['lat'] !== null ? round($childDestination['lat'], 6) : 'null') . '|'
                . ($childDestination['lng'] !== null ? round($childDestination['lng'], 6) : 'null');
            if (!isset($destinationsByKey[$destKey])) {
                $destinationsByKey[$destKey] = $childDestination;
            }
        }

        $driver = $trip->driver;
        $driverUser = $driver?->user;
        $vehicle = optional($driver?->vehicles)->first();
        $destinationsList = array_values($destinationsByKey);

        $driverAvatar = optional($driverUser)->avatar_url ?? optional($driverUser)->photo_url;
        $driverPhotoUrl = $driverAvatar ? (str_starts_with($driverAvatar, 'http') ? $driverAvatar : Storage::url($driverAvatar)) : asset('assets/images/default-driver.png');

        return [
            'trip_id'     => $trip->id,
            'trip_type'   => $trip->trip_type,
            'direction'   => $direction,
            'status'      => $trip->status,
            'driver'      => [
                'id'    => $driver?->id,
                'name'  => $driverUser?->full_name ?? $driverUser?->name,
                'phone' => $driverUser?->phone_number ?? $driverUser?->phone,
                'photo' => $driverPhotoUrl
            ],
            'vehicle' => [
                'info' => $vehicle ? "{$vehicle->brand} {$vehicle->model} ({$vehicle->plate_number})" : null
            ],
            'children'    => $childrenArray,
            // ⚠️ للتوافق مع النسخ القديمة من التطبيق فقط: أول وجهة من destinations أدناه.
            // لا تعتمد عليها عند وجود أكثر من مدرسة — استخدم destinations بدلاً منها.
            'destination'     => $destinationsList[0] ?? null,
            'destinations'    => $destinationsList,
            'is_multi_school' => count($destinationsList) > 1,
            'started_at'  => $trip->actual_start_time ? Carbon::parse($trip->actual_start_time)->toIso8601String() : null,
            'finished_at' => $trip->completed_at ? Carbon::parse($trip->completed_at)->toIso8601String() : null,
        ];
    }

    /**
     * 6. GET /api/parent/trips/{tripId}/timeline
     * الخط الزمني ومراحل الرحلة
     */
    public function getTripTimeline(int $userId, int $tripId): array
    {
        ['trip' => $trip, 'child_ids' => $ownChildIds] = $this->authorizeParentTripAccess($userId, $tripId);
        $timeline = [];

        if ($trip->started_at) {
            $timeline[] = [
                'status' => 'started',
                'title'  => 'بدأت الرحلة',
                'time'   => Carbon::parse($trip->started_at)->format('H:i')
            ];
        }

        // ⚠️ يُعرض فقط ما يخص أطفال هذا الولي؛ سابقاً كان الـ timeline يسرد الأسماء
        // الكاملة لكل أطفال الرحلة لأي ولي أمر يطلبه.
        $events = DB::table('trip_events')
            ->where('trip_id', $tripId)
            ->whereIn('child_id', $ownChildIds)
            ->orderBy('scanned_at', 'asc')
            ->get();

        foreach ($events as $e) {
            $childName = DB::table('children')->where('id', $e->child_id)->value('full_name') ?? 'الطفل';

            if ($e->action_type === 'picked_up') {
                $timeline[] = [
                    'status' => 'picked_up',
                    'title'  => "تم صعود الطفل {$childName}",
                    'time'   => Carbon::parse($e->scanned_at)->format('H:i')
                ];
            } elseif ($e->action_type === 'dropped_off') {
                $timeline[] = [
                    'status' => 'arrived_school',
                    'title'  => "وصل الطفل {$childName} للمدرسة",
                    'time'   => Carbon::parse($e->scanned_at)->format('H:i')
                ];
            }
        }

        if ($trip->status === 'completed' && $trip->completed_at) {
            $timeline[] = [
                'status' => 'completed',
                'title'  => 'اكتمال الرحلة بالكامل',
                'time'   => Carbon::parse($trip->completed_at)->format('H:i')
            ];
        }

        return $timeline;
    }

    /**
     * 7. GET /api/parent/children/{childId}/trips
     * نظرة شاملة لرحلات طفل معين (الرحلة الحالية، القادمة، السجل) مع دعم ?date=YYYY-MM-DD
     */
    public function getChildTripsOverview(int $userId, int $childId, ?string $date = null): array
    {
        $targetDate = $this->resolveRequestedDate($date);

        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) {
            throw new \Exception('بيانات الطفل غير موجودة.');
        }

        $subs = ActiveSubscription::forChild($childId)->where('status', 'active')->get();
        $sub = $subs->first(function ($s) use ($targetDate) {
            return !\App\Models\Driver\DriverAbsence::where('driver_id', $s->driver_id)
                ->whereDate('absence_date', $targetDate)
                ->exists();
        }) ?? $subs->first();

        $activeTrip = null;
        if ($sub) {
            $trip = Trip::where('driver_id', $sub->driver_id)
                ->where('status', 'in_progress')
                ->whereDate('trip_date', $targetDate)
                ->first();

            if ($trip) {
                $activeTrip = $this->getTripDetails($userId, $trip->id);
            }
        }

        $upcoming = $this->getUpcomingTrips($userId, $date);
        $history = $this->getTripHistory($userId, 15, $date);

        return [
            'child' => [
                'id'   => $child->id,
                'name' => $child->full_name ?? $child->name
            ],
            'active_trip'    => $activeTrip,
            'upcoming_trips' => $upcoming,
            'history'        => $history
        ];
    }

    /**
     * 8. GET /api/parent/trips/{tripId}/children/{childId}/status
     * حالة طفل معين داخل رحلة معينة بدقة
     */
    /**
     * 🛡️ يمنع ولي أمر من الاستعلام عن حالة/تقدّم طفل لا يتبع حسابه.
     */
    private function authorizeParentChildAccess(int $userId, int $childId): void
    {
        $parentIds = $this->resolveParentIds($userId);

        $owns = DB::table('children')
            ->where('id', $childId)
            ->whereIn('parent_id', $parentIds)
            ->exists();

        if (!$owns) {
            throw new \Exception('عذراً، هذا الطفل غير موجود أو لا يتبع لحسابك.');
        }
    }

    public function getChildTripStatus(int $userId, int $tripId, int $childId): array
    {
        $this->authorizeParentChildAccess($userId, $childId);

        $stop = DB::table('trip_stops')
            ->where('trip_id', $tripId)
            ->where('child_id', $childId)
            ->where('stop_type', 'home')
            ->first();

        if ($stop) {
            return [
                'child_id' => $childId,
                'status'   => $stop->status,
                'time'     => $stop->updated_at ? Carbon::parse($stop->updated_at)->format('H:i') : null,
            ];
        }

        // توافقية: رحلات قديمة بلا trip_stops بعد
        $event = DB::table('trip_events')
            ->where('trip_id', $tripId)
            ->where('child_id', $childId)
            ->latest('scanned_at')
            ->first();

        $isAbsent = DB::table('absence_logs')
            ->where('child_id', $childId)
            ->whereDate('absence_date', Carbon::today()->toDateString())
            ->exists();

        $status = 'waiting';
        $time   = null;

        if ($isAbsent) {
            $status = 'absent';
        } elseif ($event) {
            $status = $event->action_type;
            $time   = Carbon::parse($event->scanned_at)->format('H:i');
        }

        return [
            'child_id' => $childId,
            'status'   => $status,
            'time'     => $time
        ];
    }

    /**
     * 8.1. GET /api/parent/trips/{tripId}/children/{childId}/progress
     * خطوات التقدم والتتبع المخصصة للطفل في رحلة معينة
     */
    public function getChildTripProgress(int $userId, int $tripId, int $childId): array
    {
        $this->authorizeParentChildAccess($userId, $childId);

        $trip = Trip::findOrFail($tripId);

        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) {
            throw new \Exception('بيانات الطفل غير موجودة.');
        }

        $isAfternoon = strtolower($trip->trip_type) === 'afternoon';

        $pickupEvent = DB::table('trip_events')
            ->where('trip_id', $tripId)
            ->where('child_id', $childId)
            ->where('action_type', 'picked_up')
            ->first();

        $dropoffEvent = DB::table('trip_events')
            ->where('trip_id', $tripId)
            ->where('child_id', $childId)
            ->where('action_type', 'dropped_off')
            ->first();

        $isStarted = !empty($trip->actual_start_time) || !empty($trip->started_at) || in_array($trip->status, ['in_progress', 'completed']);
        $startTime = $trip->actual_start_time ? Carbon::parse($trip->actual_start_time)->format('Y-m-d H:i:s') : ($trip->started_at ? Carbon::parse($trip->started_at)->format('Y-m-d H:i:s') : null);

        $isPickedUp = !empty($pickupEvent) || !empty($dropoffEvent);
        $pickupTime = $pickupEvent ? Carbon::parse($pickupEvent->scanned_at)->format('Y-m-d H:i:s') : null;

        $isArrived = !empty($dropoffEvent);
        $dropoffTime = $dropoffEvent ? Carbon::parse($dropoffEvent->scanned_at)->format('Y-m-d H:i:s') : null;

        $isCompleted = $trip->status === 'completed' && !empty($trip->completed_at);
        $completedTime = $trip->completed_at ? Carbon::parse($trip->completed_at)->format('Y-m-d H:i:s') : null;

        $steps = [
            [
                'key'       => 'started',
                'title'     => 'انطلقت',
                'completed' => (bool)$isStarted,
                'timestamp' => $startTime
            ],
            [
                'key'       => 'picked_up',
                'title'     => 'في الطريق',
                'completed' => (bool)$isPickedUp,
                'timestamp' => $pickupTime
            ],
            [
                // ⚠️ كان العنوانان مقلوبين: خطوة الوصول تحمل "الاستلام" وخطوة انتهاء
                // الرحلة تحمل "وصلت للمدرسة"، فيقرأ ولي الأمر تسلسلاً غير منطقي.
                'key'       => $isAfternoon ? 'arrived_home' : 'arrived_school',
                'title'     => $isAfternoon ? 'وصل للمنزل' : 'وصل للمدرسة',
                'completed' => (bool)$isArrived,
                'timestamp' => $dropoffTime
            ],
            [
                'key'       => 'completed',
                'title'     => 'اكتملت الرحلة',
                'completed' => (bool)$isCompleted,
                'timestamp' => $completedTime
            ]
        ];

        $currentStep = 0;
        if ($isCompleted) {
            $currentStep = 4;
        } elseif ($isArrived) {
            $currentStep = 3;
        } elseif ($isPickedUp) {
            $currentStep = 2;
        } elseif ($isStarted) {
            $currentStep = 1;
        }

        return [
            'trip_id'      => (int)$tripId,
            'child_id'     => (int)$childId,
            'child_name'   => $child->full_name ?? $child->name ?? 'الطفل',
            'current_step' => $currentStep,
            'steps'        => $steps
        ];
    }

    /**
     * 9. GET /api/parent/trips/active/tracking
     * تتبع جغرافيا جميع الرحلات النشطة دفعة واحدة لولي الأمر مع دعم ?date=YYYY-MM-DD
     */
    public function getBulkActiveTracking(int $userId, ?string $date = null): array
    {
        $targetDate = $this->resolveRequestedDate($date);
        $parentIds = $this->resolveParentIds($userId);

        $childIds = DB::table('children')->whereIn('parent_id', $parentIds)->pluck('id')->toArray();
        if (empty($childIds)) {
            return [];
        }

        $subscriptions = ActiveSubscription::forChildren($childIds)
            ->where('status', 'active')
            ->with(['child.address', 'child.school', 'school'])
            ->get();

        $driverIds = $subscriptions->pluck('driver_id')->unique()->toArray();

        $activeTrips = Trip::whereIn('driver_id', $driverIds)
            ->where('status', 'in_progress')
            ->whereDate('trip_date', $targetDate)
            ->get();

        $result = [];

        foreach ($activeTrips as $trip) {
            $cacheKey = "driver_last_loc_{$trip->driver_id}";
            $cachedLoc = Cache::get($cacheKey);

            $driverLat = $cachedLoc['lat'] ?? $trip->driver->current_lat ?? null;
            $driverLng = $cachedLoc['lng'] ?? $trip->driver->current_lng ?? null;

            $tripSubs = $subscriptions->where('driver_id', $trip->driver_id);
            $firstSub = $tripSubs->first();
            $school = optional($firstSub?->school);
            $direction = strtolower($trip->trip_type) === 'afternoon' ? 'to_home' : 'to_school';

            $destLat = $direction === 'to_school' ? ($school->lat ?? null) : ($firstSub->pickup_lat ?? null);
            $destLng = $direction === 'to_school' ? ($school->lng ?? null) : ($firstSub->pickup_lng ?? null);

            $childrenArray = [];
            foreach ($tripSubs as $sub) {
                $childrenArray[] = $this->buildChildLocations($sub);
            }

            $result[] = [
                'trip_id' => $trip->id,
                'driver_location' => [
                    'lat' => $driverLat !== null ? (float)$driverLat : null,
                    'lng' => $driverLng !== null ? (float)$driverLng : null,
                ],
                'destination' => [
                    'lat' => $destLat !== null ? (float)$destLat : null,
                    'lng' => $destLng !== null ? (float)$destLng : null,
                ],
                'children' => $childrenArray,
            ];
        }

        return $result;
    }
}