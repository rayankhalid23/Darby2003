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
            ->whereIn('alert_type', ['ai_critical', 'ai_warning'])
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
     * إعادة تأهيل السائق:
     *  - يصفّر نافذة 30 يوم (ai_last_reset_at = now).
     *  - إن كان مخفياً بسبب قرار AI (users.is_trusted=false) يُعاد is_trusted=true.
     *  - يحسم كل تنبيهات AI المفتوحة لهذا السائق.
     *  - لا يمس التقييم rating_avg.
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

                $driver->update(['ai_last_reset_at' => now()]);

                if ($driver->user && !(bool) $driver->user->is_trusted) {
                    $driver->user->update(['is_trusted' => true]);
                }

                AdminAlert::where('driver_id', $driver->id)
                    ->whereIn('alert_type', ['ai_critical', 'ai_warning'])
                    ->where('is_resolved', false)
                    ->update(['is_resolved' => true, 'is_read' => true]);

                Log::info('AI policy: driver reset by admin', ['driver_id' => $driver->id]);

                return response()->json([
                    'status'  => true,
                    'message' => 'تمت إعادة تأهيل السائق وتصفير النافذة الزمنية للتصنيف.',
                    'data'    => [
                        'driver_id'        => $driver->id,
                        'ai_last_reset_at' => optional($driver->fresh()->ai_last_reset_at)->toIso8601String(),
                        'is_trusted'       => (bool) $driver->user?->fresh()->is_trusted,
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
}
