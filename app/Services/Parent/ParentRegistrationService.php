<?php

namespace App\Services\Parent;

use App\Models\User;
use App\Models\Parent\ParentModel;
use App\Services\Shared\OtpService;
use App\Services\Shared\EmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class ParentRegistrationService
{
    protected $otpService;
    protected $emailService;

    public function __construct(OtpService $otpService, EmailService $emailService)
    {
        $this->otpService = $otpService;
        $this->emailService = $emailService;
    }

    public function requestNewOtp(string $email): string
    {
        Log::info("Service: Starting requestNewOtp for email: {$email}");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning("Service: Validation failed. Invalid email format: {$email}");
            throw new Exception("البريد الإلكتروني غير صالح.");
        }

        $existingUser = User::where('email', $email)->where('is_active', 1)->first();
        if ($existingUser) {
            Log::warning("Service: Registration blocked. Email already active: {$email}");
            throw new Exception("هذا البريد مرتبط بحساب مفعل مسبقاً.");
        }

        try {
            $otpResult = $this->otpService->generate($email, 'REGISTER');
            if (!$otpResult['success']) {
                throw new Exception($otpResult['message']);
            }
            $code = $otpResult['code'];
            $this->emailService->sendOtp($email, ' المستخدم', $code, 3, null, 'REGISTER');
            Log::info("Service: OTP generated and sent successfully to: {$email}");
            return $code;
        } catch (Exception $e) {
            Log::error("Service: Failed to send OTP to {$email}. Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function registerParent(array $data)
    {
        Log::info("Service: Attempting to register new parent: " . ($data['email'] ?? 'N/A'));

        $result = $this->otpService->verify($data['email'], $data['otp'], 'REGISTER');
        if (!$result['success']) {
            Log::warning("Service: OTP verification failed for email: {$data['email']}. Message: {$result['message']}");
            throw new Exception($result['message']);
        }

        try {
            return DB::transaction(function () use ($data) {
                // ⚠️ role_id كان مثبّتاً على 3 — دور "مشرف شؤون السائقين والأسطول"
                // (staff) بجدول roles الحالي، لا دور ولي الأمر (id=7). نفس خلل
                // DriverRegisterService بالضبط، وبنفس الأثر: كل ولي أمر سجّل عبر
                // هذا المسار يمرّ من فحوصات $user->admin بصمت. نجلب المعرّف بالاسم
                // بدل رقم ثابت.
                $parentRoleId = \App\Models\Role::where('name', 'parent')->value('id');
                if (!$parentRoleId) {
                    throw new Exception('تعذّر إتمام التسجيل: دور "ولي الأمر" غير معرّف بجدول الأدوار.');
                }

                $user = User::create([
                    'full_name'         => $data['full_name'],
                    'email'             => $data['email'],
                    'phone_number'      => $data['phone_number'],
                    'alternative_phone' => $data['alternative_phone'] ?? null,
                    'password_hash'     => Hash::make($data['password']),
                    'role_id'           => $parentRoleId,
                    'is_active'         => 1,
                    'is_trusted'        => 1,
                    'email_verified_at' => Carbon::now(),
                    'last_login_at'     => Carbon::now(),
                    'avatar_url'        => $data['avatar_url'] ?? null,
                ]);
                Log::info("Service: User record created for ID: {$user->id}");

                // تسجيل الجهاز فقط إذا تم إرسال fcm_token حقيقي (فريد عالمياً في الجدول)؛
                // التسجيل الرسمي يتم عبر POST /api/user/device-token بعد تسجيل الدخول
                if (!empty($data['fcm_token'])) {
                    $deviceData = [
                        'user_id'     => $user->id,
                        'device_name' => $data['device_name'] ?? 'mobile_device',
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'device_id')) {
                        $deviceData['device_id'] = $data['device_id'] ?? null;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'platform')) {
                        $deviceData['platform'] = in_array(strtolower($data['platform'] ?? ''), ['ios', 'android', 'web']) ? strtolower($data['platform']) : 'web';
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'is_active')) {
                        $deviceData['is_active'] = true;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'last_active_at')) {
                        $deviceData['last_active_at'] = Carbon::now();
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'created_at')) {
                        $deviceData['created_at'] = Carbon::now();
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'updated_at')) {
                        $deviceData['updated_at'] = Carbon::now();
                    }

                    DB::table('user_devices')->updateOrInsert(
                        ['fcm_token' => $data['fcm_token']],
                        $deviceData
                    );
                    Log::info("Service: Device registered for User ID: {$user->id}");
                }

                return $user;
            });
        } catch (Exception $e) {
            Log::error("Service: Transaction failed during registerParent for {$data['email']}. Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getParentProfile($userId)
    {
        try {
            return User::with('parentProfile')->findOrFail($userId);
        } catch (Exception $e) {
            Log::error("Service: Failed to fetch profile for User ID: {$userId}. Error: " . $e->getMessage());
            throw $e;
        }
    }
    public function updateParentProfile(int $userId, array $data)
    {
        Log::info("Service: Starting updateParentProfile for User ID: {$userId}");

        try {
            return DB::transaction(function () use ($userId, $data) {
                // 1. جلب بيانات المستخدم
                $user = User::findOrFail($userId); // نطاق المسار محمي لولي الأمر بالفعل (لا نعتمد على role_id الثابت)
                if (!empty($data['avatar_url'])) {
    if (!empty($user->avatar_url) && file_exists(public_path($user->avatar_url))) {
        @unlink(public_path($user->avatar_url));
    }
}

                // 2. تحديث البيانات الأساسية (باستثناء البريد وكلمة المرور وحالة الموثوقية)
                $updateData = collect($data)->except(['email', 'password', 'is_trusted'])->toArray();
                
                if (!empty($updateData)) {
                    $user->fill($updateData); 
                }

                // تحديث كلمة المرور إن أُرسلت
                if (!empty($data['password'])) {
                    $user->password_hash = Hash::make($data['password']);
                }

                // تحديث حالة الموثوقية بجدول users إن أُرسلت
                if (isset($data['is_trusted'])) {
                    $user->is_trusted = (bool) $data['is_trusted'];
                }

                // متغير مؤقت لتتبع ما إذا كان هناك طلب لتغيير البريد الإلكتروني
                $isEmailChanged = false;

                // 3. معالجة منطق تغيير البريد الإلكتروني (إن وجد)
                if (array_key_exists('email', $data) && strtolower(trim($data['email'])) !== strtolower($user->email)) {
                    $newEmail = trim($data['email']);
                    
                    if (User::where('email', $newEmail)->where('id', '!=', $userId)->exists()) {
                        Log::warning("Service: Email update blocked. Email {$newEmail} already in use.");
                        throw new Exception("البريد الإلكتروني الجديد مستخدم بالفعل.");
                    }

                    Cache::put("parent_email_change_{$user->id}", $newEmail, now()->addMinutes(30));
                    Cache::put("parent_email_change_status_{$user->id}", 'pending', now()->addMinutes(30));
                    Log::info("Service: Email change requested. Cache set for User ID: {$userId}");

                    $approveUrl = URL::temporarySignedRoute('parent.profile.email.approve', now()->addMinutes(30), ['id' => $user->id]);
                    $this->emailService->sendParentEmailChangeLink($newEmail, $user->full_name, $approveUrl, $approveUrl);

                    $isEmailChanged = true;
                    $pendingEmailTemp = $newEmail;
                }

                // 4. حفظ التعديلات الفعلية في قاعدة البيانات
                $user->save();

                if ($isEmailChanged) {
                    $user->email_change_pending = true;
                    $user->pending_new_email = $pendingEmailTemp;
                }

                Log::info("Service: User fields updated and saved successfully for ID: {$userId}");

                return $user->load('parentProfile');
            });
        } catch (Exception $e) {
            Log::error("Service: updateParentProfile failed for ID {$userId}. Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function approveEmailChange(int $userId): bool
    {
        Log::info("Service: Attempting to approve email change for User ID: {$userId}");
        $cacheKey = "parent_email_change_{$userId}";

        if (!Cache::has($cacheKey)) {
            Log::warning("Service: Invalid or expired email change link for User ID: {$userId}");
            Cache::put("parent_email_change_status_{$userId}", 'expired', now()->addMinutes(15));
            throw new Exception("رابط التأكيد منتهي الصلاحية أو غير صالح.");
        }

        $newEmail = Cache::get($cacheKey);

        try {
            DB::transaction(function () use ($userId, $newEmail, $cacheKey) {
                User::where('id', $userId)->update(['email' => $newEmail, 'email_verified_at' => Carbon::now()]);
                Cache::forget($cacheKey);
                Cache::put("parent_email_change_status_{$userId}", 'verified', now()->addMinutes(15));
            });
            Log::info("Service: Email successfully updated for User ID: {$userId} to {$newEmail}");
            return true;
        } catch (Exception $e) {
            Log::error("Service: Error during approveEmailChange for ID {$userId}. Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function rejectEmailChange(int $userId): bool
    {
        $cacheKey = "parent_email_change_{$userId}";
        Cache::forget($cacheKey);
        Cache::put("parent_email_change_status_{$userId}", 'rejected', now()->addMinutes(15));
        Log::info("Service: Email change rejected for User ID: {$userId}");
        return true;
    }

    public function cancelEmailChange(int $userId): bool
    {
        Cache::forget("parent_email_change_{$userId}");
        Cache::forget("parent_email_change_status_{$userId}");
        Log::info("Service: Email change cancelled by user ID: {$userId}");
        return true;
    }

    public function resendEmailChange(int $userId): bool
    {
        $cacheKey = "parent_email_change_{$userId}";
        if (!Cache::has($cacheKey)) {
            throw new Exception("لا يوجد طلب معلق لتعديل البريد الإلكتروني لإعادة إرساله.");
        }

        $newEmail = Cache::get($cacheKey);
        $user = User::findOrFail($userId);

        $approveUrl = URL::temporarySignedRoute('parent.profile.email.approve', now()->addMinutes(30), ['id' => $user->id]);
        $this->emailService->sendParentEmailChangeLink($newEmail, $user->full_name, $approveUrl, $approveUrl);
        Log::info("Service: Email change link resent to: {$newEmail} for user ID: {$userId}");
        return true;
    }
}