<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\DriverDocument;
use App\Models\Shared\Trip;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Invoice;
use App\Models\Shared\TripStudentAttendance;
use App\Models\Shared\AbsenceLog;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\RechargeRequest;
use App\Models\Shared\WithdrawalRequest;
use App\Models\Shared\TripDispute;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\Zone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class ReportService
{
    /**
     * 1️⃣ الإحصائيات السريعة (KPI Cards)
     */
    public function getKpiSummary(): array
    {
        $today = Carbon::today();

        // 1. إجمالي المستخدمين النشطين
        $parentsCount = ParentModel::count();
        if ($parentsCount === 0) {
            $parentsCount = User::where('role_id', 3)->orWhereHas('parent')->count();
        }

        $driversCount = Driver::whereHas('user', fn($q) => $q->where('is_active', true))
            ->whereIn('status', ['Approved', 'approved', 'Active', 'active'])
            ->count();

        $childrenCount = Child::count();
        $totalUsers = User::count();

        // 2. إجمالي رحلات اليوم
        $todayTripsQuery = Trip::whereDate('created_at', $today)
            ->orWhereDate('started_at', $today);

        $tripsTodayCompleted = (clone $todayTripsQuery)->where('status', 'completed')->count();
        $tripsTodayInProgress = (clone $todayTripsQuery)->where('status', 'in_progress')->count();
        $tripsTodayCancelled = (clone $todayTripsQuery)->where('status', 'cancelled')->count();
        $tripsTodayTotal = $tripsTodayCompleted + $tripsTodayInProgress + $tripsTodayCancelled;

        // 3. إجمالي الإيرادات (أرباح المنصة لهذا الشهر)
        $startOfMonth = Carbon::now()->startOfMonth();
        $monthlyCommissionCents = FinancialLedger::where('created_at', '>=', $startOfMonth)
            ->where(function ($q) {
                $q->where('destination_account', 'platform_revenue_pool')
                  ->orWhere('type', 'like', '%commission%')
                  ->orWhere('type', 'like', '%platform_fee%');
            })
            ->sum('amount');

        $monthlyTotalVolumeCents = FinancialLedger::where('created_at', '>=', $startOfMonth)
            ->where('type', 'trip_escrow_hold')
            ->sum('amount');

        $vault = MasterEscrowVault::getVault();
        $platformRevenuePool = round(($vault->platform_revenue_pool ?? 0) / 100, 2);

        // 4. التنبيهات العاجلة
        $pendingDrivers = Driver::whereIn('status', ['Pending', 'pending'])->count();
        $pendingWithdrawals = WithdrawalRequest::where('status', 'pending')->count();
        $openDisputes = TripDispute::where('status', 'open')->count();
        $pendingRecharges = RechargeRequest::where('status', 'pending')->count();

        return [
            'active_users' => [
                'parents'  => $parentsCount,
                'drivers'  => $driversCount,
                'children' => $childrenCount,
                'total'    => $totalUsers,
            ],
            'today_trips' => [
                'completed'   => $tripsTodayCompleted,
                'in_progress' => $tripsTodayInProgress,
                'cancelled'   => $tripsTodayCancelled,
                'total'       => $tripsTodayTotal,
            ],
            'monthly_revenue' => [
                'platform_earnings'     => round($monthlyCommissionCents / 100, 2),
                'platform_revenue_pool' => $platformRevenuePool,
                'total_volume'          => round($monthlyTotalVolumeCents / 100, 2),
                'month'                 => Carbon::now()->format('Y-m'),
            ],
            'urgent_alerts' => [
                'pending_drivers'     => $pendingDrivers,
                'pending_withdrawals' => $pendingWithdrawals,
                'open_disputes'       => $openDisputes,
                'pending_recharges'   => $pendingRecharges,
                'total_urgent'        => $pendingDrivers + $pendingWithdrawals + $openDisputes + $pendingRecharges,
            ],
        ];
    }

    /**
     * 2️⃣ التقارير المالية (Financial Reports)
     */
    public function getFinancialReport(array $filters): array
    {
        [$dateFrom, $dateTo, $groupBy] = $this->parseDateFilters($filters);

        // أ) رسم بياني للإيرادات والأرباح
        $ledgerEntries = FinancialLedger::whereBetween('created_at', [$dateFrom, $dateTo])->get();

        $chartData = [];
        $driverEarningsTotalCents = 0;
        $platformCommissionTotalCents = 0;
        $totalVolumeCents = 0;

        foreach ($ledgerEntries as $entry) {
            $amt = $entry->amount;
            $totalVolumeCents += $amt;

            if ($entry->destination_account === 'driver_available_pool' || str_contains($entry->type, 'driver_payout')) {
                $driverEarningsTotalCents += $amt;
            } elseif ($entry->destination_account === 'platform_revenue_pool' || str_contains($entry->type, 'commission')) {
                $platformCommissionTotalCents += $amt;
            }
        }

        // بناء البيانات المقسمة حسب الفترة الزمنية (يومي / أسبوعي / شهري)
        $grouped = $ledgerEntries->groupBy(function ($entry) use ($groupBy) {
            $dt = Carbon::parse($entry->created_at);
            if ($groupBy === 'year') {
                return $dt->format('Y');
            } elseif ($groupBy === 'month') {
                return $dt->format('Y-m');
            } else {
                return $dt->format('Y-m-d');
            }
        });

        foreach ($grouped as $periodLabel => $items) {
            $comm = 0;
            $driverAmt = 0;
            $vol = 0;

            foreach ($items as $it) {
                $vol += $it->amount;
                if ($it->destination_account === 'platform_revenue_pool' || str_contains($it->type, 'commission')) {
                    $comm += $it->amount;
                }
                if ($it->destination_account === 'driver_available_pool' || str_contains($it->type, 'driver_payout')) {
                    $driverAmt += $it->amount;
                }
            }

            $chartData[] = [
                'period'              => $periodLabel,
                'platform_commission' => round($comm / 100, 2),
                'driver_earnings'     => round($driverAmt / 100, 2),
                'total_volume'        => round($vol / 100, 2),
            ];
        }

        // ب) تقرير المحافظ والشحن
        $rechargesQuery = RechargeRequest::whereBetween('created_at', [$dateFrom, $dateTo]);
        $totalRechargedAmount = (clone $rechargesQuery)->where('status', 'completed')->sum('amount');

        $paymentMethodsBreakdown = RechargeRequest::select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(amount) as total_amount'))
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'completed')
            ->groupBy('payment_method')
            ->get()
            ->map(function ($item) use ($totalRechargedAmount) {
                $itemTotal = (float) $item->total_amount;
                $pct = $totalRechargedAmount > 0 ? round(($itemTotal / $totalRechargedAmount) * 100, 1) : 0;
                return [
                    'payment_method' => $item->payment_method ?? 'أخرى',
                    'count'          => (int) $item->count,
                    'total_amount'   => round($itemTotal, 2),
                    'percentage'     => $pct,
                ];
            });

        // ج) تقرير السحوبات المالية
        $withdrawalsQuery = WithdrawalRequest::whereBetween('created_at', [$dateFrom, $dateTo]);
        $processedWithdrawalsAmt = (clone $withdrawalsQuery)->where('status', 'approved')->sum('amount');
        $pendingWithdrawalsAmt   = (clone $withdrawalsQuery)->where('status', 'pending')->sum('amount');
        $processedWithdrawalsCnt = (clone $withdrawalsQuery)->where('status', 'approved')->count();
        $pendingWithdrawalsCnt   = (clone $withdrawalsQuery)->where('status', 'pending')->count();

        // د) تقرير الاعتراضات والتسويات
        $disputesQuery = TripDispute::whereBetween('created_at', [$dateFrom, $dateTo]);
        $totalDisputesCount = (clone $disputesQuery)->count();
        $openDisputesCount  = (clone $disputesQuery)->where('status', 'open')->count();
        $resolvedRefunded   = (clone $disputesQuery)->where('status', 'resolve_parent_refunded')->count();
        $resolvedPaidDriver = (clone $disputesQuery)->where('status', 'resolve_driver_paid')->count();

        return [
            'date_range' => [
                'date_from' => $dateFrom->toDateTimeString(),
                'date_to'   => $dateTo->toDateTimeString(),
                'period'    => $filters['period'] ?? 'month',
            ],
            'revenue_summary' => [
                'platform_commission' => round($platformCommissionTotalCents / 100, 2),
                'driver_earnings'     => round($driverEarningsTotalCents / 100, 2),
                'total_volume'        => round($totalVolumeCents / 100, 2),
                'chart_data'          => $chartData,
            ],
            'recharge_report' => [
                'total_recharged'          => round((float) $totalRechargedAmount, 2),
                'payment_methods_breakdown' => $paymentMethodsBreakdown,
            ],
            'withdrawal_report' => [
                'processed_amount' => round((float) $processedWithdrawalsAmt, 2),
                'pending_amount'   => round((float) $pendingWithdrawalsAmt, 2),
                'processed_count'  => $processedWithdrawalsCnt,
                'pending_count'    => $pendingWithdrawalsCnt,
            ],
            'disputes_report' => [
                'total_disputes'         => $totalDisputesCount,
                'open_disputes'          => $openDisputesCount,
                'resolved_refunded_count'=> $resolvedRefunded,
                'resolved_driver_count'  => $resolvedPaidDriver,
            ],
        ];
    }

    /**
     * 3️⃣ تقارير التشغيل والرحلات (Operational & Trips Reports)
     */
    public function getTripsReport(array $filters): array
    {
        [$dateFrom, $dateTo] = $this->parseDateFilters($filters);

        $tripsQuery = Trip::whereBetween('created_at', [$dateFrom, $dateTo]);

        $totalTrips = (clone $tripsQuery)->count();
        $completedTrips = (clone $tripsQuery)->where('status', 'completed')->count();
        $cancelledTrips = (clone $tripsQuery)->where('status', 'cancelled')->count();
        $inProgressTrips = (clone $tripsQuery)->where('status', 'in_progress')->count();
        $scheduledTrips = (clone $tripsQuery)->where('status', 'scheduled')->count();

        $completionRate = $totalTrips > 0 ? round(($completedTrips / $totalTrips) * 100, 1) : 0;
        $cancellationRate = $totalTrips > 0 ? round(($cancelledTrips / $totalTrips) * 100, 1) : 0;

        // إحصائيات غياب الطلاب
        $attendanceQuery = TripStudentAttendance::whereBetween('created_at', [$dateFrom, $dateTo]);
        $totalAttendanceRecords = (clone $attendanceQuery)->count();
        $presentCount  = (clone $attendanceQuery)->where('attendance_status', 'present')->count();
        $absentCount   = (clone $attendanceQuery)->whereIn('attendance_status', ['absent', 'excused', 'unexcused'])->count();
        $excusedCount  = AbsenceLog::whereBetween('absence_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->count();
        $unexcusedCount = max(0, $absentCount - $excusedCount);

        // غياب/إلغاء السائقين
        $driverCancellationsCount = Trip::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'cancelled')
            ->count();

        $driverAbsencesCount = DB::table('driver_absences')
            ->whereBetween('absence_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->count();

        // خريطة التغطية والطلب الأكثر انتشاراً (Heatmap / Top Demand)
        $topSchools = School::withCount('children')
            ->orderBy('children_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($school) {
                return [
                    'school_id'      => $school->id,
                    'school_name'    => $school->name,
                    'students_count' => $school->children_count,
                    'lat'            => (float) ($school->lat ?? 0),
                    'lng'            => (float) ($school->lng ?? 0),
                ];
            });

        $topZones = Zone::withCount('drivers')
            ->orderBy('drivers_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($zone) {
                return [
                    'zone_id'       => $zone->id,
                    'zone_name'     => $zone->name,
                    'drivers_count' => $zone->drivers_count,
                ];
            });

        return [
            'date_range' => [
                'date_from' => $dateFrom->toDateTimeString(),
                'date_to'   => $dateTo->toDateTimeString(),
            ],
            'completion_summary' => [
                'total_trips'                => $totalTrips,
                'completed_trips'            => $completedTrips,
                'cancelled_trips'            => $cancelledTrips,
                'in_progress_trips'          => $inProgressTrips,
                'scheduled_trips'            => $scheduledTrips,
                'completion_rate_percentage' => $completionRate,
                'cancellation_rate_percentage' => $cancellationRate,
            ],
            'absence_stats' => [
                'student_absence' => [
                    'total_records'   => $totalAttendanceRecords,
                    'present_count'   => $presentCount,
                    'absent_count'    => $absentCount,
                    'excused_count'   => $excusedCount,
                    'unexcused_count' => $unexcusedCount,
                ],
                'driver_absence' => [
                    'driver_cancellations_count' => $driverCancellationsCount,
                    'driver_absences_count'      => $driverAbsencesCount,
                ],
            ],
            'demand_heatmap' => [
                'top_schools' => $topSchools,
                'top_zones'   => $topZones,
            ],
        ];
    }

    /**
     * 4️⃣ تقارير الاشتراكات (Subscriptions Reports)
     */
    public function getSubscriptionsReport(array $filters): array
    {
        [$dateFrom, $dateTo] = $this->parseDateFilters($filters);

        $invoicesQuery = Invoice::whereBetween('created_at', [$dateFrom, $dateTo]);
        $totalInvoices = (clone $invoicesQuery)->count();

        // أ) توزيع أنواع الاشتراكات
        $monthlyCount = (clone $invoicesQuery)->whereIn('subscription_type', ['multi_day', 'monthly'])->count();
        $dailyCount   = (clone $invoicesQuery)->whereIn('subscription_type', ['single_day', 'daily'])->count();
        $bothCount    = (clone $invoicesQuery)->where('subscription_type', 'both')->count();

        $monthlyPct = $totalInvoices > 0 ? round(($monthlyCount / $totalInvoices) * 100, 1) : 0;
        $dailyPct   = $totalInvoices > 0 ? round(($dailyCount / $totalInvoices) * 100, 1) : 0;
        $bothPct    = $totalInvoices > 0 ? round(($bothCount / $totalInvoices) * 100, 1) : 0;

        // ب) حالة الاشتراكات (نشطة / متوقفة / ملغاة)
        $activeSubsCount    = ActiveSubscription::where('status', 'active')->count();
        $pausedSubsCount    = ActiveSubscription::where('status', 'paused')->count();
        $cancelledSubsCount = ActiveSubscription::where('status', 'cancelled')->count();
        $totalSubsCount     = $activeSubsCount + $pausedSubsCount + $cancelledSubsCount;

        // ج) الاشتراكات التي ستنتهي قريباً (خلال الأيام الـ 7 القادمة)
        $nextWeek = Carbon::today()->addDays(7);
        $expiringSoonSubscriptions = Invoice::with(['subscriptionRequest.parent.user', 'subscriptionRequest.driver.user'])
            ->where('type', 'proforma')
            ->where('status', 'pending')
            ->whereBetween('due_date', [Carbon::today()->toDateString(), $nextWeek->toDateString()])
            ->orderBy('due_date', 'asc')
            ->get()
            ->map(function ($inv) {
                $daysLeft = (int) Carbon::today()->diffInDays(Carbon::parse($inv->due_date), false);
                $req = $inv->subscriptionRequest;
                return [
                    'contract_id'       => $inv->id,
                    'contract_number'   => $inv->invoice_number,
                    'parent_name'       => $inv->parent?->full_name ?? 'غير معروف',
                    'parent_phone'      => $inv->parent?->phone_number ?? '---',
                    'driver_name'       => $inv->driver?->user?->full_name ?? 'غير محدد',
                    'subscription_type' => $inv->subscription_type,
                    'start_date'        => $inv->created_at?->toDateString(),
                    'end_date'          => $inv->due_date?->toDateString(),
                    'days_left'         => max(0, $daysLeft),
                ];
            });

        return [
            'date_range' => [
                'date_from' => $dateFrom->toDateTimeString(),
                'date_to'   => $dateTo->toDateTimeString(),
            ],
            'subscription_types' => [
                'total_contracts'       => $totalInvoices,
                'multi_day_count'       => $monthlyCount,
                'single_day_count'      => $dailyCount,
                'both_count'            => $bothCount,
                'multi_day_percentage'  => $monthlyPct,
                'single_day_percentage' => $dailyPct,
                'both_percentage'       => $bothPct,
            ],
            'status_breakdown' => [
                'active_count'    => $activeSubsCount,
                'paused_count'    => $pausedSubsCount,
                'cancelled_count' => $cancelledSubsCount,
                'total_subs'      => $totalSubsCount,
            ],
            'expiring_soon' => [
                'count' => $expiringSoonSubscriptions->count(),
                'list'  => $expiringSoonSubscriptions,
            ],
        ];
    }

    /**
     * 5️⃣ تقارير أداء السائقين (Driver Performance Reports)
     */
    public function getDriversPerformanceReport(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $sortBy = $filters['sort_by'] ?? 'trips'; // trips, rating, retention
        $search = $filters['search'] ?? null;

        $query = Driver::with(['user', 'vehicles'])
            ->withCount([
                'trips as completed_trips_count' => function ($q) {
                    $q->where('status', 'completed');
                },
                'activeSubscriptions as active_subs_count' => function ($q) {
                    $q->where('active_subscriptions.status', 'active');
                },
            ]);

        if (!empty($search)) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        if ($sortBy === 'rating') {
            $query->orderBy('rating_avg', 'desc');
        } elseif ($sortBy === 'retention') {
            $query->orderBy('retention_rate', 'desc');
        } else {
            $query->orderBy('completed_trips_count', 'desc');
        }

        $drivers = $query->paginate($perPage);

        $leaderboard = collect($drivers->items())->map(function ($driver, $idx) use ($drivers) {
            $rank = ($drivers->currentPage() - 1) * $drivers->perPage() + ($idx + 1);
            return [
                'rank'                  => $rank,
                'driver_id'             => $driver->id,
                'name'                  => $driver->user?->full_name ?? 'سائق',
                'phone'                 => $driver->user?->phone_number ?? '---',
                'email'                 => $driver->user?->email ?? '---',
                'status'                => $driver->status,
                'rating_avg'            => (float) ($driver->rating_avg ?? 5.0),
                'completed_trips_count' => (int) ($driver->completed_trips_count ?? 0),
                'active_subs_count'     => (int) ($driver->active_subs_count ?? 0),
                'retention_rate'        => (float) ($driver->retention_rate ?? 100.0),
                'vehicle_plate'         => $driver->vehicles?->first()?->plate_number ?? '---',
            ];
        });

        // حالة وثائق المركبات والرخص
        $today = Carbon::today();
        $in30Days = Carbon::today()->addDays(30);

        $totalVehicles = Vehicle::count();
        $expiredLicensesCount = Driver::whereNotNull('license_expiry')
            ->where('license_expiry', '<', $today)
            ->count();

        $expiringSoonLicensesCount = Driver::whereNotNull('license_expiry')
            ->whereBetween('license_expiry', [$today, $in30Days])
            ->count();

        $validLicensesCount = Driver::whereNotNull('license_expiry')
            ->where('license_expiry', '>', $in30Days)
            ->count();

        $expiringDriversList = Driver::with('user')
            ->whereNotNull('license_expiry')
            ->where('license_expiry', '<=', $in30Days)
            ->orderBy('license_expiry', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($driver) use ($today) {
                $daysLeft = (int) $today->diffInDays(Carbon::parse($driver->license_expiry), false);
                return [
                    'driver_id'      => $driver->id,
                    'name'           => $driver->user?->full_name,
                    'phone'          => $driver->user?->phone_number,
                    'license_expiry' => $driver->license_expiry,
                    'days_left'      => $daysLeft,
                    'is_expired'     => $daysLeft < 0,
                ];
            });

        return [
            'leaderboard' => [
                'data' => $leaderboard->values()->all(),
                'meta' => [
                    'current_page' => $drivers->currentPage(),
                    'last_page'    => $drivers->lastPage(),
                    'per_page'     => $drivers->perPage(),
                    'total'        => $drivers->total(),
                ],
            ],
            'vehicles_documents_status' => [
                'total_vehicles'            => $totalVehicles,
                'valid_licenses'            => $validLicensesCount,
                'expiring_soon_licenses'    => $expiringSoonLicensesCount,
                'expired_licenses'          => $expiredLicensesCount,
                'expiring_drivers_list'     => $expiringDriversList,
            ],
        ];
    }

    /**
     * تصدير التقارير (JSON أو CSV)
     */
    public function exportReport(string $type, array $filters, string $format = 'json'): mixed
    {
        $reportData = match ($type) {
            'kpi'           => $this->getKpiSummary(),
            'financial'     => $this->getFinancialReport($filters),
            'trips'         => $this->getTripsReport($filters),
            'subscriptions' => $this->getSubscriptionsReport($filters),
            'drivers'       => $this->getDriversPerformanceReport($filters),
            default         => $this->getKpiSummary(),
        };

        if ($format === 'csv') {
            return $this->convertToCsv($type, $reportData);
        }

        return $reportData;
    }

    /**
     * تحويل التقرير إلى تنسيق CSV للتحميل الإداري
     */
    private function convertToCsv(string $type, array $data): string
    {
        $output = fopen('php://temp', 'r+');

        // BOM لدعم الأحرف العربية في Excel
        fputs($output, "\xEF\xBB\xBF");

        if ($type === 'drivers') {
            fputcsv($output, ['الترتيب', 'رقم السائق', 'الاسم الكامل', 'رقم الهاتف', 'البريد الإلكتروني', 'الحالة', 'التقييم', 'الرحلات المكتملة', 'الاشتراكات النشطة', 'نسبة الالتزام']);
            foreach ($data['leaderboard']['data'] as $row) {
                fputcsv($output, [
                    $row['rank'],
                    $row['driver_id'],
                    $row['name'],
                    $row['phone'],
                    $row['email'],
                    $row['status'],
                    $row['rating_avg'],
                    $row['completed_trips_count'],
                    $row['active_subs_count'],
                    $row['retention_rate'] . '%',
                ]);
            }
        } elseif ($type === 'financial') {
            fputcsv($output, ['الفترة', 'عمولة المنصة', 'أرباح السائقين', 'إجمالي العمليات']);
            foreach ($data['revenue_summary']['chart_data'] as $row) {
                fputcsv($output, [
                    $row['period'],
                    $row['platform_commission'],
                    $row['driver_earnings'],
                    $row['total_volume'],
                ]);
            }
        } else {
            fputcsv($output, ['المفتاح', 'القيمة']);
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                fputcsv($output, [$k, $v]);
            }
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * تحليل فلترة التواريخ
     */
    private function parseDateFilters(array $filters): array
    {
        $groupBy = 'day';
        $dateFrom = Carbon::now()->startOfMonth();
        $dateTo   = Carbon::now()->endOfDay();

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $dateFrom = Carbon::parse($filters['date_from'])->startOfDay();
            $dateTo   = Carbon::parse($filters['date_to'])->endOfDay();
        } elseif (!empty($filters['period'])) {
            switch ($filters['period']) {
                case 'today':
                    $dateFrom = Carbon::today();
                    $dateTo   = Carbon::today()->endOfDay();
                    $groupBy  = 'day';
                    break;
                case 'week':
                    $dateFrom = Carbon::now()->startOfWeek();
                    $dateTo   = Carbon::now()->endOfWeek();
                    $groupBy  = 'day';
                    break;
                case 'month':
                    $dateFrom = Carbon::now()->startOfMonth();
                    $dateTo   = Carbon::now()->endOfMonth();
                    $groupBy  = 'day';
                    break;
                case 'year':
                    $dateFrom = Carbon::now()->startOfYear();
                    $dateTo   = Carbon::now()->endOfYear();
                    $groupBy  = 'month';
                    break;
            }
        }

        return [$dateFrom, $dateTo, $groupBy];
    }
}
