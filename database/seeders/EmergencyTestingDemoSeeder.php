<?php

namespace Database\Seeders;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\ParentModel;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Municipality;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\RequestChild;
use App\Models\Shared\Route;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\Zone;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmergencyTestingDemoSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    public function run(): void
    {
        $this->command?->info('🚀 تجهيز بيانات وبيئة اختبار سيناريوهات الطوارئ...');

        $adminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id') ?? 1;
        $driverRoleId = DB::table('roles')->where('name', 'driver')->value('id') ?? 8;
        $parentRoleId = DB::table('roles')->where('name', 'parent')->value('id') ?? 7;

        PricingSetting::firstOrCreate([], [
            'base_fare'                => 15.00,
            'km_rate'                  => 1.50,
            'price_per_km_ac'          => 2.50,
            'price_per_km_non_ac'      => 2.00,
            'platform_commission_rate' => 8.00,
            'driver_split_percent'     => 85.00,
            'company_split_percent'    => 15.00,
            'is_active'                => true,
        ]);

        $muni = Municipality::firstOrCreate(['name' => 'طرابلس الكبرى']);
        $subMuni = SubMunicipality::firstOrCreate(['municipality_id' => $muni->id, 'name' => 'سوق الجمعة']);
        $zone = Zone::firstOrCreate(
            ['name' => 'طريق الشط التجريبي'],
            ['sub_municipality_id' => $subMuni->id]
        );

        // 1. حساب المشرف (Admin)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin.emergency@darby.ly'],
            [
                'full_name'     => 'مشرف إدارة الطوارئ والرادار',
                'phone_number'  => '0919990001',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $adminRoleId,
                'is_active'     => 1,
            ]
        );
        $adminUser->tokens()->delete();
        $adminToken = $adminUser->createToken('emergency_test_token')->plainTextToken;

        // 2. المدارس
        $schoolA = School::firstOrCreate(
            ['name' => 'مدرسة المستقبل النموذجية'],
            [
                'zone_id' => $zone->id,
                'lat'     => 32.895000,
                'lng'     => 13.195000,
                'address' => 'طرابلس - طريق الشط بالقرب من الميناء',
                'status'  => 'Approved',
            ]
        );

        $schoolB = School::firstOrCreate(
            ['name' => 'مدرسة النور الأهلية'],
            [
                'zone_id' => $zone->id,
                'lat'     => 32.905000,
                'lng'     => 13.205000,
                'address' => 'طرابلس - تقاطع زاوية الدهماني',
                'status'  => 'Approved',
            ]
        );

        // 3. أولياء الأمور
        $parentUser1 = User::firstOrCreate(
            ['email' => 'parent1.emergency@darby.ly'],
            [
                'full_name'     => 'سالم الهوني (ولي أمر)',
                'phone_number'  => '0919990011',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $parentRoleId,
                'is_active'     => 1,
                'is_trusted'    => 1,
            ]
        );
        $parent1 = ParentModel::find($parentUser1->id);
        $parent1?->deposit(100000);
        $parentUser1->tokens()->delete();
        $parent1Token = $parentUser1->createToken('emergency_test_token')->plainTextToken;

        $parentUser2 = User::firstOrCreate(
            ['email' => 'parent2.emergency@darby.ly'],
            [
                'full_name'     => 'منير الترهوني (ولي أمر)',
                'phone_number'  => '0919990022',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $parentRoleId,
                'is_active'     => 1,
                'is_trusted'    => 1,
            ]
        );
        $parent2 = ParentModel::find($parentUser2->id);
        $parent2?->deposit(100000);
        $parentUser2->tokens()->delete();
        $parent2Token = $parentUser2->createToken('emergency_test_token')->plainTextToken;

        // عناوين المنازل
        $addr1 = Address::firstOrCreate(['user_id' => $parentUser1->id, 'label' => 'منزل محمد سالم'], ['lat' => 32.875000, 'lng' => 13.175000]);
        $addr2 = Address::firstOrCreate(['user_id' => $parentUser1->id, 'label' => 'منزل سارة سالم'], ['lat' => 32.876000, 'lng' => 13.176000]);
        $addr3 = Address::firstOrCreate(['user_id' => $parentUser2->id, 'label' => 'منزل ياسين منير'], ['lat' => 32.877000, 'lng' => 13.177000]);
        $addr4 = Address::firstOrCreate(['user_id' => $parentUser2->id, 'label' => 'منزل مريم منير'], ['lat' => 32.878000, 'lng' => 13.178000]);

        // الأطفال
        $child1 = Child::firstOrCreate(['full_name' => 'محمد سالم'], ['parent_id' => $parent1->id, 'school_id' => $schoolA->id, 'address_id' => $addr1->id, 'birth_date' => '2016-01-01', 'gender' => 'male', 'grade' => 3, 'qr_code_token' => 'QR_MOHAMMED']);
        $child2 = Child::firstOrCreate(['full_name' => 'سارة سالم'], ['parent_id' => $parent1->id, 'school_id' => $schoolB->id, 'address_id' => $addr2->id, 'birth_date' => '2018-05-01', 'gender' => 'female', 'grade' => 1, 'qr_code_token' => 'QR_SARA']);
        $child3 = Child::firstOrCreate(['full_name' => 'ياسين منير'], ['parent_id' => $parent2->id, 'school_id' => $schoolA->id, 'address_id' => $addr3->id, 'birth_date' => '2017-02-01', 'gender' => 'male', 'grade' => 2, 'qr_code_token' => 'QR_YASEEN']);
        $child4 = Child::firstOrCreate(['full_name' => 'مريم منير'], ['parent_id' => $parent2->id, 'school_id' => $schoolB->id, 'address_id' => $addr4->id, 'birth_date' => '2019-03-01', 'gender' => 'female', 'grade' => 1, 'qr_code_token' => 'QR_MARYAM']);

        // 4. السائق الأساسي (المعطل)
        $origDriverUser = User::firstOrCreate(
            ['email' => 'driver.primary@darby.ly'],
            [
                'full_name'     => 'كابتن محمود الأصلي',
                'phone_number'  => '0919990002',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $origDriverUser->tokens()->delete();
        $origDriverToken = $origDriverUser->createToken('emergency_test_token')->plainTextToken;

        $origDriver = Driver::firstOrCreate(
            ['user_id' => $origDriverUser->id],
            [
                'national_id'       => 'NAT100001',
                'license_number'    => 'LIC100001',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.885000,
                'current_lng'       => 13.185000,
            ]
        );
        $origDriver->deposit(50000);

        $origVehicle = Vehicle::firstOrCreate(
            ['driver_id' => $origDriver->id],
            [
                'brand'           => 'تويوتا',
                'model'           => 'هاي آس',
                'year'            => 2022,
                'color'           => 'أبيض',
                'type'            => 'van',
                'plate_number'    => 'LY-1111',
                'capacity_manual' => 10,
                'status'          => 'Active',
            ]
        );
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $origDriver->id, 'zone_id' => $zone->id]);

        $routeMorning = Route::firstOrCreate(
            ['driver_id' => $origDriver->id, 'route_name' => 'مسار الصباح - طريق الشط'],
            [
                'vehicle_id' => $origVehicle->id,
                'route_type' => 'Morning',
                'shift_slot' => 'morning_go',
                'status'     => 'Active',
                'start_time' => '07:00:00',
            ]
        );

        $routeReturn = Route::firstOrCreate(
            ['driver_id' => $origDriver->id, 'route_name' => 'مسار العودة - طريق الشط'],
            [
                'vehicle_id' => $origVehicle->id,
                'route_type' => 'Afternoon',
                'shift_slot' => 'afternoon_return',
                'status'     => 'Active',
                'start_time' => '13:30:00',
            ]
        );

        // إنشاء الاشتراكات النشطة
        $this->ensureSubscription($parent1, $child1, $origDriver, $routeMorning, $addr1, $schoolA);
        $this->ensureSubscription($parent1, $child2, $origDriver, $routeMorning, $addr2, $schoolB);
        $this->ensureSubscription($parent2, $child3, $origDriver, $routeMorning, $addr3, $schoolA);
        $this->ensureSubscription($parent2, $child4, $origDriver, $routeMorning, $addr4, $schoolB);

        // 5. السائق البديل 1: متفرغ تماماً (Free - لا يملك أي رحلة جارية)
        $subFreeUser = User::firstOrCreate(
            ['email' => 'driver.sub.free@darby.ly'],
            [
                'full_name'     => 'كابتن وسام البديل المتفرغ',
                'phone_number'  => '0919990003',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $subFreeUser->tokens()->delete();
        $subFreeToken = $subFreeUser->createToken('emergency_test_token')->plainTextToken;

        $subFreeDriver = Driver::firstOrCreate(
            ['user_id' => $subFreeUser->id],
            [
                'national_id'       => 'NAT200002',
                'license_number'    => 'LIC200002',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.884000,
                'current_lng'       => 13.184000,
            ]
        );
        $subFreeVehicle = Vehicle::firstOrCreate(
            ['driver_id' => $subFreeDriver->id],
            [
                'brand'           => 'نيسان',
                'model'           => 'أورفان',
                'year'            => 2023,
                'color'           => 'فضي',
                'type'            => 'van',
                'plate_number'    => 'LY-2222',
                'capacity_manual' => 12,
                'status'          => 'Active',
            ]
        );
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $subFreeDriver->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(
            ['driver_id' => $subFreeDriver->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()],
            ['booked' => 0]
        );
        DriverSeatSlot::updateOrCreate(
            ['driver_id' => $subFreeDriver->id, 'slot' => 'afternoon_return', 'date' => Carbon::today()->toDateString()],
            ['booked' => 0]
        );

        // 6. السائق البديل 2: لديه رحلة جارية بالفعل (Busy - Active Trip)
        $subBusyUser = User::firstOrCreate(
            ['email' => 'driver.sub.busy@darby.ly'],
            [
                'full_name'     => 'كابتن كمال البديل المشغول برحلة',
                'phone_number'  => '0919990004',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $subBusyUser->tokens()->delete();
        $subBusyToken = $subBusyUser->createToken('emergency_test_token')->plainTextToken;

        $subBusyDriver = Driver::firstOrCreate(
            ['user_id' => $subBusyUser->id],
            [
                'national_id'       => 'NAT300003',
                'license_number'    => 'LIC300003',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.883000,
                'current_lng'       => 13.183000,
            ]
        );
        $subBusyVehicle = Vehicle::firstOrCreate(
            ['driver_id' => $subBusyDriver->id],
            [
                'brand'           => 'هيونداي',
                'model'           => 'H1',
                'year'            => 2021,
                'color'           => 'أسود',
                'type'            => 'van',
                'plate_number'    => 'LY-3333',
                'capacity_manual' => 10,
                'status'          => 'Active',
            ]
        );
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $subBusyDriver->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(
            ['driver_id' => $subBusyDriver->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()],
            ['booked' => 1] // يتبقى 9 مقاعد شاغرة
        );

        // إنشاء رحلة جارية خاصة بالسائق المشغول
        $subBusyRoute = Route::firstOrCreate(
            ['driver_id' => $subBusyDriver->id, 'route_name' => 'مسار كمال الصباحي'],
            [
                'vehicle_id' => $subBusyVehicle->id,
                'route_type' => 'Morning',
                'shift_slot' => 'morning_go',
                'status'     => 'Active',
                'start_time' => '07:00:00',
            ]
        );

        $subBusyTrip = Trip::firstOrCreate(
            [
                'driver_id'  => $subBusyDriver->id,
                'trip_date'  => Carbon::today()->toDateString(),
                'shift_slot' => 'morning_go',
                'status'     => 'in_progress',
            ],
            [
                'route_id'             => $subBusyRoute->id,
                'trip_type'            => 'Morning',
                'start_lat'            => 32.883000,
                'start_lng'            => 13.183000,
                'scheduled_start_time' => now(),
                'actual_start_time'    => now(),
            ]
        );

        // 7. إنشاء الرحلات الجاهزة للاختبار للسائق الأصلي
        // أ) رحلة الصباح (Morning Trip): طفلان صعدا (1 و 2) وطفلان في الانتظار (3 و 4)
        $morningTrip = Trip::updateOrCreate(
            [
                'driver_id'  => $origDriver->id,
                'trip_date'  => Carbon::today()->toDateString(),
                'shift_slot' => 'morning_go',
            ],
            [
                'route_id'             => $routeMorning->id,
                'trip_type'            => 'Morning',
                'status'               => 'in_progress',
                'start_lat'            => 32.885000,
                'start_lng'            => 13.185000,
                'scheduled_start_time' => now(),
                'actual_start_time'    => now(),
            ]
        );

        TripStop::where('trip_id', $morningTrip->id)->delete();

        // محطات رحلة الصباح:
        // طفل 1: صعد بالحافلة
        TripStop::create(['trip_id' => $morningTrip->id, 'child_id' => $child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => $addr1->lat, 'lng' => $addr1->lng, 'label' => 'منزل محمد سالم']);
        // طفل 2: صعد بالحافلة
        TripStop::create(['trip_id' => $morningTrip->id, 'child_id' => $child2->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 2, 'status' => TripStop::STATUS_BOARDED, 'lat' => $addr2->lat, 'lng' => $addr2->lng, 'label' => 'منزل سارة سالم']);
        // طفل 3: ينتظر بالمنزل
        TripStop::create(['trip_id' => $morningTrip->id, 'child_id' => $child3->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING, 'lat' => $addr3->lat, 'lng' => $addr3->lng, 'label' => 'منزل ياسين منير']);
        // طفل 4: ينتظر بالمنزل
        TripStop::create(['trip_id' => $morningTrip->id, 'child_id' => $child4->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 4, 'status' => TripStop::STATUS_PENDING, 'lat' => $addr4->lat, 'lng' => $addr4->lng, 'label' => 'منزل مريم منير']);
        // محطات المدارس
        TripStop::create(['trip_id' => $morningTrip->id, 'school_id' => $schoolA->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 5, 'status' => TripStop::STATUS_PENDING, 'lat' => $schoolA->lat, 'lng' => $schoolA->lng, 'label' => $schoolA->name]);
        TripStop::create(['trip_id' => $morningTrip->id, 'school_id' => $schoolB->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 6, 'status' => TripStop::STATUS_PENDING, 'lat' => $schoolB->lat, 'lng' => $schoolB->lng, 'label' => $schoolB->name]);

        // ب) رحلة العودة (Return Trip): طفلان صعدا من المدرسة ومتجهان للمنازل
        $returnTrip = Trip::updateOrCreate(
            [
                'driver_id'  => $origDriver->id,
                'trip_date'  => Carbon::today()->toDateString(),
                'shift_slot' => 'afternoon_return',
            ],
            [
                'route_id'             => $routeReturn->id,
                'trip_type'            => 'Afternoon',
                'status'               => 'in_progress',
                'start_lat'            => $schoolA->lat,
                'start_lng'            => $schoolA->lng,
                'scheduled_start_time' => now(),
                'actual_start_time'    => now(),
            ]
        );

        TripStop::where('trip_id', $returnTrip->id)->delete();

        // محطات رحلة العودة:
        TripStop::create(['trip_id' => $returnTrip->id, 'school_id' => $schoolA->id, 'child_id' => $child1->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => $schoolA->lat, 'lng' => $schoolA->lng, 'label' => 'استلام محمد من المدرسة']);
        TripStop::create(['trip_id' => $returnTrip->id, 'school_id' => $schoolB->id, 'child_id' => $child2->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 2, 'status' => TripStop::STATUS_BOARDED, 'lat' => $schoolB->lat, 'lng' => $schoolB->lng, 'label' => 'استلام سارة من المدرسة']);
        TripStop::create(['trip_id' => $returnTrip->id, 'child_id' => $child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING, 'lat' => $addr1->lat, 'lng' => $addr1->lng, 'label' => 'منزل محمد سالم']);
        TripStop::create(['trip_id' => $returnTrip->id, 'child_id' => $child2->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 4, 'status' => TripStop::STATUS_PENDING, 'lat' => $addr2->lat, 'lng' => $addr2->lng, 'label' => 'منزل سارة سالم']);

        // =========================================================================
        // 🚨 تجهيز السائقين الـ 6 لحالات اختبار الطوارئ الدقيقة المطلوبة:
        // =========================================================================

        // الحالة 1: سائق بعيد جداً (خارج نطاق الـ 15 كم) - المفروض لا يصله الطلب
        $driverFarUser = User::firstOrCreate(
            ['email' => 'driver.case1.far@darby.ly'],
            [
                'full_name'     => 'كابتن طارق البعيد (خارج النطاق)',
                'phone_number'  => '0919991001',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $driverFar = Driver::firstOrCreate(
            ['user_id' => $driverFarUser->id],
            [
                'national_id'       => 'NAT900001',
                'license_number'    => 'LIC900001',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.500000, // بعيد جداً (> 40 كم عن طرابلس)
                'current_lng'       => 13.500000,
            ]
        );
        $vehFar = Vehicle::firstOrCreate(['driver_id' => $driverFar->id], ['brand' => 'تويوتا', 'model' => 'كوستر', 'year' => 2022, 'color' => 'أبيض', 'type' => 'van', 'plate_number' => 'LY-9001', 'capacity_manual' => 14, 'status' => 'Active']);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $driverFar->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(['driver_id' => $driverFar->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()], ['booked' => 0]);

        // الحالة 2: سائق قريب وما عنداش رحلة لكن وثائقه منتهية الصلاحية - المفروض لا يصله الطلب
        $driverExpiredDocsUser = User::firstOrCreate(
            ['email' => 'driver.case2.expireddocs@darby.ly'],
            [
                'full_name'     => 'كابتن فرج (وثائق منتهية الصلاحية)',
                'phone_number'  => '0919991002',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $driverExpiredDocs = Driver::firstOrCreate(
            ['user_id' => $driverExpiredDocsUser->id],
            [
                'national_id'       => 'NAT900002',
                'license_number'    => 'LIC900002',
                'license_expiry'    => now()->subMonths(2)->toDateString(), // رخصة منتهية
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.884500, // قريب جداً
                'current_lng'       => 13.184500,
            ]
        );
        $vehExpired = Vehicle::firstOrCreate(['driver_id' => $driverExpiredDocs->id], ['brand' => 'تويوتا', 'model' => 'هاي آس', 'year' => 2020, 'color' => 'فضي', 'type' => 'van', 'plate_number' => 'LY-9002', 'capacity_manual' => 12, 'status' => 'Active']);
        \App\Models\Driver\VehicleDocument::updateOrCreate(
            ['vehicle_id' => $vehExpired->id, 'doc_type' => 'insurance'],
            [
                'state'       => 'expired',
                'expiry_date' => now()->subMonth()->toDateString(),
                'file_url'    => 'uploads/documents/expired_insurance.pdf',
                'is_verified' => 1,
            ]
        );
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $driverExpiredDocs->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(['driver_id' => $driverExpiredDocs->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()], ['booked' => 0]);

        // الحالة 3: سائق عنده رحلة ونشط لكن السيارة ممتلئة بالكامل - المفروض لا يصله الطلب
        $driverFullCarUser = User::firstOrCreate(
            ['email' => 'driver.case3.fullcar@darby.ly'],
            [
                'full_name'     => 'كابتن حمزة (سيارة ممتلئة بالكامل)',
                'phone_number'  => '0919991003',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $driverFullCar = Driver::firstOrCreate(
            ['user_id' => $driverFullCarUser->id],
            [
                'national_id'       => 'NAT900003',
                'license_number'    => 'LIC900003',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.883500, // قريب
                'current_lng'       => 13.183500,
            ]
        );
        $vehFull = Vehicle::firstOrCreate(['driver_id' => $driverFullCar->id], ['brand' => 'هيونداي', 'model' => 'H1', 'year' => 2021, 'color' => 'رمادي', 'type' => 'van', 'plate_number' => 'LY-9003', 'capacity_manual' => 10, 'status' => 'Active']);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $driverFullCar->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(['driver_id' => $driverFullCar->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()], ['booked' => 10]); // 10 من 10 ممتلئة!

        // الحالة 4: سائق عنده رحلة وعنده مقاعد لكن وقته يتجاوز الحد المسموح (سيتأخر على مواعيد أطفاله) - المفروض لا يصله الطلب
        $driverLateUser = User::firstOrCreate(
            ['email' => 'driver.case4.late@darby.ly'],
            [
                'full_name'     => 'كابتن بشير (سيتأخر على مواعيد تسليم أطفاله)',
                'phone_number'  => '0919991004',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $driverLate = Driver::firstOrCreate(
            ['user_id' => $driverLateUser->id],
            [
                'national_id'       => 'NAT900004',
                'license_number'    => 'LIC900004',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.882000,
                'current_lng'       => 13.182000,
            ]
        );
        $vehLate = Vehicle::firstOrCreate(['driver_id' => $driverLate->id], ['brand' => 'نيسان', 'model' => 'أورفان', 'year' => 2022, 'color' => 'أزرق', 'type' => 'van', 'plate_number' => 'LY-9004', 'capacity_manual' => 14, 'status' => 'Active']);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $driverLate->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(['driver_id' => $driverLate->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()], ['booked' => 2]); // شاغر 12 مقعد
        Route::updateOrCreate(
            ['driver_id' => $driverLate->id, 'route_name' => 'مسار بشير الطويل جداً'],
            [
                'vehicle_id'         => $vehLate->id,
                'route_type'         => 'Morning',
                'shift_slot'         => 'morning_go',
                'status'             => 'Active',
                'estimated_duration' => 85, // زمن المسار 85 دقيقة (أعلى من الحد الأقصى 60 دقيقة فيتأخر حتماً)
            ]
        );

        // الحالة 5: سائق متفرغ وقريب ووثائقه سارية وعنده مقاعد - المفروض يظهر له الطلب ويصله ✅
        $driverEligible1User = User::firstOrCreate(
            ['email' => 'driver.case5.eligible@darby.ly'],
            [
                'full_name'     => 'كابتن أنيس المؤهل (قريب ومقاعد متوفرة ووقت مناسب)',
                'phone_number'  => '0919991005',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $driverEligible1 = Driver::firstOrCreate(
            ['user_id' => $driverEligible1User->id],
            [
                'national_id'       => 'NAT900005',
                'license_number'    => 'LIC900005',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.884000,
                'current_lng'       => 13.184000,
            ]
        );
        $vehEligible1 = Vehicle::firstOrCreate(['driver_id' => $driverEligible1->id], ['brand' => 'تويوتا', 'model' => 'هاي آس', 'year' => 2023, 'color' => 'أبيض', 'type' => 'van', 'plate_number' => 'LY-9005', 'capacity_manual' => 12, 'status' => 'Active']);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $driverEligible1->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(['driver_id' => $driverEligible1->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()], ['booked' => 1]); // شاغر 11 مقعد
        Route::updateOrCreate(
            ['driver_id' => $driverEligible1->id, 'route_name' => 'مسار أنيس القصير'],
            [
                'vehicle_id'         => $vehEligible1->id,
                'route_type'         => 'Morning',
                'shift_slot'         => 'morning_go',
                'status'             => 'Active',
                'estimated_duration' => 25, // زمن المسار 25 دقيقة فقط (مريح ومسموح)
            ]
        );

        // الحالة 6: سائق ما عنداش رحلة إطلاقاً ووثائقه مفعلة بالكامل وقريب جداً - المفروض يظهر له الطلب فوراً ✅
        $driverFreeNearUser = User::firstOrCreate(
            ['email' => 'driver.case6.freenear@darby.ly'],
            [
                'full_name'     => 'كابتن وسام المتفرغ (قريب وبدون أي رحلة ووثائقه ممتازة)',
                'phone_number'  => '0919991006',
                'password'      => Hash::make(self::DEFAULT_PASSWORD),
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'       => $driverRoleId,
                'is_active'     => 1,
            ]
        );
        $driverFreeNear = Driver::firstOrCreate(
            ['user_id' => $driverFreeNearUser->id],
            [
                'national_id'       => 'NAT900006',
                'license_number'    => 'LIC900006',
                'license_expiry'    => now()->addYears(2)->toDateString(),
                'status'            => 'Approved',
                'accepted_gender'   => 'both',
                'subscription_type' => 'both',
                'morning_go'        => 1,
                'morning_return'    => 1,
                'current_lat'       => 32.884800,
                'current_lng'       => 13.184800,
            ]
        );
        $vehFreeNear = Vehicle::firstOrCreate(['driver_id' => $driverFreeNear->id], ['brand' => 'نيسان', 'model' => 'أورفان', 'year' => 2024, 'color' => 'فضي', 'type' => 'van', 'plate_number' => 'LY-9006', 'capacity_manual' => 14, 'status' => 'Active']);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $driverFreeNear->id, 'zone_id' => $zone->id]);
        DriverSeatSlot::updateOrCreate(['driver_id' => $driverFreeNear->id, 'slot' => 'morning_go', 'date' => Carbon::today()->toDateString()], ['booked' => 0]); // متفرغ تماماً

        // طباعة التقرير الشامل في الطرفية
        $this->command?->line("\n=======================================================");
        $this->command?->info("🎉 تم تجهيز بيانات بيئة اختبار الطوارئ والحالات الست بنجاح!");
        $this->command?->line("=======================================================");
        $this->command?->line("🔑 بيانات الحسابات المجهزة:");
        $this->command?->line("1) مشرف النظام (Admin):");
        $this->command?->line("   - Email: admin.emergency@darby.ly");
        $this->command?->line("   - Phone: 0919990001 | Password: " . self::DEFAULT_PASSWORD);
        $this->command?->line("   - Token: {$adminToken}");
        $this->command?->line("-------------------------------------------------------");
        $this->command?->line("2) السائق الأساسي المعطل (الكابتن محمود):");
        $this->command?->line("   - Driver ID: {$origDriver->id} | User ID: {$origDriverUser->id}");
        $this->command?->line("   - Email: driver.primary@darby.ly | Phone: 0919990002 | Password: " . self::DEFAULT_PASSWORD);
        $this->command?->line("   - Token: {$origDriverToken}");
        $this->command?->line("   - رحلة الصباح الجاهزة (Morning Trip ID): {$morningTrip->id}");
        $this->command?->line("-------------------------------------------------------");
        $this->command?->line("3) الحالات الـ 6 لاختبار طلب الطوارئ (كلمة المرور للجميع: " . self::DEFAULT_PASSWORD . "):");
        $this->command?->line("   [❌ حالة 1 - بعيد جداً]: Email: driver.case1.far@darby.ly | Phone: 0919991001 (النتيجة المتوقعة: لا يصله الطلب)");
        $this->command?->line("   [❌ حالة 2 - وثائق منتهية]: Email: driver.case2.expireddocs@darby.ly | Phone: 0919991002 (النتيجة المتوقعة: لا يصله الطلب)");
        $this->command?->line("   [❌ حالة 3 - سيارة ممتلئة]: Email: driver.case3.fullcar@darby.ly | Phone: 0919991003 (النتيجة المتوقعة: لا يصله الطلب)");
        $this->command?->line("   [❌ حالة 4 - وقت متأخر]: Email: driver.case4.late@darby.ly | Phone: 0919991004 (النتيجة المتوقعة: لا يصله الطلب)");
        $this->command?->line("   [✅ حالة 5 - مؤهل ومقاعد ووقت متاح]: Email: driver.case5.eligible@darby.ly | Phone: 0919991005 (النتيجة المتوقعة: يصله الطلب)");
        $this->command?->line("   [✅ حالة 6 - متفرغ وقريب ووثائق سارية]: Email: driver.case6.freenear@darby.ly | Phone: 0919991006 (النتيجة المتوقعة: يصله الطلب)");
        $this->command?->line("=======================================================\n");
        $this->command?->line("   - Parent 2 (أب ياسين ومريم): Phone: 0919990022 | Token: {$parent2Token}");
        $this->command?->line("=======================================================\n");
    }

    private function ensureSubscription($parent, $child, $driver, $route, $address, $school): void
    {
        $req = SubscriptionRequest::firstOrCreate(
            ['parent_id' => $parent->id, 'driver_id' => $driver->id],
            [
                'status'                      => 'accepted',
                'total_price'                 => 100.00,
                'total_amount_after_discount' => 100.00,
                'children_count'              => 1,
            ]
        );

        $exists = DB::table('request_children')->where('request_id', $req->id)->where('child_id', $child->id)->exists();
        if (!$exists) {
            $req->children()->attach([$child->id => ['total_amount_after_discount' => 100.00]]);
        }
        $reqChildId = RequestChild::where('request_id', $req->id)->where('child_id', $child->id)->value('id');

        ActiveSubscription::firstOrCreate(
            ['subscription_request_id' => $req->id, 'request_child_id' => $reqChildId],
            [
                'route_id'    => $route->id,
                'pickup_lat'  => $address->lat,
                'pickup_lng'  => $address->lng,
                'dropoff_lat' => $school->lat,
                'dropoff_lng' => $school->lng,
                'status'      => 'active',
            ]
        );
    }
}
