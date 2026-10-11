<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Admin\Admin;
use App\Models\Driver\Driver;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\Trip;

class AdminRadarHybridTrackingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $driverUser;
    protected Driver $driver;
    protected RouteModel $route;
    protected Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. إعداد حساب المشرف
        $this->adminUser = User::factory()->create([
            'email'     => 'super_radar_' . uniqid() . '@darby.ly',
            'role_id'   => 1,
            'is_active' => true,
        ]);
        Admin::create(['user_id' => $this->adminUser->id, 'created_by' => 1]);

        // 2. إعداد السائق
        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق رادار تجريبي',
            'email'         => 'radar.driver.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
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

        $vehicleId = DB::table('vehicles')->insertGetId([
            'driver_id'       => $this->driver->id,
            'brand'           => 'تويوتا',
            'model'           => 'كوستر',
            'year'            => 2023,
            'color'           => 'أبيض',
            'plate_number'    => 'RAD-' . rand(1000, 9999),
            'capacity_manual' => 14,
            'status'          => 'Active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->route = RouteModel::create([
            'driver_id'  => $this->driver->id,
            'vehicle_id' => $vehicleId,
            'route_name' => 'مسار اختبار الرادار الحي',
            'route_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'start_time' => Carbon::now()->addMinutes(10)->format('H:i:s'),
            'status'     => 'Active',
        ]);

        $this->trip = Trip::create([
            'driver_id'    => $this->driver->id,
            'route_id'     => $this->route->id,
            'trip_type'    => 'Morning',
            'shift_slot'   => 'morning_go',
            'status'       => 'in_progress',
            'trip_date'    => now()->toDateString(),
            'scheduled_at' => now(),
            'started_at'   => now()->subMinutes(5),
        ]);
    }

    /**
     * اختبار: رادار الأدمن يرجع ميتا-داتا الفايربيز مع كاش التيليماتري الحديث
     */
    public function test_admin_radar_returns_telemetry_cache_and_firebase_metadata(): void
    {
        // وضع إحداثيات لحظية وسرعة واتجاه في كاش السائق
        $cacheKey = "driver_last_loc_{$this->driver->id}";
        Cache::put($cacheKey, [
            'lat'        => 32.8850,
            'lng'        => 13.1920,
            'speed'      => 48.0,
            'heading'    => 135.0,
            'timestamp'  => now()->timestamp,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        $res = $this->actingAs($this->adminUser)->getJson('/api/admin/dashboard/active-trips');

        $res->assertStatus(200);
        $res->assertJson([
            'success'  => true,
            'firebase' => [
                'collection' => 'trips_tracking',
                'strategy'   => 'realtime_with_backend_fallback',
            ],
        ]);

        $resData = $res->json('data');
        $this->assertNotEmpty($resData);

        $matchingTrip = collect($resData)->firstWhere('id', $this->trip->id);
        $this->assertNotNull($matchingTrip, 'يجب أن تظهر الرحلة النشطة الحالية برقمها في الرادار');

        $this->assertEquals($this->trip->id, $matchingTrip['trip_id']);
        $this->assertEquals(32.8850, (float) $matchingTrip['lat']);
        $this->assertEquals(13.1920, (float) $matchingTrip['lng']);
        $this->assertEquals(135.0, (float) $matchingTrip['heading']);
        $this->assertEquals('48 كم/س', $matchingTrip['speed']);
        $this->assertTrue($matchingTrip['is_online']);
        $this->assertEquals("trips_tracking/{$this->trip->id}", $matchingTrip['firestore_doc']);
    }

    /**
     * اختبار: السائق يرسل نبضة GPS فتنعكس فوراً في رادار الأدمن وفي الكاش والباك إند كـ Fallback
     */
    public function test_driver_gps_ping_updates_radar_telemetry_immediately(): void
    {
        // 1. السائق يرسل نبضة GPS جديدة
        $pingRes = $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$this->trip->id}/location",
            [
                'latitude'  => 32.8955,
                'longitude' => 13.1811,
                'speed'     => 55.5,
                'heading'   => 210.0,
            ]
        );

        $pingRes->assertStatus(200);

        // 2. فحص كاش التيليماتري
        $cached = Cache::get("driver_last_loc_{$this->driver->id}");
        $this->assertNotNull($cached);
        $this->assertEquals(32.8955, $cached['lat']);
        $this->assertEquals(13.1811, $cached['lng']);
        $this->assertEquals(55.5, $cached['speed']);
        $this->assertEquals(210.0, $cached['heading']);

        // 3. المشرف يطلب الرادار (الباك إند Fallback) فيجد نفس الإحداثيات والسرعة والاتجاه بدقة تامة
        $radarRes = $this->actingAs($this->adminUser)->getJson('/api/admin/dashboard/active-trips');
        $radarRes->assertStatus(200);

        $matchingTrip = collect($radarRes->json('data'))->firstWhere('id', $this->trip->id);
        $this->assertNotNull($matchingTrip);
        $this->assertEquals(32.8955, (float) $matchingTrip['lat']);
        $this->assertEquals(13.1811, (float) $matchingTrip['lng']);
        $this->assertEquals(210.0, (float) $matchingTrip['heading']);
        $this->assertEquals('56 كم/س', $matchingTrip['speed']);
        $this->assertTrue($matchingTrip['is_online']);
        $this->assertEquals("trips_tracking/{$this->trip->id}", $matchingTrip['firestore_doc']);
    }
}
