<?php

namespace Tests\Feature;

use App\Models\Driver\Address as DriverAddress;
use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\Route;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\User;
use App\Services\Trip\TripLifecycleService;
use App\Support\GeoEstimator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DriverSmartRouteSequencingTest extends TestCase
{
    use DatabaseTransactions;
    /**
     * اختبار 1: التحقق من ترتيب الخوارزمية الجغرافية في الحالتين (منزل السائق vs الموقع الحي بالسراج)
     */
    public function test_geo_estimator_sequences_correctly_for_home_vs_live_location()
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/driver_smart_coordinates.json')), true);

        $school = $fixture['destination_school'];
        $schoolPoints = [['id' => 999, 'lat' => (float)$school['lat'], 'lng' => (float)$school['lng']]];

        $homePoints = array_map(function ($c) {
            return [
                'id'        => $c['id'],
                'child_id'  => $c['id'],
                'name'      => $c['child_name'],
                'lat'       => (float)$c['lat'],
                'lng'       => (float)$c['lng'],
            ];
        }, $fixture['children_stops']);

        // الحالة 1: انطلاق من منزل السائق (حي الأندلس)
        $driverHome = $fixture['scenarios']['scenario_1_home_anchor']['driver_start_point'];
        $orderFromHome = GeoEstimator::orderStopsForDirection($homePoints, $schoolPoints, true, $driverHome);

        // المحطة الأولى من المنزل يجب أن تكون سند (ID = 1) بالسياحية
        $this->assertEquals(1, $orderFromHome[0]['id'], 'يجب أن يكون الطفل الأول من منزل السائق هو سند طه (السياحية)');
        // المحطة الأخيرة يجب أن تكون المدرسة
        $this->assertEquals(999, end($orderFromHome)['id'], 'المحطة الأخيرة يجب أن تكون المدرسة');

        // الحالة 2: انطلاق من موقع السائق الحي بالسراج
        $driverLive = $fixture['scenarios']['scenario_2_live_gps_anchor']['driver_start_point'];
        $orderFromLive = GeoEstimator::orderStopsForDirection($homePoints, $schoolPoints, true, $driverLive);

        // المحطة الأولى من السراج يجب أن تكون محمد (ID = 3) بغوط الشعال لأنه الأقرب للسراج
        $this->assertEquals(3, $orderFromLive[0]['id'], 'يجب أن يكون الطفل الأول من موقع السراج هو محمد عبد السلام (غوط الشعال)');
        // المحطة الأخيرة يجب أن تكون المدرسة
        $this->assertEquals(999, end($orderFromLive)['id']);
    }

    /**
     * اختبار 2: التحقق من إعادة ترتيب محطات رحلة حقيقية ديناميكياً عند بدء الرحلة
     */
    public function test_trip_stops_are_reordered_dynamically_on_start_trip()
    {
        // تجهيز مستخدم وسائق
        $user = User::factory()->create(['role_id' => 3]);
        $driver = Driver::create([
            'user_id'         => $user->id,
            'national_id'     => '99' . rand(1000000000, 9999999999),
            'license_number'  => 'DL-' . rand(10000, 99999),
            'license_expiry'  => now()->addYears(2),
            'status'          => 'Approved',
            'shift'           => 1,
            'morning_go'      => 1,
        ]);

        // عنوان منزل السائق (حي الأندلس)
        DriverAddress::create([
            'user_id'    => $user->id,
            'label'      => 'المنزل',
            'lat'        => 32.887200,
            'lng'        => 13.155400,
            'is_default' => true,
        ]);

        $school = School::create([
            'name'       => 'مدرسة طرابلس المركزية',
            'address'    => 'طرابلس',
            'lat'        => 32.889000,
            'lng'        => 13.185000,
            'is_active'  => 1,
        ]);

        $child1 = Child::create([
            'full_name'    => 'سند طه', 
            'school_id'    => $school->id, 
            'parent_id'    => $user->id, 
            'birth_date'   => '2015-01-01',
            'gender'       => 'male',
            'grade'        => 5,
            'is_active'    => 1
        ]);
        $child3 = Child::create([
            'full_name'    => 'محمد عبد السلام', 
            'school_id'    => $school->id, 
            'parent_id'    => $user->id, 
            'birth_date'   => '2016-02-02',
            'gender'       => 'male',
            'grade'        => 4,
            'is_active'    => 1
        ]);

        // مسار ورحلة
        $route = Route::create([
            'driver_id'   => $driver->id,
            'route_name'  => 'مسار صباحي تجريبي',
            'route_type'  => 'Morning',
            'shift_slot'  => 'morning_go',
            'status'      => 'Active',
            'start_time'  => '07:00:00',
        ]);

        $trip = Trip::create([
            'driver_id'   => $driver->id,
            'route_id'    => $route->id,
            'trip_type'   => 'Morning',
            'shift_slot'  => 'morning_go',
            'status'      => 'pending',
            'trip_date'   => now()->toDateString(),
        ]);

        // محطات الرحلة بالترتيب الأساسي القديم: سند أولاً (1) ثم محمد ثانياً (2) ثم المدرسة (3)
        $stop1 = TripStop::create([
            'trip_id'        => $trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $child1->id,
            'lat'            => 32.882100,
            'lng'            => 13.142000,
            'label'          => 'سند - سياحية',
            'sequence_order' => 1,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        $stop3 = TripStop::create([
            'trip_id'        => $trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $child3->id,
            'lat'            => 32.862000,
            'lng'            => 13.155000,
            'label'          => 'محمد - غوط الشعال',
            'sequence_order' => 2,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        $schoolStop = TripStop::create([
            'trip_id'        => $trip->id,
            'stop_type'      => TripStop::TYPE_SCHOOL,
            'school_id'      => $school->id,
            'lat'            => 32.889000,
            'lng'            => 13.185000,
            'label'          => 'مدرسة طرابلس',
            'sequence_order' => 3,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        // الآن: السائق يبدأ الرحلة من السراج (32.845000, 13.125000)
        $lifecycleService = app(TripLifecycleService::class);
        $lifecycleService->computeLiveEtas($trip, 32.845000, 13.125000);

        // التحقق من إعادة ترتيب المحطات
        $stop1->refresh();
        $stop3->refresh();
        $schoolStop->refresh();

        // نظراً لأن السائق في السراج، فإن محمد (غوط الشعال) يجب أن يصبح هو المحطة رقم 1!
        $this->assertEquals(1, $stop3->sequence_order, 'محطة محمد بغوط الشعال يجب أن تصبح رقم 1 لأنها الأقرب للسراج');
        $this->assertEquals(2, $stop1->sequence_order, 'محطة سند بالسياحية يجب أن تصبح رقم 2');
        $this->assertEquals(3, $schoolStop->sequence_order, 'محطة المدرسة تبقى الأخيرة');
        $this->assertNotNull($stop3->eta_minutes);
    }

    /**
     * اختبار 3: التحقق من إضافة عناوين السائق وتعيين العنوان الافتراضي (المنزل)
     */
    public function test_driver_address_management_and_default_flag()
    {
        $user = User::factory()->create(['role_id' => 3]);
        $driver = Driver::create([
            'user_id'         => $user->id,
            'national_id'     => '88' . rand(1000000000, 9999999999),
            'license_number'  => 'DL-' . rand(10000, 99999),
            'license_expiry'  => now()->addYears(2),
            'status'          => 'Approved',
            'shift'           => 1,
            'morning_go'      => 1,
        ]);

        $this->actingAs($user, 'sanctum');

        // 1. إضافة عنوان المنزل الأول (يجب أن يصبح تلقائياً is_default = true)
        $response1 = $this->postJson('/api/driver/addresses', [
            'label' => 'المنزل الرئيسي',
            'lat'   => 32.887200,
            'lng'   => 13.155400,
        ]);

        $response1->assertStatus(201)
            ->assertJsonPath('data.label', 'المنزل الرئيسي')
            ->assertJsonPath('data.is_default', true);

        $address1Id = $response1->json('data.id');

        // 2. إضافة عنوان ثانٍ (موقف السيارات)
        $response2 = $this->postJson('/api/driver/addresses', [
            'label' => 'موقف السيارات',
            'lat'   => 32.871100,
            'lng'   => 13.142000,
        ]);

        $response2->assertStatus(201)
            ->assertJsonPath('data.is_default', false);

        $address2Id = $response2->json('data.id');

        // 3. تعديل العنوان الثاني ليكون الافتراضي
        $response3 = $this->putJson("/api/driver/addresses/{$address2Id}", [
            'is_default' => true,
        ]);

        $response3->assertStatus(200)
            ->assertJsonPath('data.is_default', true);

        // التحقق من أن العنوان الأول تم إلغاء تفعيله كافتراضي
        $this->assertDatabaseHas('addresses', ['id' => $address1Id, 'is_default' => 0]);
        $this->assertDatabaseHas('addresses', ['id' => $address2Id, 'is_default' => 1]);
    }
}
