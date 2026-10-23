<?php

namespace Tests\Feature;

use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Role;
use App\Models\Shared\Route;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripStop;
use App\Models\User;
use App\Services\Trip\EmergencyBreakdownService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminEmergencyTripDetailsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * اختبار التحقق من إرسال إشعار للأدمن عند وقوع طارئ، وجلب تفاصيل الرحلة المعطلة بالكامل
     */
    public function test_admin_receives_notification_and_can_fetch_emergency_trip_details()
    {
        // 1. إنشاء حساب أدمن ومشرف عمليات
        $admin = User::factory()->create([
            'role_id'   => 1,
            'full_name' => 'مدير النظام التنفيذي',
        ]);

        $supervisor = User::factory()->create([
            'role_id'   => 2,
            'full_name' => 'مشرف العمليات والطوارئ',
        ]);

        // 2. إنشاء سائق أصلي مع مركبته
        $driverUser = User::factory()->create(['role_id' => 3, 'full_name' => 'سالم عبد الله']);
        $driver = Driver::create([
            'user_id'        => $driverUser->id,
            'national_id'    => '77' . rand(1000000000, 9999999999),
            'license_number' => 'DL-' . rand(10000, 99999),
            'license_expiry' => now()->addYears(2),
            'status'         => 'Approved',
            'shift'          => 1,
        ]);

        $vehicle = $driver->vehicles()->create([
            'driver_id'       => $driver->id,
            'brand'           => 'Toyota',
            'model'           => 'HiAce',
            'plate_number'    => 'TRIP-5544',
            'capacity_manual' => 14,
            'color'           => 'أبيض',
            'year'            => 2022,
        ]);

        // 3. إنشاء مدرسة وطفل وولي أمر
        $school = School::create([
            'name'      => 'مدرسة النصر النموذجية',
            'address'   => 'شارع النصر، طرابلس',
            'lat'       => 32.887000,
            'lng'       => 13.190000,
            'is_active' => 1,
        ]);

        $parent = User::factory()->create(['role_id' => 7, 'full_name' => 'عمر خالد']);
        $child = Child::create([
            'full_name'  => 'ياسين عمر',
            'school_id'  => $school->id,
            'parent_id'  => $parent->id,
            'birth_date' => '2015-05-05',
            'gender'     => 'male',
            'grade'      => 4,
            'is_active'  => 1,
        ]);

        // 4. إنشاء رحلة قيد التشغيل (in_progress)
        $route = Route::create([
            'driver_id'  => $driver->id,
            'vehicle_id' => $vehicle->id,
            'route_name' => 'مسار النصر الصباحي',
            'route_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'status'     => 'Active',
            'start_time' => '07:00:00',
        ]);

        $trip = Trip::create([
            'driver_id'   => $driver->id,
            'route_id'    => $route->id,
            'trip_type'   => 'Morning',
            'shift_slot'  => 'morning_go',
            'status'      => 'in_progress',
            'trip_date'   => now()->toDateString(),
        ]);

        // محطة للطفل في حالة صعود للحافلة (BOARDED)
        TripStop::create([
            'trip_id'        => $trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $child->id,
            'lat'            => 32.882000,
            'lng'            => 13.155000,
            'sequence_order' => 1,
            'status'         => TripStop::STATUS_BOARDED,
        ]);

        // 5. الإبلاغ عن تعطل الحافلة (Report Breakdown)
        $emergencyService = app(EmergencyBreakdownService::class);
        $breakdownResult = $emergencyService->reportBreakdown(
            $trip,
            32.885000,
            13.160000,
            'عطل في المحرك وارتفاع درجة الحرارة'
        );

        $dispatchId = $breakdownResult['dispatch_id'];
        $this->assertNotNull($dispatchId);

        // 6. التحقق من وصول الإشعار للأدمن في جدول notifications
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
        ]);

        // 7. تجربة استدعاء إندبوينت الأدمن لعرض كل تفاصيل الرحلة المعطلة
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson("/api/admin/trips/emergencies/{$dispatchId}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.dispatch_id', $dispatchId)
            ->assertJsonPath('data.reason', 'عطل في المحرك وارتفاع درجة الحرارة')
            ->assertJsonPath('data.original_driver.name', 'سالم عبد الله')
            ->assertJsonPath('data.original_driver.vehicle.plate_number', 'TRIP-5544')
            ->assertJsonPath('data.children_summary.boarded_in_bus_count', 1)
            ->assertJsonPath('data.children_details.0.full_name', 'ياسين عمر')
            ->assertJsonPath('data.children_details.0.parent.name', 'عمر خالد')
            ->assertJsonPath('data.children_details.0.school.name', 'مدرسة النصر النموذجية');

        // 8. تجربة الاستعلام أيضاً باستخدام معرّف الرحلة trip_id مباشرة
        $responseByTripId = $this->getJson("/api/admin/trips/emergencies/{$trip->id}");
        $responseByTripId->assertStatus(200)
            ->assertJsonPath('data.dispatch_id', $dispatchId);
    }
}
