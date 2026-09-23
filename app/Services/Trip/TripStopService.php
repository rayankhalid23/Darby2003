<?php

namespace App\Services\Trip;

use App\Models\Shared\Trip;
use App\Models\Shared\TripEvent;
use App\Models\Shared\ActiveSubscription;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Services\Trip\TripLifecycleService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class TripStopService
{
    protected TripLifecycleService $lifecycleService;

    protected NotificationService $notificationService;

    public function __construct(TripLifecycleService $lifecycleService, NotificationService $notificationService)
    {
        $this->lifecycleService = $lifecycleService;
        $this->notificationService = $notificationService;
    }

    public function startWaitingCounter(int $tripId, int $childId): array
    {
        $trip = Trip::findOrFail($tripId);
        $driver = Driver::findOrFail($trip->driver_id);
        
        $waitingMinutes = $driver->driver_waiting_minutes ?? 5;
        $expiresAt = Carbon::now()->addMinutes($waitingMinutes);
        $cacheKey = "trip_waiting_{$tripId}_{$childId}";
        
        Cache::put($cacheKey, [
            'start_time' => Carbon::now()->toIso8601String(),
            'end_time' => $expiresAt->toIso8601String(),
            'duration_minutes' => $waitingMinutes
        ], now()->addMinutes($waitingMinutes + 5));

        return [
            'status' => 'counter_started',
            'expires_at' => $expiresAt
        ];
    }
    public function skipChild(int $tripId, int $childId, ?string $reason = null): void
    {
        $trip = Trip::findOrFail($tripId);

        // 1. جلب سجل الاشتراك النشط للطفل لتفادي أخطاء الـ Foreign Key وتأمين البيانات المالية
        $subscription = ActiveSubscription::forDriver($trip->driver_id)
            ->forChild($childId)
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            throw new Exception('أمن النظام: لم يتم العثور على اشتراك فعال لهذا الطفل مع هذا السائق.');
        }

        DB::transaction(function () use ($trip, $childId, $subscription, $reason) {
            // 2. التحقق مما إذا كان الطفل قد صعد الحافلة بالفعل في هذه الرحلة منعاً للتضارب
            $alreadyPickedUp = TripEvent::where('trip_id', $trip->id)
                ->where('child_id', $childId)
                ->where('action_type', 'picked_up')
                ->lockForUpdate()
                ->exists();

            if ($alreadyPickedUp) {
                throw new Exception('لا يمكن تخطي الطفل، لقد تم تأكيد ركوبه بالفعل!');
            }

            // 3. تحديث أو إنشاء الحدث بكافة الحقول الإلزامية لتفادي أخطاء قاعدة البيانات
            TripEvent::updateOrCreate(
                [
                    'trip_id'         => $trip->id, 
                    'child_id'        => $childId, 
                    'action_type'     => 'skipped'
                ],
                [
                    'subscription_id' => $subscription->id, // 👈 التمرير الآمن والمباشر لجدولك الفعلي
                    'location_lat'    => $subscription->pickup_lat ?? 0,
                    'location_lng'    => $subscription->pickup_lng ?? 0,
                    'scanned_at'      => Carbon::now(),
                    'trip_cost'       => 0, // تخطي المحطة وتأخر الطفل يعني تكلفة صفرية للرحلة
                    'reason'          => $reason,
                ]
            );

            \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->where('child_id', $childId)
                ->where('stop_type', 'home')
                ->update([
                    'status' => \App\Models\Shared\TripStop::STATUS_SKIPPED_UNRESPONSIVE,
                    'reason' => $reason,
                ]);
        });

        // 4. تصفير وتنظيف كاش الانتظار فوراً
        Cache::forget("trip_waiting_{$tripId}_{$childId}");

        // 5. جلب حساب المستخدم لولي الأمر بطريقة آمنة لإرسال إشعار فوري له بتخطي المحطة
        $dbChild = DB::table('children')->where('id', $childId)->select('parent_id', 'full_name')->first();
        if ($dbChild && $dbChild->parent_id) {
            $parentModel = \App\Models\Parent\ParentModel::find($dbChild->parent_id);
            if ($parentModel && $parentModel->user_id) {
                $parentUser = User::find($parentModel->user_id);
                if ($parentUser) {
                    $this->notificationService->sendToUser($parentUser, 'child_skipped', [
                        'title'      => 'تنبيه: تحركت الحافلة ⚠️',
                        'message'    => "نظراً لانتهاء وقت الانتظار المحدد دون صعود الطفل: {$dbChild->full_name}، تحركت الحافلة للمحطة التالية.",
                        'child_name' => $dbChild->full_name,
                        'trip_id'    => (string) $trip->id,
                        'entity_id'  => $trip->id . '_' . $childId,
                    ]);
                }
            }
        }

        // 6. إعادة حساب خط السير والأوقات التقديرية لباقي محطات الأطفال بعد تخطي هذا الطفل
        $this->lifecycleService->calculateInitialRoute($trip->id);
    }

    public function confirmManualPickup(int $tripId, int $childId, int $parentId): void
    {
        $trip = Trip::findOrFail($tripId);
        
        $subscription = ActiveSubscription::forDriver($trip->driver_id)
            ->forChild($childId)
            ->where('status', 'active')
            ->first();

        $subId = $subscription ? $subscription->id : (ActiveSubscription::forChild($childId)->value('id') ?? 1);
        $pickupLat = $subscription ? ($subscription->pickup_lat ?? 32.89) : 32.89;
        $pickupLng = $subscription ? ($subscription->pickup_lng ?? 13.18) : 13.18;

        DB::transaction(function () use ($trip, $childId, $subId, $pickupLat, $pickupLng) {
            $isSkipped = TripEvent::where('trip_id', $trip->id)
                ->where('child_id', $childId)
                ->where('action_type', 'skipped')
                ->lockForUpdate()
                ->exists();

            if ($isSkipped) {
                throw new Exception('عذراً، قام السائق بتجاوز هذه المحطة بالفعل.');
            }

            TripEvent::updateOrCreate(
                ['trip_id' => $trip->id, 'child_id' => $childId, 'action_type' => 'picked_up'],
                [
                    'subscription_id' => $subId,
                    'trip_type'       => ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب') ? 'ذهاب' : 'عودة',
                    'location_lat'    => $pickupLat,
                    'location_lng'    => $pickupLng,
                    'scanned_at'      => Carbon::now(),
                    'trip_cost'       => 0,
                ]
            );

            // ⚠️ كانت هذه الدالة تسجّل trip_events فقط دون تحديث trip_stops، خلافاً لمسار
            // السائق (DriverTripController::scan) ولـ skipChild في نفس الملف. فيبقى صف
            // محطة المنزل عالقاً على 'pending' حتى بعد صعود الطفل فعلياً، فيمنع
            // assertNoForgottenChildren() السائق من إنهاء الرحلة رغم اكتمالها فعلاً،
            // ويستمر resolveNextStop() في توجيه السائق لمحطة طفل صعد الحافلة بالفعل.
            \App\Models\Shared\TripStop::where('trip_id', $trip->id)
                ->where('child_id', $childId)
                ->where('stop_type', \App\Models\Shared\TripStop::TYPE_HOME)
                ->where('status', \App\Models\Shared\TripStop::STATUS_PENDING)
                ->update(['status' => \App\Models\Shared\TripStop::STATUS_BOARDED]);
        });

        Cache::forget("trip_waiting_{$tripId}_{$childId}");

        $driverUser = User::find($trip->driver->user_id ?? null);
        if ($driverUser) {
            $this->notificationService->sendToUser($driverUser, 'manual_pickup_confirmed', [
                'title'     => 'تأكيد يدوي من ولي الأمر 🎯',
                'message'   => 'قامت الأم بتأكيد ركوب الطفل يدوياً.',
                'trip_id'   => (string) $trip->id,
                'entity_id' => $trip->id . '_' . $childId,
            ]);
        }
    }
}