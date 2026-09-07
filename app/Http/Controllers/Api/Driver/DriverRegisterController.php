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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class DriverRegisterController extends Controller
{
    protected DriverRegisterService $registerService;
    protected OtpService $otpService;

    public function __construct(DriverRegisterService $registerService, OtpService $otpService)
    {
        $this->registerService = $registerService;
        $this->otpService = $otpService;
    }

    /**
     * الخطوة 1: طلب تسجيل الحساب وإرسال الـ OTP (دون الحفظ في قاعدة البيانات)
     */
    public function registerAccount(RegisterAccountRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            
            // استدعاء الدالة الجديدة لإرسال الـ OTP فقط لحماية النظام من البيانات الوهمية
            $this->registerService->sendVerificationOtp($data);

            return response()->json([
                'status'  => true,
                'message' => 'تم إرسال رمز التحقق (OTP) إلى بريدك الإلكتروني بنجاح.',
            ], 200);

        } catch (Exception $e) {
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
        try {
            // 1. التحقق من صحة الـ OTP
            $result = $this->otpService->verify($request->email, $request->otp, 'REGISTER');
            
            if (!$result['success']) {
                return response()->json([
                    'status'  => false,
                    'message' => $result['message']
                ], 400);
            }

            // 2. إذا كان الرمز صحيحاً، نقوم بإنشاء الحساب وتفعيله مباشرة
            $user = $this->registerService->registerAccountAfterOtp($request->all());
            $driver = $user->driver;
            
            // 3. إنشاء توكن الدخول المباشر للحساب الجديد المفعّل
            $token = $user->createToken('driver_token')->plainTextToken;

            return response()->json([
                'status'    => true,
                'message'   => 'تم تفعيل الحساب وإنشاؤه بنجاح.',
                'user_id'   => $user->id,
                'driver_id' => $driver?->id,
                'token'     => $token
            ], 201);

        } catch (Exception $e) {
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
    public function completeProfile(CompleteProfileRequest $request, int $userId): JsonResponse
    {
        $authUser = $request->user();
        if ($authUser && (int) $authUser->id !== (int) $userId) {
            return response()->json([
                'status'  => false,
                'message' => 'غير مصرح لك بإكمال ملف مستخدم آخر.',
            ], 403);
        }

        $uploadedPaths = [];
        try {
            $data = $request->validated();

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