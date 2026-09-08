<?php

namespace App\Http\Middleware;

use App\Models\Shared\TermsVersion;
use App\Services\Shared\TermsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يحجب أي إجراء محمي بها حتى يوافق المستخدم صراحة على أحدث نسخة سارية من
 * الشروط والأحكام الخاصة بدوره. هذا هو التأكد الفعلي والمُنفَّذ في الكود من
 * أن ولي الأمر أو السائق وافق فعلاً — وليس مجرد افتراض أن التسجيل يكفي.
 *
 * يُفعَّل فقط على المسارات الحساسة (مثل إنشاء/قبول طلب اشتراك) وليس عاماً
 * على كل شيء، حتى لا يمنع المستخدم من مجرد تصفح حسابه أو تعديل ملفه الشخصي
 * ريثما يوافق. الاستخدام: Route::middleware('terms.accepted').
 */
class EnsureTermsAccepted
{
    protected TermsService $termsService;

    public function __construct(TermsService $termsService)
    {
        $this->termsService = $termsService;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $role = match ((int) ($user->role_id ?? 0)) {
            3       => TermsVersion::AUDIENCE_PARENT,
            4       => TermsVersion::AUDIENCE_DRIVER,
            default => null,
        };

        // مستخدم بدور غير خاضع لهذا الميثاق (أدمن مثلاً) يمر بلا فحص
        if (!$role) {
            return $next($request);
        }

        if (!$this->termsService->hasAccepted($user, $role)) {
            $current = $this->termsService->getCurrentPublished($role);

            return response()->json([
                'success'          => false,
                'error_code'       => 'TERMS_NOT_ACCEPTED',
                'message'          => 'يجب الموافقة على الشروط والأحكام السارية قبل متابعة هذا الإجراء.',
                'terms_version_id' => $current?->id,
            ], 451); // 451 Unavailable For Legal Reasons
        }

        return $next($request);
    }
}
