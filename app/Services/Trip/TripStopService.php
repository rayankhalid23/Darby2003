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
use Illuminate\Support\Facades\Auth;
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

  /**
     * التحقق من كود الـ QR الخاص باصطحاب الطفل (Pickup) وتوثيق الصعود مع حساب التكلفة ديناميكياً.
     *
     * @param int $tripId
     * @param int $childId
     * @param string $qrCode
     * @param float|null $driverLat
     * @param float|null $driverLng
     * @return bool
     * @throws \Exception
     */
  public function verifyPickupQR($tripId, $childId, $qrCode, $driverLat, $driverLng): bool
  {
      // 1. جلب بيانات الاشتراك النشط من جدول active_subscriptions
      $subscription = \App\Models\Shared\ActiveSubscription::forChild($childId)
          ->forDriver(\Illuminate\Support\Facades\Auth::user()->driver->id)
          ->where('status', 'active')
          ->first();

      if (!$subscription) {
          throw new \Exception("أمن النظام: لا يوجد اشتراك نشط يربطك بهذا الطفل أو هذه الرحلة!");
      }

      // 2. التحقق من تطابق كود الـ QR
      if ($subscription->child->qr_code_token !== $qrCode) {
          throw new \Exception("كود الـ QR غير متطابق مع هذا الطفل.");
      }

      // 3. جلب بيانات الرحلة الحالية
      $trip = \App\Models\Shared\Trip::findOrFail($tripId);
      
      // تنفيذ عملية الحفظ داخل عملية مالية موحدة (Database Transaction)
      \Illuminate\Support\Facades\DB::transaction(function () use ($trip, $childId, $driverLat, $driverLng, $subscription) {
            
      // 1. تأمين وحماية إحداثيات الـ GPS
      $lat = $driverLat ?? request('latitude') ?? request('lat');
      $lng = $driverLng ?? request('longitude') ?? request('lng');

      if (is_null($lat) || is_null($lng)) {
          throw new \Exception("فشل توثيق الحدث: لم يتم التقاط إحداثيات الموقع الجغرافي (GPS) بشكل صحيح.");
      }

      // 2. جلب بيانات الطلب والتسعير ديناميكياً مباشرة من جدول active_subscriptions الفعلي لديك
      $requestChild = \Illuminate\Support\Facades\DB::table('request_children')
          ->join('requests', 'requests.id', '=', 'request_children.request_id')
          ->where('requests.id', $subscription->contract_id) 
          ->where('request_children.child_id', $childId)
          ->select(
              'request_children.price_per_child', 
              'requests.subscription_type', 
              'requests.direction', 
              'requests.days_count'
          )
          ->first();

      $tripCost = 0;

      if ($requestChild) {
          $basePrice = (float) $requestChild->price_per_child;
          $subType   = strtolower($requestChild->subscription_type);
          $direction = strtolower($requestChild->direction);
          $daysCount = (int) ($requestChild->days_count ?? 1);
          $daysCount = $daysCount > 0 ? $daysCount : 1; 

          if ($subType === 'monthly' || $subType === 'multi_day' || $subType === 'شهري') {
              $dailyCost = $basePrice / $daysCount;
              if ($direction === 'round_trip' || $direction === 'ذهاب وإياب') {
                  $tripCost = $dailyCost / 2;
              } else {
                  $tripCost = $dailyCost;
              }
          } else {
              if ($direction === 'round_trip' || $direction === 'ذهاب وإياب') {
                  $tripCost = $basePrice / 2;
              } else {
                  $tripCost = $basePrice;
              }
          }
      }

      // 3. إدخال الحدث برقم معرف الـ active_subscriptions مباشرة وبنجاح
      \App\Models\Shared\TripEvent::create([
          'trip_id'         => $trip->id,
          'child_id'        => $childId,
          'subscription_id' => $subscription->id, // الاعتماد المباشر على جدولك
          'action_type'     => 'picked_up',
          'location_lat'    => $lat, 
          'location_lng'    => $lng, 
          'scanned_at'      => \Illuminate\Support\Carbon::now(),
          'trip_cost'       => round($tripCost, 2),
      ]);
  });

      // 5. تنظيف كاش الانتظار الخاص بالطفل ليعلم النظام بأنه داخل الحافلة
      \Illuminate\Support\Facades\Cache::forget("trip_waiting_{$tripId}_{$childId}");

      // 6. إرسال إشعار فوري لولي الأمر بصعود طفله بسلام
      if (isset($subscription->child->parent->user_id)) {
          $parentUser = \App\Models\User::find($subscription->child->parent->user_id);
          if ($parentUser) {
              $this->notificationService->sendToUser($parentUser, 'child_picked_up', [
                  'title'      => 'صعد طفلك الحافلة بسلام 🚌✨',
                  'message'    => "تم مسح الـ QR بنجاح للطفل: {$subscription->child->full_name}.",
                  'child_name' => $subscription->child->full_name,
                  'trip_id'    => (string) $trip->id,
                  'entity_id'  => $trip->id . '_' . $childId,
              ]);
          }
      }

      return true;
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