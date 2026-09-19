<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * واجهة الإدارة لتنبيهات محرك تصنيف تعليقات الأولياء + إعادة تأهيل السائق يدوياً.
 */
class DriverAiPolicyController extends Controller
{
    public function alertsIndex(Request $request): JsonResponse
    {
        $query = AdminAlert::with(['driver.user'])
            ->where(function ($q) {
                $q->where('alert_type', 'like', 'ai_%')
                  ->orWhereIn('alert_type', ['safety_hazard_suspend', 'ai_critical', 'ai_warning', 'ai_moderate_violation', 'ai_formal_warning', 'ai_admin_review_required']);
            })
            ->latest();

        if ($request->filled('risk_level')) {
            $query->where('risk_level', strtoupper((string) $request->query('risk_level')));
        }

        if ($request->has('is_resolved') && $request->query('is_resolved') !== null && $request->query('is_resolved') !== '') {
            $query->where('is_resolved', filter_var($request->query('is_resolved'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('driver_id')) {
            $query->where('driver_id', (int) $request->query('driver_id'));
        }

        $perPage = (int) $request->query('per_page', 15);
        $alerts  = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => $alerts->items(),
            'meta'   => [
                'current_page' => $alerts->currentPage(),
                'last_page'    => $alerts->lastPage(),
                'per_page'     => $alerts->perPage(),
                'total'        => $alerts->total(),
            ],
        ]);
    }

    public function alertsShow(int $id): JsonResponse
    {
        $alert = AdminAlert::with(['driver.user'])->find($id);
        if (!$alert) {
            return response()->json([
                'status'  => false,
                'message' => 'عذراً، التنبيه المطلوب غير موجود.',
            ], 404);
        }

        $driver = $alert->driver;
        $user   = $driver?->user;
        $meta   = is_array($alert->metadata) ? $alert->metadata : [];

        return response()->json([
            'status' => true,
            'data'   => [
                'alert_id'    => $alert->id,
                'created_at'  => optional($alert->created_at)->format('Y-m-d H:i:s'),
                'is_resolved' => (bool) $alert->is_resolved,
                'alert_type'  => $alert->alert_type,
                'risk_level'  => $alert->risk_level,
                'title'       => $alert->title,
                'message'     => $alert->message,
                'driver'      => [
                    'id'             => $driver?->id,
                    'name'           => $user?->full_name ?? ('سائق #' . $driver?->id),
                    'phone'          => $user?->phone_number ?? '',
                    'current_rating' => (float) ($driver->rating_avg ?? 0),
                    'is_trusted'     => (bool) ($user->is_trusted ?? false),
                    'status'         => $driver?->status,
                ],
                'metadata'    => $meta,
            ],
        ]);
    }

    public function resolveAlert(int $id): JsonResponse
    {
        $alert = AdminAlert::find($id);
        if (!$alert) {
            return response()->json([
                'status'  => false,
                'message' => 'التنبيه غير موجود.',
            ], 404);
        }

        $alert->update([
            'is_resolved' => true,
            'is_read'     => true,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'تمت تسوية التنبيه.',
            'data'    => [
                'alert_id'    => $alert->id,
                'is_resolved' => true,
            ],
        ]);
    }

    /**
     * إعادة تأهيل السائق واستعادته:
     *  - يصفّر نافذة 30 يوم (ai_last_reset_at = now).
     *  - يرفع أي حجب وقائي مؤقت (suspended_until = null).
     *  - يصفّر عداد التحذيرات (active_warnings_count = 0).
     *  - يعيد تفعيل حساب السائق وموثوقيته (is_trusted = true).
     *  - يحسم كل تنبيهات AI المفتوحة لهذا السائق.
     */
    public function resetDriver(int $driverId): JsonResponse
    {
        try {
            return DB::transaction(function () use ($driverId) {
                $driver = Driver::with('user')->find($driverId);
                if (!$driver) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'السائق غير موجود.',
                    ], 404);
                }

                $driver->update([
                    'ai_last_reset_at'      => now(),
                    'suspended_until'       => null,
                    'active_warnings_count' => 0,
                ]);

                if ($driver->user && !(bool) $driver->user->is_trusted) {
                    $driver->user->update(['is_trusted' => true]);
                }

                AdminAlert::where('driver_id', $driver->id)
                    ->where('is_resolved', false)
                    ->where(function ($q) {
                        $q->where('alert_type', 'like', 'ai_%')
                          ->orWhereIn('alert_type', ['safety_hazard_suspend', 'ai_critical', 'ai_warning']);
                    })
                    ->update(['is_resolved' => true, 'is_read' => true]);

                Log::info('AI policy: driver reset and restored by admin', ['driver_id' => $driver->id]);

                return response()->json([
                    'status'  => true,
                    'message' => 'تمت إعادة تأهيل السائق ورفع الحجب وتصفير التحذيرات بنجاح.',
                    'data'    => [
                        'driver_id'             => $driver->id,
                        'rating_avg'            => (float) $driver->rating_avg,
                        'suspended_until'       => null,
                        'active_warnings_count' => 0,
                        'is_trusted'            => (bool) $driver->user?->fresh()->is_trusted,
                    ],
                ]);
            });
        } catch (Exception $e) {
            Log::error('AI reset error', ['driver_id' => $driverId, 'error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء إعادة تأهيل السائق.',
            ], 500);
        }
    }

    /**
     * سجل تدقيق قرارات الذكاء الاصطناعي (AI Decision Audits)
     * GET /api/admin/ai-audits
     */
    public function auditsIndex(Request $request): JsonResponse
    {
        $query = \App\Models\Shared\AiDecisionAudit::with(['driver.user', 'review'])
            ->latest();

        if ($request->filled('driver_id')) {
            $query->where('driver_id', (int) $request->query('driver_id'));
        }

        if ($request->filled('decision_code')) {
            $query->where('decision_code', (int) $request->query('decision_code'));
        }

        $perPage = (int) $request->query('per_page', 15);
        $audits  = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => $audits->items(),
            'meta'   => [
                'current_page' => $audits->currentPage(),
                'last_page'    => $audits->lastPage(),
                'per_page'     => $audits->perPage(),
                'total'        => $audits->total(),
            ],
        ]);
    }

    /**
     * تفاصيل سجل تدقيق قرار محدد
     * GET /api/admin/ai-audits/{id}
     */
    public function auditsShow(int $id): JsonResponse
    {
        $audit = \App\Models\Shared\AiDecisionAudit::with(['driver.user', 'review.parent'])->find($id);
        if (!$audit) {
            return response()->json([
                'status'  => false,
                'message' => 'سجل التدقيق المطلوب غير موجود.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $audit,
        ]);
    }
}
