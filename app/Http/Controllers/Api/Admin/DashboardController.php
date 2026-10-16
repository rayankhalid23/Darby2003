<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Shared\Trip;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\TripTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    // =========================================================================
    // 📊 إحصائيات لوحة التحكم الرئيسية
    // =========================================================================

    /**
     * GET /api/admin/dashboard/stats
     * يُرجع إحصائيات الداشبورد الرئيسية السبعة:
     * 1. إجمالي المستخدمون
     * 2. إجمالي السائقين المفعلين
     * 3. إجمالي أولياء الأمور
     * 4. إجمالي الأطفال المشتركون في رحلات
     * 5. إجمالي الاشتراكات اليومية
     * 6. إجمالي الاشتراكات الشهرية
     * 7. إجمالي السائقون الذين لديهم رحلات حالياً
     */
    public function stats(): JsonResponse
    {
        try {
            $data = \Illuminate\Support\Facades\Cache::remember('admin_dashboard_stats', 30, function () {
                // 1. إجمالي مستخدمي المنصة (أولياء أمور + سائقين + إداريين)
                $totalUsers = User::count();

                // 2. السائقين المستقلين المفعلين (حساب السائق مقبول ومفعل)
                $activeDrivers = Driver::whereHas('user', fn($q) => $q->where('is_active', true))
                    ->whereIn('status', ['Approved', 'approved', 'Active', 'active'])
                    ->count();

                // 3. إجمالي أولياء الأمور (حساب السجلات المباشرة مع تغطية الاحتياط من حسابات المستخدمين)
                $totalParents = \App\Models\Parent\ParentModel::count();
                if ($totalParents === 0) {
                    $totalParents = User::where('role_id', 3)
                        ->orWhereHas('parent')
                        ->count();
                }

                // 4. إجمالي الأطفال المشتركون في رحلات (الذين لديهم اشتراكات نشطة)
                $subscribedChildren = ActiveSubscription::where('status', 'active')
                    ->whereHas('requestChild')
                    ->with('requestChild')
                    ->get()
                    ->pluck('child_id')
                    ->unique()
                    ->count();

                // 5. إجمالي الاشتراكات اليومية النشطة
                $dailySubscriptions = ActiveSubscription::where('status', 'active')
                    ->whereHas('subscriptionRequest', fn($q) => $q->whereIn('subscription_type', ['single_day', 'daily']))
                    ->count();

                // 6. إجمالي الاشتراكات متعددة الأيام النشطة
                $monthlySubscriptions = ActiveSubscription::where('status', 'active')
                    ->whereHas('subscriptionRequest', fn($q) => $q->whereIn('subscription_type', ['multi_day', 'monthly']))
                    ->count();

                // 7. إجمالي السائقين الذين عندهم رحلات جارية حالياً
                $driversWithActiveTrips = Trip::where('status', 'in_progress')
                    ->whereNotNull('driver_id')
                    ->distinct('driver_id')
                    ->count('driver_id');

                // إحصائيات إضافية للتغيير النسبي
                $lastWeekUsers = User::where('created_at', '>=', now()->subWeek())->count();
                $lastWeekDrivers = Driver::whereHas('user', fn($q) => $q->where('created_at', '>=', now()->subWeek()))->count();

                $userChangePercent = $totalUsers > 0
                    ? round(($lastWeekUsers / max($totalUsers, 1)) * 100, 1)
                    : 0;

                return [
                    'total_users' => [
                        'label'   => 'إجمالي المستخدمين',
                        'value'   => number_format($totalUsers),
                        'raw'     => $totalUsers,
                        'change'  => "+{$userChangePercent}% الأسبوع الماضي",
                        'trend'   => 'up',
                    ],
                    'active_drivers' => [
                        'label'   => 'إجمالي السائقين المفعلين',
                        'value'   => number_format($activeDrivers),
                        'raw'     => $activeDrivers,
                        'change'  => $lastWeekDrivers > 0 ? "+{$lastWeekDrivers} سائقين جدد" : 'لا جديد هذا الأسبوع',
                        'trend'   => $lastWeekDrivers > 0 ? 'up' : 'neutral',
                    ],
                    'total_parents' => [
                        'label'   => 'إجمالي أولياء الأمور',
                        'value'   => number_format($totalParents),
                        'raw'     => $totalParents,
                        'change'  => 'مسجلين في المنصة',
                        'trend'   => 'info',
                    ],
                    'subscribed_children' => [
                        'label'   => 'إجمالي الأطفال المشتركين في الرحلات',
                        'value'   => number_format($subscribedChildren),
                        'raw'     => $subscribedChildren,
                        'change'  => 'اشتراكات نشطة',
                        'trend'   => 'info',
                    ],
                    'daily_subscriptions' => [
                        'label'   => 'إجمالي الاشتراكات اليومية',
                        'value'   => number_format($dailySubscriptions),
                        'raw'     => $dailySubscriptions,
                        'change'  => 'اشتراكات يومية نشطة',
                        'trend'   => 'info',
                    ],
                    'monthly_subscriptions' => [
                        'label'   => 'إجمالي الاشتراكات الشهرية',
                        'value'   => number_format($monthlySubscriptions),
                        'raw'     => $monthlySubscriptions,
                        'change'  => 'اشتراكات شهرية نشطة',
                        'trend'   => 'info',
                    ],
                    'drivers_with_active_trips' => [
                        'label'   => 'إجمالي السائقين الذين لديهم رحلات حالياً',
                        'value'   => number_format($driversWithActiveTrips),
                        'raw'     => $driversWithActiveTrips,
                        'change'  => 'رحلات حية جارية',
                        'trend'   => 'live',
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الإحصائيات الشاملة بنجاح.',
                'data'    => $data,
                'generated_at' => now()->toISOString(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب الإحصائيات: ' . $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // 🚗 الرحلات الحية للرادار
    // =========================================================================

    /**
     * GET /api/admin/dashboard/active-trips
     * يُرجع قائمة الرحلات النشطة الآن مع تفاصيل السائق والأطفال والموقع
     * ويعتمد نظاماً هجيناً (Firestore Realtime + Backend Telemetry Fallback)
     */
    public function activeTrips(): JsonResponse
    {
        $activeTrips = Trip::where('status', 'in_progress')
            ->with([
                'driver.user',
                'driver.vehicles',
                'driver.activeSubscriptions' => function ($q) {
                    $q->where('active_subscriptions.status', 'active')
                      ->with(['child.parent', 'child.school', 'school']);
                },
                'stops' => function ($q) {
                    $q->with(['child.parent', 'child.school', 'school'])->orderBy('sequence_order');
                },
                'events.child.parent',
                'events.child.school',
                // آخر تحديث موقع من جدول trip_tracking
                'tracking' => function ($q) {
                    $q->latest('recorded_at')->limit(1);
                },
                'route',
            ])
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (Trip $trip) {

                // --- بيانات السائق ---
                $driver   = $trip->driver;
                $user     = $driver?->user;

                // --- التيليماتري اللحظية: فحص الكاش الحي أولاً، ثم جدول trip_tracking، ثم جدول drivers ---
                $cacheKey = $driver ? "driver_last_loc_{$driver->id}" : null;
                $cachedLoc = $cacheKey ? Cache::get($cacheKey) : null;
                $lastTrack = $trip->tracking->first();

                $lat = $cachedLoc['lat'] ?? $lastTrack?->latitude ?? $driver?->current_lat ?? (32.9 + (rand(-50, 50) / 1000));
                $lng = $cachedLoc['lng'] ?? $lastTrack?->longitude ?? $driver?->current_lng ?? (13.2 + (rand(-50, 50) / 1000));

                $isOnline = false;
                if (isset($cachedLoc['timestamp']) && (time() - $cachedLoc['timestamp'] <= 300)) {
                    $isOnline = true;
                }

                $heading = isset($cachedLoc['heading']) ? (float) $cachedLoc['heading'] : null;

                $speedDisplay = '0 كم/س';
                if (isset($cachedLoc['speed'])) {
                    $speedDisplay = round((float) $cachedLoc['speed']) . ' كم/س';
                } elseif ($lastTrack?->speed) {
                    $speedDisplay = round((float) $lastTrack->speed) . ' كم/س';
                }

                $lastUpdateDisplay = 'منذ قليل';
                if (!empty($cachedLoc['updated_at'])) {
                    $lastUpdateDisplay = Carbon::parse($cachedLoc['updated_at'])->diffForHumans();
                } elseif ($lastTrack?->recorded_at) {
                    $lastUpdateDisplay = $lastTrack->recorded_at->diffForHumans();
                }

                // --- استخراج وتفصيل بيانات الأطفال وحالاتهم داخل هذه الرحلة ---
                $childrenMap = collect();

                // 1. من خلال محطات الرحلة (TripStops)
                if ($trip->stops && $trip->stops->isNotEmpty()) {
                    foreach ($trip->stops->whereNotNull('child_id')->groupBy('child_id') as $childId => $cStops) {
                        $firstStop = $cStops->first();
                        $child = $firstStop->child;
                        if (!$child) continue;

                        $stopStatuses = $cStops->pluck('status')->all();

                        $isDelivered = in_array(\App\Models\Shared\TripStop::STATUS_DROPPED_OFF_SCHOOL, $stopStatuses, true)
                            || in_array(\App\Models\Shared\TripStop::STATUS_DELIVERED_HOME, $stopStatuses, true);

                        $isOnBoard = !$isDelivered && in_array(\App\Models\Shared\TripStop::STATUS_BOARDED, $stopStatuses, true);

                        $isAbsent = !$isDelivered && !$isOnBoard && (
                            in_array(\App\Models\Shared\TripStop::STATUS_ABSENT_PRE, $stopStatuses, true)
                            || in_array(\App\Models\Shared\TripStop::STATUS_ABSENT_LATE, $stopStatuses, true)
                            || in_array(\App\Models\Shared\TripStop::STATUS_SKIPPED_UNRESPONSIVE, $stopStatuses, true)
                        );

                        $cStatus = 'pending';
                        $cStatusLabel = 'بانتظار الاستلام (لم يُصعد بعد)';

                        if ($isDelivered) {
                            $cStatus = 'delivered';
                            $cStatusLabel = 'تم التوصيل';
                        } elseif ($isOnBoard) {
                            $cStatus = 'on_board';
                            $cStatusLabel = 'على متن الحافلة (مع السائق)';
                        } elseif ($isAbsent) {
                            $cStatus = 'absent';
                            $cStatusLabel = 'غائب';
                        }

                        $parent = $child->parent;
                        $school = $child->school ?? $firstStop->school;

                        $childrenMap->put($childId, [
                            'child_id'     => (int) $child->id,
                            'child_name'   => $child->full_name ?? $child->name ?? 'طفل',
                            'parent_name'  => $parent?->full_name ?? 'غير محدد',
                            'parent_phone' => $parent?->phone_number ?? 'غير متوفر',
                            'school_name'  => $school?->name ?? 'المدرسة',
                            'status'       => $cStatus,
                            'status_label' => $cStatusLabel,
                        ]);
                    }
                }

                // 2. فحص أحداث المسح المباشرة (TripEvents) لضمان الدقة اللحظية
                if ($trip->events && $trip->events->isNotEmpty()) {
                    foreach ($trip->events->whereNotNull('child_id')->groupBy('child_id') as $cId => $cEvents) {
                        $hasDropoff = $cEvents->contains(fn($e) => in_array($e->action_type, ['dropped_off', 'dropoff'], true));
                        $hasPickup  = $cEvents->contains(fn($e) => in_array($e->action_type, ['picked_up', 'pickup'], true));
                        $hasAbsent  = $cEvents->contains(fn($e) => in_array($e->action_type, ['absent', 'skip', 'skipped'], true));

                        if ($childrenMap->has($cId)) {
                            $item = $childrenMap->get($cId);
                            if ($hasDropoff) {
                                $item['status'] = 'delivered';
                                $item['status_label'] = 'تم التوصيل';
                            } elseif ($hasPickup) {
                                $item['status'] = 'on_board';
                                $item['status_label'] = 'على متن الحافلة (مع السائق)';
                            } elseif ($hasAbsent) {
                                $item['status'] = 'absent';
                                $item['status_label'] = 'غائب';
                            }
                            $childrenMap->put($cId, $item);
                        } else {
                            $firstEvent = $cEvents->first();
                            $child = $firstEvent->child;
                            if ($child) {
                                $parent = $child->parent;
                                $school = $child->school;

                                $cStatus = 'pending';
                                $cStatusLabel = 'بانتظار الاستلام (لم يُصعد بعد)';
                                if ($hasDropoff) {
                                    $cStatus = 'delivered';
                                    $cStatusLabel = 'تم التوصيل';
                                } elseif ($hasPickup) {
                                    $cStatus = 'on_board';
                                    $cStatusLabel = 'على متن الحافلة (مع السائق)';
                                } elseif ($hasAbsent) {
                                    $cStatus = 'absent';
                                    $cStatusLabel = 'غائب';
                                }

                                $childrenMap->put($cId, [
                                    'child_id'     => (int) $child->id,
                                    'child_name'   => $child->full_name ?? $child->name ?? 'طفل',
                                    'parent_name'  => $parent?->full_name ?? 'غير محدد',
                                    'parent_phone' => $parent?->phone_number ?? 'غير متوفر',
                                    'school_name'  => $school?->name ?? 'المدرسة',
                                    'status'       => $cStatus,
                                    'status_label' => $cStatusLabel,
                                ]);
                            }
                        }
                    }
                }

                // 3. احتياط: الاشتراكات النشطة للسائق المرتبطة بمسار الرحلة
                if ($childrenMap->isEmpty() && $driver?->activeSubscriptions) {
                    $subs = $driver->activeSubscriptions->filter(function ($sub) use ($trip) {
                        return empty($trip->route_id) || $sub->route_id == $trip->route_id;
                    });
                    if ($subs->isEmpty()) {
                        $subs = $driver->activeSubscriptions;
                    }

                    foreach ($subs as $sub) {
                        $child = $sub->child;
                        if (!$child || $childrenMap->has($child->id)) continue;

                        $parent = $child->parent;
                        $school = $sub->school ?? $child->school;

                        $childrenMap->put($child->id, [
                            'child_id'     => (int) $child->id,
                            'child_name'   => $child->full_name ?? $child->name ?? 'طفل',
                            'parent_name'  => $parent?->full_name ?? 'غير محدد',
                            'parent_phone' => $parent?->phone_number ?? 'غير متوفر',
                            'school_name'  => $school?->name ?? 'المدرسة',
                            'status'       => 'pending',
                            'status_label' => 'بانتظار الاستلام (لم يُصعد بعد)',
                        ]);
                    }
                }

                $childrenList = $childrenMap->values();

                // العدادات المطلوبة بدقة دون تكرار:
                $totalChildrenCount   = $childrenList->count();
                $onBoardCount         = $childrenList->where('status', 'on_board')->count();
                $pendingPickupCount   = $childrenList->where('status', 'pending')->count();
                $deliveredCount       = $childrenList->where('status', 'delivered')->count();
                $absentCount          = $childrenList->where('status', 'absent')->count();

                // اسم نصي ملخص للتوافقية مع الواجهات القديمة
                $childrenSummaryNames = $childrenList->pluck('child_name')->take(2)->implode(' و ');
                if ($totalChildrenCount > 2) {
                    $childrenSummaryNames .= ' وآخرين (' . $totalChildrenCount . ')';
                }
                if (empty($childrenSummaryNames)) {
                    $childrenSummaryNames = 'غير محدد';
                }

                // --- حساب مدة الرحلة ---
                $duration = 'جارٍ التحميل';
                if ($trip->actual_start_time) {
                    $diffMins = (int) Carbon::parse($trip->actual_start_time)->diffInMinutes(now());
                    $duration = $diffMins > 0 ? "{$diffMins} دقيقة" : 'بدأت للتو';
                } elseif ($trip->started_at) {
                    $diffMins = (int) Carbon::parse($trip->started_at)->diffInMinutes(now());
                    $duration = $diffMins > 0 ? "{$diffMins} دقيقة" : 'بدأت للتو';
                }

                // --- الوجهة من المسار ---
                $destination = $trip->route?->name ?? 'مدرسة طرابلس المركزية...';

                // --- تحويل الإحداثيات إلى نسبة مئوية للعرض على الخريطة SVG ---
                // طرابلس: lat ≈ 32.5 → 33.2, lng ≈ 13.0 → 13.5
                $mapX = round(max(5, min(95, (($lng - 13.0) / 0.5) * 90 + 5)), 1);
                $mapY = round(max(10, min(85, ((33.2 - $lat) / 0.7) * 75 + 10)), 1);

                return [
                    'id'                      => $trip->id,
                    'trip_id'                 => $trip->id,
                    'driver_id'               => $driver?->id,
                    'name'                    => $user?->full_name ?? 'سائق غير معروف',
                    'phone'                   => $user?->phone_number ?? '---',
                    'avatar'                  => $user?->avatar_url ?? "https://ui-avatars.com/api/?name=" . urlencode($user?->full_name ?? 'D') . "&background=3b82f6&color=fff&size=100",
                    'children'                => $childrenSummaryNames,
                    'children_details'        => $childrenList->all(),
                    'total_children_count'    => $totalChildrenCount,
                    'children_on_board_count' => $onBoardCount,       // عدد الأطفال اللي معاه
                    'pending_pickup_count'    => $pendingPickupCount, // عدد الأطفال اللي مزال ما خذاهمش
                    'delivered_count'         => $deliveredCount,     // عدد الأطفال اللي وصلهم
                    'absent_count'            => $absentCount,        // عدد الأطفال الغائبين
                    'destination'             => $destination,
                    'duration'                => $duration,
                    'status'                  => 'في الطريق للاستلام',
                    'lat'                     => (float) $lat,
                    'lng'                     => (float) $lng,
                    'heading'                 => $heading,
                    'speed'                   => $speedDisplay,
                    'is_online'               => $isOnline,
                    'firestore_doc'           => "trips_tracking/{$trip->id}",
                    'map_x'                   => $mapX,   // نسبة X% على خريطة SVG
                    'map_y'                   => $mapY,   // نسبة Y% على خريطة SVG
                    'region'                  => $this->getRegionName((float) $lat, (float) $lng),
                    'last_update'             => $lastUpdateDisplay,
                ];
            });

        // --- إذا لم تكن هناك رحلات حية، نُرجع بيانات تجريبية ---
        if ($activeTrips->isEmpty()) {
            $activeTrips = $this->getDemoTrips();
        }

        return response()->json([
            'success'  => true,
            'count'    => $activeTrips->count(),
            'firebase' => [
                'collection' => 'trips_tracking',
                'strategy'   => 'realtime_with_backend_fallback',
            ],
            'data'     => $activeTrips,
            'is_demo'  => Trip::where('status', 'in_progress')->doesntExist(),
        ]);
    }

    // =========================================================================
    // 🗺️ دوال مساعدة
    // =========================================================================

    /**
     * تحديد اسم المنطقة بناءً على الإحداثيات (طرابلس)
     */
    private function getRegionName(float $lat, float $lng): string
    {
        if ($lat > 32.95 && $lng < 13.15) return 'السياحية';
        if ($lat > 32.88 && $lng < 13.20) return 'حي الأندلس';
        if ($lat > 32.90 && $lng > 13.17) return 'بن عاشور';
        if ($lat < 32.88 && $lng < 13.15) return 'قرقارش';
        if ($lat < 32.88 && $lng > 13.20) return 'سوق الجمعة';
        if ($lng > 13.35) return 'تاجوراء';
        if ($lng < 13.05) return 'جنزور';
        return 'طرابلس المركز';
    }

    /**
     * بيانات تجريبية تُعرض عند عدم وجود رحلات حية في DB
     */
    private function getDemoTrips(): \Illuminate\Support\Collection
    {
        return collect([
            [
                'id'                      => 1,
                'trip_id'                 => 1,
                'driver_id'               => 1,
                'name'                    => 'عبد السلام المصراتي',
                'phone'                   => '091-3456789',
                'avatar'                  => 'https://ui-avatars.com/api/?name=%D8%B9%D8%A8%D8%AF%D8%A7%D9%84%D8%B3%D9%84%D8%A7%D9%85&background=3b82f6&color=fff&size=100',
                'children'                => 'علي ومروة وأحمد (3)',
                'children_details'        => [
                    [
                        'child_id'     => 101,
                        'child_name'   => 'علي المصراتي',
                        'parent_name'  => 'محمد المصراتي',
                        'parent_phone' => '091-1112233',
                        'school_name'  => 'مدرسة الجيل الجديد الدولية',
                        'status'       => 'on_board',
                        'status_label' => 'على متن الحافلة (مع السائق)',
                    ],
                    [
                        'child_id'     => 102,
                        'child_name'   => 'مروة المصراتي',
                        'parent_name'  => 'محمد المصراتي',
                        'parent_phone' => '091-1112233',
                        'school_name'  => 'مدرسة الجيل الجديد الدولية',
                        'status'       => 'pending',
                        'status_label' => 'بانتظار الاستلام (لم يُصعد بعد)',
                    ],
                    [
                        'child_id'     => 103,
                        'child_name'   => 'أحمد الترهوني',
                        'parent_name'  => 'خالد الترهوني',
                        'parent_phone' => '092-4445566',
                        'school_name'  => 'مدرسة الجيل الجديد الدولية',
                        'status'       => 'delivered',
                        'status_label' => 'تم التوصيل',
                    ],
                ],
                'total_children_count'    => 3,
                'children_on_board_count' => 1,
                'pending_pickup_count'    => 1,
                'delivered_count'         => 1,
                'absent_count'            => 0,
                'destination'             => 'مدرسة الجيل الجديد الدولي...',
                'duration'                => '12 دقيقة',
                'status'                  => 'في الطريق للاستلام',
                'lat'                     => 32.91,
                'lng'                     => 13.17,
                'heading'                 => 45.0,
                'speed'                   => '55 كم/س',
                'is_online'               => true,
                'firestore_doc'           => 'trips_tracking/1',
                'map_x'                   => 28.0,
                'map_y'                   => 45.0,
                'region'                  => 'حي الأندلس',
                'last_update'             => 'منذ دقيقتين',
            ],
            [
                'id'                      => 2,
                'trip_id'                 => 2,
                'driver_id'               => 2,
                'name'                    => 'مفتاح الزنتاني',
                'phone'                   => '092-6549873',
                'avatar'                  => 'https://ui-avatars.com/api/?name=%D9%85%D9%81%D8%AA%D8%A7%D8%AD&background=10b981&color=fff&size=100',
                'children'                => 'أحمد وسند',
                'children_details'        => [
                    [
                        'child_id'     => 104,
                        'child_name'   => 'أحمد الزنتاني',
                        'parent_name'  => 'طارق الزنتاني',
                        'parent_phone' => '091-7778899',
                        'school_name'  => 'مدرسة الشروق الأهلية',
                        'status'       => 'on_board',
                        'status_label' => 'على متن الحافلة (مع السائق)',
                    ],
                    [
                        'child_id'     => 105,
                        'child_name'   => 'سند الورفلي',
                        'parent_name'  => 'جمال الورفلي',
                        'parent_phone' => '092-3332211',
                        'school_name'  => 'مدرسة الشروق الأهلية',
                        'status'       => 'on_board',
                        'status_label' => 'على متن الحافلة (مع السائق)',
                    ],
                ],
                'total_children_count'    => 2,
                'children_on_board_count' => 2,
                'pending_pickup_count'    => 0,
                'delivered_count'         => 0,
                'absent_count'            => 0,
                'destination'             => 'مدرسة الشروق الأهلية، الس...',
                'duration'                => '4 دقائق',
                'status'                  => 'في الطريق للاستلام',
                'lat'                     => 32.90,
                'lng'                     => 13.22,
                'heading'                 => 90.0,
                'speed'                   => '40 كم/س',
                'is_online'               => true,
                'firestore_doc'           => 'trips_tracking/2',
                'map_x'                   => 52.0,
                'map_y'                   => 38.0,
                'region'                  => 'بن عاشور',
                'last_update'             => 'منذ دقيقة',
            ],
            [
                'id'                      => 3,
                'trip_id'                 => 3,
                'driver_id'               => 3,
                'name'                    => 'علي غومة',
                'phone'                   => '092-2223344',
                'avatar'                  => 'https://ui-avatars.com/api/?name=%D8%B9%D9%84%D9%8A&background=f59e0b&color=fff&size=100',
                'children'                => 'سارة وخالد وفاطمة (3)',
                'children_details'        => [
                    [
                        'child_id'     => 106,
                        'child_name'   => 'سارة غومة',
                        'parent_name'  => 'نادر غومة',
                        'parent_phone' => '091-8889900',
                        'school_name'  => 'مدرسة طرابلس المركزية',
                        'status'       => 'on_board',
                        'status_label' => 'على متن الحافلة (مع السائق)',
                    ],
                    [
                        'child_id'     => 107,
                        'child_name'   => 'خالد بن علي',
                        'parent_name'  => 'عبد الرؤوف بن علي',
                        'parent_phone' => '092-1234567',
                        'school_name'  => 'مدرسة طرابلس المركزية',
                        'status'       => 'pending',
                        'status_label' => 'بانتظار الاستلام (لم يُصعد بعد)',
                    ],
                    [
                        'child_id'     => 108,
                        'child_name'   => 'فاطمة بن علي',
                        'parent_name'  => 'عبد الرؤوف بن علي',
                        'parent_phone' => '092-1234567',
                        'school_name'  => 'مدرسة طرابلس المركزية',
                        'status'       => 'pending',
                        'status_label' => 'بانتظار الاستلام (لم يُصعد بعد)',
                    ],
                ],
                'total_children_count'    => 3,
                'children_on_board_count' => 1,
                'pending_pickup_count'    => 2,
                'delivered_count'         => 0,
                'absent_count'            => 0,
                'destination'             => 'مدرسة طرابلس المركزية...',
                'duration'                => '8 دقائق',
                'status'                  => 'في الطريق للاستلام',
                'lat'                     => 32.89,
                'lng'                     => 13.38,
                'heading'                 => 180.0,
                'speed'                   => '65 كم/س',
                'is_online'               => true,
                'firestore_doc'           => 'trips_tracking/3',
                'map_x'                   => 78.0,
                'map_y'                   => 48.0,
                'region'                  => 'تاجوراء',
                'last_update'             => 'الآن',
            ],
        ]);
    }
}
