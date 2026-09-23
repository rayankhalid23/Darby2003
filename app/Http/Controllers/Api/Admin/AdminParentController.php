<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ParentFilterRequest;
use App\Http\Requests\Api\Admin\SuspendAccountRequest;
use App\Http\Resources\Api\Admin\AdminParentListResource;
use App\Services\Admin\AdminParentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class AdminParentController extends Controller
{
    protected AdminParentService $adminParentService;

    public function __construct(AdminParentService $adminParentService)
    {
        $this->adminParentService = $adminParentService;
    }

    /**
     * 1. عرض جميع أولياء الأمور مع بياناتهم وأبنائهم
     * GET /api/admin/parents
     */
    public function index(ParentFilterRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $parents = $this->adminParentService->getParentsList($filters);

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب قائمة أولياء الأمور بنجاح.',
                'data'    => AdminParentListResource::collection($parents),
                'meta'    => [
                    'current_page' => $parents->currentPage(),
                    'last_page'    => $parents->lastPage(),
                    'per_page'     => $parents->perPage(),
                    'total'        => $parents->total(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Index Parents Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ أثناء جلب قائمة أولياء الأمور.'], 500);
        }
    }

    /**
     * 2. عرض تفاصيل ولي أمر معين بالكامل مع أبنائه وعناوينه
     * GET /api/admin/parents/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $parent = $this->adminParentService->getParentDetails($id);

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب تفاصيل ولي الأمر بنجاح.',
                'data'    => new AdminParentListResource($parent)
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Show Parent Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'عذراً، ولي الأمر المطلوب غير موجود في النظام.'], 404);
        }
    }

    /**
     * 3. إيقاف حساب ولي الأمر
     * POST /api/admin/parents/{id}/suspend
     */
    public function suspend(SuspendAccountRequest $request, int $id): JsonResponse
    {
        try {
            $adminId = auth()->user()->admin->id ?? auth()->id();
            $parent = $this->adminParentService->suspendParent($id, $request->input('reason'), $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'تم إيقاف حساب ولي الأمر بنجاح.',
                'data'    => [
                    'id'        => $parent->id,
                    'is_active' => (bool) $parent->is_active,
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Suspend Parent Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'تعذر إيقاف حساب ولي الأمر: ' . $e->getMessage()], 404);
        }
    }

    /**
     * 4. إعادة تفعيل حساب ولي الأمر الموقوف
     * POST /api/admin/parents/{id}/activate
     */
    public function activate(int $id): JsonResponse
    {
        try {
            $adminId = auth()->user()->admin->id ?? auth()->id();
            $parent = $this->adminParentService->activateParent($id, $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'تم إعادة تفعيل حساب ولي الأمر بنجاح.',
                'data'    => [
                    'id'        => $parent->id,
                    'is_active' => (bool) $parent->is_active,
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Admin Activate Parent Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'تعذر إعادة تفعيل حساب ولي الأمر: ' . $e->getMessage()], 404);
        }
    }
}
