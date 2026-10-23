<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\Route;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;

class SubscriptionTripPickupDropoffTimeTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected User $parentUser;
    protected ParentModel $parent;
    protected School $school;
    protected Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق الاختبار الزمني',
            'email'         => 'driver.time.' . uniqid() . '@test.com',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'          => $this->driverUser->id,
            'national_id'      => 'NAT' . rand(100000, 999999),
            'license_number'   => 'LIC' . rand(100000, 999999),
            'license_expiry'   => now()->addYears(2)->format('Y-m-d'),
            'status'           => 'Approved',
            'current_lat'      => 32.8872,
            'current_lng'      => 13.1932,
            'morning_go'       => 1,
            'morning_return'   => 1,
            'afternoon_go'     => 1,
            'afternoon_return' => 1,
        ]);

        DB::table('vehicles')->insert([
            'driver_id'       => $this->driver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هايس',
            'year'            => 2023,
            'color'           => 'أبيض',
            'plate_number'    => 'TIME-' . rand(1000, 9999),
            'capacity_manual' => 14,
            'status'          => 'Active',
            'deleted_at'      => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        foreach (['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'] as $slot) {
            DB::table('driver_seat_slots')->insert([
                'driver_id'      => $this->driver->id,
                'slot'           => $slot,
                'date'           => now()->addDay()->toDateString(),
                'booked'         => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر الاختبار الزمني',
            'email'         => 'parent.time.' . uniqid() . '@test.com',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $this->parent = ParentModel::find($this->parentUser->id);

        DB::table('wallets')->insert([
            'holder_type'    => \App\Models\User::class,
            'holder_id'      => $this->parentUser->id,
            'name'           => 'Default Wallet',
            'slug'           => 'default',
            'uuid'           => (string) \Illuminate\Support\Str::uuid(),
            'balance'        => 500000,
            'decimal_places' => 2,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->school = School::create([
            'name'       => 'مدرسة النور النموذجية',
            'latitude'   => 32.889000,
            'longitude'  => 13.185000,
            'lat'        => 32.889000,
            'lng'        => 13.185000,
            'address'    => 'طرابلس المركز',
            'is_active'  => 1,
        ]);

        $this->child = Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school->id,
            'full_name'           => 'ياسين أحمد سالم',
            'birth_date'          => '2016-05-10',
            'gender'              => 'male',
            'grade'               => '4',
            'preferred_time_slot' => 'morning',
            'pickup_time'         => null,
            'dropoff_time'        => null,
            'is_active'           => 1,
        ]);
    }

    /**
     * اختبار: حفظ وقت الاستلام والتسليم عند إرسال طلب اشتراك جديد
     */
    public function test_store_subscription_saves_pickup_and_dropoff_times(): void
    {
        $targetDate = now()->next(\Carbon\Carbon::SUNDAY)->toDateString();
        $payload = [
            'driver_id'         => $this->driver->id,
            'subscription_type' => 'single_day',
            'trip_direction'    => 'both',
            'timing'            => 'MORNING',
            'start_date'        => $targetDate,
            'end_date'          => $targetDate,
            'pickup_time'       => '07:25',
            'dropoff_time'      => '13:45',
            'home_address'      => [
                'lat'   => 32.875000,
                'lng'   => 13.168000,
                'label' => 'منزل العائلة',
            ],
            'children' => [
                [
                    'child_id'     => $this->child->id,
                    'pickup_time'  => '07:25',
                    'dropoff_time' => '13:45',
                ]
            ],
        ];

        $response = $this->actingAs($this->parentUser)
            ->postJson('/api/parent/requests', $payload);

        $response->assertStatus(201);
        $reqId = $response->json('data.id');

        // التحقق من قاعدة البيانات: جدول requests
        $this->assertDatabaseHas('requests', [
            'id'           => $reqId,
            'pickup_time'  => '07:25:00',
            'dropoff_time' => '13:45:00',
        ]);

        // التحقق من تحديث جدول children
        $this->assertDatabaseHas('children', [
            'id'           => $this->child->id,
            'pickup_time'  => '07:25:00',
            'dropoff_time' => '13:45:00',
        ]);

        // قبول السائق للاشتراك
        $acceptResponse = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/subscription-requests/{$reqId}/status", [
                'status' => 'accepted',
            ]);
        $acceptResponse->assertStatus(200);

        // التحقق من جدول active_subscriptions
        $this->assertDatabaseHas('active_subscriptions', [
            'subscription_request_id' => $reqId,
            'pickup_time'             => '07:25:00',
            'dropoff_time'            => '13:45:00',
        ]);
    }

    /**
     * اختبار: تفاصيل الرحلة للسائق DriverTripController@show
     * ترجع pickup_time و dropoff_time للطفل وليست null
     */
    public function test_driver_trip_show_returns_pickup_and_dropoff_times(): void
    {
        $req = SubscriptionRequest::create([
            'parent_id'         => $this->parent->id,
            'driver_id'         => $this->driver->id,
            'status'            => 'accepted',
            'subscription_type' => 'monthly',
            'trip_direction'    => 'both',
            'pickup_time'       => '07:15:00',
            'dropoff_time'      => '14:10:00',
        ]);

        $route = Route::create([
            'subscription_request_id' => $req->id,
            'driver_id'               => $this->driver->id,
            'route_name'              => 'مسار الصباح',
            'route_type'              => 'Morning',
            'shift_slot'              => 'morning_go',
            'start_time'              => '07:15:00',
            'status'                  => 'Active',
        ]);

        $activeSub = ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'request_child_id'        => null,
            'route_id'                => $route->id,
            'pickup_lat'              => 32.875000,
            'pickup_lng'              => 13.168000,
            'pickup_label'            => 'المنزل',
            'dropoff_lat'             => 32.889000,
            'dropoff_lng'             => 13.185000,
            'dropoff_label'           => 'المدرسة',
            'pickup_time'             => '07:15:00',
            'dropoff_time'            => '14:10:00',
            'status'                  => 'active',
        ]);

        // ربط الطفل بالطلب
        DB::table('request_children')->insert([
            'request_id' => $req->id,
            'child_id'   => $this->child->id,
        ]);
        $activeSub->update(['request_child_id' => DB::getPdo()->lastInsertId()]);

        $trip = Trip::create([
            'driver_id'            => $this->driver->id,
            'route_id'             => $route->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'pending',
            'scheduled_start_time' => '07:15:00',
            'trip_date'            => now()->toDateString(),
        ]);

        TripStop::create([
            'trip_id'        => $trip->id,
            'stop_type'      => 'home',
            'child_id'       => $this->child->id,
            'lat'            => 32.875000,
            'lng'            => 13.168000,
            'label'          => 'المنزل',
            'sequence_order' => 1,
            'status'         => 'pending',
        ]);

        $response = $this->actingAs($this->driverUser)
            ->getJson("/api/driver/trips/{$trip->id}");

        $response->assertStatus(200);
        $children = $response->json('data.children');
        $this->assertNotEmpty($children);
        $firstChild = $children[0];

        $this->assertNotNull($firstChild['pickup_time'], 'pickup_time should not be null');
        $this->assertNotNull($firstChild['dropoff_time'], 'dropoff_time should not be null');
        $this->assertEquals('07:15', $firstChild['pickup_time']);
        $this->assertEquals('14:10', $firstChild['dropoff_time']);
    }

    /**
     * اختبار: تفاصيل الرحلة لولي الأمر ParentTripService@getTripDetails
     * ترجع pickup_time و dropoff_time للأطفال وليست null
     */
    public function test_parent_trip_details_returns_pickup_and_dropoff_times(): void
    {
        $req = SubscriptionRequest::create([
            'parent_id'         => $this->parent->id,
            'driver_id'         => $this->driver->id,
            'status'            => 'accepted',
            'subscription_type' => 'monthly',
            'trip_direction'    => 'both',
            'pickup_time'       => '07:30:00',
            'dropoff_time'      => '13:30:00',
        ]);

        $route = Route::create([
            'subscription_request_id' => $req->id,
            'driver_id'               => $this->driver->id,
            'route_name'              => 'مسار الصباح',
            'route_type'              => 'Morning',
            'shift_slot'              => 'morning_go',
            'start_time'              => '07:30:00',
            'status'                  => 'Active',
        ]);

        $activeSub = ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'request_child_id'        => null,
            'route_id'                => $route->id,
            'pickup_lat'              => 32.875000,
            'pickup_lng'              => 13.168000,
            'pickup_label'            => 'المنزل',
            'dropoff_lat'             => 32.889000,
            'dropoff_lng'             => 13.185000,
            'dropoff_label'           => 'المدرسة',
            'pickup_time'             => '07:30:00',
            'dropoff_time'            => '13:30:00',
            'status'                  => 'active',
        ]);

        DB::table('request_children')->insert([
            'request_id' => $req->id,
            'child_id'   => $this->child->id,
        ]);
        $activeSub->update(['request_child_id' => DB::getPdo()->lastInsertId()]);

        $trip = Trip::create([
            'driver_id'            => $this->driver->id,
            'route_id'             => $route->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'scheduled_start_time' => '07:30:00',
            'trip_date'            => now()->toDateString(),
        ]);

        TripStop::create([
            'trip_id'        => $trip->id,
            'stop_type'      => 'home',
            'child_id'       => $this->child->id,
            'lat'            => 32.875000,
            'lng'            => 13.168000,
            'label'          => 'المنزل',
            'sequence_order' => 1,
            'status'         => 'pending',
        ]);

        // فحص تفاصيل الرحلة لولي الأمر
        $response = $this->actingAs($this->parentUser)
            ->getJson("/api/parent/trips/{$trip->id}");

        $response->assertStatus(200);
        $children = $response->json('data.children');
        $this->assertNotEmpty($children);
        $firstChild = $children[0];

        $this->assertNotNull($firstChild['pickup_time'], 'pickup_time should not be null');
        $this->assertNotNull($firstChild['dropoff_time'], 'dropoff_time should not be null');
        $this->assertEquals('07:30 AM', $firstChild['pickup_time']);
        $this->assertEquals('01:30 PM', $firstChild['dropoff_time']);

        // فحص أيضاً الرحلات النشطة لولي الأمر /api/parent/trips/active
        $activeResponse = $this->actingAs($this->parentUser)
            ->getJson("/api/parent/trips/active");
        $activeResponse->assertStatus(200);
        $activeData = $activeResponse->json('data');
        $this->assertNotEmpty($activeData);
        $activeChild = $activeData[0]['children'][0];
        $this->assertEquals('07:30 AM', $activeChild['pickup_time']);
        $this->assertEquals('01:30 PM', $activeChild['dropoff_time']);
    }

    /**
     * اختبار: تفاصيل طلب الاشتراك في شاشة السائق tripDetails
     * ترجع pickup_time و dropoff_time وليست null
     */
    public function test_driver_subscription_trip_details_returns_times(): void
    {
        $req = SubscriptionRequest::create([
            'parent_id'         => $this->parent->id,
            'driver_id'         => $this->driver->id,
            'status'            => 'pending',
            'subscription_type' => 'monthly',
            'trip_direction'    => 'both',
            'pickup_time'       => '07:45:00',
            'dropoff_time'      => '14:15:00',
        ]);

        DB::table('request_children')->insert([
            'request_id' => $req->id,
            'child_id'   => $this->child->id,
        ]);

        $response = $this->actingAs($this->driverUser)
            ->getJson("/api/driver/subscription-requests/{$req->id}/trip-details");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('07:45:00', $data['pickup_time']);
        $this->assertEquals('14:15:00', $data['dropoff_time']);
        $this->assertEquals('07:45:00', $data['children'][0]['pickup_time']);
        $this->assertEquals('14:15:00', $data['children'][0]['dropoff_time']);
    }
}
