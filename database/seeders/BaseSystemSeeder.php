<?php

namespace Database\Seeders;

use App\Constants\PermissionConstants;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\PaymentMethod;
use App\Models\Shared\PricingSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * السيدر الأساسي للنظام - Base System Seeder.
 *
 * يزرع البنية التحتية الثابتة التي لا يعمل التطبيق بدونها:
 *   1) الأدوار الثمانية + جدول الصلاحيات + إسناد الصلاحيات لكل دور
 *   2) البلديات الرئيسية (طرابلس المركز + أبو سليم + حي الأندلس)
 *      + المحلات الفرعية + المناطق (تقسيمات فعلية على أرض الواقع)
 *   3) المدارس الحقيقية (~20 مدرسة مشهورة في طرابلس بإحداثيات واقعية تقريبية)
 *   4) إعدادات التسعير والعمولات (سطر واحد)
 *   5) وسائل الدفع: سداد + موبي كاش + مصرف الصحاري (parent / instant_simulation)
 *   6) الشروط والأحكام: نسخة 1.0 منشورة (parent + driver)
 *   7) خزينة المنصة (master_escrow_vault) بسجل صفري ابتدائي
 *   8) حساب المدير العام (super_admin) + حساب مشرف لكل دور من الأدوار الخمسة الإدارية
 *
 * كلمة المرور الموحدة لجميع الحسابات المُنشأة هنا: Password123!
 *
 *   php artisan db:seed --class=BaseSystemSeeder
 */
class BaseSystemSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    /**
     * صلاحيات تطبيقَي ولي الأمر والسائق - غير موجودة في PermissionConstants.
     */
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

    private const ROLES = [
        ['id' => 1, 'name' => 'super_admin',           'display_name' => 'مدير النظام العام',               'kind' => 'staff',   'is_super' => 1, 'description' => 'صلاحية مطلقة على كل أجزاء النظام'],
        ['id' => 2, 'name' => 'operations_supervisor', 'display_name' => 'مشرف العمليات والرادار',           'kind' => 'staff',   'is_super' => 0, 'description' => 'متابعة الرحلات الحية وتوليد رحلات اليوم'],
        ['id' => 3, 'name' => 'fleet_supervisor',      'display_name' => 'مشرف شؤون السائقين والأسطول',      'kind' => 'staff',   'is_super' => 0, 'description' => 'اعتماد السائقين ومراجعة تعديلات بياناتهم'],
        ['id' => 4, 'name' => 'support_supervisor',    'display_name' => 'مشرف خدمة العملاء والشكاوى',       'kind' => 'staff',   'is_super' => 0, 'description' => 'معالجة الشكاوى والتقييمات'],
        ['id' => 5, 'name' => 'finance_officer',       'display_name' => 'المشرف المالي ومسؤول الخزينة',     'kind' => 'staff',   'is_super' => 0, 'description' => 'الشحن والسحب والتسويات ودفتر الأستاذ'],
        ['id' => 6, 'name' => 'geography_supervisor',  'display_name' => 'مشرف المدارس والبيانات الجغرافية',  'kind' => 'staff',   'is_super' => 0, 'description' => 'إدارة المدارس والبلديات والمناطق'],
        ['id' => 7, 'name' => 'parent',                'display_name' => 'ولي أمر',                         'kind' => 'account', 'is_super' => 0, 'description' => 'حساب ولي أمر في تطبيق أولياء الأمور'],
        ['id' => 8, 'name' => 'driver',                'display_name' => 'سائق حافلة/فان',                  'kind' => 'account', 'is_super' => 0, 'description' => 'حساب سائق في تطبيق السائقين'],
    ];

    /** super_admin غير مذكور: العمود is_super=1 يمنحه كل شيء تلقائياً */
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
            'driver_reviews.manage', 'drivers.view', 'parents.view',
            'parents.suspend', 'notifications.broadcast', 'content.manage_terms',
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

    /**
     * التقسيم الجغرافي الفعلي لبلديات طرابلس الكبرى الثلاث الأساسية.
     * كل بلدية تحتها محلات فرعية، وكل محلة تحتها مناطق شهيرة على أرض الواقع.
     */
    private const GEOGRAPHY = [
        'بلدية طرابلس المركز' => [
            'محلة طرابلس المدينة' => ['بن عاشور', 'زاوية الدهماني', 'شارع النصر', 'الظهرة'],
            'محلة النوفليين'      => ['النوفليين', 'راس حسن', 'العزيزية المدينة'],
            'محلة باب بن غشير'    => ['باب بن غشير', 'طريق المطار القديم', 'الفلاح'],
            'محلة فشلوم'          => ['فشلوم', 'المنصورة', 'الحي الصناعي'],
            'محلة الظهرة'         => ['الظهرة الشرقية', 'الظهرة الغربية', 'الخضراء'],
        ],
        'بلدية أبو سليم' => [
            'محلة أبو سليم المركز' => ['أبو سليم', 'مشروع الهضبة', 'الدريبي'],
            'محلة الهضبة'          => ['الهضبة الخضراء', 'الهضبة الشعبية', 'خلة الفرجان'],
            'محلة صلاح الدين'      => ['صلاح الدين', 'القرية النموذجية', 'طريق السواني'],
            'محلة عين زارة'        => ['عين زارة الشرقية', 'عين زارة الغربية', 'الحداب'],
            'محلة خلة الفرجان'     => ['خلة الفرجان الشمالية', 'خلة الفرجان الجنوبية', 'الفلاح 2'],
        ],
        'بلدية حي الأندلس' => [
            'محلة حي الأندلس المركز' => ['حي الأندلس', 'السياحية', 'غوط الشعال'],
            'محلة قرقارش'            => ['قرقارش', 'السراج', 'قرقارش البحر'],
            'محلة قرجي'              => ['قرجي الغربي', 'قرجي الشرقي', 'الشهداء قرجي'],
            'محلة السياحية'          => ['السياحية الشمالية', 'السياحية الجنوبية', 'شارع البحر'],
            'محلة النصر'             => ['النصر', 'سيدي المصري', 'الحوارث'],
        ],
    ];

    /**
     * مدارس واقعية مشهورة في طرابلس بإحداثيات تقريبية لموقعها العام في الحي.
     * قائمة متوازنة على البلديات الثلاث لضمان تغطية جغرافية.
     */
    private const SCHOOLS = [
        // بلدية طرابلس المركز
        ['name' => 'مدرسة النصر النموذجية للتعليم الأساسي',    'zone_name' => 'شارع النصر',        'lat' => 32.88712000, 'lng' => 13.19154000, 'address' => 'شارع النصر - بجوار مسجد النصر الكبير'],
        ['name' => 'مدرسة بن عاشور الابتدائية والإعدادية',       'zone_name' => 'بن عاشور',          'lat' => 32.87456000, 'lng' => 13.19023000, 'address' => 'بن عاشور - شارع الاستقلال'],
        ['name' => 'مدرسة زاوية الدهماني الثانوية للبنين',        'zone_name' => 'زاوية الدهماني',    'lat' => 32.87821000, 'lng' => 13.18435000, 'address' => 'زاوية الدهماني - قرب دوار الشط'],
        ['name' => 'مدرسة النوفليين الأهلية النموذجية',           'zone_name' => 'النوفليين',         'lat' => 32.88934000, 'lng' => 13.20512000, 'address' => 'النوفليين - بجوار مجمع العيادات'],
        ['name' => 'مدرسة فشلوم للتعليم الأساسي',                 'zone_name' => 'فشلوم',             'lat' => 32.89143000, 'lng' => 13.18211000, 'address' => 'فشلوم - الطريق العام'],
        ['name' => 'مدرسة الظهرة الابتدائية المختلطة',            'zone_name' => 'الظهرة الشرقية',    'lat' => 32.89523000, 'lng' => 13.19871000, 'address' => 'الظهرة الشرقية - خلف مركز الصحة'],
        ['name' => 'مدرسة باب بن غشير الحديثة',                   'zone_name' => 'باب بن غشير',       'lat' => 32.86321000, 'lng' => 13.20456000, 'address' => 'باب بن غشير - الشارع الرئيسي'],

        // بلدية أبو سليم
        ['name' => 'مدرسة أبو سليم النموذجية للبنين',              'zone_name' => 'أبو سليم',           'lat' => 32.86423000, 'lng' => 13.16045000, 'address' => 'أبو سليم - الطريق الدائري الثاني'],
        ['name' => 'مدرسة الهضبة الخضراء للتعليم الأساسي',         'zone_name' => 'الهضبة الخضراء',    'lat' => 32.85712000, 'lng' => 13.16834000, 'address' => 'الهضبة الخضراء - بجوار مسجد بلال'],
        ['name' => 'مدرسة صلاح الدين الابتدائية والإعدادية',       'zone_name' => 'صلاح الدين',        'lat' => 32.85234000, 'lng' => 13.19012000, 'address' => 'صلاح الدين - خلف السوق التجاري'],
        ['name' => 'مدرسة عين زارة النموذجية',                     'zone_name' => 'عين زارة الشرقية',  'lat' => 32.84567000, 'lng' => 13.22345000, 'address' => 'عين زارة الشرقية - الطريق العام'],
        ['name' => 'مدرسة خلة الفرجان للتعليم الأساسي',            'zone_name' => 'خلة الفرجان',       'lat' => 32.84891000, 'lng' => 13.15423000, 'address' => 'خلة الفرجان - قرب مركز الشرطة'],
        ['name' => 'مدرسة الفتح الأهلية النموذجية للبنات',         'zone_name' => 'مشروع الهضبة',      'lat' => 32.85834000, 'lng' => 13.17234000, 'address' => 'مشروع الهضبة - بجوار مسجد الفتح'],

        // بلدية حي الأندلس
        ['name' => 'مدرسة حي الأندلس النموذجية الدولية',            'zone_name' => 'حي الأندلس',        'lat' => 32.89234000, 'lng' => 13.16812000, 'address' => 'حي الأندلس - بالقرب من جامع الأندلس الكبير'],
        ['name' => 'مدرسة قرقارش الحديثة للتعليم الأساسي',           'zone_name' => 'قرقارش',            'lat' => 32.87891000, 'lng' => 13.13456000, 'address' => 'قرقارش - الشارع الرئيسي'],
        ['name' => 'مدرسة السراج الأهلية للبنات',                     'zone_name' => 'السراج',            'lat' => 32.88234000, 'lng' => 13.12234000, 'address' => 'السراج - بجوار جامع السراج الكبير'],
        ['name' => 'مدرسة الجيل الجديد الدولية',                       'zone_name' => 'السياحية',          'lat' => 32.90012000, 'lng' => 13.15678000, 'address' => 'السياحية - شارع البحر'],
        ['name' => 'مدرسة قرجي النموذجية للتعليم الأساسي',            'zone_name' => 'قرجي الغربي',       'lat' => 32.88567000, 'lng' => 13.11834000, 'address' => 'قرجي الغربي - الشارع العام'],
        ['name' => 'مدرسة النصر الأهلية الثانوية',                     'zone_name' => 'النصر',             'lat' => 32.89456000, 'lng' => 13.14567000, 'address' => 'النصر - بجوار سوق النصر'],
        ['name' => 'مدرسة الشهداء الابتدائية والإعدادية',              'zone_name' => 'الشهداء قرجي',      'lat' => 32.88345000, 'lng' => 13.11234000, 'address' => 'الشهداء قرجي - الشارع الرئيسي'],
    ];

    /** وسائل الدفع المعتمدة الآن: سداد + موبي كاش + مصرف الصحاري */
    private const PAYMENT_METHODS = [
        [
            'name_ar'    => 'خدمة سداد (Sadad)',
            'code'       => 'sadad',
            'min_amount' => 1.00,
            'max_amount' => 5000.00,
            'sort_order' => 1,
        ],
        [
            'name_ar'    => 'محفظة موبي كاش (MobiCash)',
            'code'       => 'mobicash',
            'min_amount' => 5.00,
            'max_amount' => 10000.00,
            'sort_order' => 2,
        ],
        [
            'name_ar'    => 'مصرف الصحاري - بطاقة مصرفية',
            'code'       => 'sahara_bank',
            'min_amount' => 5.00,
            'max_amount' => 10000.00,
            'sort_order' => 3,
        ],
    ];

    private const SUPERVISORS = [
        ['role_name' => 'operations_supervisor', 'full_name' => 'علي عمر الترهوني',        'email' => 'operations@darby.ly', 'phone_number' => '0910000002', 'gender' => 'male'],
        ['role_name' => 'fleet_supervisor',      'full_name' => 'فاطمة محمد العريبي',       'email' => 'fleet@darby.ly',      'phone_number' => '0910000003', 'gender' => 'female'],
        ['role_name' => 'support_supervisor',    'full_name' => 'مصطفى صالح الشريف',        'email' => 'support@darby.ly',    'phone_number' => '0910000004', 'gender' => 'male'],
        ['role_name' => 'finance_officer',       'full_name' => 'ليلى إبراهيم بن نصر',      'email' => 'finance@darby.ly',    'phone_number' => '0910000005', 'gender' => 'female'],
        ['role_name' => 'geography_supervisor',  'full_name' => 'يوسف عبدالله المزوغي',      'email' => 'geography@darby.ly',  'phone_number' => '0910000006', 'gender' => 'male'],
    ];

    public function run(): void
    {
        $this->command?->info('🏗️  BaseSystemSeeder: بدء بناء البنية الأساسية للنظام...');

        // ملاحظة: بدون transaction لأن TRUNCATE + Schema::disableForeignKeyConstraints
        // يُنهيان المعاملة ضمنياً في MySQL (implicit commit).
        $this->cleanupBaseTables();
        $this->seedPermissionsAndRoles();
        $this->seedGeographyTree();
        $this->seedSchools();
        $this->seedPricingSettings();
        $this->seedPaymentMethods();
        $this->seedTermsAndArticles();
        $this->seedEscrowVault();
        $this->seedAdminAndSupervisors();

        Cache::flush();

        $this->printReport();
    }

    // =====================================================================
    // 0) تنظيف الجداول الأساسية بترتيب آمن FK
    // =====================================================================
    private function cleanupBaseTables(): void
    {
        $this->command?->info('🧹 تنظيف الجداول الأساسية...');

        Schema::disableForeignKeyConstraints();

        foreach ([
            'permission_role',
            'permissions',
            'terms_acceptances',
            'terms_articles',
            'terms_versions',
            'schools',
            'zones',
            'sub_municipalities',
            'municipalities',
            'pricing_settings',
            'payment_methods',
            'master_escrow_vault',
            'admin_audit_logs',
            'user_devices',
            'wallets',
            'users',
            'roles',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    // =====================================================================
    // 1) الأدوار + الصلاحيات + الإسنادات
    // =====================================================================
    private function seedPermissionsAndRoles(): void
    {
        $this->command?->info('1️⃣  زرع الأدوار والصلاحيات...');

        $now = now();

        // 1-a: الصلاحيات (شجرة الإدارة + صلاحيات التطبيقين)
        $rows = [];
        foreach (PermissionConstants::getPermissionsTree() as $group) {
            foreach ($group['permissions'] as $perm) {
                $rows[] = [
                    'key'            => $perm['key'],
                    'group_key'      => $group['group_key'],
                    'name_ar'        => $perm['name'],
                    'description_ar' => $perm['description'] ?? null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }
        foreach (self::APP_PERMISSIONS as $perm) {
            $rows[] = $perm + ['created_at' => $now, 'updated_at' => $now];
        }
        DB::table('permissions')->insert($rows);

        // 1-b: الأدوار الثمانية (بمعرفات ثابتة)
        DB::table('roles')->insert(array_map(
            fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now],
            self::ROLES
        ));

        // 1-c: الربط في permission_role (super_admin غير مذكور: is_super=1)
        $roleIds       = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'key');
        $pivot         = [];

        foreach (self::GRANTS as $roleName => $keys) {
            foreach ($keys as $key) {
                if (!isset($roleIds[$roleName], $permissionIds[$key])) {
                    $this->command?->warn("تجاهل إسناد غير معروف: {$roleName} -> {$key}");
                    continue;
                }
                $pivot[] = [
                    'role_id'       => $roleIds[$roleName],
                    'permission_id' => $permissionIds[$key],
                ];
            }
        }
        DB::table('permission_role')->insert($pivot);

        $this->command?->line(sprintf(
            '   ✔ صلاحيات: %d | أدوار: %d | إسنادات: %d',
            count($rows), count(self::ROLES), count($pivot)
        ));
    }

    // =====================================================================
    // 2) البلديات → المحلات الفرعية → المناطق
    // =====================================================================
    /** @var array<string,int> */
    private array $zoneIdByName = [];

    private function seedGeographyTree(): void
    {
        $this->command?->info('2️⃣  زرع البلديات والمحلات والمناطق...');

        $now = now();
        $muniCount = 0;
        $subCount  = 0;
        $zoneCount = 0;

        foreach (self::GEOGRAPHY as $muniName => $subMunis) {
            $muniId = DB::table('municipalities')->insertGetId([
                'name' => $muniName, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $muniCount++;

            foreach ($subMunis as $subName => $zones) {
                $subId = DB::table('sub_municipalities')->insertGetId([
                    'municipality_id' => $muniId,
                    'name'            => $subName,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $subCount++;

                foreach ($zones as $zoneName) {
                    $zoneId = DB::table('zones')->insertGetId([
                        'sub_municipality_id' => $subId,
                        'name'                => $zoneName,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                    $this->zoneIdByName[$zoneName] = $zoneId;
                    $zoneCount++;
                }
            }
        }

        $this->command?->line("   ✔ بلديات: {$muniCount} | محلات فرعية: {$subCount} | مناطق: {$zoneCount}");
    }

    // =====================================================================
    // 3) المدارس الحقيقية
    // =====================================================================
    private function seedSchools(): void
    {
        $this->command?->info('3️⃣  زرع المدارس الحقيقية...');

        $now = now();
        $rows = [];
        foreach (self::SCHOOLS as $s) {
            $zoneId = $this->zoneIdByName[$s['zone_name']] ?? null;
            if (!$zoneId) {
                $this->command?->warn("تخطي مدرسة (لا توجد منطقة): {$s['name']} → {$s['zone_name']}");
                continue;
            }
            $rows[] = [
                'name'       => $s['name'],
                'zone_id'    => $zoneId,
                'lat'        => $s['lat'],
                'lng'        => $s['lng'],
                'address'    => $s['address'],
                'status'     => 'Approved',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('schools')->insert($rows);

        $this->command?->line('   ✔ مدارس: ' . count($rows));
    }

    // =====================================================================
    // 4) إعدادات التسعير والعمولات
    // =====================================================================
    private function seedPricingSettings(): void
    {
        $this->command?->info('4️⃣  زرع إعدادات التسعير...');

        PricingSetting::create([
            'discount_one_child'             => 0.00,
            'discount_two_children'          => 10.00,
            'discount_three_plus_children'   => 15.00,
            'platform_commission_rate'       => 8.00,
            'price_per_km_ac'                => 2.50,
            'price_per_km_non_ac'            => 2.00,
            'location_change_fee'            => 5.00,
            'location_change_fee_under_2km'  => 5.00,
            'location_change_fee_2_to_6km'   => 10.00,
            'location_change_fee_6_to_10km'  => 15.00,
        ]);

        $this->command?->line('   ✔ سطر إعدادات تسعير واحد بالقيم المعتمدة');
    }

    // =====================================================================
    // 5) وسائل الدفع (سداد / موبي كاش / مصرف الصحاري)
    // =====================================================================
    private function seedPaymentMethods(): void
    {
        $this->command?->info('5️⃣  زرع وسائل الدفع (سداد / موبي كاش / مصرف الصحاري)...');

        foreach (self::PAYMENT_METHODS as $i => $m) {
            PaymentMethod::create([
                'name_ar'         => $m['name_ar'],
                'code'            => $m['code'],
                'target_audience' => 'parent',
                'processing_type' => 'instant_simulation',
                'min_amount'      => $m['min_amount'],
                'max_amount'      => $m['max_amount'],
                'is_active'       => true,
                'sort_order'      => $m['sort_order'] ?? ($i + 1),
            ]);
        }

        $this->command?->line('   ✔ ' . count(self::PAYMENT_METHODS) . ' وسائل دفع (parent / instant_simulation)');
    }

    // =====================================================================
    // 6) الشروط والأحكام - نسخة 1.0 منشورة
    // =====================================================================
    private function seedTermsAndArticles(): void
    {
        $this->call(PlatformTermsSeeder::class);
    }

    // =====================================================================
    // 7) خزينة المنصة - سجل صفري ابتدائي
    // =====================================================================
    private function seedEscrowVault(): void
    {
        $this->command?->info('7️⃣  تهيئة خزينة المنصة (master_escrow_vault)...');
        MasterEscrowVault::create([
            'parents_escrow_pool'    => 0,
            'driver_pending_pool'    => 0,
            'driver_available_pool'  => 0,
            'pending_withdrawal_pool'=> 0,
            'platform_revenue_pool'  => 0,
            'penalty_pool'           => 0,
        ]);
        $this->command?->line('   ✔ خزينة بأرصدة صفرية');
    }

    // =====================================================================
    // 8) حساب المدير العام + حساب مشرف لكل دور
    // =====================================================================
    private function seedAdminAndSupervisors(): void
    {
        $this->command?->info('8️⃣  إنشاء المدير العام والمشرفين...');

        $roleIds = DB::table('roles')->pluck('id', 'name');

        // المدير العام
        $admin = User::create([
            'full_name'         => 'أحمد محمد المنصوري',
            'email'             => 'admin@darby.ly',
            'phone_number'      => '0910000001',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $roleIds['super_admin'],
            'is_active'         => true,
            'is_trusted'        => true,
            'gender'            => 'male',
            'email_verified_at' => now(),
        ]);

        // مشرفو الأدوار الخمسة
        foreach (self::SUPERVISORS as $sup) {
            User::create([
                'full_name'         => $sup['full_name'],
                'email'             => $sup['email'],
                'phone_number'      => $sup['phone_number'],
                'password'          => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'           => $roleIds[$sup['role_name']],
                'is_active'         => true,
                'is_trusted'        => true,
                'gender'            => $sup['gender'],
                'created_by'        => $admin->id,
                'email_verified_at' => now(),
            ]);
        }

        $this->command?->line('   ✔ حساب مدير عام + ' . count(self::SUPERVISORS) . ' مشرفين');
    }

    // =====================================================================
    // تقرير موجز
    // =====================================================================
    private function printReport(): void
    {
        $this->command?->newLine();
        $this->command?->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->command?->info('║              ✅ BaseSystemSeeder اكتمل بنجاح                     ║');
        $this->command?->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->command?->newLine();

        $this->command?->line('📊 <fg=cyan>ملخص البيانات المزروعة:</>');
        $this->command?->line('   • أدوار: ' . DB::table('roles')->count());
        $this->command?->line('   • صلاحيات: ' . DB::table('permissions')->count());
        $this->command?->line('   • إسنادات صلاحيات: ' . DB::table('permission_role')->count());
        $this->command?->line('   • بلديات: ' . DB::table('municipalities')->count());
        $this->command?->line('   • محلات فرعية: ' . DB::table('sub_municipalities')->count());
        $this->command?->line('   • مناطق: ' . DB::table('zones')->count());
        $this->command?->line('   • مدارس: ' . DB::table('schools')->count());
        $this->command?->line('   • وسائل دفع: ' . DB::table('payment_methods')->count());
        $this->command?->line('   • نسخ شروط: ' . DB::table('terms_versions')->count() . ' (' . DB::table('terms_articles')->count() . ' مادة)');
        $this->command?->line('   • مستخدمون: ' . DB::table('users')->count());

        $this->command?->newLine();
        $this->command?->line('🔐 <fg=yellow>بيانات الدخول (كلمة المرور الموحدة: <fg=green>' . self::DEFAULT_PASSWORD . '</>)</>');
        $this->command?->line('   • المدير العام:            admin@darby.ly        (0910000001)');
        foreach (self::SUPERVISORS as $sup) {
            $this->command?->line(sprintf(
                '   • %-40s %-25s (%s)',
                $sup['full_name'],
                $sup['email'],
                $sup['phone_number']
            ));
        }
        $this->command?->newLine();
    }
}
