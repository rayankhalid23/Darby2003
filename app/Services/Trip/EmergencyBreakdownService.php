<?php

namespace App\Services\Trip;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\Route;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripStop;
use App\Models\Shared\Zone;
use App\Models\User;
use App\Services\Notification\NotificationFormatter;
use App\Services\Notification\NotificationService;
use App\Services\Shared\FinancialLedgerService;
use App\Services\Shared\OsrmRoutingService;
use App\Support\GeoEstimator;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmergencyBreakdownService
{
    protected NotificationService $notificationService;
    protected FinancialLedgerService $financialLedgerService;
    protected OsrmRoutingService $osrmService;

    public function __construct(
        NotificationService $notificationService,
        FinancialLedgerService $financialLedgerService,
        OsrmRoutingService $osrmService
    ) {
        $this->notificationService = $notificationService;
        $this->financialLedgerService = $financialLedgerService;
        $this->osrmService = $osrmService;
    }

    /**
     * الإبلاغ عن تعطل المركبة وبدء عملية البحث الفوري عن سائقين بدلاء.
     */
    public function reportBreakdown(
        Trip $trip,
        ?float $breakdownLat = null,
        ?float $breakdownLng = null,
        ?string $reason = null,
        int $timeoutMinutes = 10
    ): array {
        return DB::transaction(function () use ($trip, $breakdownLat, $breakdownLng, $reason, $timeoutMinutes) {
            // 1. تحديد موقع العطل
            $lat = $breakdownLat ?? $trip->start_lat ?? $trip->driver?->current_lat ?? 32.8872;
            $lng = $breakdownLng ?? $trip->start_lng ?? $trip->driver?->current_lng ?? 13.1913;

            // 2. تحديث حالة الرحلة الأصلية إلى معطلة/متوقفة
            $trip->update([
                'status'            => 'suspended_breakdown',
                'suspension_reason' => $reason ?? 'عطل طارئ وتوقف للمركبة',
            ]);

            // 3. حصر الأطفال العالقين في الحافلة (محطات pending أو boarded)
            $strandedStops = TripStop::where('trip_id', $trip->id)
                ->whereNotNull('child_id')
                ->whereIn('status', [TripStop::STATUS_PENDING, TripStop::STATUS_BOARDED])
                ->with(['child.parent.user', 'child.school', 'child.address'])
                ->get();

            $strandedChildIds = $strandedStops->pluck('child_id')->unique()->values()->all();
            $strandedChildrenCount = count($strandedChildIds);

            // 4. احتساب قيمة المشوار المالي لهؤلاء الأطفال لنقلها لاحقاً للسائق البديل
            $tripFareAmount = $this->calculateStrandedChildrenTripFare($trip, $strandedChildIds);

            // إذا لم يكن هناك أطفال عالقون في الحافلة
            if ($strandedChildrenCount === 0) {
                return [
                    'status'                  => 'success',
                    'message'                 => 'تم تسجيل توقف الرحلة، لا يوجد أطفال عالقون في الحافلة حالياً.',
                    'trip_id'                 => $trip->id,
                    'breakdown_location'      => [
                        'latitude'  => (float) $lat,
                        'longitude' => (float) $lng,
                        'lat'       => (float) $lat,
                        'lng'       => (float) $lng,
                        'maps_url'  => "https://maps.google.com/?q={$lat},{$lng}",
                    ],
                    'stranded_children_count' => 0,
                    'dispatch'                => null,
                ];
            }

            // 5. إنشاء سجل طلب الاستبدال الطارئ
            $dispatch = TripBreakdownDispatch::create([
                'trip_id'                 => $trip->id,
                'original_driver_id'      => $trip->driver_id,
                'status'                  => TripBreakdownDispatch::STATUS_PENDING,
                'breakdown_lat'           => $lat,
                'breakdown_lng'           => $lng,
                'reason'                  => $reason,
                'stranded_children_ids'   => $strandedChildIds,
                'stranded_children_count' => $strandedChildrenCount,
                'trip_fare_amount'        => $tripFareAmount,
                'dispatched_at'           => now(),
                'expires_at'              => now()->addMinutes($timeoutMinutes),
            ]);

            // 6. البحث عن السائقين البدلاء المؤهلين في المنطقة والمناطق المجاورة
            $candidateDrivers = $this->findEligibleSubstituteDrivers($lat, $lng, $strandedChildrenCount, $trip);

            if ($candidateDrivers->isEmpty()) {
                // ⚠️ لا يوجد سائقون بدلاء متاحون — تشغيل سيناريو الطوارئ البديل فوراً
                $this->triggerFallbackParentPickup($dispatch, $trip);

                return [
                    'status'                  => 'no_substitutes_available',
                    'message'                 => 'تعذر العثور على سائق بديل متاح حالياً. تم إشعار السائق بالتواصل مع أولياء الأمور وإشعار أولياء الأمور بموقع الأبناء الحي لاستلامهم.',
                    'trip_id'                 => $trip->id,
                    'dispatch_id'             => $dispatch->id,
                    'breakdown_location'      => [
                        'latitude'  => (float) $lat,
                        'longitude' => (float) $lng,
                        'lat'       => (float) $lat,
                        'lng'       => (float) $lng,
                        'maps_url'  => "https://maps.google.com/?q={$lat},{$lng}",
                    ],
                    'stranded_children_count' => $strandedChildrenCount,
                    'candidates_count'        => 0,
                ];
            }

            // 7. تحديث السائقين المرشحين وبث الإشعارات لهم
            $candidateDriverIds = $candidateDrivers->pluck('id')->values()->all();
            $dispatch->update([
                'status'               => TripBreakdownDispatch::STATUS_BROADCASTED,
                'candidate_driver_ids' => $candidateDriverIds,
            ]);

            $this->broadcastToCandidateDrivers($dispatch, $candidateDrivers, $trip);

            // 8. إشعار مبدئي لأولياء الأمور بتوقف الرحلة والبحث عن بديل
            $this->notifyParentsInitialSuspension($trip, $strandedStops);

            return [
                'status'                  => 'broadcasted',
                'message'                 => 'تم تسجيل توقف الرحلة وإرسال طلبات الإنقاذ العاجلة لـ ' . count($candidateDriverIds) . ' سائق بديل مؤهل في المنطقة.',
                'trip_id'                 => $trip->id,
                'dispatch_id'             => $dispatch->id,
                'breakdown_location'      => [
                    'latitude'  => (float) $lat,
                    'longitude' => (float) $lng,
                    'lat'       => (float) $lat,
                    'lng'       => (float) $lng,
                    'maps_url'  => "https://maps.google.com/?q={$lat},{$lng}",
                ],
                'stranded_children_count' => $strandedChildrenCount,
                'candidates_count'        => count($candidateDriverIds),
                'candidate_driver_ids'    => $candidateDriverIds,
                'trip_fare_amount'        => $tripFareAmount,
            ];
        });
    }

    /**
     * البحث الذكي عن السائقين البدلاء المؤهلين في المنطقة الحالية والمناطق المجاورة
     */
    public function findEligibleSubstituteDrivers(
        float $breakdownLat,
        float $breakdownLng,
        int $neededSeats,
        Trip $originalTrip
    ): Collection {
        // 1. تحديد المناطق المحيطة بموقع العطل
        $nearbyZoneIds = $this->resolveNearbyZoneIds($breakdownLat, $breakdownLng, $originalTrip);

        // 2. استعلام السائقين النشطين والمعتمدين
        $query = Driver::query()
            ->whereIn('status', ['Approved', 'Active'])
            ->where('id', '!=', $originalTrip->driver_id)
            ->whereDoesntHave('documents', function ($q) {
                $q->whereIn('doc_type', ['LICENSE', 'INSURANCE', 'STAMP', 'TECHNICAL_INSPECTION'])
                  ->where('status', 'Expired');
            })
            ->with(['user', 'vehicles', 'zones.subMunicipality', 'seatSlots', 'routes']);

        // فلترة بالمناطق المجاورة إن وُجدت
        if (!empty($nearbyZoneIds)) {
            $query->where(function ($q) use ($nearbyZoneIds, $breakdownLat, $breakdownLng) {
                $q->whereHas('zones', fn($zq) => $zq->whereIn('zones.id', $nearbyZoneIds))
                  ->orWhereRaw(
                      '(6371 * acos(cos(radians(?)) * cos(radians(current_lat)) * cos(radians(current_lng) - radians(?)) + sin(radians(?)) * sin(radians(current_lat)))) <= 15',
                      [$breakdownLat, $breakdownLng, $breakdownLat]
                  );
            });
        }

        $candidates = $query->get();

        // 3. التصفية المتقدمة: السعة + ملاءمة المسار والوقت
        $shiftSlot = $originalTrip->shift_slot ?? 'morning_go';

        return $candidates->filter(function (Driver $driver) use ($neededSeats, $shiftSlot, $breakdownLat, $breakdownLng, $originalTrip) {
            // أ) فحص السعة المتاحة
            $hasCapacity = $this->checkDriverSeatCapacity($driver, $neededSeats, $shiftSlot);
            if (!$hasCapacity) {
                return false;
            }

            // ب) فحص ملاءمة المسار (Route Feasibility) لعدم الإخلال بمساره الحالي
            $feasible = $this->checkDriverRouteFeasibility($driver, $breakdownLat, $breakdownLng, $originalTrip);
            return $feasible;
        })->values();
    }

    /**
     * التحقق من توفر مقاعد شاغرة لدى السائق
     */
    protected function checkDriverSeatCapacity(Driver $driver, int $neededSeats, string $shiftSlot): bool
    {
        // فحص DriverSeatSlot إن وجد
        $slot = $driver->seatSlots->firstWhere('slot', $shiftSlot);
        if ($slot) {
            return (int) $slot->available_seats >= $neededSeats;
        }

        // فحص سعة المركبة النشطة مقارنة بالطلاب المسجلين
        $activeVehicle = $driver->vehicles->where('status', 'Active')->first() ?? $driver->vehicles->first();
        $capacity = (int) ($activeVehicle?->capacity_manual ?? $activeVehicle?->capacity ?? 10);
        $currentSubsCount = ActiveSubscription::forDriver($driver->id)
            ->where('status', '!=', 'cancelled')
            ->count();

        $available = max(0, $capacity - $currentSubsCount);
        return $available >= $neededSeats;
    }

    /**
     * التحقق من أن مسار السائق لن يتعطل أو يتجاوز الحد الأقصى للوقت عند استلام الأطفال
     */
    protected function checkDriverRouteFeasibility(Driver $driver, float $breakdownLat, float $breakdownLng, Trip $originalTrip): bool
    {
        // السائق إذا كان لديه موقع حالي، نفحص المسافة بينه وبين موقع العطل
        if ($driver->current_lat && $driver->current_lng) {
            $distKm = GeoEstimator::haversineKm($driver->current_lat, $driver->current_lng, $breakdownLat, $breakdownLng);
            // إذا كان بعيداً جداً عن العطل (أكثر من 20 كم) يعتبر غير ملائم زمنياً
            if ($distKm > 20.0) {
                return false;
            }
        }

        // فحص المسار النشط للسائق
        $activeRoute = Route::where('driver_id', $driver->id)
            ->where('status', 'Active')
            ->first();

        if ($activeRoute && $activeRoute->estimated_duration) {
            // إذا كان مسار السائق الحالي ممتلئاً جداً وزمنه يتجاوز 60 دقيقة
            if ((int) $activeRoute->estimated_duration > 65) {
                return false;
            }
        }

        return true;
    }

    /**
     * قبول مهمة الاستبدال الطارئة من قبل أول سائق يوافق
     */
    public function acceptBreakdownDispatch(int $dispatchId, int $substituteDriverId): array
    {
        return DB::transaction(function () use ($dispatchId, $substituteDriverId) {
            /** @var TripBreakdownDispatch $dispatch */
            $dispatch = TripBreakdownDispatch::where('id', $dispatchId)
                ->lockForUpdate()
                ->firstOrFail();

            // فحص هل تم قبول الطلب مسبقاً أو إلغاؤه
            if ($dispatch->status === TripBreakdownDispatch::STATUS_ACCEPTED) {
                throw new Exception('عذراً، تم قبول هذه المهمة الطارئة بالفعل من قِبل سائق آخر.', 409);
            }

            if (in_array($dispatch->status, [
                TripBreakdownDispatch::STATUS_COMPLETED,
                TripBreakdownDispatch::STATUS_CANCELLED,
                TripBreakdownDispatch::STATUS_EXPIRED
            ])) {
                throw new Exception('هذا الطلب الطارئ لم يعد متاحاً.', 422);
            }

            $substituteDriver = Driver::with(['user', 'vehicles'])->findOrFail($substituteDriverId);
            $originalTrip = Trip::with(['driver.user', 'stops'])->findOrFail($dispatch->trip_id);

            // 1. تحديث حالة الطلب وحسمه لهذا السائق البديل
            $dispatch->update([
                'status'               => TripBreakdownDispatch::STATUS_ACCEPTED,
                'substitute_driver_id' => $substituteDriverId,
                'accepted_at'          => now(),
            ]);

            // 2. إلغاء الطلب فوراً لبقية السائقين المرشحين وإرسال إشعار بذلك
            $this->dismissOtherCandidates($dispatch, $substituteDriverId);

            // 3. تحديث مسار السائق البديل ومحطاته فوراً لضم محطة الالتقاط ومحطات الأطفال العالقين
            $substituteTrip = $this->integrateStrandedChildrenIntoSubstituteRoute($dispatch, $substituteDriver, $originalTrip);
            $dispatch->update(['substitute_trip_id' => $substituteTrip->id]);

            // 4. إرسال إشعار لأولياء الأمور بتفاصيل السائق البديل (الاسم، الهاتف، المركبة)
            $this->notifyParentsSubstituteAssigned($dispatch, $substituteDriver, $originalTrip);

            // 5. إشعار السائق الأصلي بأن السائق البديل قبل المهمة
            $this->notifyOriginalDriverSubstituteAccepted($originalTrip, $substituteDriver);

            return [
                'status'             => 'success',
                'message'            => 'تم قبول المهمة الطارئة بنجاح، وتحديث خط سيرك فوراً بنقاط استلام وتسليم الأطفال.',
                'dispatch_id'        => $dispatch->id,
                'substitute_trip_id' => $substituteTrip->id,
                'substitute_driver'  => [
                    'id'    => $substituteDriver->id,
                    'name'  => $substituteDriver->user?->full_name,
                    'phone' => $substituteDriver->user?->phone_number,
                ],
            ];
        });
    }

    /**
     * رفض المهمة الطارئة من قبل سائق مرشح
     */
    public function rejectBreakdownDispatch(int $dispatchId, int $driverId): array
    {
        return DB::transaction(function () use ($dispatchId, $driverId) {
            /** @var TripBreakdownDispatch $dispatch */
            $dispatch = TripBreakdownDispatch::where('id', $dispatchId)->lockForUpdate()->firstOrFail();

            if ($dispatch->status !== TripBreakdownDispatch::STATUS_BROADCASTED) {
                return ['status' => 'ignored', 'message' => 'الطلب غير متاح حالياً.'];
            }

            $rejected = $dispatch->rejected_driver_ids ?? [];
            if (!in_array($driverId, $rejected)) {
                $rejected[] = $driverId;
            }

            $candidates = $dispatch->candidate_driver_ids ?? [];
            $dispatch->rejected_driver_ids = $rejected;

            // إذا رفض جميع السائقين المرشحين
            $remaining = array_diff($candidates, $rejected);
            if (empty($remaining)) {
                $dispatch->status = TripBreakdownDispatch::STATUS_DECLINED_ALL;
                $dispatch->save();

                $trip = Trip::find($dispatch->trip_id);
                if ($trip) {
                    $this->triggerFallbackParentPickup($dispatch, $trip);
                }
            } else {
                $dispatch->save();
            }

            return ['status' => 'rejected', 'message' => 'تم تسجيل رفض المهمة.'];
        });
    }

    /**
     * تحديث خط سير السائق البديل فوراً ودمج محطات استلام وتسليم الأطفال العالقين
     */
    protected function integrateStrandedChildrenIntoSubstituteRoute(
        TripBreakdownDispatch $dispatch,
        Driver $substituteDriver,
        Trip $originalTrip
    ): Trip {
        $today = Carbon::today()->toDateString();

        // 1. البحث عن رحلة قائمة للسائق البديل اليوم أو إنشاء رحلة إنقاذ
        $substituteTrip = Trip::where('driver_id', $substituteDriver->id)
            ->where('status', 'in_progress')
            ->whereDate('trip_date', $today)
            ->first();

        if (!$substituteTrip) {
            // البحث عن مسار مفعل للسائق البديل
            $altRoute = Route::where('driver_id', $substituteDriver->id)
                ->where('status', 'Active')
                ->first();

            $substituteTrip = Trip::create([
                'driver_id'           => $substituteDriver->id,
                'trip_type'           => $originalTrip->trip_type,
                'shift_slot'          => $originalTrip->shift_slot,
                'status'              => 'in_progress',
                'route_id'            => $altRoute?->id ?? $originalTrip->route_id,
                'scheduled_start_time'=> now(),
                'actual_start_time'   => now(),
                'scheduled_at'        => now(),
                'start_lat'           => $substituteDriver->current_lat ?? $dispatch->breakdown_lat,
                'start_lng'           => $substituteDriver->current_lng ?? $dispatch->breakdown_lng,
                'trip_date'           => $today,
            ]);
        }

        // 2. جلب محطات الأطفال العالقين من الرحلة الأصلية
        $strandedChildIds = $dispatch->stranded_children_ids ?? [];
        $originalStops = TripStop::where('trip_id', $originalTrip->id)
            ->whereIn('child_id', $strandedChildIds)
            ->get();

        // 3. إضافة محطة الالتقاط الموحدة من موقع الحافلة المعطلة
        $highestSeq = TripStop::where('trip_id', $substituteTrip->id)->max('sequence_order') ?? 0;

        TripStop::create([
            'trip_id'        => $substituteTrip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => null,
            'lat'            => $dispatch->breakdown_lat,
            'lng'            => $dispatch->breakdown_lng,
            'label'          => 'نقطة استلام الأطفال من الحافلة المتوقفة',
            'sequence_order' => ++$highestSeq,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        // 4. إضافة محطات الوجهة لكل طفل عالق
        foreach ($originalStops as $origStop) {
            TripStop::create([
                'trip_id'        => $substituteTrip->id,
                'stop_type'      => $origStop->stop_type,
                'child_id'       => $origStop->child_id,
                'school_id'      => $origStop->school_id,
                'lat'            => $origStop->lat,
                'lng'            => $origStop->lng,
                'label'          => $origStop->label . ' (استكمال إنقاذ)',
                'sequence_order' => ++$highestSeq,
                'status'         => TripStop::STATUS_PENDING,
            ]);
        }

        // 5. إعادة حساب ترتيب المحطات والأوقات التقديرية الحية (Live ETAs)
        $subLat = $substituteDriver->current_lat ?? $dispatch->breakdown_lat;
        $subLng = $substituteDriver->current_lng ?? $dispatch->breakdown_lng;

        if ($subLat && $subLng) {
            app(TripLifecycleService::class)->computeLiveEtas($substituteTrip, (float) $subLat, (float) $subLng);
        }

        return $substituteTrip;
    }

    /**
     * إرسال إشعار لأولياء الأمور بتعيين سائق بديل مع كامل بياناته
     */
    protected function notifyParentsSubstituteAssigned(
        TripBreakdownDispatch $dispatch,
        Driver $substituteDriver,
        Trip $originalTrip
    ): void {
        $strandedChildIds = $dispatch->stranded_children_ids ?? [];
        if (empty($strandedChildIds)) {
            return;
        }

        $parentUserIds = Child::whereIn('id', $strandedChildIds)
            ->pluck('parent_id')
            ->unique();

        $users = User::whereIn('id', $parentUserIds)->get();
        if ($users->isEmpty()) {
            return;
        }

        $subVehicle = $substituteDriver->vehicles->where('status', 'Active')->first() ?? $substituteDriver->vehicles->first();
        $subDriverName = $substituteDriver->user?->full_name ?? 'السائق البديل';
        $subDriverPhone = $substituteDriver->user?->phone_number ?? '';
        $vehicleInfo = $subVehicle ? "مركبة: {$subVehicle->make} {$subVehicle->model} (لوحة: {$subVehicle->plate_number})" : '';

        $this->notificationService->sendToUsers($users, NotificationFormatter::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_PARENT, [
            'title'                  => '🔄 تم تعيين سائق بديل للرحلة',
            'substitute_driver_name' => $subDriverName,
            'substitute_phone'       => $subDriverPhone,
            'vehicle_info'           => $vehicleInfo,
            'trip_id'                => (string) $originalTrip->id,
            'message'                => "توقفت الحافلة بسبب عطل طارئ، وتم تكليف السائق البديل ({$subDriverName} - {$subDriverPhone}) لاستكمال نقل الأبناء بسلام. {$vehicleInfo}",
            'entity_id'              => (string) $originalTrip->id,
        ]);
    }

    /**
     * إشعار السائق الأصلي بأن السائق البديل وافق
     */
    protected function notifyOriginalDriverSubstituteAccepted(Trip $originalTrip, Driver $substituteDriver): void
    {
        $originalDriverUser = $originalTrip->driver?->user;
        if (!$originalDriverUser) {
            return;
        }

        $subDriverName = $substituteDriver->user?->full_name ?? 'السائق البديل';
        $subDriverPhone = $substituteDriver->user?->phone_number ?? '';

        $this->notificationService->sendToUser($originalDriverUser, NotificationFormatter::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_ORIGINAL, [
            'title'                  => '✅ تم قبول مهمة الإنقاذ',
            'substitute_driver_name' => $subDriverName,
            'substitute_phone'       => $subDriverPhone,
            'trip_id'                => (string) $originalTrip->id,
            'message'                => "وافق السائق البديل ({$subDriverName} - {$subDriverPhone}) على استلام ونقل الطلاب العالقين، وهو في طريقه لموقعك الآن.",
            'entity_id'              => (string) $originalTrip->id,
        ]);
    }

    /**
     * سيناريو الطوارئ البديل عند عدم توفر سائق بديل أو انتهاء المهلة:
     * 1. إشعار السائق الأصلي بالاتصال بأولياء الأمور.
     * 2. إشعار أولياء الأمور بموقع الأبناء الحي والطلب منهم الحضور لاستلامهم ("تعال خذ ابنك").
     */
    public function triggerFallbackParentPickup(TripBreakdownDispatch $dispatch, Trip $trip): void
    {
        $dispatch->update(['status' => TripBreakdownDispatch::STATUS_UNRESOLVED]);

        $lat = $dispatch->breakdown_lat ?? $trip->start_lat ?? 32.8872;
        $lng = $dispatch->breakdown_lng ?? $trip->start_lng ?? 13.1913;
        $mapsUrl = "https://maps.google.com/?q={$lat},{$lng}";

        // 1. إشعار السائق الأصلي
        $origDriverUser = $trip->driver?->user;
        if ($origDriverUser) {
            $this->notificationService->sendToUser($origDriverUser, NotificationFormatter::TYPE_EMERGENCY_DRIVER_CALL_PARENTS, [
                'title'     => '📞 تعذر توفير بديل - تواصل مع أولياء الأمور',
                'message'   => 'تعذر العثور على سائق بديل متاح حالياً. يرجى التواصل مباشرة وبسرعة مع أولياء الأمور وإرشادهم لموقع الحافلة.',
                'trip_id'   => (string) $trip->id,
                'entity_id' => (string) $trip->id,
            ]);
        }

        // 2. إشعار أولياء الأمور بموقع الأطفال ورابط الخريطة
        $strandedChildIds = $dispatch->stranded_children_ids ?? [];
        if (!empty($strandedChildIds)) {
            $parentUserIds = Child::whereIn('id', $strandedChildIds)
                ->pluck('parent_id')
                ->unique();

            $users = User::whereIn('id', $parentUserIds)->get();
            if ($users->isNotEmpty()) {
                $this->notificationService->sendToUsers($users, NotificationFormatter::TYPE_EMERGENCY_BREAKDOWN_PARENT_PICKUP, [
                    'title'         => '⚠️ تعطل الحافلة: يرجى استلام طفلك',
                    'location_text' => "إحداثيات: {$lat}, {$lng}",
                    'maps_url'      => $mapsUrl,
                    'trip_id'       => (string) $trip->id,
                    'message'       => "توقفت الحافلة بسبب عطل طارئ وتعذر توفير بديل حالياً. الأبناء في أمان، يرجى التوجه لموقعهم لاستلام ابنك: {$mapsUrl}",
                    'entity_id'     => (string) $trip->id,
                ]);
            }
        }
    }

    /**
     * إلغاء الإشعار لبقية السائقين المرشحين بعد قبول أول سائق
     */
    protected function dismissOtherCandidates(TripBreakdownDispatch $dispatch, int $acceptedDriverId): void
    {
        $allCandidates = $dispatch->candidate_driver_ids ?? [];
        $otherDriverIds = array_diff($allCandidates, [$acceptedDriverId]);

        if (empty($otherDriverIds)) {
            return;
        }

        $otherUsers = User::whereHas('driver', fn($q) => $q->whereIn('id', $otherDriverIds))->get();
        if ($otherUsers->isNotEmpty()) {
            $this->notificationService->sendToUsers($otherUsers, NotificationFormatter::TYPE_EMERGENCY_REQUEST_CANCELLED_OTHER, [
                'title'       => 'ℹ️ تم قبول المهمة الطارئة من سائق آخر',
                'message'     => 'شكراً لاستعدادك، تم قبول مهمة نقل الأطفال العالقين من قِبل سائق آخر أسرع استجابة.',
                'dispatch_id' => (string) $dispatch->id,
                'entity_id'   => (string) $dispatch->id,
            ]);
        }
    }

    /**
     * إرسال بث الطوارئ للسائقين المرشحين
     */
    protected function broadcastToCandidateDrivers(
        TripBreakdownDispatch $dispatch,
        Collection $candidateDrivers,
        Trip $trip
    ): void {
        $candidateUserIds = $candidateDrivers->pluck('user_id')->filter()->unique();
        $users = User::whereIn('id', $candidateUserIds)->get();

        if ($users->isNotEmpty()) {
            $count = $dispatch->stranded_children_count;
            $fare = $dispatch->trip_fare_amount;

            $this->notificationService->sendToUsers($users, NotificationFormatter::TYPE_EMERGENCY_SUBSTITUTE_REQUEST, [
                'title'       => '🚨 طلب طارئ: نقل أطفال من حافلة متوقفة',
                'message'     => "يوجد حافلة متوقفة بالقرب منك وبها {$count} أطفال بحاجة لنقل فوري. الأجرة المخصصة للمهمة: {$fare} د.ل. أول من يقبل يحصل على المهمة.",
                'dispatch_id' => (string) $dispatch->id,
                'entity_id'   => (string) $dispatch->id,
                'seats_count' => (string) $count,
                'fare_amount' => (string) $fare,
            ]);
        }
    }

    /**
     * إشعار مبدئي لأولياء الأمور بتوقف الرحلة
     */
    protected function notifyParentsInitialSuspension(Trip $trip, Collection $strandedStops): void
    {
        $parentUserIds = $strandedStops->pluck('child.parent.user_id')->filter()->unique();
        $users = User::whereIn('id', $parentUserIds)->get();

        if ($users->isNotEmpty()) {
            $this->notificationService->sendToUsers($users, NotificationFormatter::TYPE_TRIP_SUSPENDED, [
                'title'     => '🚨 توقف مؤقت للحافلة',
                'message'   => 'حدث عطل طارئ وتوقفت الحافلة، يقوم النظام حالياً بتوجيه أقرب سائق بديل لاستكمال الرحلة وسنوافيكم ببياناته فوراً.',
                'trip_id'   => (string) $trip->id,
                'entity_id' => (string) $trip->id,
            ]);
        }
    }

    /**
     * احتساب أجرة مشوار هؤلاء الأطفال العالقين لتحويلها للسائق البديل
     */
    protected function calculateStrandedChildrenTripFare(Trip $trip, array $strandedChildIds): float
    {
        if (empty($strandedChildIds)) {
            return 0.00;
        }

        $subs = ActiveSubscription::forDriver($trip->driver_id)
            ->forChildren($strandedChildIds)
            ->with('subscriptionRequest')
            ->get();

        $totalFare = 0.00;
        $pricingSetting = PricingSetting::first();
        $defaultTripRate = (float) ($pricingSetting->price_per_km_ac ?? 5.00);

        foreach ($subs as $sub) {
            $req = $sub->subscriptionRequest;
            if ($req && $req->total_price > 0 && $req->children_count > 0) {
                // حصة الطفل من المشوار اليومي
                $workingDays = max(1, (int) ($req->working_days_count ?? 22));
                $childPrice = (float) ($req->children->firstWhere('id', $sub->child_id)?->pivot?->total_amount_after_discount ?? ($req->total_price / $req->children_count));
                $singleTripPrice = round($childPrice / ($workingDays * 2), 2);
                $totalFare += max(2.50, $singleTripPrice);
            } else {
                $totalFare += $defaultTripRate;
            }
        }

        return round($totalFare, 2);
    }

    /**
     * تحديد المناطق المجاورة لموقع العطل
     */
    protected function resolveNearbyZoneIds(float $lat, float $lng, Trip $originalTrip): array
    {
        $driverZones = DB::table('driver_zone')->where('driver_id', $originalTrip->driver_id)->pluck('zone_id')->toArray();
        if (empty($driverZones)) {
            return Zone::pluck('id')->toArray();
        }

        // جلب البلديات الفرعية لهذه المناطق لجلب كل المناطق التابعة لنفس البلدية الفرعية (المناطق المجاورة)
        $subMuniIds = Zone::whereIn('id', $driverZones)->pluck('sub_municipality_id')->filter()->unique();

        return Zone::whereIn('sub_municipality_id', $subMuniIds)->pluck('id')->toArray();
    }

    /**
     * التسوية المالية الفورية: خصم قيمة الرحلة من السائق الأصلي وتحويلها لمحفظة السائق البديل
     */
    public function settleBreakdownFinancialTransfer(TripBreakdownDispatch $dispatch): bool
    {
        if ($dispatch->financial_settled) {
            return true;
        }

        if (!$dispatch->substitute_driver_id || $dispatch->trip_fare_amount <= 0) {
            $dispatch->update(['financial_settled' => true, 'settled_at' => now()]);
            return true;
        }

        return DB::transaction(function () use ($dispatch) {
            $originalDriver = Driver::find($dispatch->original_driver_id);
            $substituteDriver = Driver::find($dispatch->substitute_driver_id);

            if (!$substituteDriver) {
                return false;
            }

            $fareCents = (int) round($dispatch->trip_fare_amount * 100);

            // 1. خصم من محفظة السائق الأصلي (أو قيد مدين)
            if ($originalDriver) {
                try {
                    $origBalance = (int) ($originalDriver->balance ?? 0);
                    $originalDriver->forceWithdraw($fareCents);
                    $newOrigBal = (int) $originalDriver->balance;

                    $this->financialLedgerService->recordLedgerEntry(
                        \App\Services\Shared\FinancialLedgerService::driverAccount($originalDriver),
                        "substitute_driver_pool",
                        $fareCents,
                        'breakdown_substitute_deduction',
                        $origBalance,
                        $newOrigBal,
                        "BREAKDOWN-DEDUCT-{$dispatch->id}",
                        ['dispatch_id' => $dispatch->id, 'trip_id' => $dispatch->trip_id]
                    );
                } catch (\Throwable $e) {
                    Log::warning("فشل الخصم من السائق الأصلي ID {$originalDriver->id} للطلب الطارئ ID {$dispatch->id}: " . $e->getMessage());
                }
            }

            // 2. إيداع فوري في محفظة السائق البديل
            $subBalanceBefore = (int) ($substituteDriver->balance ?? 0);
            $substituteDriver->deposit($fareCents);
            $subBalanceAfter = (int) $substituteDriver->balance;

            try {
                $this->financialLedgerService->recordLedgerEntry(
                    "substitute_driver_pool",
                    \App\Services\Shared\FinancialLedgerService::driverAccount($substituteDriver),
                    $fareCents,
                    'breakdown_substitute_payout',
                    $subBalanceBefore,
                    $subBalanceAfter,
                    "BREAKDOWN-PAYOUT-{$dispatch->id}",
                    [
                        'dispatch_id'          => $dispatch->id,
                        'original_driver_id'   => $dispatch->original_driver_id,
                        'substitute_driver_id' => $substituteDriver->id,
                        'fare_amount'          => $dispatch->trip_fare_amount,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("فشل تسجيل حركة السجل المالي لإيداع السائق البديل: " . $e->getMessage());
            }

            // 3. تحديث حالة التسوية في الطلب
            $dispatch->update([
                'financial_settled' => true,
                'settled_at'        => now(),
                'status'            => TripBreakdownDispatch::STATUS_COMPLETED,
                'completed_at'      => now(),
            ]);

            return true;
        });
    }
}
