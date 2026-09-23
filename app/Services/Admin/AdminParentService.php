<?php

namespace App\Services\Admin;

use App\Models\Parent\ParentModel;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

class AdminParentService
{
    protected AdminAuditLogService $auditLogService;
    protected NotificationService $notificationService;

    public function __construct(AdminAuditLogService $auditLogService, NotificationService $notificationService)
    {
        $this->auditLogService = $auditLogService;
        $this->notificationService = $notificationService;
    }

    /**
     * جلب قائمة كافة أولياء الأمور مع بياناتهم وأبنائهم، مع الفلترة والبحث
     */
    public function getParentsList(array $filters): LengthAwarePaginator
    {
        $query = ParentModel::with(['defaultAddress', 'children.school'])
            ->withCount('children');

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page'])
            ? (int) min($filters['per_page'], 100)
            : 15;

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }

    /**
     * جلب تفاصيل ولي أمر محدد بالكامل مع أبنائه وعناوينه
     */
    public function getParentDetails(int $id): ParentModel
    {
        $parent = ParentModel::with(['defaultAddress', 'addresses', 'children.school'])
            ->withCount('children')
            ->find($id);

        if (!$parent) {
            throw new Exception('عذراً، ولي الأمر المطلوب غير موجود في النظام.');
        }

        return $parent;
    }

    /**
     * إيقاف حساب ولي الأمر (تجميد الدخول)
     */
    public function suspendParent(int $id, ?string $reason, int $adminId): ParentModel
    {
        return DB::transaction(function () use ($id, $reason, $adminId) {
            $parent = ParentModel::lockForUpdate()->find($id);

            if (!$parent) {
                throw new Exception('عذراً، ولي الأمر المطلوب غير موجود في النظام.');
            }

            $parent->update(['is_active' => false]);

            $this->auditLogService->record(
                action: 'suspend_parent',
                entityType: 'parent',
                entityId: $parent->id,
                entityName: $parent->full_name,
                result: 'suspended',
                reason: $reason,
                changes: [],
                adminId: $adminId
            );

            try {
                $this->notificationService->sendToUser($parent, 'parent_suspended', [
                    'title'       => '⛔ تم إيقاف حسابك مؤقتاً',
                    'message'     => $reason ? "تم إيقاف حسابك من قبل الإدارة للسبب التالي: {$reason}" : 'تم إيقاف حسابك من قبل إدارة النظام، يرجى التواصل مع الدعم.',
                    'entity_type' => 'parent',
                    'entity_id'   => (string) $parent->id,
                    'screen'      => 'PARENT_PROFILE',
                ]);
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار إيقاف حساب ولي الأمر #{$parent->id}: " . $e->getMessage());
            }

            return $parent->fresh();
        });
    }

    /**
     * إعادة تفعيل حساب ولي الأمر الموقوف
     */
    public function activateParent(int $id, int $adminId): ParentModel
    {
        return DB::transaction(function () use ($id, $adminId) {
            $parent = ParentModel::lockForUpdate()->find($id);

            if (!$parent) {
                throw new Exception('عذراً، ولي الأمر المطلوب غير موجود في النظام.');
            }

            $parent->update(['is_active' => true]);

            $this->auditLogService->record(
                action: 'activate_parent',
                entityType: 'parent',
                entityId: $parent->id,
                entityName: $parent->full_name,
                result: 'activated',
                reason: null,
                changes: [],
                adminId: $adminId
            );

            try {
                $this->notificationService->sendToUser($parent, 'parent_activated', [
                    'title'       => '✅ تم إعادة تفعيل حسابك',
                    'message'     => 'تمت إعادة تفعيل حسابك من قبل إدارة النظام، يمكنك الآن استخدام التطبيق بشكل طبيعي.',
                    'entity_type' => 'parent',
                    'entity_id'   => (string) $parent->id,
                    'screen'      => 'PARENT_PROFILE',
                ]);
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار تفعيل حساب ولي الأمر #{$parent->id}: " . $e->getMessage());
            }

            return $parent->fresh();
        });
    }
}
