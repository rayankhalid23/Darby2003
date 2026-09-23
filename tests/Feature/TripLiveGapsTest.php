<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\DailyTripGenerationService;

/**
 * ط§ط®طھط¨ط§ط± ط¥ط؛ظ„ط§ظ‚ ط§ظ„ظپط¬ظˆط§طھ ط§ظ„ظ…ظڈط¨ظ„ظژظ‘ط؛ ط¹ظ†ظ‡ط§ ظپظٹ ط¯ظ„ظٹظ„ ط±ط¨ط· ط§ظ„ظپط±ظˆظ†طھ:
 * 1) GET /trips/{tripId}/stops ظˆ ط¯ظ‚ط© ط§ظ„ط­ط§ظ„ط© ظپظٹ show()/live()
 * 2) POST /trips/register-absence + ط£ط«ط±ظ‡ط§ ط§ظ„ظپط¹ظ„ظٹ ط¹ظ„ظ‰ طھظˆظ„ظٹط¯ ط§ظ„ط±ط­ظ„ط§طھ
 * 3) ط§ظ„ط¥ط¨ظ„ط§ط؛ ط¹ظ† ط¹ط·ظ„ ظˆط§ط³طھط¦ظ†ط§ظپ ط§ظ„ط±ط­ظ„ط©
 */
class TripLiveGapsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected RouteModel $route;
    protected Child $child;
    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'ط³ط§ط¦ظ‚'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ظˆظ„ظٹ ط£ظ…ط±'],
        ]);

        $this->driverUser = User::create([
            'full_name'    => 'ط³ط§ط¦ظ‚ ظپط¬ظˆط§طھ ط§ظ„ط±ط­ظ„ط§طھ',
            'email'        => 'driver.gaps.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 2,
            'is_active'    => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);

        $vehicleId = DB::table('vehicles')->insertGetId([
            'driver_id'       => $this->driver->id,
            'brand'           => 'طھظˆظٹظˆطھط§',
            'model'           => 'ظ‡ط§ظٹط³',
            'year'            => 2022,
            'color'           => 'ط£ط¨ظٹط¶',
            'plate_number'    => 'GAP-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'capacity_ai'     => 10,
            'status'          => 'Active',
            'deleted_at'      => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $parentUser = User::create([
            'full_name'    => 'ظˆظ„ظٹ ط£ظ…ط± ظپط¬ظˆط§طھ ط§ظ„ط±ط­ظ„ط§طھ',
            'email'        => 'parent.gaps.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 3,
            'is_active'    => 1,
        ]);
        $parent = ParentModel::create(['user_id' => $parentUser->id, 'is_trusted' => 1]);

        $this->school = School::create([
            'name' => 'ظ…ط¯ط±ط³ط© ظپط¬ظˆط§طھ ط§ظ„ط±ط­ظ„ط§طھ', 'address' => 'ط´ط§ط±ط¹ ط§ظ„ط§ط®طھط¨ط§ط±',
            'lat' => 32.90, 'lng' => 13.20, 'status' => 'active',
        ]);

        $this->child = Child::create([
            'parent_id' => $parent->id, 'school_id' => $this->school->id,
            'full_name' => 'ط·ظپظ„ ظپط¬ظˆط§طھ ط§ظ„ط±ط­ظ„ط§طھ', 'birth_date' => '2018-05-10',
            'gender' => 'male', 'grade' => 1, 'notification_radius' => 500,
        ]);

        $this->route = RouteModel::create([
            'driver_id'  => $this->driver->id,
            'vehicle_id' => $vehicleId,
            'route_name' => 'ظ…ط³ط§ط± ط§ط®طھط¨ط§ط± ط§ظ„ظپط¬ظˆط§طھ',
            'route_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'start_time' => Carbon::now()->addMinutes(20)->format('H:i:s'),
            'status'     => 'Active',
        ]);

        RouteStop::create(['route_id' => $this->route->id, 'stop_type' => 'home', 'child_id' => $this->child->id, 'lat' => 32.881, 'lng' => 13.191, 'label' => 'ط§ظ„ظ…ظ†ط²ظ„', 'sequence_order' => 1]);
        RouteStop::create(['route_id' => $this->route->id, 'stop_type' => 'school', 'school_id' => $this->school->id, 'lat' => 32.90, 'lng' => 13.20, 'label' => 'ط§ظ„ظ…ط¯ط±ط³ط©', 'sequence_order' => 2]);
    }

    // =========================================================
    // ظپط¬ظˆط© 1+4: GET /trips/{tripId}/stops ظˆط¯ظ‚ط© ط§ظ„ط­ط§ظ„ط© ظپظٹ show()
    // =========================================================

    public function test_trip_stops_endpoint_returns_ordered_stops_with_status(): void
    {
        $today = $this->actingAs($this->driverUser)->getJson('/api/v1/driver/trips/today');
        $tripId = $today->json('data.0.trip_id');

        $response = $this->actingAs($this->driverUser)->getJson("/api/v1/driver/trips/{$tripId}/stops");

        $response->assertStatus(200);
        $response->assertJsonPath('data.trip_id', $tripId);
        $stops = $response->json('data.stops');
        $this->assertCount(2, $stops);
        $this->assertEquals('home', $stops[0]['stop_type']);
        $this->assertEquals('pending', $stops[0]['status']);
        $this->assertEquals($this->child->id, $stops[0]['child_id']);
        $this->assertEquals('school', $stops[1]['stop_type']);
    }

    public function test_show_and_live_reflect_precise_trip_stop_status_after_boarding(): void
    {
        $parentRecord = ParentModel::where('id', $this->child->parent_id)->first();

        $subscriptionRequest = \App\Models\Shared\SubscriptionRequest::create([
            'parent_id' => $this->child->parent_id, 'driver_id' => $this->driver->id, 'school_id' => $this->school->id,
        ]);
        // جدول contracts حُذف (مهاجرة 2026_08_24_000001) وصار الاشتراك النشط
        // يرتبط بطلب الاشتراك مباشرة عبر subscription_request_id.
        $sub = \App\Models\Shared\ActiveSubscription::create([
            'subscription_request_id' => $subscriptionRequest->id, 'status' => 'active', 'child_id' => $this->child->id,
            'driver_id' => $this->driver->id, 'route_id' => $this->route->id, 'parent_id' => $parentRecord->user_id,
            'pickup_lat' => 32.881, 'pickup_lng' => 13.191, 'pickup_label' => 'ط§ظ„ظ…ظ†ط²ظ„', 'pickup_time' => '07:00:00',
            'dropoff_lat' => 32.90, 'dropoff_lng' => 13.20, 'dropoff_label' => 'ط§ظ„ظ…ط¯ط±ط³ط©', 'dropoff_time' => '14:00:00',
        ]);

        $today = $this->actingAs($this->driverUser)->getJson('/api/v1/driver/trips/today');
        $tripId = $today->json('data.0.trip_id');

        // ظ‚ط¨ظ„ ط§ظ„طµط¹ظˆط¯: pending
        $show = $this->actingAs($this->driverUser)->getJson("/api/v1/driver/trips/{$tripId}");
        $show->assertJsonPath('data.children.0.status', 'pending');
        $show->assertJsonPath('data.children.0.pickup_status', 'pending');

        // لا يجوز تسجيل أي حالة لطفل قبل بدء الرحلة فعلياً
        $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$tripId}/start",
            ['latitude' => 32.881, 'longitude' => 13.191]
        )->assertStatus(200);

        // تأكيد الصعود يدوياً (زر) — يتطلب موقع GPS ضمن نطاق المحطة
        $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$tripId}/pickup",
            ['trip_child_id' => $sub->id, 'latitude' => 32.881, 'longitude' => 13.191]
        )->assertStatus(200);

        // ط¨ط¹ط¯ ط§ظ„طµط¹ظˆط¯: boarded (ظˆظ„ظٹط³ ط§ظ„ظ‚ظٹظ…ط© ط§ظ„ظ‚ط¯ظٹظ…ط© picked_up)
        $showAfter = $this->actingAs($this->driverUser)->getJson("/api/v1/driver/trips/{$tripId}");
        $showAfter->assertJsonPath('data.children.0.status', 'boarded');
        $showAfter->assertJsonPath('data.children.0.pickup_status', 'picked_up');

        $live = $this->actingAs($this->driverUser)->getJson("/api/v1/driver/trips/{$tripId}/live");
        $live->assertStatus(200);
        $this->assertEquals(1, $live->json('data.progress.total'));
        $this->assertEquals(0, $live->json('data.progress.completed'));
    }

    // =========================================================
    // ظپط¬ظˆط© 2: طھط³ط¬ظٹظ„ ط؛ظٹط§ط¨ ط§ظ„ط³ط§ط¦ظ‚ + ط£ط«ط±ظ‡ ط¹ظ„ظ‰ طھظˆظ„ظٹط¯ ط§ظ„ط±ط­ظ„ط§طھ
    // =========================================================

    public function test_driver_can_register_absence(): void
    {
        $tomorrow = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($this->driverUser)
            ->postJson('/api/v1/driver/trips/register-absence', ['dates' => [$tomorrow]]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('driver_absences', [
            'driver_id'     => $this->driver->id,
            'absence_date'  => $tomorrow,
        ]);
    }

    public function test_absent_driver_route_is_not_generated(): void
    {
        $today = Carbon::today()->toDateString();

        DB::table('driver_absences')->insert([
            'driver_id' => $this->driver->id, 'absence_date' => $today,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(DailyTripGenerationService::class);
        $trip = $service->generateForRoute($this->route);

        $this->assertNull($trip);
        $this->assertDatabaseMissing('trips', ['route_id' => $this->route->id, 'trip_date' => $today]);
    }

    public function test_register_absence_rejects_past_dates(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->postJson('/api/v1/driver/trips/register-absence', ['dates' => ['2020-01-01']]);

        $response->assertStatus(422);
    }

    // =========================================================
    // ظپط¬ظˆط© 3: ط§ظ„ط¥ط¨ظ„ط§ط؛ ط¹ظ† ط¹ط·ظ„ ظˆط§ط³طھط¦ظ†ط§ظپ ط§ظ„ط±ط­ظ„ط©
    // =========================================================

    public function test_driver_can_report_breakdown_on_in_progress_trip(): void
    {
        $trip = Trip::create([
            'driver_id' => $this->driver->id, 'route_id' => $this->route->id,
            'trip_type' => 'Morning', 'shift_slot' => 'morning_go', 'status' => 'in_progress',
            'trip_date' => now()->toDateString(), 'scheduled_at' => now(),
        ]);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$trip->id}/report-breakdown", ['reason' => 'ط¥ط·ط§ط± ظ…ط«ظ‚ظˆط¨']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('trips', [
            'id' => $trip->id, 'status' => 'suspended_breakdown', 'suspension_reason' => 'ط¥ط·ط§ط± ظ…ط«ظ‚ظˆط¨',
        ]);
    }

    public function test_cannot_report_breakdown_on_pending_trip(): void
    {
        $trip = Trip::create([
            'driver_id' => $this->driver->id, 'route_id' => $this->route->id,
            'trip_type' => 'Morning', 'shift_slot' => 'morning_go', 'status' => 'pending',
            'trip_date' => now()->toDateString(), 'scheduled_at' => now(),
        ]);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$trip->id}/report-breakdown", []);

        $response->assertStatus(422);
    }

    public function test_driver_can_resume_suspended_trip(): void
    {
        $trip = Trip::create([
            'driver_id' => $this->driver->id, 'route_id' => $this->route->id,
            'trip_type' => 'Morning', 'shift_slot' => 'morning_go', 'status' => 'suspended_breakdown',
            'suspension_reason' => 'ط¹ط·ظ„ ظ…ط¤ظ‚طھ',
            'trip_date' => now()->toDateString(), 'scheduled_at' => now(),
        ]);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$trip->id}/resume");

        $response->assertStatus(200);
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'status' => 'in_progress', 'suspension_reason' => null]);
    }

    public function test_cannot_resume_trip_that_is_not_suspended(): void
    {
        $trip = Trip::create([
            'driver_id' => $this->driver->id, 'route_id' => $this->route->id,
            'trip_type' => 'Morning', 'shift_slot' => 'morning_go', 'status' => 'in_progress',
            'trip_date' => now()->toDateString(), 'scheduled_at' => now(),
        ]);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/trips/{$trip->id}/resume");

        $response->assertStatus(422);
    }
}
