<?php

namespace App\Services\Shared;

use App\Models\Shared\OtpCode;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class OtpService
{
    /**
     * توليد كود تحقق جديد
     */
    public function generate(string $email, string $purpose): array
    {
        $email = trim(mb_strtolower($email));

        // 1. منع الطلب المتكرر قبل مرور 60 ثانية
        $lastOtp = OtpCode::where('email', $email)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        if ($lastOtp) {
            $secondsPassed = Carbon::parse($lastOtp->created_at)->diffInSeconds(now());
            if ($secondsPassed < 60) {
                $secondsLeft = 60 - $secondsPassed;
                return [
                    'success' => false,
                    'message' => "انتظر {$secondsLeft} ثانية قبل طلب رمز جديد."
                ];
            }
        }

        $code = strval(random_int(100000, 999999));

        // 2. إلغاء صلاحية أي أكواد نشطة سابقة
        OtpCode::where('email', $email)
            ->where('purpose', $purpose)
            ->where('is_used', 0)
            ->update(['is_used' => 1]);

        // 3. إنشاء سجل جديد بصلاحية 5 دقائق
        OtpCode::create([
            'email'      => $email,
            'purpose'    => $purpose,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
            'is_used'    => 0,
            'attempts'   => 0
        ]);

        return [
            'success' => true,
            'code'    => $code
        ];
    }

    /**
     * التحقق من كود الـ OTP
     */
    public function verify(string $email, string $code, string $purpose, bool $markAsUsed = true): array
    {
        $email = trim(mb_strtolower($email));
        $code  = trim((string) $code);

        // 1. جلب الرمز النشط حالياً
        $activeOtp = OtpCode::where('email', $email)
            ->where('purpose', $purpose)
            ->where('is_used', 0)
            ->latest()
            ->first();

        if (!$activeOtp) {
            return ['success' => false, 'message' => 'لا يوجد رمز نشط، يرجى طلب رمز جديد.'];
        }

        // 2. التحقق من انتهاء الصلاحية (مقارنة مضبوطة التوقيت)
        $expiresAt = Carbon::parse($activeOtp->expires_at)->setTimezone(config('app.timezone'));
        if ($expiresAt->isPast()) {
            $activeOtp->update(['is_used' => 1]);
            return ['success' => false, 'message' => 'الرمز منتهي الصلاحية، يرجى طلب رمز جديد.'];
        }

        // 3. التحقق من تجاوز المحاولات الفاشلة
        if ($activeOtp->attempts >= 3) {
            $activeOtp->update(['is_used' => 1]);
            return ['success' => false, 'message' => 'تم تجاوز عدد المحاولات المسموح بها.'];
        }

        // 4. مطابقة الكود مع الرمز النشط
        if (Hash::check($code, $activeOtp->code_hash)) {
            if ($markAsUsed) {
                $activeOtp->update(['is_used' => 1]);
            }
            return ['success' => true, 'message' => 'تم التحقق بنجاح.'];
        }

        // 5. فحص ما إذا كان المستخدم قد أدخل الرمز القديم الملغى
        $oldOtp = OtpCode::where('email', $email)
            ->where('purpose', $purpose)
            ->where('is_used', 1)
            ->latest()
            ->first();

        if ($oldOtp && Hash::check($code, $oldOtp->code_hash)) {
            return [
                'success' => false,
                'message' => 'تم إلغاء صلاحية هذا الرمز لطلب رمز جديد، استخدم الرمز الأخير.'
            ];
        }

        // 6. إذا كان الكود خطأ تماماً: زيادة المحاولات على الرمز النشط
        $activeOtp->increment('attempts');
        $remainingAttempts = 3 - $activeOtp->attempts;

        if ($remainingAttempts <= 0) {
            $activeOtp->update(['is_used' => 1]);
            return ['success' => false, 'message' => 'تم تجاوز عدد المحاولات المسموح بها.'];
        }

        return [
            'success' => false, 
            'message' => "رمز غير صحيح. متبقي لديك {$remainingAttempts} محاولات."
        ];
    }
}