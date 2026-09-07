<?php

namespace App\Http\Controllers\API\Parent;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Api\Shared\StoreSubscriptionRequest;
use App\Services\Shared\SubscriptionRequestService;
use App\Http\Resources\Api\Parent\SubscriptionRequestDetailsResource;
use App\Http\Resources\Api\Shared\SubscriptionRequestResource;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ParentSubscriptionController extends Controller
{
    protected SubscriptionRequestService $subscriptionService;

    public function __construct(SubscriptionRequestService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        try {
            $result = $this->subscriptionService->createRequest(
                $request->validated(), 
                $request->user()->id 
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال طلب الاشتراك بنجاح.',
                'data'    => new SubscriptionRequestResource($result)
            ], 201);

        } catch (Exception $e) {
            Log::error('Error in ParentSubscriptionController@store', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $request->user()->id ?? null,
                'request' => $request->all(),
                'trace'   => $e->getTraceAsString()
            ]);

            $statusCode = ($e->getCode() >= 400 && $e->getCode() < 500) ? $e->getCode() : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'عذراً، حدث خطأ أثناء معالجة الطلب، يرجى المحاولة لاحقاً.',
            ], $statusCode);
        }
    }
    public function index(Request $request)
    {
        $user = $request->user();
        
        $parentId = (int) $user->id;

        $requests = SubscriptionRequest::query()
            ->with([
                'driver.user',
                'children' => function ($query) {
                    $query->withPivot([
                        'timing',
                        'distance_km',
                        'price_per_child',
                        'trip_price',
                        'discount_amount',            
                        'total_amount_after_discount',
                        'driver_net_price'
                    ]);
                },
                'children.school',
                'children.address'
            ])
            ->where('parent_id', $parentId)
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->latest()
            ->paginate($request->get('per_page', 15));

        return SubscriptionRequestDetailsResource::collection($requests)
            ->additional([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب طلبات الاشتراك بنجاح',
            ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $parentId = (int) $user->id;

        $subscriptionRequest = SubscriptionRequest::query()
            ->with([
                'driver.user',
                'children' => function ($query) {
                    $query->withPivot([
                        'timing',
                        'distance_km',
                        'price_per_child',
                        'trip_price',
                        'discount_amount',            
                        'total_amount_after_discount',
                        'driver_net_price'
                    ]);
                },
                'children.school',
                'children.address'
            ])
            ->where('parent_id', $parentId)
            ->findOrFail($id);

        return (new SubscriptionRequestDetailsResource($subscriptionRequest))
            ->additional([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب تفاصيل طلب الاشتراك بنجاح',
            ]);
    }

    public function cancel($id, Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $this->subscriptionService->cancelSubscriptionByParent((int) $id, $userId);

            return response()->json([
                'status'  => 'success',
                'success' => true,
                'message' => 'تم إلغاء طلب الاشتراك بنجاح.'
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود.'], 404);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function activeSubscriptions(Request $request)
    {
        try {
            $request->validate([
                'filter' => 'nullable|string|in:active,pending,completed,cancelled',
            ]);

            $userId = $request->user()->id;
            $filter = $request->query('filter');

            $activeSubscriptions = $this->subscriptionService->getParentActiveSubscriptions($userId, $filter);

            // تفكيك الاشتراكات ليتم عرض كل طفل باشتراكه المستقل
            $childSubscriptions = collect();
            foreach ($activeSubscriptions as $subscriptionRequest) {
                if ($subscriptionRequest->children && $subscriptionRequest->children->isNotEmpty()) {
                    foreach ($subscriptionRequest->children as $child) {
                        $matchingActiveSub = optional($subscriptionRequest->activeSubscriptions)->firstWhere('child_id', $child->id);
                        $activeSubId = $matchingActiveSub ? $matchingActiveSub->id : $subscriptionRequest->id;
                        $childSubscriptions->push([
                            'subscriptionRequest' => $subscriptionRequest,
                            'child'               => $child,
                            'activeSubId'         => $activeSubId,
                        ]);
                    }
                } else {
                    $childSubscriptions->push([
                        'subscriptionRequest' => $subscriptionRequest,
                        'child'               => null,
                        'activeSubId'         => $subscriptionRequest->id,
                    ]);
                }
            }

            return \App\Http\Resources\Api\Parent\ParentActiveChildSubscriptionResource::collection($childSubscriptions)
                ->additional([
                    'status'  => true,
                    'success' => true,
                    'message' => 'تم جلب الاشتراكات النشطة بنجاح',
                ]);

        } catch (Exception $e) {
            Log::error('Error in ParentSubscriptionController@activeSubscriptions', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $request->user()->id ?? null,
                'filter'  => $request->query('filter')
            ]);

            return response()->json([
                'status'  => false,
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب الاشتراكات النشطة.'
            ], 400);
        }
    }

    public function showActive(Request $request, $id)
    {
        try {
            $user = $request->user();
            $parentId = (int) $user->id;

            // 1. البحث برقم اشتراك الطفل المحدد من جدول active_subscriptions
            $activeSub = \App\Models\Shared\ActiveSubscription::query()
                ->with([
                    'child.school',
                    'child.address',
                    'driver.user',
                    'subscriptionRequest.children' => function ($query) {
                        $query->withPivot([
                            'timing',
                            'distance_km',
                            'price_per_child',
                            'trip_price',
                            'discount_amount',            
                            'total_amount_after_discount',
                            'driver_net_price'
                        ]);
                    },
                    'subscriptionRequest.activeSubscriptions'
                ])
                ->forParent($parentId)
                ->where('id', $id)
                ->first();

            if ($activeSub && $activeSub->subscriptionRequest && $activeSub->child) {
                $childWithPivot = $activeSub->subscriptionRequest->children->firstWhere('id', $activeSub->child_id) ?? $activeSub->child;
                return (new \App\Http\Resources\Api\Parent\ParentActiveChildSubscriptionResource([
                    'subscriptionRequest' => $activeSub->subscriptionRequest,
                    'child'               => $childWithPivot,
                    'activeSubId'         => $activeSub->id,
                ]))->additional([
                    'status'  => true,
                    'success' => true,
                    'message' => 'تم جلب تفاصيل الاشتراك بنجاح',
                ]);
            }

            // 2. إذا تم تمرير المعرف العام للطلب
            $subscriptionRequest = SubscriptionRequest::query()
                ->with([
                    'driver.user',
                    'children' => function ($query) {
                        $query->withPivot([
                            'timing',
                            'distance_km',
                            'price_per_child',
                            'trip_price',
                            'discount_amount',            
                            'total_amount_after_discount',
                            'driver_net_price'
                        ]);
                    },
                    'children.school',
                    'children.address',
                    'activeSubscriptions'
                ])
                ->where(function ($q) use ($parentId, $user) {
                    $q->where('parent_id', $parentId)
                      ->orWhere('parent_id', $user->id);
                })
                ->where(function ($q) use ($id) {
                    $q->where('id', $id)
                      ->orWhereHas('activeSubscriptions', function ($subQ) use ($id) {
                          $subQ->where('id', $id);
                      });
                })
                ->first();

            if (!$subscriptionRequest) {
                return response()->json([
                    'status'  => false,
                    'success' => false,
                    'message' => 'الاشتراك النشط غير موجود أو لا تملك صلاحية الوصول إليه.'
                ], 404);
            }

            $firstChild = $subscriptionRequest->children?->first();
            return (new \App\Http\Resources\Api\Parent\ParentActiveChildSubscriptionResource([
                'subscriptionRequest' => $subscriptionRequest,
                'child'               => $firstChild,
            ]))->additional([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب تفاصيل الاشتراك بنجاح',
            ]);

        } catch (Exception $e) {
            Log::error('Error in ParentSubscriptionController@showActive', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $request->user()->id ?? null,
                'sub_id'  => $id
            ]);

            return response()->json([
                'status'  => false,
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب تفاصيل الاشتراك.'
            ], 500);
        }
    }

    public function cancelActiveSubscription(Request $request, $id): JsonResponse
    {
        try {
            $updated = $this->subscriptionService->cancelActiveSubscriptionByParent(
                (int) $id,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إلغاء الاشتراك بنجاح وتم إشعار السائق.',
                'data'    => ['id' => $updated->id, 'status' => $updated->status],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'الاشتراك النشط غير موجود أو لا تملك صلاحية إلغائه.',
            ], 404);

        } catch (Exception $e) {
            Log::error('Error in ParentSubscriptionController@cancelActiveSubscription', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
                'sub_id'  => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function formatBilling(ActiveSubscription $item): array
    {
        $subReq = $item->subscriptionRequest;
        $totalPrice = (float) ($subReq?->total_price ?? 0);

        $pivotData = null;
        if ($subReq) {
            $pivotData = DB::table('request_children')
                ->where('request_id', $subReq->id)
                ->where('child_id', $item->child_id)
                ->first();
        }

        $childPrice = $pivotData?->price_per_child !== null ? (float) $pivotData->price_per_child : $totalPrice;
        $startDate  = $pivotData?->start_date ?? $item->start_date;
        $endDate    = $pivotData?->end_date ?? $item->end_date;
        $subType    = $pivotData?->subscription_type ?? 'multi_day';

        $endsAt = $endDate ? \Carbon\Carbon::parse($endDate) : null;
        $remainingDays = $endsAt ? max(0, (int) now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false)) : null;

        return [
            'subscriptionType' => $subType,
            'totalPrice'       => $totalPrice,
            'childPrice'       => round($childPrice, 2),
            'currency'         => 'د.ل',
            'startsAt'         => $startDate ? \Carbon\Carbon::parse($startDate)->toDateString() : null,
            'endsAt'           => $endsAt?->toDateString(),
            'remainingDays'    => $remainingDays,
            'autoRenew'        => false,
            'paymentMethod'    => 'wallet',
        ];
    }

    public function checkSubscription(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $parentId = (int) $user->id;

            $query = \App\Models\Shared\ActiveSubscription::forParent($parentId)
                ->whereIn('status', ['active', 'pending', 'completed']);

            if ($request->has('driver_id') && !empty($request->driver_id)) {
                $query->forDriver($request->driver_id);
            }

            $hasSubscription = $query->exists();

            return response()->json([
                'success'          => true,
                'has_subscription' => $hasSubscription
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in ParentSubscriptionController@checkSubscription: ' . $e->getMessage());

            return response()->json([
                'success'          => false,
                'has_subscription' => false,
                'message'          => 'حدث خطأ أثناء التحقق من الاشتراك.'
            ], 500);
        }
    }

    public function showRequest(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            $subscriptionRequest = SubscriptionRequest::with([
                'driver.user',
                'children' => function ($query) {
                    $query->withPivot([
                        'timing',
                        'distance_km',
                        'price_per_child',
                        'trip_price',
                        'discount_amount',            
                        'total_amount_after_discount',
                        'driver_net_price'
                    ]);
                },
                'children.school',
                'children.pickupAddress',
                'children.dropoffAddress'
            ])
            ->where('parent_id', $userId)
            ->where('id', $id)
            ->first();

            if (!$subscriptionRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'طلب الاشتراك غير موجود أو لا تملك صلاحية الوصول إليه.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب تفاصيل الطلب بنجاح.',
                'data'    => new SubscriptionRequestDetailsResource($subscriptionRequest)
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in ParentSubscriptionController@showRequest', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $request->user()->id ?? null,
                'request_id' => $id,
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب تفاصيل الطلب: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getParentChatList(Request $request): JsonResponse
    {
        try {
            $chats = $this->subscriptionService->getParentChats($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب قائمة المحادثات بنجاح.',
                'data'    => $chats
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], 400);
        }
    }
}