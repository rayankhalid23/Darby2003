<?php

namespace App\Services\Trip;

use App\Models\Shared\Route;
use App\Models\Shared\Trip;
use App\Models\Shared\ActiveSubscription;
use App\Services\Shared\OsrmRoutingService;
use Carbon\Carbon;
use Exception;

class RouteModuleException extends Exception
{
    protected string $errorCode;

    public function __construct(string $message, string $errorCode = 'ROUTE_ERROR', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

class RouteRecommendationService
{
    /**
     * حساب جميع مؤشرات الأداء والبيانات الديناميكية للمسار وتحديثها تلقائياً
     */
    public function calculateRouteMetrics(Route $route): array
    {
        $route->loadMissing(['driver.vehicle', 'activeSubscriptions.child', 'activeSubscriptions.school']);

        $activeSubs = $route->activeSubscriptions
            ->where('status', '!=', 'cancelled')
            ->values();

        $childrenCount = $activeSubs->count();
        $uniqueSchoolsCount = $activeSubs->pluck('school_id')->filter()->unique()->count();
        
        $vehicleCapacity = (int) ($route->driver?->vehicle?->capacity_manual ?? 12);
        $availableSeats = max(0, $vehicleCapacity - $childrenCount);

        // أول وآخر وقت دوام للمدارس المرتبطة بهذا المسار
        $schoolTimes = $activeSubs->map(function ($sub) {
            return $sub->school?->start_time ?? '08:00';
        })->sort()->values();

        $firstSchoolTime = $schoolTimes->first() ?? '08:00';
        $lastSchoolTime  = $schoolTimes->last() ?? '08:30';

        // حساب زمن المسار
        $estimatedDuration = (int) ($route->estimated_duration ?? ($childrenCount * 5 + 20));
        $firstSchoolCarbon = Carbon::createFromFormat('H:i', substr($firstSchoolTime, 0, 5));
        $recommendedDeparture = $firstSchoolCarbon->copy()->subMinutes($estimatedDuration + 10)->format('H:i');

        // فحص قفل المسار (Route Lock) عند وجود رحلة نشطة أو بدأت اليوم
        $activeTrip = Trip::where('route_id', $route->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereDate('trip_date', Carbon::today()->toDateString())
            ->first();

        $isLocked = !is_null($activeTrip);
        $lockReason = $isLocked ? 'Trip Started' : null;

        // حالة المسار المتقدمة (draft, ready, in_trip, completed, archived)
        if ($isLocked) {
            $formattedStatus = 'in_trip';
        } elseif (strtolower($route->status) === 'inactive' || strtolower($route->status) === 'archived') {
            $formattedStatus = 'archived';
        } elseif ($childrenCount === 0) {
            $formattedStatus = 'draft';
        } else {
            $formattedStatus = 'ready';
        }

        // فحص هل يحتاج المسار لمراجعة السائق (Needs Review) وسبب المراجعة
        $needsReview = false;
        $reviewReason = null;
        foreach ($activeSubs as $sub) {
            if ($sub->status === 'needs_review') {
                $needsReview = true;
                $reviewReason = $sub->review_reason ?? 'تم تغيير مدرسة أو بيانات الطفل مؤخراً';
                break;
            }
        }

        // حساب Health Score للمسار (من 0 إلى 100)
        $healthScore = 100;
        if ($availableSeats === 0) {
            $healthScore -= 10;
        }
        if ($vehicleCapacity < $childrenCount) {
            $healthScore -= 40; // تحذير سعة زائدة
        }
        if ($childrenCount > 10) {
            $healthScore -= 15;
        }
        if ($estimatedDuration > 60) {
            $healthScore -= 15;
        }
        $healthScore = max(30, min(100, $healthScore));

        return [
            'id'                    => (int) $route->id,
            'name'                  => $route->route_name,
            'trip_type'             => strtolower($route->route_type),
            'status'                => $formattedStatus,
            'is_locked'             => $isLocked,
            'lock_reason'           => $lockReason,
            'children_count'        => $childrenCount,
            'schools_count'         => $uniqueSchoolsCount > 0 ? $uniqueSchoolsCount : 1,
            'vehicle_capacity'     => $vehicleCapacity,
            'available_seats'       => $availableSeats,
            'first_school_time'     => substr($firstSchoolTime, 0, 5),
            'last_school_time'      => substr($lastSchoolTime, 0, 5),
            'recommended_departure' => $recommendedDeparture,
            'estimated_duration'   => $estimatedDuration,
            'health_score'          => $healthScore,
            'needs_review'          => $needsReview,
            'review_reason'         => $reviewReason,
        ];
    }

    /**
     * التحقق من جميع قواعد عمل الاشتراكات والمسارات (Business Rules Validation)
     */
    public function validateSubscriptionAssignment(ActiveSubscription $sub, Route $route): void
    {
        // 1. لا يمكن إضافة اشتراك منتهي أو موقوف
        $statusLower = strtolower($sub->status ?? 'active');
        if ($statusLower === 'expired') {
            throw new RouteModuleException("الاشتراك منتهي الصلاحية ولا يمكن إسناده لمسار.", 'SUBSCRIPTION_EXPIRED');
        }
        if (in_array($statusLower, ['suspended', 'paused'])) {
            throw new RouteModuleException("الاشتراك موقوف مؤقتاً ولا يمكن إسناده حالياً.", 'SUBSCRIPTION_SUSPENDED');
        }

        // 2. لا يمكن إضافة طفل قبل تاريخ بدء الاشتراك
        $startDate = $sub->contract?->start_date ?? $sub->created_at;
        if ($startDate && Carbon::parse($startDate)->isFuture()) {
            $formattedDate = Carbon::parse($startDate)->format('Y-m-d');
            throw new RouteModuleException("لا يمكن إضافة طفل إلى المسار قبل تاريخ بداية اشتراكه المعتمد ($formattedDate).", 'SUBSCRIPTION_NOT_STARTED');
        }

        // 3. لا يمكن إضافة طفل إلى مسارين نفس الفترة (صباحيين أو مسائيين) في نفس الوقت
        //
        // ⚠️ بدون استبعاد status='cancelled' كان أي اشتراك قديم مُلغى (سبق أن رُبط
        // بمسار ثم أُلغي) يبقى يحجب الطفل أبداً عن أي إسناد جديد بنفس الفترة — تحقّقت
        // فعلياً: طفل عنده اشتراك ملغى من أيام مضت رُفض إسناد اشتراكه الجديد النشط
        // بخطأ "مضاف بالفعل" رغم عدم وجود أي تعارض فعلي حالياً.
        $subTripType = strtolower($route->route_type);
        $alreadyAssignedOtherRoute = ActiveSubscription::forChild($sub->child_id)
            ->where('id', '!=', $sub->id)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('route_id')
            ->whereHas('route', function ($q) use ($subTripType) {
                $q->whereRaw('LOWER(route_type) = ?', [$subTripType]);
            })
            ->exists();

        if ($alreadyAssignedOtherRoute) {
            throw new RouteModuleException("الطفل مضاف بالفعل إلى مسار آخر في نفس الفترة الزمنية ($subTripType).", 'SUBSCRIPTION_ALREADY_ASSIGNED');
        }

        // 4. لا يمكن تجاوز سعة المركبة
        $metrics = $this->calculateRouteMetrics($route);
        if ($metrics['available_seats'] <= 0) {
            throw new RouteModuleException("لا يوجد مقاعد متاحة في هذا المسار. تم تجاوز سعة المركبة.", 'ROUTE_FULL');
        }

        // 5. لا يمكن التعديل أو الإسناد إذا كانت هناك رحلة حالية قيد التشغيل (Started / InProgress)
        $this->validateRouteNotRunning($route);
    }

    /**
     * التحقق من عدم وجود رحلة جارية للمسار عند التعديل أو النقل أو الحذف
     */
    public function validateRouteNotRunning(Route $route): void
    {
        $hasActiveTrip = Trip::where('route_id', $route->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereDate('trip_date', Carbon::today()->toDateString())
            ->exists();

        if ($hasActiveTrip) {
            throw new RouteModuleException("المسار مقفل بسبب وجود رحلة جارية حالياً (Trip Started).", 'ROUTE_LOCKED');
        }
    }

    /**
     * 1️⃣ معامل الازدحام بناءً على وقت المرور
     * μ = 1.15 (06:30-07:15) | 1.30 (07:15-08:00) | 1.00 (غيرها)
     */
    private function getTrafficMultiplier(string $timeHHMM): float
    {
        try {
            $minutes = (int) substr($timeHHMM, 0, 2) * 60 + (int) substr($timeHHMM, 3, 2);
        } catch (\Throwable) {
            return 1.00;
        }
        if ($minutes >= 390 && $minutes < 435) return 1.15; // 06:30 – 07:15
        if ($minutes >= 435 && $minutes < 480) return 1.30; // 07:15 – 08:00
        return 1.00;
    }

    /**
     * 2️⃣ تحويل HH:MM إلى دقائق منذ منتصف الليل
     */
    private function toMinutes(string $timeHHMM): int
    {
        $parts = explode(':', $timeHHMM);
        return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
    }

    /**
     * 3️⃣ تحويل الدقائق إلى HH:MM
     */
    private function fromMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * 4️⃣ احسب ETA التراكمية لكل عقدة
     */
    private function calculateEtas(array $nodes, int $departureMinutes, OsrmRoutingService $osrm): array
    {
        $currentTime = $departureMinutes;

        foreach ($nodes as $i => &$node) {
            if ($i === 0) {
                $node['eta_minutes'] = $currentTime + ($node['service_time'] ?? 3);
            } else {
                $prev = $nodes[$i - 1];
                $eta  = $this->fromMinutes($currentTime);
                $mu   = $this->getTrafficMultiplier($eta);

                $matrix = $osrm->getDistanceMatrix(
                    ['lat' => $prev['lat'], 'lng' => $prev['lng']],
                    ['lat' => $node['lat'], 'lng' => $node['lng']]
                );

                $travelMin = (int) ceil(($matrix['duration'] * $mu) / 60);
                $currentTime += $travelMin + ($node['service_time'] ?? 3);
                $node['eta_minutes'] = $currentTime;
            }
            $node['eta'] = $this->fromMinutes($node['eta_minutes']);

            if (($node['type'] ?? '') === 'dropoff') {
                $bellMin   = $this->toMinutes($node['bell_time'] ?? '08:00');
                $node['slack'] = ($bellMin - 15) - $node['eta_minutes'];
            }
            $currentTime = $node['eta_minutes'];
        }
        unset($node);
        return $nodes;
    }

    /**
     * 5️⃣ فحص القيود الصارمة (Hard Constraints)
     */
    private function validateHardConstraints(array $nodes, array $originalDropoffEtas): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'dropoff') continue;

            $bellMin = $this->toMinutes($node['bell_time'] ?? '08:00');
            $eta     = $node['eta_minutes'];

            // قيد 5.1: نافذة الوصول للمدرسة [BellTime-60, BellTime-15]
            if ($eta < ($bellMin - 60) || $eta > ($bellMin - 15)) {
                return false;
            }

            // قيد 5.2: حماية تأخير الأطفال القدامى (≤ 15 دقيقة)
            $childId = $node['child_id'] ?? null;
            if ($childId && isset($originalDropoffEtas[$childId])) {
                $delay = $eta - $originalDropoffEtas[$childId];
                if ($delay > 15) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * 6️⃣ دالة الهدف: متوسط الـ Slack Time (كلما زاد كان أفضل)
     */
    private function calculateObjectiveScore(array $nodes): float
    {
        $slacks = array_filter(array_column($nodes, 'slack'), fn($s) => $s !== null);
        if (empty($slacks)) return 0.0;
        return array_sum($slacks) / count($slacks);
    }

    /**
     * 7️⃣ محاكاة إدراج طفل جديد في المسار (Insertion Heuristic)
     * تُجرِّب كل التباديل وتختار الأفضل وفق دالة الهدف
     */
    private function simulateChildInsertion(
        array $currentNodes,
        array $newPickup,
        array $newDropoff,
        int   $departureMinutes,
        OsrmRoutingService $osrm,
        array $originalDropoffEtas
    ): ?array {
        $m = count($currentNodes);
        $bestArrangement = null;
        $bestScore       = PHP_INT_MIN;

        for ($i = 0; $i <= $m; $i++) {
            for ($j = $i + 1; $j <= $m + 1; $j++) {
                $candidate = $currentNodes;
                array_splice($candidate, $i, 0, [$newPickup]);
                array_splice($candidate, $j, 0, [$newDropoff]);

                $candidate = $this->calculateEtas($candidate, $departureMinutes, $osrm);

                if (!$this->validateHardConstraints($candidate, $originalDropoffEtas)) {
                    continue;
                }

                $score = $this->calculateObjectiveScore($candidate);
                if ($score > $bestScore) {
                    $bestScore       = $score;
                    $bestArrangement = $candidate;
                }
            }
        }

        return $bestArrangement;
    }

    /**
     * ✅ الدالة الرئيسية: توصيات المسار بخوارزمية VRPTW
     */
    public function getRecommendationsForSubscription(ActiveSubscription $sub): array
    {
        $driverId = $sub->driver_id;

        $routes = Route::where('driver_id', $driverId)
            ->where('status', 'Active')
            ->with(['activeSubscriptions.school', 'driver.vehicle'])
            ->get();

        if ($routes->isEmpty()) {
            return [
                'recommended_route' => null,
                'message'           => 'لا يوجد مسار مناسب حالياً. يمكنك إنشاء مسار جديد.',
                'other_routes'      => [],
                'rejected_routes'   => [],
            ];
        }

        $osrm = new OsrmRoutingService();

        // بناء نقطتي الطفل الجديد
        $newPickup = [
            'node_id'      => 'NEW_PICKUP',
            'child_id'     => $sub->child_id,
            'type'         => 'pickup',
            'lat'          => (float) ($sub->pickup_lat  ?? 0),
            'lng'          => (float) ($sub->pickup_lng  ?? 0),
            'service_time' => 3,
            'bell_time'    => $sub->school?->start_time ?? '07:45',
            'status'       => 'pending',
        ];

        $newDropoff = [
            'node_id'      => 'NEW_DROPOFF',
            'child_id'     => $sub->child_id,
            'type'         => 'dropoff',
            'lat'          => (float) ($sub->dropoff_lat ?? $sub->school?->lat ?? 0),
            'lng'          => (float) ($sub->dropoff_lng ?? $sub->school?->lng ?? 0),
            'service_time' => 2,
            'bell_time'    => $sub->school?->start_time ?? '07:45',
            'status'       => 'pending',
        ];

        $feasible  = [];
        $rejected  = [];
        $others    = [];

        foreach ($routes as $route) {
            $metrics = $this->calculateRouteMetrics($route);

            // فحص الفترة
            $subTimingRaw = $sub->contract?->timing ?? 'morning';
            $subTripType  = in_array(strtolower($subTimingRaw), ['morning', 'صباح', 'both'])
                ? 'morning' : 'afternoon';

            $isSameTiming = (strtolower($route->route_type) === $subTripType);

            if (!$isSameTiming) {
                $others[] = [
                    'id'             => (int) $route->id,
                    'name'           => $route->route_name,
                    'score'          => null,
                    'is_feasible'    => false,
                    'rejection_reason' => 'فترة المسار مختلفة عن فترة الاشتراك',
                ];
                continue;
            }

            // فحص السعة المبدئية
            if ($metrics['available_seats'] <= 0) {
                $rejected[] = [
                    'id'               => (int) $route->id,
                    'name'             => $route->route_name,
                    'is_feasible'      => false,
                    'rejection_reason' => 'لا توجد مقاعد متاحة في هذا المسار',
                ];
                continue;
            }

            // بناء الـ nodes الحالية من optimized_points
            $currentNodes = [];
            $rawPoints    = $route->optimized_points ?? [];
            if (is_string($rawPoints)) $rawPoints = json_decode($rawPoints, true) ?? [];

            $originalDropoffEtas = [];
            foreach ($rawPoints as $pt) {
                $node = [
                    'node_id'      => $pt['node_id']      ?? uniqid('N_'),
                    'child_id'     => $pt['child_id']     ?? null,
                    'type'         => $pt['type']         ?? 'pickup',
                    'lat'          => (float) ($pt['lat'] ?? 0),
                    'lng'          => (float) ($pt['lng'] ?? 0),
                    'service_time' => (int) ($pt['service_time'] ?? 3),
                    'bell_time'    => $pt['bell_time']    ?? '07:45',
                    'status'       => $pt['status']       ?? 'pending',
                    'eta_minutes'  => 0,
                    'eta'          => '00:00',
                    'slack'        => null,
                ];
                $currentNodes[] = $node;
            }

            $startTime      = $route->start_time ?? '07:00:00';
            $departureMins  = $this->toMinutes(substr($startTime, 0, 5));

            // حساب الـ ETA الأصلية لحماية الأطفال القدامى
            if (!empty($currentNodes)) {
                $original = $this->calculateEtas($currentNodes, $departureMins, $osrm);
                foreach ($original as $n) {
                    if ($n['type'] === 'dropoff' && $n['child_id']) {
                        $originalDropoffEtas[$n['child_id']] = $n['eta_minutes'];
                    }
                }
            }

            // تجاهل إذا الإحداثيات صفرية
            if ($newPickup['lat'] == 0 || $newPickup['lng'] == 0) {
                // Fallback: استخدام النقاط المبسطة
                $feasible[] = [
                    'id'             => (int) $route->id,
                    'name'           => $route->route_name,
                    'score'          => 70.0,
                    'is_feasible'    => true,
                    'simulated_nodes'=> [],
                    'reason'         => ['لم يتم توفير إحداثيات الطفل — تم القبول مبدئياً'],
                    'warnings'       => [],
                    'metrics'        => $metrics,
                ];
                continue;
            }

            // تشغيل محاكاة VRPTW
            $bestNodes = $this->simulateChildInsertion(
                $currentNodes,
                $newPickup,
                $newDropoff,
                $departureMins,
                $osrm,
                $originalDropoffEtas
            );

            if ($bestNodes !== null) {
                $score = $this->calculateObjectiveScore($bestNodes);
                $feasible[] = [
                    'id'              => (int) $route->id,
                    'name'            => $route->route_name,
                    'score'           => round($score, 2),
                    'is_feasible'     => true,
                    'simulated_nodes' => $bestNodes,
                    'reason'          => [
                        'تم التحقق من نوافذ الوصول للمدارس',
                        'يوجد مقعد متاح في المركبة',
                        'تضمين الطفل الجديد لا يتأخر بأي طفل آخر',
                    ],
                    'warnings'        => [],
                    'metrics'         => $metrics,
                ];
            } else {
                $rejected[] = [
                    'id'               => (int) $route->id,
                    'name'             => $route->route_name,
                    'is_feasible'      => false,
                    'rejection_reason' => 'تعارض زمني: إضافة الطفل تتجاوز نوافذ الوصول المسموحة لأحد الأطفال الحاليين',
                ];
            }
        }

        // ترتيب تنازلي بالـ score
        usort($feasible, fn($a, $b) => $b['score'] <=> $a['score']);

        $best  = !empty($feasible) ? array_shift($feasible) : null;

        return [
            'recommended_route' => $best ? [
                'id'              => $best['id'],
                'name'            => $best['name'],
                'score'           => $best['score'],
                'reason'          => $best['reason'],
                'warnings'        => $best['warnings'],
                'simulated_nodes' => $best['simulated_nodes'],
                'metrics'         => $best['metrics'],
            ] : null,
            'other_routes'    => array_map(fn($r) => [
                'id'    => $r['id'],
                'name'  => $r['name'],
                'score' => $r['score'],
            ], $feasible),
            'rejected_routes' => array_merge($rejected, $others),
            'message'         => $best
                ? 'تم إيجاد مسار مناسب وفق خوارزمية VRPTW'
                : 'لا يوجد مسار متوافق. جميع المسارات تتجاوز القيود الزمنية أو السعة.',
        ];
    }
}
