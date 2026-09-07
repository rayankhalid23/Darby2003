<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\ParentRegisterRequest;
use App\Http\Requests\Api\Parent\UpdateParentProfileRequest;
use App\Http\Requests\Api\Shared\OtpRequest; 
use App\Http\Resources\Api\Parent\ParentResource;
use App\Services\Parent\ParentRegistrationService;
use App\Http\Requests\Api\Shared\SendOtpRequest;
use App\Models\User; 
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ParentAuthController extends Controller
{
    protected ParentRegistrationService $registrationService;

    public function __construct(ParentRegistrationService $registrationService)
    {
        $this->registrationService = $registrationService;
    }

   /**
     * الخطوة 1: طلب كود تحقق (OTP) وإرساله
     * ملاحظة: تم تعديل الـ Request ليأخذ فقط 'email' لضمان السرعة وسهولة الاستخدام.
     */
    public function sendOtp(\App\Http\Requests\Api\Shared\SendOtpRequest $request): JsonResponse
    {
        Log::info("Parent: Initiating OTP request process for email: " . $request->email);
        
        try {
            // 1. التحقق من وجود الحساب في قاعدة البيانات
            $emailExists = User::where('email', $request->email)->exists();
            
            if ($emailExists) {
                Log::warning("Parent: OTP request rejected. Email already registered: " . $request->email);
                return response()->json([
                    'status'     => false,
                    'error_code' => 'EMAIL_ALREADY_EXISTS',
                    'message'    => 'هذا البريد الإلكتروني مسجل لدينا بالفعل. هل نسيت كلمة المرور؟'
                ], 400);
            }

            // 2. استدعاء الخدمة لإرسال الكود
            // هذه الدالة داخل السيرفس ستقوم بتوليد الكود وإرساله عبر EmailService
            $this->registrationService->requestNewOtp($request->email);
            
            Log::info("Parent: OTP successfully sent and processed for: " . $request->email);
            
            return response()->json([
                'status'  => true,
                'message' => 'تم إرسال كود التحقق بنجاح إلى بريدك الإلكتروني.'
            ], 200);

        } catch (Exception $e) {
            // تسجيل الخطأ الفعلي مع مسار التنفيذ
            Log::error("Parent Send OTP Execution Error [{$request->email}]: " . $e->getMessage());
            
            return response()->json([
                'status'     => false,
                'error_code' => 'OTP_SEND_FAILED',
                'message'    => 'حدث خطأ أثناء محاولة إرسال رمز التحقق، يرجى المحاولة لاحقاً.'
            ], 500);
        }
    }

    public function register(ParentRegisterRequest $request): JsonResponse
    {
        Log::info("Parent: Registration attempt started for: " . $request->email);

        try {
            $data = $request->validated();

if ($request->hasFile('avatar')) {
    $file = $request->file('avatar');
    $filename = 'parent_avatar_' . time() . '.' . $file->getClientOriginalExtension();
    $file->move(public_path('uploads/parents/avatars'), $filename);
    $data['avatar_url'] = 'uploads/parents/avatars/' . $filename;
}

$user = $this->registrationService->registerParent($data);
            $token = $user->createToken('parent_token')->plainTextToken;

            Log::info("Parent: Account created successfully for user ID: " . $user->id);
            
            return response()->json([
                'status'  => true,
                'message' => 'تم إنشاء حساب ولي الأمر بنجاح.',
                'data'    => new ParentResource($user),
                'token'   => $token
            ], 201);

        } catch (Exception $e) {
            Log::error("Parent Registration Error: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    public function getProfile(Request $request): JsonResponse
    {
        try {
            $user = $this->registrationService->getParentProfile($request->user()->id);
            Log::info("Parent: Profile fetched for user ID: " . $request->user()->id);
            
            return response()->json([
                'status'  => true,
                'message' => 'تم جلب البيانات بنجاح.',
                'data'    => new ParentResource($user)
            ], 200);
        } catch (Exception $e) {
            Log::error("Parent Get Profile Error for User {$request->user()->id}: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    public function updateProfile(UpdateParentProfileRequest $request): JsonResponse
    {
        Log::info("Parent: Update profile initiated for user ID: " . $request->user()->id);

        try {
            $data = $request->validated();

            if ($request->hasFile('avatar')) {
                $file = $request->file('avatar');
                $filename = 'parent_avatar_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('uploads/parents/avatars'), $filename);
                $data['avatar_url'] = 'uploads/parents/avatars/' . $filename;
            }

            $user = $this->registrationService->updateParentProfile($request->user()->id, $data);
            
            $isEmailPending = isset($user->email_change_pending) && $user->email_change_pending === true;

            $message = $isEmailPending
                ? 'تم تحديث الملف الشخصي بنجاح. يرجى تأكيد بريدك الإلكتروني الجديد.'
                : 'تم تحديث الملف الشخصي بنجاح.';

            $emailVerificationData = $isEmailPending ? [
                'status'    => 'pending',
                'new_email' => $user->pending_new_email ?? null,
            ] : null;

            Log::info("Parent: Profile updated successfully for user ID: " . $user->id);
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => [
                    'profile'            => new ParentResource($user),
                    'email_verification' => $emailVerificationData,
                ]
            ], 200);
            
        } catch (Exception $e) {
            Log::error("Parent Update Profile Error for User {$request->user()->id}: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    public function approveEmailChange(int $id): JsonResponse
    {
        Log::info("Parent: Attempting to approve email change for user ID: " . $id);

        try {
            $this->registrationService->approveEmailChange($id);
            Log::info("Parent: Email change approved successfully for user ID: " . $id);

            return response()->json([
                'success'       => true,
                'email_changed' => true,
                'message'       => 'تم تأكيد وتحديث البريد الإلكتروني بنجاح.'
            ], 200);

        } catch (Exception $e) {
            Log::error("Parent Approve Email Change Error for ID {$id}: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * دالة فحص حالة تغيير البريد الإلكتروني للفرونت إند (Email Change Status Check)
     * GET /api/parent/profile/email-change/status
     */
    public function checkEmailChangeStatus(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $statusKey = "parent_email_change_status_{$userId}";
            $pendingKey = "parent_email_change_{$userId}";

            $status = \Illuminate\Support\Facades\Cache::get($statusKey);

            if (!$status) {
                if (\Illuminate\Support\Facades\Cache::has($pendingKey)) {
                    $status = 'pending';
                } else {
                    $status = 'expired';
                }
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'status' => $status
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Parent Check Email Status Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * دالة إلغاء تغيير البريد الإلكتروني (Cancel Email Change)
     * POST /api/parent/profile/email-change/cancel
     */
    public function cancelEmailChange(Request $request): JsonResponse
    {
        try {
            $this->registrationService->cancelEmailChange($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Email change cancelled.'
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * دالة إعادة إرسال رسالة التحقق للبريد الجديد (Resend Email Verification)
     * POST /api/parent/profile/email-change/resend
     */
    public function resendEmailChange(Request $request): JsonResponse
    {
        try {
            $this->registrationService->resendEmailChange($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Verification email resent successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function rejectEmailChange(int $id): JsonResponse
    {
        Log::info("Parent: Attempting to reject email change for user ID: " . $id);

        try {
            $this->registrationService->rejectEmailChange($id);
            Log::info("Parent: Email change rejected successfully for user ID: " . $id);

            return response()->json([
                'status'        => true,
                'email_changed' => false,
                'message'       => 'تم إلغاء طلب تعديل البريد الإلكتروني بنجاح.'
            ], 200);

        } catch (Exception $e) {
            Log::error("Parent Reject Email Change Error for ID {$id}: " . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    private function errorResponse(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'status'     => false,
            'error_code' => 'API_EXECUTION_ERROR',
            'message'    => $message
        ], $code);
    }
}