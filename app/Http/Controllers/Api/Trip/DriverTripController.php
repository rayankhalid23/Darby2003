<?php

namespace App\Http\Controllers\Api\Trip;

use App\Http\Controllers\Controller;
use App\Models\Shared\Trip;
use App\Models\Shared\TripEvent;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\ActiveSubscription;
use App\Services\Trip\TripLifecycleService;
use App\Services\Trip\TripTrackingService;
use App\Services\Trip\TripStopService;
use App\Services\Trip\RouteRecommendationService;
use App\Services\Notification\NotificationService;
use App\Http\Requests\Api\Trip\DriverAbsenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use Throwable;

class DriverTripController extends Controller
{
    protected TripLifecycleService $lifecycleService;
    protected TripTrackingService $trackingService;
    protected TripStopService $stopService;
    protected RouteRecommendationService $recommendationService;
    protected \App\Services\Trip\DailyTripGenerationService $dailyTripGenerationService;
    protected \App\Services\Trip\GeofenceService $geofenceService;
    protected NotificationService $notificationService;
    protected \App\Services\Trip\EmergencyBreakdownService $emergencyBreakdownService;

    public function __construct(
        TripLifecycleService $lifecycleService,
        TripTrackingService $trackingService,
        TripStopService $stopService,
        RouteRecommendationService $recommendationService,
        \App\Services\Trip\DailyTripGenerationService $dailyTripGenerationService,
        \App\Services\Trip\GeofenceService $geofenceService,
        NotificationService $notificationService,
        \App\Services\Trip\EmergencyBreakdownService $emergencyBreakdownService
    ) {
        $this->lifecycleService = $lifecycleService;
        $this->trackingService = $trackingService;
        $this->stopService = $stopService;
        $this->recommendationService = $recommendationService;
        $this->dailyTripGenerationService = $dailyTripGenerationService;
        $this->geofenceService = $geofenceService;
        $this->notificationService = $notificationService;
        $this->emergencyBreakdownService = $emergencyBreakdownService;
    }

    /**
     * أقصى عدد أيام مستقبلية يُسمح بمعاينة (وتوليد) رحلاتها مسبقاً.
     */
    private const MAX_LOOKAHEAD_DAYS = 14;

    private function resolveRequestedDate(?string $requestedDate): string
    {
        $today = Carbon::now(config('app.timezone', 'Africa/Tripoli'))->startOfDay();

        if ($requestedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
            try {
                $parsed = Carbon::parse($requestedDate)->startOfDay();

                // ⚠️ هذه المعاملة تُنشئ رحلات فعلياً في قاعدة البيانات، لذا يجب ألا تقبل
                // تاريخاً ماضياً (تلفيق رحلات تاريخية) ولا تاريخاً بعيداً (تضخيم الجدول).
                if ($parsed->lt($today) || $parsed->gt($today->copy()->addDays(self::MAX_LOOKAHEAD_DAYS))) {
                    return $today->toDateString();
                }

                return $parsed->toDateString();
            } catch (\Throwable) {
                // fallback
            }
        }

        return $today->toDateString();
    }

    /**
     * 1️⃣ GET /api/driver/trips/today
     * جلب رحلات اليوم الخاصة بالسائق مع دعم تمرير ?date=YYYY-MM-DD
     */
    public function todayTrips(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            if (!$driver) {
                return response()->json(['status' => 'error', 'message' => 'بيانات السائق غير مقترنة بالحساب.'], 403);
            }

            $targetDateString = $this->resolveRequestedDate($request->query('date'));
            $targetDate = Carbon::parse($targetDateString);

            // جلب رحلات اليوم من جدول Trips أو إنشائها تلقائياً بناءً على المسارات النشطة
            $activeRoutes = RouteModel::where('driver_id', $driver->id)
                ->where('status', 'Active')
                ->get();

            $trips = [];

            foreach ($activeRoutes as $route) {
                $trip = $this->dailyTripGenerationService->generateForRoute($route, $targetDate);

                if (!$trip || strtolower($trip->status) === 'cancelled') {
                    continue;
                }

                $metrics = $this->recommendationService->calculateRouteMetrics($route);

                $formattedStatus = strtolower($trip->status);

                $tripFormattedDate = $trip->trip_date ? Carbon::parse($trip->trip_date)->format('Y-m-d') : $targetDateString;

                $trips[] = [
                    'trip_id'               => (int) $trip->id,
                    'route_id'              => (int) $route->id,
                    'route_name'            => $route->route_name,
                    'trip_type'             => $route->route_type,
                    'trip_date'             => $tripFormattedDate,
                    'date'                  => $tripFormattedDate,
                    'status'                => $formattedStatus,
                    'children_count'        => $metrics['children_count'],
                    'schools_count'         => $metrics['schools_count'],
                    'estimated_duration'   => $metrics['estimated_duration'],
                    'recommended_departure' => $metrics['recommended_departure'],
                    'started_at'            => $trip->actual_start_time ? Carbon::parse($trip->actual_start_time)->format('H:i') : null,
                ];
            }

            return response()->json([
                'status' => 'success',
                'date'   => $targetDateString,
                'data'   => $trips
            ], 200);

        } catch (Throwable $e) {
            Log::error("Error in todayTrips: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * يحوّل حالة trip_stops الدقيقة إلى: القيمة الخام + pickup_status/dropoff_status المبسّطة (توافقية مع الفرونت الحالي)
     */
    private function tripStopStatusFields(?\App\Models\Shared\TripStop $stop): array
    {
        $raw = $stop->status ?? \App\Models\Shared\TripStop::STATUS_PENDING;

        $boardedLike = [
            \App\Models\Shared\TripStop::STATUS_BOARDED,
            \App\Models\Shared\TripStop::STATUS_DROPPED_OFF_SCHOOL,
            \App\Models\Shared\TripStop::STATUS_DELIVERED_HOME,
            \App\Models\Shared\TripStop::STATUS_DROPOFF_FAILED,
            \App\Models\Shared\TripStop::STATUS_DIRECT_PARENT_HANDLING,
        ];
        $absentLike = [\App\Models\Shared\TripStop::STATUS_ABSENT_PRE, \App\Models\Shared\TripStop::STATUS_ABSENT_LATE];
        $deliveredLike = [\App\Models\Shared\TripStop::STATUS_DROPPED_OFF_SCHOOL, \App\Models\Shared\TripStop::STATUS_DELIVERED_HOME];

        $pickupStatus = 'pending';
        if (in_array($raw, $boardedLike, true)) $pickupStatus = 'picked_up';
        elseif (in_array($raw, $absentLike, true)) $pickupStatus = 'absent';
        elseif ($raw === \App\Models\Shared\TripStop::STATUS_SKIPPED_UNRESPONSIVE) $pickupStatus = 'skipped';

        return [
            'status'         => $raw,
            'pickup_status'  => $pickupStatus,
            'dropoff_status' => in_array($raw, $deliveredLike, true) ? 'dropped_off' : 'pending',
            'eta'            => $stop?->eta ? substr((string) $stop->eta, 0, 5) : null,
            'sequence_order' => $stop?->sequence_order !== null ? (int) $stop->sequence_order : null,
        ];
    }

    /**
     * توافقية: يُستخدم فقط للرحلات القديمة التي لا تملك trip_stops بعد (قبل تفعيل المسار الرئيسي الجديد)
     */
    private function legacyStatusFromEvents(int $childId, $events): array
    {
        $pickupEvent = $events->where('child_id', $childId)->whereIn('action_type', ['picked_up', 'skipped', 'absent'])->first();
        $dropoffEvent = $events->where('child_id', $childId)->where('action_type', 'dropped_off')->first();

        $pickupStatus = 'pending';
        if ($pickupEvent) {
            if ($pickupEvent->action_type === 'picked_up') $pickupStatus = 'picked_up';
            elseif ($pickupEvent->action_type === 'skipped') $pickupStatus = 'skipped';
            elseif ($pickupEvent->action_type === 'absent') $pickupStatus = 'absent';
        }
        $dropoffStatus = $dropoffEvent ? 'dropped_off' : 'pending';

        return [
            'status'         => $dropoffStatus === 'dropped_off' ? 'delivered_home' : $pickupStatus,
            'pickup_status'  => $pickupStatus,
            'dropoff_status' => $dropoffStatus,
            'eta'            => null,
            'sequence_order' => null,
        ];
    }

    /**
     * 2️⃣ GET /api/driver/trips/{tripId}
     * تفاصيل الرحلة
     */
    public function show($tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $trip = Trip::where('id', $tripId)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            $route = RouteModel::where('id', $trip->route_id)->first();
            $subs = ActiveSubscription::forDriver($driver->id)
                ->where(function ($q) use ($trip, $route) {
                    $q->where('route_id', $trip->route_id);
                    if ($route?->contract_id) {
                        $q->orWhere('contract_id', $route->contract_id);
                    }
                })
                ->where('status', '!=', 'cancelled')
                ->with(['child.school', 'child.address', 'school'])
                ->get();

            if ($subs->isEmpty()) {
                $childIdsFromStopsOrEvents = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                    ->pluck('child_id')
                    ->merge(TripEvent::where('trip_id', $trip->id)->pluck('child_id'))
                    ->filter()
                    ->unique();

                if ($childIdsFromStopsOrEvents->isNotEmpty()) {
                    $subs = ActiveSubscription::forChildren($childIdsFromStopsOrEvents->all())
                        ->with(['child.school', 'child.address', 'school'])
                        ->get();
                }

                if ($subs->isEmpty()) {
                    $subs = ActiveSubscription::forDriver($driver->id)
                        ->where('status', '!=', 'cancelled')
                        ->with(['child.school', 'child.address', 'school'])
                        ->get();
                }
            }

            // مزامنة أية محطات ناقصة تلقائياً للرحلة
            $existingStopsCount = \App\Models\Shared\TripStop::where('trip_id', $trip->id)->count();
            if ($existingStopsCount === 0 && $subs->isNotEmpty()) {
                $seq = 1;
                foreach ($subs as $subItem) {
                    if ($subItem->child_id) {
                        \App\Models\Shared\TripStop::create([
                            'trip_id'        => $trip->id,
                            'stop_type'      => 'home',
                            'child_id'       => $subItem->child_id,
                            'school_id'      => $subItem->school_id,
                            'lat'            => $subItem->child?->latitude ?? $driver->latitude ?? 32.8872,
                            'lng'            => $subItem->child?->longitude ?? $driver->longitude ?? 13.1913,
                            'label'          => $subItem->pickup_label ?? 'الحي السكني',
                            'sequence_order' => $seq++,
                            'status'         => 'pending',
                        ]);
                    }
                }
            }

            $schoolsGrouped = $subs->groupBy(function ($subItem) {
                return $subItem->school_id ?? $subItem->child?->school_id ?? 0;
            })->map(function ($group) {
                $first = $group->first();
                $childSchool = $first->school ?? $first->child?->school ?? \App\Models\Parent\School::find($first->school_id ?? $first->child?->school_id);
                $sName = $childSchool?->name ?? 'المدرسة';
                $sId = $childSchool?->id ?? (int)$first->school_id;
                $schoolLat = $childSchool?->lat ?? $first->dropoff_lat ?? 32.890000;
                $schoolLng = $childSchool?->lng ?? $first->dropoff_lng ?? 13.180000;

                return [
                    'school_id'      => (int) $sId,
                    'name'           => $sName,
                    'latitude'       => (float) $schoolLat,
                    'longitude'      => (float) $schoolLng,
                    'lat'            => (float) $schoolLat,
                    'lng'            => (float) $schoolLng,
                    'address'        => $childSchool?->address ?? null,
                    'children_count' => $group->unique('child_id')->count(),
                ];
            })->values();

            $events = TripEvent::where('trip_id', $trip->id)->get();

            $stopsByChild = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->where('stop_type', 'home')
                ->get()
                ->keyBy('child_id');

            $children = $subs->unique('child_id')->map(function ($sub) use ($events, $stopsByChild, $driver) {
                $childObj = $sub->child;
                $stop = $stopsByChild->get($sub->child_id);
                $statusFields = $stop
                    ? $this->tripStopStatusFields($stop)
                    : $this->legacyStatusFromEvents($sub->child_id, $events);

                // مدرسة وموقع الطفل
                $childSchool = $sub->school ?? $childObj?->school ?? \App\Models\Parent\School::find($sub->school_id ?? $childObj?->school_id);
                $schoolLat = $childSchool?->lat ?? $sub->dropoff_lat ?? 32.890000;
                $schoolLng = $childSchool?->lng ?? $sub->dropoff_lng ?? 13.180000;
                $schoolLocation = [
                    'id'        => $childSchool?->id ? (int) $childSchool->id : null,
                    'name'      => $childSchool?->name ?? 'المدرسة',
                    'address'   => $childSchool?->address ?? null,
                    'latitude'  => (float) $schoolLat,
                    'longitude' => (float) $schoolLng,
                    'lat'       => (float) $schoolLat,
                    'lng'       => (float) $schoolLng,
                ];

                // منزل وسكن الطفل
                $childAddress = $childObj?->address;
                $homeLat = $stop?->lat ?? $childAddress?->lat ?? $sub->pickup_lat ?? $childObj?->latitude ?? 32.875210;
                $homeLng = $stop?->lng ?? $childAddress?->lng ?? $sub->pickup_lng ?? $childObj?->longitude ?? 13.165420;
                $homeTitle = $stop?->label ?? $childAddress?->label ?? $sub->pickup_label ?? 'المنزل الرئيسي';
                $homeLocation = [
                    'title'     => $homeTitle,
                    'address'   => $childAddress?->label ?? $sub->pickup_label ?? $homeTitle,
                    'latitude'  => (float) $homeLat,
                    'longitude' => (float) $homeLng,
                    'lat'       => (float) $homeLat,
                    'lng'       => (float) $homeLng,
                ];

                $rawPhoto = $childObj?->photo_url ?? null;
                $photoUrl = $rawPhoto ? (str_starts_with($rawPhoto, 'http') ? $rawPhoto : \Illuminate\Support\Facades\Storage::url($rawPhoto)) : asset('assets/images/default-child.png');

                return [
                    'trip_child_id'   => (int) $sub->id,
                    'child_id'        => (int) $sub->child_id,
                    'name'            => $childObj?->full_name ?? $childObj?->name ?? 'طفل',
                    'photo'           => $photoUrl,
                    'latitude'        => (float) $homeLat,
                    'longitude'       => (float) $homeLng,
                    'lat'             => (float) $homeLat,
                    'lng'             => (float) $homeLng,
                    'school'          => $schoolLocation['name'],
                    'school_name'     => $schoolLocation['name'],
                    'school_latitude' => (float) $schoolLat,
                    'school_longitude'=> (float) $schoolLng,
                    'pickup_address'  => $homeLocation['title'],
                    'dropoff_address' => $schoolLocation['name'],
                    'status'          => $statusFields['status'],
                    'pickup_status'   => $statusFields['pickup_status'],
                    'dropoff_status'  => $statusFields['dropoff_status'],
                    'eta'             => $statusFields['eta'],
                    'sequence_order'  => $statusFields['sequence_order'],
                    'home_location'   => $homeLocation,
                    'school_location' => $schoolLocation,
                ];
            })->values();

            // ⚠️ يجب أن يطابق ما يعرضه todayTrips و history لنفس الرحلة تماماً،
            // وإلا رأى السائق ثلاثة أسماء مختلفة للمسار الواحد في ثلاث شاشات.
            $routeName = $route?->route_name
                ?? $route?->formatted_route_name
                ?? RouteModel::generateGenericRouteName(null, 'both', $trip->trip_type);

            $metrics = $route
                ? $this->recommendationService->calculateRouteMetrics($route)
                : ['estimated_duration' => (int) ($route?->estimated_duration ?? 45), 'recommended_departure' => '07:00'];

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'trip_id'               => (int) $trip->id,
                    'trip_type'             => $trip->trip_type,
                    'route_name'            => $routeName,
                    'status'                => strtolower($trip->status),
                    'suspension_reason'     => $trip->suspension_reason,
                    'trip_date'             => $trip->trip_date ? Carbon::parse($trip->trip_date)->format('Y-m-d') : Carbon::today()->format('Y-m-d'),
                    'recommended_departure' => $metrics['recommended_departure'],
                    'estimated_duration'   => (int) $metrics['estimated_duration'],
                    'vehicle' => [
                        'plate'    => $driver->vehicle?->plate_number ?? '5-12345',
                        'capacity' => (int) ($driver->vehicle?->capacity_manual ?? 14),
                    ],
                    'statistics' => [
                        'children' => $children->count(),
                        'schools'  => $schoolsGrouped->count(),
                    ],
                    'schools'  => $schoolsGrouped,
                    'children' => $children,
                ]
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'الرحلة غير موجودة.'], 404);
        }
    }

    /**
     * 3️⃣ POST /api/driver/trips/{tripId}/start
     * بدء الرحلة
     */
    public function start(Request $request, $tripId = null): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $lat = $request->has('latitude') ? (float) $request->latitude : ($request->has('lat') ? (float) $request->lat : null);
            $lng = $request->has('longitude') ? (float) $request->longitude : ($request->has('lng') ? (float) $request->lng : null);

            $targetTripId = $tripId ?? $request->trip_id;

            if ($targetTripId) {
                $trip = Trip::where('id', $targetTripId)->where('driver_id', $driverId)->firstOrFail();

                // ⚠️ فحص الغياب كان موجوداً في المسار القديم (بدون tripId) فقط، بينما التطبيق
                // يستخدم هذا المسار — فكان السائق الغائب رسمياً يبدأ رحلته بشكل طبيعي.
                $tripDate = $trip->trip_date
                    ? Carbon::parse($trip->trip_date)->toDateString()
                    : Carbon::today()->toDateString();

                $isAbsent = \App\Models\Driver\DriverAbsence::where('driver_id', $driverId)
                    ->whereDate('absence_date', $tripDate)
                    ->where(function ($q) use ($trip) {
                        $q->whereDoesntHave('trips')
                          ->orWhereHas('trips', fn($tq) => $tq->where('trips.id', $trip->id));
                    })
                    ->exists();

                if ($isAbsent) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'DRIVER_ABSENT',
                        'message'    => 'لا يمكن بدء الرحلة؛ أنت مسجل كغائب في هذا اليوم.',
                    ], 422);
                }

                // ⚠️ بدون هذا الفحص كان بالإمكان إعادة فتح رحلة مكتملة وإنهاؤها مجدداً،
                // فتتكرر مسارات التسوية المالية وتفقد سجلات الرحلات معناها.
                if (in_array(strtolower((string) $trip->status), ['completed', 'cancelled'], true)) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'TRIP_NOT_STARTABLE',
                        'message'    => 'هذه الرحلة منتهية أو ملغاة ولا يمكن بدؤها من جديد.',
                    ], 409);
                }

                if (strtolower((string) $trip->status) === 'in_progress') {
                    return response()->json([
                        'status'  => 'success',
                        'message' => 'الرحلة جارية بالفعل.',
                        'data'    => [
                            'trip_id'    => (int) $trip->id,
                            'status'     => 'in_progress',
                            'started_at' => $trip->actual_start_time
                                ? Carbon::parse($trip->actual_start_time)->format('H:i')
                                : Carbon::now()->format('H:i'),
                        ],
                    ], 200);
                }

                // (10) رحلة بلا أي طفل فعلي (الجميع غائبون مسبقاً) لا معنى لتشغيلها
                $actionableStops = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                    ->where('stop_type', \App\Models\Shared\TripStop::TYPE_HOME)
                    ->where('sequence_order', '>', 0)
                    ->count();

                if ($actionableStops === 0) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'NO_ACTIVE_CHILDREN',
                        'message'    => 'لا يوجد أي طفل مطلوب نقله في هذه الرحلة (الجميع مسجل غيابهم)، لا حاجة لتشغيلها.',
                    ], 422);
                }

                $trip->status = 'in_progress';
                $trip->actual_start_time = now();
                $trip->started_at = now();

                if ($lat !== null && $lng !== null) {
                    $trip->start_lat = $lat;
                    $trip->start_lng = $lng;
                    \App\Models\Driver\Driver::where('id', $driverId)->update(['current_lat' => $lat, 'current_lng' => $lng]);
                }

                $trip->save();

                if ($lat !== null && $lng !== null) {
                    try {
                        $this->lifecycleService->computeLiveEtas($trip, $lat, $lng);
                    } catch (\Throwable $e) {
                        Log::warning("فشل حساب ETAs الحية عند بدء الرحلة ID: {$trip->id} - " . $e->getMessage());
                    }
                }
            } else {
                $tripType = $request->trip_type ?? 'Morning';
                $trip = $this->lifecycleService->startTrip($driverId, $tripType, $lat, $lng);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'تم بدء الرحلة.',
                'data'    => [
                    'trip_id'    => (int) $trip->id,
                    'status'     => 'in_progress',
                    'started_at' => Carbon::now()->format('H:i'),
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * 4️⃣ GET /api/driver/trips/{tripId}/live
     * شاشة الرحلة المباشرة
     */
    public function live($tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driver->id)->firstOrFail();
            $subs = ActiveSubscription::forDriver($driver->id)
                ->where('route_id', $trip->route_id)
                ->where('status', '!=', 'cancelled')
                ->with(['child', 'school'])
                ->get();

            $events = TripEvent::where('trip_id', $trip->id)->get();

            $homeStops = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->where('stop_type', 'home')
                ->orderBy('sequence_order')
                ->get();

            if ($homeStops->isNotEmpty()) {
                $total = $homeStops->count();
                $completed = $homeStops->whereNotIn('status', \App\Models\Shared\TripStop::NON_FINAL_STATUSES)->count();

                $currentStop = $homeStops
                    ->whereIn('status', \App\Models\Shared\TripStop::NON_FINAL_STATUSES)
                    ->sortBy('sequence_order')
                    ->first();

                $currentSub = $currentStop ? $subs->firstWhere('child_id', $currentStop->child_id) : null;
                $currentStatusFields = $currentStop ? $this->tripStopStatusFields($currentStop) : null;
            } else {
                // توافقية: رحلات قديمة بلا trip_stops بعد
                $total = $subs->count();
                $completed = $events->pluck('child_id')->unique()->count();

                $currentSub = $subs->first(function ($s) use ($events) {
                    return !$events->where('child_id', $s->child_id)->exists();
                }) ?? $subs->first();
                $currentStatusFields = $currentSub ? $this->legacyStatusFromEvents($currentSub->child_id, $events) : null;
            }

            $remaining = max(0, $total - $completed);

            $currentChildData = null;
            if ($currentSub) {
                $currentChildData = [
                    'trip_child_id'   => (int) $currentSub->id,
                    'child_id'        => (int) $currentSub->child_id,
                    'name'            => $currentSub->child->full_name ?? $currentSub->child->name ?? 'طفل',
                    'school'          => optional($currentSub->school)->name ?? 'المدرسة',
                    'pickup_address'  => $currentSub->pickup_label ?? 'حي الأندلس',
                    'latitude'        => (float) ($currentSub->pickup_lat ?? 32.880000),
                    'longitude'       => (float) ($currentSub->pickup_lng ?? 13.180000),
                    'status'          => $currentStatusFields['status'] ?? 'pending',
                    'pickup_status'   => $currentStatusFields['pickup_status'] ?? 'pending',
                    'dropoff_status'  => $currentStatusFields['dropoff_status'] ?? 'pending',
                    'eta'             => $currentStatusFields['eta'] ?? null,
                ];
            }

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'trip_status'   => strtolower($trip->status),
                    'current_child' => $currentChildData,
                    'progress'      => [
                        'total'     => $total,
                        'completed' => $completed,
                        'remaining' => $remaining,
                    ]
                ]
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * 5️⃣ POST /api/driver/trips/{tripId}/location
     * تحديث موقع GPS
     */
    public function updateLocation(\App\Http\Requests\Api\Trip\UpdateLocationRequest $request, $tripId): JsonResponse
    {
        // ⚠️ بدون هذا الفحص كان أي سائق يستطيع دفع إحداثيات إلى رحلة سائق آخر،
        // فيغيّر موقع الضحية الحي ويحقن نقاط تتبع وهمية يراها أولياء أمور تلك الرحلة.
        $driver = Auth::user()?->driver;

        if (!$driver) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'DRIVER_NOT_FOUND',
                'message'    => 'بيانات السائق غير مقترنة بالحساب.',
            ], 403);
        }

        $ownsTrip = Trip::where('id', $tripId)->where('driver_id', $driver->id)->exists();

        if (!$ownsTrip) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'TRIP_NOT_FOUND',
                'message'    => 'الرحلة غير موجودة أو غير مسندة لك.',
            ], 404);
        }

        $lat = (float) $request->validated('latitude');
        $lng = (float) $request->validated('longitude');
        $speed = (float) ($request->validated('speed') ?? 0);
        $heading = $request->validated('heading');

        $result = $this->trackingService->updateDriverLocation($tripId, $lat, $lng, $speed, $heading !== null ? (float) $heading : null);
        return response()->json(['status' => 'success'] + $result, 200);
    }

    /**
     * GET /api/v1/driver/trips/{tripId}/stops
     * القائمة الكاملة والمرتبة لمحطات الرحلة اليومية (trip_stops) بحالتها الدقيقة و ETA المحسوبة عند البدء
     */
    public function tripStops($tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driverId)->firstOrFail();

            $stops = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->with(['child', 'school'])
                ->orderBy('sequence_order')
                ->get()
                ->map(function ($stop) {
                    return [
                        'id'             => (int) $stop->id,
                        'stop_type'      => $stop->stop_type,
                        'sequence_order' => (int) $stop->sequence_order,
                        'status'         => $stop->status,
                        'child_id'       => $stop->child_id ? (int) $stop->child_id : null,
                        'child_name'     => $stop->child?->full_name ?? $stop->child?->name,
                        'school_id'      => $stop->school_id ? (int) $stop->school_id : null,
                        'school_name'    => $stop->school?->name,
                        'label'          => $stop->label,
                        'latitude'       => (float) $stop->lat,
                        'longitude'      => (float) $stop->lng,
                        'eta'            => $stop->eta ? substr((string) $stop->eta, 0, 5) : null,
                        'eta_minutes'    => $stop->eta_minutes !== null ? (int) $stop->eta_minutes : null,
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'trip_id'     => (int) $trip->id,
                    'trip_status' => strtolower($trip->status),
                    'stops'       => $stops,
                ]
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'الرحلة غير موجودة.'], 404);
        }
    }

    /**
     * GET /api/driver/trips/upcoming-for-absence
     * يعرض للسائق رحلاته القادمة ليختار منها عند تسجيل طلب غياب.
     */
    public function upcomingTripsForAbsence(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trips = $this->lifecycleService->getUpcomingTripsForAbsence($driverId);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم جلب رحلاتك القادمة بنجاح.',
                'data'    => $trips,
            ], 200);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/v1/driver/register-absence
     * أو POST /api/driver/trips/register-absence
     * تسجيل طلب غياب السائق عن رحلات محددة والسبب والتاريخ
     */
    public function registerAbsence(DriverAbsenceRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $tripIds = $request->input('trip_ids');
            $date = $request->input('date');
            $reason = $request->input('reason');
            $dates = $request->input('dates');

            if (!empty($tripIds)) {
                $absence = $this->lifecycleService->setDriverAbsence($driverId, $date, $tripIds, $reason);

                return response()->json([
                    'status'  => 'success',
                    'message' => 'تم تسجيل غيابك عن الرحلات المحددة فوراً، وفصلك عنها، وتنبيه أولياء أمور أطفالها.',
                    'data'    => [
                        'absence_id'   => $absence->id,
                        'driver_id'    => $driverId,
                        'absence_date' => $absence->absence_date->toDateString(),
                        'reason'       => $absence->reason,
                        'status'       => $absence->status,
                        'trip_ids'     => $tripIds,
                        'trips'        => $absence->trips->map(function ($trip) {
                            return [
                                'id'                   => $trip->id,
                                'trip_type'            => $trip->trip_type,
                                'shift_slot'           => $trip->shift_slot,
                                'trip_date'            => $trip->trip_date ? Carbon::parse($trip->trip_date)->toDateString() : null,
                                'status'               => $trip->status,
                                'scheduled_start_time' => $trip->scheduled_start_time,
                            ];
                        }),
                    ],
                ], 200);
            }

            // Legacy path for dates array
            $this->lifecycleService->setDriverAbsence($driverId, $dates, [], $reason);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل غيابك في التواريخ المحددة، ولن يتم توليد رحلات لمساراتك في هذه الأيام.',
                'data'    => ['dates' => $dates],
            ], 200);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/v1/driver/trips/{tripId}/report-breakdown
     * الإبلاغ عن عطل يوقف الرحلة الجارية وبدء البحث الفوري عن سائقين بدلاء
     */
    public function reportBreakdown(\App\Http\Requests\Api\Trip\ReportBreakdownRequest $request, $tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driverId)->firstOrFail();

            if ($trip->status !== 'in_progress') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'لا يمكن الإبلاغ عن عطل إلا لرحلة قيد التشغيل حالياً.',
                ], 422);
            }

            $breakdownLat = $request->input('latitude') ?? $request->input('lat');
            $breakdownLng = $request->input('longitude') ?? $request->input('lng');
            $reason = $request->input('reason');

            if ($breakdownLat !== null && $breakdownLng !== null) {
                \App\Models\Driver\Driver::where('id', $driverId)->update([
                    'current_lat' => (float) $breakdownLat,
                    'current_lng' => (float) $breakdownLng,
                ]);
            }

            $result = $this->emergencyBreakdownService->reportBreakdown(
                $trip,
                $breakdownLat !== null ? (float) $breakdownLat : null,
                $breakdownLng !== null ? (float) $breakdownLng : null,
                $reason
            );

            return response()->json($result, 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/v1/driver/emergency-dispatches/{dispatchId}/accept
     * قبول مهمة الاستبدال الطارئة من قبل السائق البديل
     */
    public function acceptBreakdownDispatch(Request $request, $dispatchId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $result = $this->emergencyBreakdownService->acceptBreakdownDispatch((int) $dispatchId, $driverId);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    /**
     * POST /api/v1/driver/emergency-dispatches/{dispatchId}/reject
     * رفض المهمة الطارئة من قبل السائق
     */
    public function rejectBreakdownDispatch(Request $request, $dispatchId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $result = $this->emergencyBreakdownService->rejectBreakdownDispatch((int) $dispatchId, $driverId);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/v1/driver/emergency-dispatches/available
     * جلب المهام الطارئة المتاحة لهذا السائق
     */
    public function getAvailableBreakdownDispatches(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $dispatches = \App\Models\Shared\TripBreakdownDispatch::where('status', \App\Models\Shared\TripBreakdownDispatch::STATUS_BROADCASTED)
                ->whereJsonContains('candidate_driver_ids', $driverId)
                ->where(function ($q) use ($driverId) {
                    $q->whereNull('rejected_driver_ids')
                      ->orWhereJsonDoesntContain('rejected_driver_ids', $driverId);
                })
                ->where('expires_at', '>', now())
                ->with(['trip', 'originalDriver.user'])
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $dispatches,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/v1/driver/emergency-dispatches/{dispatchId}
     * جلب تفاصيل مهمة طارئة محددة
     */
    public function getBreakdownDispatchDetails(Request $request, $dispatchId): JsonResponse
    {
        try {
            $dispatch = \App\Models\Shared\TripBreakdownDispatch::with([
                'trip.stops.child.address',
                'trip.stops.child.school',
                'originalDriver.user',
                'substituteDriver.user'
            ])->findOrFail($dispatchId);

            return response()->json([
                'status' => 'success',
                'data'   => $dispatch,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/v1/driver/trips/{tripId}/resume
     * استئناف رحلة كانت متوقفة بسبب عطل
     */
    public function resumeTrip($tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driverId)->firstOrFail();

            if ($trip->status !== 'suspended_breakdown') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'هذه الرحلة ليست متوقفة حالياً.',
                ], 422);
            }

            $trip->status = 'in_progress';
            $trip->suspension_reason = null;
            $trip->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم استئناف الرحلة.',
                'data'    => ['trip_id' => (int) $trip->id, 'status' => 'in_progress'],
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * 6️⃣ 7️⃣ 8️⃣ 9️⃣ الاندبوينت الموحد والمستقل لتحديث حالة الطفل داخل الرحلة
     * POST /api/driver/trips/{tripId}/children/{tripChildId}/status
     * POST /api/driver/trips/{tripId}/pickup
     * POST /api/driver/trips/{tripId}/dropoff
     * POST /api/driver/trips/{tripId}/absent
     */
    public function updateChildTripStatus(Request $request, $tripId, $tripChildId = null): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driverId)->firstOrFail();

            $subId = $tripChildId ?? $request->trip_child_id;

            if (empty($subId)) {
                return response()->json([
                    'status'     => 'error',
                    'error_code' => 'TRIP_CHILD_REQUIRED',
                    'message'    => 'يجب تحديد الطفل (trip_child_id) لتنفيذ هذا الإجراء.',
                ], 422);
            }

            // ⚠️ كل الإجراءات على الأطفال يجب أن تقع داخل رحلة جارية فعلياً؛ بدون هذا الفحص
            // كان يمكن تسجيل صعود ونزول وإنهاء رحلة لم تُبدأ أصلاً (تبقى حالتها pending).
            if (strtolower((string) $trip->status) !== 'in_progress') {
                $isCompleted = strtolower((string) $trip->status) === 'completed';

                return response()->json([
                    'status'     => 'error',
                    'error_code' => $isCompleted ? 'TRIP_ALREADY_COMPLETED' : 'TRIP_NOT_STARTED',
                    'message'    => $isCompleted
                        ? 'الرحلة منتهية بالفعل ولا يمكن تعديل حالات الأطفال فيها.'
                        : 'يجب بدء الرحلة أولاً قبل تسجيل أي حالة للأطفال.',
                ], 409);
            }

            $action = strtolower($request->action ?? $request->route()->getActionMethod());

            // ⚠️ يجب فحص الأسماء الأكثر تحديداً أولاً (dropoff_failed يحتوي جزئياً على "dropoff")
            if (str_contains($action, 'dropoff_failed')) $action = 'dropoff_failed';
            elseif (str_contains($action, 'direct_parent') || str_contains($action, 'parent_handling')) $action = 'direct_parent_handling';
            elseif (str_contains($action, 'pickup')) $action = 'pickup';
            elseif (str_contains($action, 'dropoff')) $action = 'dropoff';
            elseif (str_contains($action, 'absent')) $action = 'absent';
            elseif (str_contains($action, 'skip')) $action = 'skip';

            // 🔒 التحقق من ملكية الاشتراك للسائق الحالي لمنع التلاعب باشتراكات سائقين آخرين (IDOR)
            $sub = ActiveSubscription::where('id', $subId)->forDriver($driverId)->first();

            if (!$sub) {
                return response()->json([
                    'status'     => 'error',
                    'error_code' => 'TRIP_CHILD_NOT_FOUND',
                    'message'    => 'هذا الطفل غير مشترك معك أو رقم الاشتراك غير صحيح.',
                ], 404);
            }

            $childId = $sub->child_id;

            // trip_events.trip_type/location_lat/location_lng أعمدة إلزامية (NOT NULL) في قاعدة البيانات
            $eventTripType = ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب') ? 'ذهاب' : 'عودة';

            // محطة منزل الطفل في trip_stops — مصدر الحقيقة لحالة الطفل ولإحداثيات فحص الـ Geofence
            $homeStop = \App\Models\Shared\TripStop::where('trip_id', $tripId)
                ->where('child_id', $childId)
                ->where('stop_type', 'home')
                ->first();

            $isQr = strtolower($request->verification_method ?? 'manual') === 'qr';

            // 🎯 المرونة مع الأمان: QR يمنح سرعة وتجاوزاً للنطاق، الزر اليدوي يُقيَّد بـ GPS
            if (in_array($action, ['pickup', 'dropoff'], true)) {
                if ($isQr) {
                    $providedToken = $request->qr_code_token;
                    if (!$providedToken || $providedToken !== ($sub->child->qr_code_token ?? null)) {
                        return response()->json([
                            'status'     => 'error',
                            'error_code' => 'QR_MISMATCH',
                            'message'    => 'كود الـ QR غير متطابق مع هذا الطفل.',
                        ], 400);
                    }

                    // الـ QR يمنح مرونة في النطاق (لا يُلزم السائق بالوقوف على النقطة بالضبط)
                    // لكنه لا يُعفي من الوجود في المنطقة: بدون هذا كان يمكن تأكيد صعود طفل
                    // من مئات الكيلومترات بمجرد امتلاك الكود.
                    $qrLat = $request->has('latitude') ? (float) $request->latitude : ($request->has('lat') ? (float) $request->lat : null);
                    $qrLng = $request->has('longitude') ? (float) $request->longitude : ($request->has('lng') ? (float) $request->lng : null);

                    if ($qrLat !== null && $qrLng !== null) {
                        $qrTargetLat = $homeStop?->lat ?? $sub->pickup_lat;
                        $qrTargetLng = $homeStop?->lng ?? $sub->pickup_lng;

                        if ($action === 'dropoff') {
                            $isGoTripQr = \App\Models\Driver\DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
                                || (!$trip->shift_slot && ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب'));
                            if ($isGoTripQr) {
                                $qrTargetLat = $sub->dropoff_lat ?? $sub->school?->lat ?? $sub->child?->school?->lat;
                                $qrTargetLng = $sub->dropoff_lng ?? $sub->school?->lng ?? $sub->child?->school?->lng;
                            }
                        }

                        if ($qrTargetLat !== null && $qrTargetLng !== null) {
                            $distanceMeters = \App\Support\GeoEstimator::haversineKm(
                                $qrLat, $qrLng, (float) $qrTargetLat, (float) $qrTargetLng
                            ) * 1000;

                            if ($distanceMeters > \App\Services\Trip\GeofenceService::QR_MAX_RADIUS_METERS) {
                                return response()->json([
                                    'status'     => 'error',
                                    'error_code' => 'OUT_OF_RANGE',
                                    'message'    => sprintf(
                                        'أنت بعيد جداً عن موقع المحطة (%.0f م) لتأكيد المسح. يرجى الاقتراب من الموقع.',
                                        $distanceMeters
                                    ),
                                ], 422);
                            }
                        }
                    }
                } else {
                    $driverLat = $request->has('latitude') ? (float) $request->latitude : ($request->has('lat') ? (float) $request->lat : null);
                    $driverLng = $request->has('longitude') ? (float) $request->longitude : ($request->has('lng') ? (float) $request->lng : null);

                    if ($driverLat === null || $driverLng === null) {
                        return response()->json([
                            'status'     => 'error',
                            'error_code' => 'LOCATION_REQUIRED',
                            'message'    => 'يجب إرسال الموقع الجغرافي الحالي (latitude, longitude) للتأكيد اليدوي، أو استخدام مسح QR.',
                        ], 422);
                    }

                    if ($action === 'pickup') {
                        $targetLat = $homeStop?->lat ?? $sub->pickup_lat ?? $sub->child?->latitude ?? $sub->child?->address?->lat;
                        $targetLng = $homeStop?->lng ?? $sub->pickup_lng ?? $sub->child?->longitude ?? $sub->child?->address?->lng;
                        $stopType = \App\Models\Shared\TripStop::TYPE_HOME;
                    } else {
                        // dropoff
                        $isGoTrip = \App\Models\Driver\DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
                            || (!$trip->shift_slot && ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب'));
                        if ($isGoTrip) {
                            $targetLat = $sub->dropoff_lat ?? $sub->school?->lat ?? $sub->child?->school?->lat;
                            $targetLng = $sub->dropoff_lng ?? $sub->school?->lng ?? $sub->child?->school?->lng;
                            $stopType = \App\Models\Shared\TripStop::TYPE_SCHOOL;
                        } else {
                            $targetLat = $homeStop?->lat ?? $sub->pickup_lat ?? $sub->child?->latitude ?? $sub->child?->address?->lat;
                            $targetLng = $homeStop?->lng ?? $sub->pickup_lng ?? $sub->child?->longitude ?? $sub->child?->address?->lng;
                            $stopType = \App\Models\Shared\TripStop::TYPE_HOME;
                        }
                    }

                    if ($targetLat !== null && $targetLng !== null) {
                        try {
                            $this->geofenceService->assertWithinCoordinates($driverLat, $driverLng, (float) $targetLat, (float) $targetLng, $stopType);
                        } catch (\App\Services\Trip\GeofenceViolationException $e) {
                            return response()->json([
                                'status'     => 'error',
                                'error_code' => $e->getErrorCode(),
                                'message'    => $e->getMessage(),
                            ], $e->getCode());
                        }
                    }
                }
            }

            if ($action === 'pickup') {
                // 🔒 قفل صف المحطة داخل معاملة قاعدة بيانات لمنع تسابق طلبين متزامنين (Race Condition)
                // على نفس الطفل (مثلاً ضغطتين سريعتين من التطبيق أو إعادة إرسال بعد انقطاع شبكة)
                $conflict = false;
                DB::transaction(function () use ($tripId, $childId, $sub, $eventTripType, $homeStop, $request, &$conflict) {
                    $lockedStop = $homeStop
                        ? \App\Models\Shared\TripStop::where('id', $homeStop->id)->lockForUpdate()->first()
                        : null;

                    if ($lockedStop && $lockedStop->status !== \App\Models\Shared\TripStop::STATUS_PENDING) {
                        $conflict = true;
                        return;
                    }

                    TripEvent::create([
                        'trip_id'         => $tripId,
                        'child_id'        => $childId,
                        'subscription_id' => $sub->id,
                        'action_type'     => 'picked_up',
                        'trip_type'       => $eventTripType,
                        'scanned_at'      => now(),
                        'location_lat'    => $request->latitude ?? $request->lat ?? $sub->pickup_lat ?? 0,
                        'location_lng'    => $request->longitude ?? $request->lng ?? $sub->pickup_lng ?? 0,
                        'trip_cost'       => 0,
                    ]);

                    $lockedStop?->update(['status' => \App\Models\Shared\TripStop::STATUS_BOARDED]);
                });

                if ($conflict) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'ALREADY_PROCESSED',
                        'message'    => 'تم تسجيل صعود هذا الطفل مسبقاً.',
                    ], 409);
                }

                // 🔔 إرسال إشعار لحظي FCM لولي الأمر
                try {
                    $parentUser = $sub->parent?->user ?? \App\Models\User::find($sub->parent_id);
                    if ($parentUser) {
                        $childName = $sub->child->full_name ?? $sub->child->name ?? 'طفلك';
                        $this->notificationService->sendToUser($parentUser, 'child_picked_up', [
                            'title'      => '🚌 صعود الحافلة بنجاح',
                            'message'    => "صعد {$childName} إلى الحافلة الآن بسلام.",
                            'child_name' => $childName,
                            'trip_id'    => (string) $tripId,
                            'entity_id'  => $tripId . '_' . $childId,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("FCM Notification error on pickup: " . $e->getMessage());
                }

                $nextStop = $this->resolveNextStop($trip, $homeStop?->sequence_order);

                return response()->json([
                    'status'    => 'success',
                    'message'   => 'تم تأكيد الصعود وإرسال الإشعار لولي الأمر.',
                    'next_stop' => $nextStop,
                ], 200);

            } elseif ($action === 'dropoff') {
                // اتجاه الرحلة يحدد الوجهة النهائية: ذهاب → المدرسة، إياب → المنزل
                $isGoTrip = \App\Models\Driver\DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
                    || (!$trip->shift_slot && $trip->trip_type === 'Morning');
                $dropoffStatus = $isGoTrip
                    ? \App\Models\Shared\TripStop::STATUS_DROPPED_OFF_SCHOOL
                    : \App\Models\Shared\TripStop::STATUS_DELIVERED_HOME;

                // 🔒 نفس حماية القفل والتزامن المطبقة على الصعود، بالإضافة لمنع النزول قبل الصعود
                $conflict = false;
                $notBoarded = false;
                DB::transaction(function () use ($tripId, $childId, $sub, $eventTripType, $homeStop, $request, $dropoffStatus, &$conflict, &$notBoarded) {
                    $lockedStop = $homeStop
                        ? \App\Models\Shared\TripStop::where('id', $homeStop->id)->lockForUpdate()->first()
                        : null;

                    if ($lockedStop) {
                        if ($lockedStop->status === \App\Models\Shared\TripStop::STATUS_PENDING) {
                            $notBoarded = true;
                            return;
                        }
                        if ($lockedStop->status !== \App\Models\Shared\TripStop::STATUS_BOARDED) {
                            $conflict = true;
                            return;
                        }
                    }

                    TripEvent::create([
                        'trip_id'         => $tripId,
                        'child_id'        => $childId,
                        'subscription_id' => $sub->id,
                        'action_type'     => 'dropped_off',
                        'trip_type'       => $eventTripType,
                        'scanned_at'      => now(),
                        'location_lat'    => $request->latitude ?? $request->lat ?? $sub->dropoff_lat ?? 0,
                        'location_lng'    => $request->longitude ?? $request->lng ?? $sub->dropoff_lng ?? 0,
                        'trip_cost'       => 0,
                    ]);

                    $lockedStop?->update(['status' => $dropoffStatus]);
                });

                if ($notBoarded) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'NOT_BOARDED_YET',
                        'message'    => 'لا يمكن تأكيد النزول قبل تأكيد صعود الطفل أولاً.',
                    ], 409);
                }
                if ($conflict) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'ALREADY_PROCESSED',
                        'message'    => 'تم تسجيل نزول هذا الطفل مسبقاً.',
                    ], 409);
                }

                // 🔔 إرسال إشعار لحظي FCM لولي الأمر
                try {
                    $parentUser = $sub->parent?->user ?? \App\Models\User::find($sub->parent_id);
                    if ($parentUser) {
                        $childName = $sub->child->full_name ?? $sub->child->name ?? 'طفلك';
                        $this->notificationService->sendToUser($parentUser, 'child_dropped_off', [
                            'title'      => '🏫 وصول بسلام',
                            'message'    => "وصل {$childName} ونزل بالمدرسة/المنزل بسلام.",
                            'child_name' => $childName,
                            'trip_id'    => (string) $tripId,
                            'entity_id'  => $tripId . '_' . $childId,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("FCM Notification error on dropoff: " . $e->getMessage());
                }

                $nextStop = $this->resolveNextStop($trip, $homeStop?->sequence_order);

                return response()->json([
                    'status'    => 'success',
                    'message'   => 'تم تأكيد النزول وإرسال الإشعار لولي الأمر.',
                    'next_stop' => $nextStop,
                ], 200);

            } elseif (in_array($action, ['absent', 'skip', 'dropoff_failed', 'direct_parent_handling'], true)) {
                $actionTypeMap = [
                    'absent'                 => 'absent',
                    'skip'                   => 'skipped',
                    'dropoff_failed'         => 'dropoff_failed',
                    'direct_parent_handling' => 'direct_parent_handling',
                ];
                $stopStatusMap = [
                    'absent'                 => \App\Models\Shared\TripStop::STATUS_ABSENT_LATE,
                    'skip'                   => \App\Models\Shared\TripStop::STATUS_SKIPPED_UNRESPONSIVE,
                    'dropoff_failed'         => \App\Models\Shared\TripStop::STATUS_DROPOFF_FAILED,
                    'direct_parent_handling' => \App\Models\Shared\TripStop::STATUS_DIRECT_PARENT_HANDLING,
                ];
                $notificationMap = [
                    'absent'                 => ['title' => '⚠️ غياب الطفل', 'message' => 'لم يجد السائق {name} في محطة الانتظار، تم تسجيل غيابه.'],
                    'skip'                   => ['title' => '⚠️ تجاوز المحطة', 'message' => 'انتهى وقت الانتظار دون استجابة، تحركت الحافلة وتجاوزت محطة {name}.'],
                    'dropoff_failed'         => ['title' => '🚨 تعذر تسليم الطفل', 'message' => 'تعذر على السائق تسليم {name} في محطة النزول، يرجى التواصل الفوري.'],
                    'direct_parent_handling' => ['title' => 'ℹ️ استلام مباشر من ولي الأمر', 'message' => 'تم تسليم {name} مباشرة لولي الأمر خارج الإجراء المعتاد.'],
                ];
                $notificationTypeMap = [
                    'absent'                 => 'child_absent',
                    'skip'                   => 'child_skip',
                    'dropoff_failed'         => 'child_dropoff_failed',
                    'direct_parent_handling' => 'child_direct_parent_handling',
                ];

                // 🔒 قفل ومنع تعارض التزامن: كل إجراء له الحالات المصدر المنطقية المسموح له بالانطلاق منها فقط.
                // absent/skip منطقياً قبل الصعود فقط (الطفل لم يركب بعد)، dropoff_failed بعد الصعود فقط
                // (الطفل بالحافلة وتعذّر تسليمه)، direct_parent_handling يصلح في الحالتين.
                $allowedSourceStatuses = [
                    'absent'                 => [\App\Models\Shared\TripStop::STATUS_PENDING],
                    'skip'                   => [\App\Models\Shared\TripStop::STATUS_PENDING],
                    'dropoff_failed'         => [\App\Models\Shared\TripStop::STATUS_BOARDED],
                    'direct_parent_handling' => \App\Models\Shared\TripStop::NON_FINAL_STATUSES,
                ];

                $reasonInput = $request->input('reason')
                    ?? $request->input('skip_reason')
                    ?? $request->input('notes')
                    ?? $request->input('exception_reason');

                $conflict = false;
                DB::transaction(function () use ($tripId, $childId, $sub, $eventTripType, $homeStop, $action, $actionTypeMap, $stopStatusMap, $allowedSourceStatuses, $reasonInput, &$conflict) {
                    $lockedStop = $homeStop
                        ? \App\Models\Shared\TripStop::where('id', $homeStop->id)->lockForUpdate()->first()
                        : null;

                    if ($lockedStop && !in_array($lockedStop->status, $allowedSourceStatuses[$action], true)) {
                        $conflict = true;
                        return;
                    }

                    TripEvent::create([
                        'trip_id'         => $tripId,
                        'child_id'        => $childId,
                        'subscription_id' => $sub->id,
                        'action_type'     => $actionTypeMap[$action],
                        'trip_type'       => $eventTripType,
                        'scanned_at'      => now(),
                        'location_lat'    => $sub->pickup_lat ?? 0,
                        'location_lng'    => $sub->pickup_lng ?? 0,
                        'trip_cost'       => 0,
                        'reason'          => $reasonInput,
                    ]);

                    $stopUpdate = ['status' => $stopStatusMap[$action]];
                    if ($reasonInput !== null) {
                        $stopUpdate['reason'] = $reasonInput;
                    }
                    $lockedStop?->update($stopUpdate);
                });

                if ($conflict) {
                    return response()->json([
                        'status'     => 'error',
                        'error_code' => 'ALREADY_PROCESSED',
                        'message'    => 'تم تسجيل حالة نهائية لهذا الطفل بالفعل.',
                    ], 409);
                }

                // 🔔 إرسال إشعار لحظي FCM لولي الأمر
                try {
                    $parentUser = $sub->parent?->user ?? \App\Models\User::find($sub->parent_id);
                    if ($parentUser) {
                        $childName = $sub->child->full_name ?? $sub->child->name ?? 'طفلك';
                        $notif = $notificationMap[$action];
                        $this->notificationService->sendToUser($parentUser, $notificationTypeMap[$action], [
                            'title'      => $notif['title'],
                            'message'    => str_replace('{name}', $childName, $notif['message']),
                            'child_name' => $childName,
                            'trip_id'    => (string) $tripId,
                            'entity_id'  => $tripId . '_' . $childId,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("FCM Notification error on {$action}: " . $e->getMessage());
                }

                $nextStop = $this->resolveNextStop($trip, $homeStop?->sequence_order);

                return response()->json([
                    'status'    => 'success',
                    'message'   => 'تم تسجيل الحالة بنجاح.',
                    'next_stop' => $nextStop,
                ], 200);
            }

            return response()->json(['status' => 'error', 'message' => 'الإجراء غير معرف.'], 422);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * تحديد المحطة التالية للسائق في الرحلة (قد تكون منزلاً لطفل آخر أو مدرسة لإنزال الطلاب)
     */
    private function resolveNextStop(Trip $trip, ?int $currentSequenceOrder = null): ?array
    {
        $stopsQuery = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
            ->where('sequence_order', '>', 0)
            ->orderBy('sequence_order', 'asc');

        $nextStop = null;
        if ($currentSequenceOrder !== null) {
            $nextStop = (clone $stopsQuery)
                ->where('sequence_order', '>', $currentSequenceOrder)
                ->whereIn('status', [
                    \App\Models\Shared\TripStop::STATUS_PENDING,
                    \App\Models\Shared\TripStop::STATUS_BOARDED
                ])
                ->first();
        }

        if (!$nextStop) {
            $nextStop = (clone $stopsQuery)
                ->where('status', \App\Models\Shared\TripStop::STATUS_PENDING)
                ->first();
        }

        if ($nextStop) {
            $isSchool = $nextStop->stop_type === \App\Models\Shared\TripStop::TYPE_SCHOOL;
            $child = $nextStop->child_id ? \App\Models\Parent\Child::with(['school', 'address'])->find($nextStop->child_id) : null;
            $school = $nextStop->school_id ? \App\Models\Parent\School::find($nextStop->school_id) : ($child?->school);
            $sub = $nextStop->child_id
                ? ActiveSubscription::forDriver($trip->driver_id)->forChild($nextStop->child_id)->first()
                : null;

            $name = $isSchool
                ? ($school?->name ?? $nextStop->label ?? 'المدرسة')
                : ($child?->full_name ?? $child?->name ?? 'طفل');

            $address = $isSchool
                ? ($school?->address ?? $school?->address_text ?? $nextStop->label ?? 'مقر المدرسة')
                : ($child?->address?->label ?? $sub?->pickup_label ?? $nextStop->label ?? 'موقع المنزل');

            return [
                'stop_id'        => (int) $nextStop->id,
                'stop_type'      => $nextStop->stop_type,
                'sequence_order' => (int) $nextStop->sequence_order,
                'name'           => $name,
                'title'          => $name,
                'child_id'       => $nextStop->child_id ? (int) $nextStop->child_id : null,
                'trip_child_id'  => $sub ? (int) $sub->id : null,
                'child_name'     => $child?->full_name ?? $child?->name ?? null,
                'school_id'      => $nextStop->school_id ? (int) $nextStop->school_id : ($school?->id ? (int) $school->id : null),
                'school_name'    => $school?->name ?? null,
                'latitude'       => (float) $nextStop->lat,
                'longitude'      => (float) $nextStop->lng,
                'lat'            => (float) $nextStop->lat,
                'lng'            => (float) $nextStop->lng,
                'address'        => $address,
                'status'         => $nextStop->status,
                'eta'            => $nextStop->eta ? substr((string) $nextStop->eta, 0, 5) : null,
            ];
        }

        // fallback في حال كانت الرحلة بدون سجلات trip_stops مسبقة
        $processedChildIds = DB::table('trip_events')
            ->where('trip_id', $trip->id)
            ->whereIn('action_type', ['picked_up', 'absent', 'skipped', 'dropped_off'])
            ->pluck('child_id')
            ->all();

        $unprocessedSubs = ActiveSubscription::forDriver($trip->driver_id)
            ->where('route_id', $trip->route_id)
            ->where('status', '!=', 'cancelled')
            ->when(!empty($processedChildIds), fn ($q) => $q->excludingChildren($processedChildIds))
            ->with(['child.school', 'child.address'])
            ->get();

        if ($unprocessedSubs->isNotEmpty()) {
            $nextSub = $unprocessedSubs->first();
            $child = $nextSub->child;
            $childAddress = $child?->address;
            $homeLat = $childAddress?->lat ?? $nextSub->pickup_lat ?? $child?->latitude ?? 32.875210;
            $homeLng = $childAddress?->lng ?? $nextSub->pickup_lng ?? $child?->longitude ?? 13.165420;

            return [
                'stop_id'        => null,
                'stop_type'      => 'home',
                'sequence_order' => null,
                'name'           => $child?->full_name ?? $child?->name ?? 'طفل',
                'title'          => $child?->full_name ?? $child?->name ?? 'محطة الطفل',
                'child_id'       => (int) $nextSub->child_id,
                'trip_child_id'  => (int) $nextSub->id,
                'child_name'     => $child?->full_name ?? $child?->name ?? null,
                'school_id'      => $nextSub->school_id ? (int) $nextSub->school_id : null,
                'school_name'    => optional($nextSub->school)->name ?? optional($child?->school)->name,
                'latitude'       => (float) $homeLat,
                'longitude'      => (float) $homeLng,
                'lat'            => (float) $homeLat,
                'lng'            => (float) $homeLng,
                'address'        => $childAddress?->label ?? $nextSub->pickup_label ?? 'المنزل',
                'status'         => 'pending',
                'eta'            => null,
            ];
        }

        $isGoTrip = \App\Models\Driver\DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
            || (!$trip->shift_slot && ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب'));

        if ($isGoTrip) {
            $firstSub = ActiveSubscription::forDriver($trip->driver_id)
                ->where('route_id', $trip->route_id)
                ->with('child.school')
                ->first();

            if (!$firstSub && $trip->driver_id) {
                $firstSub = ActiveSubscription::forDriver($trip->driver_id)
                    ->with('child.school')
                    ->first();
            }

            if ($firstSub && $firstSub->child?->school) {
                $school = $firstSub->child->school;
                return [
                    'stop_id'        => null,
                    'stop_type'      => 'school',
                    'sequence_order' => null,
                    'name'           => $school->name,
                    'title'          => $school->name,
                    'child_id'       => null,
                    'trip_child_id'  => null,
                    'child_name'     => null,
                    'school_id'      => (int) $school->id,
                    'school_name'    => $school->name,
                    'latitude'       => (float) ($school->lat ?? $school->latitude ?? $firstSub->dropoff_lat ?? 32.890000),
                    'longitude'      => (float) ($school->lng ?? $school->longitude ?? $firstSub->dropoff_lng ?? 13.180000),
                    'lat'            => (float) ($school->lat ?? $school->latitude ?? $firstSub->dropoff_lat ?? 32.890000),
                    'lng'            => (float) ($school->lng ?? $school->longitude ?? $firstSub->dropoff_lng ?? 13.180000),
                    'address'        => $school->address ?? $school->address_text ?? 'مقر المدرسة',
                    'status'         => 'pending',
                    'eta'            => null,
                ];
            }
        }

        return null;
    }

    // Wrappers للـ Routes الفردية
    public function pickup(Request $request, $tripId): JsonResponse
    {
        return $this->updateChildTripStatus($request, $tripId);
    }

    public function dropoff(Request $request, $tripId): JsonResponse
    {
        return $this->updateChildTripStatus($request, $tripId);
    }

    public function absent(Request $request, $tripId): JsonResponse
    {
        return $this->updateChildTripStatus($request, $tripId);
    }

    public function skip(Request $request, $tripId, $childId): JsonResponse
    {
        $request->merge(['action' => 'skip']);
        return $this->updateChildTripStatus($request, $tripId, $childId);
    }

    public function verifyQr(Request $request, $tripId, $childId): JsonResponse
    {
        $stage = strtolower($request->stage ?? 'pickup') === 'dropoff' ? 'dropoff' : 'pickup';
        $request->merge(['action' => $stage, 'verification_method' => 'qr']);
        return $this->updateChildTripStatus($request, $tripId, $childId);
    }

    /**
     * 🔟 POST /api/driver/trips/{tripId}/complete
     * إنهاء الرحلة وإرجاع الملخص الإحصائي
     */
    public function complete($tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driverId)->firstOrFail();

            // 🛡️ صمام أمان الأطفال: يرمي TripLifecycleException (422) إذا وُجد طفل بحالة boarded/pending
            $result = $this->lifecycleService->completeTrip($trip->id);

            $events = TripEvent::where('trip_id', $trip->id)->get();
            $totalSubs = ActiveSubscription::where('route_id', $trip->route_id)->count();

            $pickedUp = $events->where('action_type', 'picked_up')->count();
            $droppedOff = $events->where('action_type', 'dropped_off')->count();
            $absent = $events->whereIn('action_type', ['absent', 'skipped', 'dropoff_failed', 'direct_parent_handling'])->count();

            $trip->refresh();

            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
                'summary' => [
                    'children'    => $totalSubs > 0 ? $totalSubs : max(1, $pickedUp + $absent),
                    'picked_up'   => $pickedUp,
                    'dropped_off' => $droppedOff,
                    'absent'      => $absent,
                    'duration'    => $this->calculateTripDurationMinutes($trip),
                    'distance'    => $this->calculateTripDistanceKm($trip),
                ]
            ], 200);

        } catch (\App\Services\Trip\TripLifecycleException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getCode());
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * المدة الفعلية للرحلة بالدقائق (وليست قيمة ثابتة).
     */
    private function calculateTripDurationMinutes(Trip $trip): int
    {
        $startedAt = $trip->started_at ?? $trip->actual_start_time;

        if ($startedAt && $trip->completed_at) {
            return (int) Carbon::parse($startedAt)->diffInMinutes(Carbon::parse($trip->completed_at));
        }

        return (int) ($trip->route?->estimated_duration ?? 0);
    }

    /**
     * المسافة الفعلية المقطوعة بالكيلومتر، محسوبة من نقاط التتبع المسجلة للرحلة.
     */
    private function calculateTripDistanceKm(Trip $trip): float
    {
        $points = \App\Models\Shared\TripTracking::where('trip_id', $trip->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['latitude', 'longitude']);

        if ($points->count() < 2) {
            return (float) ($trip->route?->total_distance ?? 0);
        }

        $distanceKm = 0.0;
        $previous = null;

        foreach ($points as $point) {
            if ($previous !== null) {
                $distanceKm += \App\Support\GeoEstimator::haversineKm(
                    (float) $previous->latitude,
                    (float) $previous->longitude,
                    (float) $point->latitude,
                    (float) $point->longitude
                );
            }
            $previous = $point;
        }

        return round($distanceKm, 2);
    }

    /**
     * 11️⃣ GET /api/driver/trips/history
     * سجل الرحلات السابقة
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->driver) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'بيانات السائق غير موجودة أو الحساب غير مصرح له.'
                ], 403);
            }

            $driverId = $user->driver->id;
            $perPage = (int) $request->query('per_page', 15);
            if ($perPage <= 0 || $perPage > 100) {
                $perPage = 15;
            }

            $query = Trip::where('driver_id', $driverId)
                ->where('status', 'completed')
                ->with(['route']);

            if ($request->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('date'))) {
                $query->whereDate('trip_date', $request->query('date'));
            }

            $paginated = $query->orderBy('trip_date', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage);

            $data = collect($paginated->items())->map(function ($t) {
                $actualStartedAt = $t->started_at ?? $t->actual_start_time;
                $actualCompletedAt = $t->completed_at;

                $duration = 40;
                if ($actualStartedAt && $actualCompletedAt) {
                    $duration = (int) Carbon::parse($actualStartedAt)->diffInMinutes(Carbon::parse($actualCompletedAt));
                } elseif ($t->route && $t->route->estimated_duration) {
                    $duration = (int) $t->route->estimated_duration;
                }

                return [
                    'trip_id'             => (int) $t->id,
                    'trip_date'           => $t->trip_date ? Carbon::parse($t->trip_date)->format('Y-m-d') : Carbon::parse($t->created_at)->format('Y-m-d'),
                    'route_name'          => $t->route?->route_name ?? 'المسار العام',
                    'status'              => 'completed',
                    'actual_started_at'   => $actualStartedAt ? Carbon::parse($actualStartedAt)->format('Y-m-d H:i:s') : null,
                    'actual_completed_at' => $actualCompletedAt ? Carbon::parse($actualCompletedAt)->format('Y-m-d H:i:s') : null,
                    'duration'            => $duration,
                ];
            })->values();

            return response()->json([
                'status'     => 'success',
                'data'       => $data,
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'total_pages'  => $paginated->lastPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'has_more'     => $paginated->hasMorePages(),
                ]
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 12️⃣ GET /api/driver/trips/history/{tripId}
     * تفاصيل رحلة سابقة مكتملة
     */
    public function historyDetails($tripId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driverId = $user->driver->id;

            $trip = Trip::where('id', $tripId)->where('driver_id', $driverId)->firstOrFail();

            $events = TripEvent::where('trip_id', $trip->id)
                ->with(['child', 'subscription.school'])
                ->get();

            $tripStops = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->get()
                ->keyBy('child_id');

            $subs = ActiveSubscription::forDriver($driverId)
                ->where(function($q) use ($trip) {
                    $q->where('route_id', $trip->route_id);
                    if ($trip->route?->contract_id) {
                        $q->orWhere('contract_id', $trip->route->contract_id);
                    }
                })
                ->with(['child', 'school'])
                ->get()
                ->keyBy('child_id');

            $eventsByChild = $events->groupBy('child_id');

            $allChildIds = $eventsByChild->keys()
                ->merge($tripStops->keys())
                ->merge($subs->keys())
                ->filter()
                ->unique();

            $children = $allChildIds->map(function ($childId) use ($eventsByChild, $tripStops, $subs) {
                $childEvents = $eventsByChild->get($childId, collect());
                $stop = $tripStops->get($childId);
                $sub = $subs->get($childId);

                $pickupEvent = $childEvents->first(fn($e) => in_array($e->action_type, ['picked_up', 'pickup']));
                $dropoffEvent = $childEvents->first(fn($e) => in_array($e->action_type, ['dropped_off', 'dropoff']));
                $absentEvent = $childEvents->first(fn($e) => in_array($e->action_type, ['absent', 'skip', 'skipped']));
                $latestEvent = $childEvents->sortByDesc('scanned_at')->first();

                $childObj = $pickupEvent?->child 
                    ?? $dropoffEvent?->child 
                    ?? $latestEvent?->child 
                    ?? $sub?->child;

                $schoolName = optional($sub?->school)->name 
                    ?? optional($childObj?->school)->name
                    ?? optional($pickupEvent?->subscription?->school)->name 
                    ?? optional($dropoffEvent?->subscription?->school)->name 
                    ?? optional(\App\Models\Parent\School::find($sub?->school_id ?? $childObj?->school_id))->name
                    ?? 'المدرسة';

                $pickupAddress = $sub?->pickup_label ?? $stop?->label ?? 'الحي السكني';
                $dropoffAddress = $schoolName;

                $pickupTime = $pickupEvent?->scanned_at 
                    ? Carbon::parse($pickupEvent->scanned_at)->format('H:i') 
                    : null;

                $dropoffTime = $dropoffEvent?->scanned_at 
                    ? Carbon::parse($dropoffEvent->scanned_at)->format('H:i') 
                    : null;

                $status = 'completed';
                if ($dropoffEvent) {
                    $status = 'completed';
                } elseif ($pickupEvent) {
                    $status = 'picked_up';
                } elseif ($absentEvent) {
                    if (in_array($absentEvent->action_type, ['skip', 'skipped']) || ($stop && $stop->status === \App\Models\Shared\TripStop::STATUS_SKIPPED_UNRESPONSIVE)) {
                        $status = 'skipped';
                    } else {
                        $status = 'absent';
                    }
                } elseif ($stop) {
                    if ($stop->status === \App\Models\Shared\TripStop::STATUS_SKIPPED_UNRESPONSIVE) {
                        $status = 'skipped';
                    } elseif (in_array($stop->status, [\App\Models\Shared\TripStop::STATUS_ABSENT_PRE, \App\Models\Shared\TripStop::STATUS_ABSENT_LATE])) {
                        $status = 'absent';
                    }
                }

                $reason = $absentEvent?->reason 
                    ?? $stop?->reason 
                    ?? $latestEvent?->reason 
                    ?? null;

                $scannedAt = $latestEvent?->scanned_at 
                    ? Carbon::parse($latestEvent->scanned_at)->format('Y-m-d H:i:s') 
                    : null;

                return [
                    'child_id'           => (int) $childId,
                    'child_name'         => $childObj->full_name ?? $childObj->name ?? 'طفل',
                    'name'               => $childObj->full_name ?? $childObj->name ?? 'طفل',
                    'school'             => $schoolName,
                    'school_name'        => $schoolName,
                    'pickup_address'     => $pickupAddress,
                    'dropoff_address'    => $dropoffAddress,
                    'pickup_time'        => $pickupTime,
                    'dropoff_time'       => $dropoffTime,
                    'scanned_pickup_at'  => $pickupEvent?->scanned_at ? Carbon::parse($pickupEvent->scanned_at)->format('Y-m-d H:i:s') : null,
                    'scanned_dropoff_at' => $dropoffEvent?->scanned_at ? Carbon::parse($dropoffEvent->scanned_at)->format('Y-m-d H:i:s') : null,
                    'status'             => $status,
                    'reason'             => $reason,
                    'pickup_status'      => $pickupEvent ? 'completed' : ($absentEvent ? ($status === 'skipped' ? 'skipped' : 'absent') : ($status === 'absent' ? 'absent' : ($status === 'skipped' ? 'skipped' : 'pending'))),
                    'dropoff_status'     => $dropoffEvent ? 'completed' : 'pending',
                    'action_type'        => $latestEvent->action_type ?? $status,
                    'scanned_at'         => $scannedAt,
                ];
            })->values();

            $routeName = $trip->route?->route_name
                ?? $trip->route?->formatted_route_name
                ?? RouteModel::generateGenericRouteName(null, 'both', $trip->trip_type);

            $actualStartedAt = $trip->started_at ?? $trip->actual_start_time;
            $actualCompletedAt = $trip->completed_at;

            $duration = 40;
            if ($actualStartedAt && $actualCompletedAt) {
                $duration = (int) Carbon::parse($actualStartedAt)->diffInMinutes(Carbon::parse($actualCompletedAt));
            } elseif ($trip->route && $trip->route->estimated_duration) {
                $duration = (int) $trip->route->estimated_duration;
            }

            $totalStudents = $children->count();
            $pickedUpCount = $children->filter(fn($c) => in_array($c['status'], ['completed', 'picked_up']))->count();
            $absentCount = $children->filter(fn($c) => in_array($c['status'], ['absent', 'skipped']))->count();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'trip_id'             => (int) $trip->id,
                    'trip_date'           => $trip->trip_date ? Carbon::parse($trip->trip_date)->format('Y-m-d') : Carbon::parse($trip->created_at)->format('Y-m-d'),
                    'route_name'          => $routeName,
                    'status'              => 'completed',
                    'actual_started_at'   => $actualStartedAt ? Carbon::parse($actualStartedAt)->format('Y-m-d H:i:s') : null,
                    'actual_completed_at' => $actualCompletedAt ? Carbon::parse($actualCompletedAt)->format('Y-m-d H:i:s') : null,
                    'duration'            => $duration,
                    'distance'            => $this->calculateTripDistanceKm($trip),
                    'summary'             => [
                        'total_students' => $totalStudents,
                        'picked_up'      => $pickedUpCount,
                        'absent'         => $absentCount,
                    ],
                    'children'            => $children,
                ]
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'الرحلة غير موجودة.'], 404);
        }
    }
}