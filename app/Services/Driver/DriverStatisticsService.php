<?php

namespace App\Services\Driver;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverAbsence;
use App\Models\Driver\DriverDocument;
use App\Models\Driver\VehicleDocument;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripEscrowHold;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * جلب أحدث تاريخ انتهاء لوثيقة تأمين خاصة بمركبات السائق.
     * المصدر الأساسي: جدول vehicle_documents (doc_type = INSURANCE, expiry_date) عبر hasManyThrough.
     * الفولباك: جدول driver_documents القديم إن كان موجوداً — لأنه غير موجود في كل البيئات.
     */
    protected function resolveInsuranceExpiry(Driver $driver): ?string
    {
        $expiry = VehicleDocument::whereIn(
                'vehicle_id',
                DB::table('vehicles')->where('driver_id', $driver->id)->pluck('id')
            )
            ->where('doc_type', 'INSURANCE')
            ->whereNotNull('expiry_date')
            ->orderByDesc('expiry_date')
            ->value('expiry_date');

        if ($expiry) {
            return $expiry instanceof Carbon ? $expiry->toDateString() : (string) $expiry;
        }

        if (Schema::hasTable('driver_documents')) {
            $legacy = DriverDocument::where('driver_id', $driver->id)
                ->where('doc_type', 'INSURANCE')
                ->whereNotNull('insurance_expiry_date')
                ->orderByDesc('id')
                ->value('insurance_expiry_date');

            return $legacy ? (string) $legacy : null;
        }

        return null;
    }

    /**
     * جلب كافة الإحصائيات المجمعة للسائق
     */
    public function getDashboardStatistics(Driver $driver, ?int $month = null, ?int $year = null): array
    {
        $targetYear = $year ?? (int) Carbon::now()->format('Y');
        $targetMonth = $month ?? (int) Carbon::now()->format('m');

        return [
            'period'                           => [
                'month' => $targetMonth,
                'year'  => $targetYear,
            ],
            'financial_stats'                  => $this->getFinancialStats($driver, $targetMonth, $targetYear),
            'subscription_and_passenger_stats' => $this->getSubscriptionAndPassengerStats($driver),
            'trip_operations_stats'            => $this->getTripOperationsStats($driver, $targetMonth, $targetYear),
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

        $account = \App\Services\Shared\FinancialLedgerService::driverAccount($driver);
        $validCreditTypes = [
            'driver_payout', 'trip_payout', 'payout',
            'subscription_payment', 'subscription_settle',
            'trip_capture', 'location_change_fee', 'compensation'
        ];

        $wallet = $driver->wallet;

        // 1. أرباح الشهر الحالي الصافية
        $walletCurrentMonthCents = $wallet ? (int) $wallet->transactions()
            ->where('type', 'deposit')
            ->where('confirmed', true)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->sum('amount') : 0;

        $currentMonthLedgerCents = (int) FinancialLedger::where('destination_account', $account)
            ->whereIn('type', $validCreditTypes)
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
            $walletCurrentMonthCents / 100,
            $currentMonthLedgerCents / 100,
            $currentMonthEscrowDinar,
            $currentMonthSubsDinar
        ), 2);

        // 2. أرباح الشهر السابق الصافية
        $walletPreviousMonthCents = $wallet ? (int) $wallet->transactions()
            ->where('type', 'deposit')
            ->where('confirmed', true)
            ->whereBetween('created_at', [$previousMonthStart, $previousMonthEnd])
            ->sum('amount') : 0;

        $previousMonthLedgerCents = (int) FinancialLedger::where('destination_account', $account)
            ->whereIn('type', $validCreditTypes)
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
            $walletPreviousMonthCents / 100,
            $previousMonthLedgerCents / 100,
            $previousMonthEscrowDinar,
            $previousMonthSubsDinar
        ), 2);

        // 3. إجمالي الأرباح الصافية التراكمية (من إيداعات المحفظة، حركات السجل المالي، وحجوزات الضمان المفرج عنها، والاشتراكات)
        $walletTotalDepositsCents = $wallet ? (int) $wallet->transactions()
            ->where('type', 'deposit')
            ->where('confirmed', true)
            ->sum('amount') : 0;

        $walletCurrentBalanceCents = (int) $driver->balance;

        $ledgerTotalPayoutCents = (int) FinancialLedger::where('destination_account', $account)
            ->whereIn('type', $validCreditTypes)
            ->sum('amount');

        $escrowReleasedNetDinar = (float) TripEscrowHold::where('driver_id', $driver->id)
            ->where('hold_status', 'released_available')
            ->sum(DB::raw('amount * (1 - ' . $this->commissionRate() . ') / 100'));

        $completedSubsNetDinar = (float) DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'completed', 'active'])
            ->sum('request_children.driver_net_price');

        $totalNetEarnings = round(max(
            $walletTotalDepositsCents / 100,
            $walletCurrentBalanceCents / 100,
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
        // 1. عدد الطلاب المشتركين النشطين رسمياً مع السائق (بدون حالات الطوارئ/الإنقاذ)
        $activeSubscribedChildIds = $this->resolveActiveSubscribedChildIds($driver);
        $emergencyRescueChildIds  = $this->resolveEmergencyRescueChildIds($driver);
        
        $activeStudentsCount    = count($activeSubscribedChildIds);
        $emergencyStudentsCount = count($emergencyRescueChildIds);
        $totalCurrentPassengers = count(array_unique(array_merge($activeSubscribedChildIds, $emergencyRescueChildIds)));

        // 2. سعة المركبة والمقاعد المتاحة (تُحسب بطرح إجمالي الركاب الفعليين من سعة الحافلة)
        $vehicle = $driver->vehicle
            ?? $driver->vehicles()->where('status', 'Active')->first()
            ?? $driver->vehicles()->orderBy('id', 'desc')->first();

        $totalCapacity  = $vehicle ? (int) $vehicle->capacity_manual : 0;
        $occupiedSeats  = min($totalCurrentPassengers > 0 ? $totalCurrentPassengers : $activeStudentsCount, $totalCapacity > 0 ? $totalCapacity : $totalCurrentPassengers);
        $availableSeats = max(0, $totalCapacity - $occupiedSeats);
        $occupancyRate  = $totalCapacity > 0 ? round(($occupiedSeats / $totalCapacity) * 100, 2) : 0.0;

        // 3. إحصائيات الاشتراكات (النشطة، المكتملة، الملغاة، والإجمالي)
        // أ. الاشتراكات النشطة
        $activeSubsFromTable = ActiveSubscription::forDriver($driver->id)
            ->where('status', 'active')
            ->count();
        $activeSubsFromRequests = DB::table('requests')
            ->where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'active'])
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', Carbon::today()->toDateString());
            })
            ->count();
        $activeSubsCount = max($activeSubsFromTable, $activeSubsFromRequests);
        if ($activeSubsCount === 0 && $activeStudentsCount > 0) {
            $activeSubsCount = 1;
        }

        // ب. الاشتراكات المكتملة
        $completedSubsFromTable = ActiveSubscription::forDriver($driver->id)
            ->where('status', 'completed')
            ->count();
        $completedSubsFromRequests = DB::table('requests')
            ->where('driver_id', $driver->id)
            ->where(function ($q) {
                $q->where('status', 'completed')
                  ->orWhere(function ($subQ) {
                      $subQ->whereIn('status', ['accepted', 'active'])
                           ->whereNotNull('end_date')
                           ->where('end_date', '<', Carbon::today()->toDateString());
                  });
            })
            ->count();
        $completedSubsCount = max($completedSubsFromTable, $completedSubsFromRequests);

        // ج. الاشتراكات الملغاة
        $cancelledSubsFromTable = ActiveSubscription::forDriver($driver->id)
            ->where('status', 'cancelled')
            ->count();
        $cancelledSubsFromRequests = SubscriptionRequest::where('driver_id', $driver->id)
            ->where('status', 'cancelled')
            ->count();
        $cancelledSubsCount = max($cancelledSubsFromTable, $cancelledSubsFromRequests);

        // د. الطلبات الجديدة قيد الانتظار
        $pendingRequestsCount = SubscriptionRequest::where('driver_id', $driver->id)
            ->where('status', 'pending')
            ->count();

        // هـ. إجمالي الاشتراكات التاريخية
        $totalHistoricalSubscriptions = $activeSubsCount + $completedSubsCount + $cancelledSubsCount;
        $totalFromRequests = SubscriptionRequest::where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'active', 'completed', 'cancelled'])
            ->count();
        $totalHistoricalSubscriptions = max($totalHistoricalSubscriptions, $totalFromRequests);

        return [
            'active_students_count'           => $activeStudentsCount,
            'emergency_rescue_students_count' => $emergencyStudentsCount,
            'total_current_passengers_count'  => $totalCurrentPassengers,
            'vehicle_capacity'      => [
                'vehicle_model'   => $vehicle ? trim("{$vehicle->brand} {$vehicle->model}") : null,
                'plate_number'    => $vehicle?->plate_number,
                'total_capacity'  => $totalCapacity,
                'occupied_seats'  => $occupiedSeats,
                'available_seats' => $availableSeats,
                'occupancy_rate'  => $occupancyRate,
            ],
            'subscriptions' => [
                'pending_requests_count'         => $pendingRequestsCount,
                'active_subscriptions_count'     => $activeSubsCount,
                'completed_subscriptions_count'  => $completedSubsCount,
                'cancelled_subscriptions_count'  => $cancelledSubsCount,
                'total_subscriptions_count'      => $totalHistoricalSubscriptions,
            ],
            'history' => [
                'pending_requests_count'         => $pendingRequestsCount,
                'active_subscriptions_count'     => $activeSubsCount,
                'completed_subscriptions_count'  => $completedSubsCount,
                'cancelled_subscriptions_count'  => $cancelledSubsCount,
                'total_historical_subscriptions' => $totalHistoricalSubscriptions,
            ],
        ];
    }

    /**
     * استخراج معرفات الأطفال المشتركين رسمياً مع السائق (عقود واشتراكات نشطة سارية)
     * مع استبعاد ركاب الطوارئ والإنقاذ المؤقتين.
     */
    public function resolveActiveSubscribedChildIds(Driver $driver): array
    {
        // 1. أطفال من الاشتراكات النشطة عبر طلبات السائق المباشرة
        $fromActiveSubs = DB::table('active_subscriptions')
            ->join('request_children', 'active_subscriptions.request_child_id', '=', 'request_children.id')
            ->join('requests', 'active_subscriptions.subscription_request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->where('active_subscriptions.status', 'active')
            ->pluck('request_children.child_id');

        // 2. أطفال من الاشتراكات النشطة المسندة لمسارات السائق الدائمة
        $fromRouteSubs = DB::table('active_subscriptions')
            ->join('request_children', 'active_subscriptions.request_child_id', '=', 'request_children.id')
            ->join('routes', 'active_subscriptions.route_id', '=', 'routes.id')
            ->where('routes.driver_id', $driver->id)
            ->where('active_subscriptions.status', 'active')
            ->pluck('request_children.child_id');

        // 3. أطفال من طلبات الاشتراك المقبولة أو النشطة
        $fromRequests = DB::table('request_children')
            ->join('requests', 'request_children.request_id', '=', 'requests.id')
            ->where('requests.driver_id', $driver->id)
            ->whereIn('requests.status', ['accepted', 'active'])
            ->where(function ($q) {
                $q->whereNull('requests.end_date')
                  ->orWhere('requests.end_date', '>=', Carbon::today()->toDateString());
            })
            ->pluck('request_children.child_id');

        return $fromActiveSubs
            ->merge($fromRouteSubs)
            ->merge($fromRequests)
            ->unique()
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * استخراج معرفات ركاب الطوارئ والإنقاذ المؤقتين (الذين تم إسنادهم للسائق بسبب عطل حافلة أخرى)
     */
    public function resolveEmergencyRescueChildIds(Driver $driver): array
    {
        // 1. من خلال جدول إرساليات الطوارئ (الأطفال العالقين في الحافلة المتعطلة)
        $dispatches = DB::table('trip_breakdown_dispatches')
            ->where('substitute_driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'in_progress', 'broadcasted'])
            ->pluck('stranded_children_ids');

        $dispatchChildIds = collect();
        foreach ($dispatches as $rawIds) {
            $ids = is_string($rawIds) ? json_decode($rawIds, true) : (array) $rawIds;
            if (is_array($ids)) {
                $dispatchChildIds = $dispatchChildIds->merge($ids);
            }
        }

        // 2. من خلال محطات الإنقاذ المسندة لرحلات السائق الجارية والمجدولة
        $fromRescueStops = DB::table('trip_stops')
            ->join('trips', 'trip_stops.trip_id', '=', 'trips.id')
            ->where('trips.driver_id', $driver->id)
            ->whereIn('trips.status', ['pending', 'in_progress', 'scheduled'])
            ->whereNotNull('trip_stops.child_id')
            ->where(function ($q) {
                $q->where('trip_stops.label', 'like', '%إنقاذ%')
                  ->orWhere('trip_stops.label', 'like', '%rescue%');
            })
            ->pluck('trip_stops.child_id');

        return $dispatchChildIds
            ->merge($fromRescueStops)
            ->unique()
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * 3. 🛣️ إحصائيات الرحلات اليومية والتشغيل (Trip Operations Stats)
     * تدعم الفلترة بالشهر والسنة لتوفير بيانات حقيقية دقيقة عن الفترة المحددة:
     * - عدد إجمالي الرحلات المكتملة
     * - الرحلات التي غاب فيها السائق
     * - الرحلات التي حدث فيها عطل لمركبة السائق
     * - الرحلات التي استلمها السائق كـ سائق بديل
     */
    public function getTripOperationsStats(Driver $driver, ?int $month = null, ?int $year = null): array
    {
        $targetYear = $year ?? (int) Carbon::now()->format('Y');
        $targetMonth = $month ?? (int) Carbon::now()->format('m');

        $monthStart = Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfMonth();
        $monthEnd   = Carbon::createFromDate($targetYear, $targetMonth, 1)->endOfMonth();

        // 1. الرحلات المكتملة في الشهر المحدد
        $monthCompletedTripsQuery = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('trip_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                  ->orWhere(function ($sq) use ($monthStart, $monthEnd) {
                      $sq->whereNull('trip_date')
                         ->whereBetween('created_at', [$monthStart, $monthEnd]);
                  });
            });

        $monthCompletedTrips = $monthCompletedTripsQuery->count();

        $morningTrips = (clone $monthCompletedTripsQuery)
            ->where(function ($q) {
                $q->where('trip_type', 'like', '%morning%')
                  ->orWhere('shift_slot', 'like', '%morning%')
                  ->orWhere('trip_type', 'like', '%ذهاب%');
            })
            ->count();

        $eveningTrips = (clone $monthCompletedTripsQuery)
            ->where(function ($q) {
                $q->where('trip_type', 'like', '%evening%')
                  ->orWhere('trip_type', 'like', '%return%')
                  ->orWhere('trip_type', 'like', '%afternoon%')
                  ->orWhere('shift_slot', 'like', '%evening%')
                  ->orWhere('shift_slot', 'like', '%afternoon%')
                  ->orWhere('trip_type', 'like', '%عودة%');
            })
            ->count();

        $todayCompletedTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereDate('trip_date', Carbon::today())
            ->count();

        $allTimeCompletedTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->count();

        // 2. معدل الالتزام بالمواعيد
        $scheduledTripsQuery = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('scheduled_start_time')
            ->whereNotNull('actual_start_time')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('trip_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                  ->orWhere(function ($sq) use ($monthStart, $monthEnd) {
                      $sq->whereNull('trip_date')
                         ->whereBetween('created_at', [$monthStart, $monthEnd]);
                  });
            });

        $scheduledTripsCount = $scheduledTripsQuery->count();

        if ($scheduledTripsCount > 0) {
            $onTimeTrips = (clone $scheduledTripsQuery)
                ->whereRaw('TIMESTAMPDIFF(MINUTE, scheduled_start_time, actual_start_time) <= 15')
                ->count();

            $punctualityRate = round(($onTimeTrips / $scheduledTripsCount) * 100, 2);
        } else {
            $punctualityRate = $monthCompletedTrips > 0 ? 100.0 : 100.0;
        }

        // 3. الغيابات وأعطال المركبة للشهر المعني
        // أ) رحلات وأيام غياب السائق
        $linkedAbsentTripIds = DB::table('driver_absence_trips')
            ->join('driver_absences', 'driver_absence_trips.driver_absence_id', '=', 'driver_absences.id')
            ->where('driver_absences.driver_id', $driver->id)
            ->whereBetween('driver_absences.absence_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->pluck('driver_absence_trips.trip_id')
            ->unique();

        $unlinkedAbsences = DriverAbsence::where('driver_id', $driver->id)
            ->whereBetween('absence_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereDoesntHave('trips')
            ->get();

        $unlinkedTripsCount = 0;
        foreach ($unlinkedAbsences as $unlinkedAbsence) {
            $dateStr = $unlinkedAbsence->absence_date ? Carbon::parse($unlinkedAbsence->absence_date)->toDateString() : null;
            if ($dateStr) {
                $dayTrips = Trip::where('driver_id', $driver->id)->whereDate('trip_date', $dateStr)->count();
                $unlinkedTripsCount += max(1, $dayTrips);
            } else {
                $unlinkedTripsCount += 1;
            }
        }

        $monthAbsentTripsCount = $linkedAbsentTripIds->count() + $unlinkedTripsCount;
        $monthAbsenceDaysCount = DriverAbsence::where('driver_id', $driver->id)
            ->whereBetween('absence_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->count();

        // إجمالي الغيابات التراكمية (لكل الأوقات)
        $allTimeLinkedAbsentTripIds = DB::table('driver_absence_trips')
            ->join('driver_absences', 'driver_absence_trips.driver_absence_id', '=', 'driver_absences.id')
            ->where('driver_absences.driver_id', $driver->id)
            ->pluck('driver_absence_trips.trip_id')
            ->unique();

        $allTimeUnlinkedAbsences = DriverAbsence::where('driver_id', $driver->id)->whereDoesntHave('trips')->get();
        $allTimeUnlinkedTripsCount = 0;
        foreach ($allTimeUnlinkedAbsences as $uAbsence) {
            $dateStr = $uAbsence->absence_date ? Carbon::parse($uAbsence->absence_date)->toDateString() : null;
            if ($dateStr) {
                $dayTrips = Trip::where('driver_id', $driver->id)->whereDate('trip_date', $dateStr)->count();
                $allTimeUnlinkedTripsCount += max(1, $dayTrips);
            } else {
                $allTimeUnlinkedTripsCount += 1;
            }
        }
        $allTimeAbsentTripsCount = $allTimeLinkedAbsentTripIds->count() + $allTimeUnlinkedTripsCount;
        $allTimeAbsenceDaysCount = DriverAbsence::where('driver_id', $driver->id)->count();

        // ب) أعطال مركبة السائق للشهر المعني
        $breakdownTripIdsFromTrips = Trip::where('driver_id', $driver->id)
            ->where(function ($q) {
                $q->where('status', 'suspended_breakdown')
                  ->orWhereNotNull('suspension_reason');
            })
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('trip_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                  ->orWhere(function ($sq) use ($monthStart, $monthEnd) {
                      $sq->whereNull('trip_date')
                         ->whereBetween('created_at', [$monthStart, $monthEnd]);
                  });
            })
            ->pluck('id');

        $breakdownTripIdsFromDispatches = TripBreakdownDispatch::where('original_driver_id', $driver->id)
            ->whereBetween(DB::raw('COALESCE(dispatched_at, created_at)'), [$monthStart, $monthEnd])
            ->pluck('trip_id');

        $monthVehicleBreakdownsCount = $breakdownTripIdsFromTrips
            ->merge($breakdownTripIdsFromDispatches)
            ->unique()
            ->count();

        // إجمالي أعطال المركبة التراكمية (لكل الأوقات)
        $allTimeBreakdownTripIdsFromTrips = Trip::where('driver_id', $driver->id)
            ->where(function ($q) {
                $q->where('status', 'suspended_breakdown')
                  ->orWhereNotNull('suspension_reason');
            })
            ->pluck('id');
        $allTimeBreakdownTripIdsFromDispatches = TripBreakdownDispatch::where('original_driver_id', $driver->id)->pluck('trip_id');
        $allTimeVehicleBreakdownsCount = $allTimeBreakdownTripIdsFromTrips
            ->merge($allTimeBreakdownTripIdsFromDispatches)
            ->unique()
            ->count();

        $totalDowntimeDays = $monthAbsenceDaysCount + $monthVehicleBreakdownsCount;

        // 4. الرحلات التي استلمها السائق كـ سائق بديل (Substitute Driver Trips)
        $monthSubstituteDispatchesQuery = TripBreakdownDispatch::where('substitute_driver_id', $driver->id)
            ->whereIn('status', [TripBreakdownDispatch::STATUS_ACCEPTED, TripBreakdownDispatch::STATUS_COMPLETED])
            ->whereBetween(DB::raw('COALESCE(accepted_at, dispatched_at, created_at)'), [$monthStart, $monthEnd]);

        $monthSubstituteTripsCount = $monthSubstituteDispatchesQuery->count();
        $monthCompletedSubstituteTripsCount = (clone $monthSubstituteDispatchesQuery)
            ->where('status', TripBreakdownDispatch::STATUS_COMPLETED)
            ->count();

        $allTimeSubstituteTripsCount = TripBreakdownDispatch::where('substitute_driver_id', $driver->id)
            ->whereIn('status', [TripBreakdownDispatch::STATUS_ACCEPTED, TripBreakdownDispatch::STATUS_COMPLETED])
            ->count();

        // 5. حالة رحلات اليوم اللحظية
        $todayTrips = Trip::where('driver_id', $driver->id)
            ->whereDate('trip_date', Carbon::today())
            ->get();

        return [
            'period' => [
                'month' => $targetMonth,
                'year'  => $targetYear,
            ],
            'summary_cards' => [
                'completed_trips_count'    => $monthCompletedTrips,
                'absent_trips_count'       => $monthAbsentTripsCount,
                'vehicle_breakdowns_count' => $monthVehicleBreakdownsCount,
                'substitute_trips_count'   => $monthSubstituteTripsCount,
            ],
            'completed_trips' => [
                'total'           => $monthCompletedTrips,
                'all_time_total'  => $allTimeCompletedTrips,
                'morning_trips'   => $morningTrips,
                'evening_trips'   => $eveningTrips,
                'today_completed' => $todayCompletedTrips,
            ],
            'today_trips_overview' => [
                'total_scheduled' => $todayTrips->count(),
                'completed'       => $todayTrips->where('status', 'completed')->count(),
                'in_progress'     => $todayTrips->where('status', 'in_progress')->count(),
                'pending'         => $todayTrips->whereIn('status', ['pending', 'scheduled'])->count(),
            ],
            'punctuality_rate' => $punctualityRate,
            'absences_and_breakdowns' => [
                'absent_trips_count'       => $monthAbsentTripsCount,
                'absence_days_count'       => $monthAbsenceDaysCount,
                'vehicle_breakdowns_count' => $monthVehicleBreakdownsCount,
                'substitute_trips_count'   => $monthSubstituteTripsCount,
                'total_downtime_days'      => $totalDowntimeDays,
                'all_time' => [
                    'absent_trips_count'       => $allTimeAbsentTripsCount,
                    'absence_days_count'       => $allTimeAbsenceDaysCount,
                    'vehicle_breakdowns_count' => $allTimeVehicleBreakdownsCount,
                    'substitute_trips_count'   => $allTimeSubstituteTripsCount,
                ],
            ],
            'substitute_trips' => [
                'as_substitute_trips_count'        => $monthSubstituteTripsCount,
                'completed_substitute_trips_count' => $monthCompletedSubstituteTripsCount,
                'all_time_substitute_trips_count'  => $allTimeSubstituteTripsCount,
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

        // وثيقة التأمين — المصدر الأساسي: جدول vehicle_documents (المطبع) عبر مركبة السائق.
        // مع دعم رجعي لجدول driver_documents القديم إن كان موجوداً في بيئة ما.
        $insuranceExpiryRaw = $this->resolveInsuranceExpiry($driver);
        $insuranceExpiry = $insuranceExpiryRaw ? Carbon::parse($insuranceExpiryRaw) : null;
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
