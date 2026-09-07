<?php

namespace App\Services\Driver;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverAbsence;
use App\Models\Driver\DriverDocument;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripEscrowHold;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DriverStatisticsService
{
    /**
     * نسبة عمولة المنصة ككسر عشري، من المصدر الوحيد pricing_settings.
     *
     * ⚠️ كانت مثبتة هنا بـ 12٪ بينما بقية النظام يحسب 8٪، فكانت أرباح السائق
     * المعروضة في لوحته لا تطابق ما يصل محفظته فعلياً.
     */
    protected function commissionRate(): float
    {
        return \App\Models\Shared\PricingSetting::commissionRateFraction();
    }

    /**
     * جلب كافة الإحصائيات المجمعة للسائق
     */
    public function getDashboardStatistics(Driver $driver, ?int $month = null, ?int $year = null): array
    {
        $targetYear = $year ?? (int) Carbon::now()->format('Y');
        $targetMonth = $month ?? (int) Carbon::now()->format('m');

        return [
            'financial_stats'                  => $this->getFinancialStats($driver, $targetMonth, $targetYear),
            'subscription_and_passenger_stats' => $this->getSubscriptionAndPassengerStats($driver),
            'trip_operations_stats'            => $this->getTripOperationsStats($driver),
            'quick_widgets'                    => $this->getQuickWidgets($driver),
        ];
    }

    /**
     * 1. 💰 الإحصائيات المالية (Financial Statistics)
     */
    public function getFinancialStats(Driver $driver, int $month, int $year): array
    {
        $currentMonthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $currentMonthEnd = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        $previousMonthStart = $currentMonthStart->copy()->subMonth()->startOfMonth();
        $previousMonthEnd = $currentMonthStart->copy()->subMonth()->endOfMonth();

        // 1. أرباح الشهر الحالي الصافية
        $currentMonthLedgerCents = (int) FinancialLedger::where('destination_account', \App\Services\Shared\FinancialLedgerService::driverAccount($driver))
            ->whereIn('type', ['driver_payout', 'trip_payout', 'payout'])
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->sum('amount');

        $currentMonthEscrowDinar = (float) TripEscrowHold::where('driver_id', $driver->id)
            ->where('hold_status', 'released_available')
            ->whereBetween('updated_at', [$currentMonthStart, $currentMonthEnd])
            ->sum(DB::raw('amount * (1 - ' . $this->commissionRate() . ') / 100'));

        $currentMonthSubsDinar = (float) DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'completed', 'active'])
            ->whereBetween('requests.start_date', [$currentMonthStart->toDateString(), $currentMonthEnd->toDateString()])
            ->sum('request_children.driver_net_price');

        $currentMonthNetEarnings = round(max(
            $currentMonthLedgerCents / 100,
            $currentMonthEscrowDinar,
            $currentMonthSubsDinar
        ), 2);

        // 2. أرباح الشهر السابق الصافية
        $previousMonthLedgerCents = (int) FinancialLedger::where('destination_account', \App\Services\Shared\FinancialLedgerService::driverAccount($driver))
            ->whereIn('type', ['driver_payout', 'trip_payout', 'payout'])
            ->whereBetween('created_at', [$previousMonthStart, $previousMonthEnd])
            ->sum('amount');

        $previousMonthEscrowDinar = (float) TripEscrowHold::where('driver_id', $driver->id)
            ->where('hold_status', 'released_available')
            ->whereBetween('updated_at', [$previousMonthStart, $previousMonthEnd])
            ->sum(DB::raw('amount * (1 - ' . $this->commissionRate() . ') / 100'));

        $previousMonthSubsDinar = (float) DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'completed', 'active'])
            ->whereBetween('requests.start_date', [$previousMonthStart->toDateString(), $previousMonthEnd->toDateString()])
            ->sum('request_children.driver_net_price');

        $previousMonthNetEarnings = round(max(
            $previousMonthLedgerCents / 100,
            $previousMonthEscrowDinar,
            $previousMonthSubsDinar
        ), 2);

        // 3. إجمالي الأرباح الصافية التراكمية (من حركات السجل المالي والمحافظ وحجوزات الضمان المفرج عنها)
        $ledgerTotalPayoutCents = (int) FinancialLedger::where('destination_account', \App\Services\Shared\FinancialLedgerService::driverAccount($driver))
            ->whereIn('type', ['driver_payout', 'trip_payout', 'payout'])
            ->sum('amount');

        $escrowReleasedNetDinar = (float) TripEscrowHold::where('driver_id', $driver->id)
            ->where('hold_status', 'released_available')
            ->sum(DB::raw('amount * (1 - ' . $this->commissionRate() . ') / 100'));

        $completedSubsNetDinar = (float) DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'completed', 'active'])
            ->where('requests.end_date', '<', Carbon::today()->toDateString())
            ->sum('request_children.driver_net_price');

        $totalNetEarnings = round(max(
            $ledgerTotalPayoutCents / 100,
            $escrowReleasedNetDinar,
            $completedSubsNetDinar,
            $currentMonthNetEarnings
        ), 2);

        // نسبة النمو مقارنة بالشهر السابق
        $growthPercentage = 0.0;
        $growthTrend = 'neutral';
        if ($previousMonthNetEarnings > 0) {
            $growthPercentage = round((($currentMonthNetEarnings - $previousMonthNetEarnings) / $previousMonthNetEarnings) * 100, 2);
            $growthTrend = $growthPercentage > 0 ? 'up' : ($growthPercentage < 0 ? 'down' : 'neutral');
        } elseif ($currentMonthNetEarnings > 0) {
            $growthPercentage = 100.0;
            $growthTrend = 'up';
        }

        // 2. الأرباح المتوقعة (النشطة): مجموع صافي الاشتراكات الفعّالة حالياً
        $activeSubsExpectedDinar = (float) DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'active'])
            ->where('requests.end_date', '>=', Carbon::today()->toDateString())
            ->sum('request_children.driver_net_price');

        $activeEscrowHoldsDinar = (float) TripEscrowHold::where('driver_id', $driver->id)
            ->whereIn('hold_status', ['held', 'captured_pending'])
            ->sum(DB::raw('amount * (1 - ' . $this->commissionRate() . ') / 100'));

        $expectedActiveEarnings = round(max($activeSubsExpectedDinar, $activeEscrowHoldsDinar), 2);

        // 3. المبالغ المعلقة أو المستحقة
        $walletBalance = round(((float) $driver->balance) / 100, 2);

        $pendingEscrowBalance = round((float) TripEscrowHold::where('driver_id', $driver->id)
            ->where('hold_status', 'captured_pending')
            ->sum(DB::raw('amount * (1 - ' . $this->commissionRate() . ') / 100')), 2);

        $cashDuesToPlatform = 0.00; // مبالغ الدفع النقدي المستحقة للمنصة إن وجدت

        return [
            'net_earnings' => [
                'total'             => $totalNetEarnings,
                'current_month'     => $currentMonthNetEarnings,
                'previous_month'    => $previousMonthNetEarnings,
                'growth_percentage' => $growthPercentage,
                'growth_trend'      => $growthTrend,
            ],
            'expected_active_earnings' => $expectedActiveEarnings,
            'pending_and_due'          => [
                'wallet_balance'         => $walletBalance,
                'escrow_pending_balance' => $pendingEscrowBalance,
                'cash_dues_to_platform'  => $cashDuesToPlatform,
                'currency'               => 'د.ل',
            ],
        ];
    }

    /**
     * 2. 👥 إحصائيات الاشتراكات والركاب (Subscription & Passenger Stats)
     */
    public function getSubscriptionAndPassengerStats(Driver $driver): array
    {
        // 1. عدد الطلاب المشتركين النشطين
        $activeStudentsFromActiveSubs = ActiveSubscription::forDriver($driver->id)
            ->where('status', 'active')
            ->whereHas('requestChild')
            ->with('requestChild')
            ->get()
            ->pluck('child_id')
            ->unique()
            ->count();

        $activeStudentsFromRequests = DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'active'])
            ->where('requests.end_date', '>=', Carbon::today()->toDateString())
            ->distinct('request_children.child_id')
            ->count('request_children.child_id');

        $activeStudentsCount = max($activeStudentsFromActiveSubs, $activeStudentsFromRequests);

        // 2. سعة المركبة والمقاعد المتاحة (تُحسب بطرح عدد الطلبة النشطين من capacity_manual)
        $vehicle = $driver->vehicle
            ?? $driver->vehicles()->where('status', 'Active')->first()
            ?? $driver->vehicles()->orderBy('id', 'desc')->first();

        $totalCapacity = $vehicle ? (int) $vehicle->capacity_manual : 0;
        $occupiedSeats = min($activeStudentsCount, $totalCapacity > 0 ? $totalCapacity : $activeStudentsCount);
        $availableSeats = max(0, $totalCapacity - $occupiedSeats);
        $occupancyRate = $totalCapacity > 0 ? round(($occupiedSeats / $totalCapacity) * 100, 2) : 0.0;

        // 3. السجل التاريخي للاشتراكات (المكتملة والملغاة)
        $completedSubsCount = DB::table('requests')
            ->where('driver_id', $driver->id)
            ->where(function ($q) {
                $q->where('status', 'completed')
                  ->orWhere(function ($subQ) {
                      $subQ->whereIn('status', ['accepted', 'active'])
                           ->where('end_date', '<', Carbon::today()->toDateString());
                  });
            })
            ->count();

        $cancelledSubsCount = SubscriptionRequest::where('driver_id', $driver->id)
            ->where('status', 'cancelled')
            ->count();

        $totalHistoricalSubscriptions = SubscriptionRequest::where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'active', 'completed', 'cancelled'])
            ->count();

        if ($totalHistoricalSubscriptions === 0 && ($completedSubsCount > 0 || $cancelledSubsCount > 0 || $activeStudentsCount > 0)) {
            $totalHistoricalSubscriptions = $completedSubsCount + $cancelledSubsCount + ($activeStudentsCount > 0 ? 1 : 0);
        }

        return [
            'active_students_count' => $activeStudentsCount,
            'vehicle_capacity'      => [
                'vehicle_model'   => $vehicle ? trim("{$vehicle->brand} {$vehicle->model}") : null,
                'plate_number'    => $vehicle?->plate_number,
                'total_capacity'  => $totalCapacity,
                'occupied_seats'  => $occupiedSeats,
                'available_seats' => $availableSeats,
                'occupancy_rate'  => $occupancyRate,
            ],
            'history' => [
                'completed_subscriptions_count'   => $completedSubsCount,
                'cancelled_subscriptions_count'   => $cancelledSubsCount,
                'total_historical_subscriptions' => $totalHistoricalSubscriptions,
            ],
        ];
    }

    /**
     * 3. 🛣️ إحصائيات الرحلات اليومية والتشغيل (Trip Operations Stats)
     */
    public function getTripOperationsStats(Driver $driver): array
    {
        // 1. إجمالي الرحلات المنجزة
        $totalCompletedTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->count();

        $morningTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('trip_type', 'like', '%morning%')
                  ->orWhere('shift_slot', 'like', '%morning%');
            })
            ->count();

        $eveningTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('trip_type', 'like', '%evening%')
                  ->orWhere('trip_type', 'like', '%return%')
                  ->orWhere('trip_type', 'like', '%afternoon%')
                  ->orWhere('shift_slot', 'like', '%evening%')
                  ->orWhere('shift_slot', 'like', '%afternoon%');
            })
            ->count();

        $todayCompletedTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereDate('trip_date', Carbon::today())
            ->count();

        // 2. معدل الالتزام بالمواعيد
        $scheduledTripsCount = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('scheduled_start_time')
            ->whereNotNull('actual_start_time')
            ->count();

        if ($scheduledTripsCount > 0) {
            // احتساب الرحلات التي بدأت في الوقت المحدد مع سماح 15 دقيقة
            $onTimeTrips = Trip::where('driver_id', $driver->id)
                ->where('status', 'completed')
                ->whereNotNull('scheduled_start_time')
                ->whereNotNull('actual_start_time')
                ->whereRaw('TIMESTAMPDIFF(MINUTE, scheduled_start_time, actual_start_time) <= 15')
                ->count();

            $punctualityRate = round(($onTimeTrips / $scheduledTripsCount) * 100, 2);
        } else {
            // إذا لم توجد رحلات مجدولة سابقة أو كل الرحلات تمت بنجاح
            $punctualityRate = $totalCompletedTrips > 0 ? 100.0 : 100.0;
        }

        // 3. الغيابات وأعطال المركبة
        $absenceDaysCount = DriverAbsence::where('driver_id', $driver->id)->count();

        $vehicleBreakdownsCount = Trip::where('driver_id', $driver->id)
            ->where(function ($q) {
                $q->where('status', 'suspended_breakdown')
                  ->orWhereNotNull('suspension_reason');
            })
            ->count();

        $totalDowntimeDays = $absenceDaysCount + $vehicleBreakdownsCount;

        return [
            'completed_trips' => [
                'total'           => $totalCompletedTrips,
                'morning_trips'   => $morningTrips,
                'evening_trips'   => $eveningTrips,
                'today_completed' => $todayCompletedTrips,
            ],
            'punctuality_rate' => $punctualityRate,
            'absences_and_breakdowns' => [
                'absence_days_count'      => $absenceDaysCount,
                'vehicle_breakdowns_count' => $vehicleBreakdownsCount,
                'total_downtime_days'     => $totalDowntimeDays,
            ],
        ];
    }

    /**
     * 4. ⚡ تنبيهات وإحصائيات سريعة (Widgets & Quick Alerts)
     */
    public function getQuickWidgets(Driver $driver): array
    {
        $today = Carbon::today();
        $fiveDaysFromNow = $today->copy()->addDays(5);

        // 1. اشتراكات تنتهي قريباً (خلال 5 أيام)
        $expiringQuery = DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->leftJoin('children', 'request_children.child_id', '=', 'children.id')
            ->leftJoin('schools', 'children.school_id', '=', 'schools.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'active'])
            ->whereBetween('requests.end_date', [$today->toDateString(), $fiveDaysFromNow->toDateString()])
            ->select([
                'requests.id as subscription_id',
                'children.id as child_id',
                'children.full_name as child_full_name',
                'schools.name as school_name',
                'requests.end_date',
                'requests.trip_direction',
            ])
            ->get();

        $expiringItems = [];
        foreach ($expiringQuery as $item) {
            $endDate = Carbon::parse($item->end_date);
            $daysRemaining = $today->diffInDays($endDate, false);

            $childName = trim($item->child_full_name ?? '');
            if (empty($childName)) {
                $childName = 'طالب مشترك #' . $item->child_id;
            }

            $expiringItems[] = [
                'subscription_id' => (int) $item->subscription_id,
                'child_id'        => (int) $item->child_id,
                'child_name'      => $childName,
                'school_name'     => $item->school_name ?? 'غير محدد',
                'end_date'        => $item->end_date,
                'days_remaining'  => max(0, (int) $daysRemaining),
                'direction'       => $item->trip_direction ?? 'two_way',
            ];
        }

        // 2. حالة الوثائق (رخصة القيادة + تأمين السيارة)
        $licenseExpiry = $driver->license_expiry ? Carbon::parse($driver->license_expiry) : null;
        $licenseDaysRemaining = $licenseExpiry ? (int) $today->diffInDays($licenseExpiry, false) : null;
        $licenseIsExpired = $licenseDaysRemaining !== null && $licenseDaysRemaining < 0;

        $licenseIndicator = 'green';
        $licenseStatusLabel = 'سارية ومفعلة';
        if ($licenseDaysRemaining === null) {
            $licenseIndicator = 'unknown';
            $licenseStatusLabel = 'غير مسجلة';
        } elseif ($licenseIsExpired) {
            $licenseIndicator = 'red';
            $licenseStatusLabel = 'منتهية الصلاحية';
        } elseif ($licenseDaysRemaining <= 15) {
            $licenseIndicator = 'yellow';
            $licenseStatusLabel = 'تنتهي قريباً';
        }

        // وثيقة التأمين
        $insuranceDoc = DriverDocument::where('driver_id', $driver->id)
            ->where('doc_type', 'INSURANCE')
            ->whereNotNull('insurance_expiry_date')
            ->orderBy('id', 'desc')
            ->first();

        $insuranceExpiry = $insuranceDoc?->insurance_expiry_date ? Carbon::parse($insuranceDoc->insurance_expiry_date) : null;
        $insuranceDaysRemaining = $insuranceExpiry ? (int) $today->diffInDays($insuranceExpiry, false) : null;
        $insuranceIsExpired = $insuranceDaysRemaining !== null && $insuranceDaysRemaining < 0;

        $insuranceIndicator = 'green';
        $insuranceStatusLabel = 'ساري ومفعل';
        if ($insuranceDaysRemaining === null) {
            $insuranceIndicator = 'unknown';
            $insuranceStatusLabel = 'غير مرفق';
        } elseif ($insuranceIsExpired) {
            $insuranceIndicator = 'red';
            $insuranceStatusLabel = 'منتهي الصلاحية';
        } elseif ($insuranceDaysRemaining <= 15) {
            $insuranceIndicator = 'yellow';
            $insuranceStatusLabel = 'ينتهي قريباً';
        }

        // المؤشر الإجمالي العام
        $overallIndicator = 'green';
        $overallStatusLabel = 'كافة الوثائق الرسمية سارية ومفعلة';

        if ($licenseIndicator === 'red' || $insuranceIndicator === 'red') {
            $overallIndicator = 'red';
            $overallStatusLabel = 'تنبيه: يوجد وثائق رسمية منتهية الصلاحية';
        } elseif ($licenseIndicator === 'yellow' || $insuranceIndicator === 'yellow') {
            $overallIndicator = 'yellow';
            $overallStatusLabel = 'تنبيه: يوجد وثائق رسمية تنتهي قريباً';
        }

        return [
            'expiring_soon_subscriptions' => [
                'count'          => count($expiringItems),
                'days_threshold' => 5,
                'items'          => $expiringItems,
            ],
            'documents_status' => [
                'overall_indicator'    => $overallIndicator,
                'overall_status_label' => $overallStatusLabel,
                'license'              => [
                    'label'          => 'رخصة القيادة',
                    'license_number' => $driver->license_number,
                    'expiry_date'    => $driver->license_expiry?->toDateString(),
                    'days_remaining' => $licenseDaysRemaining,
                    'is_expired'     => $licenseIsExpired,
                    'indicator'      => $licenseIndicator,
                    'status_label'   => $licenseStatusLabel,
                ],
                'insurance'            => [
                    'label'          => 'تأمين المركبة',
                    'expiry_date'    => $insuranceExpiry?->toDateString(),
                    'days_remaining' => $insuranceDaysRemaining,
                    'is_expired'     => $insuranceIsExpired,
                    'indicator'      => $insuranceIndicator,
                    'status_label'   => $insuranceStatusLabel,
                ],
            ],
        ];
    }
}
