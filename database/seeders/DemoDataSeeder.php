<?php

namespace Database\Seeders;

use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\Invoice;
use App\Models\Shared\LocationChangeRequest;
use App\Models\Shared\LocationChangeRequestChild;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\PaymentMethod;
use App\Models\Shared\PlatformFinance;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\RechargeRequest;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\RouteStop;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripEscrowHold;
use App\Models\Shared\TripEvent;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripTracking;
use App\Models\Shared\WithdrawalRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DemoDataSeeder - بيانات تجريبية شاملة عبر دورة حياة كاملة للمنصة.
 *
 * يعتمد على BaseSystemSeeder (الأدوار، الجغرافيا، المدارس، التسعير، وسائل الدفع).
 * كل السجلات مرتبطة منطقياً وليس عشوائياً: كل اشتراك نشط له مسار كامل ورحلات
 * ومحفظة وفواتير وحركات دفتر أستاذ - كأنه شغل على أرض الواقع فعلاً.
 *
 * كلمة المرور الموحدة: Password123!
 *
 * السيناريوهات المُغطاة:
 *   - 2 أولياء أمور + 3 أطفال لكل ولي (6 أطفال)
 *   - 2 سائقين مُعتمدَين بمركبات ووثائق كاملة
 *   - اشتراك مُكتمل سابق (منتهي منذ شهر) - كل رحلاته منفذة وفواتيره مسددة
 *   - اشتراكان نشطان حاليان (كلا الأسرتين) مع رحلات ماضية ومجدولة
 *   - طلب اشتراك معلق ينتظر السائق
 *   - طلب اشتراك مرفوض من السائق
 *   - طلبات شحن (مكتملة + معلقة) وطلبات سحب (مكتملة + معلقة)
 *   - طلبات تغيير عنوان (مقبولة + مرفوضة)
 *   - طلبات غياب من كلا الطرفين (طفل + سائق)
 *   - تقييمات وشكاوى (support_tickets)
 *   - إشعارات تشغيلية مترابطة
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    private PricingSetting $pricing;
    /** @var array<int,int> zone_id by name */
    private array $zones = [];
    /** @var array<int,\stdClass> */
    private array $schools = [];
    private array $paymentMethods = [];

    private User $parent1;
    private User $parent2;
    private User $driver1User;
    private User $driver2User;
    private Driver $driver1;
    private Driver $driver2;
    private Vehicle $vehicle1;
    private Vehicle $vehicle2;
    private Address $parent1Address;
    private Address $parent2Address;
    /** @var Child[] */
    private array $parent1Children;
    private array $parent2Children;

    public function run(): void
    {
        $this->command?->info('🎬 DemoDataSeeder: بدء زرع البيانات التجريبية الشاملة...');

        $this->assertBaseSeeded();
        $this->cleanupDemoTables();
        $this->loadReferences();

        $this->createParentsAndAddresses();
        $this->createParentChildren();
        $this->createDriversAndVehicles();
        $this->createDriverPreferences();

        $this->createCompletedSubscription();     // اشتراك منتهي كامل (parent1 + driver1)
        $this->createActiveSubscriptionParent1();  // اشتراك نشط جاري (parent1 + driver1)
        $this->createActiveSubscriptionParent2();  // اشتراك نشط جاري (parent2 + driver2)
        $this->createPendingRequest();             // طلب معلق (parent2 → driver1)
        $this->createRejectedRequest();            // طلب مرفوض (parent1 → driver2)

        $this->createRechargeAndWithdrawalRequests();
        $this->createLocationChangeRequests();
        $this->createParentAbsences();
        $this->createDriverAbsences();
        $this->createReviewsAndComplaints();
        $this->createNotifications();

        $this->printReport();
    }

    private function assertBaseSeeded(): void
    {
        if (!DB::table('users')->where('email', 'admin@darby.ly')->exists()) {
            $this->command?->error('❌ يجب تشغيل BaseSystemSeeder أولاً!');
            throw new \RuntimeException('BaseSystemSeeder not run yet.');
        }
        if (DB::table('schools')->count() === 0 || DB::table('pricing_settings')->count() === 0) {
            $this->command?->error('❌ جداول أساسية فارغة - شغّل BaseSystemSeeder أولاً!');
            throw new \RuntimeException('Base tables empty.');
        }
    }

    private function cleanupDemoTables(): void
    {
        $this->command?->info('🧹 تنظيف جداول البيانات التجريبية...');

        Schema::disableForeignKeyConstraints();
        foreach ([
            'notifications',
            'driver_reviews',
            'support_tickets',
            'absence_logs',
            'driver_absence_trips',
            'driver_absences',
            'location_change_request_children',
            'location_change_requests',
            'trip_escrow_holds',
            'trip_tracking',
            'trip_events',
            'trip_stops',
            'trip_manual_confirmations',
            'trip_disputes',
            'trips',
            'route_stops',
            'routes',
            'platform_finance_trip_settlements',
            'platform_finances',
            'financial_ledger',
            'invoices',
            'withdrawal_requests',
            'driver_recharge_requests',
            'recharge_requests',
            'transactions',
            'transfers',
            'active_subscriptions',
            'request_children',
            'requests',
            'driver_seat_slots',
            'driver_zone',
            'driver_approvals',
            'vehicle_documents',
            'vehicles',
            'drivers',
            'children',
            'addresses',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        // إزالة أي مستخدمي parent/driver قديمين (نُبقي الأدمن والمشرفين)
        User::whereIn('role_id', function ($q) {
            $q->select('id')->from('roles')->whereIn('name', ['parent', 'driver']);
        })->forceDelete();
        // إعادة تصفير الخزينة
        MasterEscrowVault::query()->delete();
        MasterEscrowVault::create([]);
        Schema::enableForeignKeyConstraints();
    }

    private function loadReferences(): void
    {
        $this->pricing = PricingSetting::first();
        foreach (DB::table('zones')->get() as $z) {
            $this->zones[$z->name] = $z->id;
        }
        foreach (DB::table('schools')->get() as $s) {
            $this->schools[$s->name] = $s;
        }
        foreach (PaymentMethod::all() as $pm) {
            $this->paymentMethods[$pm->code] = $pm;
        }
    }

    // =====================================================================
    // 1) أولياء الأمور + عناوينهم الرئيسية
    // =====================================================================
    private function createParentsAndAddresses(): void
    {
        $this->command?->info('1️⃣  إنشاء ولي الأمر #1 و #2 وعناوينهم...');

        $parentRoleId = DB::table('roles')->where('name', 'parent')->value('id');

        // ولي الأمر #1 - محمد الترهوني (بن عاشور، طرابلس المركز)
        $this->parent1 = User::create([
            'full_name'         => 'محمد علي عبدالسلام الترهوني',
            'email'             => 'parent1@darby.ly',
            'phone_number'      => '0911111111',
            'alternative_phone' => '0921111111',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $parentRoleId,
            'is_active'         => true,
            'is_trusted'        => true,
            'gender'            => 'male',
            'email_verified_at' => now(),
            'last_login_at'     => now()->subHours(3),
        ]);

        $this->parent1Address = Address::create([
            'user_id'    => $this->parent1->id,
            'zone_id'    => $this->zones['بن عاشور'],
            'label'      => 'المنزل - بن عاشور',
            'lat'        => 32.87423000,
            'lng'        => 13.19187000,
            'is_default' => true,
        ]);

        // ولي الأمر #2 - خالد الفيتوري (حي الأندلس)
        $this->parent2 = User::create([
            'full_name'         => 'خالد أحمد المبروك الفيتوري',
            'email'             => 'parent2@darby.ly',
            'phone_number'      => '0912222222',
            'alternative_phone' => '0922222222',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $parentRoleId,
            'is_active'         => true,
            'is_trusted'        => true,
            'gender'            => 'male',
            'email_verified_at' => now(),
            'last_login_at'     => now()->subHours(5),
        ]);

        $this->parent2Address = Address::create([
            'user_id'    => $this->parent2->id,
            'zone_id'    => $this->zones['حي الأندلس'],
            'label'      => 'المنزل - حي الأندلس',
            'lat'        => 32.89217000,
            'lng'        => 13.16723000,
            'is_default' => true,
        ]);

        // شحن محافظ الأولياء برصيد ابتدائي (كافٍ لدفع اشتراك شهر)
        $this->parent1->deposit(150000); // 1500 د.ل
        $this->parent2->deposit(120000); // 1200 د.ل
    }

    // =====================================================================
    // 2) أطفال كل ولي أمر (3 أطفال × 2)
    // =====================================================================
    private function createParentChildren(): void
    {
        $this->command?->info('2️⃣  إضافة 3 أطفال لكل ولي أمر...');

        $sch = fn (string $name) => $this->schools[$name];

        // أطفال ولي الأمر #1 (كلهم في نفس المنطقة)
        $this->parent1Children = [
            Child::create([
                'parent_id'           => $this->parent1->id,
                'school_id'           => $sch('مدرسة بن عاشور الابتدائية والإعدادية')->id,
                'address_id'          => $this->parent1Address->id,
                'full_name'           => 'يوسف محمد علي الترهوني',
                'birth_date'          => Carbon::now()->subYears(9)->format('Y-m-d'),
                'gender'              => 'male',
                'grade'               => 3,
                'school_stage'        => 'primary',
                'medical_notes'       => 'حساسية طفيفة من الفول السوداني - تجنّب أي وجبات تحتوي عليه.',
                'notification_radius' => 500,
                'qr_code_token'       => (string) Str::uuid(),
                'preferred_time_slot' => 'morning',
                'pickup_time'         => '06:45:00',
                'dropoff_time'        => '13:30:00',
                'is_active'           => true,
            ]),
            Child::create([
                'parent_id'           => $this->parent1->id,
                'school_id'           => $sch('مدرسة النصر النموذجية للتعليم الأساسي')->id,
                'address_id'          => $this->parent1Address->id,
                'full_name'           => 'سارة محمد علي الترهوني',
                'birth_date'          => Carbon::now()->subYears(11)->format('Y-m-d'),
                'gender'              => 'female',
                'grade'               => 5,
                'school_stage'        => 'primary',
                'medical_notes'       => null,
                'notification_radius' => 500,
                'qr_code_token'       => (string) Str::uuid(),
                'preferred_time_slot' => 'morning',
                'pickup_time'         => '06:45:00',
                'dropoff_time'        => '13:30:00',
                'is_active'           => true,
            ]),
            Child::create([
                'parent_id'           => $this->parent1->id,
                'school_id'           => $sch('مدرسة زاوية الدهماني الثانوية للبنين')->id,
                'address_id'          => $this->parent1Address->id,
                'full_name'           => 'أحمد محمد علي الترهوني',
                'birth_date'          => Carbon::now()->subYears(15)->format('Y-m-d'),
                'gender'              => 'male',
                'grade'               => 9,
                'school_stage'        => 'middle',
                'medical_notes'       => 'يستخدم نظارة طبية - يُرجى الانتباه لعدم كسرها.',
                'notification_radius' => 500,
                'qr_code_token'       => (string) Str::uuid(),
                'preferred_time_slot' => 'morning',
                'pickup_time'         => '06:45:00',
                'dropoff_time'        => '13:30:00',
                'is_active'           => true,
            ]),
        ];

        // أطفال ولي الأمر #2
        $this->parent2Children = [
            Child::create([
                'parent_id'           => $this->parent2->id,
                'school_id'           => $sch('مدرسة حي الأندلس النموذجية الدولية')->id,
                'address_id'          => $this->parent2Address->id,
                'full_name'           => 'إبراهيم خالد أحمد الفيتوري',
                'birth_date'          => Carbon::now()->subYears(8)->format('Y-m-d'),
                'gender'              => 'male',
                'grade'               => 2,
                'school_stage'        => 'primary',
                'medical_notes'       => null,
                'notification_radius' => 500,
                'qr_code_token'       => (string) Str::uuid(),
                'preferred_time_slot' => 'morning',
                'pickup_time'         => '07:00:00',
                'dropoff_time'        => '13:15:00',
                'is_active'           => true,
            ]),
            Child::create([
                'parent_id'           => $this->parent2->id,
                'school_id'           => $sch('مدرسة السراج الأهلية للبنات')->id,
                'address_id'          => $this->parent2Address->id,
                'full_name'           => 'مريم خالد أحمد الفيتوري',
                'birth_date'          => Carbon::now()->subYears(12)->format('Y-m-d'),
                'gender'              => 'female',
                'grade'               => 6,
                'school_stage'        => 'primary',
                'medical_notes'       => null,
                'notification_radius' => 500,
                'qr_code_token'       => (string) Str::uuid(),
                'preferred_time_slot' => 'morning',
                'pickup_time'         => '07:00:00',
                'dropoff_time'        => '13:15:00',
                'is_active'           => true,
            ]),
            Child::create([
                'parent_id'           => $this->parent2->id,
                'school_id'           => $sch('مدرسة قرقارش الحديثة للتعليم الأساسي')->id,
                'address_id'          => $this->parent2Address->id,
                'full_name'           => 'حمزة خالد أحمد الفيتوري',
                'birth_date'          => Carbon::now()->subYears(14)->format('Y-m-d'),
                'gender'              => 'male',
                'grade'               => 8,
                'school_stage'        => 'middle',
                'medical_notes'       => 'يُعاني من ربو خفيف - يحمل بخاخاً في حقيبته.',
                'notification_radius' => 500,
                'qr_code_token'       => (string) Str::uuid(),
                'preferred_time_slot' => 'morning',
                'pickup_time'         => '07:00:00',
                'dropoff_time'        => '13:15:00',
                'is_active'           => true,
            ]),
        ];
    }

    // =====================================================================
    // 3) السائقان + مركبتاهما + وثائقهما
    // =====================================================================
    private function createDriversAndVehicles(): void
    {
        $this->command?->info('3️⃣  إنشاء السائقين + المركبات + الوثائق...');

        $driverRoleId = DB::table('roles')->where('name', 'driver')->value('id');
        $adminUserId  = User::where('email', 'fleet@darby.ly')->value('id');

        // السائق #1 - عبدالرزاق الأشهب (يخدم طرابلس المركز)
        $this->driver1User = User::create([
            'full_name'         => 'عبدالرزاق سالم بشير الأشهب',
            'email'             => 'driver1@darby.ly',
            'phone_number'      => '0913333333',
            'alternative_phone' => '0923333333',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $driverRoleId,
            'is_active'         => true,
            'is_trusted'        => true,
            'gender'            => 'male',
            'email_verified_at' => now(),
            'last_login_at'     => now()->subMinutes(45),
        ]);

        $this->driver1 = Driver::create([
            'user_id'             => $this->driver1User->id,
            'national_id'         => '119851234567',
            'license_number'      => 'LY-TR-98745',
            'license_expiry'      => Carbon::now()->addYears(2)->format('Y-m-d'),
            'license_image_url'   => 'https://cdn.darby.ly/demo/licenses/driver1.jpg',
            'status'              => 'Approved',
            'reviewed_by'         => $adminUserId,
            'shift'               => 'both',
            'morning_go'          => 1,
            'morning_return'      => 1,
            'afternoon_go'        => 1,
            'afternoon_return'    => 1,
            'subscription_type'   => 'multi_day',
            'accepted_gender'     => 'both',
            'school_stages'       => json_encode(['kindergarten', 'primary', 'middle', 'secondary']),
            'current_lat'         => 32.87500000,
            'current_lng'         => 13.19100000,
            'last_ping_at'        => now()->subMinutes(2),
            'rating_avg'          => 4.75,
            'is_searchable'       => 1,
            'driver_waiting_minutes' => 10,
        ]);

        $this->vehicle1 = Vehicle::create([
            'driver_id'         => $this->driver1->id,
            'plate_number'      => 'ط ط 5678',
            'brand'             => 'Hyundai',
            'model'             => 'H1',
            'year'              => 2019,
            'color'             => 'أبيض',
            'type'              => 'Bus',
            'capacity_manual'   => 22,
            'has_ac'            => 1,
            'status'            => 'Active',
            'vehicle_image_url' => 'https://cdn.darby.ly/demo/vehicles/driver1_bus.jpg',
        ]);

        $this->createVehicleDocs($this->vehicle1);

        // السائق #2 - منصور الزوي (يخدم حي الأندلس + أبو سليم)
        $this->driver2User = User::create([
            'full_name'         => 'منصور محمد فرج الزوي',
            'email'             => 'driver2@darby.ly',
            'phone_number'      => '0914444444',
            'alternative_phone' => '0924444444',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $driverRoleId,
            'is_active'         => true,
            'is_trusted'        => true,
            'gender'            => 'male',
            'email_verified_at' => now(),
            'last_login_at'     => now()->subMinutes(20),
        ]);

        $this->driver2 = Driver::create([
            'user_id'             => $this->driver2User->id,
            'national_id'         => '119871876543',
            'license_number'      => 'LY-TR-88221',
            'license_expiry'      => Carbon::now()->addYears(3)->format('Y-m-d'),
            'license_image_url'   => 'https://cdn.darby.ly/demo/licenses/driver2.jpg',
            'status'              => 'Approved',
            'reviewed_by'         => $adminUserId,
            'shift'               => 'both',
            'morning_go'          => 1,
            'morning_return'      => 1,
            'afternoon_go'        => 1,
            'afternoon_return'    => 1,
            'subscription_type'   => 'multi_day',
            'accepted_gender'     => 'both',
            'school_stages'       => json_encode(['primary', 'middle', 'secondary']),
            'current_lat'         => 32.89200000,
            'current_lng'         => 13.16700000,
            'last_ping_at'        => now()->subMinutes(4),
            'rating_avg'          => 4.90,
            'is_searchable'       => 1,
            'driver_waiting_minutes' => 8,
        ]);

        $this->vehicle2 = Vehicle::create([
            'driver_id'         => $this->driver2->id,
            'plate_number'      => 'ط ط 9012',
            'brand'             => 'Toyota',
            'model'             => 'Hiace',
            'year'              => 2020,
            'color'             => 'فضي',
            'type'              => 'Van',
            'capacity_manual'   => 15,
            'has_ac'            => 1,
            'status'            => 'Active',
            'vehicle_image_url' => 'https://cdn.darby.ly/demo/vehicles/driver2_van.jpg',
        ]);

        $this->createVehicleDocs($this->vehicle2);

        // موافقة تسجيل ابتدائية لكل سائق (سجل approval)
        foreach ([$this->driver1, $this->driver2] as $d) {
            DB::table('driver_approvals')->insert([
                'driver_id'    => $d->id,
                'request_type' => 'Registration',
                'status'       => 'Approved',
                'admin_id'     => $adminUserId,
                'reviewed_at'  => now()->subDays(60),
                'created_at'   => now()->subDays(62),
                'updated_at'   => now()->subDays(60),
            ]);
        }
    }

    private function createVehicleDocs(Vehicle $vehicle): void
    {
        $docs = [
            'LOGBOOK'           => Carbon::now()->addYears(3),
            'INSURANCE'         => Carbon::now()->addMonths(11),
            'INSPECTION'        => Carbon::now()->addMonths(10),
            'OPERATING_PERMIT'  => Carbon::now()->addMonths(9),
        ];

        foreach ($docs as $type => $expiry) {
            DB::table('vehicle_documents')->insert([
                'vehicle_id'  => $vehicle->id,
                'doc_type'    => $type,
                'file_url'    => 'https://cdn.darby.ly/demo/docs/vehicle_' . $vehicle->id . '_' . strtolower($type) . '.pdf',
                'expiry_date' => $expiry->format('Y-m-d'),
                'is_verified' => 1,
                'state'       => 'active',
                'created_at'  => now()->subDays(60),
                'updated_at'  => now()->subDays(60),
            ]);
        }
    }

    // =====================================================================
    // 4) تفضيلات السائقين (المناطق المخدومة + seat slots)
    // =====================================================================
    private function createDriverPreferences(): void
    {
        $this->command?->info('4️⃣  تفضيلات السائقين (المناطق + seat slots)...');

        // السائق #1: بن عاشور + المناطق المجاورة في طرابلس المركز
        $d1Zones = ['بن عاشور', 'زاوية الدهماني', 'شارع النصر', 'الظهرة الشرقية', 'النوفليين'];
        foreach ($d1Zones as $zoneName) {
            if (isset($this->zones[$zoneName])) {
                DB::table('driver_zone')->insert([
                    'driver_id'  => $this->driver1->id,
                    'zone_id'    => $this->zones[$zoneName],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // السائق #2: حي الأندلس + قرقارش
        $d2Zones = ['حي الأندلس', 'قرقارش', 'السراج', 'السياحية', 'قرجي الغربي'];
        foreach ($d2Zones as $zoneName) {
            if (isset($this->zones[$zoneName])) {
                DB::table('driver_zone')->insert([
                    'driver_id'  => $this->driver2->id,
                    'zone_id'    => $this->zones[$zoneName],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // seat_slots: مخازن مقاعد فارغة لأيام العمل القادمة (لكن سنشغلها لاحقاً بحجوزات الاشتراكات)
        // نتركها فارغة هنا؛ الاشتراكات النشطة ستملأها.
    }

    // =====================================================================
    // 5) اشتراك سابق مُكتمل (parent1 + driver1) - شهر انتهى قبل ~5 أسابيع
    // =====================================================================
    private function createCompletedSubscription(): void
    {
        $this->command?->info('5️⃣  اشتراك مُكتمل سابق (شهر منتهي) بكل رحلاته وفواتيره...');

        $startDate = Carbon::now()->subWeeks(9)->startOfWeek(Carbon::SATURDAY);
        $endDate   = $startDate->copy()->addDays(30);
        $workDays  = $this->workingDaysBetween($startDate, $endDate);

        $parent   = $this->parent1;
        $children = $this->parent1Children;
        $driver   = $this->driver1;
        $vehicle  = $this->vehicle1;

        $req = $this->createRequestWithChildren(
            parent:       $parent,
            children:     $children,
            driver:       $driver,
            vehicle:      $vehicle,
            parentAddr:   $this->parent1Address,
            startDate:    $startDate,
            endDate:      $endDate,
            workDays:     $workDays,
            status:       'accepted',
            respondedAt:  $startDate->copy()->subDays(2),
        );

        // إنشاء 3 اشتراكات نشطة (طفل واحد لكل child) - كلها مكتملة الآن
        $subs = $this->createActiveSubscriptionsForRequest($req, $children, status: 'completed');

        // إنشاء المسارَين (Morning Go + Afternoon Return)
        [$morningRoute, $afternoonRoute] = $this->createRoutePair($req, $driver, $vehicle, $children, $this->parent1Address);

        // ربط الاشتراكات النشطة بالمسار الصباحي (المرجعي)
        foreach ($subs as $s) {
            $s->route_id = $morningRoute->id;
            $s->save();
        }

        // توليد رحلات لكل يوم عمل: ذهاب + عودة
        $totalTrips = 0;
        $day = $startDate->copy();
        while ($day->lte($endDate)) {
            if ($this->isWorkingDay($day)) {
                $this->createDailyTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day, allCompleted: true);
                $totalTrips += 2;
            }
            $day->addDay();
        }

        // النظام المالي: فاتورة نهائية + platform_finance (settled بالكامل) + ledger
        $this->settleSubscriptionFinancials($req, $subs, $parent, $driver, $totalTrips);

        $req->status = 'accepted';
        $req->save();
    }

    // =====================================================================
    // 6) اشتراك نشط جارٍ (parent1 + driver1) - بدأ قبل 3 أسابيع، يستمر لأسبوعين
    // =====================================================================
    private function createActiveSubscriptionParent1(): void
    {
        $this->command?->info('6️⃣  اشتراك نشط جارٍ (parent1 + driver1)...');

        $startDate = Carbon::now()->subWeeks(3)->startOfWeek(Carbon::SATURDAY);
        $endDate   = Carbon::now()->addWeeks(2)->endOfWeek(Carbon::THURSDAY);
        $workDays  = $this->workingDaysBetween($startDate, $endDate);

        $parent   = $this->parent1;
        $children = $this->parent1Children;
        $driver   = $this->driver1;
        $vehicle  = $this->vehicle1;

        $req = $this->createRequestWithChildren(
            parent:      $parent,
            children:    $children,
            driver:      $driver,
            vehicle:     $vehicle,
            parentAddr:  $this->parent1Address,
            startDate:   $startDate,
            endDate:     $endDate,
            workDays:    $workDays,
            status:      'accepted',
            respondedAt: $startDate->copy()->subDays(3),
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, $children, status: 'active');
        [$morningRoute, $afternoonRoute] = $this->createRoutePair($req, $driver, $vehicle, $children, $this->parent1Address);
        foreach ($subs as $s) {
            $s->route_id = $morningRoute->id;
            $s->save();
        }

        // رحلات: من startDate إلى اليوم = مكتملة، ومن الغد فصاعداً = planned
        $completedTrips = 0;
        $day = $startDate->copy();
        while ($day->lte($endDate)) {
            if ($this->isWorkingDay($day)) {
                if ($day->lt(Carbon::today())) {
                    $this->createDailyTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day, allCompleted: true);
                    $completedTrips += 2;
                } elseif ($day->isSameDay(Carbon::today())) {
                    // اليوم: صباحية مكتملة، مسائية جارية
                    $this->createDailyTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day, allCompleted: false, todayScenario: true);
                    $completedTrips += 1;
                } else {
                    // مستقبل: planned فقط
                    $this->createPlannedTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day);
                }
            }
            $day->addDay();
        }

        // ماليات: platform_finance مبدئي (held) + خصم من محفظة الأب لتغطية الاشتراك
        $this->createHeldPlatformFinance($req, $subs, $parent, $driver, $completedTrips);
    }

    // =====================================================================
    // 7) اشتراك نشط جارٍ (parent2 + driver2)
    // =====================================================================
    private function createActiveSubscriptionParent2(): void
    {
        $this->command?->info('7️⃣  اشتراك نشط جارٍ (parent2 + driver2)...');

        $startDate = Carbon::now()->subWeeks(2)->startOfWeek(Carbon::SATURDAY);
        $endDate   = Carbon::now()->addWeeks(2)->endOfWeek(Carbon::THURSDAY);
        $workDays  = $this->workingDaysBetween($startDate, $endDate);

        $parent   = $this->parent2;
        $children = $this->parent2Children;
        $driver   = $this->driver2;
        $vehicle  = $this->vehicle2;

        $req = $this->createRequestWithChildren(
            parent:      $parent,
            children:    $children,
            driver:      $driver,
            vehicle:     $vehicle,
            parentAddr:  $this->parent2Address,
            startDate:   $startDate,
            endDate:     $endDate,
            workDays:    $workDays,
            status:      'accepted',
            respondedAt: $startDate->copy()->subDays(1),
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, $children, status: 'active');
        [$morningRoute, $afternoonRoute] = $this->createRoutePair($req, $driver, $vehicle, $children, $this->parent2Address);
        foreach ($subs as $s) {
            $s->route_id = $morningRoute->id;
            $s->save();
        }

        $completedTrips = 0;
        $day = $startDate->copy();
        while ($day->lte($endDate)) {
            if ($this->isWorkingDay($day)) {
                if ($day->lt(Carbon::today())) {
                    $this->createDailyTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day, allCompleted: true);
                    $completedTrips += 2;
                } elseif ($day->isSameDay(Carbon::today())) {
                    $this->createDailyTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day, allCompleted: false, todayScenario: true);
                    $completedTrips += 1;
                } else {
                    $this->createPlannedTrips($driver, $morningRoute, $afternoonRoute, $children, $subs, $day);
                }
            }
            $day->addDay();
        }

        $this->createHeldPlatformFinance($req, $subs, $parent, $driver, $completedTrips);
    }

    // =====================================================================
    // 8) طلب اشتراك معلق (parent2 → driver1) لم يرد عليه بعد
    // =====================================================================
    private function createPendingRequest(): void
    {
        $this->command?->info('8️⃣  طلب اشتراك معلق (parent2 → driver1)...');

        $startDate = Carbon::now()->addWeek()->startOfWeek(Carbon::SATURDAY);
        $endDate   = $startDate->copy()->addMonth();
        $workDays  = $this->workingDaysBetween($startDate, $endDate);

        // نُرسل بولد واحد فقط (إبراهيم)
        $child = $this->parent2Children[0];

        $this->createRequestWithChildren(
            parent:      $this->parent2,
            children:    [$child],
            driver:      $this->driver1,
            vehicle:     $this->vehicle1,
            parentAddr:  $this->parent2Address,
            startDate:   $startDate,
            endDate:     $endDate,
            workDays:    $workDays,
            status:      'pending',
            respondedAt: null,
        );
    }

    // =====================================================================
    // 9) طلب مرفوض (parent1 → driver2)
    // =====================================================================
    private function createRejectedRequest(): void
    {
        $this->command?->info('9️⃣  طلب اشتراك مرفوض (parent1 → driver2)...');

        $startDate = Carbon::now()->subWeeks(4)->startOfWeek(Carbon::SATURDAY);
        $endDate   = $startDate->copy()->addMonth();
        $workDays  = $this->workingDaysBetween($startDate, $endDate);

        $req = $this->createRequestWithChildren(
            parent:      $this->parent1,
            children:    [$this->parent1Children[0]],
            driver:      $this->driver2,
            vehicle:     $this->vehicle2,
            parentAddr:  $this->parent1Address,
            startDate:   $startDate,
            endDate:     $endDate,
            workDays:    $workDays,
            status:      'rejected',
            respondedAt: Carbon::now()->subWeeks(4)->addDays(1),
        );

        $req->rejection_reason = 'المنطقة الجغرافية خارج نطاق تغطية السائق (يخدم حي الأندلس فقط).';
        $req->save();
    }

    // =====================================================================
    // 🔟 طلبات شحن وسحب (parent + driver)
    // =====================================================================
    private function createRechargeAndWithdrawalRequests(): void
    {
        $this->command?->info('🔟 طلبات الشحن والسحب...');

        $adminId = User::where('email', 'finance@darby.ly')->value('id');
        $sadad   = $this->paymentMethods['sadad'];
        $mobicash = $this->paymentMethods['mobicash'];

        // 10-a: شحن مكتمل لولي الأمر #1 (500 د.ل عبر سداد)
        RechargeRequest::create([
            'parent_id'         => $this->parent1->id,
            'amount'            => 500.00,
            'payment_method'    => 'sadad',
            'payment_method_id' => $sadad->id,
            'reference_number'  => 'SD-' . strtoupper(Str::random(10)),
            'transaction_ref'   => 'TX-' . strtoupper(Str::random(12)),
            'status'            => 'completed',
            'admin_id'          => $adminId,
            'completed_at'      => now()->subDays(20),
            'created_at'        => now()->subDays(20),
            'updated_at'        => now()->subDays(20),
        ]);

        // 10-b: شحن معلق لولي الأمر #2
        RechargeRequest::create([
            'parent_id'         => $this->parent2->id,
            'amount'            => 300.00,
            'payment_method'    => 'mobicash',
            'payment_method_id' => $mobicash->id,
            'reference_number'  => 'MC-' . strtoupper(Str::random(10)),
            'status'            => 'pending',
            'created_at'        => now()->subHours(6),
            'updated_at'        => now()->subHours(6),
        ]);

        // 10-c: سحب مكتمل للسائق #1
        WithdrawalRequest::create([
            'driver_id'                  => $this->driver1->id,
            'amount'                     => 400.00,
            'wallet_balance_at_request'  => 750.00,
            'status'                     => 'approved',
            'payment_method_details'     => json_encode([
                'method' => 'sahara_bank',
                'holder_name' => 'عبدالرزاق سالم الأشهب',
                'iban' => 'LY83002001000000000123456789',
            ]),
            'admin_id'                   => $adminId,
            'processed_at'               => now()->subDays(15),
            'created_at'                 => now()->subDays(16),
            'updated_at'                 => now()->subDays(15),
        ]);

        // 10-d: سحب معلق للسائق #2
        WithdrawalRequest::create([
            'driver_id'                  => $this->driver2->id,
            'amount'                     => 250.00,
            'wallet_balance_at_request'  => 480.00,
            'status'                     => 'pending',
            'payment_method_details'     => json_encode([
                'method' => 'sadad',
                'sadad_number' => '0914444444',
            ]),
            'created_at'                 => now()->subDays(2),
            'updated_at'                 => now()->subDays(2),
        ]);

        // 10-e: سحب مرفوض للسائق #2
        WithdrawalRequest::create([
            'driver_id'                  => $this->driver2->id,
            'amount'                     => 1200.00,
            'wallet_balance_at_request'  => 480.00,
            'status'                     => 'rejected',
            'rejection_reason'           => 'المبلغ المطلوب يتجاوز الرصيد المتاح في المحفظة.',
            'payment_method_details'     => json_encode(['method' => 'sadad']),
            'admin_id'                   => $adminId,
            'processed_at'               => now()->subDays(8),
            'created_at'                 => now()->subDays(9),
            'updated_at'                 => now()->subDays(8),
        ]);
    }

    // =====================================================================
    // 1️⃣1️⃣ طلبات تغيير العنوان (مقبولة + مرفوضة)
    // =====================================================================
    private function createLocationChangeRequests(): void
    {
        $this->command?->info('1️⃣1️⃣ طلبات تغيير العنوان...');

        // parent1 - اشتراك نشط - طلب تغيير مقبول
        $sub = ActiveSubscription::whereHas('subscriptionRequest', fn ($q) =>
            $q->where('parent_id', $this->parent1->id)->where('status', 'accepted')
        )->where('status', 'active')->first();

        if ($sub) {
            $lcr = LocationChangeRequest::create([
                'active_subscription_id'     => $sub->id,
                'child_id'                   => $this->parent1Children[0]->id,
                'parent_id'                  => $this->parent1->id,
                'driver_id'                  => $this->driver1->id,
                'point_type'                 => 'dropoff',
                'direction'                  => 'to_home',
                'change_date'                => Carbon::tomorrow()->format('Y-m-d'),
                'is_single_day'              => 1,
                'new_lat'                    => 32.87823000,
                'new_lng'                    => 13.18921000,
                'new_label'                  => 'بيت الجد - زاوية الدهماني',
                'distance_km'                => 1.8,
                'fee_tier'                   => 'under_2km',
                'fee_amount'                 => 5.00,
                'commission_rate'            => 8.00,
                'platform_commission_amount' => 0.40,
                'driver_net_fee'             => 4.60,
                'status'                     => 'approved',
                'responded_at'               => now()->subHours(2),
                'is_settled'                 => 1,
            ]);

            LocationChangeRequestChild::create([
                'location_change_request_id' => $lcr->id,
                'child_id'                   => $this->parent1Children[0]->id,
                'active_subscription_id'     => $sub->id,
                'direction'                  => 'to_home',
                'previous_lat'               => $this->parent1Address->lat,
                'previous_lng'               => $this->parent1Address->lng,
                'previous_label'             => $this->parent1Address->label,
                'applied'                    => 1,
            ]);

            // طلب مرفوض
            $lcr2 = LocationChangeRequest::create([
                'active_subscription_id'     => $sub->id,
                'child_id'                   => $this->parent1Children[1]->id,
                'parent_id'                  => $this->parent1->id,
                'driver_id'                  => $this->driver1->id,
                'point_type'                 => 'pickup',
                'direction'                  => 'to_school',
                'change_date'                => Carbon::yesterday()->format('Y-m-d'),
                'is_single_day'              => 1,
                'new_lat'                    => 32.83450000,
                'new_lng'                    => 13.11234000,
                'new_label'                  => 'بيت الخال - في منطقة بعيدة جداً',
                'distance_km'                => 12.5,
                'fee_tier'                   => null,
                'fee_amount'                 => 15.00,
                'commission_rate'            => 8.00,
                'platform_commission_amount' => 1.20,
                'driver_net_fee'             => 13.80,
                'status'                     => 'rejected',
                'rejection_reason'           => 'المسافة تتجاوز 10 كم - خارج نطاق الخدمة المتفق عليه.',
                'responded_at'               => Carbon::yesterday()->setTime(20, 30),
            ]);

            LocationChangeRequestChild::create([
                'location_change_request_id' => $lcr2->id,
                'child_id'                   => $this->parent1Children[1]->id,
                'active_subscription_id'     => $sub->id,
                'direction'                  => 'to_school',
                'previous_lat'               => $this->parent1Address->lat,
                'previous_lng'               => $this->parent1Address->lng,
                'previous_label'             => $this->parent1Address->label,
                'applied'                    => 0,
                'skip_reason'                => 'الطلب مرفوض',
            ]);
        }
    }

    // =====================================================================
    // 1️⃣2️⃣ غيابات الأطفال (من ولي الأمر)
    // =====================================================================
    private function createParentAbsences(): void
    {
        $this->command?->info('1️⃣2️⃣ إشعارات غياب الأطفال (parent side)...');

        // ولي الأمر #1: طفلان غابا في يومين مختلفين خلال الاشتراك النشط
        DB::table('absence_logs')->insert([
            [
                'child_id'      => $this->parent1Children[0]->id,
                'absence_date'  => Carbon::now()->subDays(3)->format('Y-m-d'),
                'absence_type'  => 'both',
                'created_at'    => Carbon::now()->subDays(4)->setTime(20, 0),
                'updated_at'    => Carbon::now()->subDays(4)->setTime(20, 0),
            ],
            [
                'child_id'      => $this->parent1Children[2]->id,
                'absence_date'  => Carbon::now()->subDays(6)->format('Y-m-d'),
                'absence_type'  => 'pickup',
                'created_at'    => Carbon::now()->subDays(7)->setTime(21, 0),
                'updated_at'    => Carbon::now()->subDays(7)->setTime(21, 0),
            ],
            [
                'child_id'      => $this->parent2Children[1]->id,
                'absence_date'  => Carbon::now()->subDays(2)->format('Y-m-d'),
                'absence_type'  => 'both',
                'created_at'    => Carbon::now()->subDays(3)->setTime(19, 30),
                'updated_at'    => Carbon::now()->subDays(3)->setTime(19, 30),
            ],
        ]);
    }

    // =====================================================================
    // 1️⃣3️⃣ غيابات السائقين
    // =====================================================================
    private function createDriverAbsences(): void
    {
        $this->command?->info('1️⃣3️⃣ طلبات غياب السائقين...');

        $adminId = User::where('email', 'operations@darby.ly')->value('id');

        // السائق #1: غياب مُوافَق عليه سابقاً
        DB::table('driver_absences')->insert([
            'driver_id'    => $this->driver1->id,
            'absence_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'reason'       => 'مراجعة طبية عاجلة في مستشفى طرابلس المركزي.',
            'status'       => 'approved',
            'reviewed_by'  => $adminId,
            'reviewed_at'  => Carbon::now()->subDays(11)->setTime(18, 0),
            'admin_notes'  => 'موافقة استثنائية - يُرجى الالتزام بالإشعار المسبق أطول مستقبلاً.',
            'created_at'   => Carbon::now()->subDays(12)->setTime(20, 0),
            'updated_at'   => Carbon::now()->subDays(11)->setTime(18, 0),
        ]);

        // السائق #2: غياب معلق ينتظر الموافقة
        DB::table('driver_absences')->insert([
            'driver_id'    => $this->driver2->id,
            'absence_date' => Carbon::tomorrow()->format('Y-m-d'),
            'reason'       => 'صيانة طارئة لعلبة التروس - المركبة في الورشة.',
            'status'       => 'pending',
            'reviewed_by'  => null,
            'reviewed_at'  => null,
            'admin_notes'  => null,
            'created_at'   => now()->subHours(4),
            'updated_at'   => now()->subHours(4),
        ]);
    }

    // =====================================================================
    // 1️⃣4️⃣ تقييمات + شكاوى (support_tickets)
    // =====================================================================
    private function createReviewsAndComplaints(): void
    {
        $this->command?->info('1️⃣4️⃣ التقييمات والشكاوى...');

        // تقييم ولي الأمر #1 للسائق #1 (بعد الاشتراك المكتمل)
        $completedReq = SubscriptionRequest::where('parent_id', $this->parent1->id)
            ->where('driver_id', $this->driver1->id)
            ->where('status', 'accepted')
            ->orderBy('created_at')
            ->first();

        DB::table('driver_reviews')->insert([
            'subscription_request_id' => $completedReq?->id,
            'parent_id'  => $this->parent1->id,
            'driver_id'  => $this->driver1->id,
            'rating'     => 5,
            'comment'    => 'سائق ممتاز وملتزم بالمواعيد، الأطفال ارتاحوا معه كثيراً. شكراً على الاهتمام.',
            'status'     => 'active',
            'created_at' => Carbon::now()->subWeeks(4),
            'updated_at' => Carbon::now()->subWeeks(4),
        ]);

        // تقييم ولي الأمر #2 للسائق #2
        DB::table('driver_reviews')->insert([
            'subscription_request_id' => null,
            'parent_id'  => $this->parent2->id,
            'driver_id'  => $this->driver2->id,
            'rating'     => 4,
            'comment'    => 'الخدمة جيدة عموماً، لكن أحياناً يتأخر 5-10 دقائق عن موعد الاستلام.',
            'status'     => 'active',
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        // شكوى (support_ticket) من ولي الأمر #2 على السائق #2
        DB::table('support_tickets')->insert([
            'user_id'         => $this->parent2->id,
            'creator_role'    => 'parent',
            'category'        => 'delay',
            'target_role'     => 'driver',
            'target_user_id'  => $this->driver2User->id,
            'description'     => 'السائق تأخر أكثر من 15 دقيقة عن موعد استلام الطفل أمس، والابن وصل المدرسة متأخراً وحُرم من الحصة الأولى.',
            'status'          => 'in_progress',
            'scope'           => 'operations',
            'assigned_admin_id' => User::where('email','support@darby.ly')->value('id'),
            'created_at'      => Carbon::now()->subDays(3),
            'updated_at'      => Carbon::now()->subDays(1),
        ]);

        // شكوى من السائق #1 على تعطل ولي الأمر عن الرد
        DB::table('support_tickets')->insert([
            'user_id'         => $this->driver1User->id,
            'creator_role'    => 'driver',
            'category'        => 'communication',
            'target_role'     => 'parent',
            'target_user_id'  => $this->parent1->id,
            'description'     => 'ولي الأمر لم يرد على الاتصال عند وصولي لنقطة الاستلام صباح الأربعاء، انتظرت 15 دقيقة ثم اضطررت للمغادرة.',
            'status'          => 'resolved',
            'scope'           => 'operations',
            'assigned_admin_id' => User::where('email','support@darby.ly')->value('id'),
            'resolution_note' => 'تم التواصل مع ولي الأمر وتذكيره بضرورة الرد على السائق. لن يُحتسب اليوم كغياب مبلَّغ.',
            'closed_by'       => User::where('email','support@darby.ly')->value('id'),
            'closed_at'       => Carbon::now()->subDays(6),
            'created_at'      => Carbon::now()->subDays(8),
            'updated_at'      => Carbon::now()->subDays(6),
        ]);
    }

    // =====================================================================
    // 1️⃣5️⃣ إشعارات
    // =====================================================================
    private function createNotifications(): void
    {
        $this->command?->info('1️⃣5️⃣ الإشعارات...');

        $notify = function (User $user, string $type, string $title, string $body, ?Carbon $when = null, bool $read = false) {
            $when ??= now()->subHours(rand(1, 72));
            DB::table('notifications')->insert([
                'id'               => (string) Str::uuid(),
                'type'             => $type,
                'notifiable_type'  => User::class,
                'notifiable_id'    => $user->id,
                'data'             => json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE),
                'read_at'          => $read ? $when->copy()->addMinutes(30) : null,
                'created_at'       => $when,
                'updated_at'       => $when,
            ]);
        };

        // إشعارات ولي الأمر #1
        $notify($this->parent1, 'App\\Notifications\\SubscriptionAccepted',
            'تم قبول طلب اشتراكك',
            'وافق السائق عبدالرزاق الأشهب على طلب اشتراكك الشهري.', now()->subWeeks(3), read: true);
        $notify($this->parent1, 'App\\Notifications\\TripStarted',
            'بدأت رحلة الصباح',
            'انطلقت رحلة توصيل يوسف وسارة وأحمد إلى المدرسة الآن.', now()->subHours(6));
        $notify($this->parent1, 'App\\Notifications\\ChildDeliveredSchool',
            'تم توصيل الطفل للمدرسة',
            'تم توصيل يوسف بأمان إلى مدرسة بن عاشور الابتدائية.', now()->subHours(5)->subMinutes(20), read: true);
        $notify($this->parent1, 'App\\Notifications\\LocationChangeApproved',
            'تمت الموافقة على تغيير العنوان',
            'وافق السائق على تغيير نقطة تسليم يوسف غداً إلى بيت الجد.', now()->subHours(2));

        // إشعارات ولي الأمر #2
        $notify($this->parent2, 'App\\Notifications\\RechargePending',
            'طلب الشحن قيد المراجعة',
            'طلب شحن 300 د.ل عبر موبي كاش قيد المراجعة الإدارية.', now()->subHours(6));
        $notify($this->parent2, 'App\\Notifications\\ComplaintUpdate',
            'شكواك قيد المعالجة',
            'تم تحويل شكواك بخصوص تأخر السائق إلى مشرف الدعم.', now()->subDays(1));

        // إشعارات السائق #1
        $notify($this->driver1User, 'App\\Notifications\\WithdrawalApproved',
            'تم اعتماد طلب السحب',
            'تم تحويل 400 د.ل إلى حسابك في مصرف الصحاري.', now()->subDays(15), read: true);
        $notify($this->driver1User, 'App\\Notifications\\NewSubscriptionRequest',
            'طلب اشتراك جديد',
            'وصل طلب اشتراك من ولي الأمر خالد الفيتوري لطفل واحد.', now()->subHours(6));

        // إشعارات السائق #2
        $notify($this->driver2User, 'App\\Notifications\\WithdrawalRejected',
            'طلب السحب مرفوض',
            'طلبك لسحب 1200 د.ل مرفوض - المبلغ يتجاوز الرصيد المتاح.', now()->subDays(8), read: true);
        $notify($this->driver2User, 'App\\Notifications\\ComplaintReceived',
            'شكوى جديدة من ولي أمر',
            'ولي الأمر خالد الفيتوري قدم شكوى بخصوص التأخير - يرجى المراجعة.', now()->subDays(3));
    }

    // =====================================================================
    // 🔧 helper: إنشاء طلب اشتراك + request_children
    // =====================================================================
    private function createRequestWithChildren(
        User $parent,
        array $children,
        Driver $driver,
        Vehicle $vehicle,
        Address $parentAddr,
        Carbon $startDate,
        Carbon $endDate,
        int $workDays,
        string $status,
        ?Carbon $respondedAt,
    ): SubscriptionRequest {
        $childrenCount = count($children);
        $pricePerKm    = (float) ($vehicle->has_ac ? $this->pricing->price_per_km_ac : $this->pricing->price_per_km_non_ac);
        $discountPct   = $this->getDiscountPct($childrenCount);
        $commissionPct = (float) $this->pricing->platform_commission_rate;

        // احتساب مسافة تراكمية لكل طفل (المنزل → مدرسته)
        $totalDailyPrice = 0.0;
        $childPricing = [];
        foreach ($children as $child) {
            $school = $this->schools[$child->school->name] ?? DB::table('schools')->where('id', $child->school_id)->first();
            $dist   = $this->haversineKm(
                (float) $parentAddr->lat, (float) $parentAddr->lng,
                (float) $school->lat,     (float) $school->lng,
            );
            $billableDist  = round($dist, 2);
            $tripPrice     = round($billableDist * $pricePerKm, 2);
            $dailyPrice    = round($tripPrice * 2, 2); // ذهاب + عودة
            $childPricing[$child->id] = compact('school', 'billableDist', 'tripPrice', 'dailyPrice');
            $totalDailyPrice += $dailyPrice;
        }

        $totalPrice       = round($totalDailyPrice * $workDays, 2);
        $discountAmount   = round($totalPrice * $discountPct / 100, 2);
        $afterDiscount    = round($totalPrice - $discountAmount, 2);
        $commissionAmount = round($afterDiscount * $commissionPct / 100, 2);
        $driverNet        = round($afterDiscount - $commissionAmount, 2);

        $req = SubscriptionRequest::create([
            'parent_id'                    => $parent->id,
            'driver_id'                    => $driver->id,
            'status'                       => $status,
            'total_price'                  => $totalPrice,
            'discount_amount'              => $discountAmount,
            'total_amount_after_discount'  => $afterDiscount,
            'platform_commission_amount'   => $commissionAmount,
            'driver_net_amount'            => $driverNet,
            'children_count'               => $childrenCount,
            'pickup_time'                  => $children[0]->pickup_time,
            'dropoff_time'                 => $children[0]->dropoff_time,
            'max_waiting_time'             => 15,
            'subscription_type'            => 'multi_day',
            'trip_direction'               => 'both',
            'start_date'                   => $startDate->format('Y-m-d'),
            'end_date'                     => $endDate->format('Y-m-d'),
            'working_days_count'           => $workDays,
            'home_label'                   => $parentAddr->label,
            'home_lat'                     => $parentAddr->lat,
            'home_lng'                     => $parentAddr->lng,
            'home_address_id'              => $parentAddr->id,
            'pricing_setting_id'           => $this->pricing->id,
            'price_per_km'                 => $pricePerKm,
            'vehicle_has_ac'               => $vehicle->has_ac,
            'discount_percent'             => $discountPct,
            'platform_commission_rate'     => $commissionPct,
            'responded_at'                 => $respondedAt,
            'created_at'                   => $respondedAt ? $respondedAt->copy()->subDay() : now(),
            'updated_at'                   => $respondedAt ?? now(),
        ]);

        // request_children
        foreach ($children as $child) {
            $p = $childPricing[$child->id];
            $childTripAfterDisc  = round($p['tripPrice'] * $workDays * 2 * (1 - $discountPct / 100) / ($workDays * 2), 2);
            $childDailyAfterDisc = round($p['dailyPrice'] * (1 - $discountPct / 100), 2);
            $childDiscAmount     = round($p['dailyPrice'] * $workDays - $childDailyAfterDisc * $workDays, 2);
            $childAfterDisc      = round($childDailyAfterDisc * $workDays, 2);
            $childDriverNet      = round($childAfterDisc * (1 - $commissionPct / 100), 2);

            DB::table('request_children')->insert([
                'request_id'                   => $req->id,
                'child_id'                     => $child->id,
                'school_id'                    => $child->school_id,
                'timing'                       => 'both',
                'distance_km'                  => $p['billableDist'],
                'billable_distance_km'         => $p['billableDist'],
                'school_label'                 => $p['school']->name,
                'school_lat'                   => $p['school']->lat,
                'school_lng'                   => $p['school']->lng,
                'price_per_child'              => $childDailyAfterDisc,
                'trip_price'                   => $p['tripPrice'],
                'trip_price_after_discount'    => $childTripAfterDisc,
                'daily_price'                  => $p['dailyPrice'],
                'discount_amount'              => $childDiscAmount,
                'total_amount_after_discount'  => $childAfterDisc,
                'driver_net_price'             => $childDriverNet,
                'created_at'                   => $req->created_at,
                'updated_at'                   => $req->updated_at,
            ]);
        }

        return $req->fresh();
    }

    // =====================================================================
    // 🔧 helper: تحويل request → active_subscriptions
    // =====================================================================
    /** @return ActiveSubscription[] */
    private function createActiveSubscriptionsForRequest(SubscriptionRequest $req, array $children, string $status): array
    {
        $subs = [];
        $sort = 0;
        foreach ($children as $child) {
            $rc = DB::table('request_children')
                ->where('request_id', $req->id)
                ->where('child_id', $child->id)
                ->first();
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            $sort++;
            $subs[] = ActiveSubscription::create([
                'subscription_request_id' => $req->id,
                'request_child_id'        => $rc->id,
                'pickup_lat'              => $req->home_lat,
                'pickup_lng'              => $req->home_lng,
                'pickup_label'            => $req->home_label,
                'dropoff_lat'             => $school->lat,
                'dropoff_lng'             => $school->lng,
                'dropoff_label'           => $school->name,
                'pickup_time'             => $req->pickup_time,
                'dropoff_time'            => $req->dropoff_time,
                'sort_order'              => $sort,
                'status'                  => $status,
            ]);
        }
        return $subs;
    }

    // =====================================================================
    // 🔧 helper: إنشاء المسار الصباحي + المسائي مع route_stops
    // =====================================================================
    /** @return array{0:RouteModel,1:RouteModel} */
    private function createRoutePair(SubscriptionRequest $req, Driver $driver, Vehicle $vehicle, array $children, Address $parentAddr): array
    {
        // مسار صباحي: منزل → مدرسة (لكل طفل)
        $morning = RouteModel::create([
            'subscription_request_id' => $req->id,
            'driver_id'               => $driver->id,
            'vehicle_id'              => $vehicle->id,
            'route_name'              => 'مسار صباحي - ' . $parentAddr->label,
            'route_type'              => 'Morning',
            'shift_slot'              => 'morning_go',
            'start_time'              => $req->pickup_time,
            'total_distance'          => 0,
            'estimated_duration'      => 45,
            'status'                  => 'Active',
        ]);

        // stop 1: home
        RouteStop::create([
            'route_id'       => $morning->id,
            'stop_type'      => 'home',
            'child_id'       => null,
            'school_id'      => null,
            'lat'            => $parentAddr->lat,
            'lng'            => $parentAddr->lng,
            'label'          => $parentAddr->label,
            'sequence_order' => 1,
        ]);

        // stops 2..: schools (unique per child) sequenced by distance from home
        $seq = 2;
        $schoolStops = [];
        foreach ($children as $child) {
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            if (isset($schoolStops[$school->id])) continue;
            $schoolStops[$school->id] = true;
            RouteStop::create([
                'route_id'       => $morning->id,
                'stop_type'      => 'school',
                'child_id'       => $child->id,
                'school_id'      => $school->id,
                'lat'            => $school->lat,
                'lng'            => $school->lng,
                'label'          => $school->name,
                'sequence_order' => $seq++,
            ]);
        }

        // مسار مسائي: مدارس → منزل
        $afternoon = RouteModel::create([
            'subscription_request_id' => $req->id,
            'driver_id'               => $driver->id,
            'vehicle_id'              => $vehicle->id,
            'route_name'              => 'مسار مسائي - ' . $parentAddr->label,
            'route_type'              => 'Afternoon',
            'shift_slot'              => 'afternoon_return',
            'start_time'              => $req->dropoff_time,
            'total_distance'          => 0,
            'estimated_duration'      => 45,
            'status'                  => 'Active',
        ]);

        $seq = 1;
        $schoolStops = [];
        foreach ($children as $child) {
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            if (isset($schoolStops[$school->id])) continue;
            $schoolStops[$school->id] = true;
            RouteStop::create([
                'route_id'       => $afternoon->id,
                'stop_type'      => 'school',
                'child_id'       => $child->id,
                'school_id'      => $school->id,
                'lat'            => $school->lat,
                'lng'            => $school->lng,
                'label'          => $school->name,
                'sequence_order' => $seq++,
            ]);
        }
        RouteStop::create([
            'route_id'       => $afternoon->id,
            'stop_type'      => 'home',
            'child_id'       => null,
            'school_id'      => null,
            'lat'            => $parentAddr->lat,
            'lng'            => $parentAddr->lng,
            'label'          => $parentAddr->label,
            'sequence_order' => $seq,
        ]);

        return [$morning, $afternoon];
    }

    // =====================================================================
    // 🔧 helper: إنشاء رحلات يوم كامل (ذهاب + عودة)
    // =====================================================================
    private function createDailyTrips(Driver $driver, RouteModel $morning, RouteModel $afternoon, array $children, array $subs, Carbon $day, bool $allCompleted, bool $todayScenario = false): void
    {
        // === رحلة الصباح ===
        $scheduledStart = $day->copy()->setTimeFromTimeString($morning->start_time);
        $actualStart    = $scheduledStart->copy()->addMinutes(rand(-3, 7));
        $completedAt    = $actualStart->copy()->addMinutes(rand(30, 45));

        $morningTrip = Trip::create([
            'driver_id'            => $driver->id,
            'route_id'             => $morning->id,
            'trip_type'            => 'morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'completed',
            'scheduled_at'         => $scheduledStart,
            'started_at'           => $actualStart,
            'completed_at'         => $completedAt,
            'scheduled_start_time' => $morning->start_time,
            'actual_start_time'    => $actualStart->format('H:i:s'),
            'start_lat'            => 32.87500000,
            'start_lng'            => 13.19100000,
            'trip_date'            => $day->format('Y-m-d'),
            'created_at'           => $scheduledStart,
            'updated_at'           => $completedAt,
        ]);

        $this->createTripStopsAndEvents($morningTrip, $morning, $children, $subs, 'morning', $actualStart);
        $this->seedTripTracking($morningTrip, $actualStart, $completedAt);
        $this->createTripEscrowHold($morningTrip, $subs, $driver, isCompleted: true);

        // === رحلة العصر ===
        $scheduledStart = $day->copy()->setTimeFromTimeString($afternoon->start_time);

        if ($todayScenario) {
            // اليوم الحالي: مسائية = مجدولة أو جارية (لم تكتمل)
            $afternoonTrip = Trip::create([
                'driver_id'            => $driver->id,
                'route_id'             => $afternoon->id,
                'trip_type'            => 'afternoon',
                'shift_slot'           => 'afternoon_return',
                'status'               => 'planned',
                'scheduled_at'         => $scheduledStart,
                'scheduled_start_time' => $afternoon->start_time,
                'trip_date'            => $day->format('Y-m-d'),
                'created_at'           => $day->copy()->startOfDay(),
                'updated_at'           => $day->copy()->startOfDay(),
            ]);
            $this->createTripStopsAndEvents($afternoonTrip, $afternoon, $children, $subs, 'afternoon', $scheduledStart, plannedOnly: true);
            return;
        }

        $actualStart = $scheduledStart->copy()->addMinutes(rand(-2, 8));
        $completedAt = $actualStart->copy()->addMinutes(rand(35, 50));

        $afternoonTrip = Trip::create([
            'driver_id'            => $driver->id,
            'route_id'             => $afternoon->id,
            'trip_type'            => 'afternoon',
            'shift_slot'           => 'afternoon_return',
            'status'               => 'completed',
            'scheduled_at'         => $scheduledStart,
            'started_at'           => $actualStart,
            'completed_at'         => $completedAt,
            'scheduled_start_time' => $afternoon->start_time,
            'actual_start_time'    => $actualStart->format('H:i:s'),
            'start_lat'            => 32.87500000,
            'start_lng'            => 13.19100000,
            'trip_date'            => $day->format('Y-m-d'),
            'created_at'           => $scheduledStart,
            'updated_at'           => $completedAt,
        ]);

        $this->createTripStopsAndEvents($afternoonTrip, $afternoon, $children, $subs, 'afternoon', $actualStart);
        $this->seedTripTracking($afternoonTrip, $actualStart, $completedAt);
        $this->createTripEscrowHold($afternoonTrip, $subs, $driver, isCompleted: true);
    }

    private function createPlannedTrips(Driver $driver, RouteModel $morning, RouteModel $afternoon, array $children, array $subs, Carbon $day): void
    {
        $morningTrip = Trip::create([
            'driver_id'            => $driver->id,
            'route_id'             => $morning->id,
            'trip_type'            => 'morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'planned',
            'scheduled_at'         => $day->copy()->setTimeFromTimeString($morning->start_time),
            'scheduled_start_time' => $morning->start_time,
            'trip_date'            => $day->format('Y-m-d'),
            'created_at'           => $day->copy()->startOfDay(),
            'updated_at'           => $day->copy()->startOfDay(),
        ]);
        $this->createTripStopsAndEvents($morningTrip, $morning, $children, $subs, 'morning', $day->copy()->setTimeFromTimeString($morning->start_time), plannedOnly: true);

        $afternoonTrip = Trip::create([
            'driver_id'            => $driver->id,
            'route_id'             => $afternoon->id,
            'trip_type'            => 'afternoon',
            'shift_slot'           => 'afternoon_return',
            'status'               => 'planned',
            'scheduled_at'         => $day->copy()->setTimeFromTimeString($afternoon->start_time),
            'scheduled_start_time' => $afternoon->start_time,
            'trip_date'            => $day->format('Y-m-d'),
            'created_at'           => $day->copy()->startOfDay(),
            'updated_at'           => $day->copy()->startOfDay(),
        ]);
        $this->createTripStopsAndEvents($afternoonTrip, $afternoon, $children, $subs, 'afternoon', $day->copy()->setTimeFromTimeString($afternoon->start_time), plannedOnly: true);
    }

    // =====================================================================
    // 🔧 helper: trip_stops + trip_events لكل طفل في الرحلة
    // =====================================================================
    private function createTripStopsAndEvents(Trip $trip, RouteModel $route, array $children, array $subs, string $tripType, Carbon $startTime, bool $plannedOnly = false): void
    {
        $stops = RouteStop::where('route_id', $route->id)->orderBy('sequence_order')->get();
        $subsByChild = collect($subs)->keyBy(fn ($s) => optional($s->requestChild)->child_id ?? DB::table('request_children')->where('id', $s->request_child_id)->value('child_id'));

        $now = $startTime->copy();
        foreach ($stops as $stop) {
            $tripStop = TripStop::create([
                'trip_id'        => $trip->id,
                'route_stop_id'  => $stop->id,
                'stop_type'      => $stop->stop_type,
                'child_id'       => $stop->child_id,
                'school_id'      => $stop->school_id,
                'lat'            => $stop->lat,
                'lng'            => $stop->lng,
                'label'          => $stop->label,
                'sequence_order' => $stop->sequence_order,
                'status'         => $plannedOnly ? 'pending' : ($tripType === 'morning'
                    ? ($stop->stop_type === 'home' ? 'boarded' : 'dropped_off_school')
                    : ($stop->stop_type === 'school' ? 'boarded' : 'delivered_home')),
                'eta'            => $now->format('H:i:s'),
                'eta_minutes'    => 5,
            ]);

            if ($plannedOnly) continue;

            // trip_events لكل طفل ذي علاقة
            $children_at_stop = [];
            if ($stop->stop_type === 'home') {
                foreach ($children as $c) $children_at_stop[] = $c;
            } else {
                foreach ($children as $c) {
                    if ($c->school_id == $stop->school_id) $children_at_stop[] = $c;
                }
            }

            foreach ($children_at_stop as $c) {
                $sub = $subsByChild->get($c->id);
                if (!$sub) continue;

                $action = $tripType === 'morning'
                    ? ($stop->stop_type === 'home' ? 'picked_up' : 'dropped_off')
                    : ($stop->stop_type === 'school' ? 'picked_up' : 'delivered_home');

                TripEvent::create([
                    'trip_id'        => $trip->id,
                    'child_id'       => $c->id,
                    'subscription_id'=> $sub->id,
                    'action_type'    => $action,
                    'trip_type'      => $tripType,
                    'location_lat'   => $stop->lat,
                    'location_lng'   => $stop->lng,
                    'scanned_at'     => $now,
                    'trip_cost'      => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            $now->addMinutes(rand(5, 12));
        }
    }

    // =====================================================================
    // 🔧 helper: نقاط GPS متفرقة أثناء الرحلة
    // =====================================================================
    private function seedTripTracking(Trip $trip, Carbon $start, Carbon $end): void
    {
        $minutes = $start->diffInMinutes($end);
        $samples = max(3, min(6, (int) floor($minutes / 8)));
        for ($i = 0; $i <= $samples; $i++) {
            $t = $start->copy()->addMinutes((int) ($i * ($minutes / $samples)));
            TripTracking::create([
                'trip_id'     => $trip->id,
                'latitude'    => 32.87000000 + (mt_rand(-200, 200) / 10000),
                'longitude'   => 13.19000000 + (mt_rand(-200, 200) / 10000),
                'speed'       => rand(20, 55),
                'accuracy'    => rand(3, 15),
                'recorded_at' => $t,
            ]);
        }
    }

    // =====================================================================
    // 🔧 helper: احتجاز رصيد الرحلة (escrow hold) - lifecycle كامل
    // =====================================================================
    private function createTripEscrowHold(Trip $trip, array $subs, Driver $driver, bool $isCompleted): void
    {
        // نأخذ فقط أول اشتراك (نموذج) - في الواقع يتم لكل طفل بحصته
        $sub = $subs[0] ?? null;
        if (!$sub) return;

        $subReq = $sub->subscriptionRequest ?? SubscriptionRequest::find($sub->subscription_request_id);
        if (!$subReq) return;

        $tripDailyTotal = (float) $subReq->total_amount_after_discount / max(1, (int) $subReq->working_days_count);
        $tripHalf = $tripDailyTotal / 2; // ذهاب أو عودة
        $amountCents = (int) round($tripHalf * 100);

        TripEscrowHold::create([
            'trip_id'       => $trip->id,
            'parent_id'     => $subReq->parent_id,
            'driver_id'     => $driver->id,
            'amount'        => $amountCents,
            'hold_status'   => $isCompleted ? 'captured' : 'held',
            'held_at'       => $trip->scheduled_at,
            'captured_at'   => $isCompleted ? $trip->completed_at : null,
            'available_at'  => $isCompleted ? $trip->completed_at?->copy()?->addDays(3) : null,
        ]);
    }

    // =====================================================================
    // 🔧 helper: تسويات مالية كاملة (اشتراك مكتمل)
    // =====================================================================
    private function settleSubscriptionFinancials(SubscriptionRequest $req, array $subs, User $parent, Driver $driver, int $totalTrips): void
    {
        $totalAmount    = (float) $req->total_amount_after_discount;
        $commission     = (float) $req->platform_commission_amount;
        $driverNet      = (float) $req->driver_net_amount;
        $amountCents    = (int) round($totalAmount * 100);
        $commCents      = (int) round($commission * 100);
        $netCents       = (int) round($driverNet * 100);

        // خصم من محفظة الأب (مسبقاً عند القبول)
        $balanceBefore = (int) ($parent->balance ?? 0);
        if ($balanceBefore >= $amountCents) {
            $parent->withdraw($amountCents);
        } else {
            // تعبئة قبل الخصم
            $parent->deposit($amountCents);
            $parent->withdraw($amountCents);
        }
        $balanceAfter = (int) ($parent->fresh()->balance ?? 0);

        FinancialLedger::create([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => 'SUB-' . $req->id,
            'source_account'      => "parent_wallet:{$parent->id}",
            'destination_account' => 'platform_escrow',
            'amount'              => $amountCents,
            'balance_before'      => $balanceBefore,
            'balance_after'       => $balanceAfter,
            'type'                => 'subscription_hold',
            'status'              => 'completed',
            'metadata'            => json_encode(['subscription_request_id' => $req->id]),
            'created_at'          => $req->responded_at ?? now(),
            'updated_at'          => $req->responded_at ?? now(),
        ]);

        // إيداع النَت في محفظة السائق (بعد اكتمال كل الرحلات)
        $driverUser = $driver->user;
        $driverBalBefore = (int) ($driverUser->balance ?? 0);
        $driverUser->deposit($netCents);
        $driverBalAfter = (int) ($driverUser->fresh()->balance ?? 0);

        FinancialLedger::create([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => 'SUB-' . $req->id . '-SETTLE',
            'source_account'      => 'platform_escrow',
            'destination_account' => "driver_wallet:{$driver->id}",
            'amount'              => $netCents,
            'balance_before'      => $driverBalBefore,
            'balance_after'       => $driverBalAfter,
            'type'                => 'subscription_settle',
            'status'              => 'completed',
            'metadata'            => json_encode(['subscription_request_id' => $req->id, 'trips_settled' => $totalTrips]),
            'created_at'          => now()->subDays(35),
            'updated_at'          => now()->subDays(35),
        ]);

        // عمولة المنصة
        FinancialLedger::create([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => 'SUB-' . $req->id . '-COMM',
            'source_account'      => 'platform_escrow',
            'destination_account' => 'platform_revenue',
            'amount'              => $commCents,
            'balance_before'      => 0,
            'balance_after'       => 0,
            'type'                => 'platform_commission',
            'status'              => 'completed',
            'metadata'            => json_encode(['subscription_request_id' => $req->id]),
            'created_at'          => now()->subDays(35),
            'updated_at'          => now()->subDays(35),
        ]);

        // تحديث الخزينة الرئيسية
        $vault = MasterEscrowVault::getVault();
        $vault->increment('platform_revenue_pool', $commCents);
        $vault->increment('driver_available_pool', $netCents);

        // فاتورة نهائية
        Invoice::create([
            'subscription_request_id' => $req->id,
            'parent_id'               => $parent->id,
            'driver_id'               => $driver->id,
            'invoice_number'          => 'INV-' . str_pad((string) $req->id, 6, '0', STR_PAD_LEFT),
            'amount'                  => $totalAmount,
            'type'                    => 'final',
            'status'                  => 'paid',
            'due_date'                => $req->end_date,
            'subscription_type'       => 'multi_day',
            'total_trips'             => $totalTrips,
            'completed_trips'         => $totalTrips,
            'driver_absences'         => 0,
            'student_absences'        => 0,
            'calculated_amount'       => $totalAmount,
            'action_taken'            => 'settled',
            'payment_method'          => 'wallet',
            'details'                 => json_encode(['fully_settled' => true, 'settled_at' => now()->subDays(35)->toIso8601String()]),
            'paid_at'                 => now()->subDays(35),
            'resolved_at'             => now()->subDays(35),
        ]);

        // platform_finances (settled)
        PlatformFinance::create([
            'subscription_request_id'    => $req->id,
            'active_subscription_id'     => $subs[0]->id,
            'parent_id'                  => $parent->id,
            'driver_id'                  => $driver->id,
            'total_amount'               => $totalAmount,
            'platform_commission_rate'   => 8.00,
            'platform_commission_amount' => $commission,
            'driver_net_amount'          => $driverNet,
            'expected_trips_count'       => $totalTrips,
            'settled_trips_count'        => $totalTrips,
            'settled_amount'             => $totalAmount,
            'status'                     => 'settled',
            'held_at'                    => $req->responded_at,
            'settled_at'                 => now()->subDays(35),
        ]);
    }

    // =====================================================================
    // 🔧 helper: platform_finance مبدئي (اشتراك نشط)
    // =====================================================================
    private function createHeldPlatformFinance(SubscriptionRequest $req, array $subs, User $parent, Driver $driver, int $completedTrips): void
    {
        $totalAmount = (float) $req->total_amount_after_discount;
        $commission  = (float) $req->platform_commission_amount;
        $driverNet   = (float) $req->driver_net_amount;
        $amountCents = (int) round($totalAmount * 100);

        // خصم من محفظة الأب
        $balanceBefore = (int) ($parent->fresh()->balance ?? 0);
        if ($balanceBefore < $amountCents) {
            $parent->deposit($amountCents - $balanceBefore);
        }
        $parent->withdraw($amountCents);
        $balanceAfter = (int) ($parent->fresh()->balance ?? 0);

        FinancialLedger::create([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => 'SUB-' . $req->id,
            'source_account'      => "parent_wallet:{$parent->id}",
            'destination_account' => 'platform_escrow',
            'amount'              => $amountCents,
            'balance_before'      => $balanceBefore,
            'balance_after'       => $balanceAfter,
            'type'                => 'subscription_hold',
            'status'              => 'completed',
            'metadata'            => json_encode(['subscription_request_id' => $req->id]),
            'created_at'          => $req->responded_at ?? now(),
            'updated_at'          => $req->responded_at ?? now(),
        ]);

        // نسبة مسواة حتى الآن
        $expectedTrips = $this->workingDaysBetween(
            Carbon::parse($req->start_date),
            Carbon::parse($req->end_date),
        ) * 2;
        $settledRatio = $expectedTrips > 0 ? $completedTrips / $expectedTrips : 0;
        $settledAmount = round($totalAmount * $settledRatio, 2);

        PlatformFinance::create([
            'subscription_request_id'    => $req->id,
            'active_subscription_id'     => $subs[0]->id,
            'parent_id'                  => $parent->id,
            'driver_id'                  => $driver->id,
            'total_amount'               => $totalAmount,
            'platform_commission_rate'   => 8.00,
            'platform_commission_amount' => $commission,
            'driver_net_amount'          => $driverNet,
            'expected_trips_count'       => $expectedTrips,
            'settled_trips_count'        => $completedTrips,
            'settled_amount'             => $settledAmount,
            'status'                     => 'held',
            'held_at'                    => $req->responded_at,
        ]);

        // فاتورة proforma (لم تُسدد بعد كاملاً)
        Invoice::create([
            'subscription_request_id' => $req->id,
            'parent_id'               => $parent->id,
            'driver_id'               => $driver->id,
            'invoice_number'          => 'INV-' . str_pad((string) $req->id, 6, '0', STR_PAD_LEFT),
            'amount'                  => $totalAmount,
            'type'                    => 'proforma',
            'status'                  => 'pending',
            'due_date'                => $req->end_date,
            'subscription_type'       => 'multi_day',
            'total_trips'             => $expectedTrips,
            'completed_trips'         => $completedTrips,
            'driver_absences'         => 0,
            'student_absences'        => 0,
            'calculated_amount'       => $totalAmount,
            'action_taken'            => 'none',
            'payment_method'          => 'wallet',
        ]);

        // تحديث خزينة المنصة (parent escrow pool)
        MasterEscrowVault::getVault()->increment('parents_escrow_pool', $amountCents);
    }

    // =====================================================================
    // 🔧 utility helpers
    // =====================================================================
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }

    /** ليبيا: الجمعة/السبت عطلة، الأحد-الخميس عمل */
    private function isWorkingDay(Carbon $date): bool
    {
        $dow = $date->dayOfWeek; // 0=sunday .. 6=saturday
        return in_array($dow, [Carbon::SUNDAY, Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY]);
    }

    private function workingDaysBetween(Carbon $start, Carbon $end): int
    {
        $days = 0;
        $d = $start->copy();
        while ($d->lte($end)) {
            if ($this->isWorkingDay($d)) $days++;
            $d->addDay();
        }
        return $days;
    }

    private function getDiscountPct(int $childrenCount): float
    {
        if ($childrenCount >= 3) return (float) $this->pricing->discount_three_plus_children;
        if ($childrenCount === 2) return (float) $this->pricing->discount_two_children;
        return (float) $this->pricing->discount_one_child;
    }

    // =====================================================================
    // تقرير موجز
    // =====================================================================
    private function printReport(): void
    {
        $this->command?->newLine();
        $this->command?->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->command?->info('║             ✅ DemoDataSeeder اكتمل بنجاح                        ║');
        $this->command?->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->command?->newLine();

        $this->command?->line('📊 <fg=cyan>ملخص البيانات التجريبية:</>');
        $this->command?->line('   • مستخدمون: ' . DB::table('users')->count() . ' (منهم ' . DB::table('users')->whereIn('role_id', DB::table('roles')->whereIn('name',['parent','driver'])->pluck('id'))->count() . ' parent/driver)');
        $this->command?->line('   • أطفال: ' . DB::table('children')->count());
        $this->command?->line('   • عناوين: ' . DB::table('addresses')->count());
        $this->command?->line('   • سائقون: ' . DB::table('drivers')->count() . ' | مركبات: ' . DB::table('vehicles')->count() . ' | وثائق مركبات: ' . DB::table('vehicle_documents')->count());
        $this->command?->line('   • مناطق مخدومة: ' . DB::table('driver_zone')->count());
        $this->command?->line('   • طلبات اشتراك: ' . DB::table('requests')->count() . ' (' . DB::table('requests')->whereIn('status',['accepted','pending','rejected'])->count() . ')');
        $this->command?->line('   • اشتراكات نشطة: ' . DB::table('active_subscriptions')->count());
        $this->command?->line('   • مسارات: ' . DB::table('routes')->count() . ' | وقفات: ' . DB::table('route_stops')->count());
        $this->command?->line('   • رحلات: ' . DB::table('trips')->count() . ' (منها ' . DB::table('trips')->where('status','completed')->count() . ' مكتملة، ' . DB::table('trips')->where('status','planned')->count() . ' مجدولة)');
        $this->command?->line('   • trip_stops: ' . DB::table('trip_stops')->count() . ' | trip_events: ' . DB::table('trip_events')->count());
        $this->command?->line('   • trip_tracking: ' . DB::table('trip_tracking')->count() . ' نقطة GPS');
        $this->command?->line('   • trip_escrow_holds: ' . DB::table('trip_escrow_holds')->count());
        $this->command?->line('   • فواتير: ' . DB::table('invoices')->count() . ' | platform_finances: ' . DB::table('platform_finances')->count());
        $this->command?->line('   • قيود financial_ledger: ' . DB::table('financial_ledger')->count());
        $this->command?->line('   • طلبات شحن: ' . DB::table('recharge_requests')->count() . ' | طلبات سحب: ' . DB::table('withdrawal_requests')->count());
        $this->command?->line('   • طلبات تغيير عنوان: ' . DB::table('location_change_requests')->count());
        $this->command?->line('   • غيابات أطفال: ' . DB::table('absence_logs')->count() . ' | غيابات سائقين: ' . DB::table('driver_absences')->count());
        $this->command?->line('   • تقييمات: ' . DB::table('driver_reviews')->count() . ' | تذاكر دعم/شكاوى: ' . DB::table('support_tickets')->count());
        $this->command?->line('   • إشعارات: ' . DB::table('notifications')->count());

        $this->command?->newLine();
        $this->command?->line('🔐 <fg=yellow>حسابات التجربة (كلمة المرور: <fg=green>' . self::DEFAULT_PASSWORD . '</>)</>');
        $this->command?->line('   👨‍👦 ولي أمر #1: parent1@darby.ly / 0911111111  (محمد الترهوني - بن عاشور)');
        $this->command?->line('   👨‍👦 ولي أمر #2: parent2@darby.ly / 0912222222  (خالد الفيتوري - حي الأندلس)');
        $this->command?->line('   🚌 سائق #1:    driver1@darby.ly / 0913333333  (عبدالرزاق الأشهب - باص هيونداي)');
        $this->command?->line('   🚌 سائق #2:    driver2@darby.ly / 0914444444  (منصور الزوي - فان تويوتا)');
        $this->command?->newLine();
    }
}
