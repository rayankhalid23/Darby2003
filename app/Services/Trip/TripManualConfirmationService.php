<?php

namespace App\Services\Trip;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\ParentModel;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Trip;
use App\Models\Shared\TripManualConfirmation;
use App\Models\Shared\TripStop;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

/**
 * يغطي الحالة التي ينسى فيها السائق (أو يتعطل تطبيقه) توثيق استلام/تسليم طفل في رحلة
 * سابقة، فيتيح للسائق اختيار الرحلة والأطفال المعنيين، ويرسل النظام سؤال تأكيد لولي
 * الأمر قبل تغيير حالة الطفل في تلك الرحلة — لا يُغيَّر شيء إلا بموافقة صريحة من ولي الأمر.
 */
class TripManualConfirmationService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * جميع أولياء الأمور والأطفال المشتركين (اشتراك نشط أو سبق تفعيله) مع هذا السائق.
     */
    public function getSubscribedParentsAndChildren(int $driverUserId)
    {
        $driver = Driver::where('user_id', $driverUserId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        return ActiveSubscription::forDriver($driver->id)
            ->whereIn('status', ['active', 'completed'])
            ->with(['child:id,full_name,photo_url,parent_id', 'parent:id,full_name,phone_number'])
            ->get()
            ->map(fn (ActiveSubscription $sub) => [
                'active_subscription_id' => $sub->id,
                'child' => [
                    'id'   => $sub->child?->id,
                    'name' => $sub->child?->full_name,
                    'photo_url' => $sub->child?->photo_url,
                ],
                'parent' => [
                    'user_id' => $sub->parent?->id,
                    'name'    => $sub->parent?->full_name,
                    'phone'   => $sub->parent?->phone_number,
                ],
            ])
            ->values();
    }

    /**
     * الرحلات السابقة التي لم تُنفَّذ/تُغلق بشكل صحيح (تاريخها فات وحالتها ليست مكتملة).
     * ملاحظة: لا توجد حالياً حالة "cancelled" فعلية ضمن enum جدول trips (pending/in_progress/
     * completed/suspended_breakdown فقط)، لذا استُبعدت من الفلتر لتفادي الإيحاء بميزة غير موجودة.
     */
    public function getIncompleteTrips(int $driverUserId)
    {
        $driver = Driver::where('user_id', $driverUserId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        return Trip::where('driver_id', $driver->id)
            ->where('trip_date', '<', now()->toDateString())
            ->where('status', '!=', 'completed')
            ->with('route:id,route_name')
            ->orderByDesc('trip_date')
            ->get()
            ->map(function (Trip $trip) {
                $pendingCount = TripStop::where('trip_id', $trip->id)
                    ->where('stop_type', TripStop::TYPE_HOME)
                    ->whereIn('status', TripStop::NON_FINAL_STATUSES)
                    ->count();

                return [
                    'trip_id'          => $trip->id,
                    'trip_date'        => $trip->trip_date?->format('Y-m-d'),
                    'shift_slot'       => $trip->shift_slot,
                    'route_name'       => $trip->route?->route_name,
                    'status'           => $trip->status,
                    'unconfirmed_count' => $pendingCount,
                ];
            });
    }

    /**
     * أطفال رحلة معيّنة القابلين لطلب تأكيد يدوي (حالتهم غير نهائية بعد).
     */
    public function getTripPendingChildren(int $driverUserId, int $tripId)
    {
        $driver = Driver::where('user_id', $driverUserId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        $trip = Trip::where('id', $tripId)->where('driver_id', $driver->id)->first();
        if (!$trip) {
            throw new Exception('الرحلة غير موجودة أو لا تخص هذا السائق.');
        }

        return TripStop::where('trip_id', $trip->id)
            ->where('stop_type', TripStop::TYPE_HOME)
            ->whereIn('status', TripStop::NON_FINAL_STATUSES)
            ->with('child:id,full_name,photo_url')
            ->get()
            ->map(fn (TripStop $stop) => [
                'trip_stop_id' => $stop->id,
                'child'        => ['id' => $stop->child?->id, 'name' => $stop->child?->full_name],
                'current_status' => $stop->status,
                'has_pending_confirmation' => TripManualConfirmation::where('trip_stop_id', $stop->id)
                    ->where('status', TripManualConfirmation::STATUS_PENDING)
                    ->exists(),
            ])
            ->values();
    }

    /**
     * ينشئ طلبات تأكيد لكل طفل مختار في رحلة معيّنة، ويرسل إشعاراً لولي أمر كل طفل.
     *
     * @return TripManualConfirmation[]
     */
    public function requestConfirmations(int $driverUserId, int $tripId, array $childIds): array
    {
        $driver = Driver::where('user_id', $driverUserId)->first();
        if (!$driver) {
            throw new Exception('هذا الحساب غير مسجل كسائق في النظام.');
        }

        $trip = Trip::where('id', $tripId)->where('driver_id', $driver->id)->first();
        if (!$trip) {
            throw new Exception('الرحلة غير موجودة أو لا تخص هذا السائق.');
        }

        $isGoTrip = DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
            || (!$trip->shift_slot && $trip->trip_type === 'Morning');

        $finalStatus = $isGoTrip ? TripStop::STATUS_DROPPED_OFF_SCHOOL : TripStop::STATUS_DELIVERED_HOME;

        $created = [];

        foreach ($childIds as $childId) {
            $stop = TripStop::where('trip_id', $trip->id)
                ->where('child_id', $childId)
                ->where('stop_type', TripStop::TYPE_HOME)
                ->first();

            if (!$stop || !in_array($stop->status, TripStop::NON_FINAL_STATUSES, true)) {
                // لا توجد محطة لهذا الطفل في هذه الرحلة، أو حالته موثّقة بالفعل — تخطٍ آمن.
                continue;
            }

            $sub = ActiveSubscription::forDriver($driver->id)
                ->forChild($childId)
                ->whereIn('status', ['active', 'completed'])
                ->first();

            if (!$sub || !$sub->parent_id) {
                continue;
            }

            // إذا كانت الحالة الحالية "boarded" فالطفل صعد فعلاً وينقصه توثيق التسليم فقط.
            $questionType = $stop->status === TripStop::STATUS_BOARDED
                ? TripManualConfirmation::QUESTION_DROPOFF
                : ($isGoTrip ? TripManualConfirmation::QUESTION_DROPOFF : TripManualConfirmation::QUESTION_PICKUP);

            $confirmation = DB::transaction(function () use ($trip, $stop, $childId, $sub, $questionType, $finalStatus) {
                $existing = TripManualConfirmation::where('trip_stop_id', $stop->id)
                    ->where('status', TripManualConfirmation::STATUS_PENDING)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                return TripManualConfirmation::create([
                    'trip_id'       => $trip->id,
                    'trip_stop_id'  => $stop->id,
                    'child_id'      => $childId,
                    'parent_id'     => $sub->parent_id,
                    'driver_id'     => $sub->driver_id,
                    'question_type' => $questionType,
                    'target_status' => $finalStatus,
                    'status'        => TripManualConfirmation::STATUS_PENDING,
                ]);
            });

            $confirmation->loadMissing(['child', 'parent']);

            try {
                $parentUser = $confirmation->parent;
                if ($parentUser) {
                    $childName = $confirmation->child?->full_name ?? 'الطفل';
                    $dateLabel = $trip->trip_date?->format('Y-m-d') ?? '';
                    $actionLabel = $questionType === TripManualConfirmation::QUESTION_PICKUP
                        ? 'اصطحاب الطفل من المنزل'
                        : ($isGoTrip ? 'توصيل الطفل إلى المدرسة' : 'توصيل الطفل إلى المنزل');

                    $this->notifyUser(
                        $parentUser,
                        'يرجى التأكيد 🙏',
                        "بخصوص رحلة يوم {$dateLabel}: هل قام السائق بـ ({$actionLabel}) للطفل ({$childName})؟ يرجى التأكيد.",
                        'trip_manual_confirmation_request',
                        (string) $confirmation->id,
                        ['child_name' => $childName]
                    );
                }
            } catch (Throwable $e) {
                Log::warning("فشل إرسال إشعار طلب التأكيد اليدوي ID {$confirmation->id}: " . $e->getMessage());
            }

            $created[] = $confirmation;
        }

        return $created;
    }

    /**
     * رد ولي الأمر على طلب التأكيد اليدوي: تأكيد أو نفي.
     */
    public function respondToConfirmation(int $parentUserId, int $confirmationId, bool $confirmed): TripManualConfirmation
    {
        $confirmation = TripManualConfirmation::where('id', $confirmationId)
            ->where('parent_id', $parentUserId)
            ->first();

        if (!$confirmation) {
            throw new Exception('طلب التأكيد غير موجود أو لا يخص طفلك.');
        }

        if ($confirmation->status !== TripManualConfirmation::STATUS_PENDING) {
            throw new Exception('تم الرد على هذا الطلب مسبقاً.');
        }

        return DB::transaction(function () use ($confirmation, $confirmed) {
            if ($confirmed) {
                TripStop::where('id', $confirmation->trip_stop_id)
                    ->update(['status' => $confirmation->target_status]);

                $confirmation->update([
                    'status'       => TripManualConfirmation::STATUS_CONFIRMED,
                    'responded_at' => now(),
                ]);
            } else {
                $confirmation->update([
                    'status'       => TripManualConfirmation::STATUS_DENIED,
                    'responded_at' => now(),
                ]);
            }

            $confirmation->loadMissing(['child', 'driver.user']);

            try {
                $driverUser = $confirmation->driver?->user;
                if ($driverUser) {
                    $childName = $confirmation->child?->full_name ?? 'الطفل';
                    if ($confirmed) {
                        $this->notifyUser(
                            $driverUser,
                            'تم تأكيد ولي الأمر ✅',
                            "أكّد ولي أمر الطفل ({$childName}) إتمام الرحلة، وتم تحديث حالتها.",
                            'trip_manual_confirmation_confirmed',
                            (string) $confirmation->id,
                            ['child_name' => $childName]
                        );
                    } else {
                        $this->notifyUser(
                            $driverUser,
                            'لم يتم التأكيد ⚠️',
                            "لم يؤكد ولي أمر الطفل ({$childName}) إتمام الرحلة. يرجى المتابعة معه مباشرة.",
                            'trip_manual_confirmation_denied',
                            (string) $confirmation->id,
                            ['child_name' => $childName]
                        );
                    }
                }
            } catch (Throwable $e) {
                Log::warning("فشل إرسال إشعار الرد على التأكيد اليدوي ID {$confirmation->id}: " . $e->getMessage());
            }

            return $confirmation->fresh(['child', 'driver.user', 'trip']);
        });
    }

    public function getParentPendingConfirmations(int $parentUserId)
    {
        return TripManualConfirmation::where('parent_id', $parentUserId)
            ->where('status', TripManualConfirmation::STATUS_PENDING)
            ->with(['child', 'trip'])
            ->orderByDesc('id')
            ->get();
    }

    private function notifyUser($user, string $title, string $message, string $type, ?string $entityId = null, array $extra = []): void
    {
        if ($user) {
            $this->notificationService->sendToUser($user, $type, array_merge([
                'title'       => $title,
                'message'     => $message,
                'entity_type' => 'trip_manual_confirmation',
                'entity_id'   => $entityId,
            ], $extra));
        }
    }
}
