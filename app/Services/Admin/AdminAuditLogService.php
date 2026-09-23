<?php

namespace App\Services\Admin;

use App\Models\Admin\Admin;
use App\Models\Admin\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminAuditLogService
{
    /**
     * الحقول الحساسة الممنوع تخزين قيمها في السجل
     */
    protected static array $sensitiveFields = [
        'password',
        'password_hash',
        'password_confirmation',
        'remember_token',
        'otp',
        'otp_code',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'fcm_device_token',
        'fcm_token',
        'updated_at',
        'created_at',
    ];

    /**
     * قاموس ترجمة أسماء الحقول إلى نصوص عربية مفهومة
     */
    protected static array $fieldLabels = [
        'full_name'        => 'الاسم الكامل',
        'name'             => 'الاسم',
        'email'            => 'البريد الإلكتروني',
        'phone_number'     => 'رقم الهاتف',
        'alternative_phone'=> 'رقم الهاتف البديل',
        'phone'            => 'رقم الهاتف',
        'national_id'      => 'الرقم الوطني',
        'license_number'   => 'رقم الرخصة',
        'license_expiry'   => 'تاريخ انتهاء الرخصة',
        'birth_date'       => 'تاريخ الميلاد',
        'gender'           => 'الجنس',
        'status'           => 'الحالة',
        'is_active'        => 'حالة التفعيل',
        'role_id'          => 'الدور الوظيفي',
        'address'          => 'العنوان',
        'address_text'     => 'نص العنوان',
        'zone_id'          => 'المنطقة',
        'municipality_id'  => 'البلدية',
        'sub_municipality_id' => 'المحلة',
        'lat'              => 'خط العرض',
        'lng'              => 'خط الطول',
        'price'            => 'السعر',
        'amount'           => 'المبلغ',
        'reason'           => 'السبب',
        'notes'            => 'الملاحظات',
    ];

    /**
     * تسجيل إجراء إداري جديد في جدول admin_audit_logs
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $entityName = null,
        ?string $result = null,
        ?string $reason = null,
        array $changes = [],
        ?int $adminId = null,
        ?string $adminName = null,
        ?string $adminRole = null
    ): AdminAuditLog {
        // تحديد هوية المشرف المنفذ للعملية
        if ($adminId === null) {
            $user = Auth::user();
            if ($user) {
                $admin = Admin::find($user->id);
                $adminId   = $admin?->id ?? $user->id;
                $adminName = $adminName ?? $user->full_name ?? ('مستخدم #' . $user->id);
                $adminRole = $adminRole ?? self::resolveRoleLabel($user->role_id ?? null);
            } else {
                $adminId   = 1;
                $adminName = $adminName ?? 'النظام الآلي';
                $adminRole = $adminRole ?? 'النظام';
            }
        } else {
            if (!$adminName) {
                $admin = Admin::with('user')->find($adminId);
                $adminName = $admin?->user?->full_name ?? ('مشرف #' . $adminId);
                $adminRole = $adminRole ?? self::resolveRoleLabel($admin?->user?->role_id ?? null);
            }
        }

        return AdminAuditLog::create([
            'admin_id'    => $adminId,
            'admin_name'  => $adminName,
            'admin_role'  => $adminRole ?? 'مشرف',
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'entity_name' => $entityName,
            'result'      => $result,
            'reason'      => $reason,
            'changes'     => $changes,
            'created_at'  => now(),
        ]);
    }

    /**
     * الاسم المعروض للدور كما هو مسجّل في جدول roles.
     *
     * ⚠️ كان السجل يكتب 'مشرف' لكل الأدوار الإشرافية الستة (العمليات، الأسطول،
     * الدعم، المالية، الجغرافيا...)، فيفقد سجل التدقيق أهم معلومة فيه: أي مشرف
     * نفّذ الإجراء بالضبط. الآن يُسجَّل الاسم الحقيقي للدور.
     */
    protected static function resolveRoleLabel(?int $roleId): string
    {
        if ($roleId === null) {
            return 'مشرف';
        }

        $displayName = \Illuminate\Support\Facades\DB::table('roles')
            ->where('id', $roleId)
            ->value('display_name');

        if ($displayName) {
            return (string) $displayName;
        }

        return ((int) $roleId === 1) ? 'مدير النظام' : 'مشرف';
    }

    /**
     * حساب مصفوفة الفروقات بين القيم القديمة والجديدة
     */
    public function diff(array $oldData, array $newData, array $customLabels = []): array
    {
        $changes = [];
        $labels = array_merge(self::$fieldLabels, $customLabels);

        foreach ($newData as $key => $newValue) {
            if (in_array($key, self::$sensitiveFields, true)) {
                // في حالة تغيير كلمة مرور أو بيانات حساسة، نسجل حدوث التغيير دون كشف القيمة
                if ($newValue !== null) {
                    $changes[] = [
                        'field'     => $key,
                        'label'     => $labels[$key] ?? $key,
                        'old_value' => null,
                        'new_value' => null,
                    ];
                }
                continue;
            }

            if (!array_key_exists($key, $oldData)) {
                continue;
            }

            $oldValue = $oldData[$key];

            // تحويل الأنواع للمقارنة النصية الدقيقة
            $oldFormatted = $this->formatValue($oldValue);
            $newFormatted = $this->formatValue($newValue);

            if ($oldFormatted !== $newFormatted) {
                $changes[] = [
                    'field'     => $key,
                    'label'     => $labels[$key] ?? $key,
                    'old_value' => $oldFormatted,
                    'new_value' => $newFormatted,
                ];
            }
        }

        return $changes;
    }

    /**
     * توليد مصفوفة تغييرات لعملية إنشاء جديدة (old_value = null)
     */
    public function buildCreatedChanges(array $data, array $customLabels = []): array
    {
        $changes = [];
        $labels = array_merge(self::$fieldLabels, $customLabels);

        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveFields, true) || $value === null) {
                continue;
            }

            $changes[] = [
                'field'     => $key,
                'label'     => $labels[$key] ?? $key,
                'old_value' => null,
                'new_value' => $this->formatValue($value),
            ];
        }

        return $changes;
    }

    /**
     * توليد مصفوفة تغييرات لعملية حذف (new_value = null)
     */
    public function buildDeletedChanges(array $data, array $customLabels = []): array
    {
        $changes = [];
        $labels = array_merge(self::$fieldLabels, $customLabels);

        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveFields, true) || $value === null) {
                continue;
            }

            $changes[] = [
                'field'     => $key,
                'label'     => $labels[$key] ?? $key,
                'old_value' => $this->formatValue($value),
                'new_value' => null,
            ];
        }

        return $changes;
    }

    private function formatValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}
