<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Driver\DriverSubscriptionResource; 
use App\Services\Shared\SubscriptionRequestService;
use App\Services\Trip\RouteFeasibilityService;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Http\Resources\Api\Driver\SubscriptionRequestDetailsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Driver\Driver;
use Carbon\Carbon;
use Exception;

class DriverSubscriptionController extends Controller
{
    protected SubscriptionRequestService $subscriptionService;
    protected RouteFeasibilityService $feasibilityService;

    public function __construct(
        SubscriptionRequestService $subscriptionService,
        RouteFeasibilityService $feasibilityService
    ) {
        $this->subscriptionService = $subscriptionService;
        $this->feasibilityService = $feasibilityService;
    }

    /**
     * جلب ملف السائق المرتبط بالمستخدم الحاضر
     */
    private function getAuthenticatedDriver(Request $request): ?Driver
    {
        return $request->user()->driver ?? Driver::where('user_id', $request->user()->id)->first();
    }

    /**
     * استجابة موحدة في حال عدم وجود ملف سائق
     */
    private function driverNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'بيانات السائق غير موجودة.',
            'error'   => 'دراااااااااافيرلم يتم العثور عل     ى ملف السائق الخاص بك.'
        ], 403);
    }

    public function index(Request $request)
    {
        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        // 'filter' هو الاسم الموثّق في FRONTEND_DRIVER_API_GUIDE (?filter=pending)؛
        // نقبل 'status' أيضاً كاسم بديل لتوافق أي عميل قديم يرسله بهذا الاسم.
        $statusFilter = $request->input('filter', $request->input('status'));

        $requests = SubscriptionRequest::query()
            ->with(['parent.user', 'driver.user', 'children.school', 'children.address'])
            ->where('driver_id', $driver->id)
            ->when(filled($statusFilter), fn($q) => $q->where('status', $statusFilter))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return DriverSubscriptionResource::collection($requests)->additional([
            'status'  => true,
            'success' => true,
            // count جزء من العقد الموثّق في FRONTEND_DRIVER_API_GUIDE وتعتمد عليه الواجهة
            // لعرض عدد الطلبات؛ غيابه كان يكسرها رغم أن البيانات تصل سليمة.
            'count'   => $requests->total(),
            'message' => 'تم جلب طلبات الاشتراك بنجاح',
        ]);
    }
    public function activeSubscriptions(Request $request)
    {
        try {
            $request->validate([
                'filter' => 'nullable|string|in:current_active,pending_start,completed,cancelled,active'
            ]);
    
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'success' => false,
                    'message' => 'حدث خطأ أثناء جلب الاشتراكات النشطة.',
                    'error'   => 'غير مصرح بالوصول، التوكن غير صحيح أو منتهي الصلاحية.'
                ], 401);
            }
    
            $driver = $this->getAuthenticatedDriver($request);
            if (!$driver) {
                return $this->driverNotFoundResponse();
            }
    
            $activeSubscriptions = $this->subscriptionService->getDriverActiveSubscriptions(
                $user->id, 
                $request->query('filter')
            );

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

            return \App\Http\Resources\Api\Driver\DriverActiveChildSubscriptionResource::collection($childSubscriptions)->additional([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب الاشتراكات النشطة بنجاح',
            ]);
    
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب الاشتراكات النشطة.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
   

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $request->validate([
            'status'           => 'required|in:accepted,rejected',
            'rejection_reason' => 'required_if:status,rejected|nullable|string|max:500',
        ]);

        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        $subscriptionRequest = SubscriptionRequest::find($id);

        if (!$subscriptionRequest) {
            return response()->json(['success' => false, 'message' => 'طلب الاشتراك غير موجود.'], 404);
        }

        if ($subscriptionRequest->driver_id != $driver->id) {
            return response()->json(['success' => false, 'message' => 'هذا الطلب غير موجه لك ولا يمكنك التحكم به.'], 403);
        }

        try {
            $updatedRequest = $this->subscriptionService->updateStatus(
                $subscriptionRequest,
                $request->status,
                $request->input('rejection_reason')
            );

            return response()->json([
                'success' => true,
                'message' => $request->status === 'accepted' ? 'تم قبول الطلب وتفعيل الاشتراك بنجاح.' : 'تم رفض الطلب بنجاح.',
                'data'    => new DriverSubscriptionResource($updatedRequest)
            ], 200);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'حدث خطأ في النظام: ' . $e->getMessage()], 500);
        }
    }

    public function accept(Request $request, $id): JsonResponse
    {
        $request->merge(['status' => 'accepted']);
        return $this->updateStatus($request, $id);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $request->merge(['status' => 'rejected']);
        return $this->updateStatus($request, $id);
    }

    public function activeSubscriptionDetails(Request $request, $id)
    {
        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        try {
            // 1. البحث برقم اشتراك الطفل المحدد من جدول active_subscriptions
            $activeSub = \App\Models\Shared\ActiveSubscription::query()
                ->with([
                    'child.school',
                    'child.address',
                    'driver.user',
                    'subscriptionRequest.parent.user',
                    'subscriptionRequest.children' => function ($query) {
                        $query->withPivot([
                            'subscription_type',
                            'trip_direction',
                            'timing',
                            'start_date',
                            'end_date',
                            'working_days_count',
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
                ->where('driver_id', $driver->id)
                ->where('id', $id)
                ->first();

            if ($activeSub && $activeSub->subscriptionRequest && $activeSub->child) {
                $childWithPivot = $activeSub->subscriptionRequest->children->firstWhere('id', $activeSub->child_id) ?? $activeSub->child;
                return (new \App\Http\Resources\Api\Driver\DriverActiveChildSubscriptionResource([
                    'subscriptionRequest' => $activeSub->subscriptionRequest,
                    'child'               => $childWithPivot,
                    'activeSubId'         => $activeSub->id,
                ]))->additional([
                    'status'  => true,
                    'success' => true,
                    'message' => 'تم جلب تفاصيل الاشتراك النشط بنجاح',
                ]);
            }

            // 2. إذا تم تمرير المعرف العام للطلب
            $subscriptionRequest = SubscriptionRequest::query()
                ->with([
                    'parent.user',
                    'children' => function ($query) {
                        $query->withPivot([
                            'subscription_type',
                            'trip_direction',
                            'timing',
                            'start_date',
                            'end_date',
                            'working_days_count',
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
                ->where('driver_id', $driver->id)
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
                    'message' => 'الاشتراك النشط غير موجود أو ليس لديك صلاحية للوصول إليه.'
                ], 404);
            }

            $firstChild = $subscriptionRequest->children?->first();
            return (new \App\Http\Resources\Api\Driver\DriverActiveChildSubscriptionResource([
                'subscriptionRequest' => $subscriptionRequest,
                'child'               => $firstChild,
            ]))->additional([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب تفاصيل الاشتراك النشط بنجاح',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        // findOrFail يرمي ModelNotFoundException، وتُترجم افتراضياً إلى استجابة عامة
        // بمفتاح 'status' بدل 'success' — يخالف عقد بقية نقاط هذا الكنترولر التي
        // تستخدم 'success' دائماً. نلتقطها هنا لنُبقي الشكل موحداً على العميل.
        $subscriptionRequest = SubscriptionRequest::query()
            ->with([
                'parent.user',
                'driver.user',
                'children' => function ($query) {
                    $query->withPivot([
                        'subscription_type',
                        'trip_direction',
                        'timing',
                        'start_date',
                        'end_date',
                        'working_days_count',
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
            ->where('driver_id', $driver->id)
            ->where('id', $id)
            ->first();

        if (!$subscriptionRequest) {
            return response()->json([
                'status'  => false,
                'success' => false,
                'message' => 'عذراً، طلب الاشتراك المطلوب غير موجود أو غير مخصص لك.',
            ], 404);
        }

        return (new DriverSubscriptionResource($subscriptionRequest))
            ->additional([
                'status'  => true,
                'success' => true,
                'message' => 'تم جلب تفاصيل طلب الاشتراك بنجاح',
            ]);
    }

    public function tripDetails(Request $request, $id): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        $subscriptionRequest = SubscriptionRequest::where('id', $id)
            ->where('driver_id', $driver->id)
            ->with(['parent.user', 'children.school', 'children.address'])
            ->first();

        if (!$subscriptionRequest) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود أو غير مخصص لك.'], 404);
        }

        // مدرسة الطلب نفسه (SubscriptionRequest::school) أصبحت null دائماً — العمود
        // school_id أُزيل من جدول requests وصار لكل طفل مدرسته الخاصة. نعرض مدرسة
        // أول طفل كتمثيل معقول للرحلة الواحدة (حالة الوجهة المشتركة الأشيع).
        $school = $subscriptionRequest->children->first()?->school;

        $tripDetails = [
            'request_id'   => $subscriptionRequest->id,
            'status'       => $subscriptionRequest->status,
            'trip_type'    => $subscriptionRequest->direction ?? 'two_way',
            'pickup_time'  => $subscriptionRequest->pickup_time  ?? null,
            'dropoff_time' => $subscriptionRequest->dropoff_time ?? null,
            'parent'       => [
                'name'  => $subscriptionRequest->parent?->user?->full_name ?? $subscriptionRequest->parent?->user?->name ?? 'غير محدد',
                'phone' => $subscriptionRequest->parent?->user?->phone_number ?? $subscriptionRequest->parent?->user?->phone ?? null,
            ],
            'school' => [
                'id'        => $school?->id        ?? null,
                'name'      => $school?->name      ?? 'غير محدد',
                'address'   => $school?->address   ?? null,
                'latitude'  => (float) ($school?->lat  ?? $school?->latitude  ?? 0),
                'longitude' => (float) ($school?->lng ?? $school?->longitude ?? 0),
            ],
            'children' => $subscriptionRequest->children->map(fn($child) => [
                'id'           => $child->id,
                'name'         => $child->full_name ?? $child->name ?? 'طفل',
                'gender'       => $child->gender ?? null,
                'home_address' => $child->pivot?->home_label ?? $child->address?->label ?? $child->address?->address ?? 'العنوان غير محدد',
                'latitude'     => (float) ($child->pivot?->home_lat  ?? $child->address?->lat  ?? $child->latitude  ?? 0),
                'longitude'    => (float) ($child->pivot?->home_lng ?? $child->address?->lng ?? $child->longitude ?? 0),
                'notes'        => $child->pivot?->child_notes ?? $child->notes ?? null,
            ])
        ];

        return response()->json(['success' => true, 'data' => $tripDetails], 200);
    }

    public function feasibilityCheck(Request $request, $id): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        $subscriptionRequest = SubscriptionRequest::where('id', $id)
            ->where('driver_id', $driver->id)
            ->with('children')
            ->first();

        if (!$subscriptionRequest) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود أو غير مخصص لهذا السائق.'], 404);
        }

        try {
            $result = $this->feasibilityService->checkForRequest($subscriptionRequest);
            return response()->json(['success' => true, 'data' => $result], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء فحص إمكانية إضافة الطلب: ' . $e->getMessage()], 500);
        }
    }

    public function cancelActiveSubscription(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $driver = $this->getAuthenticatedDriver($request);
        if (!$driver) {
            return $this->driverNotFoundResponse();
        }

        try {
            $activeSub = $this->subscriptionService->cancelActiveSubscriptionByDriver(
                $id,
                $driver->id,
                $request->input('reason')
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إلغاء الاشتراك النشط بنجاح وتم إشعار ولي الأمر.',
                'data'    => ['id' => $activeSub->id, 'status' => $activeSub->status],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'الاشتراك النشط غير موجود أو لا تملك صلاحية الوصول إليه.'], 404);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function getDriverChatList(Request $request): JsonResponse
    {
        try {
            $chats = $this->subscriptionService->getDriverChats($request->user()->id);
            return response()->json(['success' => true, 'message' => 'تم جلب قائمة المحادثات بنجاح.', 'data' => $chats], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()], 400);
        }
    }
}