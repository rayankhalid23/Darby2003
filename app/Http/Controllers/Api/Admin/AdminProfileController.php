<?php

namespace App\Http\Controllers\Api\Admin;

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
 * 👤 الملف الشخصي للمشرف / مدير النظام (الحساب الحالي صاحب التوكن).
 *
 * يخدم كلا الدورين بنفس المسارات: role_id = 1 (مدير النظام) و role_id = 2 (مشرف).
 * كل عملية هنا تخص حساب صاحب التوكن فقط ولا تحتاج تمرير أي معرّف.
 */
class AdminProfileController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * جلب سجل المشرف المرتبط بالمستخدم الحالي
     */
    private function currentAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        // ⚠️ لا تُنشئ سجل مشرف تلقائياً هنا.
        // كان الكود ينفّذ firstOrCreate لأي مستخدم بدور 1 أو 2 بلا سجل، فيكتب في
        // قاعدة البيانات أثناء طلب قراءة (GET)، ويُخفي بيانات ناقصة بدل الإبلاغ عنها،
        // وينهار بخطأ 500 عند غياب الأعمدة المطلوبة بدل إرجاع 404 واضح.
        return Admin::with(['user', 'creator'])
            ->where('id', $user->id)
            ->first();
    }

    /**
     * 👁️ عرض الملف الشخصي
     * GET /api/admin/profile
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $admin = $this->currentAdmin($request);

            if (!$admin) {
                return response()->json([
                    'status'  => false,
                    'message' => 'حسابك غير مسجل ضمن المشرفين.'
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب بيانات الملف الشخصي بنجاح.',
                'data'    => new AdminResource($admin),
            ], 200);

        } catch (Exception $e) {
            Log::error("Show Admin Profile Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * ✏️ تعديل الملف الشخصي
     * POST /api/admin/profile
     */
    public function update(UpdateAdminProfileRequest $request): JsonResponse
    {
        try {
            $admin = $this->currentAdmin($request);

            if (!$admin) {
                return response()->json([
                    'status'  => false,
                    'message' => 'حسابك غير مسجل ضمن المشرفين.'
                ], 404);
            }

            $data = $request->validated();
            unset($data['current_password'], $data['password_confirmation']);

            $emailVerification = null;

            // البريد لا يُحدَّث مباشرة — يُرسل رابط تأكيد للبريد الجديد
            if (!empty($data['email']) && strtolower(trim($data['email'])) !== strtolower($admin->user->email)) {
                $emailVerification = $this->adminService->requestEmailChange(
                    $admin->user,
                    trim($data['email'])
                );
            }
            unset($data['email']);

            $updatedAdmin = $this->adminService->updateAdmin(
                $admin,
                $data,
                $request->file('avatar')
            );

            return response()->json([
                'status'  => true,
                'message' => $emailVerification
                    ? 'تم تحديث بياناتك بنجاح. أرسلنا رابط تأكيد لبريدك الجديد، يرجى فتحه لتفعيل التغيير.'
                    : 'تم تحديث ملفك الشخصي بنجاح.',
                'data'    => new AdminResource($updatedAdmin),
                'email_verification' => $emailVerification,
            ], 200);

        } catch (Exception $e) {
            Log::error("Update Admin Profile Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'تعذر تحديث الملف الشخصي، يرجى المحاولة لاحقاً.'
            ], 500);
        }
    }

    /**
     * 🔍 حالة طلب تغيير البريد الخاص بي
     * GET /api/admin/profile/email-change/status
     */
    public function emailChangeStatus(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'data'   => $this->adminService->getEmailChangeStatus($request->user()->id),
            ], 200);

        } catch (Exception $e) {
            Log::error("Profile Email Change Status Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * ❌ إلغاء طلب تغيير البريد الخاص بي
     * POST /api/admin/profile/email-change/cancel
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
     * 🔁 إعادة إرسال رابط التأكيد لبريدي الجديد
     * POST /api/admin/profile/email-change/resend
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
