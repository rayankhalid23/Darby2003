<?php

namespace App\Services\Parent;

use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Services\Shared\SubscriptionRequestService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use App\Enums\Shared\SchoolStage;
use Exception;

class ChildService
{
    /**
     * إنشاء طفل جديد في النظام مع معالجة رفع الصورة المخصصة له.
     */
    public function createChild(array $data): Child
    {
        // 1. التحقق من التكرار
        $exists = Child::where('parent_id', $data['parent_id'])
                       ->where('full_name', $data['full_name'])
                       ->exists();
    
        if ($exists) {
            throw new \Illuminate\Validation\ValidationException(
                \Illuminate\Support\Facades\Validator::make([], []), 
                response()->json([
                    'success' => false, 
                    'message' => 'هذا الطفل مضاف مسبقاً في حسابك.',
                    'errors'  => [
                        'full_name' => ['هذا الطفل مضاف مسبقاً في حسابك.']
                    ]
                ], 422)
            );
        }
    
        // 2. استخدام Transaction
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            
            // معالجة الصورة
            if (isset($data['photo']) && $data['photo'] instanceof UploadedFile) {
                $data['photo_url'] = $this->uploadPhoto($data['photo']);
                unset($data['photo']);
            }

            // في V2 تم دمج بيانات النقل اللوجستية مباشرة كأعمدة في جدول children
            $child = Child::create($data);
    
            return $child;
        });
    }

   /**
     * تحديث بيانات الطفل وبيانات اشتراكه اللوجستي في نفس الوقت (دعم التعديل الجزئي).
     */
   public function updateChild(Child $child, array $data): Child
   {
       // 1. تأمين البيانات ومنع تعديل الحقول الحساسة
       unset($data['qr_code_token'], $data['school_stage']);

       // 2. معالجة تحديث الصورة عند رفع صورة جديدة بأي مسمى
       $photoFile = $data['photo'] ?? $data['child_photo'] ?? $data['image'] ?? request()->file('photo') ?? request()->file('child_photo') ?? request()->file('image');
       if ($photoFile instanceof \Illuminate\Http\UploadedFile) {
           if (!empty($child->photo_url)) {
               $this->deletePhoto($child->photo_url);
           }
           $data['photo_url'] = $this->uploadPhoto($photoFile);
           unset($data['photo'], $data['child_photo'], $data['image']);
       }

        // 3. تحديث بيانات الطفل الأساسية واللوجستية المدمجة في جدول children
        if (!empty($data)) {
            $child->update($data);
        }

        return $child->refresh();
   }

    /**
     * التحقق مما إذا كان لدى الطفل اشتراك مفعل أو مجدول حالياً
     */
    public function hasActiveOrScheduledSubscription(Child $child): bool
    {
        // 1. فحص جدول الاشتراكات النشطة (active_subscriptions)
        $hasActiveSub = ActiveSubscription::forChild($child->id)
            ->whereIn('status', ['active', 'paused'])
            ->exists();

        if ($hasActiveSub) {
            return true;
        }

        // 2. فحص طلبات الاشتراك المقبولة أو السارية (سواء بدأت أو مجدولة للبدء مستقبلاً)
        $hasAcceptedOrScheduledReq = SubscriptionRequest::whereIn('status', [
                SubscriptionRequest::STATUS_ACCEPTED,
                'active',
            ])
            ->whereHas('children', function ($q) use ($child) {
                $q->where('children.id', $child->id);
            })
            ->where(function ($subQ) {
                $subQ->whereNull('end_date')
                     ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->exists();

        return $hasAcceptedOrScheduledReq;
    }

    /**
     * إلغاء كافة طلبات الاشتراك المعلقة المرتبطة بالطفل
     */
    public function cancelPendingRequestsForChild(Child $child): void
    {
        $pendingRequests = SubscriptionRequest::whereIn('status', [
                SubscriptionRequest::STATUS_PENDING,
                SubscriptionRequest::STATUS_ACQUIRED,
            ])
            ->whereHas('children', function ($q) use ($child) {
                $q->where('children.id', $child->id);
            })
            ->get();

        if ($pendingRequests->isEmpty()) {
            return;
        }

        $subscriptionRequestService = app(SubscriptionRequestService::class);

        foreach ($pendingRequests as $request) {
            try {
                $request->update([
                    'status'           => SubscriptionRequest::STATUS_CANCELLED,
                    'rejection_reason' => 'تم إلغاء الطلب تلقائياً لحذف ملف الطفل [' . $child->full_name . '] من النظام.',
                ]);

                // استرجاع المبالغ المحجوزة في محفظة ولي الأمر إن وُجدت
                $subscriptionRequestService->refundHeldFundsOnCancellation($request->id, 'system');

                // إشعار السائق إن كان الطلب مرتبطاً بسائق
                $driverUser = $request->driver?->user;
                if ($driverUser) {
                    try {
                        $notificationService = app(NotificationService::class);
                        $notificationService->sendToUser(
                            $driverUser,
                            'إلغاء طلب اشتراك',
                            "تم إلغاء طلب الاشتراك المرتبط بالطفل [{$child->full_name}] بسبب حذف ملف الطفل.",
                            'subscription_request_cancelled',
                            (string) $request->id
                        );
                    } catch (\Throwable $notifEx) {
                        // تجاهل أخطاء الإشعار لعدم تعطيل العملية
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel pending request #' . $request->id . ' for child #' . $child->id . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * حذف طفل من النظام مع حذف صورته وإلغاء طلباته المعلقة بعد التأكد من عدم وجود اشتراك سارٍ أو مجدول.
     */
    public function deleteChild(Child $child): bool
    {
        if ($this->hasActiveOrScheduledSubscription($child)) {
            throw new Exception('لا يمكن حذف الطفل لوجود اشتراك مفعل أو مجدول مرتبط به.');
        }

        // إلغاء كافة طلبات الاشتراك المعلقة المرتبطة بهذا الطفل
        $this->cancelPendingRequestsForChild($child);

        // حذف الملف المادي للصورة باستخدام الحقل الصحيح photo_url
        $this->deletePhoto($child->photo_url);

        // حذف السجل من قاعدة البيانات (Soft Delete)
        return $child->delete();
    }

    /**
     * التحقق مما إذا كان ولي الأمر لديه أطفال مضافين في النظام أم لا.
     */
    public function hasChildren(int $userId, ?int $parentId): bool
    {
        return Child::where(function ($q) use ($userId, $parentId) {
            $q->where('parent_id', $userId);
            if ($parentId) {
                $q->orWhere('parent_id', $parentId);
            }
        })->exists();
    }

    /**
     * جلب الأطفال الذين لديهم اشتراكات نشطة فقط لولي الأمر الحالي.
     */
    public function getActiveSubscribedChildren(int $userId, ?int $parentId)
    {
        // 1. جلب معرفات الأطفال الذين لديهم اشتراك نشط من جدول active_subscriptions
        $activeChildIdsFromSubs = \App\Models\Shared\ActiveSubscription::whereHas('subscriptionRequest', function ($q) use ($userId, $parentId) {
                $q->where('parent_id', $userId);
                if ($parentId) {
                    $q->orWhere('parent_id', $parentId);
                }
            })
            ->where('status', 'active')
            ->with('requestChild')
            ->get()
            ->pluck('child_id')
            ->toArray();

        // 2. جلب معرفات الأطفال الذين لديهم اشتراك نشط من جدول child_logistics
        $activeChildIdsFromLogistics = Child::where(function ($q) use ($userId, $parentId) {
                $q->where('parent_id', $userId);
                if ($parentId) {
                    $q->orWhere('parent_id', $parentId);
                }
            })
            ->whereHas('logistics', function ($l) {
                $l->where('is_active', true);
            })
            ->pluck('id')
            ->toArray();

        $allActiveChildIds = array_unique(array_merge($activeChildIdsFromSubs, $activeChildIdsFromLogistics));

        return Child::whereIn('id', $allActiveChildIds)->get();
    }

    /**
     * دالة مساعدة خاصة برفع صور الأطفال بشكل آمن ومنظم
     */
    private function uploadPhoto(UploadedFile $photo): string
    {
        // تخزين الصورة في مجلد 'children_photos' داخل قرص الـ public
        return $photo->store('children_photos', 'public');
    }

    /**
     * دالة مساعدة خاصة بحذف صور الأطفال من قرص التخزين
     */
    private function deletePhoto(?string $photoPath): void
    {
        if ($photoPath && Storage::disk('public')->exists($photoPath)) {
            Storage::disk('public')->delete($photoPath);
        }
    }
}