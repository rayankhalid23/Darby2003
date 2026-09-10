<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\ActiveSubscription;
use App\Services\Trip\RouteRecommendationService;
use App\Services\Trip\RouteModuleException;
use App\Services\Trip\MasterRouteStopSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DriverRouteController extends Controller
{
    protected RouteRecommendationService $recommendationService;
    protected MasterRouteStopSyncService $routeStopSyncService;

    public function __construct(
        RouteRecommendationService $recommendationService,
        MasterRouteStopSyncService $routeStopSyncService
    ) {
        $this->recommendationService = $recommendationService;
        $this->routeStopSyncService  = $routeStopSyncService;
    }

    /**
     * Helper لإرجاع الاستجابة الموحدة للأخطاء مع Error Code
     */
    protected function errorResponse(string $message, string $code = 'INVALID_ACTION', int $httpCode = 422): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
        ], $httpCode);
    }

    /**
     * 0️⃣ فحص التزام وتطور إسناد الأطفال المقبولين (Enforcement Flow Check)
     * GET /api/v1/driver/home-status
     */
    public function checkPendingAssignments(): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            if (!$driver) {
                return $this->errorResponse('بيانات السائق غير مقترنة بالحساب.', 'DRIVER_NOT_FOUND', 403);
            }

            $pendingSubs = ActiveSubscription::forDriver($driver->id)
                ->whereNull('route_id')
                ->where('status', '!=', 'cancelled')
                ->with(['child', 'school', 'subscriptionRequest'])
                ->get();

            $pendingCount = $pendingSubs->count();
            $hasPending = $pendingCount > 0;
            $requestId = $pendingSubs->first()?->subscription_request_id ?? null;

            $pendingData = $pendingSubs->map(function ($sub) {
                return [
                    'subscription_id' => (int) $sub->id,
                    'child' => [
                        'id'          => (int) $sub->child_id,
                        'name'        => $sub->child->full_name ?? $sub->child->name ?? 'طفل',
                        'school'      => optional($sub->school)->name ?? 'المدرسة',
                        'photo'       => $sub->child->photo_url ?? null,
                    ]
                ];
            })->values();

            return response()->json([
                'status'                  => 'success',
                'has_pending_assignments' => $hasPending,
                'pending_count'           => $pendingCount,
                'request_id'              => $requestId,
                'pending_subscriptions'   => $pendingData,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error in checkPendingAssignments: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 'SERVER_ERROR', 500);
        }
    }

    /**
     * 1️⃣ GET /api/v1/driver/routes
     * جلب جميع المسارات الخاصة بالسائق
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            if (!$driver) {
                return $this->errorResponse('بيانات السائق غير مرتبطة بهذا الحساب.', 'DRIVER_NOT_FOUND', 403);
            }

            $routes = RouteModel::where('driver_id', $driver->id)
                ->latest()
                ->get();

            $transformed = $routes->map(function ($route) {
                return $this->recommendationService->calculateRouteMetrics($route);
            })->values();

            return response()->json([
                'status' => 'success',
                'routes' => $transformed
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error in DriverRouteController@index: ' . $e->getMessage());
            return $this->errorResponse('تعذر جلب المسارات حالياً.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * 2️⃣ POST /api/v1/driver/routes
     * إنشاء Route جديد
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'      => 'required|string|max:50',
            'trip_type' => 'required|in:morning,evening,afternoon',
        ]);

        try {
            $user = Auth::user();
            $driver = $user?->driver;

            if (!$driver) {
                return $this->errorResponse('السائق غير موجود.', 'DRIVER_NOT_FOUND', 403);
            }

            $vehicle = $driver->vehicle;
            $tripTypeLower = strtolower($request->trip_type);
            $dbTripType = (in_array($tripTypeLower, ['morning', 'صباح']) ? 'Morning' : 'Afternoon');

            $route = RouteModel::create([
                'driver_id'          => $driver->id,
                'vehicle_id'         => $vehicle?->id ?? 1,
                'route_name'         => $request->name,
                'route_type'         => $dbTripType,
                'start_time'         => '07:15:00',
                'status'             => 'Active',
                'estimated_duration' => 45,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنشاء المسار.',
                'route'   => [
                    'id' => (int) $route->id
                ]
            ], 201);

        } catch (Throwable $e) {
            Log::error('Error in DriverRouteController@store: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 'CREATE_ROUTE_FAILED', 422);
        }
    }

    /**
     * 3️⃣ GET /api/v1/driver/routes/{routeId}
     * تفاصيل Route كاملة مع إحداثيات المنازل والمدارس والـ Lock Status
     */
    public function show($routeId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $route = RouteModel::where('id', $routeId)
                ->where('driver_id', $driver->id)
                ->with(['activeSubscriptions.child.school', 'activeSubscriptions.school'])
                ->firstOrFail();

            $metrics = $this->recommendationService->calculateRouteMetrics($route);

            $subscriptionsData = $route->activeSubscriptions
                ->where('status', '!=', 'cancelled')
                ->sortBy('sort_order')
                ->values()
                ->map(function ($sub, $index) {
                    // يحاول جلب المدرسة من subscription أولاً، ثم من child->school
                    $schoolModel = $sub->school ?? $sub->child?->school;

                    return [
                        'id'               => (int) $sub->id,
                        'subscription_id'  => (int) $sub->id,
                        'child_id'         => (int) $sub->child_id,
                        'child_name'       => $sub->child->full_name ?? $sub->child->name ?? 'طفل',
                        'route_status'     => strtolower($sub->status ?? 'active'),
                        'pickup_order'     => ($sub->sort_order > 0) ? (int) $sub->sort_order : ($index + 1),
                        'estimated_pickup' => $sub->pickup_time ?? '07:28',
                        'needs_review'     => ($sub->status === 'needs_review'),
                        'review_reason'    => ($sub->status === 'needs_review') ? ($sub->review_reason ?? 'تم تغيير المدرسة أو العنوان') : null,
                        'pickup' => [
                            'label'     => $sub->pickup_label ?? 'حي الأندلس',
                            'latitude'  => (float) ($sub->pickup_lat ?? 32.887201),
                            'longitude' => (float) ($sub->pickup_lng ?? 13.191345),
                        ],
                        'school' => [
                            'id'        => (int) ($schoolModel?->id ?? 0),
                            'name'      => $schoolModel?->name ?? 'المدرسة',
                            'latitude'  => (float) ($schoolModel?->lat ?? $schoolModel?->latitude ?? 32.901000),
                            'longitude' => (float) ($schoolModel?->lng ?? $schoolModel?->longitude ?? 13.205000),
                        ],
                    ];
                })->values();

            $metrics['subscriptions'] = $subscriptionsData;
            $metrics['shift_slot'] = $route->shift_slot;
            $metrics['stops'] = \App\Models\Shared\RouteStop::where('route_id', $route->id)
                ->orderBy('sequence_order')
                ->get()
                ->map(fn($s) => [
                    'id'             => (int) $s->id,
                    'stop_type'      => $s->stop_type,
                    'child_id'       => $s->child_id ? (int) $s->child_id : null,
                    'school_id'      => $s->school_id ? (int) $s->school_id : null,
                    'label'          => $s->label,
                    'latitude'       => (float) $s->lat,
                    'longitude'      => (float) $s->lng,
                    'sequence_order' => (int) $s->sequence_order,
                ])->values();

            return response()->json([
                'status' => 'success',
                'route'  => $metrics
            ], 200);

        } catch (Throwable $e) {
            return $this->errorResponse('المسار غير موجود أو لا تملك صلاحية الوصول إليه.', 'ROUTE_NOT_FOUND', 404);
        }
    }

    /**
     * 4️⃣ PUT /api/v1/driver/routes/{routeId}
     * تعديل Route
     */
    public function update(Request $request, $routeId): JsonResponse
    {
        $request->validate([
            'name'      => 'sometimes|required|string|max:50',
            'trip_type' => 'sometimes|in:morning,evening,afternoon',
        ]);

        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $route = RouteModel::where('id', $routeId)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            $this->recommendationService->validateRouteNotRunning($route);

            if ($request->has('trip_type')) {
                $hasChildren = ActiveSubscription::where('route_id', $route->id)
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if ($hasChildren) {
                    return $this->errorResponse('لا يسمح بتعديل فترة المسار لأنه يحتوي على اشتراكات مسندة بالفعل.', 'ROUTE_HAS_CHILDREN', 422);
                }

                $tripTypeLower = strtolower($request->trip_type);
                $route->route_type = (in_array($tripTypeLower, ['morning', 'صباح']) ? 'Morning' : 'Afternoon');
            }

            if ($request->has('name')) {
                $route->route_name = $request->name;
            }

            $route->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث المسار.'
            ], 200);

        } catch (RouteModuleException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 422);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), 'UPDATE_FAILED', 422);
        }
    }

    /**
     * 5️⃣ DELETE /api/v1/driver/routes/{routeId}
     * حذف Route
     */
    public function destroy($routeId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $route = RouteModel::where('id', $routeId)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            $this->recommendationService->validateRouteNotRunning($route);

            $childrenCount = ActiveSubscription::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($childrenCount > 0) {
                return $this->errorResponse('لا يمكن حذف المسار لأنه يحتوي على اشتراكات.', 'ROUTE_HAS_CHILDREN', 409);
            }

            $route->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم حذف المسار.'
            ], 200);

        } catch (RouteModuleException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 422);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), 'DELETE_FAILED', 400);
        }
    }

    /**
     * 6️⃣ GET /api/v1/driver/subscriptions/{subscriptionId}/route-recommendations
     * إيجاد التوصيات لأفضل Route
     */
    public function recommendations($subscriptionId): JsonResponse
    {
        try {
            $user   = Auth::user();
            $driver = $user?->driver;

            $sub = ActiveSubscription::where('id', $subscriptionId)
                ->forDriver($driver->id)
                ->with('subscriptionRequest')          // ← مطلوب لقراءة timing في الخدمة
                ->firstOrFail();

            // ✅ التحقق من أن الاشتراك لم يُسند لمسار مسبقاً
            if ($sub->route_id !== null) {
                return $this->errorResponse(
                    'هذا الاشتراك تم إسناده لمسار بالفعل.',
                    'ALREADY_ASSIGNED',
                    400
                );
            }

            $recommendations = $this->recommendationService->getRecommendationsForSubscription($sub);

            return response()->json(array_merge(['status' => 'success'], $recommendations), 200);

        } catch (Throwable $e) {
            return $this->errorResponse('الاشتراك غير موجود.', 'SUBSCRIPTION_NOT_FOUND', 404);
        }
    }

    /**
     * 7️⃣ POST /api/v1/driver/subscriptions/{subscriptionId}/assign-route
     * إسناد Subscription إلى Route
     */
    public function assignSubscription(Request $request, $subscriptionId): JsonResponse
    {
        $request->validate([
            'route_id' => 'required|integer|exists:routes,id',
        ]);

        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $sub = ActiveSubscription::where('id', $subscriptionId)
                ->forDriver($driver->id)
                ->firstOrFail();

            $route = RouteModel::where('id', $request->route_id)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            $this->recommendationService->validateSubscriptionAssignment($sub, $route);

            $sub->route_id = $route->id;
            if ($sub->status === 'needs_review') {
                $sub->status = 'active';
            }
            $sub->save();

            // ⚠️ بدون هذا الاستدعاء يرتفع children_count بواجهة السائق فوراً بينما
            // لا تُبنى أي محطة اصطحاب/إنزال فعلية للطفل — فلا يظهر إطلاقاً برحلة
            // اليوم التي يولّدها DailyTripGenerationService (يعتمد حصراً على route_stops).
            $this->routeStopSyncService->addChildToRoute($route, $sub);

            $updatedMetrics = $this->recommendationService->calculateRouteMetrics($route->fresh());

            return response()->json([
                'status'        => 'success',
                'message'       => 'تم إسناد الاشتراك.',
                'route_summary' => [
                    'children_count'        => $updatedMetrics['children_count'],
                    'available_seats'       => $updatedMetrics['available_seats'],
                    'recommended_departure' => $updatedMetrics['recommended_departure'],
                    'first_school_time'     => $updatedMetrics['first_school_time'],
                    'last_school_time'      => $updatedMetrics['last_school_time'],
                    'estimated_duration'   => $updatedMetrics['estimated_duration'],
                    'health_score'          => $updatedMetrics['health_score'],
                ]
            ], 200);

        } catch (RouteModuleException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 422);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), 'ASSIGN_FAILED', 422);
        }
    }

    /**
     * 8️⃣ POST /api/v1/driver/subscriptions/{subscriptionId}/move-route
     * نقل اشتراك من مسار إلى آخر وإعادة ملخص المسارين (old_route & new_route)
     */
    public function moveSubscription(Request $request, $subscriptionId): JsonResponse
    {
        $request->validate([
            'to_route' => 'required|integer|exists:routes,id',
        ]);

        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $sub = ActiveSubscription::where('id', $subscriptionId)
                ->forDriver($driver->id)
                ->firstOrFail();

            $oldRoute = $sub->route;
            $targetRoute = RouteModel::where('id', $request->to_route)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            if ($oldRoute) {
                $this->recommendationService->validateRouteNotRunning($oldRoute);
            }
            $this->recommendationService->validateSubscriptionAssignment($sub, $targetRoute);

            $sub->route_id = $targetRoute->id;
            $sub->save();

            // ⚠️ نفس خلل assignSubscription: لازم إزالة محطة الطفل من المسار القديم
            // (وإلا يبقى يظهر برحلة يومَين معاً) وبناء محطته على المسار الجديد فعلياً
            // (وإلا يختفي من الرحلة اليومية تماماً رغم نجاح النقل ظاهرياً).
            if ($oldRoute) {
                $this->routeStopSyncService->removeChildFromDriverRoutes($sub);
            }
            $this->routeStopSyncService->addChildToRoute($targetRoute, $sub);

            $oldMetrics = $oldRoute ? $this->recommendationService->calculateRouteMetrics($oldRoute->fresh()) : null;
            $targetMetrics = $this->recommendationService->calculateRouteMetrics($targetRoute->fresh());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم نقل الاشتراك.',
                'old_route' => $oldMetrics ? [
                    'id'              => (int) $oldMetrics['id'],
                    'children_count'  => $oldMetrics['children_count'],
                    'available_seats' => $oldMetrics['available_seats'],
                ] : null,
                'new_route' => [
                    'id'              => (int) $targetMetrics['id'],
                    'children_count'  => $targetMetrics['children_count'],
                    'available_seats' => $targetMetrics['available_seats'],
                ]
            ], 200);

        } catch (RouteModuleException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 422);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), 'MOVE_FAILED', 422);
        }
    }

    /**
     * 9️⃣ DELETE /api/v1/driver/routes/{routeId}/subscriptions/{subscriptionId}
     * إلغاء إسناد الاشتراك وإرجاع ملخص المسار المحدث
     */
    public function unassignSubscription($routeId, $subscriptionId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $route = RouteModel::where('id', $routeId)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            $this->recommendationService->validateRouteNotRunning($route);

            $sub = ActiveSubscription::where('id', $subscriptionId)
                ->where('route_id', $routeId)
                ->forDriver($driver->id)
                ->firstOrFail();

            // ⚠️ بدون هذا الاستدعاء تبقى محطة منزل الطفل (وربما محطة مدرسته) على
            // المسار القديم للأبد — بيانات وهمية يقرأها مولّد الرحلة اليومية
            // (DailyTripGenerationService::buildTripStops) فيولّد للسائق توقفاً
            // لطفل لم يعد على هذا المسار أصلاً. لاحظت هذا فعلياً: مسار حي بلا أي
            // اشتراك نشط (children_count=0) وما زال يحمل محطتي منزل/مدرسة قديمتين.
            $sub->route_id = null;
            $sub->save();
            $this->routeStopSyncService->removeChildFromDriverRoutes($sub);

            $metrics = $this->recommendationService->calculateRouteMetrics($route->fresh());

            return response()->json([
                'status'  => 'success',
                'message' => 'تمت إزالة الاشتراك من المسار.',
                'route_summary' => [
                    'id'              => (int) $metrics['id'],
                    'children_count'  => $metrics['children_count'],
                    'available_seats' => $metrics['available_seats'],
                ]
            ], 200);

        } catch (RouteModuleException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 422);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), 'UNASSIGN_FAILED', 422);
        }
    }

    /**
     * 🔟 PUT /api/v1/driver/routes/{routeId}/reorder
     * إعادة ترتيب الوقفات والتقاط الأطفال (يدعم صيغة items وصيغة Array)
     */
    public function reorderStops(Request $request, $routeId): JsonResponse
    {
        try {
            $user = Auth::user();
            $driver = $user?->driver;

            $route = RouteModel::where('id', $routeId)
                ->where('driver_id', $driver->id)
                ->firstOrFail();

            $this->recommendationService->validateRouteNotRunning($route);

            $items = $request->input('items', []);
            $subscriptionsArr = $request->input('subscriptions', []);

            DB::transaction(function () use ($items, $subscriptionsArr, $route) {
                if (!empty($items) && is_array($items)) {
                    foreach ($items as $item) {
                        $subId = $item['subscription_id'] ?? null;
                        $order = $item['order'] ?? 1;
                        if ($subId) {
                            ActiveSubscription::where('id', $subId)
                                ->where('route_id', $route->id)
                                ->update(['sort_order' => $order]);
                        }
                    }
                } elseif (!empty($subscriptionsArr) && is_array($subscriptionsArr)) {
                    foreach ($subscriptionsArr as $index => $subId) {
                        ActiveSubscription::where('id', $subId)
                            ->where('route_id', $route->id)
                            ->update(['sort_order' => $index + 1]);
                    }
                }
            });

            $metrics = $this->recommendationService->calculateRouteMetrics($route->fresh());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث الترتيب.',
                'route_summary' => [
                    'recommended_departure' => $metrics['recommended_departure'],
                    'first_school_time'     => $metrics['first_school_time'],
                ]
            ], 200);

        } catch (RouteModuleException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 422);
        } catch (Throwable $e) {
            return $this->errorResponse($e->getMessage(), 'REORDER_FAILED', 422);
        }
    }
}