<?php

namespace App\Services\Shared;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\ParentModel;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\LocationChangeRequest;
use App\Models\Shared\LocationChangeRequestChild;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Trip\DailyTripGenerationService;
use App\Services\Trip\MasterRouteStopSyncService;
use App\Support\GeoEstimator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

/**
 * إدارة طلبات ولي الأمر لتغيير موقع الاستلام/التسليم لطفل أو أكثر ضمن اشتراكاتهم النشطة.
 *
 * المنطق الجديد:
 *   1) ولي الأمر يختار عدة أطفال + تاريخ + رحلة (ذهاب/إياب/كلاهما) + عنوان جديد.
 *   2) نُجمِّع الأطفال حسب السائق: طلب واحد لكل سائق حتى لو كان يشمل عدة أطفال ورحلات.
 *   3) قرار السائق (موافقة/رفض) يسري على كل الأطفال والرحلات داخل الطلب — لا موافقة جزئية.
 *   4) عند الموافقة نُحدِّث فقط `trip_stops` للاتجاه المحدد في التاريخ المحدد؛
 *      لا يمس الاشتراك ولا باقي أيامه.
 */
class LocationChangeService
{
    protected MasterRouteStopSyncService $routeStopSyncService;
    protected NotificationService $notificationService;
    protected DailyTripGenerationService $tripGenerationService;

    public function __construct(
        MasterRouteStopSyncService $routeStopSyncService,
        NotificationService $notificationService,
        DailyTripGenerationService $tripGenerationService
    ) {
        $this->routeStopSyncService  = $routeStopSyncService;
        $this->notificationService   = $notificationService;
        $this->tripGenerationService = $tripGenerationService;
    }

    // =========================================================================
    // Helpers مشتركة
    // =========================================================================

    private function resolveParent(int $userId): ParentModel
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            throw new Exception('هذا الحساب غير مسجل كولي أمر في النظام.');
        }
        return $parent;
    }

    private function parentIds(int $userId, ParentModel $parent): array
    {
        return array_values(array_unique(array_filter([$userId, $parent->id])));
    }

    private function directionFromShiftSlot(?string $slot): ?string
    {
        if (!$slot) {
            return null;
        }
        return DriverSeatSlot::isGoSlot($slot)
            ? LocationChangeRequest::DIRECTION_TO_SCHOOL
            : LocationChangeRequest::DIRECTION_TO_HOME;
    }

    /**
     * يترجم اتجاه ونوع النقطة إلى نوع محطة الرحلة المستهدف:
     *   ذهاب + pickup   → المنزل (نبدأ منه)
     *   ذهاب + dropoff  → المدرسة (ننتهي إليها)
     *   إياب + pickup   → المدرسة
     *   إياب + dropoff  → المنزل
     */
    private function resolveTargetStopType(string $direction, string $pointType): string
    {
        if ($direction === LocationChangeRequest::DIRECTION_TO_SCHOOL) {
            return $pointType === LocationChangeRequest::POINT_TYPE_PICKUP
                ? TripStop::TYPE_HOME
                : TripStop::TYPE_SCHOOL;
        }
        return $pointType === LocationChangeRequest::POINT_TYPE_PICKUP
            ? TripStop::TYPE_SCHOOL
            : TripStop::TYPE_HOME;
    }

    private function currentLocationForSubscription(
        ActiveSubscription $sub,
        string $direction,
        string $pointType
    ): array {
        $stopType = $this->resolveTargetStopType($direction, $pointType);

        if ($stopType === TripStop::TYPE_HOME) {
            // نقطة المنزل تُقرأ من الاشتراك مباشرة (pickup للذهاب / dropoff للإياب).
            $isPickup = $direction === LocationChangeRequest::DIRECTION_TO_SCHOOL;
            return [
                'lat'   => (float) ($isPickup ? $sub->pickup_lat  : $sub->dropoff_lat),
                'lng'   => (float) ($isPickup ? $sub->pickup_lng  : $sub->dropoff_lng),
                'label' =>          ($isPickup ? $sub->pickup_label: $sub->dropoff_label),
            ];
        }

        // نقطة المدرسة
        $isDropoff = $direction === LocationChangeRequest::DIRECTION_TO_SCHOOL;
        return [
            'lat'   => (float) ($isDropoff ? $sub->dropoff_lat : $sub->pickup_lat),
            'lng'   => (float) ($isDropoff ? $sub->dropoff_lng : $sub->pickup_lng),
            'label' =>          ($isDropoff ? $sub->dropoff_label: $sub->pickup_label),
        ];
    }

    private function resolveNewPoint(int $userId, ?int $parentId, ?int $addressId, ?float $lat, ?float $lng, ?string $label): array
    {
        if ($addressId) {
            $parentIds = array_values(array_unique(array_filter([$userId, $parentId])));
            $address   = Address::where('id', $addressId)->whereIn('user_id', $parentIds)->first();
            if (!$address) {
                throw new Exception('العنوان المحدد غير موجود ضمن مواقعك المحفوظة.');
            }
            return [
                'address_id' => $address->id,
                'lat'        => (float) $address->lat,
                'lng'        => (float) $address->lng,
                'label'      => $address->label,
            ];
        }

        if ($lat === null || $lng === null) {
            throw new Exception('يجب تحديد عنوان محفوظ أو إحداثيات الموقع الجديد.');
        }

        return ['address_id' => null, 'lat' => $lat, 'lng' => $lng, 'label' => $label];
    }

    private function splitFee(float $grossFee): array
    {
        $grossFee   = round($grossFee, 2);
        $rate       = PricingSetting::commissionRatePercent();
        $commission = round(($grossFee * $rate) / 100, 2);
        $net        = round($grossFee - $commission, 2);

        return [
            'gross_fee'           => $grossFee,
            'commission_rate'     => round($rate, 2),
            'platform_commission' => $commission,
            'driver_net_fee'      => max(0, $net),
            'currency'            => 'د.ل',
        ];
    }

    // =========================================================================
    // 1) شاشة الاختيار الأولى — الأطفال + العناوين
    // =========================================================================

    /**
     * يرجع كل أطفال ولي الأمر مع مدارسهم وسائقيهم، وكل عناوينه المحفوظة (بلا اشتراط is_default).
     */
    public function getChangeableOptions(int $userId): array
    {
        $parent    = $this->resolveParent($userId);
        $parentIds = $this->parentIds($userId, $parent);

        $addresses = Address::whereIn('user_id', $parentIds)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Address $a) => [
                'id'         => $a->id,
                'label'      => $a->label,
                'lat'        => (float) $a->lat,
                'lng'        => (float) $a->lng,
                'is_default' => (bool) $a->is_default,
            ])
            ->values();

        $children = Child::where('parent_id', $parent->id)
            ->with(['school:id,name,address', 'address:id,label,lat,lng'])
            ->get();

        // نبني قائمة سائق واحد ملخص لكل طفل من أول اشتراك نشط له.
        $childIds      = $children->pluck('id')->all();
        $subsByChild   = $childIds
            ? ActiveSubscription::forChildren($childIds)
                ->where('status', 'active')
                ->with(['subscriptionRequest.driver.user'])
                ->get()
                ->groupBy(fn (ActiveSubscription $s) => $s->child_id)
            : collect();

        $childrenPayload = $children->map(function (Child $c) use ($subsByChild) {
            $firstSub  = $subsByChild->get($c->id)?->first();
            $driver    = $firstSub?->driver;
            $driverUsr = $driver?->user;

            return [
                'id'        => $c->id,
                'name'      => $c->full_name,
                'photo_url' => $c->photo_url,
                'school' => [
                    'id'    => $c->school?->id,
                    'name'  => $c->school?->name,
                    'shift' => $c->preferred_time_slot,
                ],
                'driver' => $driver ? [
                    'id'   => $driver->id,
                    'name' => $driverUsr?->full_name ?? $driverUsr?->name,
                ] : null,
                'has_active_subscription' => (bool) $firstSub,
            ];
        })->values();

        return [
            'children'  => $childrenPayload,
            'addresses' => $addresses,
        ];
    }

    // =========================================================================
    // 2) الرحلات المتاحة لأطفال محددين في تاريخ معين
    // =========================================================================

    /**
     * لكل طفل يرجع اشتراكاته النشطة كرحلات (ذهاب/إياب) في التاريخ المحدد
     * مع علامة is_editable وسبب المنع إن وُجد.
     */
    public function getAvailableTripsForChildren(int $userId, array $childIds, string $date): array
    {
        $parent    = $this->resolveParent($userId);
        $parentIds = $this->parentIds($userId, $parent);
        $parsedDate = Carbon::parse($date)->toDateString();

        // نقصر الأطفال على أبناء ولي الأمر فقط (حارس ملكية).
        $ownedChildren = Child::where('parent_id', $parent->id)
            ->whereIn('id', $childIds)
            ->with(['school:id,name'])
            ->get()
            ->keyBy('id');

        if ($ownedChildren->isEmpty()) {
            return ['date' => $parsedDate, 'children' => []];
        }

        $subs = ActiveSubscription::forChildren($ownedChildren->keys()->all())
            ->where('status', 'active')
            ->with(['route', 'subscriptionRequest.driver.user'])
            ->get();

        $result = [];

        foreach ($ownedChildren as $child) {
            $childSubs = $subs->filter(fn (ActiveSubscription $s) => $s->child_id === $child->id);

            $driver     = $childSubs->first()?->driver;
            $driverUsr  = $driver?->user;

            $trips = [];
            foreach ($childSubs as $sub) {
                $direction = $this->directionFromShiftSlot($sub->route?->shift_slot);
                if (!$direction) {
                    continue;
                }

                $tripRow = null;
                if ($sub->route_id) {
                    $tripRow = Trip::where('driver_id', $sub->driver_id)
                        ->where('route_id', $sub->route_id)
                        ->whereDate('trip_date', $parsedDate)
                        ->first();
                }

                $isEditable  = true;
                $blockReason = null;

                if ($tripRow) {
                    $stop = TripStop::where('trip_id', $tripRow->id)
                        ->where('child_id', $child->id)
                        ->first();

                    if ($stop && !in_array($stop->status, TripStop::NON_FINAL_STATUSES, true)) {
                        $isEditable  = false;
                        $blockReason = 'الرحلة نُفّذت بالفعل ولا يمكن تعديل موقعها.';
                    }
                }

                $current = [
                    'pickup' => [
                        'lat'   => (float) $sub->pickup_lat,
                        'lng'   => (float) $sub->pickup_lng,
                        'label' => $sub->pickup_label,
                    ],
                    'dropoff' => [
                        'lat'   => (float) $sub->dropoff_lat,
                        'lng'   => (float) $sub->dropoff_lng,
                        'label' => $sub->dropoff_label,
                    ],
                ];

                $scheduledAt = null;
                $timeSource  = $direction === LocationChangeRequest::DIRECTION_TO_SCHOOL
                    ? $sub->pickup_time
                    : $sub->dropoff_time;
                if ($timeSource) {
                    $scheduledAt = Carbon::parse($parsedDate . ' ' . Carbon::parse($timeSource)->format('H:i:s'))->toIso8601String();
                }

                $trips[] = [
                    'active_subscription_id' => $sub->id,
                    'trip_id'                => $tripRow?->id,
                    'route_id'               => $sub->route_id,
                    'direction'              => $direction,
                    'direction_text'         => $direction === LocationChangeRequest::DIRECTION_TO_SCHOOL
                        ? 'رحلة الذهاب للمدرسة'
                        : 'رحلة العودة للمنزل',
                    'scheduled_at'   => $scheduledAt,
                    'current_pickup' => $current['pickup'],
                    'current_dropoff'=> $current['dropoff'],
                    'is_editable'    => $isEditable,
                    'block_reason'   => $blockReason,
                ];
            }

            $result[] = [
                'child' => [
                    'id'        => $child->id,
                    'name'      => $child->full_name,
                    'photo_url' => $child->photo_url,
                ],
                'school' => [
                    'id'    => $child->school?->id,
                    'name'  => $child->school?->name,
                    'shift' => $child->preferred_time_slot,
                ],
                'driver' => $driver ? [
                    'id'   => $driver->id,
                    'name' => $driverUsr?->full_name ?? $driverUsr?->name,
                ] : null,
                'trips' => $trips,
            ];
        }

        return ['date' => $parsedDate, 'children' => $result];
    }

    // =========================================================================
    // 3) التسعير المُجمَّع (Preview) — يجمِّع بالسائق ويحسب رسم لكل مجموعة
    // =========================================================================

    /**
     * @param array $selections مصفوفة من [{child_id, trip_ids: [active_subscription_id, ...]}]
     */
    public function quoteGroupedChange(
        int $userId,
        string $pointType,
        string $date,
        array $selections,
        ?int $addressId,
        ?float $lat,
        ?float $lng,
        ?string $label
    ): array {
        $parent      = $this->resolveParent($userId);
        $parsedDate  = Carbon::parse($date)->toDateString();
        $newPoint    = $this->resolveNewPoint($userId, $parent->id, $addressId, $lat, $lng, $label);

        if (empty($selections)) {
            throw new Exception('يجب اختيار طفل واحد على الأقل مع رحلة واحدة على الأقل.');
        }

        // نُفكّك التحديد إلى صفوف (subscription واحد لكل صف).
        $rows = collect();
        foreach ($selections as $sel) {
            $childId = (int) ($sel['child_id'] ?? 0);
            $subIds  = array_map('intval', $sel['trip_ids'] ?? []);
            if (!$childId || empty($subIds)) {
                continue;
            }

            $subs = ActiveSubscription::whereIn('id', $subIds)
                ->with(['route', 'subscriptionRequest', 'requestChild'])
                ->get();

            foreach ($subs as $sub) {
                if ($sub->child_id !== $childId) {
                    throw new Exception("الاشتراك رقم {$sub->id} لا يخص الطفل رقم {$childId}.");
                }
                if ($sub->status !== 'active') {
                    throw new Exception("الاشتراك رقم {$sub->id} ليس نشطاً.");
                }
                if ($sub->subscriptionRequest?->parent_id !== $parent->id
                    && $sub->subscriptionRequest?->parent_id !== $userId) {
                    throw new Exception("الاشتراك رقم {$sub->id} لا يخصك.");
                }

                $rows->push($sub);
            }
        }

        if ($rows->isEmpty()) {
            throw new Exception('لا توجد اشتراكات صالحة ضمن التحديد.');
        }

        // نُجمِّع بالسائق
        $groups = $rows->groupBy(fn (ActiveSubscription $s) => $s->driver_id);
        $groupedPayload = [];

        foreach ($groups as $driverId => $groupSubs) {
            $driverSub = $groupSubs->first();
            $driverUsr = $driverSub->driver?->user;

            $tripsInfo = [];
            $childrenInfo = [];
            $currentReference = null; // نستخدم أول موقع منزل كمصدر لحساب المسافة

            foreach ($groupSubs as $sub) {
                $direction = $this->directionFromShiftSlot($sub->route?->shift_slot);
                if (!$direction) {
                    throw new Exception("لا يمكن استنتاج اتجاه الرحلة للاشتراك {$sub->id}.");
                }

                $current = $this->currentLocationForSubscription($sub, $direction, $pointType);

                if ($currentReference === null && $current['lat'] && $current['lng']) {
                    $currentReference = $current;
                }

                $tripsInfo[] = [
                    'active_subscription_id' => $sub->id,
                    'child_id'   => $sub->child_id,
                    'direction'  => $direction,
                    'route_id'   => $sub->route_id,
                    'current'    => $current,
                ];

                if (!isset($childrenInfo[$sub->child_id])) {
                    $childName = $sub->child?->full_name;
                    $childrenInfo[$sub->child_id] = [
                        'id'    => $sub->child_id,
                        'name'  => $childName,
                        'trips' => [],
                    ];
                }
                $childrenInfo[$sub->child_id]['trips'][] = [
                    'active_subscription_id' => $sub->id,
                    'direction'              => $direction,
                ];
            }

            if (!$currentReference || !$currentReference['lat'] || !$currentReference['lng']) {
                throw new Exception("لا يمكن حساب رسم التغيير لأن الموقع الحالي غير مضبوط في اشتراكات السائق {$driverId}.");
            }

            $distanceKm = round(
                GeoEstimator::haversineKm(
                    $currentReference['lat'],
                    $currentReference['lng'],
                    $newPoint['lat'],
                    $newPoint['lng']
                ),
                2
            );

            $tier = PricingSetting::resolveFeeTier($distanceKm);
            if ($tier === null) {
                $max = PricingSetting::MAX_LOCATION_CHANGE_DISTANCE_KM;
                throw new Exception("المسافة الجديدة ({$distanceKm} كم) للسائق {$driverId} تتجاوز الحد الأقصى ({$max} كم).");
            }

            $feeAmount = round(PricingSetting::feeForTier($tier), 2);
            $fee       = $this->splitFee($feeAmount);

            $groupedPayload[] = [
                'group_key' => "driver_{$driverId}",
                'driver' => [
                    'id'   => $driverId,
                    'name' => $driverUsr?->full_name ?? $driverUsr?->name,
                ],
                'children' => array_values($childrenInfo),
                'trips_internal'   => $tripsInfo, // للاستخدام الداخلي في requestGroupedChange
                'current_location' => $currentReference,
                'new_location'     => [
                    'lat'   => $newPoint['lat'],
                    'lng'   => $newPoint['lng'],
                    'label' => $newPoint['label'],
                ],
                'distance_km'    => $distanceKm,
                'fee_tier'       => $tier,
                'fee_tier_label' => PricingSetting::TIER_LABELS[$tier] ?? null,
                'fee_breakdown'  => $fee,
            ];
        }

        $totalFee = array_sum(array_map(fn ($g) => $g['fee_breakdown']['gross_fee'], $groupedPayload));
        $walletBalance = (float) ($parent->balance ?? 0) / 100;

        return [
            'parsed_date'        => $parsedDate,
            'point_type'         => $pointType,
            'address_id'         => $newPoint['address_id'],
            'new_lat'            => $newPoint['lat'],
            'new_lng'            => $newPoint['lng'],
            'new_label'          => $newPoint['label'],
            'grouped'            => $groupedPayload,
            'summary' => [
                'requests_count' => count($groupedPayload),
                'children_count' => collect($groupedPayload)->flatMap(fn ($g) => collect($g['children'])->pluck('id'))->unique()->count(),
                'total_fee'      => round($totalFee, 2),
                'wallet_balance' => round($walletBalance, 2),
                'wallet_after'   => round($walletBalance - $totalFee, 2),
                'currency'       => 'د.ل',
            ],
        ];
    }

    // =========================================================================
    // 4) إنشاء الطلبات (طلب لكل سائق)
    // =========================================================================

    /**
     * ينشئ طلب LocationChangeRequest واحد لكل سائق ضمن التحديد.
     * يعيد مصفوفة الطلبات المُنشأة.
     */
    public function createGroupedChange(
        int $userId,
        string $pointType,
        string $date,
        array $selections,
        ?int $addressId,
        ?float $lat,
        ?float $lng,
        ?string $label
    ): array {
        $quote  = $this->quoteGroupedChange($userId, $pointType, $date, $selections, $addressId, $lat, $lng, $label);
        $parent = $this->resolveParent($userId);

        return DB::transaction(function () use ($quote, $parent, $userId, $pointType) {
            $created = [];

            foreach ($quote['grouped'] as $group) {
                $driverId  = (int) $group['driver']['id'];

                // نمنع تكرار طلب معلق لنفس (سائق، ولي الأمر، تاريخ، نوع نقطة، مع تقاطع في الاشتراكات)
                $existing  = LocationChangeRequest::where('driver_id', $driverId)
                    ->where('parent_id', $userId)
                    ->where('point_type', $pointType)
                    ->whereDate('change_date', $quote['parsed_date'])
                    ->where('status', LocationChangeRequest::STATUS_PENDING)
                    ->pluck('id');

                if ($existing->isNotEmpty()) {
                    $subIdsInThisGroup = collect($group['trips_internal'])->pluck('active_subscription_id')->all();
                    $conflict = LocationChangeRequestChild::whereIn('location_change_request_id', $existing)
                        ->whereIn('active_subscription_id', $subIdsInThisGroup)
                        ->exists();
                    if ($conflict) {
                        throw new Exception("يوجد طلب معلق مسبقاً لنفس السائق ونفس الرحلات في تاريخ {$quote['parsed_date']}.");
                    }
                }

                $childIds  = collect($group['children'])->pluck('id')->all();
                $directionSet = collect($group['trips_internal'])->pluck('direction')->unique();
                $requestDirection = $directionSet->count() === 1
                    ? $directionSet->first()
                    : LocationChangeRequest::DIRECTION_BOTH;

                $fee = $group['fee_breakdown'];

                $request = LocationChangeRequest::create([
                    // نُبقيهما null للطلبات المُجمَّعة — التفاصيل في جدول الربط.
                    'active_subscription_id' => count($group['trips_internal']) === 1
                        ? $group['trips_internal'][0]['active_subscription_id']
                        : null,
                    'child_id'  => count($childIds) === 1 ? $childIds[0] : null,
                    'parent_id' => $userId,
                    'driver_id' => $driverId,
                    'point_type'   => $pointType,
                    'direction'    => $requestDirection,
                    'change_date'  => $quote['parsed_date'],
                    'is_single_day'=> true,
                    'new_address_id' => $quote['address_id'],
                    'new_lat'   => $quote['new_lat'],
                    'new_lng'   => $quote['new_lng'],
                    'new_label' => $quote['new_label'],
                    'distance_km' => $group['distance_km'],
                    'fee_tier'    => $group['fee_tier'],
                    'fee_amount'  => $fee['gross_fee'],
                    'commission_rate'            => $fee['commission_rate'],
                    'platform_commission_amount' => $fee['platform_commission'],
                    'driver_net_fee'             => $fee['driver_net_fee'],
                    'status' => LocationChangeRequest::STATUS_PENDING,
                ]);

                foreach ($group['trips_internal'] as $row) {
                    LocationChangeRequestChild::create([
                        'location_change_request_id' => $request->id,
                        'child_id'               => $row['child_id'],
                        'active_subscription_id' => $row['active_subscription_id'],
                        'trip_id'                => null, // يُحلّ عند الموافقة
                        'direction'              => $row['direction'],
                        'previous_lat'   => $row['current']['lat'] ?: null,
                        'previous_lng'   => $row['current']['lng'] ?: null,
                        'previous_label' => $row['current']['label'] ?? null,
                    ]);
                }

                $request->loadMissing(['driver.user']);
                $this->notifyDriverOnCreate($request, $group, $quote);

                $created[] = $request->fresh(['driver.user', 'targets.child']);
            }

            return $created;
        });
    }

    private function notifyDriverOnCreate(LocationChangeRequest $request, array $group, array $quote): void
    {
        try {
            $driverUser = $request->driver?->user;
            if (!$driverUser) {
                return;
            }
            $childrenNames = collect($group['children'])->pluck('name')->filter()->join('، ');
            $pointLabel = $request->point_type === LocationChangeRequest::POINT_TYPE_PICKUP ? 'الاستلام' : 'التسليم';
            $netFee     = $group['fee_breakdown']['driver_net_fee'];

            $this->notifyUser(
                $driverUser,
                'طلب تغيير موقع 📍',
                "طلب ولي الأمر تغيير موقع {$pointLabel} للأطفال ({$childrenNames}) ليوم {$quote['parsed_date']} إلى: "
                    . ($quote['new_label'] ?? 'موقع جديد')
                    . " (المسافة: {$group['distance_km']} كم — صافي الرسوم لك: {$netFee} د.ل).",
                'location_change_requested',
                (string) $request->id,
                [
                    'children_names' => $childrenNames,
                    'change_date'    => $quote['parsed_date'],
                    'distance_km'    => $group['distance_km'],
                    'driver_net_fee' => $netFee,
                ]
            );
        } catch (Throwable $e) {
            Log::warning("فشل إرسال إشعار طلب تغيير الموقع ID {$request->id}: " . $e->getMessage());
        }
    }

    // =========================================================================
    // 5) رد السائق (موافقة/رفض) — يعمل على طلب مُجمَّع كوحدة واحدة
    // =========================================================================

    public function respondToChange(int $driverUserId, int $requestId, bool $approve, ?string $rejectionReason = null): LocationChangeRequest
    {
        $driver = Driver::where('user_id', $driverUserId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        $changeRequest = LocationChangeRequest::where('id', $requestId)
            ->where('driver_id', $driver->id)
            ->first();

        if (!$changeRequest) {
            throw new Exception('طلب تغيير الموقع غير موجود أو لا يخصك.');
        }

        if ($changeRequest->status !== LocationChangeRequest::STATUS_PENDING) {
            throw new Exception('تم الرد على هذا الطلب مسبقاً.');
        }

        return DB::transaction(function () use ($changeRequest, $approve, $rejectionReason) {
            if ($approve) {
                $this->applyApprovedRequest($changeRequest);

                $changeRequest->update([
                    'status'       => LocationChangeRequest::STATUS_APPROVED,
                    'responded_at' => now(),
                ]);

                $this->collectLocationChangeFee($changeRequest);
            } else {
                $changeRequest->update([
                    'status'           => LocationChangeRequest::STATUS_REJECTED,
                    'rejection_reason' => $rejectionReason,
                    'responded_at'     => now(),
                ]);
            }

            $this->notifyParentOnResponse($changeRequest, $approve, $rejectionReason);

            return $changeRequest->fresh(['parent', 'driver.user', 'targets.child']);
        });
    }

    /**
     * يطبق التحديث على trip_stops لكل صف داخل الطلب المُجمَّع، مقيداً بالاتجاه والتاريخ.
     */
    protected function applyApprovedRequest(LocationChangeRequest $request): void
    {
        $request->loadMissing(['targets.activeSubscription']);
        $changeDate = $request->change_date ? Carbon::parse($request->change_date)->toDateString() : null;

        if (!$changeDate) {
            return;
        }

        foreach ($request->targets as $target) {
            $sub = $target->activeSubscription;
            if (!$sub) {
                $target->update(['applied' => false, 'skip_reason' => 'الاشتراك المرتبط غير موجود.']);
                continue;
            }

            // نبحث عن الرحلة الفعلية لهذا الاشتراك على هذا التاريخ (route + driver + date).
            $trip = Trip::where('driver_id', $sub->driver_id)
                ->when($sub->route_id, fn ($q) => $q->where('route_id', $sub->route_id))
                ->whereDate('trip_date', $changeDate)
                ->first();

            if (!$trip) {
                $target->update(['applied' => false, 'skip_reason' => 'لم تُولَّد رحلة لهذا الاتجاه في التاريخ المحدد.']);
                continue;
            }

            $stopType = $this->resolveTargetStopType($target->direction, $request->point_type);

            $tripStop = TripStop::where('trip_id', $trip->id)
                ->where('child_id', $target->child_id)
                ->where('stop_type', $stopType)
                ->first();

            if (!$tripStop) {
                $target->update(['trip_id' => $trip->id, 'applied' => false, 'skip_reason' => 'لا توجد محطة مطابقة لهذا الطفل في الرحلة.']);
                continue;
            }

            if (!in_array($tripStop->status, TripStop::NON_FINAL_STATUSES, true)) {
                $target->update(['trip_id' => $trip->id, 'trip_stop_id' => $tripStop->id, 'applied' => false, 'skip_reason' => 'المحطة نُفّذت بالفعل.']);
                continue;
            }

            $tripStop->update([
                'lat'   => $request->new_lat,
                'lng'   => $request->new_lng,
                'label' => $request->new_label,
            ]);

            $target->update([
                'trip_id'      => $trip->id,
                'trip_stop_id' => $tripStop->id,
                'applied'      => true,
                'skip_reason'  => null,
            ]);
        }
    }

    /**
     * تحصيل الرسم لحظة موافقة السائق — نفس المنطق المالي القديم مع تعديل بسيط
     * ليعمل على الطلب المُجمَّع (رسم واحد لكل الطلب لا لكل طفل).
     */
    protected function collectLocationChangeFee(LocationChangeRequest $changeRequest): void
    {
        $feeDinar = (float) ($changeRequest->fee_amount ?? 0);

        if ($feeDinar <= 0 || $changeRequest->is_settled) {
            return;
        }

        $ledger = app(\App\Services\Shared\FinancialLedgerService::class);

        $parent = $ledger->resolveParent($changeRequest->parent_id);
        $driver = Driver::find($changeRequest->driver_id);

        if (!$parent || !$driver) {
            Log::warning("تعذّر تحصيل رسم تغيير الموقع للطلب ID {$changeRequest->id}: بيانات ولي الأمر أو السائق ناقصة.");
            return;
        }

        $feeCents = (int) round($feeDinar * 100);

        if ((int) $parent->balance < $feeCents) {
            Log::warning(
                "رصيد ولي الأمر لا يغطي رسم تغيير الموقع للطلب ID {$changeRequest->id} "
                . "({$feeDinar} د.ل). اعتُمد التغيير ويبقى الرسم غير محصّل."
            );
            return;
        }

        $commissionCents = (int) round($feeCents * PricingSetting::commissionRateFraction());
        $driverNetCents  = max(0, $feeCents - $commissionCents);

        $parentBefore = (int) $parent->balance;
        $driverBefore = (int) $driver->balance;

        $parent->withdraw($feeCents);
        $driver->deposit($driverNetCents);

        $vault = \App\Models\Shared\MasterEscrowVault::getVault();
        $vault->increment('driver_available_pool', $driverNetCents);
        $vault->increment('platform_revenue_pool', $commissionCents);

        $ledger->recordLedgerEntry(
            \App\Services\Shared\FinancialLedgerService::parentAccount($parent),
            \App\Services\Shared\FinancialLedgerService::driverAccount($driver),
            $driverNetCents,
            'location_change_fee',
            $parentBefore,
            (int) $parent->fresh()->balance,
            "LOCCHG-{$changeRequest->id}",
            [
                'location_change_request_id' => $changeRequest->id,
                'driver_balance_before'      => $driverBefore,
            ]
        );

        if ($commissionCents > 0) {
            $ledger->recordLedgerEntry(
                \App\Services\Shared\FinancialLedgerService::parentAccount($parent),
                'platform_revenue_pool',
                $commissionCents,
                'platform_commission',
                0,
                $commissionCents,
                "LOCCHG-COMMISSION-{$changeRequest->id}",
                ['location_change_request_id' => $changeRequest->id]
            );
        }

        $changeRequest->update(['is_settled' => true]);
    }

    private function notifyParentOnResponse(LocationChangeRequest $request, bool $approved, ?string $reason): void
    {
        try {
            $request->loadMissing(['parent', 'targets.child']);
            $parentUser = $request->parent;
            if (!$parentUser) {
                return;
            }

            $childrenNames = $request->targets
                ->map(fn ($t) => $t->child?->full_name)
                ->filter()
                ->unique()
                ->join('، ') ?: 'أطفالك';

            $pointLabel = $request->point_type === LocationChangeRequest::POINT_TYPE_PICKUP ? 'استلام' : 'تسليم';
            $dayText    = $request->change_date ? " ليوم " . Carbon::parse($request->change_date)->toDateString() : '';
            $fee        = (float) ($request->fee_amount ?? 0);

            if ($approved) {
                $settled = $request->fresh()->is_settled;
                $this->notifyUser(
                    $parentUser,
                    'تمت الموافقة على تغيير الموقع 🟢',
                    $settled
                        ? "وافق السائق على تغيير موقع {$pointLabel} ({$childrenNames}){$dayText}. تم خصم {$fee} د.ل من محفظتك."
                        : "وافق السائق على تغيير موقع {$pointLabel} ({$childrenNames}){$dayText}. الرسم ({$fee} د.ل) مستحق وسيُحصَّل عند توفر الرصيد.",
                    'location_change_approved',
                    (string) $request->id,
                    ['children_names' => $childrenNames, 'fee' => $fee]
                );
            } else {
                $this->notifyUser(
                    $parentUser,
                    'تم رفض طلب تغيير الموقع 🔴',
                    "عذراً، رفض السائق طلب تغيير موقع ({$childrenNames}){$dayText}." . ($reason ? " السبب: {$reason}" : ''),
                    'location_change_rejected',
                    (string) $request->id,
                    ['children_names' => $childrenNames]
                );
            }
        } catch (Throwable $e) {
            Log::warning("فشل إرسال إشعار الرد على طلب تغيير الموقع ID {$request->id}: " . $e->getMessage());
        }
    }

    // =========================================================================
    // 6) الإلغاء (من ولي الأمر) — قبل رد السائق فقط
    // =========================================================================

    public function cancelPendingRequest(int $userId, int $requestId): LocationChangeRequest
    {
        $request = LocationChangeRequest::where('id', $requestId)->first();
        if (!$request) {
            throw new Exception('الطلب غير موجود.');
        }
        if ($request->parent_id !== $userId) {
            throw new Exception('هذا الطلب لا يخصك.');
        }
        if ($request->status !== LocationChangeRequest::STATUS_PENDING) {
            throw new Exception('لا يمكن إلغاء طلب تم الرد عليه مسبقاً.');
        }

        $request->update([
            'status'       => LocationChangeRequest::STATUS_CANCELLED,
            'responded_at' => now(),
        ]);

        return $request->fresh(['driver.user', 'targets.child']);
    }

    // =========================================================================
    // 7) القوائم (ولي الأمر / السائق)
    // =========================================================================

    public function getParentRequests(int $userId, ?string $status = null)
    {
        return LocationChangeRequest::where('parent_id', $userId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['driver.user', 'targets.child', 'child'])
            ->orderByDesc('id')
            ->get();
    }

    public function getDriverRequests(int $userId, ?string $status = null)
    {
        $driver = Driver::where('user_id', $userId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        return LocationChangeRequest::where('driver_id', $driver->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['parent', 'targets.child', 'child'])
            ->orderByDesc('id')
            ->get();
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    private function notifyUser(User|null $user, string $title, string $message, string $type, ?string $entityId = null, array $extra = []): void
    {
        if ($user) {
            $this->notificationService->sendToUser($user, $type, array_merge([
                'title'       => $title,
                'message'     => $message,
                'entity_type' => 'location_change_request',
                'entity_id'   => $entityId,
            ], $extra));
        }
    }
}
