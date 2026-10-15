<?php

namespace App\Services\Driver;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Models\User;
use App\Services\Notification\NotificationFormatter;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DriverInactivityAlertService
{
    /**
     * النافذة الزمنية لاحتساب عدد مرات الرفض (بالأيام)
     */
    public const WINDOW_DAYS = 7;

    /**
     * عتبة عدد الطلبات المرفوضة التي تُطلق التنبيه
     */
    public const REJECTION_THRESHOLD = 3;

    /**
     * فترة التهدئة لمنع إغراق السائق والإدارة بالتنبيهات (بالساعات)
     */
    public const COOLDOWN_HOURS = 48;

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * فحص سلوك رفض السائق وإرسال التنبيهات اللازمة إذا كان غير نشط ويرفض بكثرة.
     *
     * @param Driver $driver
     * @return array{alerted: bool, reason?: string, rejections_count?: int, alert_id?: int}
     */
    public function checkAndAlertOnExcessiveRejections(Driver $driver): array
    {
        // 1. شرط "ما يخدمش" (Inactivity): التحقق من عدم وجود أي اشتراك نشط سارٍ للسائق
        $hasActiveSubscriptions = \Illuminate\Support\Facades\DB::table('active_subscriptions as a')
            ->join('requests as r', 'r.id', '=', 'a.subscription_request_id')
            ->where('r.driver_id', $driver->id)
            ->where('a.status', 'active')
            ->exists();

        if ($hasActiveSubscriptions) {
            return [
                'alerted' => false,
                'reason'  => 'driver_has_active_subscriptions',
            ];
        }

        // 2. شرط عتبة الرفض في النافذة الزمنية (Sliding Window Rejection Count)
        $windowStart = Carbon::now()->subDays(self::WINDOW_DAYS);

        $rejectionsCount = SubscriptionRequest::where('driver_id', $driver->id)
            ->where('status', SubscriptionRequest::STATUS_REJECTED)
            ->where(function ($q) use ($windowStart) {
                $q->where('responded_at', '>=', $windowStart)
                  ->orWhere('updated_at', '>=', $windowStart)
                  ->orWhere('created_at', '>=', $windowStart);
            })
            ->count();

        if ($rejectionsCount < self::REJECTION_THRESHOLD) {
            return [
                'alerted'          => false,
                'reason'           => 'threshold_not_reached',
                'rejections_count' => $rejectionsCount,
            ];
        }

        // 3. شرط منع التكرار (Cooldown Period): فحص ما إذا تم إطلاق تنبيه مؤخراً خلال فترة التهدئة
        $cooldownStart = Carbon::now()->subHours(self::COOLDOWN_HOURS);

        $recentAlert = AdminAlert::where('driver_id', $driver->id)
            ->where('alert_type', 'driver_inactivity_rejections')
            ->where('created_at', '>=', $cooldownStart)
            ->first();

        if ($recentAlert) {
            return [
                'alerted'          => false,
                'reason'           => 'cooldown_active',
                'rejections_count' => $rejectionsCount,
            ];
        }

        // 4. تنفيذ التنبيهات دون تغيير أي حالة للسائق (كما طُلب بدون auto-snooze)
        $driverUser = $driver->user ?? User::find($driver->user_id);
        $driverName = $driverUser?->name ?? "سائق #{$driver->id}";
        $driverPhone = $driverUser?->phone_number ?? $driverUser?->phone ?? 'غير متوفر';

        // أ) إنشاء سجل AdminAlert في قاعدة البيانات للإدارة
        $alertTitle = '⚠️ سائق غير نشط يرفض الطلبات المتكررة';
        $adminMessage = "السائق ({$driverName}) قام برفض {$rejectionsCount} طلبات خلال آخر " . self::WINDOW_DAYS . " أيام دون وجود أي اشتراك نشط لديه.";

        $adminAlert = AdminAlert::create([
            'driver_id'       => $driver->id,
            'risk_level'      => 'WARNING',
            'alert_type'      => 'driver_inactivity_rejections',
            'severity'        => 2,
            'title'           => $alertTitle,
            'message'         => $adminMessage,
            'admin_message'   => $adminMessage,
            'action_required' => 'review',
            'is_read'         => false,
            'is_resolved'     => false,
            'metadata'        => [
                'rejections_count'     => $rejectionsCount,
                'window_days'          => self::WINDOW_DAYS,
                'has_active_subs'      => false,
                'driver_name'          => $driverName,
                'driver_phone'         => $driverPhone,
                'triggered_at'         => Carbon::now()->toIso8601String(),
            ],
        ]);

        // ب) إرسال إشعار للإدارة (Users with role_id in [1, 2])
        try {
            $admins = User::whereIn('role_id', [1, 2])
                ->where('is_active', true)
                ->get();

            if ($admins->isNotEmpty()) {
                $this->notificationService->sendToUsers(
                    $admins,
                    NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ADMIN,
                    [
                        'title'       => $alertTitle,
                        'message'     => $adminMessage,
                        'driver_name' => $driverName,
                        'entity_id'   => $driver->id,
                        'entity_type' => 'driver',
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("DriverInactivityAlertService: Failed notifying admins: " . $e->getMessage());
        }

        // ج) إرسال إشعار للسائق نفسه (توجيهي / تنبيه)
        if ($driverUser) {
            try {
                $driverTitle = 'تنبيه بخصوص استقبال الطلبات ⚠️';
                $driverNoticeMessage = "لاحظنا رفضك لـ {$rejectionsCount} طلبات مؤخراً دون وجود اشتراكات نشطة في حسابك. إذا كنت غير متفرغ للنقل المدرسي حالياً، يرجى تحديث حالة التوفر لديك لتجنب التأثير على تقييمك.";

                $this->notificationService->sendToUser(
                    $driverUser,
                    NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ALERT,
                    [
                        'title'       => $driverTitle,
                        'message'     => $driverNoticeMessage,
                        'driver_name' => $driverName,
                        'entity_id'   => $driver->id,
                        'entity_type' => 'driver',
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("DriverInactivityAlertService: Failed notifying driver #{$driver->id}: " . $e->getMessage());
            }
        }

        Log::info("DriverInactivityAlertService: Triggered alert for driver #{$driver->id} ({$rejectionsCount} rejections in " . self::WINDOW_DAYS . " days).");

        return [
            'alerted'          => true,
            'rejections_count' => $rejectionsCount,
            'alert_id'         => $adminAlert->id,
        ];
    }
}
