<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Shared\TermsVersionResource;
use App\Models\Shared\TermsVersion;
use App\Services\Shared\TermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TermsController extends Controller
{
    protected TermsService $termsService;

    public function __construct(TermsService $termsService)
    {
        $this->termsService = $termsService;
    }

    /**
     * GET /api/terms/current?audience=parent|driver
     * دالة العرض العامة: تُستدعى من شاشة التسجيل قبل أي مصادقة.
     */
    public function current(Request $request): JsonResponse
    {
        $audience = $request->query('audience');

        if (!in_array($audience, [TermsVersion::AUDIENCE_PARENT, TermsVersion::AUDIENCE_DRIVER], true)) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تحديد audience صالح (parent أو driver).',
            ], 422);
        }

        $version = $this->termsService->getCurrentPublished($audience);

        if (!$version) {
            return response()->json([
                'success' => false,
                'message' => 'لا توجد نسخة منشورة من الشروط والأحكام حالياً.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الشروط والأحكام الحالية بنجاح.',
            'data'    => new TermsVersionResource($version),
        ], 200);
    }

    /**
     * GET /api/terms/{id}
     * عرض نسخة محدَّدة بالرقم (لأرشيف الشروط القديمة مثلاً، أو لمراجعة نزاع).
     */
    public function show(int $id): JsonResponse
    {
        try {
            $version = $this->termsService->getVersion($id);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'النسخة المطلوبة غير موجودة.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب نسخة الشروط بنجاح.',
            'data'    => new TermsVersionResource($version),
        ], 200);
    }

    /**
     * GET /api/terms/status  (auth:sanctum)
     * يستدعيها الفرونت في أي لحظة يقرر فيها هو عرض شاشة الشروط (بعد إنشاء
     * حساب ولي الأمر مباشرة، أو بعد موافقة الأدمن على السائق) ليعرف هل
     * المستخدم الحالي وافق على النسخة السارية أم لا، بمعزل تام عن التسجيل.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $this->resolveUserRole($user);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر تحديد دور المستخدم (ولي أمر / سائق).',
            ], 422);
        }

        $current = $this->termsService->getCurrentPublished($role);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب حالة الموافقة على الشروط بنجاح.',
            'data'    => [
                'has_accepted'      => $current ? $this->termsService->hasAccepted($user, $role) : true,
                'current_version'   => $current ? new TermsVersionResource($current) : null,
            ],
        ], 200);
    }

    /**
     * POST /api/terms/accept  (auth:sanctum)
     * تسجيل موافقة المستخدم الحالي على النسخة السارية لدوره. هذه هي نقطة
     * الدخول التي تُستدعى أيضاً عند نشر نسخة جديدة فتحجب middleware
     * EnsureTermsAccepted المستخدم حتى يُعيد الموافقة عليها.
     */
    public function accept(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $this->resolveUserRole($user);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر تحديد دور المستخدم (ولي أمر / سائق) لتسجيل الموافقة على الشروط.',
            ], 422);
        }

        try {
            $acceptance = $this->termsService->recordAcceptance(
                $user,
                $role,
                $request->ip(),
                (string) $request->userAgent()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل موافقتك على الشروط والأحكام بنجاح.',
                'data'    => [
                    'terms_version_id' => $acceptance->terms_version_id,
                    'accepted_at'      => $acceptance->accepted_at,
                ],
            ], 200);
        } catch (Throwable $e) {
            Log::warning("Terms acceptance failed for user #{$user->id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'تعذر تسجيل الموافقة على الشروط.',
            ], 400);
        }
    }

    private function resolveUserRole($user): ?string
    {
        if (!$user) {
            return null;
        }

        return match ((int) ($user->role_id ?? 0)) {
            3       => TermsVersion::AUDIENCE_PARENT,
            4       => TermsVersion::AUDIENCE_DRIVER,
            default => null,
        };
    }
}
