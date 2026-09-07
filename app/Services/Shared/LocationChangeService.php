<?php

namespace App\Services\Shared;

use App\Models\Driver\Driver;
use App\Models\Parent\Address;
use App\Models\Parent\ParentModel;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\LocationChangeRequest;
use App\Models\Shared\PricingSetting;
use App\Models\User;
use App\Support\GeoEstimator;
use App\Services\Notification\NotificationService;
use App\Services\Trip\MasterRouteStopSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

/**
 * يدير طلبات ولي الأمر لتغيير موقع استلام (pickup) أو تسليم (dropoff) طفله
 * ضمن اشتراك نشط، مع دورة موافقة/رفض من السائق قبل تفعيل التغيير فعلياً.
 */
class LocationChangeService
{
    protected MasterRouteStopSyncService $routeStopSyncService;
    protected NotificationService $notificationService;

    public function __construct(
        MasterRouteStopSyncService $routeStopSyncService,
        NotificationService $notificationService
    ) {
        $this->routeStopSyncService = $routeStopSyncService;
        $this->notificationService = $notificationService;
    }

    /**
     * يجهّز خيارات الاختيار لولي الأمر: مواقعه المحفوظة + اشتراكاته النشطة (الرحلة/الطفل/السائق)
     * التي يمكنه طلب تغيير نقطة الاستلام أو التسليم فيها.
     */
    public function getChangeableOptions(int $userId): array
    {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            throw new Exception('هذا الحساب غير مسجل كولي أمر في النظام.');
        }

        $parentIds = array_values(array_unique(array_filter([$userId, $parent->id])));
        // جدول addresses يربط العنوان بالمستخدم عبر user_id لا parent_id — الاستعلام
        // بـ parent_id كان يسقط بخطأ «Unknown column» ويعطّل شاشة تغيير الموقع بالكامل.
        $addresses = Address::whereIn('user_id', $parentIds)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        $activeSubscriptions = ActiveSubscription::whereHas('subscriptionRequest', function ($q) use ($userId, $parent) {
                $q->where('parent_id', $parent->id)->orWhere('parent_id', $userId);
            })
            ->where('status', 'active')
            ->with(['child:id,full_name,photo_url', 'driver.user:id,full_name', 'route:id,route_name,route_type,shift_slot,start_time'])
            ->get()
            ->map(function (ActiveSubscription $sub) {
                return [
                    'active_subscription_id' => $sub->id,
                    'child'                  => [
                        'id'   => $sub->child?->id,
                        'name' => $sub->child?->full_name,
                        'photo_url' => $sub->child?->photo_url,
                    ],
                    'driver' => [
                        'id'   => $sub->driver_id,
                        'name' => $sub->driver?->user?->full_name,
                    ],
                    'trip' => [
                        'timing'         => $sub->route?->route_type ?? $sub->route?->shift_slot,
                        'direction'      => $sub->route?->shift_slot ?? $sub->route?->route_type,
                        'timing_text'    => $sub->route?->route_type ?? null,
                        'direction_text' => $sub->route?->shift_slot ?? null,
                    ],
                    'current_pickup' => [
                        'lat'   => $sub->pickup_lat,
                        'lng'   => $sub->pickup_lng,
                        'label' => $sub->pickup_label,
                    ],
                    'current_dropoff' => [
                        'lat'   => $sub->dropoff_lat,
                        'lng'   => $sub->dropoff_lng,
                        'label' => $sub->dropoff_label,
                    ],
                ];
            });

        return [
            'addresses'            => $addresses,
            'active_subscriptions' => $activeSubscriptions,
        ];
    }

    /**
     * يبني عرض السعر الكامل لتغيير الموقع دون حفظ أي شيء: معلومات الرحلة المختارة،
     * سعرها، المسافة بين الموقع الجديد والموقع الحالي، شريحة الرسوم المطبَّقة،
     * والتفصيل المالي (الإجمالي / عمولة المنصة / صافي السائق).
     *
     * تستخدمه شاشة المعاينة عند ولي الأمر وأيضاً عملية الإنشاء نفسها، حتى يكون
     * الرقم الذي رآه ولي الأمر هو نفسه الرقم المحفوظ تماماً.
     */
    public function quoteChange(
        int $userId,
        int $activeSubscriptionId,
        string $pointType,
        ?int $addressId,
        ?float $lat,
        ?float $lng,
        ?string $label,
        ?string $changeDate = null
    ): array {
        $parent = ParentModel::where('user_id', $userId)->first();
        if (!$parent) {
            throw new Exception('هذا الحساب غير مسجل كولي أمر في النظام.');
        }

        $activeSub = ActiveSubscription::where('id', $activeSubscriptionId)
            ->whereHas('subscriptionRequest', function ($q) use ($userId, $parent) {
                $q->where('parent_id', $parent->id)->orWhere('parent_id', $userId);
            })
            ->with(['child', 'driver.user', 'route', 'subscriptionRequest'])
            ->first();

        if (!$activeSub) {
            throw new Exception('الاشتراك النشط غير موجود أو لا تملك صلاحية الوصول إليه.');
        }

        if ($activeSub->status !== 'active') {
            throw new Exception('لا يمكن طلب تغيير الموقع لاشتراك غير نشط.');
        }

        $newPoint   = $this->resolveNewPoint($userId, $parent->id, $addressId, $lat, $lng, $label);
        $parsedDate = $changeDate ? \Carbon\Carbon::parse($changeDate)->toDateString() : now()->toDateString();

        $isPickup     = $pointType === LocationChangeRequest::POINT_TYPE_PICKUP;
        $currentLat   = (float) ($isPickup ? $activeSub->pickup_lat : $activeSub->dropoff_lat);
        $currentLng   = (float) ($isPickup ? $activeSub->pickup_lng : $activeSub->dropoff_lng);
        $currentLabel = $isPickup ? $activeSub->pickup_label : $activeSub->dropoff_label;

        if (!$currentLat || !$currentLng) {
            throw new Exception('لا يمكن حساب رسوم التغيير لأن الموقع الحالي المسجّل في الاشتراك غير مضبوط. يرجى مراجعة الدعم.');
        }

        // المسافة المعتمدة في التسعير هي المسافة بين الموقع الجديد والموقع الحالي للطفل (المنزل).
        $distanceKm = round(
            GeoEstimator::haversineKm($currentLat, $currentLng, $newPoint['lat'], $newPoint['lng']),
            2
        );

        $tier = PricingSetting::resolveFeeTier($distanceKm);
        if ($tier === null) {
            $max = PricingSetting::MAX_LOCATION_CHANGE_DISTANCE_KM;
            throw new Exception("المسافة بين الموقع الجديد والموقع الحالي ({$distanceKm} كم) تتجاوز الحد الأقصى المسموح به ({$max} كم)، ولا توجد شريحة رسوم مطبَّقة عليها.");
        }

        $feeAmount = round(PricingSetting::feeForTier($tier), 2);
        $fee       = $this->splitFee($feeAmount);

        return [
            'active_subscription' => $activeSub,
            'parsed_date'         => $parsedDate,
            'point_type'          => $pointType,
            'address_id'          => $newPoint['address_id'],
            'new_lat'             => $newPoint['lat'],
            'new_lng'             => $newPoint['lng'],
            'new_label'           => $newPoint['label'],
            'distance_km'         => $distanceKm,
            'fee_tier'            => $tier,
            'fee'                 => $fee,
            'payload'             => [
                'active_subscription_id' => $activeSub->id,
                'point_type'             => $pointType,
                'change_date'            => $parsedDate,
                'trip'                   => $this->buildTripSummary($activeSub),
                'current_location'       => [
                    'lat'   => $currentLat,
                    'lng'   => $currentLng,
                    'label' => $currentLabel,
                ],
                'new_location'           => [
                    'lat'   => $newPoint['lat'],
                    'lng'   => $newPoint['lng'],
                    'label' => $newPoint['label'],
                ],
                'distance_km'            => $distanceKm,
                'fee_tier'               => $tier,
                'fee_tier_label'         => PricingSetting::TIER_LABELS[$tier],
                'fee_breakdown'          => $fee,
                'fee_tiers'              => PricingSetting::locationChangeFeeTiers(),
                'currency'               => 'د.ل',
            ],
        ];
    }

    /**
     * إنشاء طلب تغيير موقع جديد من ولي الأمر، بانتظار موافقة السائق.
     *
     * التغيير يخص رحلة واحدة فقط (اشتراك واحد + يوم واحد محدد)، فلا يُطبَّق على
     * باقي أيام الاشتراك ولا على بقية رحلات المسار.
     */
    public function requestChange(
        int $userId,
        int $activeSubscriptionId,
        string $pointType,
        ?int $addressId,
        ?float $lat,
        ?float $lng,
        ?string $label,
        ?string $changeDate = null
    ): LocationChangeRequest {
        $quote      = $this->quoteChange($userId, $activeSubscriptionId, $pointType, $addressId, $lat, $lng, $label, $changeDate);
        $activeSub  = $quote['active_subscription'];
        $parsedDate = $quote['parsed_date'];
        $fee        = $quote['fee'];

        return DB::transaction(function () use ($activeSub, $pointType, $quote, $userId, $parsedDate, $fee) {
            $alreadyPending = LocationChangeRequest::where('active_subscription_id', $activeSub->id)
                ->where('point_type', $pointType)
                ->where('status', LocationChangeRequest::STATUS_PENDING)
                ->whereDate('change_date', $parsedDate)
                ->exists();

            if ($alreadyPending) {
                throw new Exception('يوجد بالفعل طلب تغيير موقع معلّق لنفس الرحلة ونفس اليوم بانتظار رد السائق.');
            }

            $changeRequest = LocationChangeRequest::create([
                'active_subscription_id'     => $activeSub->id,
                'child_id'                   => $activeSub->child_id,
                'parent_id'                  => $userId,
                'driver_id'                  => $activeSub->driver_id,
                'point_type'                 => $pointType,
                'change_date'                => $parsedDate,
                'is_single_day'              => true,
                'new_address_id'             => $quote['address_id'],
                'new_lat'                    => $quote['new_lat'],
                'new_lng'                    => $quote['new_lng'],
                'new_label'                  => $quote['new_label'],
                'distance_km'                => $quote['distance_km'],
                'fee_tier'                   => $quote['fee_tier'],
                'fee_amount'                 => $fee['gross_fee'],
                'commission_rate'            => $fee['commission_rate'],
                'platform_commission_amount' => $fee['platform_commission'],
                'driver_net_fee'             => $fee['driver_net_fee'],
                'status'                     => LocationChangeRequest::STATUS_PENDING,
            ]);

            $changeRequest->loadMissing(['child', 'driver.user']);

            try {
                $driverUser = $changeRequest->driver?->user;
                if ($driverUser) {
                    $childName  = $changeRequest->child?->full_name ?? 'الطفل';
                    $pointLabel = $pointType === LocationChangeRequest::POINT_TYPE_PICKUP ? 'الاستلام' : 'التسليم';
                    $netFee     = $fee['driver_net_fee'];
                    $this->notifyUser(
                        $driverUser,
                        'طلب تغيير موقع 📍',
                        "طلب ولي أمر الطفل ({$childName}) تغيير موقع {$pointLabel} ليوم {$parsedDate} إلى: " . ($quote['new_label'] ?? 'موقع جديد')
                            . " (المسافة: {$quote['distance_km']} كم — صافي الرسوم لك بعد خصم عمولة المنصة: {$netFee} د.ل). يرجى المراجعة والموافقة أو الرفض.",
                        'location_change_requested',
                        (string) $changeRequest->id,
                        [
                            'child_name'     => $childName,
                            'change_date'    => $parsedDate,
                            'distance_km'    => $quote['distance_km'],
                            'driver_net_fee' => $netFee,
                        ]
                    );
                }
            } catch (Throwable $e) {
                Log::warning("فشل إرسال إشعار طلب تغيير الموقع ID {$changeRequest->id}: " . $e->getMessage());
            }

            return $changeRequest;
        });
    }

    /**
     * يحدد إحداثيات الموقع الجديد إما من عنوان محفوظ لولي الأمر أو من إحداثيات مُرسلة.
     */
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

        return [
            'address_id' => null,
            'lat'        => $lat,
            'lng'        => $lng,
            'label'      => $label,
        ];
    }

    /**
     * يقسّم الرسم الإجمالي إلى عمولة منصة وصافي للسائق.
     * الجمع مضمون بالدينار الواحد: gross = commission + net (بدون فروقات تقريب).
     */
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

    /**
     * ملخص الرحلة المختارة (الطفل/السائق/التوقيت) مع سعرها كما هو مسجّل في عقد الاشتراك.
     */
    private function buildTripSummary(ActiveSubscription $activeSub): array
    {
        $pivot = null;
        $request = $activeSub->subscriptionRequest;
        if ($request) {
            $pivot = $request->children()->where('children.id', $activeSub->child_id)->first()?->pivot;
        }

        return [
            'active_subscription_id' => $activeSub->id,
            'child' => [
                'id'   => $activeSub->child?->id,
                'name' => $activeSub->child?->full_name,
            ],
            'driver' => [
                'id'   => $activeSub->driver_id,
                'name' => $activeSub->driver?->user?->full_name,
            ],
            'route' => [
                'id'         => $activeSub->route_id,
                'name'       => $activeSub->route?->route_name,
                'type'       => $activeSub->route?->route_type,
                'shift_slot' => $activeSub->route?->shift_slot,
                'start_time' => $activeSub->route?->start_time,
            ],
            'pickup_time'  => $activeSub->pickup_time,
            'dropoff_time' => $activeSub->dropoff_time,
            'pricing' => [
                'trip_price'                  => $pivot ? (float) $pivot->trip_price : null,
                'price_per_child'             => $pivot ? (float) $pivot->price_per_child : null,
                'total_amount_after_discount' => $pivot ? (float) $pivot->total_amount_after_discount : null,
                'driver_net_price'            => $pivot ? (float) $pivot->driver_net_price : null,
                'distance_km'                 => $pivot ? (float) $pivot->distance_km : null,
                'working_days_count'          => $request?->working_days_count,
                'trip_direction'              => $request?->trip_direction,
                'timing'                      => $pivot?->timing,
                'start_date'                  => $request?->start_date,
                'end_date'                    => $request?->end_date,
                'currency'                    => 'د.ل',
            ],
        ];
    }

    /**
     * موافقة/رفض السائق على طلب تغيير الموقع.
     */
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
            $activeSub = ActiveSubscription::lockForUpdate()->find($changeRequest->active_subscription_id);
            if (!$activeSub) {
                throw new Exception('الاشتراك النشط المرتبط بهذا الطلب لم يعد موجوداً.');
            }

            if ($approve) {
                $isSingleDay = (bool) $changeRequest->is_single_day;
                $changeDate  = $changeRequest->change_date ? \Carbon\Carbon::parse($changeRequest->change_date)->toDateString() : null;

                if ($isSingleDay && $changeDate) {
                    // التغيير يخص رحلة واحدة فقط: نحصر التحديث في مسار هذا الاشتراك وفي
                    // يوم التغيير المحدد، حتى لا يتأثر أي مسار آخر للسائق أو أي اشتراك
                    // آخر لنفس الطفل (مثلاً رحلة الصباح مقابل رحلة العودة).
                    $tripsQuery = \App\Models\Shared\Trip::where('driver_id', $activeSub->driver_id)
                        ->whereDate('trip_date', $changeDate);

                    if ($activeSub->route_id) {
                        $tripsQuery->where('route_id', $activeSub->route_id);
                    }

                    $trips = $tripsQuery->get();

                    $stopType = $changeRequest->point_type === LocationChangeRequest::POINT_TYPE_PICKUP
                        ? \App\Models\Shared\TripStop::TYPE_HOME
                        : \App\Models\Shared\TripStop::TYPE_SCHOOL;

                    foreach ($trips as $trip) {
                        $tripStop = \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                            ->where('child_id', $activeSub->child_id)
                            ->where('stop_type', $stopType)
                            ->first();

                        if ($tripStop && $tripStop->status === \App\Models\Shared\TripStop::STATUS_PENDING) {
                            $tripStop->update([
                                'lat'   => $changeRequest->new_lat,
                                'lng'   => $changeRequest->new_lng,
                                'label' => $changeRequest->new_label,
                            ]);
                        }
                    }
                } else {
                    // تغيير دائم على كامل الاشتراك والمسار المرجعي
                    if ($changeRequest->point_type === LocationChangeRequest::POINT_TYPE_PICKUP) {
                        $activeSub->update([
                            'pickup_lat'   => $changeRequest->new_lat,
                            'pickup_lng'   => $changeRequest->new_lng,
                            'pickup_label' => $changeRequest->new_label,
                        ]);

                        try {
                            $this->routeStopSyncService->updateChildHomeStop(
                                $activeSub->child_id,
                                $activeSub->driver_id,
                                $changeRequest->new_lat,
                                $changeRequest->new_lng,
                                $changeRequest->new_label
                            );
                        } catch (Throwable $e) {
                            Log::warning("فشل مزامنة مسار محطة الاستلام بعد الموافقة على الطلب ID {$changeRequest->id}: " . $e->getMessage());
                        }
                    } else {
                        $activeSub->update([
                            'dropoff_lat'   => $changeRequest->new_lat,
                            'dropoff_lng'   => $changeRequest->new_lng,
                            'dropoff_label' => $changeRequest->new_label,
                        ]);
                    }
                }

                $changeRequest->update([
                    'status'       => LocationChangeRequest::STATUS_APPROVED,
                    'responded_at' => now(),
                ]);

                // 💰 تحصيل رسم تغيير الموقع فوراً — داخل نفس معاملة الموافقة.
                //
                // ⚠️ كان الرسم يُسجَّل في `fee_amount` ويصل ولي الأمر إشعار بأنه
                // «سيُحسب مع الاشتراك»، ثم لا يمرّ الاشتراك المكتمل بأي كود يحوّله
                // لأحد: المكان الوحيد الذي كان يستهلكه هو مسار الإلغاء، ودالة
                // التقارير settleMonthlySubscription() كانت تعلّمه `is_settled`
                // دون تحريك قرش. أي إيراد ضائع في كل مرة ثم يُعلَّم كأنه حُصِّل.
                $this->collectLocationChangeFee($changeRequest, $activeSub);
            } else {
                $changeRequest->update([
                    'status'           => LocationChangeRequest::STATUS_REJECTED,
                    'rejection_reason' => $rejectionReason,
                    'responded_at'     => now(),
                ]);
            }

            $changeRequest->loadMissing(['child', 'parent', 'driver.user']);

            try {
                $parentUser = $changeRequest->parent;
                if ($parentUser) {
                    $childName  = $changeRequest->child?->full_name ?? 'الطفل';
                    $fee        = (float) ($changeRequest->fee_amount ?? PricingSetting::DEFAULT_LOCATION_CHANGE_FEE);
                    $pointLabel = $changeRequest->point_type === LocationChangeRequest::POINT_TYPE_PICKUP ? 'استلام' : 'تسليم';
                    $dayText    = $changeRequest->change_date ? " ليوم " . \Carbon\Carbon::parse($changeRequest->change_date)->toDateString() : '';

                    if ($approve) {
                        $this->notifyUser(
                            $parentUser,
                            'تمت الموافقة على تغيير الموقع 🟢',
                            // الصياغة تتبع السلوك الفعلي: الرسم يُخصم لحظة الموافقة
                            // لا «مع الاشتراك» كما كان الإشعار يَعِد بينما لا يُحصَّل أبداً.
                            $changeRequest->fresh()->is_settled
                                ? "وافق السائق على تغيير موقع {$pointLabel} الطفل ({$childName}){$dayText}، وتم تحديث مسار الرحلة وخصم رسوم التغيير ({$fee} د.ل) من محفظتك."
                                : "وافق السائق على تغيير موقع {$pointLabel} الطفل ({$childName}){$dayText}، وتم تحديث مسار الرحلة. رسوم التغيير ({$fee} د.ل) مستحقة وسيتم تحصيلها عند توفر رصيد في محفظتك.",
                            'location_change_approved',
                            (string) $changeRequest->id,
                            ['child_name' => $childName, 'fee' => $fee]
                        );
                    } else {
                        $this->notifyUser(
                            $parentUser,
                            'تم رفض طلب تغيير الموقع 🔴',
                            "عذراً، رفض السائق طلب تغيير موقع الطفل ({$childName}){$dayText}." . ($rejectionReason ? " السبب: {$rejectionReason}" : ''),
                            'location_change_rejected',
                            (string) $changeRequest->id,
                            ['child_name' => $childName]
                        );
                    }
                }
            } catch (Throwable $e) {
                Log::warning("فشل إرسال إشعار الرد على طلب تغيير الموقع ID {$changeRequest->id}: " . $e->getMessage());
            }

            return $changeRequest->fresh(['child', 'parent', 'driver.user', 'activeSubscription']);
        });
    }

    /**
     * تحصيل رسم تغيير الموقع لحظة موافقة السائق.
     *
     * تسلسل الحركة: محفظة ولي الأمر ← عمولة المنصة + صافي السائق.
     * الرسم مقابل عمل إضافي أنجزه السائق فوراً (تعديل المسار)، فلا معنى لحجزه
     * في الأمانات بانتظار رحلات لاحقة — يُصرف في نفس اللحظة ويُقفل السجل بـ
     * `is_settled` الذي صار الآن يعني «حُصِّل فعلاً» لا «حُسِب في تقرير».
     *
     * إن لم يكفِ رصيد ولي الأمر، تمضي الموافقة ويبقى الرسم غير محصّل ويُسجَّل
     * تحذير: رفض تغيير الموقع لأجل بضعة دنانير يعطّل خدمة الطفل بلا داعٍ،
     * والمبلغ يبقى مستحقاً على السجل لتسويته لاحقاً.
     */
    protected function collectLocationChangeFee(LocationChangeRequest $changeRequest, ActiveSubscription $activeSub): void
    {
        $feeDinar = (float) ($changeRequest->fee_amount ?? 0);

        if ($feeDinar <= 0 || $changeRequest->is_settled) {
            return;
        }

        $ledger = app(\App\Services\Shared\FinancialLedgerService::class);

        $parent = $ledger->resolveParent($changeRequest->parent_id);
        $driver = Driver::find($activeSub->driver_id);

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
                'active_subscription_id'     => $activeSub->id,
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

    public function getParentRequests(int $userId)
    {
        return LocationChangeRequest::where('parent_id', $userId)
            ->with(['child', 'driver.user', 'activeSubscription'])
            ->orderByDesc('id')
            ->get();
    }

    public function getDriverRequests(int $userId, ?string $status = null)
    {
        $driver = Driver::where('user_id', $userId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        $query = LocationChangeRequest::where('driver_id', $driver->id)
            ->with(['child', 'parent', 'activeSubscription']);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('id')->get();
    }

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
