<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\DriverFilterRequest;
use App\Http\Requests\Api\Admin\DriverReviewRequest;
use App\Http\Requests\Api\Admin\ReviewProfileChangeRequest; // الجديد الخاص بفحص قرار التعديل
use App\Http\Resources\Api\Admin\AdminDriverDetailResource;
use App\Http\Resources\Api\Admin\AdminDriverListResource;
use App\Http\Resources\Api\Admin\AdminPendingChangeResource; // الجديد الخاص بتنسيق شاشة التعديلات المعلقة
use App\Services\Admin\AdminDriverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class AdminDriverController extends Controller
{
    protected AdminDriverService $adminDriverService;

    public function __construct(AdminDriverService $adminDriverService)
    {
        $this->adminDriverService = $adminDriverService;
    }

    /**
     * 1. عرض جميع السائقين وطلبات إنشاء الحساب مع الفلترة الذكية والبحث
     */
    /**
     * 1. عرض جميع السائقين وطلبات إنشاء الحساب مع الفلترة الذكية والبحث
     */
    public function index(DriverFilterRequest $request): JsonResponse
    {
        try {
            try { $filters = $request->validated(); } catch (\Throwable $e) { $filters = $request->all(); }
            $drivers = $this->adminDriverService->getDriversList($filters ?? []);

            $drivers->load('user');

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب قائمة السائقين والطلبات بنجاح.',
                'data'    => AdminDriverListResource::collection($drivers), // 🚀 أعدنا الـ Resource المحمي
                'meta'    => [
                    'current_page' => $drivers->currentPage(),
                    'last_page'    => $drivers->lastPage(),
                    'per_page'     => $drivers->perPage(),
                    'total'        => $drivers->total(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Index Drivers Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ أثناء جلب قائمة السائقين.'], 500);
        }
    }

    /**
     * 2. عرض تفاصيل سائق معين بالكامل لمراجعة وثائقه وبيانات مركباته واحصائياته
     */
    public function show(int $id): JsonResponse
    {
        try {
            $driver = $this->adminDriverService->getDriverDetails($id);

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب تفاصيل السائق والوثائق بنجاح.',
                'data'    => new AdminDriverDetailResource($driver)
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Show Driver Details Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'عذراً، السائق المطلوب غير موجود في النظام.'], 404);
        }
    }

    /**
     * 2-ب. تعديل بيانات السائق مباشرة من قبل المشرف / الأدمن مع التوثيق
     * PUT /api/admin/drivers/{id}
     */
    public function update(\App\Http\Requests\Api\Admin\UpdateDriverByAdminRequest $request, int $id): JsonResponse
    {
        $uploadedPaths = [];
        try {
            $adminId = auth()->user()->admin->id ?? auth()->id();
            $data = $request->validated();

            // رفع صورة المركبة الجديدة إن أُرسلت
            if ($request->hasFile('vehicle_image')) {
                $path = $request->file('vehicle_image')->store('drivers/vehicles', 'public');
                $data['vehicle_image_path'] = 'storage/' . $path;
                $uploadedPaths[] = $path;
            }

            // رفع صور المستندات الجديدة إن أُرسلت
            $docFileMap = [
                'doc_license'              => 'doc_license_path',
                'doc_logbook'              => 'doc_logbook_path',
                'doc_insurance'            => 'doc_insurance_path',
                'doc_booklet_page'         => 'doc_booklet_page_path',
                'doc_stamp'                => 'doc_stamp_path',
                'doc_technical_inspection' => 'doc_technical_inspection_path',
            ];
            foreach ($docFileMap as $fileField => $pathKey) {
                if ($request->hasFile($fileField)) {
                    $path = $request->file($fileField)->store('drivers/documents', 'public');
                    $data[$pathKey] = 'storage/' . $path;
                    $uploadedPaths[] = $path;
                }
            }

            $driver = $this->adminDriverService->updateDriver($id, $data, $adminId);

            return response()->json([
                'success' => true,
                'status'  => true,
                'message' => 'تم تحديث بيانات السائق بنجاح.',
                'data'    => new AdminDriverDetailResource($driver)
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'عذراً، السائق المطلوب غير موجود في النظام.'
            ], 404);
        } catch (Exception $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            Log::error("Admin Update Driver Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'تعذر تحديث بيانات السائق: ' . $e->getMessage()
            ], 500);
        }
    }
    

    /**
     * 3. اتخاذ قرار القبول والتفعيل الاحتفالي أو الرفض المسبب للسائق (الطلب الأولي)
     */
    public function review(DriverReviewRequest $request, int $id): JsonResponse
    {
        try {
            $adminId = auth()->id() ?? 1; 

            try { $data = $request->validated(); } catch (\Throwable $e) { $data = $request->all(); }
            $driver = $this->adminDriverService->reviewDriverRequest($id, $data, $adminId);
            $statusText = $driver->status === 'Approved' ? 'قبول وتفعيل حسابه بنجاح' : 'رفض الطلب مع إرسال التوضيحات';

            return response()->json([
                'status'  => true,
                'message' => 'تمت مراجعة طلب السائق وتحديث حالته بنجاح وعمل التفعيل.',
                'data'    => [
                    'id'               => $driver->id,
                    'status'           => $driver->status,
                    'full_name'        => $driver->user->full_name ?? null,
                    'email'            => $driver->user->email ?? null,
                    'is_active'        => (bool) ($driver->user->is_active ?? false),
                    
                    // 🚀 الحل: الحماية المطلقة في حال كان التاريخ null أو نصاً غير مفرس
                    'updated_at'       => $driver->updated_at ? \Carbon\Carbon::parse($driver->updated_at)->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
                    
                    // إرجاع آخر قرار تم اتخاذه الآن للتوثيق الفوري
                    'latest_approval'  => $driver->approvals()->latest()->first([
                        'status', 'rejection_reason', 'created_at'
                    ])
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Review Driver Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'تعذر إتمام مراجعة طلب السائق.'], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 🚀 الدوال الثلاث الجديدة للتحكم في تحديثات الملفات المعلقة وإشعاراتها
    |--------------------------------------------------------------------------
    */

    /**
     * 4. عرض كافة طلبات تعديل البيانات والمركبات المعلقة للآدمن
     * GET: /api/admin/drivers/pending-changes
     */
    public function pendingChanges(): JsonResponse
    {
        try {
            $changes = $this->adminDriverService->getPendingChangesList();

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب كافة التعديلات المعلقة للسائقين بنجاح.',
                'data'    => AdminPendingChangeResource::collection($changes),
                'meta'    => [
                    'current_page' => $changes->currentPage(),
                    'last_page'    => $changes->lastPage(),
                    'per_page'     => $changes->perPage(),
                    'total'        => $changes->total(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Pending Changes Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ أثناء جلب قائمة التعديلات.'], 500);
        }
    }

    /**
     * 5. عرض تفصيلي مقارن لطلب تعديل محدد
     * GET: /api/admin/drivers/pending-changes/{id}
     */
    public function showPendingChange(int $id): JsonResponse
    {
        try {
            $change = $this->adminDriverService->getPendingChangeDetails($id);

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب تفاصيل طلب التعديل بنجاح للمقارنة الإدارية.',
                'data'    => new AdminPendingChangeResource($change)
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Show Pending Change Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * 6. معالجة قرار الموافقة والتطبيق الفوري أو الرفض المسبب لتعديلات السائق
     * POST: /api/admin/drivers/pending-changes/{id}/review
     */
    /**
     * 6. معالجة قرار الموافقة والتطبيق الفوري أو الرفض المسبب لتعديلات السائق
     * POST: /api/admin/drivers/pending-changes/{id}/review
     */
    public function reviewProfileChange(ReviewProfileChangeRequest $request, int $id): JsonResponse
    {
        try {
            // 🚀 جلب id الأدمن من جدول admins مباشرة عن طريق user_id
            $userId = auth()->id();
            
            $adminId = \Illuminate\Support\Facades\DB::table('admins')
                ->where('user_id', $userId)
                ->value('id');
    
            // إذا لم يُعثر على أدمن مربوط، نأخذ أول id أدمن متاح في الجدول
            if (!$adminId) {
                $adminId = \Illuminate\Support\Facades\DB::table('admins')->value('id') ?? 1;
            }
    
            $decision = $request->input('decision');
            $rejectionReason = $request->input('rejection_reason');
    
            $this->adminDriverService->reviewProfileChangeRequest($id, $decision, $rejectionReason, $adminId);
    
            $messageText = $decision === 'Approved' 
                ? 'تمت الموافقة على التعديلات وتطبيقها على حساب المركبة والسائق فوراً بنجاح.' 
                : 'تم رفض طلب تعديل البيانات، وإرسال مبررات الرفض إلى السائق.';
    
            return response()->json([
                'status'  => true,
                'message' => $messageText
            ], 200);
    
        } catch (\Exception $e) {
            Log::error("Admin Review Profile Change Error: " . $e->getMessage());
            
            $statusCode = in_array($e->getCode(), [400, 404, 422]) ? $e->getCode() : 500;
            
            return response()->json([
                'status'  => false, 
                'message' => 'تعذر معالجة طلب التعديل: ' . $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * 7. عرض قائمة طلبات غياب السائقين
     * GET: /api/admin/driver-absences
     */
    public function getAbsenceRequests(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['status', 'driver_id', 'date', 'search', 'per_page']);
            $absences = $this->adminDriverService->getDriverAbsenceRequests($filters);

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب قائمة طلبات غياب السائقين بنجاح.',
                'data'    => $absences->items(),
                'meta'    => [
                    'current_page' => $absences->currentPage(),
                    'last_page'    => $absences->lastPage(),
                    'per_page'     => $absences->perPage(),
                    'total'        => $absences->total(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Get Absence Requests Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ أثناء جلب طلبات الغياب.'], 500);
        }
    }

    /**
     * 8. عرض تفاصيل طلب غياب محدد
     * GET: /api/admin/driver-absences/{id}
     */
    public function showAbsenceRequest(int $id): JsonResponse
    {
        try {
            $absence = $this->adminDriverService->getDriverAbsenceDetails($id);

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب تفاصيل طلب الغياب بنجاح.',
                'data'    => $absence
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Show Absence Request Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'عذراً، طلب الغياب غير موجود.'], 404);
        }
    }

    /**
     * 9. موافقة الإدارة على طلب غياب السائق ونزعه فقط من الرحلات المحددة
     * POST: /api/admin/driver-absences/{id}/approve
     */
    public function approveAbsenceRequest(Request $request, int $id): JsonResponse
    {
        try {
            $userId = auth()->id();
            $adminId = \Illuminate\Support\Facades\DB::table('admins')
                ->where('user_id', $userId)
                ->value('id') ?? 1;

            $notes = $request->input('notes') ?? $request->input('admin_notes');
            $absence = $this->adminDriverService->approveDriverAbsence($id, $notes, $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'تمت الموافقة على طلب الغياب ونزع السائق من الرحلات المحددة بنجاح.',
                'data'    => [
                    'absence_id'   => $absence->id,
                    'driver_id'    => $absence->driver_id,
                    'absence_date' => $absence->absence_date->toDateString(),
                    'status'       => $absence->status,
                    'admin_notes'  => $absence->admin_notes,
                    'trips'        => $absence->trips->map(function ($trip) {
                        return [
                            'id'        => $trip->id,
                            'driver_id' => $trip->driver_id,
                            'status'    => $trip->status,
                        ];
                    }),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Approve Absence Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * 10. رفض الإدارة لطلب غياب السائق
     * POST: /api/admin/driver-absences/{id}/reject
     */
    public function rejectAbsenceRequest(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ], [
            'reason.required' => 'يرجى كتابة سبب رفض طلب الغياب.',
        ]);

        try {
            $userId = auth()->id();
            $adminId = \Illuminate\Support\Facades\DB::table('admins')
                ->where('user_id', $userId)
                ->value('id') ?? 1;

            $reason = $request->input('reason');
            $absence = $this->adminDriverService->rejectDriverAbsence($id, $reason, $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'تم رفض طلب الغياب وإبلاغ السائق بالسبب.',
                'data'    => [
                    'absence_id'   => $absence->id,
                    'driver_id'    => $absence->driver_id,
                    'absence_date' => $absence->absence_date->toDateString(),
                    'status'       => $absence->status,
                    'admin_notes'  => $absence->admin_notes,
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Reject Absence Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}