<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Driver\DriverAbsence;
use App\Models\Driver\Vehicle;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\ChildLogistics;
use App\Models\Parent\Address;
use App\Models\Parent\School;
use App\Models\Shared\Zone;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Municipality;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\PlatformFinance;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripEvent;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\AbsenceLog;

class ComprehensiveTripLifecycleScenarioTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $driverUser;
    protected Driver $driver;
    protected Vehicle $vehicle;
    protected School $school;
    protected Address $homeAddress;
    protected Zone $zone;
    protected PricingSetting $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        $this->pricing = PricingSetting::firstOrCreate([], [
            'price_per_km_ac'              => 2.50,
            'price_per_km_non_ac'          => 2.00,
            'discount_one_child'           => 0.00,
            'discount_two_children'        => 10.00,
            'discount_three_plus_children' => 15.00,
            'platform_commission_rate'     => 8.00,
        ]);

        $municipality = Municipality::firstOrCreate(['name' => 'طرابلس الكبرى']);
        $subMuni = SubMunicipality::firstOrCreate(['municipality_id' => $municipality->id, 'name' => 'سوق الجمعة']);
        $this->zone = Zone::firstOrCreate(['sub_municipality_id' => $subMuni->id, 'name' => 'طريق الشط']);

        $this->school = School::create([
            'name'    => 'مدرسة الشط الابتدائية',
            'zone_id' => $this->zone->id,
            'lat'     => 32.89000000,
            'lng'     => 13.19000000,
            'address' => 'طرابلس - طريق الشط',
            'status'  => 'active',
        ]);

        $this->parentUser = User::create([
            'full_name'     => 'سالم علي التاورغي',
            'email'         => 'parent.trip.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $this->parent = ParentModel::create([
            'user_id'    => $this->parentUser->id,
            'is_trusted' => 1,
        ]);
        $this->parent->deposit(50000);

        $this->homeAddress = Address::create([
            'parent_id' => $this->parentUser->id,
            'label'     => 'منزل العائلة بطريق الشط',
            'lat'       => 32.88000000,
            'lng'       => 13.18000000,
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'كابتن محمود الورفلي',
            'email'         => 'driver.trip.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'           => $this->driverUser->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->toDateString(),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
            'morning_go'        => 1,
            'morning_return'    => 1,
            'current_lat'       => 32.87500000,
            'current_lng'       => 13.17500000,
        ]);

        $this->vehicle = Vehicle::create([
            'driver_id'       => $this->driver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هاي آس',
            'year'            => 2023,
            'color'           => 'فضي',
            'type'            => 'Van',
            'plate_number'    => 'LY-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'has_ac'          => 1,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);

        DB::table('driver_zone')->insertOrIgnore([
            'driver_id' => $this->driver->id,
            'zone_id'   => $this->zone->id,
        ]);

        foreach (['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'] as $slot) {
            DriverSeatSlot::create([
                'driver_id'      => $this->driver->id,
                'slot'           => $slot,
                'total_seats'    => 10,
                'reserved_seats' => 0,
            ]);
        }
    }

    protected function createChildWithActiveSubscription(string $name, string $qrToken = 'QR_TOKEN_123'): array
    {
        $child = Child::create([
            'parent_id'       => $this->parent->id,
            'school_id'       => $this->school->id,
            'address_id'      => $this->homeAddress->id,
            'full_name'       => $name,
            'birth_date'      => '2016-03-20',
            'gender'          => 'male',
            'grade'           => 3,
            'qr_code_token'   => $qrToken,
        ]);

        $start = Carbon::today()->format('Y-m-d');
        $end   = Carbon::today()->addDays(25)->format('Y-m-d');

        $req = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => 'accepted',
            'total_price'                 => 180.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 180.00,
        ]);

        $req->children()->attach($child->id, [
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'both',
            'timing'                      => 'MORNING',
            'start_date'                  => $start,
            'end_date'                    => $end,
            'working_days_count'          => 20,
            'distance_km'                 => 4.5,
            'trip_price'                  => 180.00,
            'price_per_child'             => 180.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 180.00,
            'driver_net_price'            => 165.60,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        // إنشاء أو جلب المسار الرئيسي
        $route = Route::firstOrCreate(
            ['driver_id' => $this->driver->id, 'shift_slot' => 'morning_go'],
            [
                'vehicle_id'              => $this->vehicle->id,
                'subscription_request_id' => $req->id,
                'route_name'              => 'مسار صباحي - ذهاب',
                'route_type'              => 'Morning',
                'start_time'              => '07:00:00',
                'status'                  => 'Active',
            ]
        );

        $activeSub = ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'route_id'                => $route->id,
            'child_id'                => $child->id,
            'driver_id'               => $this->driver->id,
            'parent_id'               => $this->parentUser->id,
            'status'                  => 'active',
            'pickup_lat'              => 32.88000000,
            'pickup_lng'              => 13.18000000,
            'pickup_label'            => 'منزل الطفل',
            'dropoff_lat'             => 32.89000000,
            'dropoff_lng'             => 13.19000000,
            'dropoff_label'           => 'المدرسة',
            'pickup_time'             => '07:00:00',
            'dropoff_time'            => '14:00:00',
        ]);

        // مزامنة محطات المسار RouteStop
        RouteStop::firstOrCreate(
            ['route_id' => $route->id, 'stop_type' => RouteStop::TYPE_HOME, 'child_id' => $child->id],
            ['lat' => 32.88000000, 'lng' => 13.18000000, 'label' => 'منزل ' . $name, 'sequence_order' => 1]
        );

        RouteStop::firstOrCreate(
            ['route_id' => $route->id, 'stop_type' => RouteStop::TYPE_SCHOOL, 'school_id' => $this->school->id],
            ['lat' => 32.89000000, 'lng' => 13.19000000, 'label' => $this->school->name, 'sequence_order' => 2]
        );

        return ['child' => $child, 'sub' => $activeSub, 'route' => $route, 'req' => $req];
    }

    // =========================================================================
    // 1. اختبار توليد الرحلة اليومية واستبعاد الغياب المسبق
    // =========================================================================

    public function test_01_daily_trip_generation_excludes_pre_absent_child(): void
    {
        $child1Data = $this->createChildWithActiveSubscription('أحمد التاورغي', 'QR_AHMED');
        $child2Data = $this->createChildWithActiveSubscription('محمود التاورغي', 'QR_MAHMOUD');

        $today = Carbon::today()->toDateString();

        // تسجيل غياب مسبق للطفل الأول في رحلة الذهاب
        AbsenceLog::create([
            'child_id'     => $child1Data['child']->id,
            'absence_date' => $today,
            'absence_type' => 'pickup',
        ]);

        // توليد رحلة اليوم
        $service = app(\App\Services\Trip\DailyTripGenerationService::class);
        $trip = $service->generateForRoute($child1Data['route']);

        $this->assertNotNull($trip);
        $this->assertEquals('pending', $trip->status);

        // محطة الطفل الأول الغائب يجب أن تكون absent_pre مع ترتيب 0
        $stop1 = TripStop::where('trip_id', $trip->id)->where('child_id', $child1Data['child']->id)->first();
        $this->assertNotNull($stop1);
        $this->assertEquals(TripStop::STATUS_ABSENT_PRE, $stop1->status);
        $this->assertEquals(0, $stop1->sequence_order);

        // محطة الطفل الثاني الحاضر يجب أن تكون pending مع ترتيب 1
        $stop2 = TripStop::where('trip_id', $trip->id)->where('child_id', $child2Data['child']->id)->first();
        $this->assertNotNull($stop2);
        $this->assertEquals(TripStop::STATUS_PENDING, $stop2->status);
        $this->assertEquals(1, $stop2->sequence_order);
    }

    // =========================================================================
    // 2. اختبار بدء الرحلة للسائق ومنع البدء في حال تسجيل غياب السائق
    // =========================================================================

    public function test_02_driver_cannot_start_trip_if_marked_absent_today(): void
    {
        $childData = $this->createChildWithActiveSubscription('طارق التاورغي');
        $today = Carbon::today()->toDateString();

        // تسجيل غياب السائق لليوم
        DriverAbsence::create([
            'driver_id'    => $this->driver->id,
            'absence_date' => $today,
        ]);

        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson('/api/driver/trips/start', [
                'trip_type' => 'Morning',
                'latitude'  => 32.87500000,
                'longitude' => 13.17500000,
            ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'error');
    }

    public function test_03_driver_starts_trip_successfully_updates_status_and_live_etas(): void
    {
        $childData = $this->createChildWithActiveSubscription('كريم التاورغي');

        // تجهيز رحلة اليوم
        $dailyService = app(\App\Services\Trip\DailyTripGenerationService::class);
        $trip = $dailyService->generateForRoute($childData['route']);

        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/start", [
                'latitude'  => 32.87500000,
                'longitude' => 13.17500000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('trips', [
            'id'     => $trip->id,
            'status' => 'in_progress',
        ]);
    }

    // =========================================================================
    // 3. اختبار تأكيد صعود الطفل يدوياً (زر) وبالـ Geofence
    // =========================================================================

    public function test_04_driver_confirms_pickup_manually(): void
    {
        $childData = $this->createChildWithActiveSubscription('أيمن التاورغي');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/pickup", [
                'trip_child_id'       => $childData['sub']->id,
                'latitude'            => 32.88000000,
                'longitude'           => 13.18000000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // التحقق من تسجيل حدث الصعود في trip_events
        $this->assertDatabaseHas('trip_events', [
            'trip_id'     => $trip->id,
            'child_id'    => $childData['child']->id,
            'action_type' => 'picked_up',
        ]);

        // التحقق من تحديث حالة المحطة في trip_stops إلى boarded
        $this->assertDatabaseHas('trip_stops', [
            'trip_id'  => $trip->id,
            'child_id' => $childData['child']->id,
            'status'   => TripStop::STATUS_BOARDED,
        ]);
    }

    public function test_05_manual_pickup_fails_outside_geofence_radius(): void
    {
        $childData = $this->createChildWithActiveSubscription('نبيل التاورغي');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        // إرسال موقع بعيد جداً عن منزل الطفل (أكثر من 150 متر)
        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/pickup", [
                'trip_child_id'       => $childData['sub']->id,
                'latitude'            => 32.95000000, // بعيد جداً
                'longitude'           => 13.25000000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'OUT_OF_RANGE');
    }

    // =========================================================================
    // 4. اختبار تأكيد النزول (Dropoff)
    // =========================================================================

    public function test_06_driver_confirms_dropoff_at_school(): void
    {
        $childData = $this->createChildWithActiveSubscription('وسام التاورغي');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        // أولاً تأكيد الصعود
        TripStop::where('trip_id', $trip->id)->where('child_id', $childData['child']->id)
            ->update(['status' => TripStop::STATUS_BOARDED]);

        // ثانياً تأكيد النزول عند موقع المدرسة
        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/dropoff", [
                'trip_child_id'       => $childData['sub']->id,
                'latitude'            => 32.89000000,
                'longitude'           => 13.19000000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('trip_stops', [
            'trip_id'  => $trip->id,
            'child_id' => $childData['child']->id,
            'status'   => TripStop::STATUS_DROPPED_OFF_SCHOOL,
        ]);
    }

    // =========================================================================
    // 5. صمام أمان الأطفال (Zero Forgotten Children Guard)
    // =========================================================================

    public function test_07_complete_trip_fails_if_child_is_still_boarded_on_bus(): void
    {
        $childData = $this->createChildWithActiveSubscription('فراس التاورغي', 'QR_FIRAS');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        // الطفل صعد الحافلة ولم يتم تأكيد نزوله بعد (boarded)
        TripStop::where('trip_id', $trip->id)->where('child_id', $childData['child']->id)
            ->update(['status' => TripStop::STATUS_BOARDED]);

        // محاولة إنهاء الرحلة من قِبل السائق
        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/complete");

        // يجب أن تفشل لمنع نسيان الطفل في الحافلة
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'FORGOTTEN_CHILDREN_ON_BUS');
    }

    // =========================================================================
    // 6. اكتمال الرحلة بنجاح وتسوية الأمانات المالية
    // =========================================================================

    public function test_08_complete_trip_succeeds_when_all_children_delivered_and_settles_finances(): void
    {
        $childData = $this->createChildWithActiveSubscription('إياد التاورغي', 'QR_EYAD');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        // تأكيد النزول بالمدرسة
        TripStop::where('trip_id', $trip->id)->where('child_id', $childData['child']->id)
            ->update(['status' => TripStop::STATUS_DROPPED_OFF_SCHOOL]);

        TripEvent::create([
            'trip_id'         => $trip->id,
            'child_id'        => $childData['child']->id,
            'subscription_id' => $childData['sub']->id,
            'action_type'     => 'dropped_off',
            'trip_type'       => 'ذهاب',
            'scanned_at'      => now(),
            'location_lat'    => 32.89,
            'location_lng'    => 13.19,
            'trip_cost'       => 0,
        ]);

        // محاكاة حجز مالي في الأمانات للرحلة
        $vault = MasterEscrowVault::getVault();
        $vault->increment('parents_escrow_pool', 18000);

        $finance = PlatformFinance::create([
            'subscription_request_id'    => $childData['req']->id,
            'parent_id'                  => $this->parent->id,
            'driver_id'                  => $this->driver->id,
            'total_amount'               => 180.00,
            'platform_commission_rate'   => 8.00,
            'platform_commission_amount' => 14.40,
            'driver_net_amount'          => 165.60,
            'status'                     => PlatformFinance::STATUS_HELD,
            'held_at'                    => now(),
        ]);

        $initialDriverBalance = (int) $this->driver->balance;

        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/complete");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // التحقق من تحديث حالة الرحلة
        $this->assertDatabaseHas('trips', [
            'id'     => $trip->id,
            'status' => 'completed',
        ]);

        // التحقق من تسوية القيد المالي
        $this->assertDatabaseHas('platform_finances', [
            'id'      => $finance->id,
            'trip_id' => $trip->id,
            'status'  => PlatformFinance::STATUS_COMPLETED,
        ]);

        // التحقق من إيداع المستحقات بمحفظة السائق (165.60 د.ل = 16560 سنت)
        $newDriverBalance = (int) $this->driver->fresh()->balance;
        $this->assertEquals($initialDriverBalance + 16560, $newDriverBalance);
    }

    // =========================================================================
    // 7. اختبارات تتبع الرحلة من قِبل ولي الأمر
    // =========================================================================

    public function test_09_parent_can_track_active_trip_and_see_child_status(): void
    {
        $childData = $this->createChildWithActiveSubscription('عمران التاورغي');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/trips/active');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data'));

        $tripData = collect($response->json('data'))->firstWhere('trip_id', $trip->id);
        $this->assertNotNull($tripData);
        $this->assertEquals('in_progress', $tripData['status']);
    }

    // =========================================================================
    // 8. اختبار التوقف الطارئ للرحلة واستئنافها (Breakdown & Resume)
    // =========================================================================

    public function test_10_driver_reports_breakdown_and_resumes_trip(): void
    {
        $childData = $this->createChildWithActiveSubscription('معاذ التاورغي');
        $trip = app(\App\Services\Trip\DailyTripGenerationService::class)->generateForRoute($childData['route']);
        $trip->update(['status' => 'in_progress', 'actual_start_time' => now()]);

        // 1. الإبلاغ عن عطل
        $breakdownRes = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/report-breakdown", [
                'reason' => 'ارتفاع حرارة المحرك',
            ]);

        $breakdownRes->assertStatus(200);
        $this->assertDatabaseHas('trips', [
            'id'                => $trip->id,
            'status'            => 'suspended_breakdown',
            'suspension_reason' => 'ارتفاع حرارة المحرك',
        ]);

        // 2. استئناف الرحلة
        $resumeRes = $this->actingAs($this->driverUser, 'sanctum')
            ->postJson("/api/driver/trips/{$trip->id}/resume");

        $resumeRes->assertStatus(200);
        $this->assertDatabaseHas('trips', [
            'id'                => $trip->id,
            'status'            => 'in_progress',
            'suspension_reason' => null,
        ]);
    }
}
