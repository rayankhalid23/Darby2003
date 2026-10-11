<?php

namespace Tests\Feature;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\ParentModel;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Municipality;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\RequestChild;
use App\Models\Shared\Route;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripStop;
use App\Models\Shared\Zone;
use App\Models\User;
use App\Services\Trip\EmergencyBreakdownService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmergencyFiveChildrenScenarioTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $originalDriverUser;
    protected Driver $originalDriver;
    protected Vehicle $originalVehicle;
    protected Route $route;
    protected User $substituteDriverUser;
    protected Driver $substituteDriver;
    protected Vehicle $substituteVehicle;
    protected School $schoolA;
    protected School $schoolB;
    protected Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        PricingSetting::firstOrCreate([], [
            'base_fare' => 15.00,
            'km_rate' => 1.50,
            'service_fee_percentage' => 10.00,
            'service_fee_fixed' => 2.00,
            'rush_hour_multiplier' => 1.25,
            'driver_split_percent' => 85.00,
            'company_split_percent' => 15.00,
            'grace_period_days' => 3,
            'vat_percent' => 5.00,
            'is_active' => true,
        ]);

        $municipality = Municipality::firstOrCreate(['name' => 'طرابلس الكبرى']);
        $subMunicipality = SubMunicipality::firstOrCreate([
            'municipality_id' => $municipality->id,
            'name' => 'حي الأندلس'
        ]);

        $this->zone = Zone::firstOrCreate(
            ['name' => 'حي الأندلس - سيناريو 5 أطفال'],
            [
                'municipality_id' => $municipality->id,
                'sub_municipality_id' => $subMunicipality->id,
                'polygon_coordinates' => json_encode([
                    ['lat' => 32.880, 'lng' => 13.180],
                    ['lat' => 32.890, 'lng' => 13.180],
                    ['lat' => 32.890, 'lng' => 13.190],
                    ['lat' => 32.880, 'lng' => 13.190]
                ]),
                'is_active' => true,
            ]
        );

        $this->schoolA = School::create([
            'name' => 'مدرسة الأمل النموذجية',
            'zone_id' => $this->zone->id,
            'address' => 'طرابلس - حي الأندلس شارع المدارس',
            'lat' => 32.8850,
            'lng' => 13.1850,
            'status' => 'Approved',
        ]);

        $this->schoolB = School::create([
            'name' => 'مدرسة النور الخاصة',
            'zone_id' => $this->zone->id,
            'address' => 'طرابلس - طريق الشط',
            'lat' => 32.8950,
            'lng' => 13.1950,
            'status' => 'Approved',
        ]);

        $this->parentUser = User::create([
            'full_name' => 'سالم الطرابلسي',
            'email' => 'parent.five.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id' => 3,
            'is_active' => 1,
        ]);
        $this->parentUser->update(['is_trusted' => 1]);
        $this->parent = ParentModel::find($this->parentUser->id);
        $this->parent->deposit(100000);

        $this->originalDriverUser = User::create([
            'full_name' => 'كابتن عبد السلام الأصلي',
            'email' => 'orig.five.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id' => 2,
            'is_active' => 1,
        ]);
        $this->originalDriver = Driver::create([
            'user_id' => $this->originalDriverUser->id,
            'national_id' => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->toDateString(),
            'status' => 'Approved',
            'gender' => 'male',
            'accepted_gender' => 'both',
            'subscription_type' => 'both',
            'morning_go' => 1,
            'morning_return' => 1,
            'current_lat' => 32.87500000,
            'current_lng' => 13.17500000,
        ]);
        $this->originalDriver->deposit(50000);

        $this->originalVehicle = Vehicle::create([
            'driver_id' => $this->originalDriver->id,
            'brand' => 'تويوتا',
            'model' => 'هاي آس',
            'year' => 2022,
            'color' => 'أبيض',
            'type' => 'van',
            'plate_number' => 'LY-11115',
            'capacity_manual' => 10,
            'has_ac' => 1,
            'status' => 'Active',
            'is_verified' => 1,
        ]);

        $this->route = Route::create([
            'driver_id' => $this->originalDriver->id,
            'vehicle_id' => $this->originalVehicle->id,
            'route_name' => 'مسار صباحي 5 أطفال',
            'route_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'status' => 'Active',
            'start_time' => '07:00:00',
        ]);

        $this->substituteDriverUser = User::create([
            'full_name' => 'كابتن طارق البديل',
            'email' => 'sub.five.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id' => 2,
            'is_active' => 1,
        ]);
        $this->substituteDriver = Driver::create([
            'user_id' => $this->substituteDriverUser->id,
            'national_id' => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->toDateString(),
            'status' => 'Approved',
            'gender' => 'male',
            'accepted_gender' => 'both',
            'subscription_type' => 'both',
            'morning_go' => 1,
            'morning_return' => 1,
            'current_lat' => 32.87600000,
            'current_lng' => 13.17600000,
        ]);
        $this->substituteDriver->deposit(10000);

        $this->substituteVehicle = Vehicle::create([
            'driver_id' => $this->substituteDriver->id,
            'brand' => 'تويوتا',
            'model' => 'هاي آس',
            'year' => 2021,
            'color' => 'فضي',
            'type' => 'van',
            'plate_number' => 'LY-22225',
            'capacity_manual' => 10,
            'has_ac' => 1,
            'status' => 'Active',
            'is_verified' => 1,
        ]);

        DB::table('driver_zone')->insertOrIgnore([
            ['driver_id' => $this->originalDriver->id, 'zone_id' => $this->zone->id],
            ['driver_id' => $this->substituteDriver->id, 'zone_id' => $this->zone->id],
        ]);

        DriverSeatSlot::create([
            'driver_id' => $this->substituteDriver->id,
            'slot' => 'morning_go',
            'date' => Carbon::today()->toDateString(),
            'booked' => 2,
        ]);
    }

    private function createChildWithSubscription(int $index, School $school): array
    {
        $address = Address::create([
            'user_id' => $this->parentUser->id,
            'label' => "منزل الطفل {$index}",
            'lat' => 32.8810 + ($index * 0.001),
            'lng' => 13.1810 + ($index * 0.001),
        ]);

        $child = Child::create([
            'parent_id' => $this->parent->id,
            'school_id' => $school->id,
            'address_id' => $address->id,
            'full_name' => "طفل رقم {$index}",
            'birth_date' => '2015-05-10',
            'gender' => 'male',
            'grade' => 3,
            'qr_code_token' => "QR_FIVE_{$index}",
        ]);

        $req = SubscriptionRequest::create([
            'parent_id' => $this->parent->id,
            'driver_id' => $this->originalDriver->id,
            'status' => 'accepted',
            'total_price' => 100.00,
            'total_amount_after_discount' => 100.00,
            'children_count' => 1,
        ]);

        $req->children()->attach([
            $child->id => ['total_amount_after_discount' => 100.00],
        ]);

        $requestChildId = RequestChild::where('request_id', $req->id)->where('child_id', $child->id)->value('id');

        $sub = ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'request_child_id' => $requestChildId,
            'route_id' => $this->route->id,
            'status' => 'active',
            'plan_type' => 'monthly',
            'pickup_lat' => $address->lat,
            'pickup_lng' => $address->lng,
            'pickup_label' => $address->label,
            'dropoff_lat' => $school->lat,
            'dropoff_lng' => $school->lng,
            'dropoff_label' => $school->name,
            'start_date' => Carbon::now()->subDays(5),
            'end_date' => Carbon::now()->addDays(25),
        ]);

        return [$child, $sub, $address];
    }

    /**
     * سيناريو 1: السائق لديه 5 أطفال، صعد 2 وبقي 3 في المنازل، والمدارس مختلفة.
     */
    public function test_breakdown_with_two_boarded_and_three_pending_children_across_different_schools()
    {
        // 5 أطفال موزعين على مدرستين
        [$child1, $sub1, $addr1] = $this->createChildWithSubscription(1, $this->schoolA);
        [$child2, $sub2, $addr2] = $this->createChildWithSubscription(2, $this->schoolB);
        [$child3, $sub3, $addr3] = $this->createChildWithSubscription(3, $this->schoolA);
        [$child4, $sub4, $addr4] = $this->createChildWithSubscription(4, $this->schoolB);
        [$child5, $sub5, $addr5] = $this->createChildWithSubscription(5, $this->schoolA);

        $trip = Trip::create([
            'driver_id' => $this->originalDriver->id,
            'route_id' => $this->route->id,
            'trip_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'trip_date' => Carbon::today(),
            'status' => 'in_progress',
            'current_latitude' => 32.8830,
            'current_longitude' => 13.1830,
        ]);

        // الطفل 1 و 2 صعدوا
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child1->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 1,
            'status' => TripStop::STATUS_BOARDED,
            'lat' => $addr1->lat,
            'lng' => $addr1->lng,
            'label' => $addr1->label,
        ]);
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child2->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 2,
            'status' => TripStop::STATUS_BOARDED,
            'lat' => $addr2->lat,
            'lng' => $addr2->lng,
            'label' => $addr2->label,
        ]);

        // الأطفال 3، 4، 5 في الانتظار
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child3->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 3,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $addr3->lat,
            'lng' => $addr3->lng,
            'label' => $addr3->label,
        ]);
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child4->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 4,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $addr4->lat,
            'lng' => $addr4->lng,
            'label' => $addr4->label,
        ]);
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child5->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 5,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $addr5->lat,
            'lng' => $addr5->lng,
            'label' => $addr5->label,
        ]);

        // محطات المدارس
        TripStop::create([
            'trip_id' => $trip->id,
            'school_id' => $this->schoolA->id,
            'stop_type' => TripStop::TYPE_SCHOOL,
            'sequence_order' => 6,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $this->schoolA->lat,
            'lng' => $this->schoolA->lng,
            'label' => $this->schoolA->name,
        ]);
        TripStop::create([
            'trip_id' => $trip->id,
            'school_id' => $this->schoolB->id,
            'stop_type' => TripStop::TYPE_SCHOOL,
            'sequence_order' => 7,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $this->schoolB->lat,
            'lng' => $this->schoolB->lng,
            'label' => $this->schoolB->name,
        ]);

        // 1. إبلاغ السائق الأصلي عن عطل
        $service = app(EmergencyBreakdownService::class);
        $result = $service->reportBreakdown(
            $trip,
            32.8835,
            13.1835,
            'عطل مفاجئ في المحرك'
        );

        $dispatch = TripBreakdownDispatch::findOrFail($result['dispatch_id']);
        $this->assertTrue(in_array($dispatch->status, [TripBreakdownDispatch::STATUS_PENDING, TripBreakdownDispatch::STATUS_BROADCASTED]));
        $this->assertCount(5, $dispatch->stranded_children_ids);

        // 2. قبول السائق البديل للإرسال
        $acceptResult = $service->acceptBreakdownDispatch($dispatch->id, $this->substituteDriver->id);
        $substituteTrip = Trip::findOrFail($acceptResult['substitute_trip_id']);
        $this->assertNotNull($substituteTrip);

        // 3. التحقق من محطات السائق البديل
        $subStops = TripStop::where('trip_id', $substituteTrip->id)->orderBy('sequence_order')->get();

        // المحطة الأولى يجب أن تكون السيارة المتوقفة
        $firstStop = $subStops->first();
        $this->assertEquals(TripStop::TYPE_HOME, $firstStop->stop_type);
        $this->assertEquals('السيارة المتوقفة (استلام الطلاب)', $firstStop->label);
        $this->assertNull($firstStop->child_id);
        $reasonData = json_decode($firstStop->reason, true);
        $this->assertEquals([$child1->id, $child2->id], $reasonData['boarded_child_ids']);

        // المحطات التالية يجب أن تكون فقط منازل الأطفال 3 و 4 و 5 (وليس 1 و 2)
        $homeStops = $subStops->where('stop_type', TripStop::TYPE_HOME)->whereNotNull('child_id')->values();
        $this->assertCount(3, $homeStops);
        $this->assertEquals([$child3->id, $child4->id, $child5->id], $homeStops->pluck('child_id')->all());

        // محطات المدارس يجب أن تشمل كلا المدرستين A و B
        $schoolStops = $subStops->where('stop_type', TripStop::TYPE_SCHOOL)->values();
        $this->assertCount(2, $schoolStops);
        $this->assertTrue($schoolStops->pluck('school_id')->contains($this->schoolA->id));
        $this->assertTrue($schoolStops->pluck('school_id')->contains($this->schoolB->id));

        // 4. فحص API لجلب المحطات للسائق البديل GET /stops
        $response = $this->actingAs($this->substituteDriverUser, 'sanctum')
            ->getJson("/api/v1/driver/trips/{$substituteTrip->id}/stops");

        $response->assertStatus(200);
        $stopsData = $response->json('data.stops');
        $apiFirstStop = $stopsData[0];

        $this->assertTrue($apiFirstStop['is_breakdown_transfer']);
        $this->assertTrue($apiFirstStop['can_bulk_confirm']);
        $this->assertEquals(2, $apiFirstStop['transferred_children_count']);
        $this->assertCount(2, $apiFirstStop['transferred_children']);

        // 5. بدء الرحلة البديلة
        $substituteTrip->update(['status' => 'in_progress']);

        // 6. تأكيد استلام أطفال السيارة المتوقفة دفعة واحدة
        $confirmResponse = $this->actingAs($this->substituteDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$substituteTrip->id}/confirm-breakdown-transfer", [
                'latitude' => 32.8835,
                'longitude' => 13.1835,
            ]);

        $confirmResponse->assertStatus(200);
        $confirmResponse->assertJson([
            'status' => 'success',
            'transferred_children_count' => 2,
        ]);

        // التحقق من تحديث حالة محطة السيارة المتوقفة
        $firstStop->refresh();
        $this->assertEquals(TripStop::STATUS_BOARDED, $firstStop->status);

        // التحقق من إنشاء حدث picked_up للطفلين
        $this->assertDatabaseHas('trip_events', [
            'trip_id' => $substituteTrip->id,
            'child_id' => $child1->id,
            'action_type' => 'picked_up',
        ]);
        $this->assertDatabaseHas('trip_events', [
            'trip_id' => $substituteTrip->id,
            'child_id' => $child2->id,
            'action_type' => 'picked_up',
        ]);

        // 7. التأكد أن السائق البديل يستطيع تسجيل حالة طفل بالمنزل (طفل 3) دون خطأ IDOR
        $child3Response = $this->actingAs($this->substituteDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$substituteTrip->id}/children/{$child3->id}/status", [
                'action' => 'pickup',
                'latitude' => $addr3->lat,
                'longitude' => $addr3->lng,
            ]);

        $child3Response->assertStatus(200);
        $this->assertDatabaseHas('trip_events', [
            'trip_id' => $substituteTrip->id,
            'child_id' => $child3->id,
            'action_type' => 'picked_up',
        ]);
    }

    /**
     * سيناريو 2: السائق لم يركب أي طفل بعد (0 أطفال صعدوا، كلهم 5 قيد الانتظار في المنازل).
     * يجب ألا يتم توليد محطة استلام من سيارة معطلة، بل يبدأ السائق البديل مباشرة من المنازل.
     */
    public function test_breakdown_with_zero_boarded_children_generates_no_vehicle_stop()
    {
        [$child1, $sub1, $addr1] = $this->createChildWithSubscription(1, $this->schoolA);
        [$child2, $sub2, $addr2] = $this->createChildWithSubscription(2, $this->schoolB);
        [$child3, $sub3, $addr3] = $this->createChildWithSubscription(3, $this->schoolA);

        $trip = Trip::create([
            'driver_id' => $this->originalDriver->id,
            'route_id' => $this->route->id,
            'trip_type' => 'Morning',
            'shift_slot' => 'morning_go',
            'trip_date' => Carbon::today(),
            'status' => 'in_progress',
            'current_latitude' => 32.8830,
            'current_longitude' => 13.1830,
        ]);

        // كل الأطفال بحالة PENDING (لم يصعد أحد)
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child1->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 1,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $addr1->lat,
            'lng' => $addr1->lng,
            'label' => $addr1->label,
        ]);
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child2->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 2,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $addr2->lat,
            'lng' => $addr2->lng,
            'label' => $addr2->label,
        ]);
        TripStop::create([
            'trip_id' => $trip->id,
            'child_id' => $child3->id,
            'stop_type' => TripStop::TYPE_HOME,
            'sequence_order' => 3,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $addr3->lat,
            'lng' => $addr3->lng,
            'label' => $addr3->label,
        ]);

        TripStop::create([
            'trip_id' => $trip->id,
            'school_id' => $this->schoolA->id,
            'stop_type' => TripStop::TYPE_SCHOOL,
            'sequence_order' => 4,
            'status' => TripStop::STATUS_PENDING,
            'lat' => $this->schoolA->lat,
            'lng' => $this->schoolA->lng,
            'label' => $this->schoolA->name,
        ]);

        $service = app(EmergencyBreakdownService::class);
        $result = $service->reportBreakdown(
            $trip,
            32.8830,
            13.1830,
            'عطل مبكر قبل البدء بأي صعود'
        );

        $dispatch = TripBreakdownDispatch::findOrFail($result['dispatch_id']);
        $acceptResult = $service->acceptBreakdownDispatch($dispatch->id, $this->substituteDriver->id);
        $substituteTrip = Trip::findOrFail($acceptResult['substitute_trip_id']);

        $subStops = TripStop::where('trip_id', $substituteTrip->id)->orderBy('sequence_order')->get();

        // يجب ألا توجد أي محطة للسيارة المتوقفة لأن السائق لم يركب أحداً
        $carStops = $subStops->filter(fn($s) => str_contains($s->label ?? '', 'السيارة المتوقفة'));
        $this->assertCount(0, $carStops);

        // المحطة الأولى تكون منزل الطفل الأول مباشرة
        $this->assertEquals(TripStop::TYPE_HOME, $subStops->first()->stop_type);
        $this->assertEquals($child1->id, $subStops->first()->child_id);
        $this->assertCount(3, $subStops->where('stop_type', TripStop::TYPE_HOME));
    }
}
