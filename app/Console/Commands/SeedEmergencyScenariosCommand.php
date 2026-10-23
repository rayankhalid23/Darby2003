<?php

namespace App\Console\Commands;

use App\Models\Driver\Address as DriverAddress;
use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SeedEmergencyScenariosCommand extends Command
{
    protected $signature = 'trip:seed-emergency-scenarios {--force : إعادة تهيئة الحسابات والرحلات}';
    protected $description = 'توليد سيناريوهات الطوارئ الأربعة الكاملة (ذهاب وإياب) مع حسابات السائقين والبدلاء والأولياء والأدمن للاختبار في Postman';

    public function handle(): int
    {
        $this->info("🚀 جاري تجهيز سيناريوهات الطوارئ الأربعة للاختبار في Postman...");

        $today = Carbon::today()->toDateString();

        // 1. حساب الأدمن
        $adminUser = User::updateOrCreate(
            ['email' => 'admin_emergency@darby.ly'],
            [
                'full_name'    => 'مشرف رادار الطوارئ',
                'phone_number' => '0919999001',
                'password'     => Hash::make('password123'),
                'role_id'      => 1, // SuperAdmin
                'is_active'    => 1,
            ]
        );
        $adminToken = $adminUser->createToken('admin_postman')->plainTextToken;

        // 2. حساب ولي الأمر
        $parentUser = User::updateOrCreate(
            ['email' => 'parent_emergency@darby.ly'],
            [
                'full_name'         => 'علي مصطفى الترهوني',
                'phone_number'      => '0919999002',
                'alternative_phone' => '0929999002',
                'password'          => Hash::make('password123'),
                'role_id'           => 7, // Parent
                'is_active'         => 1,
            ]
        );
        $parentToken = $parentUser->createToken('parent_postman')->plainTextToken;

        // 3. المدرسة المشتركة
        $school = School::updateOrCreate(
            ['name' => 'مدرسة طرابلس المركزية'],
            [
                'address'   => 'شارع عمر المختار، طرابلس',
                'lat'       => 32.889000,
                'lng'       => 13.185000,
                'is_active' => 1,
            ]
        );

        // 4. الأطفال الأربعة
        $child1 = Child::updateOrCreate(
            ['full_name' => 'سند طه القمودي', 'parent_id' => $parentUser->id],
            [
                'school_id'  => $school->id,
                'birth_date' => '2015-03-01',
                'gender'     => 'male',
                'grade'      => 4,
                'is_active'  => 1,
            ]
        );

        $child2 = Child::updateOrCreate(
            ['full_name' => 'أحمد علي سالم', 'parent_id' => $parentUser->id],
            [
                'school_id'  => $school->id,
                'birth_date' => '2016-05-15',
                'gender'     => 'male',
                'grade'      => 3,
                'is_active'  => 1,
            ]
        );

        $child3 = Child::updateOrCreate(
            ['full_name' => 'محمد عبد السلام المصراتي', 'parent_id' => $parentUser->id],
            [
                'school_id'  => $school->id,
                'birth_date' => '2014-08-20',
                'gender'     => 'male',
                'grade'      => 5,
                'is_active'  => 1,
            ]
        );

        $child4 = Child::updateOrCreate(
            ['full_name' => 'فاطمة عمر القرقني', 'parent_id' => $parentUser->id],
            [
                'school_id'  => $school->id,
                'birth_date' => '2017-02-10',
                'gender'     => 'female',
                'grade'      => 2,
                'is_active'  => 1,
            ]
        );

        // 5. السائق الرئيسي الأصلي
        $mainDriverUser = User::updateOrCreate(
            ['email' => 'driver_main@darby.ly'],
            [
                'full_name'    => 'عبد السلام الأشهب (السائق الأصلي)',
                'phone_number' => '0919999010',
                'password'     => Hash::make('password123'),
                'role_id'      => 8,
                'is_active'    => 1,
            ]
        );
        $mainDriver = Driver::updateOrCreate(
            ['user_id' => $mainDriverUser->id],
            [
                'national_id'    => '119999990010',
                'license_number' => 'DL-991100',
                'license_expiry' => now()->addYears(2),
                'status'         => 'Approved',
                'current_lat'    => 32.887200,
                'current_lng'    => 13.155400,
            ]
        );
        $mainVehicle = $mainDriver->vehicles()->updateOrCreate(
            ['plate_number' => '5-11111'],
            [
                'brand'             => 'تويوتا',
                'model'             => 'كوستر',
                'year'              => 2022,
                'color'             => 'أبيض',
                'capacity_manual'   => 14,
                'has_ac'            => 1,
                'status'            => 'Active',
                'vehicle_image_url' => 'https://example.com/bus_main.jpg',
            ]
        );
        $mainDriverToken = $mainDriverUser->createToken('main_driver_postman')->plainTextToken;

        // 6. السائق البديل الجاهز والمؤهل (وثائق سارية + قريب من المنطقة + ليس لديه رحلة الآن)
        $subDriverUser = User::updateOrCreate(
            ['email' => 'driver_substitute@darby.ly'],
            [
                'full_name'         => 'طارق المنصوري (الكابتن البديل)',
                'phone_number'      => '0919999020',
                'alternative_phone' => '0929999020',
                'avatar_url'        => 'https://example.com/avatar_sub.jpg',
                'password'          => Hash::make('password123'),
                'role_id'           => 8,
                'is_active'         => 1,
            ]
        );
        $subDriver = Driver::updateOrCreate(
            ['user_id' => $subDriverUser->id],
            [
                'national_id'    => '119999990020',
                'license_number' => 'DL-992200',
                'license_expiry' => now()->addYears(2),
                'status'         => 'Approved',
                'current_lat'    => 32.875000, // قرقارش - قريب جداً من حي الأندلس والسياحية
                'current_lng'    => 13.165000,
            ]
        );
        $subVehicle = $subDriver->vehicles()->updateOrCreate(
            ['plate_number' => '5-22222'],
            [
                'brand'             => 'هيونداي',
                'model'             => 'H1',
                'year'              => 2023,
                'color'             => 'فضي',
                'capacity_manual'   => 12,
                'has_ac'            => 1,
                'status'            => 'Active',
                'vehicle_image_url' => 'https://example.com/bus_sub.jpg',
            ]
        );
        $subDriverToken = $subDriverUser->createToken('sub_driver_postman')->plainTextToken;

        // 7. إنشاء الحالات الأربعة للرحلات:

        // ===============================================================
        // الحالة 1: رحلة ذهاب صباحية (Morning Go) - أطفال ركبوا + أطفال ينتظرون في المنزل
        // ===============================================================
        $route1 = Route::updateOrCreate(
            ['driver_id' => $mainDriver->id, 'route_name' => 'مسار طوارئ 1 - ذهاب مختلط'],
            [
                'vehicle_id' => $mainVehicle->id,
                'route_type' => 'Morning',
                'shift_slot' => 'morning_go',
                'status'     => 'Active',
                'start_time' => '07:00:00',
            ]
        );
        $trip1 = Trip::updateOrCreate(
            ['route_id' => $route1->id, 'trip_date' => $today],
            [
                'driver_id'            => $mainDriver->id,
                'trip_type'            => 'Morning',
                'shift_slot'           => 'morning_go',
                'status'               => 'in_progress',
                'scheduled_start_time' => '07:00:00',
                'actual_start_time'    => now()->subMinutes(15),
                'start_lat'            => 32.887200,
                'start_lng'            => 13.155400,
            ]
        );
        TripStop::where('trip_id', $trip1->id)->delete();
        // طفل 1: صعد للحافلة (BOARDED)
        TripStop::create([
            'trip_id' => $trip1->id, 'stop_type' => 'home', 'child_id' => $child1->id,
            'lat' => 32.882100, 'lng' => 13.142000, 'label' => 'منزل سند (السياحية)', 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED
        ]);
        // طفل 2: صعد للحافلة (BOARDED)
        TripStop::create([
            'trip_id' => $trip1->id, 'stop_type' => 'home', 'child_id' => $child2->id,
            'lat' => 32.875000, 'lng' => 13.168000, 'label' => 'منزل أحمد (قرقارش)', 'sequence_order' => 2, 'status' => TripStop::STATUS_BOARDED
        ]);
        // طفل 3: ينتظر في المنزل (PENDING)
        TripStop::create([
            'trip_id' => $trip1->id, 'stop_type' => 'home', 'child_id' => $child3->id,
            'lat' => 32.862000, 'lng' => 13.155000, 'label' => 'منزل محمد (غوط الشعال)', 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING
        ]);
        // محطة المدرسة
        TripStop::create([
            'trip_id' => $trip1->id, 'stop_type' => 'school', 'school_id' => $school->id,
            'lat' => 32.889000, 'lng' => 13.185000, 'label' => 'مدرسة طرابلس المركزية', 'sequence_order' => 4, 'status' => TripStop::STATUS_PENDING
        ]);

        // ===============================================================
        // الحالة 2: رحلة ذهاب صباحية - السائق لم يأخذ أي طفل بعد (الجميع ينتظر في منازلهم)
        // ===============================================================
        $route2 = Route::updateOrCreate(
            ['driver_id' => $mainDriver->id, 'route_name' => 'مسار طوارئ 2 - ذهاب بدون ركاب'],
            [
                'vehicle_id' => $mainVehicle->id,
                'route_type' => 'Morning',
                'shift_slot' => 'morning_go',
                'status'     => 'Active',
                'start_time' => '07:15:00',
            ]
        );
        $trip2 = Trip::updateOrCreate(
            ['route_id' => $route2->id, 'trip_date' => $today],
            [
                'driver_id'            => $mainDriver->id,
                'trip_type'            => 'Morning',
                'shift_slot'           => 'morning_go',
                'status'               => 'in_progress',
                'scheduled_start_time' => '07:15:00',
                'actual_start_time'    => now()->subMinutes(5),
                'start_lat'            => 32.887200,
                'start_lng'            => 13.155400,
            ]
        );
        TripStop::where('trip_id', $trip2->id)->delete();
        TripStop::create(['trip_id' => $trip2->id, 'stop_type' => 'home', 'child_id' => $child1->id, 'lat' => 32.882100, 'lng' => 13.142000, 'label' => 'منزل سند', 'sequence_order' => 1, 'status' => TripStop::STATUS_PENDING]);
        TripStop::create(['trip_id' => $trip2->id, 'stop_type' => 'home', 'child_id' => $child2->id, 'lat' => 32.875000, 'lng' => 13.168000, 'label' => 'منزل أحمد', 'sequence_order' => 2, 'status' => TripStop::STATUS_PENDING]);
        TripStop::create(['trip_id' => $trip2->id, 'stop_type' => 'school', 'school_id' => $school->id, 'lat' => 32.889000, 'lng' => 13.185000, 'label' => 'المدرسة', 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING]);

        // ===============================================================
        // الحالة 3: رحلة ذهاب صباحية - جميع الأطفال راكبون داخل السيارة
        // ===============================================================
        $route3 = Route::updateOrCreate(
            ['driver_id' => $mainDriver->id, 'route_name' => 'مسار طوارئ 3 - ذهاب الكل راكب'],
            [
                'vehicle_id' => $mainVehicle->id,
                'route_type' => 'Morning',
                'shift_slot' => 'morning_go',
                'status'     => 'Active',
                'start_time' => '07:30:00',
            ]
        );
        $trip3 = Trip::updateOrCreate(
            ['route_id' => $route3->id, 'trip_date' => $today],
            [
                'driver_id'            => $mainDriver->id,
                'trip_type'            => 'Morning',
                'shift_slot'           => 'morning_go',
                'status'               => 'in_progress',
                'scheduled_start_time' => '07:30:00',
                'actual_start_time'    => now()->subMinutes(20),
                'start_lat'            => 32.887200,
                'start_lng'            => 13.155400,
            ]
        );
        TripStop::where('trip_id', $trip3->id)->delete();
        TripStop::create(['trip_id' => $trip3->id, 'stop_type' => 'home', 'child_id' => $child1->id, 'lat' => 32.882100, 'lng' => 13.142000, 'label' => 'منزل سند', 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED]);
        TripStop::create(['trip_id' => $trip3->id, 'stop_type' => 'home', 'child_id' => $child2->id, 'lat' => 32.875000, 'lng' => 13.168000, 'label' => 'منزل أحمد', 'sequence_order' => 2, 'status' => TripStop::STATUS_BOARDED]);
        TripStop::create(['trip_id' => $trip3->id, 'stop_type' => 'home', 'child_id' => $child3->id, 'lat' => 32.862000, 'lng' => 13.155000, 'label' => 'منزل محمد', 'sequence_order' => 3, 'status' => TripStop::STATUS_BOARDED]);
        TripStop::create(['trip_id' => $trip3->id, 'stop_type' => 'school', 'school_id' => $school->id, 'lat' => 32.889000, 'lng' => 13.185000, 'label' => 'المدرسة', 'sequence_order' => 4, 'status' => TripStop::STATUS_PENDING]);

        // ===============================================================
        // الحالة 4: رحلة إياب / عودة (Afternoon / Return) - تم توصيل طفلين للمنزل وباقي طفل في الحافلة
        // ===============================================================
        $route4 = Route::updateOrCreate(
            ['driver_id' => $mainDriver->id, 'route_name' => 'مسار طوارئ 4 - إياب تم التوصيل الجزئي'],
            [
                'vehicle_id' => $mainVehicle->id,
                'route_type' => 'Afternoon',
                'shift_slot' => 'morning_return',
                'status'     => 'Active',
                'start_time' => '13:30:00',
            ]
        );
        $trip4 = Trip::updateOrCreate(
            ['route_id' => $route4->id, 'trip_date' => $today],
            [
                'driver_id'            => $mainDriver->id,
                'trip_type'            => 'Afternoon',
                'shift_slot'           => 'morning_return',
                'status'               => 'in_progress',
                'scheduled_start_time' => '13:30:00',
                'actual_start_time'    => now()->subMinutes(30),
                'start_lat'            => 32.889000,
                'start_lng'            => 13.185000,
            ]
        );
        TripStop::where('trip_id', $trip4->id)->delete();
        // محطة المدرسة: تم استلام الأطفال منها مسبقاً
        TripStop::create(['trip_id' => $trip4->id, 'stop_type' => 'school', 'school_id' => $school->id, 'lat' => 32.889000, 'lng' => 13.185000, 'label' => 'المدرسة (استلام الأطفال)', 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED]);
        // طفل 1: تم توصيله لمنزله بسلام (DELIVERED_HOME)
        TripStop::create(['trip_id' => $trip4->id, 'stop_type' => 'home', 'child_id' => $child1->id, 'lat' => 32.882100, 'lng' => 13.142000, 'label' => 'منزل سند', 'sequence_order' => 2, 'status' => TripStop::STATUS_DELIVERED_HOME]);
        // طفل 2: تم توصيله لمنزله بسلام (DELIVERED_HOME)
        TripStop::create(['trip_id' => $trip4->id, 'stop_type' => 'home', 'child_id' => $child2->id, 'lat' => 32.875000, 'lng' => 13.168000, 'label' => 'منزل أحمد', 'sequence_order' => 3, 'status' => TripStop::STATUS_DELIVERED_HOME]);
        // طفل 3: لا يزال راكباً داخل الحافلة ولم يصل لمنزله بعد (BOARDED) وتعطلت الحافلة به!
        TripStop::create(['trip_id' => $trip4->id, 'stop_type' => 'home', 'child_id' => $child3->id, 'lat' => 32.862000, 'lng' => 13.155000, 'label' => 'منزل محمد', 'sequence_order' => 4, 'status' => TripStop::STATUS_BOARDED]);

        $this->info("✅ تم إعداد وتجهيز كافة الحسابات والرحلات بنجاح!");

        $this->table(
            ['الدور', 'الاسم', 'البريد الإلكتروني', 'كلمة المرور', 'Bearer Token'],
            [
                ['أدمن / مشرف', $adminUser->full_name, $adminUser->email, 'password123', substr($adminToken, 0, 25) . '...'],
                ['السائق الأصلي', $mainDriverUser->full_name, $mainDriverUser->email, 'password123', substr($mainDriverToken, 0, 25) . '...'],
                ['السائق البديل', $subDriverUser->full_name, $subDriverUser->email, 'password123', substr($subDriverToken, 0, 25) . '...'],
                ['ولي الأمر', $parentUser->full_name, $parentUser->email, 'password123', substr($parentToken, 0, 25) . '...'],
            ]
        );

        $this->table(
            ['رقم الحالة', 'معرّف الرحلة (Trip ID)', 'النوع / الفترة', 'وصف الحالة للاختبار'],
            [
                ['الحالة 1', $trip1->id, 'Morning (ذهاب)', 'ركاب داخل السيارة (2) + طفل ينتظر في المنزل (1)'],
                ['الحالة 2', $trip2->id, 'Morning (ذهاب)', 'السائق لم يأخذ أي طفل بعد (الجميع ينتظر في منازلهم)'],
                ['الحالة 3', $trip3->id, 'Morning (ذهاب)', 'جميع الأطفال راكبون داخل السيارة (3 أطفال)'],
                ['الحالة 4', $trip4->id, 'Afternoon (إياب)', 'تم توصيل طفلين للمنزل وطفل واحد لا يزال داخل الحافلة'],
            ]
        );

        return Command::SUCCESS;
    }
}
