<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Admin\Admin;
use App\Models\Admin\AdminAuditLog;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Address;
use App\Models\Parent\School;
use App\Models\Parent\Child;
use App\Models\Parent\ChildLogistics;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripEvent;
use App\Models\Shared\TripTracking;
use App\Models\Shared\TripStudentAttendance;
use App\Models\Shared\TripManualConfirmation;
use App\Models\Shared\TripDispute;
use App\Models\Shared\DriverReview;
use App\Models\Shared\Complaint;
use App\Models\Shared\AbsenceLog;
use App\Models\Shared\LocationChangeRequest;
use App\Models\Shared\Invoice;
use App\Models\Shared\RechargeRequest;
use App\Models\Shared\WithdrawalRequest;
use App\Models\Shared\PlatformFinance;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\PricingSetting;

class MasterComprehensiveSeeder extends Seeder
{
    private string $defaultPassword = 'Password123!';

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        $this->command->info('🚀 بدء زرع البيانات الشاملة للمشروع بالكامل (Master Comprehensive Seeder)...');

        $this->cleanAllTables();
        $this->seedRoles();
        $this->seedPricingSettings();
        $this->seedContractClauses();
        $zones = $this->seedGeography();
        $schools = $this->seedSchools($zones);
        $adminUser = $this->seedAdminsAndSupervisors();
        $parentsData = $this->seedParentsAndChildren($zones, $schools);
        $driversData = $this->seedDriversAndVehicles($zones, $adminUser);
        $requestsData = $this->seedSubscriptionRequests($parentsData, $driversData, $schools);
        $activeSubsData = $this->seedActiveSubscriptions($requestsData, $parentsData, $driversData, $schools);
        $routesData = $this->seedRoutesAndStops($driversData, $activeSubsData, $schools);
        $this->seedTripsAndTracking($driversData, $routesData, $activeSubsData, $schools, $adminUser);
        $this->seedFinancialSystem($parentsData, $driversData, $requestsData, $activeSubsData, $adminUser);
        $this->seedReviewsAndComplaints($parentsData, $driversData, $adminUser);
        $this->seedLocationChangesAndAbsences($parentsData, $driversData, $activeSubsData);
        $this->seedNotificationsAndMessages($parentsData, $driversData, $adminUser);

        Schema::enableForeignKeyConstraints();

        $this->printSummaryReport();
    }

    // =========================================================================
    // 0. تنظيف الجداول القديمة
    // =========================================================================
    private function cleanAllTables(): void
    {
        $this->command->info('🧹 تنظيف البيانات القديمة من الجداول...');

        $tablesToTruncate = [
            'admin_audit_logs', 'admins', 'user_devices', 'messages', 'notifications', 'otp_codes',
            'trip_manual_confirmations', 'trip_disputes', 'sos_alerts', 'trip_tracking',
            'trip_student_attendance', 'trip_events', 'trip_stops', 'trips',
            'route_stops', 'routes',
            'platform_finances', 'financial_ledger', 'master_escrow_vault', 'trip_escrow_holds',
            'invoices', 'withdrawal_requests', 'recharge_requests', 'wallets', 'transactions', 'transfers',
            'location_change_requests', 'absence_logs', 'driver_absences',
            'complaints', 'driver_reviews', 'ratings',
            'active_subscriptions', 'request_children', 'requests',
            'driver_seat_slots', 'driver_documents', 'driver_profile_changes', 'driver_approvals', 'driver_zone', 'vehicles', 'drivers',
            'child_logistics', 'children', 'addresses', 'parents',
            'users',
        ];

        foreach ($tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
    }

    // =========================================================================
    // 1. الأدوار (Roles)
    // =========================================================================
    private function seedRoles(): void
    {
        $this->command->info('1️⃣ زرع الأدوار والصلاحيات (Roles)...');

        $defaultPerms = \App\Constants\PermissionConstants::getDefaultRolePermissions();

        $roles = [
            [
                'id'          => 1,
                'name'        => 'super_admin',
                'display_name'=> 'مدير النظام العام',
                'description' => 'صلاحيات كاملة وغير مقيدة لإدارة المنصة والإعدادات والمالية بالكامل.',
                'permissions' => json_encode($defaultPerms['super_admin'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 2,
                'name'        => 'operations_supervisor',
                'display_name'=> 'مشرف العمليات والرادار',
                'description' => 'صلاحيات مراقبة وتتبع الرحلات الحية على الرادار، تشغيل الرحلات، وإدارة المدارس والمناطق.',
                'permissions' => json_encode($defaultPerms['operations_supervisor'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 3,
                'name'        => 'parent',
                'display_name'=> 'ولي أمر',
                'description' => 'إدارة بيانات الأبناء، حجز الاشتراكات، شحن المحفظة، تتبع الرحلات الحية، وتقديم التقييمات والشكاوى.',
                'permissions' => json_encode(['children' => ['create', 'update'], 'subscriptions' => ['request', 'cancel'], 'wallet' => ['recharge', 'view'], 'trips' => ['track_live']], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 4,
                'name'        => 'driver',
                'display_name'=> 'سائق حافلة/فان',
                'description' => 'استقبال وتأكيد طلبات التوصيل، إدارة المسارات والوقفات، تشغيل الرحلات، مسح الحضور وتتبع الموقع.',
                'permissions' => json_encode(['trips' => ['start', 'complete', 'update_location', 'board_child'], 'routes' => ['manage'], 'wallet' => ['withdraw']], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 5,
                'name'        => 'fleet_supervisor',
                'display_name'=> 'مشرف شؤون السائقين والأسطول',
                'description' => 'مراجعة وتدقيق واعتماد طلبات انضمام السائقين، مراجعة تعديلات المركبات، وتجميد الحسابات.',
                'permissions' => json_encode($defaultPerms['fleet_supervisor'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 6,
                'name'        => 'support_supervisor',
                'display_name'=> 'مشرف خدمة العملاء والشكاوى',
                'description' => 'معالجة شكاوى أولياء الأمور والسائقين، متابعة تنبيهات الجودة والتقييمات، وإرسال الإشعارات.',
                'permissions' => json_encode($defaultPerms['support_supervisor'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 7,
                'name'        => 'finance_officer',
                'display_name'=> 'المشرف المالي ومسؤول الخزينة',
                'description' => 'إدارة الخزينة، تدقيق طلبات السحب والشحن، تحرير الأمانات، البت في النزاعات والتسويات المالية.',
                'permissions' => json_encode($defaultPerms['finance_officer'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id'          => 8,
                'name'        => 'geography_supervisor',
                'display_name'=> 'مشرف المدارس والبيانات الجغرافية',
                'description' => 'إدارة المدارس، البلديات، المحلات، وتخطيط المناطق الجغرافية.',
                'permissions' => json_encode($defaultPerms['geography_supervisor'], JSON_UNESCAPED_UNICODE),
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['id' => $role['id']], $role);
        }
    }

    // =========================================================================
    // 2. إعدادات التسعير والعمولات (Pricing Settings)
    // =========================================================================
    private function seedPricingSettings(): void
    {
        $this->command->info('2️⃣ زرع إعدادات التسعير والخصومات (Pricing Settings)...');

        PricingSetting::truncate();
        PricingSetting::create([
            'discount_one_child'          => 0.00,   // خصم طفل واحد: 0%
            'discount_two_children'       => 10.00,  // خصم طفلين (أخوة): 10%
            'discount_three_plus_children'=> 15.00,  // خصم 3 أطفال فما فوق: 15%
            'platform_commission_rate'    => 8.00,   // عمولة المنصة: 8%
            'price_per_km_ac'             => 2.50,   // سعر الكيلومتر للمركبة المكيفة: 2.50 د.ل
            'price_per_km_non_ac'         => 2.00,   // سعر الكيلومتر للمركبة العادية: 2.00 د.ل
        ]);
    }

    // =========================================================================
    // 3. بنود الاتفاقية والشروط (Contract Clauses)
    // =========================================================================
    private function seedContractClauses(): void
    {
        $this->command->info('3️⃣ زرع بنود وشروط اتفاقيات النقل المدرسي (Clauses)...');

        DB::table('clauses')->truncate();
        $clauses = [
            ['clause_text' => 'يلتزم السائق بالحضور في الموعد المحدد أمام نقطة التجمع المعتمدة، وألا يتجاوز وقت الانتظار المدة القصوى المحددة في الطلب (10-15 دقيقة).'],
            ['clause_text' => 'يلتزم ولي الأمر بتجهيز أبنائه وتسليمهم للسائق في المكان والزمان المتفق عليهما، وإشعار السائق عبر التطبيق مسبقاً في حال الغياب.'],
            ['clause_text' => 'تتولى منصة دربي حجز قيمة الاشتراك شهرياً كضمان مالي (Escrow) ولا يتم تحويل المستحقات لحساب السائق إلا بعد إتمام رحلات الفترة بنجاح.'],
            ['clause_text' => 'يحظر على السائق السماح لأي راكب غريب أو طفل غير مسجل بالصعود إلى المركبة طوال مسار الرحلة المدرسية.'],
            ['clause_text' => 'يلتزم السائق بمعايير السلامة المرورية والسرعة النظامية وتشغيل نظام التكييف داخل الحافلة أثناء نقل الطلاب.'],
            ['clause_text' => 'في حال حدوث عطل طارئ بالمركبة، يلتزم السائق بالضغط على زر الطوارئ SOS وإشعار المشرفين لتأمين وسيلة نقل بديلة بأسرع وقت.'],
        ];

        foreach ($clauses as $c) {
            DB::table('clauses')->insert([
                'clause_text' => $c['clause_text'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    // =========================================================
    // 4. الجغرافيا الكاملة (طرابلس الكبرى)
    // =========================================================
    private function seedGeography(): array
    {
        $this->command->info('4️⃣ زرع التقسيمات الجغرافية (البلديات، المحلات، والمناطق)...');

        $geographyData = [
            'طرابلس المركز' => [
                'طرابلس المدينة' => ['بن عاشور', 'الظهرة', 'زاوية الدهماني', 'فشلوم', 'المنصورة', 'شارع النصر'],
                'النوفليين'     => ['النوفليين', 'راس حسن', 'العزيزية المدينة'],
                'باب بن غشير'   => ['باب بن غشير', 'طريق المطار القديم'],
            ],
            'حي الأندلس' => [
                'حي الأندلس المركز' => ['حي الأندلس', 'قرقارش', 'غوط الشعال', 'السياحية', 'السراج'],
                'قرجي'             => ['قرجي الشارع الغربي', 'الشهداء قرجي'],
            ],
            'سوق الجمعة' => [
                'سوق الجمعة المركز' => ['سوق الجمعة', 'عرادة', 'شرفة الملاحة', 'الهانى', 'طريق الشط'],
                'تاجوراء الغربية'   => ['تاجوراء الغربية', 'البيفي', 'كعام'],
            ],
            'أبو سليم' => [
                'أبو سليم المركز' => ['أبو سليم', 'الهضبة الخضراء', 'الدريبي', 'مشروع الهضبة'],
                'صلاح الدين'      => ['صلاح الدين', 'عين زارة الشرقية', 'طريق السواني'],
            ],
            'جنزور' => [
                'جنزور المركز' => ['جنزور السوق', 'صرمان الساحلي', 'أولاد أحمد جنزور'],
            ],
        ];

        $savedZones = [];

        foreach ($geographyData as $muniName => $subMunis) {
            $muniId = DB::table('municipalities')->insertGetId([
                'name'       => $muniName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($subMunis as $subName => $zoneNames) {
                $subMuniId = DB::table('sub_municipalities')->insertGetId([
                    'municipality_id' => $muniId,
                    'name'            => $subName,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                foreach ($zoneNames as $zoneName) {
                    $zoneId = DB::table('zones')->insertGetId([
                        'sub_municipality_id' => $subMuniId,
                        'name'                => $zoneName,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                    $savedZones[$zoneName] = $zoneId;
                }
            }
        }

        return $savedZones;
    }

    // =========================================================
    // 5. المدارس الواقعية (Schools)
    // =========================================================
    private function seedSchools(array $zones): array
    {
        $this->command->info('5️⃣ زرع المدارس في طرابلس (Schools)...');

        $getZone = fn(string $name) => $zones[$name] ?? array_values($zones)[0];

        $schoolsData = [
            [
                'name'    => 'مدرسة الجيل الجديد الدولية',
                'lat'     => 32.89200000,
                'lng'     => 13.16800000,
                'address' => 'حي الأندلس - بالقرب من جامع الأندلس الكبير',
                'zone_id' => $getZone('حي الأندلس'),
                'status'  => 'active',
            ],
            [
                'name'    => 'مدرسة الشروق الأهلية النموذجية',
                'lat'     => 32.90500000,
                'lng'     => 13.22100000,
                'address' => 'بن عاشور - الشارع الغربي بجوار القنصلية',
                'zone_id' => $getZone('بن عاشور'),
                'status'  => 'active',
            ],
            [
                'name'    => 'مدرسة طرابلس الحديثة الابتدائية والإعدادية',
                'lat'     => 32.88700000,
                'lng'     => 13.19200000,
                'address' => 'زاوية الدهماني - شارع الاستقلال',
                'zone_id' => $getZone('زاوية الدهماني'),
                'status'  => 'active',
            ],
            [
                'name'    => 'مدرسة النور الخاصة للتعليم الأساسي',
                'lat'     => 32.89900000,
                'lng'     => 13.20500000,
                'address' => 'النوفليين - بجوار مجمع العيادات',
                'zone_id' => $getZone('النوفليين'),
                'status'  => 'active',
            ],
            [
                'name'    => 'مدرسة الرواد النموذجية الثانوية',
                'lat'     => 32.87600000,
                'lng'     => 13.24800000,
                'address' => 'سوق الجمعة - بالقرب من دوار قاطوشة',
                'zone_id' => $getZone('سوق الجمعة'),
                'status'  => 'active',
            ],
            [
                'name'    => 'مدرسة المستقبل المشرق المتكاملة',
                'lat'     => 32.86400000,
                'lng'     => 13.16000000,
                'address' => 'أبو سليم - الطريق الدائري الثاني',
                'zone_id' => $getZone('أبو سليم'),
                'status'  => 'active',
            ],
        ];

        $schools = [];
        foreach ($schoolsData as $s) {
            $schools[$s['name']] = School::create($s);
        }

        return $schools;
    }

    // =========================================================
    // 6. المشرفون ومدير النظام (Admins & Supervisors)
    // =========================================================
    private function seedAdminsAndSupervisors(): User
    {
        $this->command->info('6️⃣ إنشاء حسابات الإدارة والمشرفين (Admins & Supervisors)...');

        $adminUser = User::create([
            'full_name'         => 'المهندس أحمد المنصوري (مدير عام المنصة)',
            'email'             => 'admin@darby.ly',
            'phone_number'      => '0910000001',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 1,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        Admin::create([
            'user_id'    => $adminUser->id,
            'created_by' => $adminUser->id,
        ]);

        $supervisors = [
            [
                'full_name'    => 'علي عمر الترهوني (مشرف عمليات غرب طرابلس)',
                'email'        => 'supervisor1@darby.ly',
                'phone_number' => '0911000001',
            ],
            [
                'full_name'    => 'فاطمة محمد العريبي (مشرفة رحلات شرق طرابلس)',
                'email'        => 'supervisor2@darby.ly',
                'phone_number' => '0922000002',
            ],
        ];

        foreach ($supervisors as $sup) {
            $supUser = User::create([
                'full_name'         => $sup['full_name'],
                'email'             => $sup['email'],
                'phone_number'      => $sup['phone_number'],
                'password_hash'     => Hash::make($this->defaultPassword),
                'role_id'           => 2,
                'is_active'         => 1,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]);

            Admin::create([
                'user_id'    => $supUser->id,
                'created_by' => $adminUser->id,
            ]);

            AdminAuditLog::create([
                'admin_id'    => $adminUser->id,
                'admin_name'  => $adminUser->full_name,
                'admin_role'  => 'super_admin',
                'action'      => 'create_supervisor',
                'entity_type' => 'User',
                'entity_id'   => $supUser->id,
                'entity_name' => $sup['full_name'],
                'result'      => 'success',
                'reason'      => 'تعيين مشرف جديد لمتابعة مسارات النقل المدرسي',
                'changes'     => ['email' => $sup['email'], 'role' => 'supervisor'],
            ]);
        }

        return $adminUser;
    }

    // =========================================================
    // 7. أولياء الأمور، العناوين، والأطفال (Parents, Addresses, Children)
    // =========================================================
    private function seedParentsAndChildren(array $zones, array $schools): array
    {
        $this->command->info('7️⃣ إنشاء حسابات أولياء الأمور، العناوين، وبيانات الأبناء (Parents & Children)...');

        $schoolsList = array_values($schools);
        $school1 = $schoolsList[0];
        $school2 = $schoolsList[1];
        $school3 = $schoolsList[2];

        // --- ولي الأمر 1 (لديه طفلين، اشتراك نشط ومحفظة مشحونة) ---
        $p1User = User::create([
            'full_name'         => 'طه سالم القموضي',
            'email'             => 'parent1@darby.ly',
            'phone_number'      => '0913000001',
            'alternative_phone' => '0214801122',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $p1 = ParentModel::create(['user_id' => $p1User->id, 'is_trusted' => 1]);

        $addr1_main = Address::create([
            'parent_id' => $p1->id,
            'label'     => 'المنزل الرئيسي - حي الأندلس خلف مركز المقارحة',
            'lat'       => 32.89250000,
            'lng'       => 13.17520000,
        ]);
        $addr1_grandma = Address::create([
            'parent_id' => $p1->id,
            'label'     => 'منزل الجدة - قرقارش قرب جامع بن رجب',
            'lat'       => 32.88510000,
            'lng'       => 13.16200000,
        ]);

        $child1 = Child::create([
            'parent_id'           => $p1->id,
            'school_id'           => $school1->id,
            'address_id'          => $addr1_main->id,
            'full_name'           => 'سند طه القموضي',
            'birth_date'          => '2016-04-10',
            'gender'              => 'male',
            'grade'               => 4,
            'school_stage'        => 'primary',
            'medical_notes'       => 'حساسية خفيفة من الغبار في الأجواء الحارة',
            'notification_radius' => 500,
        ]);
        ChildLogistics::create([
            'child_id'            => $child1->id,
            'preferred_time_slot' => 'both',
            'pickup_time'         => '07:00:00',
            'dropoff_time'        => '13:45:00',
            'trip_direction'      => 'both',
            'start_date'          => Carbon::today()->startOfMonth()->toDateString(),
            'end_date'            => Carbon::today()->endOfMonth()->toDateString(),
            'subscription_type'   => 'multi_day',
            'is_active'           => 1,
        ]);

        $child2 = Child::create([
            'parent_id'           => $p1->id,
            'school_id'           => $school1->id,
            'address_id'          => $addr1_main->id,
            'full_name'           => 'مروة طه القموضي',
            'birth_date'          => '2014-08-20',
            'gender'              => 'female',
            'grade'               => 6,
            'school_stage'        => 'primary',
            'medical_notes'       => null,
            'notification_radius' => 500,
        ]);
        ChildLogistics::create([
            'child_id'            => $child2->id,
            'preferred_time_slot' => 'both',
            'pickup_time'         => '07:00:00',
            'dropoff_time'        => '13:45:00',
            'trip_direction'      => 'both',
            'start_date'          => Carbon::today()->startOfMonth()->toDateString(),
            'end_date'            => Carbon::today()->endOfMonth()->toDateString(),
            'subscription_type'   => 'multi_day',
            'is_active'           => 1,
        ]);

        // --- ولي الأمر 2 (طفل واحد، طلب قيد الانتظار) ---
        $p2User = User::create([
            'full_name'         => 'محمود علي الورفلي',
            'email'             => 'parent2@darby.ly',
            'phone_number'      => '0924000002',
            'alternative_phone' => '0213334455',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $p2 = ParentModel::create(['user_id' => $p2User->id, 'is_trusted' => 1]);

        $addr2_main = Address::create([
            'parent_id' => $p2->id,
            'label'     => 'منزل العائلة - بن عاشور بالقرب من مصحة الفردوس',
            'lat'       => 32.90150000,
            'lng'       => 13.21550000,
        ]);
        $child3 = Child::create([
            'parent_id'           => $p2->id,
            'school_id'           => $school2->id,
            'address_id'          => $addr2_main->id,
            'full_name'           => 'أيمن محمود الورفلي',
            'birth_date'          => '2017-01-15',
            'gender'              => 'male',
            'grade'               => 3,
            'school_stage'        => 'primary',
            'medical_notes'       => null,
            'notification_radius' => 400,
        ]);
        ChildLogistics::create([
            'child_id'            => $child3->id,
            'preferred_time_slot' => 'morning',
            'pickup_time'         => '07:15:00',
            'dropoff_time'        => '14:00:00',
            'trip_direction'      => 'both',
            'start_date'          => Carbon::today()->addDays(2)->toDateString(),
            'end_date'            => Carbon::today()->addDays(32)->toDateString(),
            'subscription_type'   => 'multi_day',
            'is_active'           => 1,
        ]);

        // --- ولي الأمر 3 (طفلين، طلب ملغي/مرفوض ومكتمل سابقاً) ---
        $p3User = User::create([
            'full_name'         => 'خديجة مصطفى البركي',
            'email'             => 'parent3@darby.ly',
            'phone_number'      => '0935000003',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $p3 = ParentModel::create(['user_id' => $p3User->id, 'is_trusted' => 1]);

        $addr3_main = Address::create([
            'parent_id' => $p3->id,
            'label'     => 'المنزل - النوفليين بالقرب من حديقة النوفليين',
            'lat'       => 32.89920000,
            'lng'       => 13.20310000,
        ]);
        $child4 = Child::create([
            'parent_id'           => $p3->id,
            'school_id'           => $school3->id,
            'address_id'          => $addr3_main->id,
            'full_name'           => 'ريم خالد البركي',
            'birth_date'          => '2015-06-05',
            'gender'              => 'female',
            'grade'               => 5,
            'school_stage'        => 'primary',
            'medical_notes'       => null,
            'notification_radius' => 600,
        ]);
        $child5 = Child::create([
            'parent_id'           => $p3->id,
            'school_id'           => $school3->id,
            'address_id'          => $addr3_main->id,
            'full_name'           => 'يوسف خالد البركي',
            'birth_date'          => '2013-11-22',
            'gender'              => 'male',
            'grade'               => 7,
            'school_stage'        => 'middle',
            'medical_notes'       => 'يحمل نظارات طبية',
            'notification_radius' => 600,
        ]);

        // --- ولي الأمر 4 (حساب جديد لاختبار شحن المحفظة وبدء أول طلب) ---
        $p4User = User::create([
            'full_name'         => 'عمر سالم الفرجاني',
            'email'             => 'parent4@darby.ly',
            'phone_number'      => '0946000004',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $p4 = ParentModel::create(['user_id' => $p4User->id, 'is_trusted' => 1]);

        $addr4_main = Address::create([
            'parent_id' => $p4->id,
            'label'     => 'المنزل - زاوية الدهماني شارع بن عاشور',
            'lat'       => 32.88750000,
            'lng'       => 13.19300000,
        ]);
        $child6 = Child::create([
            'parent_id'           => $p4->id,
            'school_id'           => $school2->id,
            'address_id'          => $addr4_main->id,
            'full_name'           => 'عبد الرحمن عمر الفرجاني',
            'birth_date'          => '2016-09-12',
            'gender'              => 'male',
            'grade'               => 4,
            'school_stage'        => 'primary',
            'medical_notes'       => null,
            'notification_radius' => 450,
        ]);

        return [
            'p1User' => $p1User, 'p1' => $p1, 'addr1_main' => $addr1_main, 'addr1_grandma' => $addr1_grandma, 'child1' => $child1, 'child2' => $child2,
            'p2User' => $p2User, 'p2' => $p2, 'addr2_main' => $addr2_main, 'child3' => $child3,
            'p3User' => $p3User, 'p3' => $p3, 'addr3_main' => $addr3_main, 'child4' => $child4, 'child5' => $child5,
            'p4User' => $p4User, 'p4' => $p4, 'addr4_main' => $addr4_main, 'child6' => $child6,
        ];
    }

    // =========================================================
    // 8. السائقون والمركبات والوثائق (Drivers & Vehicles)
    // =========================================================
    private function seedDriversAndVehicles(array $zones, User $adminUser): array
    {
        $this->command->info('8️⃣ إنشاء حسابات السائقين والمركبات والمستندات (Drivers & Vehicles)...');

        $getZone = fn(string $name) => $zones[$name] ?? array_values($zones)[0];

        // ─── السائق 1: معتمد - حافلة كوستر (صباحي + مسائي) ───
        $d1User = User::create([
            'full_name'         => 'عبد السلام يوسف المصراتي',
            'email'             => 'driver1@darby.ly',
            'phone_number'      => '0921000001',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 4,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $d1 = Driver::create([
            'user_id'                => $d1User->id,
            'national_id'            => '119900112233',
            'license_number'         => 'DL-998877',
            'license_expiry'         => '2028-12-31',
            'status'                 => 'Approved',
            'gender'                 => 'male',
            'shift'                  => 3, // الفترتين
            'morning_go'             => 1,
            'morning_return'         => 1,
            'afternoon_go'           => 1,
            'afternoon_return'       => 1,
            'subscription_type'      => 'both',
            'accepted_gender'        => 'both',
            'school_stages'          => json_encode(['primary', 'middle']),
            'current_lat'            => 32.89000000,
            'current_lng'            => 13.18000000,
            'driver_waiting_minutes' => 10,
            'rating_avg'             => 4.9,
        ]);
        $v1 = Vehicle::create([
            'driver_id'       => $d1->id,
            'type'            => 'Bus',
            'brand'           => 'تويوتا',
            'model'           => 'كوستر Coaster',
            'year'            => 2023,
            'color'           => 'أبيض ملكي',
            'plate_number'    => '5-112233',
            'capacity_manual' => 14,
            'has_ac'          => 1,
            'is_verified'     => 1,
            'status'          => 'Active',
        ]);
        $this->seedDriverDocs($d1->id, $v1->id, $adminUser->id, 'Verified');
        $this->seedSeatSlots($d1->id, [
            'morning_go'       => [14, 2],
            'morning_return'   => [14, 2],
            'afternoon_go'     => [14, 0],
            'afternoon_return' => [14, 0],
        ]);
        foreach ([$getZone('حي الأندلس'), $getZone('بن عاشور'), $getZone('السياحية')] as $zId) {
            DB::table('driver_zone')->insert(['driver_id' => $d1->id, 'zone_id' => $zId]);
        }

        // ─── السائق 2: معتمد - فان هايس (صباحي فقط) ───
        $d2User = User::create([
            'full_name'         => 'طاهر فرج الزنتاني',
            'email'             => 'driver2@darby.ly',
            'phone_number'      => '0932000002',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 4,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $d2 = Driver::create([
            'user_id'                => $d2User->id,
            'national_id'            => '119900445566',
            'license_number'         => 'DL-665544',
            'license_expiry'         => '2027-10-30',
            'status'                 => 'Approved',
            'gender'                 => 'male',
            'shift'                  => 1, // صباحي
            'morning_go'             => 1,
            'morning_return'         => 1,
            'afternoon_go'           => 0,
            'afternoon_return'       => 0,
            'subscription_type'      => 'both',
            'accepted_gender'        => 'both',
            'school_stages'          => json_encode(['primary']),
            'current_lat'            => 32.90500000,
            'current_lng'            => 13.22000000,
            'driver_waiting_minutes' => 8,
            'rating_avg'             => 4.6,
        ]);
        $v2 = Vehicle::create([
            'driver_id'       => $d2->id,
            'type'            => 'Van',
            'brand'           => 'هيونداي',
            'model'           => 'H1 Grand',
            'year'            => 2022,
            'color'           => 'فضي',
            'plate_number'    => '5-667788',
            'capacity_manual' => 8,
            'has_ac'          => 1,
            'is_verified'     => 1,
            'status'          => 'Active',
        ]);
        $this->seedDriverDocs($d2->id, $v2->id, $adminUser->id, 'Verified');
        $this->seedSeatSlots($d2->id, [
            'morning_go'     => [8, 0],
            'morning_return' => [8, 0],
        ]);
        foreach ([$getZone('النوفليين'), $getZone('زاوية الدهماني'), $getZone('الظهرة')] as $zId) {
            DB::table('driver_zone')->insert(['driver_id' => $d2->id, 'zone_id' => $zId]);
        }

        // ─── السائق 3: قيد المراجعة والانتظار (Pending) ───
        $d3User = User::create([
            'full_name'         => 'رمضان عبد القادر المجبري',
            'email'             => 'driver3@darby.ly',
            'phone_number'      => '0943000003',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 4,
            'is_active'         => 0,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $d3 = Driver::create([
            'user_id'                => $d3User->id,
            'national_id'            => '119900778899',
            'license_number'         => 'DL-332211',
            'license_expiry'         => '2029-05-15',
            'status'                 => 'Pending',
            'gender'                 => 'male',
            'shift'                  => 2, // مسائي
            'morning_go'             => 0,
            'morning_return'         => 0,
            'afternoon_go'           => 1,
            'afternoon_return'       => 1,
            'subscription_type'      => 'both',
            'accepted_gender'        => 'both',
            'school_stages'          => json_encode(['primary', 'middle', 'high']),
            'current_lat'            => 32.87500000,
            'current_lng'            => 13.16500000,
            'driver_waiting_minutes' => 12,
        ]);
        $v3 = Vehicle::create([
            'driver_id'       => $d3->id,
            'type'            => 'Bus',
            'brand'           => 'نيسان',
            'model'           => 'سيفيليان',
            'year'            => 2021,
            'color'           => 'ذهبي',
            'plate_number'    => '5-998877',
            'capacity_manual' => 18,
            'has_ac'          => 1,
            'is_verified'     => 0,
            'status'          => 'Active',
        ]);
        $this->seedDriverDocs($d3->id, $v3->id, null, 'Pending');
        $this->seedSeatSlots($d3->id, [
            'afternoon_go'     => [18, 0],
            'afternoon_return' => [18, 0],
        ]);
        foreach ([$getZone('قرجي الشارع الغربي'), $getZone('غوط الشعال')] as $zId) {
            DB::table('driver_zone')->insert(['driver_id' => $d3->id, 'zone_id' => $zId]);
        }

        // ─── السائق 4: مرفوض / معلق لاختبار حالات الرفض (Rejected) ───
        $d4User = User::create([
            'full_name'         => 'سالم خليل الورشفاني',
            'email'             => 'driver4@darby.ly',
            'phone_number'      => '0954000004',
            'password_hash'     => Hash::make($this->defaultPassword),
            'role_id'           => 4,
            'is_active'         => 0,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $d4 = Driver::create([
            'user_id'                => $d4User->id,
            'national_id'            => '119900889900',
            'license_number'         => 'DL-112233',
            'license_expiry'         => '2024-01-01', // منتهية
            'status'                 => 'Rejected',
            'gender'                 => 'male',
            'shift'                  => 1,
            'morning_go'             => 1,
            'morning_return'         => 1,
            'subscription_type'      => 'both',
            'accepted_gender'        => 'both',
            'school_stages'          => json_encode(['primary']),
            'current_lat'            => 32.86000000,
            'current_lng'            => 13.15000000,
        ]);
        $v4 = Vehicle::create([
            'driver_id'       => $d4->id,
            'type'            => 'Van',
            'brand'           => 'كيا',
            'model'           => 'بريستيج',
            'year'            => 2012,
            'color'           => 'أزرق',
            'plate_number'    => '5-334455',
            'capacity_manual' => 7,
            'has_ac'          => 0,
            'is_verified'     => 0,
            'status'          => 'Active',
        ]);
        $this->seedDriverDocs($d4->id, $v4->id, $adminUser->id, 'Rejected', 'رخصة القيادة منتهية وفحص المركبة الفني غير مطابق لشروط الأمان.');

        // تسجيل سجل الرفض في driver_approvals
        DB::table('driver_approvals')->insert([
            'driver_id'        => $d4->id,
            'admin_id'         => $adminUser->id,
            'status'           => 'Rejected',
            'rejection_reason' => 'رخصة القيادة منتهية وفحص المركبة الفني غير مطابق لشروط الأمان والسلامة المدرسية.',
            'created_at'       => now()->subDays(5),
        ]);

        return [
            'd1User' => $d1User, 'd1' => $d1, 'v1' => $v1,
            'd2User' => $d2User, 'd2' => $d2, 'v2' => $v2,
            'd3User' => $d3User, 'd3' => $d3, 'v3' => $v3,
            'd4User' => $d4User, 'd4' => $d4, 'v4' => $v4,
        ];
    }

    private function seedDriverDocs(int $driverId, int $vehicleId, ?int $reviewedBy, string $status = 'Verified', ?string $feedback = null): void
    {
        $docs = [
            'LICENSE'               => "uploads/drivers/docs/license_{$driverId}.jpg",
            'VEHICLE_LOGBOOK'       => "uploads/drivers/docs/logbook_{$driverId}.jpg",
            'INSURANCE'             => "uploads/drivers/docs/insurance_{$driverId}.jpg",
            'CRIMINAL_RECORD'       => "uploads/drivers/docs/criminal_{$driverId}.jpg",
            'TECHNICAL_INSPECTION'  => "uploads/drivers/docs/inspection_{$driverId}.jpg",
        ];

        foreach ($docs as $docType => $url) {
            DB::table('driver_documents')->insert([
                'driver_id'                       => $driverId,
                'vehicle_id'                      => $vehicleId,
                'doc_type'                        => $docType,
                'file_url'                        => $url,
                'license_expiry_date'             => '2028-12-31',
                'insurance_expiry_date'           => '2027-12-31',
                'technical_inspection_expiry_date'=> '2027-06-30',
                'status'                          => $status,
                'reviewed_by'                     => $reviewedBy,
                'feedback'                        => $feedback,
                'uploaded_at'                     => now()->subDays(10),
                'reviewed_at'                     => $reviewedBy ? now()->subDays(9) : null,
            ]);
        }
    }

    private function seedSeatSlots(int $driverId, array $slots): void
    {
        foreach ($slots as $slot => [$total, $reserved]) {
            DriverSeatSlot::create([
                'driver_id'      => $driverId,
                'slot'           => $slot,
                'total_seats'    => $total,
                'reserved_seats' => $reserved,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    // =========================================================
    // 9. طلبات الاشتراك وتفاصيل الأطفال (Subscription Requests)
    // =========================================================
    private function seedSubscriptionRequests(array $p, array $d, array $schools): array
    {
        $this->command->info('9️⃣ إنشاء طلبات الاشتراك بكافة الحالات (Accepted, Pending, Rejected, Cancelled)...');

        $today = Carbon::today();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth   = $today->copy()->endOfMonth();

        // ─── الطلب 1: مقبول (Accepted) - ولي الأمر 1 مع السائق 1 لطفلين (مع خصم أخوة 10%) ───
        // طفلين: السعر الأساسي = 300 د.ل لكل طفل = 600 د.ل
        // خصم 10% للأخوة = 60 د.ل (30 د.ل لكل طفل)
        // الإجمالي بعد الخصم = 540 د.ل
        // عمولة المنصة 8% = 43.20 د.ل -> صافي السائق = 496.80 د.ل (248.40 د.ل لكل طفل)
        $req1 = SubscriptionRequest::create([
            'parent_id'                   => $p['p1']->id,
            'driver_id'                   => $d['d1']->id,
            'status'                      => 'accepted',
            'notes'                       => 'يرجى الالتزام بالانتظار أمام البيت وتوخي الحذر أثناء الصعود والنزول.',
            'children_count'              => 2,
            'pickup_time'                 => '07:00:00',
            'dropoff_time'                => '13:45:00',
            'max_waiting_time'            => 15,
            'total_price'                 => 600.00,
            'discount_amount'             => 60.00,
            'total_amount_after_discount' => 540.00,
            'responded_at'                => now()->subDays(5),
            'created_at'                  => now()->subDays(6),
        ]);

        DB::table('request_children')->insert([
            [
                'request_id'                  => $req1->id,
                'child_id'                    => $p['child1']->id,
                'child_notes'                 => 'حساسية خفيفة',
                'price_per_child'             => 300.00,
                'discount_amount'             => 30.00,
                'total_amount_after_discount' => 270.00,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => 'two_way',
                'timing'                      => 'morning',
                'start_date'                  => $startOfMonth->toDateString(),
                'end_date'                    => $endOfMonth->toDateString(),
                'working_days_count'          => 22,
                'distance_km'                 => 6.50,
                'trip_price'                  => 270.00,
                'driver_net_price'            => 248.40, // بعد خصم 8% عمولة المنصة
                'created_at'                  => now()->subDays(6),
                'updated_at'                  => now()->subDays(5),
            ],
            [
                'request_id'                  => $req1->id,
                'child_id'                    => $p['child2']->id,
                'child_notes'                 => null,
                'price_per_child'             => 300.00,
                'discount_amount'             => 30.00,
                'total_amount_after_discount' => 270.00,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => 'two_way',
                'timing'                      => 'morning',
                'start_date'                  => $startOfMonth->toDateString(),
                'end_date'                    => $endOfMonth->toDateString(),
                'working_days_count'          => 22,
                'distance_km'                 => 6.50,
                'trip_price'                  => 270.00,
                'driver_net_price'            => 248.40,
                'created_at'                  => now()->subDays(6),
                'updated_at'                  => now()->subDays(5),
            ],
        ]);

        // ─── الطلب 2: قيد الانتظار (Pending) - ولي الأمر 2 مع السائق 2 لطفل واحد ───
        $req2 = SubscriptionRequest::create([
            'parent_id'                   => $p['p2']->id,
            'driver_id'                   => $d['d2']->id,
            'status'                      => 'pending',
            'notes'                       => 'الطفل في الصف الثالث، نأمل تأكيد قبول الطلب بأقرب وقت.',
            'children_count'              => 1,
            'pickup_time'                 => '07:15:00',
            'dropoff_time'                => '14:00:00',
            'max_waiting_time'            => 10,
            'total_price'                 => 320.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 320.00,
            'created_at'                  => now()->subHours(8),
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $req2->id,
            'child_id'                    => $p['child3']->id,
            'child_notes'                 => 'الرجاء الاتصال قبل الوصول بدقيقتين',
            'price_per_child'             => 320.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 320.00,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'two_way',
            'timing'                      => 'morning',
            'start_date'                  => $today->copy()->addDays(2)->toDateString(),
            'end_date'                    => $today->copy()->addDays(32)->toDateString(),
            'working_days_count'          => 22,
            'distance_km'                 => 5.20,
            'trip_price'                  => 320.00,
            'driver_net_price'            => 294.40, // 320 - 8%
            'created_at'                  => now()->subHours(8),
            'updated_at'                  => now()->subHours(8),
        ]);

        // ─── الطلب 3: مرفوض (Rejected) - ولي الأمر 3 مع السائق 2 ───
        $req3 = SubscriptionRequest::create([
            'parent_id'                   => $p['p3']->id,
            'driver_id'                   => $d['d2']->id,
            'status'                      => 'rejected',
            'notes'                       => 'طلب نقل صباحي ومسائي.',
            'rejection_reason'            => 'نعتذر منكم، سعة المركبة ممتلئة بالكامل على هذا الخط ومواعيد المدرسة غير متوافقة.',
            'children_count'              => 1,
            'pickup_time'                 => '07:30:00',
            'dropoff_time'                => '14:30:00',
            'max_waiting_time'            => 10,
            'total_price'                 => 350.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 350.00,
            'responded_at'                => now()->subDays(2),
            'created_at'                  => now()->subDays(3),
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $req3->id,
            'child_id'                    => $p['child4']->id,
            'price_per_child'             => 350.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 350.00,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'two_way',
            'timing'                      => 'morning',
            'start_date'                  => $today->copy()->subDays(10)->toDateString(),
            'end_date'                    => $today->copy()->addDays(20)->toDateString(),
            'working_days_count'          => 22,
            'distance_km'                 => 7.80,
            'trip_price'                  => 350.00,
            'driver_net_price'            => 322.00,
            'created_at'                  => now()->subDays(3),
            'updated_at'                  => now()->subDays(2),
        ]);

        // ─── الطلب 4: ملغي من ولي الأمر (Cancelled) ───
        $req4 = SubscriptionRequest::create([
            'parent_id'                   => $p['p4']->id,
            'driver_id'                   => $d['d1']->id,
            'status'                      => 'cancelled',
            'notes'                       => 'تم إلغاء الطلب لتغيير مدرسة الطفل.',
            'children_count'              => 1,
            'pickup_time'                 => '07:10:00',
            'dropoff_time'                => '13:50:00',
            'max_waiting_time'            => 15,
            'total_price'                 => 300.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 300.00,
            'created_at'                  => now()->subDays(8),
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $req4->id,
            'child_id'                    => $p['child6']->id,
            'price_per_child'             => 300.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 300.00,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'two_way',
            'timing'                      => 'morning',
            'start_date'                  => $today->copy()->subDays(8)->toDateString(),
            'end_date'                    => $today->copy()->addDays(22)->toDateString(),
            'working_days_count'          => 22,
            'distance_km'                 => 4.00,
            'trip_price'                  => 300.00,
            'driver_net_price'            => 276.00,
            'created_at'                  => now()->subDays(8),
            'updated_at'                  => now()->subDays(8),
        ]);

        return [
            'req1' => $req1,
            'req2' => $req2,
            'req3' => $req3,
            'req4' => $req4,
        ];
    }

    // =========================================================
    // 10. الاشتراكات المفعّلة والنشطة (Active Subscriptions)
    // =========================================================
    private function seedActiveSubscriptions(array $req, array $p, array $d, array $schools): array
    {
        $this->command->info('🔟 إنشاء الاشتراكات المفعّلة والنشطة (Active Subscriptions)...');

        $schoolsList = array_values($schools);
        $school1 = $schoolsList[0];
        $school2 = $schoolsList[1];
        $school3 = $schoolsList[2];

        // 1. اشتراك نشط للطفل الأول (سند)
        $activeSub1 = ActiveSubscription::create([
            'subscription_request_id' => $req['req1']->id,
            'child_id'                => $p['child1']->id,
            'driver_id'               => $d['d1']->id,
            'parent_id'               => $p['p1User']->id,
            'status'                  => 'active',
            'sort_order'              => 1,
            'pickup_lat'              => '32.89250000',
            'pickup_lng'              => '13.17520000',
            'pickup_label'            => 'منزل ولي الأمر طه (حي الأندلس)',
            'dropoff_lat'             => (string)$school1->lat,
            'dropoff_lng'             => (string)$school1->lng,
            'dropoff_label'           => $school1->name,
            'pickup_time'             => '07:00:00',
            'dropoff_time'            => '13:45:00',
        ]);

        // 2. اشتراك نشط للطفل الثاني (مروة)
        $activeSub2 = ActiveSubscription::create([
            'subscription_request_id' => $req['req1']->id,
            'child_id'                => $p['child2']->id,
            'driver_id'               => $d['d1']->id,
            'parent_id'               => $p['p1User']->id,
            'status'                  => 'active',
            'sort_order'              => 2,
            'pickup_lat'              => '32.89250000',
            'pickup_lng'              => '13.17520000',
            'pickup_label'            => 'منزل ولي الأمر طه (حي الأندلس)',
            'dropoff_lat'             => (string)$school1->lat,
            'dropoff_lng'             => (string)$school1->lng,
            'dropoff_label'           => $school1->name,
            'pickup_time'             => '07:00:00',
            'dropoff_time'            => '13:45:00',
        ]);

        // 3. اشتراك مكتمل سابقاً (Completed)
        $activeSub3 = ActiveSubscription::create([
            'subscription_request_id' => $req['req1']->id,
            'child_id'                => $p['child5']->id,
            'driver_id'               => $d['d1']->id,
            'parent_id'               => $p['p3User']->id,
            'status'                  => 'completed',
            'sort_order'              => 3,
            'pickup_lat'              => '32.89920000',
            'pickup_lng'              => '13.20310000',
            'pickup_label'            => 'النوفليين',
            'dropoff_lat'             => (string)$school3->lat,
            'dropoff_lng'             => (string)$school3->lng,
            'dropoff_label'           => $school3->name,
            'pickup_time'             => '07:20:00',
            'dropoff_time'            => '14:00:00',
        ]);

        // 4. اشتراك معلق بسبب عدم السداد (suspended_unpaid)
        $activeSub4 = ActiveSubscription::create([
            'subscription_request_id' => $req['req1']->id,
            'child_id'                => $p['child6']->id,
            'driver_id'               => $d['d2']->id,
            'parent_id'               => $p['p4User']->id,
            'status'                  => 'suspended_unpaid',
            'sort_order'              => 4,
            'pickup_lat'              => '32.88750000',
            'pickup_lng'              => '13.19300000',
            'pickup_label'            => 'زاوية الدهماني',
            'dropoff_lat'             => (string)$school2->lat,
            'dropoff_lng'             => (string)$school2->lng,
            'dropoff_label'           => $school2->name,
            'pickup_time'             => '07:15:00',
            'dropoff_time'            => '13:50:00',
        ]);

        return [
            'activeSub1' => $activeSub1,
            'activeSub2' => $activeSub2,
            'activeSub3' => $activeSub3,
            'activeSub4' => $activeSub4,
        ];
    }

    // =========================================================
    // 11. المسارات والوقفات المجدولة (Routes & Route Stops)
    // =========================================================
    private function seedRoutesAndStops(array $d, array $subs, array $schools): array
    {
        $this->command->info('1️⃣1️⃣ إنشاء المسارات والوقفات المحسوبة (Routes & Route Stops)...');

        $school1 = array_values($schools)[0];

        // مسار الذهاب الصباحي للسائق 1
        $routeMorning = Route::create([
            'subscription_request_id' => $subs['activeSub1']->subscription_request_id,
            'driver_id'               => $d['d1']->id,
            'vehicle_id'              => $d['v1']->id,
            'route_name'              => 'مسار الذهاب الصباحي - حي الأندلس إلى مدرسة الجيل الجديد',
            'route_type'              => 'Morning',
            'shift_slot'              => 'morning_go',
            'start_time'              => '06:45:00',
            'total_distance'          => 7.20,
            'estimated_duration'      => 30,
            'status'                  => 'Active',
            'optimized_points'        => [
                ['lat' => 32.89000, 'lng' => 13.18000, 'type' => 'start'],
                ['lat' => 32.89250, 'lng' => 13.17520, 'type' => 'pickup'],
                ['lat' => 32.89200, 'lng' => 13.16800, 'type' => 'school'],
            ],
        ]);

        // تحديث route_id في active_subscriptions
        $subs['activeSub1']->update(['route_id' => $routeMorning->id]);
        $subs['activeSub2']->update(['route_id' => $routeMorning->id]);

        // وقفات المسار الصباحي
        $stop1 = RouteStop::create([
            'route_id'       => $routeMorning->id,
            'stop_type'      => 'home',
            'child_id'       => $subs['activeSub1']->child_id,
            'lat'            => 32.89250000,
            'lng'            => 13.17520000,
            'label'          => 'استلام سند ومروة طه القموضي',
            'sequence_order' => 1,
        ]);

        $stop2 = RouteStop::create([
            'route_id'       => $routeMorning->id,
            'stop_type'      => 'school',
            'school_id'      => $school1->id,
            'lat'            => $school1->lat,
            'lng'            => $school1->lng,
            'label'          => $school1->name,
            'sequence_order' => 2,
        ]);

        // مسار العودة الظهيرة للسائق 1
        $routeAfternoon = Route::create([
            'subscription_request_id' => $subs['activeSub1']->subscription_request_id,
            'driver_id'               => $d['d1']->id,
            'vehicle_id'              => $d['v1']->id,
            'route_name'              => 'مسار العودة الظهري - مدرسة الجيل الجديد إلى حي الأندلس',
            'route_type'              => 'Morning',
            'shift_slot'              => 'morning_return',
            'start_time'              => '13:30:00',
            'total_distance'          => 7.20,
            'estimated_duration'      => 30,
            'status'                  => 'Active',
        ]);

        RouteStop::create([
            'route_id'       => $routeAfternoon->id,
            'stop_type'      => 'school',
            'school_id'      => $school1->id,
            'lat'            => $school1->lat,
            'lng'            => $school1->lng,
            'label'          => 'صعود الطلاب من ' . $school1->name,
            'sequence_order' => 1,
        ]);

        RouteStop::create([
            'route_id'       => $routeAfternoon->id,
            'stop_type'      => 'home',
            'child_id'       => $subs['activeSub1']->child_id,
            'lat'            => 32.89250000,
            'lng'            => 13.17520000,
            'label'          => 'تسليم سند ومروة للمنزل',
            'sequence_order' => 2,
        ]);

        return [
            'routeMorning'   => $routeMorning,
            'routeAfternoon' => $routeAfternoon,
            'stop1'          => $stop1,
            'stop2'          => $stop2,
        ];
    }

    // =========================================================
    // 12. الرحلات والتتبع المباشر (Trips & Live Tracking & QR Events)
    // =========================================================
    private function seedTripsAndTracking(array $d, array $r, array $subs, array $schools, User $adminUser): void
    {
        $this->command->info('1️⃣2️⃣ إنشاء الرحلات بمختلف حالاتها (Completed, In Progress, Pending, Breakdown)...');

        $school1 = array_values($schools)[0];
        $today = Carbon::today();

        // ─── الرحلة 1: رحلة مكتملة لليوم السابق (Completed) ───
        $tripCompleted = Trip::create([
            'driver_id'            => $d['d1']->id,
            'route_id'             => $r['routeMorning']->id,
            'trip_type'            => 'Morning',
            'trip_date'            => $today->copy()->subDay()->toDateString(),
            'status'               => 'completed',
            'scheduled_at'         => $today->copy()->subDay()->setTime(7, 0),
            'started_at'           => $today->copy()->subDay()->setTime(7, 3),
            'completed_at'         => $today->copy()->subDay()->setTime(7, 35),
            'scheduled_start_time' => $today->copy()->subDay()->setTime(7, 0),
            'actual_start_time'    => $today->copy()->subDay()->setTime(7, 3),
            'driver_attendance'    => 1,
            'start_lat'            => 32.89000000,
            'start_lng'            => 13.18000000,
        ]);

        // حضور الطلاب في الرحلة المكتملة
        TripStudentAttendance::create([
            'trip_id'           => $tripCompleted->id,
            'child_id'          => $subs['activeSub1']->child_id,
            'attendance_status' => 'present',
        ]);
        TripStudentAttendance::create([
            'trip_id'           => $tripCompleted->id,
            'child_id'          => $subs['activeSub2']->child_id,
            'attendance_status' => 'present',
        ]);

        // أحداث مسح QR والصعود/النزول
        TripEvent::create([
            'trip_id'         => $tripCompleted->id,
            'child_id'        => $subs['activeSub1']->child_id,
            'subscription_id' => $subs['activeSub1']->id,
            'action_type'     => 'picked_up',
            'trip_type'       => 'ذهاب',
            'location_lat'    => 32.89250000,
            'location_lng'    => 13.17520000,
            'scanned_at'      => $today->copy()->subDay()->setTime(7, 10),
            'trip_cost'       => 12.27,
        ]);
        TripEvent::create([
            'trip_id'         => $tripCompleted->id,
            'child_id'        => $subs['activeSub1']->child_id,
            'subscription_id' => $subs['activeSub1']->id,
            'action_type'     => 'dropped_off',
            'trip_type'       => 'ذهاب',
            'location_lat'    => $school1->lat,
            'location_lng'    => $school1->lng,
            'scanned_at'      => $today->copy()->subDay()->setTime(7, 32),
            'trip_cost'       => 12.27,
        ]);

        // وقفات الرحلة المكتملة
        TripStop::create([
            'trip_id'        => $tripCompleted->id,
            'route_stop_id'  => $r['stop1']->id,
            'stop_type'      => 'home',
            'child_id'       => $subs['activeSub1']->child_id,
            'lat'            => 32.89250000,
            'lng'            => 13.17520000,
            'label'          => 'منزل سند القموضي',
            'sequence_order' => 1,
            'status'         => 'boarded',
            'eta_minutes'    => 0,
        ]);
        TripStop::create([
            'trip_id'        => $tripCompleted->id,
            'route_stop_id'  => $r['stop2']->id,
            'stop_type'      => 'school',
            'school_id'      => $school1->id,
            'lat'            => $school1->lat,
            'lng'            => $school1->lng,
            'label'          => $school1->name,
            'sequence_order' => 2,
            'status'         => 'dropped_off_school',
            'eta_minutes'    => 0,
        ]);

        // ─── الرحلة 2: رحلة حية جارية الآن (in_progress) لاختبار الرادار والتتبع ───
        $tripInProgress = Trip::create([
            'driver_id'            => $d['d1']->id,
            'route_id'             => $r['routeMorning']->id,
            'trip_type'            => 'Morning',
            'trip_date'            => $today->toDateString(),
            'status'               => 'in_progress',
            'scheduled_at'         => now()->subMinutes(25),
            'started_at'           => now()->subMinutes(20),
            'scheduled_start_time' => now()->subMinutes(25),
            'actual_start_time'    => now()->subMinutes(20),
            'driver_attendance'    => 1,
            'start_lat'            => 32.89000000,
            'start_lng'            => 13.18000000,
        ]);

        // وقفات الرحلة الجارية
        $inProgressStop1 = TripStop::create([
            'trip_id'        => $tripInProgress->id,
            'route_stop_id'  => $r['stop1']->id,
            'stop_type'      => 'home',
            'child_id'       => $subs['activeSub1']->child_id,
            'lat'            => 32.89250000,
            'lng'            => 13.17520000,
            'label'          => 'استلام سند ومروة القموضي',
            'sequence_order' => 1,
            'status'         => 'boarded', // تم الركوب
            'eta_minutes'    => 0,
        ]);

        $inProgressStop2 = TripStop::create([
            'trip_id'        => $tripInProgress->id,
            'route_stop_id'  => $r['stop2']->id,
            'stop_type'      => 'school',
            'school_id'      => $school1->id,
            'lat'            => $school1->lat,
            'lng'            => $school1->lng,
            'label'          => $school1->name,
            'sequence_order' => 2,
            'status'         => 'pending', // في الطريق للمدرسة
            'eta_minutes'    => 8,
            'eta'            => now()->addMinutes(8)->format('H:i:s'),
        ]);

        // نقاط التتبع المباشر للرحلة الجارية (GPS Tracking Breadcrumbs)
        for ($i = 5; $i >= 0; $i--) {
            TripTracking::create([
                'trip_id'     => $tripInProgress->id,
                'latitude'    => 32.89100000 + ($i * 0.0003),
                'longitude'   => 13.17200000 + ($i * 0.0004),
                'speed'       => 38.5 + rand(-5, 5),
                'accuracy'    => 4.2,
                'recorded_at' => now()->subMinutes($i * 3),
            ]);
        }

        // ─── الرحلة 3: رحلة قيد الانتظار لموعدها (Pending) ───
        Trip::create([
            'driver_id'            => $d['d1']->id,
            'route_id'             => $r['routeAfternoon']->id,
            'trip_type'            => 'Morning',
            'trip_date'            => $today->toDateString(),
            'status'               => 'pending',
            'scheduled_at'         => $today->copy()->setTime(13, 30),
            'scheduled_start_time' => $today->copy()->setTime(13, 30),
            'driver_attendance'    => null,
        ]);

        // ─── الرحلة 4: رحلة معلقة لعطل فني (suspended_breakdown) ───
        $tripBreakdown = Trip::create([
            'driver_id'            => $d['d2']->id,
            'route_id'             => null,
            'trip_type'            => 'Morning',
            'trip_date'            => $today->copy()->subDays(3)->toDateString(),
            'status'               => 'suspended_breakdown',
            'suspension_reason'    => 'عطل مفاجئ في مضخة الوقود أثناء التوجه لمنطقة بن عاشور، تم إشعار أولياء الأمور وتأمين حافلة مساندة.',
            'scheduled_at'         => $today->copy()->subDays(3)->setTime(7, 15),
            'started_at'           => $today->copy()->subDays(3)->setTime(7, 18),
            'driver_attendance'    => 1,
        ]);

        // ─── طلبات التأكيد اليدوي للرحلات (Trip Manual Confirmations) ───
        TripManualConfirmation::create([
            'trip_id'        => $tripCompleted->id,
            'trip_stop_id'   => $inProgressStop1->id,
            'child_id'       => $subs['activeSub1']->child_id,
            'parent_id'      => $subs['activeSub1']->parent_id,
            'driver_id'      => $d['d1']->id,
            'question_type'  => 'pickup',
            'target_status'  => 'boarded',
            'status'         => 'confirmed',
            'responded_at'   => now()->subMinutes(15),
        ]);

        // ─── نزاعات الرحلات (Trip Disputes) ───
        TripDispute::create([
            'trip_id'          => $tripCompleted->id,
            'parent_id'        => $subs['activeSub1']->parent_id,
            'driver_id'        => $d['d1']->id,
            'reason'           => 'تأخر السائق 12 دقيقة في نقطة الانطلاق مما أدى لوصول الطفل مع بداية الطابور الصباحي.',
            'status'           => 'resolved',
            'resolution_notes' => 'تم مراجعة سجلات GPS والتواصل مع السائق لتنبيهه بالالتزام بالمسار البديل عند الازدحام.',
            'resolved_by'      => $adminUser->id,
            'resolved_at'      => now()->subHours(4),
        ]);

        // ─── إشعارات الطوارئ (SOS Alerts) ───
        DB::table('sos_alerts')->insert([
            'trip_id'         => $tripBreakdown->id,
            'alert_type'      => 'BREAKDOWN',
            'latitude'        => 32.90300000,
            'longitude'       => 13.21900000,
            'status'          => 'Resolved',
            'resolved_by'     => $adminUser->id,
            'resolution_note' => 'تم التدخل وإرسال سائق احتياطي لنقل الطلاب لمنازلهم بأمان.',
            'triggered_at'    => now()->subDays(3)->setTime(7, 40),
            'resolved_at'     => now()->subDays(3)->setTime(8, 10),
        ]);
    }

    // =========================================================
    // 13. النظام المالي الشامل (Wallets, Escrow, Platform Finances, Ledger)
    // =========================================================
    private function seedFinancialSystem(array $p, array $d, array $req, array $subs, User $adminUser): void
    {
        $this->command->info('1️⃣3️⃣ إنشاء المحافظ، الأرصدة، العمليات المالية، وسجل الأستاذ العام (Ledger & Finances)...');

        // 1. محافظ أولياء الأمور والسائقين (Bavix Wallets)
        $walletsData = [
            ['holder_id' => $p['p1User']->id, 'name' => 'محفظة ولي الأمر طه القموضي', 'balance' => 500.00], // 500 د.ل
            ['holder_id' => $p['p2User']->id, 'name' => 'محفظة ولي الأمر محمود الورفلي', 'balance' => 250.00], // 250 د.ل
            ['holder_id' => $p['p3User']->id, 'name' => 'محفظة ولي الأمر خديجة البركي', 'balance' => 100.00],
            ['holder_id' => $p['p4User']->id, 'name' => 'محفظة ولي الأمر عمر الفرجاني', 'balance' => 50.00],
            ['holder_id' => $d['d1User']->id, 'name' => 'محفظة السائق عبد السلام المصراتي', 'balance' => 650.00],
            ['holder_id' => $d['d2User']->id, 'name' => 'محفظة السائق طاهر الزنتاني', 'balance' => 320.00],
            ['holder_id' => $d['d3User']->id, 'name' => 'محفظة السائق رمضان المجبري', 'balance' => 0.00],
        ];

        foreach ($walletsData as $w) {
            DB::table('wallets')->insert([
                'holder_type'    => 'App\Models\User',
                'holder_id'      => $w['holder_id'],
                'name'           => $w['name'],
                'slug'           => 'default',
                'uuid'           => Str::uuid()->toString(),
                'balance'        => (int)($w['balance'] * 100), // بالسنتات/الدرهم
                'decimal_places' => 2,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // 2. سجل حركات المنصة المالية (Platform Finances)
        PlatformFinance::create([
            'subscription_request_id'   => $req['req1']->id,
            'active_subscription_id'   => $subs['activeSub1']->id,
            'parent_id'                 => $p['p1User']->id,
            'driver_id'                 => $d['d1']->id,
            'total_amount'              => 540.00,
            'platform_commission_rate'  => 8.00,
            'platform_commission_amount'=> 43.20,
            'driver_net_amount'         => 496.80,
            'refunded_amount'           => 0.00,
            'compensation_fee'          => 0.00,
            'status'                    => 'completed',
            'held_at'                   => now()->subDays(6),
            'settled_at'                => now()->subDay(),
            'notes'                     => 'تسوية مستحقات النقل المدرسي للشهر الحالي بنجاح.',
        ]);

        PlatformFinance::create([
            'subscription_request_id'   => $req['req2']->id,
            'active_subscription_id'   => null,
            'parent_id'                 => $p['p2User']->id,
            'driver_id'                 => $d['d2']->id,
            'total_amount'              => 320.00,
            'platform_commission_rate'  => 8.00,
            'platform_commission_amount'=> 25.60,
            'driver_net_amount'         => 294.40,
            'refunded_amount'           => 0.00,
            'compensation_fee'          => 0.00,
            'status'                    => 'held', // محجوز بانتظار إتمام الرحلات
            'held_at'                   => now()->subHours(8),
            'notes'                     => 'قيمة الاشتراك محجوزة في الضمان المالي لحين انتهاء الشهر.',
        ]);

        // 3. خزينة الضمان المركزية (Master Escrow Vault)
        DB::table('master_escrow_vault')->insert([
            'parents_escrow_pool'   => 32000, // 320 د.ل محجوزة
            'driver_pending_pool'   => 29440,
            'driver_available_pool' => 49680,
            'platform_revenue_pool' => 6880,  // 68.80 د.ل أرباح عمولة المنصة
            'penalty_pool'          => 0,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // 4. سجل الأستاذ العام المالي (Financial Ledger)
        $txId1 = Str::uuid()->toString();
        FinancialLedger::create([
            'transaction_id'      => $txId1,
            'reference_number'    => 'TX-DARBY-2026-001',
            'source_account'      => 'parent_' . $p['p1User']->id,
            'destination_account' => 'escrow_vault',
            'amount'              => 54000,
            'balance_before'      => 104000,
            'balance_after'       => 50000,
            'type'                => 'subscription_payment',
            'status'              => 'completed',
            'metadata'            => ['request_id' => $req['req1']->id, 'children_count' => 2],
            'created_at'          => now()->subDays(6),
        ]);

        // 5. الفواتير (Invoices)
        Invoice::create([
            'subscription_request_id' => $req['req1']->id,
            'parent_id'               => $p['p1User']->id,
            'driver_id'               => $d['d1']->id,
            'invoice_number'          => 'INV-2026-00101',
            'amount'                  => 540.00,
            'type'                    => 'monthly',
            'status'                  => 'paid',
            'due_date'                => Carbon::today()->toDateString(),
            'subscription_type'       => 'multi_day',
            'total_trips'             => 44,
            'completed_trips'         => 44,
            'driver_absences'         => 0,
            'student_absences'        => 0,
            'paid_at'                 => now()->subDays(6),
        ]);

        Invoice::create([
            'subscription_request_id' => $req['req2']->id,
            'parent_id'               => $p['p2User']->id,
            'driver_id'               => $d['d2']->id,
            'invoice_number'          => 'INV-2026-00102',
            'amount'                  => 320.00,
            'type'                    => 'proforma',
            'status'                  => 'pending',
            'due_date'                => Carbon::today()->addDays(3)->toDateString(),
            'subscription_type'       => 'multi_day',
            'total_trips'             => 22,
            'completed_trips'         => 0,
            'driver_absences'         => 0,
            'student_absences'        => 0,
        ]);

        // 0. طرق الدفع المعتمدة في المنصة (Payment Methods)
        $pmSadad = \App\Models\Shared\PaymentMethod::create([
            'name_ar'         => 'خدمة سداد (Sadad)',
            'name_en'         => 'Sadad Payment',
            'code'            => 'sadad',
            'target_audience' => 'both',
            'processing_type' => 'instant_simulation',
            'icon_url'        => '/assets/icons/payments/sadad.png',
            'min_amount'      => 1.00,
            'max_amount'      => 5000.00,
            'instructions_ar' => 'الدفع الإلكتروني المباشر عبر المحفظة الإلكترونية سداد ليبيانا.',
            'is_active'       => true,
            'sort_order'      => 1,
        ]);

        $pmTadawul = \App\Models\Shared\PaymentMethod::create([
            'name_ar'         => 'تداول / بطاقة مصرفية (Tadawul)',
            'name_en'         => 'Tadawul / Debit Card',
            'code'            => 'tadawul',
            'target_audience' => 'both',
            'processing_type' => 'instant_simulation',
            'icon_url'        => '/assets/icons/payments/tadawul.png',
            'min_amount'      => 5.00,
            'max_amount'      => 10000.00,
            'instructions_ar' => 'الدفع الفوري ببطاقات السحب الآلي عبر شبكة تداول الوطنية ومصرف التجارة والتنمية.',
            'is_active'       => true,
            'sort_order'      => 2,
        ]);

        $pmNcb = \App\Models\Shared\PaymentMethod::create([
            'name_ar'         => 'المصرف التجاري الوطني (تحويل بنكي)',
            'name_en'         => 'National Commercial Bank',
            'code'            => 'ncb_bank',
            'target_audience' => 'both',
            'processing_type' => 'manual_proof',
            'account_name'    => 'شركة دربي لنقل الطلاب ذ.م.م',
            'account_number'  => '020-1234567-001',
            'iban'            => 'LY98NCBL0200001234567001',
            'icon_url'        => '/assets/icons/payments/ncb.png',
            'min_amount'      => 50.00,
            'max_amount'      => 50000.00,
            'instructions_ar' => 'التحويل المباشر لحساب الشركة المصرفي لدى التجاري الوطني مع إرفاق صورة إشعار الخصم ورقم الإحالة.',
            'is_active'       => true,
            'sort_order'      => 3,
        ]);

        $pmCash = \App\Models\Shared\PaymentMethod::create([
            'name_ar'         => 'إيداع نقدي بمقر الشركة',
            'name_en'         => 'Cash Deposit at HQ',
            'code'            => 'cash_hq',
            'target_audience' => 'driver',
            'processing_type' => 'manual_proof',
            'icon_url'        => '/assets/icons/payments/cash.png',
            'min_amount'      => 50.00,
            'max_amount'      => 10000.00,
            'instructions_ar' => 'زيارة مقر شركة دربي الرئيسي بطرابلس - شارع بن عاشور وتسليم الإيداع النقدي للخزينة واستلام الإيصال المالي.',
            'is_active'       => true,
            'sort_order'      => 4,
        ]);

        // 6. طلبات شحن المحفظة لأولياء الأمور (Parent Recharge Requests)
        RechargeRequest::create([
            'parent_id'         => $p['p1User']->id,
            'payment_method_id' => $pmNcb->id,
            'amount'            => 300.00,
            'payment_method'    => 'ncb_bank',
            'reference_number'  => 'REF-NCB-987654',
            'transaction_ref'   => 'TXN-MOCK-PRNT-001',
            'status'            => 'completed',
            'notes'             => 'إيداع عبر تطبيق المصرف التجاري الوطني NCB',
            'admin_id'          => $adminUser->id,
            'completed_at'      => now()->subDays(6),
        ]);

        RechargeRequest::create([
            'parent_id'         => $p['p2User']->id,
            'payment_method_id' => $pmSadad->id,
            'amount'            => 200.00,
            'payment_method'    => 'sadad',
            'reference_number'  => 'REF-SADAD-112233',
            'transaction_ref'   => 'TXN-MOCK-PRNT-002',
            'session_token'     => 'MOCK_SESS_DEMO_TOKEN_12345',
            'status'            => 'pending',
            'notes'             => 'شحن فوري قيد المحاكاة والتأكيد',
        ]);

        // 6-ب. طلبات شحن محافظ السائقين المحكومة بالإيصالات (Driver Recharge Requests)
        \App\Models\Driver\DriverRechargeRequest::create([
            'driver_id'         => $d['d1']->id,
            'payment_method_id' => $pmNcb->id,
            'amount'            => 500.00,
            'reference_number'  => 'TRF-NCB-88776655',
            'proof_image_url'   => '/storage/recharges/drivers/proof_demo_1.jpg',
            'status'            => \App\Models\Driver\DriverRechargeRequest::STATUS_APPROVED,
            'admin_id'          => $adminUser->id,
            'notes'             => 'تم استلام الحوالة ومطابقة كشف حساب المصرف.',
            'approved_at'       => now()->subDays(4),
        ]);

        \App\Models\Driver\DriverRechargeRequest::create([
            'driver_id'         => $d['d2']->id,
            'payment_method_id' => $pmCash->id,
            'amount'            => 200.00,
            'reference_number'  => 'CSH-REC-2026-901',
            'proof_image_url'   => '/storage/recharges/drivers/proof_demo_2.jpg',
            'status'            => \App\Models\Driver\DriverRechargeRequest::STATUS_PENDING,
            'notes'             => 'إيداع نقدي في فرع الشركة قيد مراجعة وتأكيد الإدارة.',
        ]);

        // 7. طلبات سحب الأرباح للسائقين (Withdrawal Requests)
        WithdrawalRequest::create([
            'driver_id'                 => $d['d1']->id,
            'amount'                    => 400.00,
            'wallet_balance_at_request' => 650.00,
            'status'                    => 'pending',
            'payment_method_details'    => [
                'bank_name'      => 'مصرف الجمهورية - فرع حي الأندلس',
                'account_number' => 'LY3300100200300400500',
                'account_name'   => 'عبد السلام يوسف المصراتي',
            ],
            'created_at'                => now()->subHours(5),
        ]);

        WithdrawalRequest::create([
            'driver_id'                 => $d['d2']->id,
            'amount'                    => 200.00,
            'wallet_balance_at_request' => 320.00,
            'status'                    => 'approved',
            'payment_method_details'    => [
                'bank_name'      => 'مصرف الأمان - فرع بن عاشور',
                'account_number' => 'LY4400200300400500600',
                'account_name'   => 'طاهر فرج الزنتاني',
            ],
            'admin_id'                  => $adminUser->id,
            'processed_at'              => now()->subDays(3),
        ]);
    }

    // =========================================================
    // 14. التقييمات والشكاوى (Reviews & Complaints)
    // =========================================================
    private function seedReviewsAndComplaints(array $p, array $d, User $adminUser): void
    {
        $this->command->info('1️⃣4️⃣ إنشاء التقييمات والشكاوى (Reviews & Complaints)...');

        // تقييم إيجابي
        DriverReview::create([
            'subscription_request_id' => null,
            'parent_id'               => $p['p1User']->id,
            'driver_id'               => $d['d1']->id,
            'rating'                  => 5,
            'comment'                 => 'السائق عبد السلام قمة في الأخلاق والالتزام بالمواعيد، يعامل الأطفال كأبنائه والمركبة نظيفة ومكيفة دائماً.',
            'status'                  => 'active',
        ]);

        // تقييم متوسط
        DriverReview::create([
            'subscription_request_id' => null,
            'parent_id'               => $p['p2User']->id,
            'driver_id'               => $d['d2']->id,
            'rating'                  => 4,
            'comment'                 => 'سائق ممتاز وقيادة هادئة ولكن نأمل تقليل وقت التأخير عند محطات التجمع.',
            'status'                  => 'active',
        ]);

        // شكوى مفتوحة
        Complaint::create([
            'submitted_by'        => $p['p3User']->id,
            'against_type'        => 'DRIVER',
            'against_id'          => $d['d2']->id,
            'driver_id'           => $d['d2']->id,
            'description'         => 'عدم تشغيل مكيف الهواء في رحلة الظهيرة رغم ارتفاع درجات الحرارة.',
            'status'              => 'Open',
            'action_taken'        => 'under_investigation',
            'action_details'      => 'جاري مراجعة حالة المركبة والتواصل مع السائق للتأكد من كفاءة المكيف.',
        ]);

        // شكوى محلولة بإجراء إنذار
        Complaint::create([
            'submitted_by'    => $p['p1User']->id,
            'against_type'    => 'DRIVER',
            'against_id'      => $d['d1']->id,
            'driver_id'       => $d['d1']->id,
            'description'     => 'تأخر السائق عن موعد الحضور الصباحي بمقدار 20 دقيقة.',
            'status'          => 'Resolved',
            'resolved_by'     => $adminUser->id,
            'resolution_note' => 'تم توجيه تنبيه رسمي للسائق بضرورة الالتزام بالوقت والتحرك مبكراً.',
            'action_taken'    => 'warning_issued',
            'action_details'  => 'تم توثيق الإنذار في ملف السائق وقبول الاعتذار من ولي الأمر.',
            'resolved_at'     => now()->subDays(2),
        ]);
    }

    // =========================================================
    // 15. طلبات تعديل المواقع وغيابات الأطفال (Location Changes & Absences)
    // =========================================================
    private function seedLocationChangesAndAbsences(array $p, array $d, array $subs): void
    {
        $this->command->info('1️⃣5️⃣ إنشاء طلبات تغيير المواقع وسجلات الغياب (Location Changes & Absences)...');

        // طلب تغيير نقطة الاستلام معلق (Pending)
        LocationChangeRequest::create([
            'active_subscription_id' => $subs['activeSub1']->id,
            'child_id'               => $p['child1']->id,
            'parent_id'              => $p['p1User']->id,
            'driver_id'              => $d['d1']->id,
            'point_type'             => 'pickup',
            'new_address_id'         => $p['addr1_grandma']->id,
            'new_lat'                => 32.88510000,
            'new_lng'                => 13.16200000,
            'new_label'              => 'منزل الجدة - قرقارش قرب جامع بن رجب',
            'status'                 => 'pending',
            'created_at'             => now()->subHours(3),
        ]);

        // طلب تغيير موقع معتمد سابقاً (Approved)
        LocationChangeRequest::create([
            'active_subscription_id' => $subs['activeSub2']->id,
            'child_id'               => $p['child2']->id,
            'parent_id'              => $p['p1User']->id,
            'driver_id'              => $d['d1']->id,
            'point_type'             => 'dropoff',
            'new_address_id'         => $p['addr1_main']->id,
            'new_lat'                => 32.89250000,
            'new_lng'                => 13.17520000,
            'new_label'              => 'المنزل الرئيسي',
            'status'                 => 'approved',
            'responded_at'           => now()->subDays(4),
            'created_at'             => now()->subDays(5),
        ]);

        // غياب طفل ليوم الغد (Absence Logs)
        AbsenceLog::create([
            'child_id'     => $p['child3']->id,
            'absence_date' => Carbon::tomorrow()->toDateString(),
            'absence_type' => 'both',
        ]);

        // غياب سائق مبرمج (Driver Absences)
        DB::table('driver_absences')->insert([
            'driver_id'    => $d['d1']->id,
            'absence_date' => Carbon::today()->addDays(14)->toDateString(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // =========================================================
    // 16. الإشعارات والرسائل وأجهزة المستخدمين (Notifications & Comms)
    // =========================================================
    private function seedNotificationsAndMessages(array $p, array $d, User $adminUser): void
    {
        $this->command->info('1️⃣6️⃣ إنشاء الإشعارات الفورية، رسائل المحادثة، ورموز التحقق (Notifications, Messages & OTP)...');

        // إشعارات لولي الأمر والسائق والإدارة بصياغة نصية قياسية كاملة
        $notif1 = \App\Services\Notification\NotificationFormatter::format('trip_started', [
            'title'   => 'انطلقت رحلة الصباح 🚌',
            'message' => 'السائق عبد السلام تحرك بالمركبة باتجاه نقطة التجمع الخاصة بأبنائك.',
            'trip_id' => 2,
        ]);
        $notif2 = \App\Services\Notification\NotificationFormatter::format('child_picked_up', [
            'title'      => 'صعود آمن للطفل ✅',
            'message'    => 'تم تسجيل صعود ابنكم سند إلى الحافلة بنجاح عبر مسح البطاقة الذكية.',
            'child_name' => 'سند',
            'trip_id'    => 2,
        ]);
        $notif3 = \App\Services\Notification\NotificationFormatter::format('new_subscription_request', [
            'title'      => 'طلب اشتراك جديد 📩',
            'message'    => 'تلقيت طلب اشتراك جديد من ولي الأمر محمود الورفلي لنقل ابنه أيمن.',
            'request_id' => 2,
        ]);
        $notif4 = \App\Services\Notification\NotificationFormatter::format('recharge_completed', [
            'title'   => '💳 تم شحن المحفظة بنجاح',
            'message' => 'تم شحن محفظتك بمبلغ (150.00 د.ل) بنجاح. رصيدك الحالي: 650.00 د.ل',
            'amount'  => 150.00,
        ]);

        DB::table('notifications')->insert([
            [
                'id'              => Str::uuid()->toString(),
                'type'            => 'App\Notifications\SystemNotification',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id'   => $p['p1User']->id,
                'data'            => json_encode($notif1, JSON_UNESCAPED_UNICODE),
                'dedupe_key'      => 'trip_start_2_' . $p['p1User']->id,
                'read_at'         => null,
                'created_at'      => now()->subMinutes(20),
                'updated_at'      => now()->subMinutes(20),
            ],
            [
                'id'              => Str::uuid()->toString(),
                'type'            => 'App\Notifications\SystemNotification',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id'   => $p['p1User']->id,
                'data'            => json_encode($notif2, JSON_UNESCAPED_UNICODE),
                'dedupe_key'      => 'boarded_' . $p['child1']->id,
                'read_at'         => now()->subMinutes(10),
                'created_at'      => now()->subMinutes(15),
                'updated_at'      => now()->subMinutes(10),
            ],
            [
                'id'              => Str::uuid()->toString(),
                'type'            => 'App\Notifications\SystemNotification',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id'   => $d['d2User']->id,
                'data'            => json_encode($notif3, JSON_UNESCAPED_UNICODE),
                'dedupe_key'      => 'req_new_2',
                'read_at'         => null,
                'created_at'      => now()->subHours(8),
                'updated_at'      => now()->subHours(8),
            ],
            [
                'id'              => Str::uuid()->toString(),
                'type'            => 'App\Notifications\SystemNotification',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id'   => $p['p1User']->id,
                'data'            => json_encode($notif4, JSON_UNESCAPED_UNICODE),
                'dedupe_key'      => 'recharge_seed_' . $p['p1User']->id,
                'read_at'         => now()->subDays(1),
                'created_at'      => now()->subDays(1),
                'updated_at'      => now()->subDays(1),
            ],
        ]);

        // رسائل دردشة بين ولي الأمر والسائق (Messages)
        DB::table('messages')->insert([
            [
                'sender_id'    => $p['p1User']->id,
                'receiver_id'  => $d['d1User']->id,
                'message_body' => 'السلام عليكم كابتن عبد السلام، الأطفال جاهزون عند البوابة الرئيسية.',
                'message_type' => 'text',
                'is_read'      => 1,
                'sent_at'      => now()->subMinutes(25),
            ],
            [
                'sender_id'    => $d['d1User']->id,
                'receiver_id'  => $p['p1User']->id,
                'message_body' => 'وعليكم السلام ورحمة الله، أنا على بعد دقيقتين إن شاء الله.',
                'message_type' => 'text',
                'is_read'      => 1,
                'sent_at'      => now()->subMinutes(23),
            ],
        ]);

        // أجهزة المستخدمين للـ Push Notifications (User Devices)
        DB::table('user_devices')->insert([
            [
                'user_id'        => $p['p1User']->id,
                'device_id'      => 'DEVICE-IOS-P1',
                'fcm_token'      => 'fcm_token_parent1_live_test_string_darby_2026',
                'device_name'    => 'iPhone 15 Pro Max',
                'platform'       => 'ios',
                'app_version'    => '2.4.0',
                'is_active'      => 1,
                'last_active_at' => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'user_id'        => $d['d1User']->id,
                'device_id'      => 'DEVICE-ANDROID-D1',
                'fcm_token'      => 'fcm_token_driver1_live_test_string_darby_2026',
                'device_name'    => 'Samsung Galaxy S24 Ultra',
                'platform'       => 'android',
                'app_version'    => '2.4.0',
                'is_active'      => 1,
                'last_active_at' => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);

        // أكواد التحقق السريعة للاختبار (OTP Codes)
        DB::table('otp_codes')->insert([
            [
                'email'      => 'parent1@darby.ly',
                'code_hash'  => Hash::make('123456'),
                'purpose'    => 'LOGIN',
                'expires_at' => now()->addHours(24),
                'is_used'    => 0,
                'attempts'   => 0,
                'created_at' => now(),
            ],
            [
                'email'      => 'driver1@darby.ly',
                'code_hash'  => Hash::make('123456'),
                'purpose'    => 'LOGIN',
                'expires_at' => now()->addHours(24),
                'is_used'    => 0,
                'attempts'   => 0,
                'created_at' => now(),
            ],
        ]);
    }

    // =========================================================
    // تقرير الطباعة وملخص الحسابات
    // =========================================================
    private function printSummaryReport(): void
    {
        $this->command->info('');
        $this->command->info('================================================================================');
        $this->command->info('🎉 تم بنجاح زرع واختبار قاعدة بيانات مشروع دربي بالكامل (100% Comprehensive)!');
        $this->command->info('================================================================================');
        $this->command->info('🔑 كلمة المرور الموحدة لجميع الحسابات: ' . $this->defaultPassword);
        $this->command->info('🔢 كود التحقق السريع الموحد (OTP): 123456');
        $this->command->info('');
        $this->command->info('📧 بيانات الحسابات الجاهزة للاختبار:');
        $this->command->info('--------------------------------------------------------------------------------');
        $this->command->info('👑 الإدارة العليا (Super Admin):');
        $this->command->info('   - البريد: admin@darby.ly        | الصلاحيات: مدير نظام كامل');
        $this->command->info('');
        $this->command->info('🛡️ المشرفون (Supervisors):');
        $this->command->info('   - مشرف 1: supervisor1@darby.ly  | مشرف عمليات غرب طرابلس');
        $this->command->info('   - مشرف 2: supervisor2@darby.ly  | مشرفة عمليات شرق طرابلس');
        $this->command->info('');
        $this->command->info('👨‍👩‍👧‍👦 أولياء الأمور (Parents):');
        $this->command->info('   - ولي أمر 1: parent1@darby.ly   | الرصيد: 500 د.ل  | طفلين (سند + مروة) - اشتراك مفعّل ورحلة جارية');
        $this->command->info('   - ولي أمر 2: parent2@darby.ly   | الرصيد: 250 د.ل  | طفل واحد (أيمن) - طلب معلق (Pending)');
        $this->command->info('   - ولي أمر 3: parent3@darby.ly   | الرصيد: 100 د.ل  | طفلين - اشتراك سابق مكتمل وشكوى');
        $this->command->info('   - ولي أمر 4: parent4@darby.ly   | الرصيد: 50 د.ل   | حساب جديد / طلب ملغي');
        $this->command->info('');
        $this->command->info('🚌 السائقون والمركبات (Drivers):');
        $this->command->info('   - سائق 1: driver1@darby.ly     | الرصيد: 650 د.ل  | معتمد | تويوتا كوستر (14 مقعد) - رحلة جارية الآن');
        $this->command->info('   - سائق 2: driver2@darby.ly     | الرصيد: 320 د.ل  | معتمد | هيونداي H1 (8 مقاعد) - طلبات معلقة');
        $this->command->info('   - سائق 3: driver3@darby.ly     | الرصيد: 0 د.ل    | قيد الانتظار (Pending)');
        $this->command->info('   - سائق 4: driver4@darby.ly     | الرصيد: 0 د.ل    | مرفوض (Rejected) - رخصة منتهية');
        $this->command->info('================================================================================');
    }
}
