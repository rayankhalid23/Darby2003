<?php

namespace App\Services\Trip;

use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\SubscriptionRequest;
use App\Support\GeoEstimator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * خطأ يمنع بناء مسار صالح للتشغيل (بيانات ناقصة أو غير قابلة للترجمة إلى فترة/اتجاه).
 * يُرمى ولا يُبتلع: قبول اشتراك بمسار نصف مبني أسوأ من رفض القبول.
 */
class RouteStopSyncException extends Exception
{
    protected string $errorCode;

    public function __construct(string $message, string $errorCode = 'ROUTE_STOP_SYNC_ERROR', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

/**
 * يحافظ على تزامن الـ Master Route (routes + route_stops) لكل سائق/فترة (shift_slot)
 * مع الاشتراكات النشطة الفعلية — إضافة عند القبول، وإزالة عند الإلغاء/الانتهاء.
 * لا يمس أبداً النظام اليدوي القديم (route_id/sort_order على active_subscriptions)
 * الذي يستخدمه DriverRouteController/RouteRecommendationService.
 */
class MasterRouteStopSyncService
{
    /** وقت افتراضي لانطلاق رحلة الإياب حين لا يحدد الطلب وقت انصراف. */
    public const DEFAULT_RETURN_START_TIME = '14:00:00';

    /** وقت افتراضي لانطلاق رحلة الذهاب حين لا يحدد الطلب وقت اصطحاب. */
    public const DEFAULT_GO_START_TIME = '07:00:00';

    /**
     * يُستدعى بعد قبول طلب اشتراك وإنشاء المسار الأساسي (Primary Route) والاشتراكات النشطة.
     * يضبط shift_slot على المسار الأساسي، ويزامن محطاته، وينشئ/يزامن مسار الـ slot الثاني
     * (فقط عندما تكون direction=both، مثال: morning_go + morning_return).
     */
    public function syncOnAcceptance(SubscriptionRequest $req, Route $primaryRoute, array $slots): void
    {
        if (empty($slots)) {
            // فترة/اتجاه غير قابلين للترجمة إلى slot معروف: المسار سيبقى بلا محطات
            // وبلا shift_slot فلن يولّد له مولّد الرحلات اليومية أي رحلة أبداً.
            throw new RouteStopSyncException(
                'تعذّر تحديد فترة/اتجاه الرحلة من بيانات الطلب، فلا يمكن بناء مسار قابل للتشغيل.',
                'UNRESOLVABLE_SHIFT_SLOT'
            );
        }

        $activeSubs = ActiveSubscription::where('subscription_request_id', $req->id)
            ->with('child')
            ->get();

        if ($activeSubs->isEmpty()) {
            return;
        }

        $req->loadMissing('children');

        DB::transaction(function () use ($req, $primaryRoute, $slots, $activeSubs) {
            // ⚠️ كل طفل يُوضع فقط على الـ slots التي اشترك فيها هو بالذات. سابقاً كان
            // اتجاه أول طفل في الطلب يُطبَّق على الجميع، فيُوضع طفل مشترك بالذهاب فقط
            // على مسار الإياب أيضاً: السائق يمر على منزله ويُشعَر ولي أمره برحلة لم يدفعها.
            $subsBySlot = [];
            foreach ($activeSubs as $sub) {
                foreach ($this->resolveSubscriptionSlots($req, $sub) as $slot) {
                    $subsBySlot[$slot][] = $sub;
                }
            }

            if (empty($subsBySlot)) {
                throw new RouteStopSyncException(
                    'تعذّر تحديد فترة/اتجاه أي طفل في هذا الطلب، فلا يمكن بناء مسار قابل للتشغيل.',
                    'UNRESOLVABLE_SHIFT_SLOT'
                );
            }

            $primarySlot = $slots[0];
            $primaryRoute->shift_slot = $primarySlot;
            $primaryRoute->save();

            $routesBySlot = [$primarySlot => $primaryRoute];

            foreach (array_keys($subsBySlot) as $slot) {
                if (!isset($routesBySlot[$slot])) {
                    $routesBySlot[$slot] = $this->resolveOrCreateRouteForSlot($req, $primaryRoute, $slot);
                }
            }

            foreach ($routesBySlot as $slot => $route) {
                $slotSubs = collect($subsBySlot[$slot] ?? []);

                if ($slotSubs->isEmpty()) {
                    continue;
                }

                $this->addChildStopsToRoute($route, $slotSubs);
                $this->resequenceRoute($route);
            }
        });
    }

    /**
     * الـ slots التي يخص هذا الاشتراك بالذات — تُقرأ من صف الطفل في request_children
     * (وليس من أول طفل في الطلب)، مع الرجوع لبيانات الطلب فقط عند غياب بيانات الطفل.
     */
    private function resolveSubscriptionSlots(SubscriptionRequest $req, ActiveSubscription $sub): array
    {
        $pivot = $req->children?->firstWhere('id', $sub->child_id)?->pivot;

        $timing    = $pivot?->timing ?? 'MORNING';
        $direction = $req->trip_direction ?? 'both';

        return DriverSeatSlot::resolveSlots($timing, $direction);
    }

    /**
     * يعيد استخدام مسار السائق النشط لهذا الـ slot إن وُجد، وإلا ينشئه بوقت انطلاق صحيح.
     */
    private function resolveOrCreateRouteForSlot(SubscriptionRequest $req, Route $primaryRoute, string $slot): Route
    {
        $existing = Route::where('driver_id', $primaryRoute->driver_id)
            ->where('shift_slot', $slot)
            ->where('status', 'Active')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Route::create([
            'driver_id'               => $primaryRoute->driver_id,
            'vehicle_id'              => $primaryRoute->vehicle_id,
            'subscription_request_id' => $req->id,
            'route_name'              => 'مسار رئيسي - ' . (DriverSeatSlot::slotLabels()[$slot] ?? $slot),
            'route_type'              => str_starts_with($slot, 'morning') ? 'Morning' : 'Afternoon',
            'shift_slot'              => $slot,
            'start_time'              => $this->resolveSlotStartTime($req, $slot),
            'status'                  => 'Active',
        ]);
    }

    /**
     * وقت انطلاق المسار حسب اتجاهه.
     *
     * ⚠️ سابقاً كان مسار الإياب يرث start_time لمسار الذهاب (وقت الاصطحاب الصباحي)،
     * فتقع نافذة التوليد T-30 لرحلة العودة في الصباح الباكر بدل وقت الانصراف.
     */
    public function resolveSlotStartTime(SubscriptionRequest $req, string $slot): string
    {
        $raw = DriverSeatSlot::isGoSlot($slot)
            ? ($req->pickup_time  ?? self::DEFAULT_GO_START_TIME)
            : ($req->dropoff_time ?? self::DEFAULT_RETURN_START_TIME);

        return $this->normalizeTime(
            $raw,
            DriverSeatSlot::isGoSlot($slot) ? self::DEFAULT_GO_START_TIME : self::DEFAULT_RETURN_START_TIME
        );
    }

    private function normalizeTime(mixed $value, string $fallback): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }

        return $fallback;
    }

    /**
     * يُستدعى من DriverRouteController عند إسناد/نقل اشتراك لمسار يدوياً
     * (assign-route / move-route). بدون هذا الاستدعاء كان `active_subscriptions.
     * route_id` يتحدّث بينما تبقى `route_stops` فارغة تماماً من محطة الطفل — فيَظهر
     * الإسناد ناجحاً بواجهة السائق (children_count يرتفع) لكن DailyTripGenerationService
     * لا يرى هذا الطفل إطلاقاً لأنه يبني الرحلة اليومية حصراً من route_stops، فلا
     * يُولَّد له أي توقّف اصطحاب/إنزال أبداً.
     */
    public function addChildToRoute(Route $route, ActiveSubscription $sub): void
    {
        $sub->loadMissing('child');
        $this->addChildStopsToRoute($route, collect([$sub]));
        $this->resequenceRoute($route->fresh());
    }

    /**
     * يُستدعى عند تحول حالة اشتراك نشط إلى cancelled/completed.
     * يزيل محطة منزل الطفل من كل مسار موجودة به، ويزيل محطة المدرسة أيضاً
     * إذا لم يعد هناك طفل آخر نشط من نفس المدرسة على هذا المسار.
     */
    public function removeChildFromDriverRoutes(ActiveSubscription $sub): void
    {
        $childId = $sub->child_id;
        if (!$childId) {
            return;
        }

        $routeIds = RouteStop::where('stop_type', RouteStop::TYPE_HOME)
            ->where('child_id', $childId)
            ->whereHas('route', function ($q) use ($sub) {
                $q->where('driver_id', $sub->driver_id);
            })
            ->pluck('route_id')
            ->unique();

        foreach ($routeIds as $routeId) {
            $route = Route::find($routeId);
            if (!$route) {
                continue;
            }

            // ⚠️ اشتراك نشط آخر لنفس الطفل مع نفس السائق قد يكون ما زال يحتاج هذه المحطة
            // (تجديد متداخل، أو اشتراك تالٍ في الفترة نفسها). بدون هذا الفحص كان إلغاء
            // الاشتراك القديم يمحو الطفل من مسار السائق كلياً رغم بقاء اشتراكه الفعّال.
            if ($this->childStillNeedsRoute($childId, $sub, $route)) {
                continue;
            }

            RouteStop::where('route_id', $routeId)
                ->where('stop_type', RouteStop::TYPE_HOME)
                ->where('child_id', $childId)
                ->delete();

            $remainingChildIds = RouteStop::where('route_id', $routeId)
                ->where('stop_type', RouteStop::TYPE_HOME)
                ->pluck('child_id');

            $schoolStops = RouteStop::where('route_id', $routeId)
                ->where('stop_type', RouteStop::TYPE_SCHOOL)
                ->get();

            foreach ($schoolStops as $schoolStop) {
                $stillNeeded = Child::whereIn('id', $remainingChildIds)
                    ->where('school_id', $schoolStop->school_id)
                    ->exists();

                if (!$stillNeeded) {
                    $schoolStop->delete();
                }
            }

            $remainingStopsCount = RouteStop::where('route_id', $routeId)->count();

            if ($remainingStopsCount === 0) {
                $route->status = 'Inactive';
                $route->total_distance = 0;
                $route->estimated_duration = 0;
                $route->save();
            } else {
                $this->resequenceRoute($route->fresh());
            }
        }
    }

    /**
     * هل ما زال للطفل اشتراك نشط آخر (غير الاشتراك المُلغى) مع نفس السائق يغطي
     * فترة/اتجاه هذا المسار تحديداً؟
     */
    private function childStillNeedsRoute(int $childId, ActiveSubscription $cancelledSub, Route $route): bool
    {
        $otherSubs = ActiveSubscription::forDriver($cancelledSub->driver_id)
            ->forChild($childId)
            ->where('id', '!=', $cancelledSub->id)
            ->where('status', 'active')
            ->with('subscriptionRequest.children')
            ->get();

        if ($otherSubs->isEmpty()) {
            return false;
        }

        // مسار بلا shift_slot لا يمكن مطابقته بدقة — نتحفّظ ونُبقي المحطة
        if (!$route->shift_slot) {
            return true;
        }

        foreach ($otherSubs as $other) {
            $req = $other->subscriptionRequest;
            if (!$req) {
                // لا نستطيع تحديد فتراته: التحفّظ أأمن من حذف محطة اشتراك قائم
                return true;
            }

            if (in_array($route->shift_slot, $this->resolveSubscriptionSlots($req, $other), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * يُحدّث إحداثيات/تسمية محطة منزل طفل معيّن في كل المسارات الرئيسية النشطة لنفس السائق
     * (تُستخدم عند موافقة السائق على طلب تغيير موقع الاستلام من ولي الأمر)، ثم يعيد ترتيب
     * كل مسار متأثر. أيضاً يُحدّث محطات الرحلات (trip_stops) المُجدولة مستقبلاً والتي لم
     * تبدأ بعد (status = pending) حتى تنعكس عليها نقطة الاستلام الجديدة فوراً.
     */
    public function updateChildHomeStop(int $childId, int $driverId, float $lat, float $lng, ?string $label): void
    {
        $routeIds = RouteStop::where('stop_type', RouteStop::TYPE_HOME)
            ->where('child_id', $childId)
            ->whereHas('route', function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)->where('status', 'Active');
            })
            ->pluck('route_id')
            ->unique();

        foreach ($routeIds as $routeId) {
            RouteStop::where('route_id', $routeId)
                ->where('stop_type', RouteStop::TYPE_HOME)
                ->where('child_id', $childId)
                ->update(['lat' => $lat, 'lng' => $lng, 'label' => $label]);

            $route = Route::find($routeId);
            if ($route) {
                $this->resequenceRoute($route);
            }
        }

        \App\Models\Shared\TripStop::whereHas('trip', function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)
                  ->where('trip_date', '>=', now()->toDateString());
            })
            ->where('stop_type', RouteStop::TYPE_HOME)
            ->where('child_id', $childId)
            ->where('status', \App\Models\Shared\TripStop::STATUS_PENDING)
            ->update(['lat' => $lat, 'lng' => $lng, 'label' => $label]);
    }

    private function addChildStopsToRoute(Route $route, Collection $activeSubs): void
    {
        foreach ($activeSubs as $sub) {
            if (!$sub->child_id) {
                continue;
            }

            $childName = $sub->child?->full_name ?? $sub->child?->name ?? ('الطفل #' . $sub->child_id);

            // ⚠️ محطة بلا إحداثيات تنتقل كما هي إلى trip_stops، فيُقفَز فحص الـ Geofence
            // كلياً عند الصعود/النزول (شرط $targetLat !== null) وتُحسب مسافتها صفراً،
            // فيختل ترتيب المسار. الرفض المبكر أأمن من مسار غير قابل للتحقق.
            if ($sub->pickup_lat === null || $sub->pickup_lng === null) {
                throw new RouteStopSyncException(
                    "تعذّر بناء مسار السائق: لا توجد إحداثيات لموقع اصطحاب ({$childName}). يرجى تحديد موقع المنزل أولاً.",
                    'MISSING_PICKUP_COORDINATES'
                );
            }

            // ⚠️ updateOrCreate وليس firstOrCreate: مع firstOrCreate كانت محطة موجودة
            // مسبقاً تتجاهل إحداثيات الاشتراك الجديد، فيبقى الطفل في المسار على عنوانه
            // القديم إلى الأبد بعد انتقاله.
            RouteStop::updateOrCreate(
                [
                    'route_id'  => $route->id,
                    'stop_type' => RouteStop::TYPE_HOME,
                    'child_id'  => $sub->child_id,
                ],
                [
                    'lat'   => $sub->pickup_lat,
                    'lng'   => $sub->pickup_lng,
                    'label' => $sub->pickup_label,
                ]
            );

            $schoolId = $sub->child->school_id ?? null;
            if ($schoolId) {
                if ($sub->dropoff_lat === null || $sub->dropoff_lng === null) {
                    throw new RouteStopSyncException(
                        "تعذّر بناء مسار السائق: لا توجد إحداثيات لمدرسة ({$childName}).",
                        'MISSING_DROPOFF_COORDINATES'
                    );
                }

                RouteStop::updateOrCreate(
                    [
                        'route_id'  => $route->id,
                        'stop_type' => RouteStop::TYPE_SCHOOL,
                        'school_id' => $schoolId,
                    ],
                    [
                        'lat'   => $sub->dropoff_lat,
                        'lng'   => $sub->dropoff_lng,
                        'label' => $sub->dropoff_label,
                    ]
                );
            }
        }
    }

    /**
     * يعيد ترتيب محطات المسار حسب اتجاه الوردية (ذهاب: منازل ثم مدرسة | إياب: مدرسة ثم منازل)
     * باستخدام خوارزمية أقرب جار (Nearest Neighbor)، ثم يعيد احتساب المسافة والزمن التقديريين.
     */
    private function resequenceRoute(Route $route): void
    {
        $stops = RouteStop::where('route_id', $route->id)->get();

        $homePoints = $stops->where('stop_type', RouteStop::TYPE_HOME)
            ->map(fn($s) => ['id' => $s->id, 'lat' => (float) $s->lat, 'lng' => (float) $s->lng])
            ->values()->all();

        $schoolPoints = $stops->where('stop_type', RouteStop::TYPE_SCHOOL)
            ->map(fn($s) => ['id' => $s->id, 'lat' => (float) $s->lat, 'lng' => (float) $s->lng])
            ->values()->all();

        if (empty($homePoints) && empty($schoolPoints)) {
            return;
        }

        $isGo = DriverSeatSlot::isGoSlot($route->shift_slot ?? '');
        $finalOrder = GeoEstimator::orderStopsForDirection($homePoints, $schoolPoints, $isGo);

        foreach ($finalOrder as $index => $point) {
            RouteStop::where('id', $point['id'])->update(['sequence_order' => $index + 1]);
        }

        $distanceKm = GeoEstimator::totalPathDistanceKm($finalOrder);

        $route->total_distance = round($distanceKm, 2);
        $route->estimated_duration = max(5, GeoEstimator::estimateMinutes($distanceKm));
        $route->save();
    }
}
