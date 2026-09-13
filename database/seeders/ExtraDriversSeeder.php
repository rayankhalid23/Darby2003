<?php

namespace Database\Seeders;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Driver\Vehicle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * ExtraDriversSeeder - 10 سائقين إضافيين مصممين لكشف كل فرع من فلاتر البحث.
 *
 * يعمل APPEND فقط: لا يحذف أي بيانات موجودة. آمن للتشغيل بعد BaseSystemSeeder
 * و DemoDataSeeder وسائقَي driver1 / driver2 يظلان كما هما.
 *
 * كل سائق مصمَّم ليمثّل حالة فلترة محددة (انظر السيناريو في مخرجات التشغيل).
 *
 * كلمة المرور الموحدة: Password123!
 *
 *   php artisan db:seed --class=ExtraDriversSeeder
 */
class ExtraDriversSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    /**
     * 10 سائقين إضافيين — كل واحد يستهدف فلتراً محدداً.
     *
     * الأرقام تبدأ من 5 (driver1 و driver2 موجودان).
     */
    private const DRIVERS = [
        // =============================================================
        // #3: مطابق ممتاز لولي الأمر #1 (بن عاشور + مدارس أطفاله)
        // =============================================================
        [
            'index'        => 3,
            'full_name'    => 'حسام إبراهيم سالم القذافي',
            'gender'       => 'male',
            'phone'        => '0918111011',
            'alt_phone'    => '0928111011',
            'national_id'  => '119801122334',
            'license_no'   => 'LY-TR-10001',
            'license_days' => 900,           // رخصة سارية طويلاً
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['kindergarten','primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','شارع النصر'],
            'lat'          => 32.87500, 'lng' => 13.19200,
            'rating'       => 4.85,
            'vehicle'      => ['plate'=>'ط ط 1010','brand'=>'Toyota','model'=>'Coaster','year'=>2021,'color'=>'أصفر','type'=>'Bus','capacity'=>25,'has_ac'=>1],
            'seat_fills'   => [], // فارغ = 25 مقعد متاح
            'absences'     => [],
        ],

        // =============================================================
        // #4: مطابق لولي الأمر #2 (حي الأندلس + قرقارش)
        // =============================================================
        [
            'index'        => 4,
            'full_name'    => 'رضا مفتاح علي البرعصي',
            'gender'       => 'male',
            'phone'        => '0918111012',
            'alt_phone'    => '0928111012',
            'national_id'  => '119821122335',
            'license_no'   => 'LY-TR-10002',
            'license_days' => 800,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['primary','middle','secondary'],
            'zones'        => ['حي الأندلس','قرقارش','السراج','قرجي الغربي'],
            'lat'          => 32.89100, 'lng' => 13.16700,
            'rating'       => 4.70,
            'vehicle'      => ['plate'=>'ط ط 1011','brand'=>'Hyundai','model'=>'H1','year'=>2020,'color'=>'رمادي','type'=>'Bus','capacity'=>20,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => [],
        ],

        // =============================================================
        // #5: سائقة أنثى (لاختبار فلتر driver_gender)
        // =============================================================
        [
            'index'        => 5,
            'full_name'    => 'خديجة عبدالله محمد الشاعري',
            'gender'       => 'female',
            'phone'        => '0918111013',
            'alt_phone'    => '0928111013',
            'national_id'  => '119761122336',
            'license_no'   => 'LY-TR-10003',
            'license_days' => 700,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'female',   // ← تنقل بنات فقط
            'school_stages'   => ['primary','middle'],
            'zones'        => ['بن عاشور','زاوية الدهماني','شارع النصر','حي الأندلس','السراج','قرقارش'],
            'lat'          => 32.87200, 'lng' => 13.17800,
            'rating'       => 4.95,
            'vehicle'      => ['plate'=>'ط ط 1012','brand'=>'Kia','model'=>'Carnival','year'=>2022,'color'=>'أبيض','type'=>'Van','capacity'=>10,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => [],
        ],

        // =============================================================
        // #6: بدون تكييف (لاختبار فلتر has_ac=true يستبعده)
        // =============================================================
        [
            'index'        => 6,
            'full_name'    => 'سالم فرج المبروك أبو راس',
            'gender'       => 'male',
            'phone'        => '0918111014',
            'alt_phone'    => '0928111014',
            'national_id'  => '119731122337',
            'license_no'   => 'LY-TR-10004',
            'license_days' => 600,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['kindergarten','primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','شارع النصر','النوفليين'],
            'lat'          => 32.87600, 'lng' => 13.19500,
            'rating'       => 4.50,
            'vehicle'      => ['plate'=>'ط ط 1013','brand'=>'Nissan','model'=>'Urvan','year'=>2016,'color'=>'أزرق','type'=>'Van','capacity'=>14,'has_ac'=>0], // ← بدون تكييف
            'seat_fills'   => [],
            'absences'     => [],
        ],

        // =============================================================
        // #7: يخدم أبو سليم فقط (لن يظهر لأطفال بن عاشور/الأندلس - zone filter)
        // =============================================================
        [
            'index'        => 7,
            'full_name'    => 'عادل سعد الشتيوي الفلاح',
            'gender'       => 'male',
            'phone'        => '0918111015',
            'alt_phone'    => '0928111015',
            'national_id'  => '119781122338',
            'license_no'   => 'LY-TR-10005',
            'license_days' => 500,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['primary','middle','secondary'],
            'zones'        => ['أبو سليم','الهضبة الخضراء','صلاح الدين','عين زارة الشرقية','خلة الفرجان'],
            'lat'          => 32.86000, 'lng' => 13.17000,
            'rating'       => 4.65,
            'vehicle'      => ['plate'=>'ط ط 1014','brand'=>'Ford','model'=>'Transit','year'=>2019,'color'=>'أسود','type'=>'Van','capacity'=>15,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => [],
        ],

        // =============================================================
        // #8: يقبل ذكور فقط (accepted_gender=male)
        // (يستبعد إذا كان الأطفال مختلطي الجنس)
        // =============================================================
        [
            'index'        => 8,
            'full_name'    => 'محمود عمار الفيتوري الطرابلسي',
            'gender'       => 'male',
            'phone'        => '0918111016',
            'alt_phone'    => '0928111016',
            'national_id'  => '119851122339',
            'license_no'   => 'LY-TR-10006',
            'license_days' => 450,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'male',    // ← ينقل ذكور فقط
            'school_stages'   => ['primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','حي الأندلس','قرقارش'],
            'lat'          => 32.87800, 'lng' => 13.18700,
            'rating'       => 4.55,
            'vehicle'      => ['plate'=>'ط ط 1015','brand'=>'Toyota','model'=>'Hiace','year'=>2018,'color'=>'أبيض','type'=>'Van','capacity'=>12,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => [],
        ],

        // =============================================================
        // #9: يعمل صباحاً فقط (afternoon_go=0, afternoon_return=0)
        // (يستبعد إذا كان trip_direction=both أو return)
        // =============================================================
        [
            'index'        => 9,
            'full_name'    => 'ناصر يوسف الترهوني بن سعود',
            'gender'       => 'male',
            'phone'        => '0918111017',
            'alt_phone'    => '0928111017',
            'national_id'  => '119801122340',
            'license_no'   => 'LY-TR-10007',
            'license_days' => 400,
            'shift'        => 'morning',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 0, 'afternoon_return' => 0], // ← صباحي فقط
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','شارع النصر','حي الأندلس'],
            'lat'          => 32.87400, 'lng' => 13.19100,
            'rating'       => 4.40,
            'vehicle'      => ['plate'=>'ط ط 1016','brand'=>'Isuzu','model'=>'NPR','year'=>2019,'color'=>'أخضر','type'=>'Bus','capacity'=>18,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => [],
        ],

        // =============================================================
        // #10: مقاعده ممتلئة تماماً في الأسبوعين القادمين (كل الفترات)
        // (يظهر بدون فلتر تاريخ، ويُستبعد عند وضع تاريخ)
        // =============================================================
        [
            'index'        => 10,
            'full_name'    => 'كمال العارف بن حمد المزوغي',
            'gender'       => 'male',
            'phone'        => '0918111018',
            'alt_phone'    => '0928111018',
            'national_id'  => '119821122341',
            'license_no'   => 'LY-TR-10008',
            'license_days' => 380,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','حي الأندلس','قرقارش'],
            'lat'          => 32.87700, 'lng' => 13.18900,
            'rating'       => 4.20,
            'vehicle'      => ['plate'=>'ط ط 1017','brand'=>'Hyundai','model'=>'Starex','year'=>2017,'color'=>'فضي','type'=>'Van','capacity'=>7,'has_ac'=>1], // capacity=7
            'seat_fills'   => 'fill_all', // ← يملأ كل المقاعد بأيام الأسبوعين القادمين
            'absences'     => [],
        ],

        // =============================================================
        // #11: غائب بأيام محددة (الأسبوع القادم من السبت إلى الثلاثاء)
        // =============================================================
        [
            'index'        => 11,
            'full_name'    => 'رمضان الهاشمي بشير الأشتر',
            'gender'       => 'male',
            'phone'        => '0918111019',
            'alt_phone'    => '0928111019',
            'national_id'  => '119791122342',
            'license_no'   => 'LY-TR-10009',
            'license_days' => 300,
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['kindergarten','primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','حي الأندلس'],
            'lat'          => 32.87500, 'lng' => 13.18500,
            'rating'       => 4.30,
            'vehicle'      => ['plate'=>'ط ط 1018','brand'=>'Toyota','model'=>'Hiace','year'=>2020,'color'=>'أبيض','type'=>'Van','capacity'=>13,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => 'next_week', // غياب سبت→خميس القادم
        ],

        // =============================================================
        // #12: رخصة منتهية (يجب ألا يظهر إطلاقاً في أي بحث)
        // =============================================================
        [
            'index'        => 12,
            'full_name'    => 'إدريس عبدالله الشرياني القرقني',
            'gender'       => 'male',
            'phone'        => '0918111020',
            'alt_phone'    => '0928111020',
            'national_id'  => '119721122343',
            'license_no'   => 'LY-TR-10010',
            'license_days' => -15, // ← منتهية قبل 15 يوم
            'shift'        => 'both',
            'flags'        => ['morning_go' => 1, 'morning_return' => 1, 'afternoon_go' => 1, 'afternoon_return' => 1],
            'sub_type'     => 'multi_day',
            'accepted_gender' => 'both',
            'school_stages'   => ['kindergarten','primary','middle','secondary'],
            'zones'        => ['بن عاشور','زاوية الدهماني','حي الأندلس'],
            'lat'          => 32.87500, 'lng' => 13.18500,
            'rating'       => 4.00,
            'vehicle'      => ['plate'=>'ط ط 1019','brand'=>'Mercedes','model'=>'Sprinter','year'=>2015,'color'=>'رمادي','type'=>'Van','capacity'=>16,'has_ac'=>1],
            'seat_fills'   => [],
            'absences'     => [],
        ],
    ];

    public function run(): void
    {
        $this->command?->info('🚌 ExtraDriversSeeder: إضافة 10 سائقين مصمَّمين لكشف فلاتر البحث...');

        $this->assertPrerequisites();

        $driverRoleId = DB::table('roles')->where('name', 'driver')->value('id');
        $adminUserId  = User::where('email', 'fleet@darby.ly')->value('id');

        // خريطة أسماء المناطق → IDs (من BaseSystemSeeder)
        $zoneIdByName = DB::table('zones')->pluck('id', 'name')->toArray();

        $createdEmails = [];

        foreach (self::DRIVERS as $spec) {
            $email = sprintf('driver%d@darby.ly', $spec['index']);

            // idempotent: إذا الحساب موجود من تشغيل سابق، نتخطاه
            if (User::where('email', $email)->exists()) {
                $this->command?->line("   ⏭  {$email} موجود بالفعل — تخطي");
                continue;
            }

            $user = User::create([
                'full_name'         => $spec['full_name'],
                'email'             => $email,
                'phone_number'      => $spec['phone'],
                'alternative_phone' => $spec['alt_phone'],
                'password'          => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'           => $driverRoleId,
                'is_active'         => true,
                'is_trusted'        => true,
                'gender'            => $spec['gender'],
                'email_verified_at' => now(),
                'last_login_at'     => now()->subMinutes(rand(5, 300)),
            ]);

            $driver = Driver::create([
                'user_id'                => $user->id,
                'national_id'            => $spec['national_id'],
                'license_number'         => $spec['license_no'],
                'license_expiry'         => Carbon::now()->addDays($spec['license_days'])->format('Y-m-d'),
                'license_image_url'      => "https://cdn.darby.ly/demo/licenses/{$email}.jpg",
                'status'                 => 'Approved',
                'reviewed_by'            => $adminUserId,
                'shift'                  => $spec['shift'],
                'morning_go'             => $spec['flags']['morning_go'],
                'morning_return'         => $spec['flags']['morning_return'],
                'afternoon_go'           => $spec['flags']['afternoon_go'],
                'afternoon_return'       => $spec['flags']['afternoon_return'],
                'subscription_type'      => $spec['sub_type'],
                'accepted_gender'        => $spec['accepted_gender'],
                'school_stages'          => json_encode($spec['school_stages']),
                'current_lat'            => $spec['lat'],
                'current_lng'            => $spec['lng'],
                'last_ping_at'           => now()->subMinutes(rand(1, 60)),
                'rating_avg'             => $spec['rating'],
                'is_searchable'          => 1,
                'driver_waiting_minutes' => 10,
            ]);

            $vehicle = Vehicle::create([
                'driver_id'         => $driver->id,
                'plate_number'      => $spec['vehicle']['plate'],
                'brand'             => $spec['vehicle']['brand'],
                'model'             => $spec['vehicle']['model'],
                'year'              => $spec['vehicle']['year'],
                'color'             => $spec['vehicle']['color'],
                'type'              => $spec['vehicle']['type'],
                'capacity_manual'   => $spec['vehicle']['capacity'],
                'has_ac'            => $spec['vehicle']['has_ac'],
                'status'            => 'Active',
                'vehicle_image_url' => "https://cdn.darby.ly/demo/vehicles/{$email}.jpg",
            ]);

            $this->seedVehicleDocs($vehicle);
            $this->seedDriverZones($driver, $spec['zones'], $zoneIdByName);
            $this->seedApproval($driver, $adminUserId);

            // ملء المقاعد لسائق #10 (كل الأسبوعين القادمين — كل الفترات ممتلئة)
            if ($spec['seat_fills'] === 'fill_all') {
                $this->fillAllSeats($driver, $vehicle);
            }

            // غياب لسائق #11 (السبت→الخميس من الأسبوع القادم)
            if ($spec['absences'] === 'next_week') {
                $this->createNextWeekAbsences($driver, $adminUserId);
            }

            $createdEmails[] = $email;
        }

        $this->printReport($createdEmails);
    }

    private function assertPrerequisites(): void
    {
        if (!DB::table('users')->where('email', 'admin@darby.ly')->exists()) {
            throw new \RuntimeException('يجب تشغيل BaseSystemSeeder أولاً (لا يوجد super_admin).');
        }
        if (!DB::table('users')->where('email', 'fleet@darby.ly')->exists()) {
            throw new \RuntimeException('يجب تشغيل BaseSystemSeeder أولاً (لا يوجد fleet_supervisor).');
        }
        if (DB::table('zones')->count() === 0) {
            throw new \RuntimeException('لا توجد مناطق - شغّل BaseSystemSeeder أولاً.');
        }
    }

    private function seedVehicleDocs(Vehicle $vehicle): void
    {
        $docs = [
            'LOGBOOK'          => Carbon::now()->addYears(3),
            'INSURANCE'        => Carbon::now()->addMonths(11),
            'INSPECTION'       => Carbon::now()->addMonths(10),
            'OPERATING_PERMIT' => Carbon::now()->addMonths(9),
        ];
        foreach ($docs as $type => $expiry) {
            DB::table('vehicle_documents')->insert([
                'vehicle_id'  => $vehicle->id,
                'doc_type'    => $type,
                'file_url'    => "https://cdn.darby.ly/demo/docs/vehicle_{$vehicle->id}_" . strtolower($type) . '.pdf',
                'expiry_date' => $expiry->format('Y-m-d'),
                'is_verified' => 1,
                'state'       => 'active',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    private function seedDriverZones(Driver $driver, array $zoneNames, array $zoneIdByName): void
    {
        foreach ($zoneNames as $zoneName) {
            $zoneId = $zoneIdByName[$zoneName] ?? null;
            if (!$zoneId) {
                $this->command?->warn("   ⚠️  منطقة غير موجودة (تخطي): {$zoneName}");
                continue;
            }
            DB::table('driver_zone')->insert([
                'driver_id'  => $driver->id,
                'zone_id'    => $zoneId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedApproval(Driver $driver, int $adminId): void
    {
        DB::table('driver_approvals')->insert([
            'driver_id'    => $driver->id,
            'request_type' => 'Registration',
            'status'       => 'Approved',
            'admin_id'     => $adminId,
            'reviewed_at'  => now()->subDays(30),
            'created_at'   => now()->subDays(32),
            'updated_at'   => now()->subDays(30),
        ]);
    }

    /**
     * يملأ كل الخانات (morning_go/return + afternoon_go/return) بأيام العمل
     * القادمة (الأسبوعين القادمين) بحجوزات = capacity (ممتلئة).
     */
    private function fillAllSeats(Driver $driver, Vehicle $vehicle): void
    {
        $slots = [
            DriverSeatSlot::MORNING_GO,
            DriverSeatSlot::MORNING_RETURN,
            DriverSeatSlot::AFTERNOON_GO,
            DriverSeatSlot::AFTERNOON_RETURN,
        ];

        $start = Carbon::today();
        $end   = Carbon::today()->addDays(14);

        $day = $start->copy();
        while ($day->lte($end)) {
            // يوم عمل ليبيا: الأحد إلى الخميس (0..4)
            if (in_array($day->dayOfWeek, [Carbon::SUNDAY, Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY])) {
                foreach ($slots as $slot) {
                    DB::table('driver_seat_slots')->insert([
                        'driver_id'  => $driver->id,
                        'slot'       => $slot,
                        'date'       => $day->format('Y-m-d'),
                        'booked'     => $vehicle->capacity_manual, // ممتلئ
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            $day->addDay();
        }
    }

    private function createNextWeekAbsences(Driver $driver, int $adminId): void
    {
        // بداية الأسبوع القادم: يوم السبت القادم
        $saturday = Carbon::today()->next(Carbon::SATURDAY);

        // نغيّبه من الأحد للخميس (5 أيام عمل ليبيا)
        $absenceDays = [
            $saturday->copy()->addDay(),   // الأحد
            $saturday->copy()->addDays(2), // الاثنين
            $saturday->copy()->addDays(3), // الثلاثاء
            $saturday->copy()->addDays(4), // الأربعاء
            $saturday->copy()->addDays(5), // الخميس
        ];

        foreach ($absenceDays as $d) {
            DB::table('driver_absences')->insert([
                'driver_id'    => $driver->id,
                'absence_date' => $d->format('Y-m-d'),
                'reason'       => 'إجازة سنوية مُعتمَدة سابقاً.',
                'status'       => 'approved',
                'reviewed_by'  => $adminId,
                'reviewed_at'  => now()->subDays(2),
                'admin_notes'  => 'مُعتمد',
                'created_at'   => now()->subDays(3),
                'updated_at'   => now()->subDays(2),
            ]);
        }
    }

    private function printReport(array $createdEmails): void
    {
        $this->command?->newLine();
        $this->command?->info('╔════════════════════════════════════════════════════════════════╗');
        $this->command?->info('║           ✅ ExtraDriversSeeder اكتمل بنجاح                    ║');
        $this->command?->info('╚════════════════════════════════════════════════════════════════╝');
        $this->command?->newLine();

        $this->command?->line('   • حسابات مضافة: ' . count($createdEmails));
        $this->command?->line('   • إجمالي السائقين الآن: ' . DB::table('drivers')->count());
        $this->command?->line('   • إجمالي المركبات: ' . DB::table('vehicles')->count());
        $this->command?->line('   • إجمالي وثائق المركبات: ' . DB::table('vehicle_documents')->count());
        $this->command?->line('   • إجمالي driver_zone: ' . DB::table('driver_zone')->count());
        $this->command?->line('   • إجمالي driver_seat_slots (ممتلئة): ' . DB::table('driver_seat_slots')->count());
        $this->command?->line('   • إجمالي driver_absences: ' . DB::table('driver_absences')->count());
        $this->command?->newLine();

        $this->command?->line('🔐 <fg=yellow>الحسابات المضافة (كلمة المرور: <fg=green>' . self::DEFAULT_PASSWORD . '</>)</>');
        foreach ($createdEmails as $email) {
            $this->command?->line("   🚌 {$email}");
        }
    }
}
