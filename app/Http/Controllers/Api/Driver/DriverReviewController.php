<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver\Driver;
use App\Models\Shared\DriverReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class DriverReviewController extends Controller
{
    /**
     * جلب تقييمات السائق وملاحظات أولياء الأمور الخاصة به
     * GET /api/v1/driver/reviews
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user() ?? auth()->user();
            $driver = $user ? ($user->driver ?? Driver::where('user_id', $user->id)->first()) : null;

            if (!$driver) {
                return response()->json([
                    'status'  => false,
                    'message' => 'ملف السائق غير موجود.',
                ], 404);
            }

            $perPage = (int) $request->query('per_page', 10);

            $reviews = DriverReview::with('parent')
                ->where('driver_id', $driver->id)
                ->latest()
                ->paginate($perPage);

            $summary = [
                'rating_avg'            => round((float) ($driver->rating_avg ?? 5.0), 2),
                'total_reviews'         => DriverReview::where('driver_id', $driver->id)->count(),
                'active_warnings_count' => (int) ($driver->active_warnings_count ?? 0),
                'is_suspended'          => (bool) ($driver->is_suspended ?? false),
                'suspended_until'       => $driver->suspended_until ? $driver->suspended_until->toIso8601String() : null,
                'positive_count'        => DriverReview::where('driver_id', $driver->id)->where('rating', '>=', 4)->count(),
                'negative_count'        => DriverReview::where('driver_id', $driver->id)->where('rating', '<=', 2)->count(),
            ];

            return response()->json([
                'status'  => true,
                'message' => 'تم جلب التقييمات بنجاح.',
                'summary' => $summary,
                'data'    => $reviews->getCollection()->map(function ($r) {
                    $parentUser = $r->parent instanceof \App\Models\User ? $r->parent : optional($r->parent)->user;
                    return [
                        'id'         => $r->id,
                        'rating'     => (int) $r->rating,
                        'comment'    => $r->comment,
                        'parent_name'=> $parentUser->full_name ?? 'ولي أمر',
                        'created_at' => optional($r->created_at)->format('Y-m-d H:i:s'),
                    ];
                }),
                'meta'    => [
                    'current_page' => $reviews->currentPage(),
                    'last_page'    => $reviews->lastPage(),
                    'per_page'     => $reviews->perPage(),
                    'total'        => $reviews->total(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء جلب التقييمات: ' . $e->getMessage(),
            ], 500);
        }
    }
}
