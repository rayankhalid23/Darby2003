<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Parent\Address;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\Route as RouteModel;

class DriverTripNextStopAndGeofencingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected School $school;
    protected Child $child1;
    protected Child $child2;
    protected ActiveSubscription $sub1;
    protected ActiveSubscription $sub2;
    protected RouteModel $route;
    protected Trip $trip;
    protected TripStop $stop1;
    protected TripStop $stop2;
    protected TripStop $stopSchool;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي',
            'email'         => 'driver.nextstop.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);

        $parentUser = User::create([
            'full_name'     => 'ولي أمر تجريبي',
            'email'         => 'parent.nextstop.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);
        $parent = ParentModel::findOrFail($parentUser->id);
        $parent->update(['is_trusted' => 1]);

        $this->school = School::create([
            'name'    => 'مدرسة الفجر الجديد الابتدائية',
            'address' => 'شارع النصر، طرابلس',
            'lat'     => 32.895000,
            'lng'     => 13.185000,
            'status'  => 'Approved',
        ]);

        $addr1 = Address::create([
            'user_id' => $parentUser->id,
            'label'     => 'حي الأندلس، شارع 1',
            'lat'       => 32.871000,
            'lng'       => 13.156000,
        ]);

        $addr2 = Address::create([
            'user_id' => $parentUser->id,
            'label'     => 'حي الأندلس، شارع 2',
            'lat'       => 32.873000,
            'lng'       => 13.158000,
        ]);

        $this->child1 = Child::create([
            'parent_id'           => $parent->id,
            'school_id'           => $this->school->id,
            'address_id'          => $addr1->id,
            'full_name'           => 'أحمد سالم',
            'birth_date'          => '2017-01-01',
            'gender'              => 'male',
            'grade'               => 2,
            'notification_radius' => 500,
        ]);

        $this->child2 = Child::create([
            'parent_id'           => $parent->id,
            'school_id'           => $this->school->id,
            'address_id'          => $addr2->id,
            'full_name'           => 'مريم سالم',
            'birth_date'          => '2018-05-15',
            'gender'              => 'female',
            'grade'               => 1,
            'notification_radius' => 500,
        ]);

        $vehicle = Vehicle::create([
            'driver_id'       => $this->driver->id,
            'plate_number'    => '5-99887',
            'brand'           => 'Toyota',
            'model'           => 'HiAce',
            'year'            => 2023,
            'color'           => 'Silver',
            'type'            => 'Van',
            'capacity_manual' => 14,
            'status'          => 'active',
        ]);

        $this->route = RouteModel::create([
            'driver_id'          => $this->driver->id,
            'vehicle_id'         => $vehicle->id,
            'route_name'         => 'مسار صباحي تجريبي',
            'route_type'         => 'Morning',
            'status'             => 'Active',
            'start_time'         => '07:00',
            'estimated_duration' => 35,
            'total_distance'     => 10.5,
        ]);

        $subReq = SubscriptionRequest::create([
            'parent_id'                   => $parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_ACCEPTED,
            'total_price'                 => 200,
            'discount_amount'             => 0,
            'total_amount_after_discount' => 200,
            'children_count'              => 2,
        ]);

        $this->sub1 = ActiveSubscription::create([
            'subscription_request_id' => $subReq->id,
            'status'                  => 'active',
            'child_id'                => $this->child1->id,
            'driver_id'               => $this->driver->id,
            'parent_id'               => $parentUser->id,
            'route_id'                => $this->route->id,
            'pickup_lat'              => 32.871000,
            'pickup_lng'              => 13.156000,
            'pickup_label'            => 'حي الأندلس، شارع 1',
            'dropoff_lat'             => 32.895000,
            'dropoff_lng'             => 13.185000,
            'dropoff_label'           => 'مدرسة الفجر الجديد',
        ]);

        $this->sub2 = ActiveSubscription::create([
            'subscription_request_id' => $subReq->id,
            'status'                  => 'active',
            'child_id'                => $this->child2->id,
            'driver_id'               => $this->driver->id,
            'parent_id'               => $parentUser->id,
            'route_id'                => $this->route->id,
            'pickup_lat'              => 32.873000,
            'pickup_lng'              => 13.158000,
            'pickup_label'            => 'حي الأندلس، شارع 2',
            'dropoff_lat'             => 32.895000,
            'dropoff_lng'             => 13.185000,
            'dropoff_label'           => 'مدرسة الفجر الجديد',
        ]);

        $this->trip = Trip::create([
            'driver_id'    => $this->driver->id,
            'route_id'     => $this->route->id,
            'trip_type'    => 'Morning',
            'status'       => 'in_progress',
            'trip_date'    => now()->toDateString(),
            'scheduled_at' => now(),
        ]);

        $this->stop1 = TripStop::create([
            'trip_id'        => $this->trip->id,
            'child_id'       => $this->child1->id,
            'school_id'      => $this->school->id,
            'stop_type'      => 'home',
            'lat'            => 32.871000,
            'lng'            => 13.156000,
            'label'          => 'حي الأندلس، شارع 1',
            'sequence_order' => 1,
            'status'         => 'pending',
        ]);

        $this->stop2 = TripStop::create([
            'trip_id'        => $this->trip->id,
            'child_id'       => $this->child2->id,
            'school_id'      => $this->school->id,
            'stop_type'      => 'home',
            'lat'            => 32.873000,
            'lng'            => 13.158000,
            'label'          => 'حي الأندلس، شارع 2',
            'sequence_order' => 2,
            'status'         => 'pending',
        ]);

        $this->stopSchool = TripStop::create([
            'trip_id'        => $this->trip->id,
            'child_id'       => null,
            'school_id'      => $this->school->id,
            'stop_type'      => 'school',
            'lat'            => 32.895000,
            'lng'            => 13.185000,
            'label'          => 'مدرسة الفجر الجديد الابتدائية',
            'sequence_order' => 3,
            'status'         => 'pending',
        ]);
    }

    public function test_manual_pickup_fails_when_location_is_missing(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/children/{$this->sub1->id}/status", [
                'action' => 'pickup',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'LOCATION_REQUIRED');
    }

    public function test_manual_pickup_fails_when_driver_is_out_of_geofence_range(): void
    {
        // إحداثيات بعيدة عن منزل الطفل بأكثر من 100م (مثلاً بعد 2 كم)
        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/children/{$this->sub1->id}/status", [
                'action'    => 'pickup',
                'latitude'  => 32.890000, // بعيد عن 32.871000
                'longitude' => 13.170000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'OUT_OF_RANGE');
    }

    public function test_manual_pickup_fails_when_stop_location_is_unavailable(): void
    {
        // لا توجد إحداثيات لا في trip_stops ولا في الاشتراك ولا في عنوان الطفل،
        // إذاً يستحيل التحقق من تواجد السائق في منطقة المنزل، فيجب رفض الطلب
        // بدلاً من تمرير الصعود دون أي تحقق فعلي من الموقع.
        $this->stop1->update(['lat' => null, 'lng' => null]);
        $this->sub1->update(['pickup_lat' => null, 'pickup_lng' => null]);
        $this->child1->update(['address_id' => null]);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/children/{$this->sub1->id}/status", [
                'action'    => 'pickup',
                'latitude'  => 32.871020,
                'longitude' => 13.156010,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'STOP_LOCATION_UNAVAILABLE');

        // يجب ألا تتغير حالة المحطة أو يُسجَّل صعود دون تحقق فعلي من الموقع
        $this->assertDatabaseHas('trip_stops', [
            'id'     => $this->stop1->id,
            'status' => 'pending',
        ]);
    }

    public function test_manual_pickup_succeeds_within_geofence_and_returns_next_child_stop(): void
    {
        // إحداثيات مطابقة أو قريبة جداً (ضمن 100م) من منزل الطفل 1
        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/children/{$this->sub1->id}/status", [
                'action'    => 'pickup',
                'latitude'  => 32.871020,
                'longitude' => 13.156010,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'status',
            'message',
            'next_stop' => [
                'stop_id',
                'stop_type',
                'sequence_order',
                'name',
                'child_id',
                'trip_child_id',
                'latitude',
                'longitude',
                'status',
            ]
        ]);

        // يجب استبدال next_child بـ next_stop وتوجيه السائق للمحطة التالية (الطفل الثاني)
        $nextStop = $response->json('next_stop');
        $this->assertNotNull($nextStop);
        $this->assertEquals('home', $nextStop['stop_type']);
        $this->assertEquals($this->child2->id, $nextStop['child_id']);
        $this->assertEquals($this->sub2->id, $nextStop['trip_child_id']);
        $this->assertEquals(2, $nextStop['sequence_order']);
    }

    public function test_pickup_of_last_child_returns_school_as_next_stop(): void
    {
        // أولاً: ركوب الطفل الأول
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/children/{$this->sub1->id}/status", [
                'action'    => 'pickup',
                'latitude'  => 32.871000,
                'longitude' => 13.156000,
            ])
            ->assertStatus(200);

        // ثانياً: ركوب الطفل الثاني (آخر طفل في الرحلة)
        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/children/{$this->sub2->id}/status", [
                'action'    => 'pickup',
                'latitude'  => 32.873000,
                'longitude' => 13.158000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // المحطة القادمة يجب أن تكون المدرسة (school) وليس طفلاً آخر!
        $nextStop = $response->json('next_stop');
        $this->assertNotNull($nextStop);
        $this->assertEquals('school', $nextStop['stop_type']);
        $this->assertEquals($this->school->id, $nextStop['school_id']);
        $this->assertEquals($this->school->name, $nextStop['name']);
        $this->assertEquals(3, $nextStop['sequence_order']);
        $this->assertEquals(32.895000, $nextStop['latitude']);
        $this->assertEquals(13.185000, $nextStop['longitude']);
    }

    public function test_fetch_trip_details_contains_coordinates_in_children_and_schools(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->getJson("/api/v1/driver/trips/{$this->trip->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $data = $response->json('data');

        // فحص مصفوفة المدارس schools
        $this->assertNotEmpty($data['schools']);
        $schoolItem = $data['schools'][0];
        $this->assertArrayHasKey('latitude', $schoolItem);
        $this->assertArrayHasKey('longitude', $schoolItem);
        $this->assertEquals(32.895000, $schoolItem['latitude']);
        $this->assertEquals(13.185000, $schoolItem['longitude']);

        // فحص مصفوفة الأطفال children
        $this->assertNotEmpty($data['children']);
        foreach ($data['children'] as $childItem) {
            $this->assertArrayHasKey('latitude', $childItem);
            $this->assertArrayHasKey('longitude', $childItem);
            $this->assertIsFloat($childItem['latitude']);
            $this->assertIsFloat($childItem['longitude']);

            $this->assertArrayHasKey('home_location', $childItem);
            $this->assertArrayHasKey('latitude', $childItem['home_location']);
            $this->assertArrayHasKey('longitude', $childItem['home_location']);

            $this->assertArrayHasKey('school_location', $childItem);
            $this->assertArrayHasKey('latitude', $childItem['school_location']);
            $this->assertArrayHasKey('longitude', $childItem['school_location']);
        }
    }
}
