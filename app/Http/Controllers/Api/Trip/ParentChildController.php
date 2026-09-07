<?php

namespace App\Http\Controllers\Api\Trip;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\Trip\ChildAbsenceRequest;
use App\Models\Shared\AbsenceLog;
use App\Models\Shared\ActiveSubscription;
use App\Services\Trip\TripLifecycleService;
use App\Services\Trip\TripStopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class ParentChildController extends Controller
{
    protected TripLifecycleService $lifecycleService;
    protected TripStopService $stopService;

    public function __construct(TripLifecycleService $lifecycleService, TripStopService $stopService)
    {
        $this->lifecycleService = $lifecycleService;
        $this->stopService = $stopService;
    }

    private function resolveParentId(): int
    {
        return (int) Auth::id();
    }

    private function checkChildBelongsToParent(int $childId): void
    {
        $userId = Auth::id();

        $child = \App\Models\Parent\Child::where('id', $childId)
            ->where('parent_id', $userId)
            ->first();

        if (!$child) {
            throw new Exception("عذراً، هذا الطفل غير موجود أو لا يتبع لحسابك.");
        }
    }

    /**
     * تسجيل غياب طفل في تواريخ محددة مع تحديد نوع الغياب
     * absence_type: pickup (ذهاب فقط) | dropoff (عودة فقط) | both (الاثنين، الافتراضي)
     */
    public function setAbsence(ChildAbsenceRequest $request, $childId): JsonResponse
    {
        $user = Auth::user();
        $parentId = $this->resolveParentId();
        $absenceType = $request->input('absence_type', 'both');

        try {
            $this->checkChildBelongsToParent((int) $childId);
            $this->lifecycleService->setChildAbsence((int) $childId, $request->dates, $absenceType);

            $typeLabels = [
                'pickup'  => 'رحلة الذهاب فقط',
                'dropoff' => 'رحلة العودة فقط',
                'both'    => 'الذهاب والعودة',
            ];

            Log::info("Parent Registered Child Absence", [
                'parent_id'    => $parentId,
                'parent_name'  => $user->full_name ?? $user->name,
                'child_id'     => $childId,
                'dates'        => $request->dates,
                'absence_type' => $absenceType
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم جدولة غياب الطفل بنجاح (' . ($typeLabels[$absenceType] ?? $absenceType) . ')',
                'data'    => [
                    'child_id'     => (int) $childId,
                    'dates'        => $request->dates,
                    'absence_type' => $absenceType,
                ]
            ]);
        } catch (Exception $e) {
            Log::error("Failed to set child absence", [
                'parent_id' => $parentId,
                'child_id'  => $childId,
                'error'     => $e->getMessage()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * إلغاء غياب مجدول للطفل في تواريخ محددة (يمكن تحديد نوع الغياب لإلغاء نوع معين فقط)
     */
    public function cancelAbsence(ChildAbsenceRequest $request, $childId): JsonResponse
    {
        $user = Auth::user();
        $parentId = $this->resolveParentId();
        $absenceType = $request->input('absence_type', null);

        try {
            $this->checkChildBelongsToParent((int) $childId);
            $this->lifecycleService->removeChildAbsence((int) $childId, $request->dates, $absenceType);

            Log::info("Parent Cancelled Child Absence", [
                'parent_id'    => $parentId,
                'parent_name'  => $user->full_name ?? $user->name,
                'child_id'     => $childId,
                'dates'        => $request->dates,
                'absence_type' => $absenceType ?? 'all_types'
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إلغاء الغياب وإعادة الطفل للمسارات التشغيلية',
                'data'    => [
                    'child_id'     => (int) $childId,
                    'dates'        => $request->dates,
                    'absence_type' => $absenceType ?? 'all',
                ]
            ]);
        } catch (Exception $e) {
            Log::error("Failed to cancel child absence", [
                'parent_id' => $parentId,
                'child_id'  => $childId,
                'error'     => $e->getMessage()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * عرض قائمة الغيابات المسجلة للطفل (المستقبلية)
     */
    public function getAbsences($childId): JsonResponse
    {
        try {
            $this->checkChildBelongsToParent((int) $childId);

            $typeLabels = [
                'pickup'  => 'رحلة الذهاب فقط',
                'dropoff' => 'رحلة العودة فقط',
                'both'    => 'ذهاب وعودة',
            ];

            $absences = AbsenceLog::where('child_id', $childId)
                ->where('absence_date', '>=', Carbon::today())
                ->orderBy('absence_date')
                ->get()
                ->map(fn($log) => [
                    'id'           => $log->id,
                    'date'         => Carbon::parse($log->absence_date)->format('Y-m-d'),
                    'absence_type' => $log->absence_type ?? 'both',
                    'type_label'   => $typeLabels[$log->absence_type ?? 'both'] ?? 'ذهاب وعودة',
                ]);

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'child_id' => (int) $childId,
                    'absences' => $absences,
                    'total'    => $absences->count(),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * جلب الأيام المتاحة لتسجيل الغياب بناءً على اشتراك الطفل الفعال
     */
    public function getAvailableAbsenceDates($childId): JsonResponse
    {
        try {
            $this->checkChildBelongsToParent((int) $childId);

            $subscription = ActiveSubscription::forChild($childId)
                ->where('status', 'active')
                ->with(['subscriptionRequest.children'])
                ->first();

            if (!$subscription || !$subscription->subscriptionRequest) {
                return response()->json([
                    'status'  => 'success',
                    'data'    => ['child_id' => (int) $childId, 'available_dates' => []],
                    'message' => 'لا يوجد اشتراك نشط لهذا الطفل.'
                ]);
            }

            $subReq     = $subscription->subscriptionRequest;
            $childPivot = $subReq->children->firstWhere('id', (int) $childId)?->pivot;

            $startDate  = Carbon::parse($childPivot?->start_date ?? $subReq->start_date ?? now()->startOfMonth());
            $endDate    = Carbon::parse($childPivot?->end_date ?? $subReq->end_date ?? now()->endOfMonth()->addMonths(2));
            $today      = Carbon::today();
            $fromDate   = $startDate->lt($today) ? $today->copy() : $startDate->copy();
            $limit      = $endDate->lt(Carbon::today()->addDays(60)) ? $endDate : Carbon::today()->addDays(60);

            // أيام العمل الأساسية: الأحد(0) إلى الخميس(4)
            $workDays = [0, 1, 2, 3, 4];

            $alreadyAbsent = AbsenceLog::where('child_id', $childId)
                ->where('absence_date', '>=', $fromDate->toDateString())
                ->pluck('absence_date')
                ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();

            $availableDates = [];
            $cursor = $fromDate->copy();
            while ($cursor->lte($limit)) {
                if (in_array($cursor->dayOfWeek, $workDays) && !in_array($cursor->format('Y-m-d'), $alreadyAbsent)) {
                    $availableDates[] = $cursor->format('Y-m-d');
                }
                $cursor->addDay();
            }

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'child_id'             => (int) $childId,
                    'subscription_start'   => $startDate->toDateString(),
                    'subscription_end'     => $endDate->toDateString(),
                    'available_dates'      => $availableDates,
                    'total_available'      => count($availableDates),
                    'already_absent_dates' => $alreadyAbsent,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * التأكيد اليدوي لصعود الطفل (بديل الـ QR في حال تعطل هاتف السائق أو كاميرته)
     */
    public function confirmManualPickup($param1 = null, $param2 = null): JsonResponse
    {
        $user = Auth::user();
        $parentId = $this->resolveParentId();

        $childId = request()->route('childId') ?? $param2 ?? $param1;
        $tripId  = request()->route('tripId')  ?? $param1 ?? $param2;

        try {
            $this->checkChildBelongsToParent((int) $childId);
            $this->stopService->confirmManualPickup((int) $tripId, (int) $childId, $parentId);

            Log::notice("Parent Confirmed Manual Pickup", [
                'trip_id'     => $tripId,
                'parent_id'   => $parentId,
                'parent_name' => $user->full_name ?? $user->name,
                'child_id'    => $childId
            ]);

            return response()->json(['status' => 'success', 'message' => 'قمتِ بتأكيد ركوب طفلك يدوياً، تم إخطار السائق']);
        } catch (Exception $e) {
            Log::error("Failed manual pickup confirmation", [
                'trip_id'   => $tripId,
                'parent_id' => $parentId,
                'child_id'  => $childId,
                'error'     => $e->getMessage()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}