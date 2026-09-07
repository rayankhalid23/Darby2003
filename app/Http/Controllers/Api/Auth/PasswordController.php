<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Shared\OtpService;
use App\Services\Shared\EmailService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PasswordController extends Controller
{
    protected $otpService;
    protected $emailService;

    public function __construct(OtpService $otpService, EmailService $emailService)
    {
        $this->otpService = $otpService;
        $this->emailService = $emailService;
    }

    /**
     * 1. دالة إرسال كود التحقق (Send OTP)
     */
    public function sendResetOtp(Request $request)
    {
        // استخدام Validator يدوي لضمان تسجيل أخطاء الـ Validation في الـ Log قبل الرد على العميل
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            Log::warning("⚠️ [Send OTP Validation Failed] Inputs: " . json_encode($request->all()) . " | Errors: " . json_encode($validator->errors()->all()));
            return response()->json([
                'status'  => false, 
                'message' => 'البريد الإلكتروني المدخل غير صالح.',
                'errors'  => $validator->errors()
            ], 422);
        }

        Log::info("📨 [Send OTP Attempt] Email: {$request->email}");

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning("⚠️ [Send OTP Failed] Email not registered: {$request->email}");
                return response()->json([
                    'status'  => false, 
                    'message' => 'البريد الإلكتروني المدخل غير مسجل لدينا.'
                ], 404);
            }

            // توليد الكود وحفظه
            $otpResult = $this->otpService->generate($user->email, 'RESET_PASSWORD');
            if (!$otpResult['success']) {
                return response()->json([
                    'status'  => false,
                    'message' => $otpResult['message']
                ], 429);
            }
            $code = $otpResult['code'];
            Log::info("🔑 [OTP Generated] Email: {$user->email} | Code generated successfully.");

            // إرسال الكود عبر البريد
            $this->emailService->sendOtp(
                $user->email, 
                $user->full_name, 
                $code, 
                $user->role_id, 
                $user->gender ?? null,
                'RESET_PASSWORD'
            );

            Log::info("✅ [OTP Sent Successfully] Email: {$user->email} via EmailService.");

            return response()->json([
                'status'  => true, 
                'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني بنجاح. يرجى التحقق من صندوق الوارد.'
            ], 200);

        } catch (\Exception $e) {
            Log::error("🔥 [Fatal Error In Send OTP] Message: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return response()->json([
                'status'  => false, 
                'message' => 'حدث عطل تقني غير متوقع أثناء إرسال كود التحقق. يرجى المحاولة لاحقاً.'
            ], 500);
        }
    }

    /**
     * 2. دالة التحقق من صحة كود الـ OTP (Verify OTP)
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code'  => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            Log::warning("⚠️ [Verify OTP Validation Failed] Inputs: " . json_encode($request->except('code')) . " | Errors: " . json_encode($validator->errors()->all()));
            return response()->json([
                'status'  => false, 
                'message' => 'البيانات المدخلة غير صحيحة.',
                'errors'  => $validator->errors()
            ], 422);
        }

        Log::info("📨 [Verify OTP Attempt] Email: {$request->email} | Code Entered: {$request->code}");

        try {
            $verify = $this->otpService->verify($request->email, $request->code, 'RESET_PASSWORD');
            
            if (!$verify['success']) {
                $reasonCode = $verify['reason'] ?? 'WRONG_CODE_OR_EXPIRED';
                
                Log::warning("❌ [Verification Failed] Email: {$request->email} | Reason Code: {$reasonCode} | Message: {$verify['message']}");
                
                return response()->json([
                    'status'     => false,
                    'error_code' => $reasonCode,
                    'message'    => $verify['message'] ?? 'رمز التحقق غير صحيح أو انتهت صلاحيته.'
                ], 400);
            }

            Log::info("✅ [Verification Success] Email: {$request->email} has passed OTP check successfully.");

            // منح إذن مؤقت (15 دقيقة) لتعيين كلمة مرور جديدة، لإغلاق ثغرة تجاوز الـ OTP.
            // بدون هذا الإذن كان بإمكان أي شخص استدعاء /reset مباشرة دون التحقق من الرمز.
            \Illuminate\Support\Facades\Cache::put(
                'password_reset_verified:' . strtolower($request->email),
                true,
                now()->addMinutes(15)
            );

            return response()->json([
                'status'  => true,
                'message' => 'تم التحقق من رمز التأكيد بنجاح، يمكنك الآن تعيين كلمة مرور جديدة.'
            ], 200);

        } catch (\Exception $e) {
            Log::error("🔥 [Fatal Error In Verify OTP] Message: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return response()->json([
                'status'  => false, 
                'message' => 'حدث عطل تقني أثناء عملية التحقق من الرمز.'
            ], 500);
        }
    }

    /**
     * 3. دالة تغيير كلمة المرور (تم تعديل اسم الحقل ليتوافق مع الـ Login)
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|min:6|confirmed'
        ]);

        if ($validator->fails()) {
            Log::warning("⚠️ [Reset Password Validation Failed] Email: {$request->email} | Errors: " . json_encode($validator->errors()->all()));
            return response()->json([
                'status'  => false, 
                'message' => 'كلمة المرور الجديدة غير مطابقة للشروط المعتمدة.',
                'errors'  => $validator->errors()
            ], 422);
        }

        Log::info("🔄 [Reset Password Attempt] Email: {$request->email}");

        try {
            // إلزام التحقق المسبق من الـ OTP: يجب أن يكون هناك إذن مؤقت صالح من verify-otp.
            // pull تجعل الإذن أحادي الاستخدام (يُحذف فور استعماله) لمنع إعادة الاستخدام.
            $resetKey = 'password_reset_verified:' . strtolower($request->email);
            if (! \Illuminate\Support\Facades\Cache::pull($resetKey)) {
                Log::warning("⛔ [Reset Password Blocked] No verified OTP for email: {$request->email}");
                return response()->json([
                    'status'     => false,
                    'error_code' => 'OTP_NOT_VERIFIED',
                    'message'    => 'يرجى التحقق من رمز OTP أولاً قبل تعيين كلمة مرور جديدة.'
                ], 403);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning("⚠️ [Reset Password Failed] User not found for email: {$request->email}");
                return response()->json([
                    'status'  => false, 
                    'message' => 'فشلت العملية، الحساب المرتبط بهذا البريد غير موجود.'
                ], 404);
            }

            $user->update([
                'password'      => Hash::make($request->password),
                'password_hash' => Hash::make($request->password),
            ]);

            Log::info("🔒 [Reset Password Success] Password updated successfully for User ID: {$user->id} | Email: {$user->email}");

            return response()->json([
                'status'  => true, 
                'message' => 'تم تحديث كلمة المرور بنجاح، يمكنك الآن الانتقال لتسجيل الدخول بالحساب.'
            ], 200);

        } catch (\Exception $e) {
            Log::error("🔥 [Fatal Error In Reset Password] Message: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return response()->json([
                'status'  => false, 
                'message' => 'حدث عطل تقني أثناء تحديث كلمة المرور، يرجى إعادة المحاولة لاحقاً.'
            ], 500);
        }
    }
}