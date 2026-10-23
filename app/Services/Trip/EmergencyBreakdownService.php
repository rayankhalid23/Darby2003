<?php

namespace App\Services\Trip;

use App\Http\Controllers\Api\Shared\MediaController;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\Child;
use App\Models\Parent\School;
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
        int $timeoutMinutes = 60
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

            // 3. حصر جميع الأطفال المعنيين بالرحلة المتبقين (من صعد الحافلة ومن ينتظر في منزله)
            // لنقلهم جميعاً إلى السائق البديل لاستكمال الرحلة كاملة
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

            // 9. إشعار فوري لمشرفي النظام (Admin) بحالة الطوارئ
            $this->notifyAdminsBreakdown($trip, $dispatch, $strandedChildrenCount);

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
        // شرط حاسم: وثائق سارية بالكامل + منع أي سائق لديه رحلة في ذلك التوقيت منعاً باتاً
        $todayStr = Carbon::today()->toDateString();
        $tripDate = $originalTrip->trip_date ? Carbon::parse($originalTrip->trip_date)->toDateString() : $todayStr;
        $shiftSlot = $originalTrip->shift_slot;

        $query = Driver::query()
            ->whereIn('status', ['Approved', 'Active'])
            ->where('id', '!=', $originalTrip->driver_id)
            // أ) رخصة القيادة سارية
            ->where(function ($q) use ($todayStr) {
                $q->whereNull('license_expiry')
                  ->orWhere('license_expiry', '>=', $todayStr);
            })
            // ب) منع أي سائق لديه رحلة في ذلك التوقيت منعاً باتاً:
            // يتم إرسال طلب الطوارئ حصراً للسائقين الذين ليس لديهم أي رحلة أساساً في ذلك التوقيت
            ->whereDoesntHave('trips', function ($tq) use ($tripDate, $shiftSlot) {
                $tq->where(function ($sub) use ($tripDate, $shiftSlot) {
                    $sub->where(function ($dq) use ($tripDate) {
                        $dq->whereDate('trip_date', $tripDate)
                           ->orWhereDate('scheduled_at', $tripDate);
                    })
                    ->whereNotIn('status', ['Cancelled', 'cancelled']);

                    if (!empty($shiftSlot)) {
                        $sub->where(function ($sq) use ($shiftSlot) {
                            $sq->where('shift_slot', $shiftSlot)
                               ->orWhereNull('shift_slot');
                        });
                    }
                })
                // أو لديه أي رحلة جارية/نشطة حالياً
                ->orWhere(function ($sub) use ($tripDate) {
                    $sub->whereIn('status', ['in_progress', 'InProgress', 'started'])
                        ->where(function ($dq) use ($tripDate) {
                            $dq->whereDate('trip_date', $tripDate)
                               ->orWhereDate('scheduled_at', $tripDate);
                        });
                });
            })
            // ج) تجنب أي وثيقة منتهية الصلاحية (سواء في driver_documents أو vehicle_documents)
            ->whereDoesntHave('documents', function ($q) use ($todayStr) {
                $q->where(function ($sub) use ($todayStr) {
                    $sub->whereIn('state', ['expired', 'Expired'])
                        ->orWhere('status', 'Expired')
                        ->orWhere(function ($exp) use ($todayStr) {
                            $exp->whereNotNull('expiry_date')
                                ->where('expiry_date', '<', $todayStr);
                        });
                });
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

        // 3. التصفية المتقدمة: السعة + القرب المكاني وملاءمة الوقت
        $shiftSlot = $originalTrip->shift_slot ?? 'morning_go';

        return $candidates->filter(function (Driver $driver) use ($neededSeats, $shiftSlot, $breakdownLat, $breakdownLng, $originalTrip) {
            // أ) فحص السعة المتاحة
            $hasCapacity = $this->checkDriverSeatCapacity($driver, $neededSeats, $shiftSlot);
            if (!$hasCapacity) {
                return false;
            }

            // ب) فحص القرب وملاءمة المسار
            $feasible = $this->checkDriverRouteFeasibility($driver, $breakdownLat, $breakdownLng, $originalTrip);
            return $feasible;
        })->values();
    }

    /**
     * التحقق من توفر مقاعد شاغرة لدى السائق
     */
    protected function checkDriverSeatCapacity(Driver $driver, int $neededSeats, string $shiftSlot): bool
    {
        // السعة تُشتق من المركبة النشطة، والمقاعد المحجوزة تُقرأ من DriverSeatSlot
        // لخانة (سائق × فترة × تاريخ اليوم) — نفس المنطق المستخدم في باقي النظام
        // (RouteFeasibilityService/DriverSeatSlot::available). عمود available_seats
        // لم يعد موجوداً على الجدول منذ التطبيع V2.
        $activeVehicle = $driver->vehicles->where('status', 'Active')->first() ?? $driver->vehicles->first();
        $capacity = (int) ($activeVehicle?->capacity_manual ?? $activeVehicle?->capacity ?? 10);

        if ($capacity <= 0) {
            return false;
        }

        $available = DriverSeatSlot::available($driver->id, $shiftSlot, Carbon::today()->toDateString(), $capacity);

        return $available >= $neededSeats;
    }

    /**
     * التحقق من أن السائق قريب ومساره لن يتعطل عند استلام الأطفال
     */
    protected function checkDriverRouteFeasibility(Driver $driver, float $breakdownLat, float $breakdownLng, Trip $originalTrip): bool
    {
        // السائق إذا كان لديه موقع حالي، نفحص المسافة بينه وبين موقع العطل (يجب أن يكون قريباً - أقصاها 15 كم)
        if ($driver->current_lat && $driver->current_lng) {
            $distKm = GeoEstimator::haversineKm($driver->current_lat, $driver->current_lng, $breakdownLat, $breakdownLng);
            if ($distKm > 15.0) {
                return false;
            }
        }

        // فحص المسار النشط للسائق إن وُجد
        $activeRoute = Route::where('driver_id', $driver->id)
            ->where('status', 'Active')
            ->first();

        if ($activeRoute && $activeRoute->estimated_duration) {
            // إذا كان زمن مساره يتجاوز 60 دقيقة يعتبر غير ملائم زمنياً
            if ((int) $activeRoute->estimated_duration > 60) {
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

            // منع أي سائق لديه رحلة في ذلك التوقيت منعاً باتاً من قبول المهمة
            $todayStr = Carbon::today()->toDateString();
            $tripDate = $originalTrip->trip_date ? Carbon::parse($originalTrip->trip_date)->toDateString() : $todayStr;
            $shiftSlot = $originalTrip->shift_slot;

            $hasTripAtTime = Trip::where('driver_id', $substituteDriverId)
                ->where(function ($dq) use ($tripDate) {
                    $dq->whereDate('trip_date', $tripDate)
                       ->orWhereDate('scheduled_at', $tripDate);
                })
                ->whereNotIn('status', ['Cancelled', 'cancelled'])
                ->when($shiftSlot, fn($q) => $q->where('shift_slot', $shiftSlot))
                ->exists();

            $hasActiveTrip = Trip::where('driver_id', $substituteDriverId)
                ->whereIn('status', ['in_progress', 'InProgress', 'started'])
                ->where(function ($dq) use ($tripDate) {
                    $dq->whereDate('trip_date', $tripDate)
                       ->orWhereDate('scheduled_at', $tripDate);
                })
                ->exists();

            if ($hasActiveTrip) {
                $hasCapacity = $this->checkDriverSeatCapacity($substituteDriver, (int) $dispatch->stranded_children_count, $shiftSlot ?? 'morning_go');
                if (!$hasCapacity) {
                    throw new Exception('لا يمكنك قبول هذه المهمة الطارئة لأن لديك رحلة أخرى ولا توجد مقاعد كافية.', 422);
                }
            } elseif ($hasTripAtTime) {
                throw new Exception('لا يمكنك قبول هذه المهمة الطارئة لأن لديك رحلة أخرى في هذا التوقيت.', 422);
            }

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

            // تثبيت وقت انتهاء رحلة السائق الأصلي المعطلة فور قبول السائق البديل
            if (!$originalTrip->completed_at) {
                $originalTrip->update([
                    'completed_at' => now(),
                ]);
            }

            // 4. إرسال إشعار لأولياء الأمور بتفاصيل السائق البديل (الاسم، الهاتف، المركبة)
            $this->notifyParentsSubstituteAssigned($dispatch, $substituteDriver, $originalTrip);

            // 5. إشعار السائق الأصلي بأن السائق البديل قبل المهمة
            $this->notifyOriginalDriverSubstituteAccepted($originalTrip, $substituteDriver);

            // 6. إشعار مشرفي النظام (Admin) بنجاح تعيين السائق البديل
            $this->notifyAdminsSubstituteAccepted($originalTrip, $dispatch, $substituteDriver);

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

        // 2. تصنيف الأطفال: من صعد الحافلة المعطلة (boarded) ومن ينتظر في منزله (pending)
        $strandedChildIds = $dispatch->stranded_children_ids ?? [];

        // الأطفال الذين صعدوا في الحافلة المعطلة بالفعل
        $boardedChildIds = TripStop::where('trip_id', $originalTrip->id)
            ->whereIn('child_id', $strandedChildIds)
            ->where('status', TripStop::STATUS_BOARDED)
            ->orderBy('child_id')
            ->pluck('child_id')
            ->unique()
            ->values()
            ->all();

        // الأطفال الذين ما زالوا في انتظار الحافلة في منازلهم ولم يتم التقاطهم بعد
        $waitingChildIds = array_values(array_diff($strandedChildIds, $boardedChildIds));

        $highestSeq = TripStop::where('trip_id', $substituteTrip->id)->max('sequence_order') ?? 0;

        $isGoDirection = in_array($substituteTrip->trip_type, ['Morning', 'ذهاب']) 
            || in_array($substituteTrip->shift_slot, ['morning_go', 'afternoon_go']);

        if ($isGoDirection) {
            // ==========================================
            // أ) رحلة الذهاب (Morning / Go: من المنازل إلى المدارس)
            // ==========================================

            // 3. محطة السيارة المتوقفة: فقط إذا كان هناك أطفال صعدوا بالفعل داخل الحافلة المعطلة!
            if (!empty($boardedChildIds)) {
                $hasCarStop = TripStop::where('trip_id', $substituteTrip->id)
                    ->where('label', 'like', '%السيارة المتوقفة%')
                    ->exists();

                if (!$hasCarStop) {
                    TripStop::create([
                        'trip_id'        => $substituteTrip->id,
                        'stop_type'      => TripStop::TYPE_HOME,
                        'child_id'       => null,
                        'lat'            => $dispatch->breakdown_lat,
                        'lng'            => $dispatch->breakdown_lng,
                        'label'          => 'السيارة المتوقفة (استلام الطلاب)',
                        'sequence_order' => ++$highestSeq,
                        'status'         => TripStop::STATUS_PENDING,
                        'reason'         => json_encode(['boarded_child_ids' => $boardedChildIds]),
                    ]);
                }
            }

            // 4. إضافة محطات منازل الأطفال الذين ما زالوا ينتظرون في منازلهم (استكمال التقاطهم من بيوتهم)
            if (!empty($waitingChildIds)) {
                $waitingHomeStops = TripStop::where('trip_id', $originalTrip->id)
                    ->where('stop_type', TripStop::TYPE_HOME)
                    ->whereIn('child_id', $waitingChildIds)
                    ->get();

                foreach ($waitingChildIds as $wId) {
                    $exists = TripStop::where('trip_id', $substituteTrip->id)
                        ->where('stop_type', TripStop::TYPE_HOME)
                        ->where('child_id', $wId)
                        ->exists();

                    if (!$exists) {
                        $origStop = $waitingHomeStops->firstWhere('child_id', $wId);
                        $childObj = Child::with('address')->find($wId);
                        $lat = $origStop?->lat ?? $childObj?->address?->lat ?? 32.8872;
                        $lng = $origStop?->lng ?? $childObj?->address?->lng ?? 13.1913;
                        $label = $origStop?->label ?? ('منزل الطالب ' . ($childObj?->full_name ?? $wId));

                        TripStop::create([
                            'trip_id'        => $substituteTrip->id,
                            'stop_type'      => TripStop::TYPE_HOME,
                            'child_id'       => $wId,
                            'school_id'      => $origStop?->school_id ?? $childObj?->school_id,
                            'lat'            => $lat,
                            'lng'            => $lng,
                            'label'          => $label . ' (استكمال إنقاذ)',
                            'sequence_order' => ++$highestSeq,
                            'status'         => TripStop::STATUS_PENDING,
                        ]);
                    }
                }
            }

            // 5. إضافة وتحديث محطات المدارس (Dropoff) لجميع الأطفال (أطفال البديل + أطفال الحافلة المعطلة + أطفال الانتظار)
            $allTripChildIds = TripStop::where('trip_id', $substituteTrip->id)
                ->whereNotNull('child_id')
                ->pluck('child_id')
                ->merge($strandedChildIds)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $allSchoolIds = Child::whereIn('id', $allTripChildIds)
                ->pluck('school_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $existingSchoolIds = TripStop::where('trip_id', $substituteTrip->id)
                ->where('stop_type', TripStop::TYPE_SCHOOL)
                ->pluck('school_id')
                ->filter()
                ->all();

            $allTargetSchoolIds = array_values(array_unique(array_merge($allSchoolIds, $existingSchoolIds)));

            $origSchoolStops = TripStop::where('trip_id', $originalTrip->id)
                ->where('stop_type', TripStop::TYPE_SCHOOL)
                ->whereIn('school_id', $allTargetSchoolIds)
                ->get()
                ->keyBy('school_id');

            foreach ($allTargetSchoolIds as $sId) {
                $schoolStopExists = TripStop::where('trip_id', $substituteTrip->id)
                    ->where('stop_type', TripStop::TYPE_SCHOOL)
                    ->where('school_id', $sId)
                    ->exists();

                if (!$schoolStopExists) {
                    $origSchoolStop = $origSchoolStops->get($sId);
                    $schoolModel = $origSchoolStop ? null : School::find($sId);

                    $lat = $origSchoolStop?->lat ?? $schoolModel?->lat;
                    $lng = $origSchoolStop?->lng ?? $schoolModel?->lng;
                    $label = $origSchoolStop?->label ?? $schoolModel?->name ?? 'المدرسة';

                    if ($lat && $lng) {
                        TripStop::create([
                            'trip_id'        => $substituteTrip->id,
                            'stop_type'      => TripStop::TYPE_SCHOOL,
                            'child_id'       => null,
                            'school_id'      => $sId,
                            'lat'            => $lat,
                            'lng'            => $lng,
                            'label'          => $label,
                            'sequence_order' => 999,
                            'status'         => TripStop::STATUS_PENDING,
                        ]);
                    }
                }
            }

            // 6. ترتيب محطات الذهاب: جميع نقاط الالتقاط أولاً ثم المدارس مرتبة جغرافياً بالأقرب
            $homeStops = TripStop::where('trip_id', $substituteTrip->id)
                ->where('stop_type', TripStop::TYPE_HOME)
                ->orderBy('sequence_order')
                ->get();

            $seq = 0;
            $lastPickupLat = $substituteDriver->current_lat ?? $dispatch->breakdown_lat;
            $lastPickupLng = $substituteDriver->current_lng ?? $dispatch->breakdown_lng;

            foreach ($homeStops as $hStop) {
                $seq++;
                $hStop->update(['sequence_order' => $seq]);
                if ($hStop->lat && $hStop->lng) {
                    $lastPickupLat = (float) $hStop->lat;
                    $lastPickupLng = (float) $hStop->lng;
                }
            }

            $schoolStops = TripStop::where('trip_id', $substituteTrip->id)
                ->where('stop_type', TripStop::TYPE_SCHOOL)
                ->get();

            if ($schoolStops->isNotEmpty()) {
                $schoolPoints = $schoolStops->map(function ($s) {
                    return [
                        'id'   => $s->id,
                        'lat'  => (float) $s->lat,
                        'lng'  => (float) $s->lng,
                        'stop' => $s,
                    ];
                })->values()->all();

                $orderedSchoolPoints = GeoEstimator::orderByNearestNeighbor(
                    $schoolPoints,
                    (float) $lastPickupLat,
                    (float) $lastPickupLng
                );

                foreach ($orderedSchoolPoints as $point) {
                    $seq++;
                    $point['stop']->update(['sequence_order' => $seq]);
                }
            }
        } else {
            // ==========================================
            // ب) رحلة الإياب / العودة (Return / Afternoon: من المدارس إلى المنازل)
            // ==========================================

            // 3. محطة السيارة المتوقفة: للطلاب العالقين الذين تم أخذهم من المدارس بالفعل
            if (!empty($boardedChildIds)) {
                $hasCarStop = TripStop::where('trip_id', $substituteTrip->id)
                    ->where('label', 'like', '%السيارة المتوقفة%')
                    ->exists();

                if (!$hasCarStop) {
                    TripStop::create([
                        'trip_id'        => $substituteTrip->id,
                        'stop_type'      => TripStop::TYPE_SCHOOL,
                        'child_id'       => null,
                        'lat'            => $dispatch->breakdown_lat,
                        'lng'            => $dispatch->breakdown_lng,
                        'label'          => 'السيارة المتوقفة (استلام طلاب العودة العالقين)',
                        'sequence_order' => ++$highestSeq,
                        'status'         => TripStop::STATUS_PENDING,
                        'reason'         => json_encode(['boarded_child_ids' => $boardedChildIds]),
                    ]);
                }
            }

            // 4. محطات المدارس للأطفال الذين ما زالوا ينتظرون في مدارسهم ولم يُلتقطوا بعد
            if (!empty($waitingChildIds)) {
                $waitingSchoolIds = Child::whereIn('id', $waitingChildIds)
                    ->pluck('school_id')
                    ->filter()
                    ->unique()
                    ->all();

                foreach ($waitingSchoolIds as $wSchoolId) {
                    $schoolStopExists = TripStop::where('trip_id', $substituteTrip->id)
                        ->where('stop_type', TripStop::TYPE_SCHOOL)
                        ->where('school_id', $wSchoolId)
                        ->exists();

                    if (!$schoolStopExists) {
                        $schoolModel = School::find($wSchoolId);
                        if ($schoolModel && $schoolModel->lat && $schoolModel->lng) {
                            TripStop::create([
                                'trip_id'        => $substituteTrip->id,
                                'stop_type'      => TripStop::TYPE_SCHOOL,
                                'child_id'       => null,
                                'school_id'      => $wSchoolId,
                                'lat'            => $schoolModel->lat,
                                'lng'            => $schoolModel->lng,
                                'label'          => $schoolModel->name . ' (استلام طلاب العودة)',
                                'sequence_order' => ++$highestSeq,
                                'status'         => TripStop::STATUS_PENDING,
                            ]);
                        }
                    }
                }
            }

            // 5. محطات تنزيل المنازل (Dropoff at Homes) لجميع الأطفال (أطفال البديل + العالقين + المنتظرين)
            $allTripChildIds = TripStop::where('trip_id', $substituteTrip->id)
                ->whereNotNull('child_id')
                ->pluck('child_id')
                ->merge($strandedChildIds)
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($allTripChildIds as $cId) {
                $homeDropoffExists = TripStop::where('trip_id', $substituteTrip->id)
                    ->where('stop_type', TripStop::TYPE_HOME)
                    ->where('child_id', $cId)
                    ->exists();

                if (!$homeDropoffExists) {
                    $childObj = Child::with(['address', 'school'])->find($cId);
                    $origHomeStop = TripStop::where('trip_id', $originalTrip->id)
                        ->where('stop_type', TripStop::TYPE_HOME)
                        ->where('child_id', $cId)
                        ->first();

                    $lat = $origHomeStop?->lat ?? $childObj?->address?->lat ?? 32.8872;
                    $lng = $origHomeStop?->lng ?? $childObj?->address?->lng ?? 13.1913;
                    $label = 'تنزيل الطالب ' . ($childObj?->full_name ?? $cId) . ' في منزله';

                    TripStop::create([
                        'trip_id'        => $substituteTrip->id,
                        'stop_type'      => TripStop::TYPE_HOME,
                        'child_id'       => $cId,
                        'school_id'      => $childObj?->school_id,
                        'lat'            => $lat,
                        'lng'            => $lng,
                        'label'          => $label,
                        'sequence_order' => 999,
                        'status'         => TripStop::STATUS_PENDING,
                    ]);
                }
            }

            // 6. ترتيب محطات الإياب: نقاط الالتقاط (السيارة المتوقفة + المدارس) أولاً، تليها منازل الطلاب مرتبة بالأقرب
            $pickupStops = TripStop::where('trip_id', $substituteTrip->id)
                ->where('stop_type', TripStop::TYPE_SCHOOL)
                ->orderBy('sequence_order')
                ->get();

            $seq = 0;
            $lastPickupLat = $substituteDriver->current_lat ?? $dispatch->breakdown_lat;
            $lastPickupLng = $substituteDriver->current_lng ?? $dispatch->breakdown_lng;

            foreach ($pickupStops as $pStop) {
                $seq++;
                $pStop->update(['sequence_order' => $seq]);
                if ($pStop->lat && $pStop->lng) {
                    $lastPickupLat = (float) $pStop->lat;
                    $lastPickupLng = (float) $pStop->lng;
                }
            }

            $homeDropoffStops = TripStop::where('trip_id', $substituteTrip->id)
                ->where('stop_type', TripStop::TYPE_HOME)
                ->get();

            if ($homeDropoffStops->isNotEmpty()) {
                $homePoints = $homeDropoffStops->map(function ($h) {
                    return [
                        'id'   => $h->id,
                        'lat'  => (float) $h->lat,
                        'lng'  => (float) $h->lng,
                        'stop' => $h,
                    ];
                })->values()->all();

                $orderedHomePoints = GeoEstimator::orderByNearestNeighbor(
                    $homePoints,
                    (float) $lastPickupLat,
                    (float) $lastPickupLng
                );

                foreach ($orderedHomePoints as $point) {
                    $seq++;
                    $point['stop']->update(['sequence_order' => $seq]);
                }
            }
        }

        // 7. إعادة حساب الأوقات التقديرية الحية (Live ETAs)
        $subLat = $substituteDriver->current_lat ?? $dispatch->breakdown_lat;
        $subLng = $substituteDriver->current_lng ?? $dispatch->breakdown_lng;

        if ($subLat && $subLng) {
            app(TripLifecycleService::class)->computeLiveEtas($substituteTrip, (float) $subLat, (float) $subLng);
        }

        return $substituteTrip;
    }

    /**
     * إرسال إشعار لأولياء الأمور بتعيين سائق بديل مع كامل بياناته (الاسم، الصورة، أرقام الهواتف، وصورة السيارة)
     */
    protected function notifyParentsSubstituteAssigned(
        TripBreakdownDispatch $dispatch,
        Driver $substituteDriver,
        Trip $originalTrip
    ): void {
        $strandedChildIds = $dispatch->stranded_children_ids ?? [];
        if (empty($strandedChildIds)) {
            $strandedChildIds = TripStop::where('trip_id', $originalTrip->id)
                ->whereNotNull('child_id')
                ->pluck('child_id')
                ->unique()
                ->values()
                ->all();
        }

        if (empty($strandedChildIds)) {
            return;
        }

        $parentUserIds = Child::whereIn('id', $strandedChildIds)
            ->pluck('parent_id')
            ->filter()
            ->unique();

        $users = User::whereIn('id', $parentUserIds)->get();
        if ($users->isEmpty()) {
            return;
        }

        // المركبة النشطة للسائق البديل
        $subVehicle = $substituteDriver->vehicles->first(function ($v) {
            return in_array(strtolower($v->status ?? ''), ['active', 'approved']);
        }) ?? $substituteDriver->vehicles->first();

        $subUser = $substituteDriver->user;
        $subDriverName = $subUser?->full_name ?? 'السائق البديل';
        $subDriverPhone = $subUser?->phone_number ?? '';
        $subDriverAltPhone = $subUser?->alternative_phone ?? '';

        // صورة السائق البديل
        $rawAvatar = $subUser?->avatar_url ?? $subUser?->photo_url ?? null;
        $subDriverAvatar = MediaController::urlFor($rawAvatar);
        if (!$subDriverAvatar && !empty($rawAvatar)) {
            $subDriverAvatar = str_starts_with($rawAvatar, 'http') ? $rawAvatar : url($rawAvatar);
        }

        // صورة سيارة السائق البديل
        $rawVehicleImage = $subVehicle?->vehicle_image_url ?? $subVehicle?->vehicle_image ?? $subVehicle?->vehicle_photo ?? null;
        $vehiclePhoto = MediaController::urlFor($rawVehicleImage);
        if (!$vehiclePhoto && !empty($rawVehicleImage)) {
            $vehiclePhoto = str_starts_with($rawVehicleImage, 'http') ? $rawVehicleImage : url($rawVehicleImage);
        }

        $vehicleBrand = $subVehicle?->brand ?? $subVehicle?->make ?? '';
        $vehicleModel = $subVehicle?->model ?? '';
        $vehiclePlate = $subVehicle?->plate_number ?? '';
        $vehicleColor = $subVehicle?->color ?? '';

        $vehicleInfoParts = array_filter([
            $vehicleBrand ? "مركبة: {$vehicleBrand} {$vehicleModel}" : null,
            $vehiclePlate ? "(لوحة: {$vehiclePlate}" : null,
            $vehicleColor ? "- لون: {$vehicleColor})" : ($vehiclePlate ? ")" : null),
        ]);
        $vehicleInfo = $subVehicle ? implode(' ', $vehicleInfoParts) : '';

        // أرقام هواتف السائق البديل
        $phoneText = $subDriverPhone ? " - هاتف: {$subDriverPhone}" : '';
        if (!empty($subDriverAltPhone)) {
            $phoneText .= " / {$subDriverAltPhone}";
        }

        $substituteTripId = $dispatch->substitute_trip_id ?? $originalTrip->id;

        $phonesList = array_values(array_filter([$subDriverPhone, $subDriverAltPhone]));

        $driverData = [
            'id'                => $substituteDriver->id,
            'name'              => $subDriverName,
            'photo'             => $subDriverAvatar,
            'avatar_url'        => $subDriverAvatar,
            'phone'             => $subDriverPhone,
            'phone_number'      => $subDriverPhone,
            'alternative_phone' => $subDriverAltPhone ?: null,
            'phone_numbers'     => $phonesList,
            'phones'            => $phonesList,
            'vehicle'           => [
                'brand'             => $vehicleBrand,
                'model'             => $vehicleModel,
                'plate_number'      => $vehiclePlate,
                'color'             => $vehicleColor,
                'info'              => $vehicleInfo,
                'photo'             => $vehiclePhoto,
                'image_url'         => $vehiclePhoto,
                'vehicle_image_url' => $vehiclePhoto,
            ],
        ];

        $this->notificationService->sendToUsers($users, NotificationFormatter::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_PARENT, [
            'title'                               => '🔄 سائق بديل يستلم الرحلة',
            // بيانات وهوية السائق البديل
            'substitute_driver_name'              => $subDriverName,
            'substitute_driver_photo'             => $subDriverAvatar,
            'substitute_avatar_url'               => $subDriverAvatar,
            'substitute_driver_avatar'            => $subDriverAvatar,
            'substitute_phone'                    => $subDriverPhone,
            'substitute_phone_number'             => $subDriverPhone,
            'substitute_alternative_phone'        => $subDriverAltPhone ?: null,
            'substitute_driver_alternative_phone' => $subDriverAltPhone ?: null,
            'substitute_phones'                   => $phonesList,
            'substitute_driver_phones'            => $phonesList,
            'driver_name'                         => $subDriverName,
            'driver_photo'                        => $subDriverAvatar,
            'driver_avatar'                       => $subDriverAvatar,
            'driver_phone'                        => $subDriverPhone,
            'driver_alternative_phone'            => $subDriverAltPhone ?: null,
            'driver_phones'                       => $phonesList,
            'phone_numbers'                       => $phonesList,
            // بيانات وصورة المركبة
            'substitute_vehicle_photo'            => $vehiclePhoto,
            'substitute_vehicle_image'            => $vehiclePhoto,
            'substitute_vehicle_image_url'        => $vehiclePhoto,
            'vehicle_photo'                       => $vehiclePhoto,
            'vehicle_image'                       => $vehiclePhoto,
            'vehicle_image_url'                   => $vehiclePhoto,
            'substitute_vehicle_plate'            => $vehiclePlate,
            'substitute_vehicle_brand'            => $vehicleBrand,
            'substitute_vehicle_model'            => $vehicleModel,
            'substitute_vehicle_color'            => $vehicleColor,
            'substitute_vehicle_info'             => $vehicleInfo,
            'vehicle_info'                        => $vehicleInfo,
            // كائنات هيكلية مهيأة للمنصات والتطبيقات
            'driver'                              => $driverData,
            'substitute_driver'                   => $driverData,
            'substitute_vehicle'                  => $driverData['vehicle'],
            // معرّفات الربط والتوجيه المباشر
            'trip_id'                             => (string) $substituteTripId,
            'entity_id'                           => (string) $substituteTripId,
            'original_trip_id'                    => (string) $originalTrip->id,
            'substitute_trip_id'                  => (string) $substituteTripId,
            'dispatch_id'                         => (string) $dispatch->id,
            'message'                             => "توقفت الحافلة بسبب عطل طارئ، ويستلم الكابتن البديل ({$subDriverName}{$phoneText}) الرحلة الآن لاستكمال نقل الأبناء بسلام." . ($vehicleInfo ? " {$vehicleInfo}" : ''),
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
                ->filter()
                ->unique();

            $users = User::whereIn('id', $parentUserIds)->get();
            if ($users->isNotEmpty()) {
                $this->notificationService->sendToUsers($users, NotificationFormatter::TYPE_EMERGENCY_BREAKDOWN_PARENT_PICKUP, [
                    'title'            => '⚠️ تعطل الحافلة: يرجى استلام طفلك',
                    'location_text'    => "إحداثيات: {$lat}, {$lng}",
                    'maps_url'         => $mapsUrl,
                    'trip_id'          => (string) $trip->id,
                    'entity_id'        => (string) $trip->id,
                    'original_trip_id' => (string) $trip->id,
                    'dispatch_id'      => (string) $dispatch->id,
                    'message'          => "توقفت الحافلة بسبب عطل طارئ وتعذر توفير بديل حالياً. الأبناء في أمان، يرجى التوجه لموقعهم لاستلام ابنك: {$mapsUrl}",
                ]);
            }
        }

        // 3. إشعار فوري لمشرفي النظام (Admin) بتعذر العثور على بديل وتحويل الاستلام للأهالي
        $this->notifyAdminsUnresolved($trip, $dispatch, count($strandedChildIds));
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
        $parentUserIds = $strandedStops->pluck('child.parent_id')->filter()->unique();
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

    /**
     * استئناف رحلة معطلة مع معالجة طلبات الاستبدال الطارئة المرتبطة بها
     *
     * @throws Exception في حال كان هناك سائق بديل قد وافق بالفعل
     */
    public function resumeTrip(Trip $trip): array
    {
        return DB::transaction(function () use ($trip) {
            // 1. التحقق من وجود طلب طوارئ مرتبط
            $dispatch = TripBreakdownDispatch::where('trip_id', $trip->id)
                ->whereIn('status', [
                    TripBreakdownDispatch::STATUS_PENDING,
                    TripBreakdownDispatch::STATUS_BROADCASTED,
                    TripBreakdownDispatch::STATUS_ACCEPTED,
                ])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            // 2. إذا وافق سائق بديل بالفعل، يُمنع السائق الأصلي منعاً باتاً من الاستئناف
            if ($dispatch && $dispatch->status === TripBreakdownDispatch::STATUS_ACCEPTED) {
                $subDriverName = $dispatch->substituteDriver?->user?->full_name ?? 'السائق البديل';
                throw new Exception(
                    "لا يمكن استئناف الرحلة؛ لقد قبل {$subDriverName} مهمة الإنقاذ بالفعل وهو في طريقه لاستلام ونقل الأطفال.",
                    409
                );
            }

            // 3. إذا كان طلب الطوارئ قيد الانتظار أو البث، يتم إلغاؤه وإشعار المرشحين
            if ($dispatch && in_array($dispatch->status, [TripBreakdownDispatch::STATUS_PENDING, TripBreakdownDispatch::STATUS_BROADCASTED])) {
                $dispatch->update([
                    'status' => TripBreakdownDispatch::STATUS_CANCELLED,
                ]);

                // إشعار السائقين المرشحين بإلغاء طلب الطوارئ لانتفاء الحاجة
                $candidateIds = $dispatch->candidate_driver_ids ?? [];
                if (!empty($candidateIds)) {
                    $candidates = User::whereHas('driver', fn($q) => $q->whereIn('id', $candidateIds))->get();
                    if ($candidates->isNotEmpty()) {
                        $this->notificationService->sendToUsers($candidates, NotificationFormatter::TYPE_EMERGENCY_REQUEST_CANCELLED_OTHER, [
                            'title'       => 'ℹ️ إلغاء مهمة الإنقاذ الطارئة',
                            'message'     => 'تم إصلاح عطل الحافلة واستئناف الرحلة من قبل السائق الأصلي، شكراً لتعاونكم.',
                            'dispatch_id' => (string) $dispatch->id,
                            'entity_id'   => (string) $dispatch->id,
                        ]);
                    }
                }
            }

            // 4. استئناف الرحلة الأصلية
            $trip->update([
                'status'            => 'in_progress',
                'suspension_reason' => null,
            ]);

            // 5. إشعار أولياء الأمور بأن الرحلة استؤنفت بعد إصلاح العطل
            $strandedStops = TripStop::where('trip_id', $trip->id)
                ->whereNotNull('child_id')
                ->whereIn('status', [TripStop::STATUS_PENDING, TripStop::STATUS_BOARDED])
                ->with('child.parent.user')
                ->get();

            $parentUserIds = $strandedStops->pluck('child.parent_id')->filter()->unique();
            $parents = User::whereIn('id', $parentUserIds)->get();

            if ($parents->isNotEmpty()) {
                $this->notificationService->sendToUsers($parents, NotificationFormatter::TYPE_TRIP_STARTED, [
                    'title'     => '🚌 استئناف الرحلة المدرسية',
                    'message'   => 'تم بحمد الله معالجة العطل واستئناف الرحلة المدرسية، الحافلة تواصل مسارها الآن لنقل الأبناء.',
                    'trip_id'   => (string) $trip->id,
                    'entity_id' => (string) $trip->id,
                ]);
            }

            return [
                'status'  => 'success',
                'message' => 'تم استئناف الرحلة بنجاح وإشعار أولياء الأمور.',
                'trip_id' => (int) $trip->id,
            ];
        });
    }

    /**
     * إشعار مشرفي النظام ببلاغ تعطل جديد وبدء البث
     */
    protected function notifyAdminsBreakdown(Trip $trip, TripBreakdownDispatch $dispatch, int $strandedCount): void
    {
        $admins = User::whereIn('role_id', [1, 2])
            ->orWhereHas('role', fn($r) => $r->whereIn('name', ['Admin', 'SuperAdmin', 'admin', 'super_admin', 'operations_supervisor']))
            ->get();

        if ($admins->isNotEmpty()) {
            $driverName = $trip->driver?->user?->full_name ?? 'السائق';
            $this->notificationService->sendToUsers($admins, NotificationFormatter::TYPE_ADMIN_EMERGENCY_BREAKDOWN, [
                'title'                   => '🚨 حالة طوارئ: تعطل حافلة مدرسية',
                'message'                 => "أبلغ الكابتن ({$driverName}) عن تعطل حافلته رقم #{$trip->id} وبها {$strandedCount} أطفال. يقوم النظام حالياً بالبحث عن بديل.",
                'trip_id'                 => (string) $trip->id,
                'entity_id'               => (string) $dispatch->id,
                'dispatch_id'             => (string) $dispatch->id,
                'stranded_children_count' => (string) $strandedCount,
            ]);
        }
    }

    /**
     * إشعار مشرفي النظام بتعذر توفير بديل
     */
    protected function notifyAdminsUnresolved(Trip $trip, TripBreakdownDispatch $dispatch, int $strandedCount): void
    {
        $admins = User::whereIn('role_id', [1, 2])
            ->orWhereHas('role', fn($r) => $r->whereIn('name', ['Admin', 'SuperAdmin', 'admin', 'super_admin', 'operations_supervisor']))
            ->get();

        if ($admins->isNotEmpty()) {
            $driverName = $trip->driver?->user?->full_name ?? 'السائق';
            $this->notificationService->sendToUsers($admins, NotificationFormatter::TYPE_ADMIN_EMERGENCY_UNRESOLVED, [
                'title'                   => '⚠️ طوارئ حرجة: تعذر العثور على بديل',
                'message'                 => "تعذر العثور على سائق بديل للحافلة رقم #{$trip->id} ({$driverName}) وبها {$strandedCount} أطفال. تم إشعار أولياء الأمور بالاستلام المباشر.",
                'trip_id'                 => (string) $trip->id,
                'entity_id'               => (string) $dispatch->id,
                'dispatch_id'             => (string) $dispatch->id,
                'stranded_children_count' => (string) $strandedCount,
            ]);
        }
    }

    /**
     * إشعار مشرفي النظام بقبول سائق بديل للمهمة
     */
    protected function notifyAdminsSubstituteAccepted(Trip $trip, TripBreakdownDispatch $dispatch, Driver $substituteDriver): void
    {
        $admins = User::whereIn('role_id', [1, 2])
            ->orWhereHas('role', fn($r) => $r->whereIn('name', ['Admin', 'SuperAdmin', 'admin', 'super_admin', 'operations_supervisor']))
            ->get();

        if ($admins->isNotEmpty()) {
            $subName = $substituteDriver->user?->full_name ?? 'السائق البديل';
            $this->notificationService->sendToUsers($admins, NotificationFormatter::TYPE_ADMIN_EMERGENCY_ACCEPTED, [
                'title'                  => '✅ طوارئ: تم قبول مهمة الإنقاذ',
                'message'                => "قبل الكابتن البديل ({$subName}) مهمة إنقاذ طلاب الحافلة المعطلة رقم #{$trip->id}.",
                'trip_id'                => (string) $trip->id,
                'entity_id'              => (string) $dispatch->id,
                'dispatch_id'            => (string) $dispatch->id,
                'substitute_driver_id'   => (string) $substituteDriver->id,
                'substitute_driver_name' => $subName,
            ]);
        }
    }
}
