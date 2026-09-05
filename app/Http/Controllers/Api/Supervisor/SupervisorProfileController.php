<?php

namespace App\Http\Controllers\Api\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateAdminProfileRequest;
use App\Http\Resources\Api\Admin\AdminResource;
use App\Models\Admin\Admin;
use App\Services\Admin\AdminService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 👤 الملف الشخصي للمشرف (Supervisor Profile Controller)
 */
class SupervisorProfileController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    private function currentSupervisor(Request $request): ?Admin
    {
        return Admin::with(['user', 'creator'])
            ->where('id', $request->user()->id)
            ->first();
    }

    /**
     * 👁️ عرض الملف الشخصي للمشرف
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $supervisor = $this->currentSupervisor($request);

            if (!$supervisor) {
                return response()->json([
                    'status'  => false,
                    'message' => 'حسابك غير مسجل ضمن المشرفين.'
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب بيانات الملف الشخصي بنجاح.',
                'data'    => new AdminResource($supervisor),
            ], 200);

        } catch (Exception $e) {
            Log::error("Show Supervisor Profile Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * ✏️ تعديل الملف الشخصي للمشرف
     */
    public function update(UpdateAdminProfileRequest $request): JsonResponse
    {
        try {
            $supervisor = $this->currentSupervisor($request);

            if (!$supervisor) {
                return response()->json([
                    'status'  => false,
                    'message' => 'حسابك غير مسجل ضمن المشرفين.'
                ], 404);
            }

            $data = $request->validated();
            unset($data['current_password'], $data['password_confirmation']);

            $emailVerification = null;

            if (!empty($data['email']) && strtolower(trim($data['email'])) !== strtolower($supervisor->user->email)) {
                $emailVerification = $this->adminService->requestEmailChange(
                    $supervisor->user,
                    trim($data['email'])
                );
            }
            unset($data['email']);

            $updatedSupervisor = $this->adminService->updateAdmin(
                $supervisor,
                $data,
                $request->file('avatar')
            );

            return response()->json([
                'status'  => true,
                'message' => $emailVerification
                    ? 'تم تحديث بياناتك بنجاح. أرسلنا رابط تأكيد لبريدك الجديد، يرجى فتحه لتفعيل التغيير.'
                    : 'تم تحديث ملفك الشخصي بنجاح.',
                'data'    => new AdminResource($updatedSupervisor),
                'email_verification' => $emailVerification,
            ], 200);

        } catch (Exception $e) {
            Log::error("Update Supervisor Profile Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'تعذر تحديث الملف الشخصي، يرجى المحاولة لاحقاً.'
            ], 500);
        }
    }

    /**
     * 🔍 حالة طلب تغيير البريد
     */
    public function emailChangeStatus(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'data'   => $this->adminService->getEmailChangeStatus($request->user()->id),
            ], 200);

        } catch (Exception $e) {
            Log::error("Supervisor Email Change Status Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * ❌ إلغاء طلب تغيير البريد
     */
    public function cancelEmailChange(Request $request): JsonResponse
    {
        try {
            $this->adminService->cancelEmailChange($request->user()->id);

            return response()->json([
                'status'  => true,
                'message' => 'تم إلغاء طلب تغيير البريد الإلكتروني.'
            ], 200);

        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * 🔁 إعادة إرسال رابط التأكيد للبريد الجديد
     */
    public function resendEmailChange(Request $request): JsonResponse
    {
        try {
            $emailVerification = $this->adminService->resendEmailChange($request->user());

            return response()->json([
                'status'             => true,
                'message'            => 'تمت إعادة إرسال رابط التأكيد بنجاح.',
                'email_verification' => $emailVerification,
            ], 200);

        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
