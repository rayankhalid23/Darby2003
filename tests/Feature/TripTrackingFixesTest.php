<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;

/**
 * اختبارات الإصلاحات على منطق تتبع الرحلات والحالات الاستثنائية:
 * 1) البدء التلقائي للرحلة عند استقبال حركة GPS (Auto-Start).
 * 2) توحيد سلوك مسار التتبع القديم (driver/update-location) مع المسار الرئيسي.
 * 3) صحة is_online بعد إضافة الطابع الزمني للكاش.
 * 4) رفض إحداثيات GPS غير صالحة عبر الفاليديشن.
 * 5) الحماية من التسابق/التكرار (Race Condition) على صعود/نزول/تخطي نفس الطفل.
 */
class TripTrackingFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق اختبار التتبع',
            'email'         => 'driver.track.' . uniqid() . '@darby.test',
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
            'current_lat'    => 32.8000,
            'current_lng'    => 13.1000,
        ]);
    }

    protected function makePendingTrip(): Trip
    {
        return Trip::create([
            'driver_id'    => $this->driver->id,
            'trip_type'    => 'Morning',
            'status'       => 'pending',
            'trip_date'    => now()->toDateString(),
            'scheduled_at' => now(),
        ]);
    }

    protected function makeInProgressTrip(): Trip
    {
        return Trip::create([
            'driver_id'    => $this->driver->id,
            'trip_type'    => 'Morning',
            'status'       => 'in_progress',
            'trip_date'    => now()->toDateString(),
            'scheduled_at' => now(),
        ]);
    }

    // =========================================================================
    // 1. البدء التلقائي للرحلة من حركة GPS
    // =========================================================================

    public function test_trip_auto_starts_when_gps_speed_exceeds_threshold(): void
    {
        $trip = $this->makePendingTrip();

        $response = $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$trip->id}/location",
            ['latitude' => 32.8010, 'longitude' => 13.1010, 'speed' => 25]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('trips', [
            'id'     => $trip->id,
            'status' => 'in_progress',
        ]);
        $this->assertNotNull($trip->fresh()->started_at);
    }

    public function test_trip_stays_pending_when_gps_speed_below_threshold(): void
    {
        $trip = $this->makePendingTrip();

        $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$trip->id}/location",
            ['latitude' => 32.8010, 'longitude' => 13.1010, 'speed' => 3]
        )->assertStatus(200);

        $this->assertDatabaseHas('trips', [
            'id'     => $trip->id,
            'status' => 'pending',
        ]);
    }

    // =========================================================================
    // 2. توحيد المسار القديم (driver/update-location) مع الخدمة الرئيسية
    // =========================================================================

    public function test_legacy_tracking_endpoint_now_writes_same_trip_tracking_row_and_updates_driver(): void
    {
        $trip = $this->makeInProgressTrip();

        $response = $this->actingAs($this->driverUser)->postJson(
            '/api/v1/driver/driver/update-location',
            [
                'trip_id'    => $trip->id,
                'driver_lat' => 32.8321,
                'driver_lng' => 13.1654,
                'heading'    => 90,
                'is_online'  => true,
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('drivers', [
            'id'          => $this->driver->id,
            'current_lat' => 32.8321,
            'current_lng' => 13.1654,
        ]);

        $this->assertDatabaseHas('trip_tracking', [
            'trip_id'   => $trip->id,
            'latitude'  => 32.8321,
            'longitude' => 13.1654,
        ]);
    }

    public function test_legacy_tracking_endpoint_rejects_invalid_coordinates(): void
    {
        $trip = $this->makeInProgressTrip();

        $response = $this->actingAs($this->driverUser)->postJson(
            '/api/v1/driver/driver/update-location',
            ['trip_id' => $trip->id, 'driver_lat' => 999, 'driver_lng' => 13.1]
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // 3. is_online يعكس فعلياً آخر نبضة GPS
    // =========================================================================

    public function test_location_update_stores_fresh_timestamp_for_online_status(): void
    {
        $trip = $this->makeInProgressTrip();

        $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$trip->id}/location",
            ['latitude' => 32.81, 'longitude' => 13.11, 'speed' => 10]
        )->assertStatus(200);

        $cached = Cache::get("driver_last_loc_{$this->driver->id}");

        $this->assertNotNull($cached);
        $this->assertArrayHasKey('timestamp', $cached);
        $this->assertEqualsWithDelta(now()->timestamp, $cached['timestamp'], 5);
    }

    // =========================================================================
    // 4. رفض إحداثيات GPS غير صالحة على المسار الرئيسي
    // =========================================================================

    public function test_main_location_endpoint_rejects_out_of_range_coordinates(): void
    {
        $trip = $this->makeInProgressTrip();

        $response = $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$trip->id}/location",
            ['latitude' => 999, 'longitude' => 13.11, 'speed' => 10]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_main_location_endpoint_rejects_missing_coordinates(): void
    {
        $trip = $this->makeInProgressTrip();

        $response = $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$trip->id}/location",
            ['speed' => 10]
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // 5. الحماية من التسابق/التكرار على إجراءات الأطفال
    // =========================================================================

    protected function makeChildWithSubscription(Trip $trip): array
    {
        $parentUser = User::create([
            'full_name'     => 'ولي أمر اختبار التسابق',
            'email'         => 'parent.race.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);
        $parent = ParentModel::create(['user_id' => $parentUser->id, 'is_trusted' => 1]);

        $school = School::create([
            'name' => 'مدرسة اختبار التسابق', 'address' => 'شارع الاختبار',
            'lat' => 32.90, 'lng' => 13.20, 'status' => 'active',
        ]);

        $child = Child::create([
            'parent_id' => $parent->id, 'school_id' => $school->id,
            'full_name' => 'طفل التسابق', 'birth_date' => '2018-05-10',
            'gender' => 'male', 'grade' => 1, 'notification_radius' => 500,
            'qr_code_token' => 'QR_RACE_' . uniqid(),
        ]);

        $subscriptionRequest = SubscriptionRequest::create([
            'parent_id'                   => $parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_ACCEPTED,
            'total_price'                 => 100,
            'discount_amount'             => 0,
            'total_amount_after_discount' => 100,
            'children_count'              => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id' => $subscriptionRequest->id, 'child_id' => $child->id,
            'subscription_type' => 'multi_day', 'trip_direction' => 'go', 'timing' => 'MORNING',
            'start_date' => now()->format('Y-m-d'), 'end_date' => now()->addMonths(1)->format('Y-m-d'),
            'working_days_count' => 22, 'distance_km' => 4.0, 'trip_price' => 100.00,
            'price_per_child' => 100.00, 'discount_amount' => 0.00, 'total_amount_after_discount' => 100.00,
            'driver_net_price' => 92.00, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sub = ActiveSubscription::create([
            'subscription_request_id' => $subscriptionRequest->id,
            'status' => 'active', 'child_id' => $child->id, 'driver_id' => $this->driver->id,
            'parent_id' => $parentUser->id,
            'pickup_lat' => 32.88, 'pickup_lng' => 13.19, 'pickup_label' => 'منزل', 'pickup_time' => '07:00:00',
            'dropoff_lat' => 32.90, 'dropoff_lng' => 13.20, 'dropoff_label' => 'مدرسة', 'dropoff_time' => '14:00:00',
        ]);

        TripStop::create([
            'trip_id' => $trip->id, 'child_id' => $child->id, 'stop_type' => 'home',
            'lat' => 32.88, 'lng' => 13.19, 'label' => 'منزل', 'sequence_order' => 1,
            'status' => 'pending',
        ]);

        return ['child' => $child, 'sub' => $sub];
    }

    public function test_duplicate_pickup_request_is_rejected_as_conflict_not_duplicated(): void
    {
        $trip = $this->makeInProgressTrip();
        $data = $this->makeChildWithSubscription($trip);

        $payload = ['action' => 'pickup', 'latitude' => 32.88, 'longitude' => 13.19];
        $url = "/api/v1/driver/trips/{$trip->id}/children/{$data['sub']->id}/status";

        $this->actingAs($this->driverUser)->postJson($url, $payload)->assertStatus(200);
        $second = $this->actingAs($this->driverUser)->postJson($url, $payload);

        $second->assertStatus(409);
        $second->assertJsonPath('error_code', 'ALREADY_PROCESSED');

        $this->assertEquals(1, DB::table('trip_events')
            ->where('trip_id', $trip->id)->where('child_id', $data['child']->id)
            ->where('action_type', 'picked_up')->count());
    }

    public function test_dropoff_before_pickup_is_rejected(): void
    {
        $trip = $this->makeInProgressTrip();
        $data = $this->makeChildWithSubscription($trip);

        // موقع صحيح عند محطة المدرسة، لعزل اختبار حماية الترتيب المنطقي (لا نزول قبل صعود) فقط
        $response = $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$trip->id}/children/{$data['sub']->id}/status",
            ['action' => 'dropoff', 'latitude' => 32.90, 'longitude' => 13.20]
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'NOT_BOARDED_YET');
    }

    public function test_duplicate_dropoff_request_is_rejected_as_conflict(): void
    {
        $trip = $this->makeInProgressTrip();
        $data = $this->makeChildWithSubscription($trip);
        $url = "/api/v1/driver/trips/{$trip->id}/children/{$data['sub']->id}/status";
        $dropoffPayload = ['action' => 'dropoff', 'latitude' => 32.90, 'longitude' => 13.20];

        $this->actingAs($this->driverUser)->postJson($url, ['action' => 'pickup', 'latitude' => 32.88, 'longitude' => 13.19])
            ->assertStatus(200);

        $this->actingAs($this->driverUser)->postJson($url, $dropoffPayload)->assertStatus(200);

        $second = $this->actingAs($this->driverUser)->postJson($url, $dropoffPayload);

        $second->assertStatus(409);
        $second->assertJsonPath('error_code', 'ALREADY_PROCESSED');
    }

    public function test_duplicate_skip_request_is_rejected_as_conflict(): void
    {
        $trip = $this->makeInProgressTrip();
        $data = $this->makeChildWithSubscription($trip);
        $url = "/api/v1/driver/trips/{$trip->id}/children/{$data['sub']->id}/status";

        $this->actingAs($this->driverUser)->postJson($url, ['action' => 'skip'])->assertStatus(200);
        $second = $this->actingAs($this->driverUser)->postJson($url, ['action' => 'skip']);

        $second->assertStatus(409);
        $second->assertJsonPath('error_code', 'ALREADY_PROCESSED');
    }

    public function test_skip_after_pickup_is_rejected(): void
    {
        $trip = $this->makeInProgressTrip();
        $data = $this->makeChildWithSubscription($trip);
        $url = "/api/v1/driver/trips/{$trip->id}/children/{$data['sub']->id}/status";

        $this->actingAs($this->driverUser)->postJson($url, ['action' => 'pickup', 'latitude' => 32.88, 'longitude' => 13.19])
            ->assertStatus(200);

        $second = $this->actingAs($this->driverUser)->postJson($url, ['action' => 'skip']);

        $second->assertStatus(409);
        $second->assertJsonPath('error_code', 'ALREADY_PROCESSED');
    }
}
