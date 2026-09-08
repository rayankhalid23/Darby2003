<?php

namespace Database\Seeders\V2;

use App\Constants\PermissionConstants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * تغذية نواة الأدوار والصلاحيات في قاعدة school_transport_v2.
 *
 * المصادر:
 *   - صلاحيات الإدارة (28): من PermissionConstants::getPermissionsTree().
 *     الصنف يبقى مصدر المبرمج (إكمال تلقائي وكشف الخطأ الإملائي وقت الكتابة)
 *     وهذا الجدول مصدر وقت التشغيل، وهذا السيدر هو ما يبقيهما متطابقين.
 *   - صلاحيات التطبيقات (13): معرّفة هنا لأنها لم تكن في الصنف أصلاً،
 *     بل كانت محشورة في roles.permissions ككائن JSON متداخل لا يقرؤه أي كود.
 *   - الإسنادات: منقولة حرفياً من التوزيع الفعلي في school_transport_db.
 *
 * قابل لإعادة التشغيل بأمان: كل الكتابات upsert على المفاتيح الفريدة.
 *
 *   php artisan db:seed --class="Database\Seeders\V2\RbacSeeder"
 */
class RbacSeeder extends Seeder
{
    /** صلاحيات تطبيقَي ولي الأمر والسائق - غير موجودة في PermissionConstants */
    private const APP_PERMISSIONS = [
        ['key' => 'trips.track_live',      'group_key' => 'parent_app', 'name_ar' => 'تتبع الرحلة مباشرة',       'description_ar' => 'متابعة موقع الحافلة على الخريطة أثناء الرحلة'],
        ['key' => 'children.create',       'group_key' => 'parent_app', 'name_ar' => 'إضافة طفل',                'description_ar' => 'تسجيل طفل جديد تحت حساب ولي الأمر'],
        ['key' => 'children.update',       'group_key' => 'parent_app', 'name_ar' => 'تعديل بيانات طفل',         'description_ar' => 'تحديث بيانات الطفل ومدرسته وعنوانه'],
        ['key' => 'subscriptions.request', 'group_key' => 'parent_app', 'name_ar' => 'إنشاء طلب اشتراك',         'description_ar' => 'إرسال طلب اشتراك إلى سائق'],
        ['key' => 'subscriptions.cancel',  'group_key' => 'parent_app', 'name_ar' => 'إلغاء اشتراك',             'description_ar' => 'إنهاء اشتراك قائم قبل تاريخه'],
        ['key' => 'wallet.view',           'group_key' => 'parent_app', 'name_ar' => 'عرض رصيد المحفظة',         'description_ar' => 'الاطلاع على الرصيد وكشف الحساب'],
        ['key' => 'wallet.recharge',       'group_key' => 'parent_app', 'name_ar' => 'شحن المحفظة',              'description_ar' => 'إنشاء طلب شحن رصيد'],

        ['key' => 'trips.start',           'group_key' => 'driver_app', 'name_ar' => 'بدء الرحلة',               'description_ar' => 'تشغيل رحلة اليوم وبدء التتبع'],
        ['key' => 'trips.complete',        'group_key' => 'driver_app', 'name_ar' => 'إنهاء الرحلة',             'description_ar' => 'إغلاق الرحلة بعد تسليم آخر طفل'],
        ['key' => 'trips.update_location', 'group_key' => 'driver_app', 'name_ar' => 'تحديث الموقع أثناء الرحلة', 'description_ar' => 'إرسال إحداثيات السائق الحية'],
        ['key' => 'trips.board_child',     'group_key' => 'driver_app', 'name_ar' => 'تسجيل صعود الطفل ونزوله',  'description_ar' => 'تأكيد ركوب الطفل وتسليمه'],
        ['key' => 'routes.manage',         'group_key' => 'driver_app', 'name_ar' => 'إدارة مسارات السائق',      'description_ar' => 'ترتيب نقاط المسار وتعديلها'],
        ['key' => 'wallet.withdraw',       'group_key' => 'driver_app', 'name_ar' => 'طلب سحب رصيد',             'description_ar' => 'إنشاء طلب سحب من المحفظة'],
    ];

    /** الأدوار الثمانية - العمود kind يفصل نوع الحساب عن الدور الإداري */
    private const ROLES = [
        ['name' => 'super_admin',           'display_name' => 'مدير النظام العام',               'kind' => 'staff',   'is_super' => true,  'description' => 'صلاحية مطلقة على كل أجزاء النظام'],
        ['name' => 'operations_supervisor', 'display_name' => 'مشرف العمليات والرادار',           'kind' => 'staff',   'is_super' => false, 'description' => 'متابعة الرحلات الحية وتوليد رحلات اليوم'],
        ['name' => 'fleet_supervisor',      'display_name' => 'مشرف شؤون السائقين والأسطول',      'kind' => 'staff',   'is_super' => false, 'description' => 'اعتماد السائقين ومراجعة تعديلات بياناتهم'],
        ['name' => 'support_supervisor',    'display_name' => 'مشرف خدمة العملاء والشكاوى',       'kind' => 'staff',   'is_super' => false, 'description' => 'معالجة الشكاوى والتقييمات'],
        ['name' => 'finance_officer',       'display_name' => 'المشرف المالي ومسؤول الخزينة',     'kind' => 'staff',   'is_super' => false, 'description' => 'الشحن والسحب والتسويات ودفتر الأستاذ'],
        ['name' => 'geography_supervisor',  'display_name' => 'مشرف المدارس والبيانات الجغرافية',  'kind' => 'staff',   'is_super' => false, 'description' => 'إدارة المدارس والبلديات والمناطق'],
        ['name' => 'parent',                'display_name' => 'ولي أمر',                         'kind' => 'account', 'is_super' => false, 'description' => 'حساب ولي أمر في تطبيق أولياء الأمور'],
        ['name' => 'driver',                'display_name' => 'سائق حافلة/فان',                  'kind' => 'account', 'is_super' => false, 'description' => 'حساب سائق في تطبيق السائقين'],
    ];

    /**
     * الإسنادات كما هي فعلياً في القاعدة القديمة.
     * super_admin غير مذكور هنا: العمود is_super يغنيه عن أي إسناد.
     */
    private const GRANTS = [
        'operations_supervisor' => [
            'dashboard.view_stats', 'dashboard.view_radar', 'trips.generate_daily',
            'trips.emergency_cancel', 'drivers.view', 'schools.manage',
            'geography.manage', 'reports.view',
        ],
        'fleet_supervisor' => [
            'dashboard.view_stats', 'drivers.view', 'drivers.review_initial',
            'drivers.review_changes', 'drivers.edit_data', 'drivers.suspend',
            'driver_reviews.manage', 'reports.view',
        ],
        'support_supervisor' => [
            'dashboard.view_stats', 'complaints.view', 'complaints.resolve',
            'driver_reviews.manage', 'drivers.view', 'notifications.broadcast',
            'content.manage_terms',
        ],
        'finance_officer' => [
            'dashboard.view_stats', 'financial.view_summary', 'financial.view_ledger',
            'financial.manage_withdrawals', 'financial.manage_recharges',
            'financial.release_escrows', 'financial.resolve_disputes',
            'financial.manage_settlements', 'financial.manage_payment_methods',
            'financial.manage_pricing', 'reports.view', 'reports.export',
        ],
        'geography_supervisor' => [
            'dashboard.view_stats', 'schools.manage', 'geography.manage',
        ],
        'parent' => [
            'trips.track_live', 'wallet.recharge', 'wallet.view',
            'children.create', 'children.update',
            'subscriptions.request', 'subscriptions.cancel',
        ],
        'driver' => [
            'trips.start', 'trips.complete', 'trips.update_location',
            'trips.board_child', 'routes.manage', 'wallet.withdraw',
        ],
    ];

    public function run(): void
    {
        $now = now();

        // 1) الصلاحيات
        $rows = [];

        foreach (PermissionConstants::getPermissionsTree() as $group) {
            foreach ($group['permissions'] as $permission) {
                $rows[] = [
                    'key'            => $permission['key'],
                    'group_key'      => $group['group_key'],
                    'name_ar'        => $permission['name'],
                    'description_ar' => $permission['description'] ?? null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        foreach (self::APP_PERMISSIONS as $permission) {
            $rows[] = $permission + ['created_at' => $now, 'updated_at' => $now];
        }

        DB::table('permissions')->upsert(
            $rows,
            ['key'],
            ['group_key', 'name_ar', 'description_ar', 'updated_at']
        );

        // 2) الأدوار
        DB::table('roles')->upsert(
            array_map(
                fn (array $role) => $role + ['created_at' => $now, 'updated_at' => $now],
                self::ROLES
            ),
            ['name'],
            ['display_name', 'kind', 'is_super', 'description', 'updated_at']
        );

        // 3) الربط
        $roleIds       = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'key');
        $pivot         = [];

        foreach (self::GRANTS as $roleName => $keys) {
            foreach ($keys as $key) {
                if (! isset($roleIds[$roleName], $permissionIds[$key])) {
                    $this->command?->warn("تخطي إسناد غير معروف: {$roleName} -> {$key}");
                    continue;
                }

                $pivot[] = [
                    'role_id'       => $roleIds[$roleName],
                    'permission_id' => $permissionIds[$key],
                ];
            }
        }

        // insertOrIgnore يعتمد على المفتاح المركب لمنع التكرار
        DB::table('permission_role')->insertOrIgnore($pivot);

        $this->command?->info(sprintf(
            'صلاحيات: %d | أدوار: %d | إسنادات: %d',
            DB::table('permissions')->count(),
            DB::table('roles')->count(),
            DB::table('permission_role')->count()
        ));
    }
}
