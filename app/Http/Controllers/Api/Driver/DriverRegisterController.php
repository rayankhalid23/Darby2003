<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Driver\RegisterAccountRequest;
use App\Http\Requests\Api\Driver\CompleteProfileRequest;
use App\Http\Requests\Api\Driver\ProfileUpdateRequest; 
use App\Http\Requests\Api\Driver\AbandonRegistrationRequest;
use App\Http\Requests\Api\Shared\OtpRequest;
use App\Services\Driver\DriverRegisterService;
use App\Services\Shared\OtpService;
use App\Http\Resources\Api\Driver\DriverResource;
use App\Http\Controllers\Api\Shared\MediaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class DriverRegisterController extends Controller
{
    protected DriverRegisterService $registerService;
    protected OtpService $otpService;

    private const AVATAR_FIELDS = [
        'avatar',
        'avatar_url',
        'photo',
        'driver_photo',
        'personal_photo',
        'image',
        'profile_image',
    ];

    public function __construct(DriverRegisterService $registerService, OtpService $otpService)
    {
        $this->registerService = $registerService;
        $this->otpService = $otpService;
    }

    /**
     * استخراج وحفظ الصورة الشخصية للسائق من أي مسمى حقل مدعوم (ملف مرفوع أو Base64)
     */
    private function extractAndStoreAvatar(Request $request, ?string &$storedRelativePath = null): ?string
    {
        foreach (self::AVATAR_FIELDS as $field) {
            $file = $request->file($field);
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $path = $file->store('drivers/avatars', 'public');
                $storedRelativePath = $path;
                return 'storage/' . $path;
            }
        }

        foreach (self::AVATAR_FIELDS as $field) {
            $val = $request->input($field);
            if (!is_string($val) || trim($val) === '') {
                continue;
            }
            $val = trim($val);

            if (preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,(.+)$/s', $val, $matches)) {
                $ext = strtolower($matches[1]);
                if ($ext === 'jpeg') {
                    $ext = 'jpg';
                }
                $binary = base64_decode($matches[2], true);
                if ($binary !== false && strlen($binary) > 0) {
                    $path = 'drivers/avatars/' . Str::uuid() . '.' . $ext;
                    Storage::disk('public')->put($path, $binary);
                    $storedRelativePath = $path;
                    return 'storage/' . $path;
                }
            }
        }

        return null;
    }

    /**
     * الخطوة 1: طلب تسجيل الحساب وإرسال الـ OTP (مع الاحتفاظ المؤقت بالصورة المرفوعة لحين تفعيل الـ OTP)
     */
    public function registerAccount(RegisterAccountRequest $request): JsonResponse
    {
        $uploadedAvatarPath = null;
        try {
            $data = $request->validated();
            $email = trim(mb_strtolower((string) ($data['email'] ?? $request->input('email'))));

            // حفظ الصورة الشخصية مؤقتاً إذا أرسلها الفرونت في الخطوة الأولى (register)
            $avatarUrl = $this->extractAndStoreAvatar($request, $uploadedAvatarPath);
            if ($avatarUrl && $email !== '') {
                $previousCachedAvatar = Cache::get('driver_reg_avatar:' . $email);
                if ($previousCachedAvatar && is_string($previousCachedAvatar)) {
                    $oldRel = preg_replace('#^storage/#', '', $previousCachedAvatar);
                    if ($oldRel && $oldRel !== $uploadedAvatarPath) {
                        Storage::disk('public')->delete($oldRel);
                    }
                }
                Cache::put('driver_reg_avatar:' . $email, $avatarUrl, now()->addHours(2));
            }

            if ($email !== '') {
                Cache::put('driver_reg_data:' . $email, [
                    'full_name'         => $data['full_name'] ?? null,
                    'phone_number'      => $data['phone_number'] ?? null,
                    'gender'            => $data['gender'] ?? null,
                    'password'          => $data['password'] ?? null,
                    'alternative_phone' => $data['alternative_phone'] ?? null,
                    'device_name'       => $data['device_name'] ?? null,
                    'platform'          => $data['platform'] ?? null,
                    'fcm_token'         => $data['fcm_token'] ?? null,
                ], now()->addHours(2));
            }

            // استدعاء الدالة لإرسال الـ OTP لحماية النظام من البيانات الوهمية
            $this->registerService->sendVerificationOtp($data);

            return response()->json([
                'status'  => true,
                'message' => 'تم إرسال رمز التحقق (OTP) إلى بريدك الإلكتروني بنجاح.',
            ], 200);

        } catch (Exception $e) {
            if ($uploadedAvatarPath) {
                Storage::disk('public')->delete($uploadedAvatarPath);
            }
            Log::error("Account Registration OTP Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() ?: 'فشل إرسال رمز التحقق، يرجى المحاولة لاحقاً.'
            ], 400);
        }
    }

    /**
     * الخطوة 2: التحقق من رمز OTP وإنشاء الحساب وتفعيله فوراً
     */
    public function verifyOtp(OtpRequest $request): JsonResponse
    {
        $uploadedAvatarPath = null;
        try {
            $email = trim(mb_strtolower((string) $request->email));

            // 1. التحقق من صحة الـ OTP
            $result = $this->otpService->verify($email, $request->otp, 'REGISTER');
            
            if (!$result['success']) {
                return response()->json([
                    'status'  => false,
                    'message' => $result['message']
                ], 400);
            }

            $data = $request->all();

            // رفع الصورة الشخصية للسائق إن أُرسلت أثناء التحقق، أو استعادتها من الخطوة الأولى (register)
            $avatarUrl = $this->extractAndStoreAvatar($request, $uploadedAvatarPath);
            if ($avatarUrl) {
                $data['avatar_url'] = $avatarUrl;
                $cachedFromStep1 = Cache::pull('driver_reg_avatar:' . $email);
                if ($cachedFromStep1 && is_string($cachedFromStep1)) {
                    $oldRel = preg_replace('#^storage/#', '', $cachedFromStep1);
                    if ($oldRel && $oldRel !== $uploadedAvatarPath) {
                        Storage::disk('public')->delete($oldRel);
                    }
                }
            } else {
                $cachedFromStep1 = Cache::pull('driver_reg_avatar:' . $email);
                if ($cachedFromStep1 && is_string($cachedFromStep1)) {
                    $data['avatar_url'] = $cachedFromStep1;
                }
            }

            Cache::forget('driver_reg_data:' . $email);

            // 2. إذا كان الرمز صحيحاً، نقوم بإنشاء الحساب وتفعيله مباشرة
            $user = $this->registerService->registerAccountAfterOtp($data);
            $driver = $user->driver;
            
            // 3. إنشاء توكن الدخول المباشر للحساب الجديد المفعّل
            $token = $user->createToken('driver_token')->plainTextToken;

            return response()->json([
                'status'     => true,
                'message'    => 'تم تفعيل الحساب وإنشاؤه بنجاح.',
                'user_id'    => $user->id,
                'driver_id'  => $driver?->id,
                'avatar_url' => MediaController::urlFor($user->avatar_url),
                'token'      => $token
            ], 201);

        } catch (Exception $e) {
            if ($uploadedAvatarPath) {
                Storage::disk('public')->delete($uploadedAvatarPath);
            }
            Log::error("OTP Verification & Creation Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() ?: 'فشل التحقق وإنشاء الحساب، يرجى المحاولة لاحقاً.'
            ], 500);
        }
    }

    /**
     * المرحلة الثانية: إكمال ملف السائق (المركبة + الوثائق) لأول مرة
     */
    public function completeProfile(CompleteProfileRequest $request, ?int $userId = null): JsonResponse
    {
        $authUser = $request->user();
        $userId = $userId ?? ($authUser ? (int) $authUser->id : 0);

        if ($authUser && (int) $authUser->id !== (int) $userId) {
            return response()->json([
                'status'  => false,
                'message' => 'غير مصرح لك بإكمال ملف مستخدم آخر.',
            ], 403);
        }

        $uploadedPaths = [];
        try {
            $data = $request->validated();

            // رفع الصورة الشخصية للسائق إن أُرسلت (مع دعم المسميات البديلة أو Base64 من الفرونت)
            $avatarStoredPath = null;
            $avatarUrl = $this->extractAndStoreAvatar($request, $avatarStoredPath);
            if ($avatarUrl) {
                $data['avatar_path'] = $avatarUrl;
                if ($avatarStoredPath) {
                    $uploadedPaths[] = $avatarStoredPath;
                }
            }

            // رفع صورة المركبة
            $vehicleFile = $request->file('vehicle_image') ?? $request->file('vehicle_photo');
            $path = $vehicleFile->store('drivers/vehicles', 'public');
            $data['vehicle_image_path'] = 'storage/' . $path;
            $uploadedPaths[] = $path;

            // رفع صور المستندات
            $docFiles = [
                'doc_license_path'              => $request->file('doc_license') ?? $request->file('license_photo'),
                'doc_logbook_path'               => $request->file('doc_logbook') ?? $request->file('logbook_photo'),
                'doc_insurance_path'             => $request->file('doc_insurance') ?? $request->file('insurance_photo'),
                'doc_booklet_page_path'          => $request->file('doc_booklet_page'),
                'doc_stamp_path'                 => $request->file('doc_stamp'),
                'doc_technical_inspection_path'  => $request->file('doc_technical_inspection'),
            ];

            foreach ($docFiles as $pathKey => $file) {
                if ($file) {
                    $path = $file->store('drivers/documents', 'public');
                    $data[$pathKey] = 'storage/' . $path;
                    $uploadedPaths[] = $path;
                }
            }

            $driver = $this->registerService->completeProfile($userId, $data);

            return response()->json([
                'status'  => true,
                'message' => 'تم رفع البيانات بنجاح، بانتظار مراجعة الإدارة.',
                'data'    => new DriverResource($driver)
            ], 200);

        } catch (Exception $e) {
            foreach ($uploadedPaths as $storedPath) {
                Storage::disk('public')->delete($storedPath);
            }
            Log::error("Complete Profile Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'فشل إكمال الملف الشخصي للمركبة والمستندات.'], 500);
        }
    }

    /**
     * إلغاء وحذف الحساب غير المكتمل بعد التحقق من الـ OTP
     * DELETE /api/v1/driver/abandon-registration
     * POST   /api/v1/driver/cancel-registration
     */
    public function abandonRegistration(AbandonRegistrationRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'المستخدم غير مصادق.',
                ], 401);
            }

            // إذا أرسل العميل user_id في الـ Body، نتأكد أنه يطابق المستخدم المصادق بالتوكن
            if ($request->filled('user_id') && (int) $request->input('user_id') !== (int) $user->id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'غير مصرح لك بإلغاء تسجيل هذا الحساب.',
                ], 403);
            }

            $this->registerService->abandonRegistration($user);

            return response()->json([
                'status'  => true,
                'message' => 'تم إلغاء طلب التسجيل وحذف الحساب غير المكتمل بنجاح.',
            ], 200);

        } catch (Exception $e) {
            Log::error("Abandon Driver Registration Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() ?: 'فشل إلغاء طلب التسجيل.',
            ], 400);
        }
    }
}