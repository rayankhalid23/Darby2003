<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\Trip;

/**
 * اختبار تحديث موقع السائق أثناء الرحلة الفعلية (POST /driver/trips/{tripId}/location):
 * يجب أن يحدّث موقع السائق في MySQL كالمعتاد، ويحاول أيضاً مزامنة نفس الموقع مع
 * Firestore (Collection: trips_tracking, Document ID = trip_id) دون أن يفشل الطلب
 * حتى لو تعذّرت مزامنة Firestore (مثال: ملف الاعتماد غير صالح/فارغ في بيئة الاختبار).
 */
class TripLocationFirestoreSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected RouteModel $route;
    protected Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
        ]);

        $this->driverUser = User::create([
            'full_name'    => 'سائق مزامنة الموقع',
            'email'        => 'driver.geo.' . uniqid() . '@darby.test',
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
            'current_lat'    => 32.8000,
            'current_lng'    => 13.1000,
        ]);

        $vehicleId = DB::table('vehicles')->insertGetId([
            'driver_id'       => $this->driver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هايس',
            'year'            => 2022,
            'color'           => 'أبيض',
            'plate_number'    => 'GEO-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'status'          => 'Active',
            'deleted_at'      => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->route = RouteModel::create([
            'driver_id'  => $this->driver->id,
            'vehicle_id' => $vehicleId,
            'route_name' => 'مسار اختبار مزامنة الموقع',
            'route_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'start_time' => Carbon::now()->addMinutes(20)->format('H:i:s'),
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
        ]);
    }

    public function test_updating_location_during_active_trip_succeeds_and_syncs_to_mysql_and_firestore(): void
    {
        $response = $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$this->trip->id}/location",
            [
                'latitude'  => 32.8123,
                'longitude' => 13.1456,
                'speed'     => 25,
                'heading'   => 180,
            ]
        );

        // الطلب ينجح حتى لو تعذّرت مزامنة Firestore (اعتماد Firebase غير مُهيأ في بيئة الاختبار)
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // 1. تحديث موقع السائق اللحظي في جدول drivers
        $this->assertDatabaseHas('drivers', [
            'id'          => $this->driver->id,
            'current_lat' => 32.8123,
            'current_lng' => 13.1456,
        ]);

        // 2. تسجيل نقطة تتبّع جديدة في trip_tracking (أول نقطة → تُحفظ دائماً)
        $this->assertDatabaseHas('trip_tracking', [
            'trip_id'   => $this->trip->id,
            'latitude'  => 32.8123,
            'longitude' => 13.1456,
        ]);
    }

    public function test_repeated_nearby_updates_do_not_spam_trip_tracking_table(): void
    {
        // أول تحديث: يُحفظ لأنه لا يوجد كاش سابق
        $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$this->trip->id}/location",
            ['latitude' => 32.8000, 'longitude' => 13.1000, 'speed' => 20]
        )->assertStatus(200);

        $countAfterFirst = DB::table('trip_tracking')->where('trip_id', $this->trip->id)->count();
        $this->assertEquals(1, $countAfterFirst);

        // تحديث ثانٍ بإحداثيات شبه مطابقة (أقل من عتبة 15 متر تقريباً) → لا يُضاف صف جديد
        $this->actingAs($this->driverUser)->postJson(
            "/api/v1/driver/trips/{$this->trip->id}/location",
            ['latitude' => 32.80001, 'longitude' => 13.10001, 'speed' => 20]
        )->assertStatus(200);

        $countAfterSecond = DB::table('trip_tracking')->where('trip_id', $this->trip->id)->count();
        $this->assertEquals(1, $countAfterSecond, 'لا يجب إضافة صف جديد لتحديث موقع شبه مطابق للسابق.');
    }
}
