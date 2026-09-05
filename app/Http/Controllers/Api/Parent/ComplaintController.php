<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\StoreComplaintRequest;
use App\Http\Requests\Api\Parent\UpdateComplaintRequest;
use App\Http\Resources\Api\Parent\ComplaintResource;
use App\Services\Parent\ComplaintService;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class ComplaintController extends Controller
{
    protected ComplaintService $complaintService;

    public function __construct(ComplaintService $complaintService)
    {
        $this->complaintService = $complaintService;
    }

    /**
     * عرض قائمة الشكاوى الخاصة بولي الأمر
     */
    public function index(): JsonResponse
    {
        try {
            $complaints = $this->complaintService->getParentComplaints(
                auth()->id(),
                request()->only(['status', 'type', 'filter']) 
            );

            return response()->json([
                'success'    => true,
                'data'       => ComplaintResource::collection($complaints),
                'pagination' => [
                    'current_page' => $complaints->currentPage(),
                    'last_page'    => $complaints->lastPage(),
                    'total'        => $complaints->total(),
                    'per_page'     => $complaints->perPage(),
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error("Complaints Index Error: " . $e->getMessage());
            return response()->json([
                'success'    => false,
                'error_code' => 'FETCH_COMPLAINTS_FAILED',
                'message'    => 'حدث خطأ أثناء جلب قائمة الشكاوى، يرجى المحاولة لاحقاً.'
            ], 500);
        }
    }

    /**
     * تفاصيل شكوى معينة مع الحماية والتحقق
     */
    public function show(int $id): JsonResponse
    {
        try {
            $complaint = $this->complaintService->getParentComplaintDetail(auth()->id(), $id);

            if (!$complaint) {
                return response()->json([
                    'success'    => false,
                    'error_code' => 'COMPLAINT_NOT_FOUND',
                    'message' => 'الشكوى المطلوبة غير موجودة، أو ربما تم حذفها مسبقاً.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => new ComplaintResource($complaint),
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success'    => false,
                'error_code' => 'COMPLAINT_NOT_FOUND',
                'message'    => 'الشكوى المطلوبة غير موجودة في النظام.'
            ], 404);
        } catch (Exception $e) {
            Log::error("Complaint Show Error [ID {$id}]: " . $e->getMessage());
            return response()->json([
                'success'    => false,
                'error_code' => 'UNAUTHORIZED_OR_NOT_FOUND',
                'message'    => $e->getMessage() ?: 'غير مصرح لك بالوصول لهذه الشكوى أو أنها غير موجودة.'
            ], 403);
        }
    }

    /**
     * تقديم شكوى جديدة
     */
    public function store(StoreComplaintRequest $request): JsonResponse
    {
        try {
            $complaint = $this->complaintService->createComplaint(
                auth()->id(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تقديم الشكوى بنجاح، بانتظار مراجعة الإدارة.',
                'data'    => new ComplaintResource($complaint),
            ], 201);

        } catch (Exception $e) {
            Log::error("Complaint Store Error: " . $e->getMessage());
            return response()->json([
                'success'    => false,
                'error_code' => 'COMPLAINT_CREATION_FAILED',
                'message'    => 'تعذر تقديم الشكوى حالياً، يرجى التأكد من البيانات والمحاولة لاحقاً.'
            ], 400);
        }
    }

   /**
 * تعديل شكوى قائمة مع حماية ضد التعديل بعد المعالجة
 */
public function update(UpdateComplaintRequest $request, int $id): JsonResponse
{
    try {
        $complaint = $this->complaintService->updateComplaint(
            auth()->id(),
            $id,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الشكوى بنجاح.',
            'data'    => new ComplaintResource($complaint),
        ], 200);

    } catch (Exception $e) {
        Log::error("Complaint Update Error [ID {$id}]: " . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => $e->getMessage() ?: 'حدث خطأ أثناء تحديث الشكوى.',
        ], 400);
    }
}

    /**
     * حذف شكوى
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->complaintService->deleteComplaint(auth()->id(), $id);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الشكوى بنجاح.',
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success'    => false,
                'error_code' => 'COMPLAINT_NOT_FOUND',
                'message'    => 'الشكوى التي تحاول حذفها غير موجودة بالفعل.'
            ], 404);
        } catch (Exception $e) {
            Log::error("Complaint Destroy Error [ID {$id}]: " . $e->getMessage());
            return response()->json([
                'success'    => false,
                'error_code' => 'COMPLAINT_DELETE_RESTRICTED',
                'message'    => $e->getMessage() ?: 'لا يمكن حذف الشكوى بعد أن اتخذت الإدارة إجراءً عليها.'
            ], 400);
        }
    }

    /**
     * جلب رحلات السائق التابع له ولي الأمر (مع نظام تشخيص ذكي مدمج)
     */
    public function driverTrips(int $driverId): JsonResponse
    {
        try {
            // 1. استدعاء السيرفس الأساسية لجلب البيانات
            $trips = $this->complaintService->getDriverTripsForParent(auth()->id(), $driverId);

            $diagnostics = null;

            // 2. نظام التشخيص الذكي: إذا رجعت البيانات فارغة وكان وضع التطوير مفعلاً
            if (($trips === null || (is_countable($trips) && count($trips) === 0)) && config('app.debug')) {
                
                $parentId = auth()->id();
                
                // ب. التحقق من السائق: هل هو ممرر كـ user_id أم كـ driver_id؟
                $driverById = DB::table('drivers')->where('id', $driverId)->first();
                $driverByUserId = DB::table('drivers')->where('user_id', $driverId)->first();
                
                $realDriverId = null;
                if ($driverById) {
                    $realDriverId = $driverById->id;
                } elseif ($driverByUserId) {
                    $realDriverId = $driverByUserId->id;
                }

                // ج. التحقق من وجود اشتراك يربط الطرفين
                $subscription = null;
                if ($parentId && $realDriverId) {
                    $subscription = DB::table('active_subscriptions')
                        ->where('parent_id', $parentId)
                        ->where('driver_id', $realDriverId)
                        ->first();
                }

                // د. التحقق من الرحلات الإجمالية المسجلة لهذا السائق في جدول trips
                $rawTripsCount = $realDriverId ? DB::table('trips')->where('driver_id', $realDriverId)->count() : 0;
                $rawEventsCount = DB::table('trip_events')->count();

                // بناء تقرير الفحص لمساعدتك في قاعدة البيانات
                $diagnostics = [
                    'message' => 'تقرير فحص قاعدة البيانات للتأكد من سبب الفراغ:',
                    'parent_user_id' => auth()->id(),
                    'parent_record' => $parent ? "موجود (ID: {$parent->id})" : "غير موجود في جدول parents لـ User ID المذكور",
                    'input_driver_id' => $driverId,
                    'resolved_driver_id_in_db' => $realDriverId ?? "لا يوجد سائق بهذا الـ ID في جدول drivers",
                    'is_driver_passed_as_user_id' => $driverByUserId ? "نعم (السائق ممرر بـ user_id الخاص به)" : "لا",
                    'subscription' => $subscription ? "موجود (الحالة: " . ($subscription->status ?? 'غير محددة') . ")" : "لا يوجد أي اشتراك مسجل بين ولي الأمر هذا والسائق في جدول active_subscriptions",
                    'total_trips_for_driver_in_db' => $rawTripsCount,
                    'total_events_in_system' => $rawEventsCount
                ];

                Log::warning("DriverTrips Empty Response Diagnostics:", $diagnostics);
            }

            // الاستجابة النهائية
            $responsePayload = [
                'success' => true,
                'data'    => $trips ?? [],
            ];

            if ($diagnostics) {
                $responsePayload['diagnostics'] = $diagnostics;
            }

            return response()->json($responsePayload, 200);

        } catch (Exception $e) {
            Log::error("Complaint Driver Trips Error [Driver {$driverId}]: " . $e->getMessage());
            return response()->json([
                'success'     => false,
                'error_code'  => 'DRIVER_TRIPS_FETCH_FAILED',
                'message'     => 'تعذر جلب رحلات السائق المطلوبة.',
                'debug_error' => config('app.debug') ? $e->getMessage() : null
            ], 400);
        }
    }
}