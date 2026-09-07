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
     */
    public function activeTrips(): JsonResponse
    {
        $activeTrips = Trip::where('status', 'in_progress')
            ->with([
                'driver.user',
                'driver.vehicles',
                'driver.activeSubscriptions' => function ($q) {
                    $q->where('status', 'active')->with('child');
                },
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

                // --- الموقع الحالي: من آخر تتبع أو من current_lat/lng للسائق ---
                $lastTrack = $trip->tracking->first();
                $lat = $lastTrack?->latitude  ?? $driver?->current_lat ?? 32.9 + (rand(-50, 50) / 1000);
                $lng = $lastTrack?->longitude ?? $driver?->current_lng ?? 13.2 + (rand(-50, 50) / 1000);

                // --- الأطفال المرتبطين بهذه الرحلة عبر اشتراكات السائق ---
                $childrenNames = $driver?->activeSubscriptions
                    ?->pluck('child.name')
                    ->filter()
                    ->take(2)
                    ->implode(' و ') ?? 'غير محدد';

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
                    'id'          => $trip->id,
                    'driver_id'   => $driver?->id,
                    'name'        => $user?->full_name ?? 'سائق غير معروف',
                    'phone'       => $user?->phone_number ?? '---',
                    'avatar'      => $user?->avatar_url ?? "https://ui-avatars.com/api/?name=" . urlencode($user?->full_name ?? 'D') . "&background=3b82f6&color=fff&size=100",
                    'children'    => $childrenNames,
                    'destination' => $destination,
                    'duration'    => $duration,
                    'status'      => 'في الطريق للاستلام',
                    'lat'         => $lat,
                    'lng'         => $lng,
                    'map_x'       => $mapX,   // نسبة X% على خريطة SVG
                    'map_y'       => $mapY,   // نسبة Y% على خريطة SVG
                    'region'      => $this->getRegionName($lat, $lng),
                    'speed'       => $lastTrack?->speed ? round($lastTrack->speed) . ' كم/س' : '45 كم/س',
                    'last_update' => $lastTrack?->recorded_at?->diffForHumans() ?? 'منذ قليل',
                ];
            });

        // --- إذا لم تكن هناك رحلات حية، نُرجع بيانات تجريبية ---
        if ($activeTrips->isEmpty()) {
            $activeTrips = $this->getDemoTrips();
        }

        return response()->json([
            'success' => true,
            'count'   => $activeTrips->count(),
            'data'    => $activeTrips,
            'is_demo' => Trip::where('status', 'in_progress')->doesntExist(),
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
                'id'          => 1,
                'driver_id'   => 1,
                'name'        => 'عبد السلام المصراتي',
                'phone'       => '091-3456789',
                'avatar'      => 'https://ui-avatars.com/api/?name=%D8%B9%D8%A8%D8%AF%D8%A7%D9%84%D8%B3%D9%84%D8%A7%D9%85&background=3b82f6&color=fff&size=100',
                'children'    => 'علي ومروة',
                'destination' => 'مدرسة الجيل الجديد الدولي...',
                'duration'    => '12 دقيقة',
                'status'      => 'في الطريق للاستلام',
                'lat'         => 32.91,
                'lng'         => 13.17,
                'map_x'       => 28.0,
                'map_y'       => 45.0,
                'region'      => 'حي الأندلس',
                'speed'       => '55 كم/س',
                'last_update' => 'منذ دقيقتين',
            ],
            [
                'id'          => 2,
                'driver_id'   => 2,
                'name'        => 'مفتاح الزنتاني',
                'phone'       => '092-6549873',
                'avatar'      => 'https://ui-avatars.com/api/?name=%D9%85%D9%81%D8%AA%D8%A7%D8%AD&background=10b981&color=fff&size=100',
                'children'    => 'أحمد وسند',
                'destination' => 'مدرسة الشروق الأهلية، الس...',
                'duration'    => '4 دقائق',
                'status'      => 'في الطريق للاستلام',
                'lat'         => 32.90,
                'lng'         => 13.22,
                'map_x'       => 52.0,
                'map_y'       => 38.0,
                'region'      => 'بن عاشور',
                'speed'       => '40 كم/س',
                'last_update' => 'منذ دقيقة',
            ],
            [
                'id'          => 3,
                'driver_id'   => 3,
                'name'        => 'علي غومة',
                'phone'       => '092-2223344',
                'avatar'      => 'https://ui-avatars.com/api/?name=%D8%B9%D9%84%D9%8A&background=f59e0b&color=fff&size=100',
                'children'    => 'سارة وخالد',
                'destination' => 'مدرسة طرابلس المركزية...',
                'duration'    => '8 دقائق',
                'status'      => 'في الطريق للاستلام',
                'lat'         => 32.89,
                'lng'         => 13.38,
                'map_x'       => 78.0,
                'map_y'       => 48.0,
                'region'      => 'تاجوراء',
                'speed'       => '65 كم/س',
                'last_update' => 'الآن',
            ],
        ]);
    }
}
