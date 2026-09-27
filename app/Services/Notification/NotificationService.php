<?php

namespace App\Services\Notification;

use App\Jobs\SendFcmNotificationJob;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * نقطة الدخول الوحيدة لإرسال الإشعارات من الـ Business Logic.
 *
 * مسؤولياتها فقط: بناء الحمولة الموحدة عبر NotificationFormatter، إنشاء صف
 * database notification فوراً (بدون انتظار Firebase)، ثم توزيع Push Job منفصل
 * عبر الـ Queue. لا تتعامل مع Firebase مباشرة إطلاقاً — ذلك من مسؤولية
 * FcmService عبر SendFcmNotificationJob فقط.
 */
class NotificationService
{
    /**
     * إرسال إشعار لمستخدم واحد.
     *
     * @param  bool  $withPush  false لكتابة صف database فقط بدون إطلاق SendFcmNotificationJob.
     *                          القيمة الافتراضية true تُطلق الـ Push لجميع أجهزة المستخدم النشطة عبر Firebase.
     * @return string|null معرّف الإشعار (UUID)، أو null إذا تم تجاهله لتكراره (idempotency)
     */
    public function sendToUser(User $user, string $type, array $data = [], bool $withPush = true): ?string
    {
        $result = $this->sendToUsers([$user], $type, $data, $withPush);

        return $result[$user->id] ?? null;
    }

    /**
     * إرسال نفس الإشعار (بنفس النوع والبيانات) لعدة مستخدمين دفعة واحدة.
     * كل مستخدم يحصل على صف database notification منفصل ومعرّف UUID خاص به.
     *
     * منع التكرار (idempotency) يعتمد على قيد UNIQUE حقيقي على عمود dedupe_key في قاعدة
     * البيانات (وليس فحص SELECT قبل INSERT فقط، الذي يبقى عرضة لـ race condition بين
     * طلبين متزامنين). المحاولة الثانية لنفس (event, entity, recipient) تصطدم بقيد UNIQUE
     * وتُعامَل كتكرار متوقع، لا كخطأ.
     *
     * @param  iterable<User>  $users
     * @param  bool  $withPush  false لتعطيل توزيع push job لكل المستخدمين في هذه الدفعة.
     * @return array<int, string> معرّفات الإشعارات مفهرسة بمعرّف المستخدم (تُستثنى الحالات المكررة)
     */
    public function sendToUsers(iterable $users, string $type, array $data = [], bool $withPush = true): array
    {
        $formatted = NotificationFormatter::format($type, $data);

        $ids = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $dedupeKey = $this->buildDedupeKey($type, $formatted['entity_type'], $formatted['entity_id'], $user->id);
            $id = (string) Str::uuid();

            $dbPayload = [
                'title'           => $formatted['title'],
                'message'         => $formatted['message'],
                'type'            => $formatted['type'],
                'action_url'      => $formatted['action_url'],
                'entity_type'     => $formatted['entity_type'],
                'entity_id'       => $formatted['entity_id'],
                'screen'          => $formatted['screen'],
                'action'          => $formatted['action'],
                'payload'         => array_merge($formatted['payload'], ['idempotency_key' => $dedupeKey]),
                'idempotency_key' => $dedupeKey,
            ];

            try {
                $user->notifications()->create([
                    'id'          => $id,
                    'type'        => 'App\\Notifications\\SystemNotification',
                    'data'        => $dbPayload,
                    'dedupe_key'  => $dedupeKey,
                    'read_at'     => null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // نفس (event, entity, recipient) موجود مسبقاً — تكرار متوقع (طلب مكرر،
                // إعادة محاولة، أو سباق بين طلبين متزامنين). ليس خطأ، فقط تجاهل بصمت.
                continue;
            }

            if ($withPush) {
                SendFcmNotificationJob::dispatch($id, $user->id)->afterCommit();
            }

            $ids[$user->id] = $id;
        }

        return $ids;
    }

    /**
     * مفتاح dedupe: نوع الحدث + نوع/معرّف الكيان + المستقبل.
     * كافٍ لمنع التكرار غير المرغوب (retry/طلب مكرر) دون منع تكرارات مشروعة
     * (مثال: trip_upcoming اليوم له entity_id مختلف عن غداً لأنه لرحلة مختلفة).
     */
    protected function buildDedupeKey(string $type, ?string $entityType, mixed $entityId, int $userId): string
    {
        return md5($type . ':' . ($entityType ?? '') . ':' . ($entityId ?? '') . ':' . $userId);
    }
}
