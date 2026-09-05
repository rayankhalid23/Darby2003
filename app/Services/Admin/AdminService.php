<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\Admin\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Services\Shared\EmailService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; 
use Exception;

class AdminService
{
    protected $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * جلب قائمة المشرفين مع دعم البحث والترقيم
     * ملاحظة: جدول admins بلا أعمدة تواريخ لذلك نرتب تنازلياً حسب المعرف
     */
    public function getAllAdmins(int $perPage = 10, ?string $search = null)
    {
        return Admin::with(['user', 'creator'])
            // استبعاد المشرفين الذين حُذفت حساباتهم (soft deleted)
            ->whereHas('user', function ($query) use ($search) {
                if (!empty($search)) {
                    $query->where(function ($q) use ($search) {
                        $q->where('full_name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('phone_number', 'like', "%{$search}%");
                    });
                }
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }
    /**
     * 1️⃣ إنشاء مشرف جديد (مسموح فقط للأدمن الرئيسي role_id = 1)
     */
    public function createAdmin(array $data, ?UploadedFile $avatar = null): Admin
    {
        $currentUser = auth()->user();

        // 🛑 الأدمن الرئيسي فقط هو من يملك صلاحية الإضافة
        if (!$currentUser || $currentUser->role_id != 1) {
            throw new \Exception('ليس لديك الصلاحية في إضافة مشرف.');
        }

        return DB::transaction(function () use ($data, $avatar) {
            $avatarUrl = null;
            if ($avatar) {
                $path = $avatar->store('uploads/admins/avatars', 'public');
                $avatarUrl = 'storage/' . $path;
            }

            $generatedPassword = $data['password'] ?? (Str::random(8) . rand(10, 99));

            $user = User::create([
                'full_name'          => $data['full_name'],
                'email'              => $data['email'],
                'phone_number'       => $data['phone_number'],
                'password'           => Hash::make($generatedPassword),
                'password_hash'      => Hash::make($generatedPassword),
                'role_id'            => $data['role_id'] ?? 2,
                'custom_permissions' => $data['custom_permissions'] ?? null,
                'is_active'          => $data['is_active'] ?? 1,
                'created_by'         => $data['created_by'] ?? auth()->id() ?? 1,
                'avatar_url'         => $avatarUrl,
            ]);

            $this->emailService->sendAdminCredentials(
                $user->email,
                $user->full_name,
                $user->email,
                $generatedPassword
            );

            return Admin::find($user->id)->load(['user.role', 'creator']);
        });
    }

    /**
     * 2️⃣ تعديل بيانات المشرف
     */
    public function updateAdmin(Admin $admin, array $data, ?UploadedFile $avatar = null): Admin
    {
        $currentUser = auth()->user();

        if ($currentUser && !$currentUser->isSuperAdmin()) {
            // 🛑 منع المشرف من تعديل بيانات أي مشرف آخر
            if ($currentUser->id !== $admin->user_id) {
                throw new \Exception('ليس لديك الصلاحية لتعديل بيانات المشرفين.');
            }

            // 🛑 منع المشرف من تغيير حالة نشاط حسابه الشخصي (تفعيل / تعطيل)
            if (array_key_exists('is_active', $data)) {
                throw new \Exception('ليس لديك الصلاحية لتغيير حالة نشاط الحساب.');
            }
        }

        return DB::transaction(function () use ($admin, $data, $avatar, $currentUser) {
            try {
                $user = $admin->user;
                $updateData = [];

                if (array_key_exists('full_name', $data))          $updateData['full_name'] = $data['full_name'];
                if (array_key_exists('phone_number', $data))       $updateData['phone_number'] = $data['phone_number'];
                if (array_key_exists('is_active', $data))          $updateData['is_active'] = $data['is_active'];
                if (array_key_exists('role_id', $data) && ($currentUser?->isSuperAdmin() ?? false)) {
                    $updateData['role_id'] = $data['role_id'];
                }
                if (array_key_exists('custom_permissions', $data) && ($currentUser?->isSuperAdmin() ?? false)) {
                    $updateData['custom_permissions'] = $data['custom_permissions'];
                }

                if (!empty($data['password'])) {
                    $updateData['password_hash'] = Hash::make($data['password']);
                }

                if ($avatar) {
                    if ($user->avatar_url && Storage::disk('public')->exists(str_replace('storage/', '', $user->avatar_url))) {
                        Storage::disk('public')->delete(str_replace('storage/', '', $user->avatar_url));
                    }
                    $path = $avatar->store('uploads/admins/avatars', 'public');
                    $updateData['avatar_url'] = 'storage/' . $path;
                }

                if (!empty($updateData)) {
                    $user->update($updateData);
                }

                return $admin->refresh()->load(['user.role', 'creator']);

            } catch (\Exception $e) {
                Log::error("Error updating admin ID {$admin->id}: " . $e->getMessage());
                throw $e;
            }
        });
    }

    
  
    // =====================================================================
    // 📧 منظومة تغيير البريد الإلكتروني بالتأكيد (مطابقة لآلية ولي الأمر)
    //
    // المبدأ: البريد لا يتغير فوراً. نحفظ الطلب في الكاش لمدة 30 دقيقة ونرسل
    // رابطي قبول/رفض موقّعين للبريد الجديد. الواجهة تتابع الحالة عبر
    // نقطة status، ويمكنها الإلغاء أو إعادة الإرسال.
    //
    // مفاتيح الكاش (كلها مرتبطة بـ user_id الخاص بالمشرف المُعدَّل):
    //   admin_email_change_{userId}         => ['new_email' => ..., 'token' => ...]
    //   admin_email_change_token_{token}    => userId   (للبحث العكسي من الرابط)
    //   admin_email_change_status_{userId}  => pending|verified|rejected|expired
    // =====================================================================

    /** مدة صلاحية رابط التأكيد بالدقائق */
    public const EMAIL_CHANGE_TTL = 30;

    /**
     * تسجيل طلب تغيير بريد جديد وإرسال رابطي التأكيد والرفض
     */
    public function requestEmailChange(User $user, string $newEmail): array
    {
        // إلغاء أي طلب معلق سابق لنفس المشرف حتى لا تتزاحم الروابط
        $this->forgetEmailChange($user->id);

        $token      = Str::random(64);
        $expiresAt  = now()->addMinutes(self::EMAIL_CHANGE_TTL);
        $approveUrl = URL::temporarySignedRoute('admin.email.approve', $expiresAt, ['token' => $token]);
        $rejectUrl  = URL::temporarySignedRoute('admin.email.reject',  $expiresAt, ['token' => $token]);

        Cache::put("admin_email_change_{$user->id}", [
            'new_email' => $newEmail,
            'token'     => $token,
        ], $expiresAt);
        Cache::put("admin_email_change_token_{$token}", $user->id, $expiresAt);
        Cache::put("admin_email_change_status_{$user->id}", 'pending', $expiresAt);

        Log::info("=== Admin Email Change Request === user {$user->id} -> {$newEmail}");

        $this->emailService->sendEmailChangeLink($newEmail, $user->full_name, $approveUrl, $rejectUrl);

        return [
            'status'     => 'pending',
            'new_email'  => $newEmail,
            'expires_at' => $expiresAt->toDateTimeString(),
        ];
    }

    /**
     * تأكيد تغيير البريد عبر الرابط المرسل
     */
    public function approveEmailChange(string $token): User
    {
        $userId  = Cache::get("admin_email_change_token_{$token}");
        $pending = $userId ? Cache::get("admin_email_change_{$userId}") : null;

        if (!$userId || !$pending) {
            throw new Exception('الرابط منتهي الصلاحية أو تم استخدامه مسبقاً.');
        }

        $newEmail = $pending['new_email'];

        // إعادة التحقق من عدم استخدام البريد خلال فترة الانتظار
        if (User::where('email', $newEmail)->where('id', '!=', $userId)->exists()) {
            Cache::put("admin_email_change_status_{$userId}", 'rejected', now()->addMinutes(15));
            $this->forgetEmailChange($userId, $token);
            throw new Exception('تعذر التفعيل: البريد الإلكتروني أصبح مستخدماً لحساب آخر.');
        }

        $user = User::findOrFail($userId);

        DB::transaction(function () use ($user, $newEmail, $userId, $token) {
            $user->update([
                'email'             => $newEmail,
                'email_verified_at' => now(),
            ]);

            $this->forgetEmailChange($userId, $token);
            Cache::put("admin_email_change_status_{$userId}", 'verified', now()->addMinutes(15));
        });

        Log::info("Admin email change approved for user {$userId} -> {$newEmail}");

        return $user->refresh();
    }

    /**
     * رفض طلب تغيير البريد عبر الرابط المرسل
     */
    public function rejectEmailChange(string $token): void
    {
        $userId = Cache::get("admin_email_change_token_{$token}");

        if (!$userId) {
            throw new Exception('الرابط منتهي الصلاحية أو تم استخدامه مسبقاً.');
        }

        $this->forgetEmailChange($userId, $token);
        Cache::put("admin_email_change_status_{$userId}", 'rejected', now()->addMinutes(15));

        Log::info("Admin email change rejected for user {$userId}");
    }

    /**
     * حالة طلب تغيير البريد الحالي: pending | verified | rejected | expired
     */
    public function getEmailChangeStatus(int $userId): array
    {
        $pending = Cache::get("admin_email_change_{$userId}");
        $status  = Cache::get("admin_email_change_status_{$userId}");

        if (!$status) {
            $status = $pending ? 'pending' : 'expired';
        }

        return [
            'status'    => $status,
            'new_email' => $pending['new_email'] ?? null,
        ];
    }

    /**
     * إلغاء طلب تغيير البريد من الواجهة
     */
    public function cancelEmailChange(int $userId): void
    {
        $pending = Cache::get("admin_email_change_{$userId}");

        if (!$pending) {
            throw new Exception('لا يوجد طلب معلق لتغيير البريد الإلكتروني.');
        }

        $this->forgetEmailChange($userId, $pending['token'] ?? null);
        Cache::forget("admin_email_change_status_{$userId}");

        Log::info("Admin email change cancelled for user {$userId}");
    }

    /**
     * إعادة إرسال رابط التأكيد للبريد الجديد نفسه
     */
    public function resendEmailChange(User $user): array
    {
        $pending = Cache::get("admin_email_change_{$user->id}");

        if (!$pending) {
            throw new Exception('لا يوجد طلب معلق لتغيير البريد الإلكتروني لإعادة إرساله.');
        }

        return $this->requestEmailChange($user, $pending['new_email']);
    }

    /**
     * تنظيف مفاتيح الكاش الخاصة بطلب تغيير البريد
     */
    private function forgetEmailChange(int $userId, ?string $token = null): void
    {
        $existing = Cache::get("admin_email_change_{$userId}");
        $token  ??= $existing['token'] ?? null;

        Cache::forget("admin_email_change_{$userId}");

        if ($token) {
            Cache::forget("admin_email_change_token_{$token}");
        }
    }

    /**
     * 🗑️ حذف المشرف نهائياً مع تنظيف كل ما يتعلق به
     *
     * الخطوات: نقل ملكية المشرفين الذين أنشأهم (بسبب قيد RESTRICT على created_by)،
     * ثم إلغاء توكنات الدخول، ثم حذف صورته من التخزين، وأخيراً حذف الحساب والسجل.
     */

    public function deleteAdmin(Admin $admin, ?int $performedByUserId = null): void
{
    $currentUser = auth()->user();

    // 🛑 التحقق من الصلاحية: مسموح فقط للأدمن الرئيسي (role_id = 1)
    if (!$currentUser || $currentUser->role_id != 1) {
        throw new \Exception('ليس لديك الصلاحية لحذف المشرفين.');
    }

    $performedByUserId = $performedByUserId ?? $currentUser->id ?? 1;

    DB::transaction(function () use ($admin, $performedByUserId) {
        $user = $admin->user;

        if ($user) {
            // 1. نقل ملكية أي مشرفين أنشأهم هذا المشرف إلى منفّذ عملية الحذف
            Admin::where('created_by', $user->id)
                ->where('id', '!=', $admin->id)
                ->update(['created_by' => $performedByUserId]);

            // 2. إلغاء كل جلسات الدخول النشطة فوراً
            $user->tokens()->delete();

            // 3. إلغاء أي طلب معلق لتغيير البريد الإلكتروني
            $this->forgetEmailChange($user->id);
            Cache::forget("admin_email_change_status_{$user->id}");

            // 4. حذف ملف الصورة الشخصية من التخزين قبل حذف الحساب.
            // بدونه تبقى صور المشرفين المحذوفين متراكمة في القرص بلا أي مرجع
            // يمكن الوصول إليها عبر رابطها المباشر — نفس المنطق المطبق في updateAdmin.
            if ($user->avatar_url) {
                $avatarPath = str_replace('storage/', '', $user->avatar_url);
                if (Storage::disk('public')->exists($avatarPath)) {
                    Storage::disk('public')->delete($avatarPath);
                }
            }

            // 5. تعيين تاريخ الوقت الحالي في deleted_at (Soft Delete)
            $user->delete();
        }

        // 6. حذف سجل المشرف (Soft Delete)
        $admin->delete();

        Log::info("Admin ID {$admin->id} soft-deleted by user {$performedByUserId}");
    });
}
  
   
}