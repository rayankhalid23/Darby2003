<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Admin;
use App\Services\Admin\AdminService;
use App\Http\Requests\Api\Admin\StoreAdminRequest;
use App\Http\Requests\Api\Admin\UpdateAdminRequest;
use App\Http\Resources\Api\Admin\AdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Exception;

class AdminController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * جلب شجرة الصلاحيات والأدوار المتاحة للنظام
     */
    public function rolesAndPermissions(Request $request): JsonResponse
    {
        try {
            $roles = \App\Models\Role::whereNotIn('name', ['parent', 'driver'])->get()->map(function ($role) {
                return [
                    'id'          => $role->id,
                    'key'         => $role->name,
                    'name'        => $role->display_name,
                    'description' => $role->description,
                    'permissions' => is_array($role->permissions) ? $role->permissions : (json_decode($role->permissions, true) ?? []),
                ];
            });

            $permissionsTree = \App\Constants\PermissionConstants::getPermissionsTree();

            return response()->json([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب شجرة الأدوار والصلاحيات بنجاح.',
                'data'    => [
                    'roles'            => $roles,
                    'permissions_tree' => $permissionsTree,
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error("Fetch Roles and Permissions Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ أثناء جلب الأدوار والصلاحيات.'], 500);
        }
    }

    /**
     * عرض قائمة المشرفين
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $admins = $this->adminService->getAllAdmins(
                (int) $request->query('per_page', 10),
                $request->query('search')
            );
            return response()->json([
                'status'  => true,
                'message' => 'تم جلب قائمة المشرفين بنجاح.',
                'data'    => [
                    'data'  => AdminResource::collection($admins),
                    'links' => [
                        'first' => $admins->url(1),
                        'last'  => $admins->url($admins->lastPage()),
                        'prev'  => $admins->previousPageUrl(),
                        'next'  => $admins->nextPageUrl(),
                    ],
                    'meta' => [
                        'current_page' => $admins->currentPage(),
                        'last_page'    => $admins->lastPage(),
                        'per_page'     => $admins->perPage(),
                        'total'        => $admins->total(),
                    ],
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error("Fetch Admins Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * إضافة مشرف جديد
     */
    public function store(StoreAdminRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            // منشئ الحساب يُؤخذ من المستخدم المصادَق حصراً (لا من مدخلات العميل).
            $validated['created_by'] = auth()->id();

            $admin = $this->adminService->createAdmin(
                $validated,
                $request->file('avatar') ?? $request->file('avatar_url')
            );

            return response()->json([
                'status'  => true,
                'message' => 'تم إضافة المشرف بنجاح.',
                'data'    => new AdminResource($admin)
            ], 201);
        } catch (Exception $e) {
            // تسجيل الخطأ التفصيلي داخلياً فقط، دون تسريب رسالة SQL الخام للعميل.
            Log::error("Store Admin Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'تعذر إضافة المشرف، يرجى مراجعة البيانات والمحاولة مرة أخرى.'], 500);
        }
    }

    /**
     * عرض مشرف واحد بالتحديد
     */
    public function show($id): JsonResponse
    {
        try {
            $admin = Admin::with(['user', 'creator'])->whereHas('user')->find($id);

            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'عذراً، المشرف غير موجود.'], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب بيانات المشرف.',
                'data'    => new AdminResource($admin)
            ], 200);
        } catch (Exception $e) {
            Log::error("Show Admin Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * تعديل بيانات المشرف (يدعم التحديث الجزئي الموثق)
     */
    public function update(UpdateAdminRequest $request, $id): JsonResponse
    {
        try {
            $admin = Admin::with('user')->find($id);

            if (!$admin || !$admin->user) {
                return response()->json(['status' => false, 'message' => 'عذراً، المشرف غير موجود.'], 404);
            }

            try { $data = $request->validated(); } catch (\Throwable $e) { $data = $request->all(); }
            $emailVerification = null;

            // البريد لا يُحدَّث مباشرة — يُسجَّل كطلب معلق ويُرسل رابط تأكيد للبريد الجديد
            if (!empty($data['email']) && strtolower(trim($data['email'])) !== strtolower($admin->user->email)) {
                $newEmail = trim($data['email']);
                unset($data['email']);

                $emailVerification = $this->adminService->requestEmailChange($admin->user, $newEmail);
            } else {
                unset($data['email']);
            }

            $updatedAdmin = $this->adminService->updateAdmin(
                $admin,
                $data,
                $request->file('avatar') ?? $request->file('avatar_url')
            );

            return response()->json([
                'status'  => true,
                'message' => $emailVerification
                    ? 'تم تحديث البيانات بنجاح. أرسلنا رابط تأكيد للبريد الجديد، يرجى فتحه لتفعيل التغيير.'
                    : 'تم تحديث بيانات المشرف بنجاح.',
                'data'    => new AdminResource($updatedAdmin),
                // يظهر ككائن عند وجود طلب معلق، و null عند عدم وجوده
                'email_verification' => $emailVerification,
            ], 200);

        } catch (Exception $e) {
            Log::error("Update Admin Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'تعذر تحديث البيانات: ' . $e->getMessage()], 500);
        }
    }

    /**
     * حذف مشرف نهائياً من النظام
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $admin = Admin::with('user')->whereHas('user')->find($id);

            if (!$admin) {
                return response()->json([
                    'status'  => false,
                    'message' => 'عذراً، المشرف غير موجود.'
                ], 404);
            }

            $currentUser = $request->user();

            // منع المشرف من حذف حسابه الشخصي بنفسه
            if ($currentUser && $currentUser->id === $admin->user_id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'لا يمكنك حذف حسابك الشخصي من هنا.'
                ], 403);
            }

            // حماية حساب مدير النظام الأساسي من الحذف
            if ((int) $admin->user->role_id === 1) {
                return response()->json([
                    'status'  => false,
                    'message' => 'لا يمكن حذف حساب مدير النظام الأساسي.'
                ], 403);
            }

            $deletedName = $admin->user->full_name;

            $this->adminService->deleteAdmin($admin, $currentUser?->id ?? $admin->created_by);

            return response()->json([
                'status'  => true,
                'message' => "تم حذف المشرف ({$deletedName}) بنجاح."
            ], 200);

        } catch (\Exception $e) {
            Log::error("Delete Admin Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() // 👈 تم التعديل هنا لتمرير نص الخطأ الحقيقي
            ], 400);
        }
    }

    /**
     * 🟢 تأكيد تغيير البريد — يُفتح من رابط البريد مباشرة (رابط موقّع عام)
     */
    public function approveEmailChange(Request $request, $token): JsonResponse
    {
        try {
            if (! $request->hasValidSignature()) {
                return response()->json([
                    'status'        => false,
                    'email_changed' => false,
                    'message'       => 'عذراً، هذا الرابط غير صالح أو انتهت صلاحيته!'
                ], 403);
            }

            $user = $this->adminService->approveEmailChange($token);

            return response()->json([
                'status'        => true,
                'email_changed' => true,
                'message'       => 'تم تفعيل وتحديث بريدك الإلكتروني بنجاح! 🎉',
                'data'          => ['email' => $user->email],
            ], 200);

        } catch (Exception $e) {
            Log::error("Approve Email Change Error: " . $e->getMessage());
            return response()->json([
                'status'        => false,
                'email_changed' => false,
                'message'       => $e->getMessage()
            ], 400);
        }
    }

    /**
     * 🔴 رفض تغيير البريد — يُفتح من رابط البريد مباشرة (رابط موقّع عام)
     */
    public function rejectEmailChange(Request $request, $token): JsonResponse
    {
        try {
            if (! $request->hasValidSignature()) {
                return response()->json(['status' => false, 'message' => 'رابط غير صالح أو تم التلاعب به.'], 403);
            }

            $this->adminService->rejectEmailChange($token);

            return response()->json([
                'status'  => true,
                'message' => 'تم إلغاء طلب تغيير البريد الإلكتروني بنجاح.'
            ], 200);

        } catch (Exception $e) {
            Log::error("Reject Email Change Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * 🔍 فحص حالة طلب تغيير البريد لمشرف معيّن
     * GET /api/admin/admins/{id}/email-change/status
     */
    public function emailChangeStatus($id): JsonResponse
    {
        try {
            $admin = Admin::with('user')->whereHas('user')->find($id);

            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'عذراً، المشرف غير موجود.'], 404);
            }

            return response()->json([
                'status' => true,
                'data'   => $this->adminService->getEmailChangeStatus($admin->user_id),
            ], 200);

        } catch (Exception $e) {
            Log::error("Email Change Status Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'حدث خطأ في النظام.'], 500);
        }
    }

    /**
     * ❌ إلغاء طلب تغيير البريد من لوحة التحكم
     * POST /api/admin/admins/{id}/email-change/cancel
     */
    public function cancelEmailChange($id): JsonResponse
    {
        try {
            $admin = Admin::with('user')->whereHas('user')->find($id);

            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'عذراً، المشرف غير موجود.'], 404);
            }

            $this->adminService->cancelEmailChange($admin->user_id);

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
     * POST /api/admin/admins/{id}/email-change/resend
     */
    public function resendEmailChange($id): JsonResponse
    {
        try {
            $admin = Admin::with('user')->whereHas('user')->find($id);

            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'عذراً، المشرف غير موجود.'], 404);
            }

            $emailVerification = $this->adminService->resendEmailChange($admin->user);

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