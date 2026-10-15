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
use App\Models\Shared\Route;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripStop;
use App\Models\Shared\Zone;
use App\Models\User;
use App\Services\Notification\NotificationFormatter;
use App\Services\Trip\EmergencyBreakdownService;
use App\Services\Trip\TripLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmergencyBreakdownAndDriverSubstitutionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $originalDriverUser;
    protected Driver $originalDriver;
    protected User $substituteDriverUser1;
    protected Driver $substituteDriver1;
    protected User $substituteDriverUser2;
    protected Driver $substituteDriver2;
    protected School $school;
    protected Address $homeAddress;
    protected Zone $zone;
    protected Trip $trip;
    protected Child $child1;
    protected Child $child2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        PricingSetting::firstOrCreate([], [
            'price_per_km_ac'              => 2.50,
            'price_per_km_non_ac'          => 2.00,
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
            'status'  => 'Approved',
        ]);

        // 1. إنشاء ولي الأمر
        $this->parentUser = User::create([
            'full_name'     => 'أحمد الهوني',
            'email'         => 'parent.emergency.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $this->parentUser->update(['is_trusted' => 1]);
        $this->parent = ParentModel::find($this->parentUser->id);
        $this->parent->deposit(100000);

        $this->homeAddress = Address::create([
            'user_id' => $this->parentUser->id,
            'label'   => 'المنزل',
            'lat'     => 32.88000000,
            'lng'     => 13.18000000,
        ]);

        // 2. إنشاء السائق الأصلي المعطل
        $this->originalDriverUser = User::create([
            'full_name'     => 'كابتن عبد السلام الأصلي',
            'email'         => 'orig.driver.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->originalDriver = Driver::create([
            'user_id'           => $this->originalDriverUser->id,
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
        $this->originalDriver->deposit(50000); // 500 د.ل رصيد مبدئي

        $this->originalVehicle = Vehicle::create([
            'driver_id'       => $this->originalDriver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هاي آس',
            'year'            => 2022,
            'color'           => 'أبيض',
            'type'            => 'van',
            'plate_number'    => 'LY-1111',
            'capacity_manual' => 10,
            'has_ac'          => 1,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);

        DB::table('driver_zone')->insertOrIgnore([
            ['driver_id' => $this->originalDriver->id, 'zone_id' => $this->zone->id],
        ]);

        // 3. إنشاء سائقين بديلين مرشحين (سائق 1 وسائق 2)
        $this->substituteDriverUser1 = User::create([
            'full_name'     => 'كابتن طارق البديل الأول',
            'email'         => 'sub1.driver.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->substituteDriver1 = Driver::create([
            'user_id'           => $this->substituteDriverUser1->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->toDateString(),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
            'morning_go'        => 1,
            'morning_return'    => 1,
            'current_lat'       => 32.87600000,
            'current_lng'       => 13.17600000,
        ]);

        $this->substituteVehicle1 = Vehicle::create([
            'driver_id'       => $this->substituteDriver1->id,
            'brand'           => 'نيسان',
            'model'           => 'أورفان',
            'year'            => 2023,
            'color'           => 'فضي',
            'type'            => 'van',
            'plate_number'    => 'LY-2222',
            'capacity_manual' => 12,
            'has_ac'          => 1,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);

        DB::table('driver_zone')->insertOrIgnore([
            ['driver_id' => $this->substituteDriver1->id, 'zone_id' => $this->zone->id],
        ]);

        DriverSeatSlot::create([
            'driver_id' => $this->substituteDriver1->id,
            'slot'      => 'morning_go',
            'date'      => Carbon::today()->toDateString(),
            'booked'    => 2,
        ]);

        // السائق البديل الثاني
        $this->substituteDriverUser2 = User::create([
            'full_name'     => 'كابتن هشام البديل الثاني',
            'email'         => 'sub2.driver.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->substituteDriver2 = Driver::create([
            'user_id'           => $this->substituteDriverUser2->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->toDateString(),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
            'morning_go'        => 1,
            'morning_return'    => 1,
            'current_lat'       => 32.87700000,
            'current_lng'       => 13.17700000,
        ]);

        $this->substituteVehicle2 = Vehicle::create([
            'driver_id'       => $this->substituteDriver2->id,
            'brand'           => 'هيونداي',
            'model'           => 'H1',
            'year'            => 2021,
            'color'           => 'أسود',
            'type'            => 'van',
            'plate_number'    => 'LY-3333',
            'capacity_manual' => 10,
            'has_ac'          => 1,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);

        DB::table('driver_zone')->insertOrIgnore([
            ['driver_id' => $this->substituteDriver2->id, 'zone_id' => $this->zone->id],
        ]);

        DriverSeatSlot::create([
            'driver_id' => $this->substituteDriver2->id,
            'slot'      => 'morning_go',
            'date'      => Carbon::today()->toDateString(),
            'booked'    => 1,
        ]);

        // 4. إنشاء أطفال واشتراكات نشطة مع السائق الأصلي
        $this->child1 = Child::create([
            'parent_id'     => $this->parent->id,
            'school_id'     => $this->school->id,
            'address_id'    => $this->homeAddress->id,
            'full_name'     => 'عمر أحمد',
            'birth_date'    => '2016-01-01',
            'gender'        => 'male',
            'grade'         => 3,
            'qr_code_token' => 'QR_CHILD_1',
        ]);

        $this->child2 = Child::create([
            'parent_id'     => $this->parent->id,
            'school_id'     => $this->school->id,
            'address_id'    => $this->homeAddress->id,
            'full_name'     => 'فاطمة أحمد',
            'birth_date'    => '2018-05-01',
            'gender'        => 'female',
            'grade'         => 1,
            'qr_code_token' => 'QR_CHILD_2',
        ]);

        $route = Route::create([
            'driver_id'   => $this->originalDriver->id,
            'vehicle_id'  => $this->originalVehicle->id,
            'route_name'  => 'مسار صباحي - طريق الشط',
            'route_type'  => 'Morning',
            'shift_slot'  => 'morning_go',
            'status'      => 'Active',
            'start_time'  => '07:00:00',
        ]);

        $req = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->originalDriver->id,
            'status'                      => 'accepted',
            'total_price'                 => 200.00,
            'total_amount_after_discount' => 200.00,
            'children_count'              => 2,
        ]);

        // child_id/driver_id/parent_id لم يعودوا أعمدة فعلية على active_subscriptions
        // (تُشتق الآن عبر request_child_id/subscription_request_id — انظر
        // ActiveSubscription::getChildIdAttribute). لازم نمرّ عبر pivot الطلب
        // request_children حتى تعمل ActiveSubscription::forChildren() في الخدمة.
        $req->children()->attach([
            $this->child1->id => ['total_amount_after_discount' => 100.00],
            $this->child2->id => ['total_amount_after_discount' => 100.00],
        ]);
        $requestChildId1 = \App\Models\Shared\RequestChild::where('request_id', $req->id)->where('child_id', $this->child1->id)->value('id');
        $requestChildId2 = \App\Models\Shared\RequestChild::where('request_id', $req->id)->where('child_id', $this->child2->id)->value('id');

        ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'request_child_id'        => $requestChildId1,
            'route_id'                => $route->id,
            'pickup_lat'              => 32.88000000,
            'pickup_lng'              => 13.18000000,
            'dropoff_lat'             => 32.89000000,
            'dropoff_lng'             => 13.19000000,
            'status'                  => 'active',
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'request_child_id'        => $requestChildId2,
            'route_id'                => $route->id,
            'pickup_lat'              => 32.88000000,
            'pickup_lng'              => 13.18000000,
            'dropoff_lat'             => 32.89000000,
            'dropoff_lng'             => 13.19000000,
            'status'                  => 'active',
        ]);

        // 5. إنشاء رحلة جارية وبها أطفال صاعدون
        $this->trip = Trip::create([
            'driver_id'            => $this->originalDriver->id,
            'route_id'             => $route->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.87500000,
            'start_lng'            => 13.17500000,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        TripStop::create([
            'trip_id'        => $this->trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $this->child1->id,
            'lat'            => 32.88000000,
            'lng'            => 13.18000000,
            'label'          => 'منزل عمر',
            'sequence_order' => 1,
            'status'         => TripStop::STATUS_BOARDED, // الطفل بالحافلة
        ]);

        TripStop::create([
            'trip_id'        => $this->trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $this->child2->id,
            'lat'            => 32.88000000,
            'lng'            => 13.18000000,
            'label'          => 'منزل فاطمة',
            'sequence_order' => 2,
            'status'         => TripStop::STATUS_BOARDED, // الطفل بالحافلة
        ]);

        TripStop::create([
            'trip_id'        => $this->trip->id,
            'stop_type'      => TripStop::TYPE_SCHOOL,
            'school_id'      => $this->school->id,
            'lat'            => 32.89000000,
            'lng'            => 13.19000000,
            'label'          => 'المدرسة',
            'sequence_order' => 3,
            'status'         => TripStop::STATUS_PENDING,
        ]);
    }

    /**
     * سيناريو 1: السائق يبلّغ عن عطل وتوقف الحافلة وفيها أطفال
     * فيقوم النظام بالبحث عن السائقين البدلاء وبث الإشعارات وتحديث حالة الرحلة.
     */
    public function test_driver_reports_breakdown_and_system_broadcasts_to_nearby_substitutes()
    {
        $response = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/report-breakdown", [
                'latitude'  => 32.88500000,
                'longitude' => 13.18500000,
                'reason'    => 'عطل في المحرك وتوقف تام للمركبة',
                'accuracy'  => 5.2,
                'speed'     => 0.0,
                'address'   => 'طرابلس - طريق الشط بالقرب من جزيرة الميناء',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'                  => 'broadcasted',
            'stranded_children_count' => 2,
            'breakdown_location'      => [
                'latitude'  => 32.885,
                'longitude' => 13.185,
            ]
        ]);

        $this->trip->refresh();
        $this->assertEquals('suspended_breakdown', $this->trip->status);
        $this->assertEquals('عطل في المحرك وتوقف تام للمركبة', $this->trip->suspension_reason);

        // التحقق من تحديث موقع السائق الحي بدقة
        $this->originalDriver->refresh();
        $this->assertEquals(32.88500000, (float) $this->originalDriver->current_lat);
        $this->assertEquals(13.18500000, (float) $this->originalDriver->current_lng);

        // التحقق من إنشاء سجل Dispatch وتخزين إحداثيات العطل بدقة
        $this->assertDatabaseHas('trip_breakdown_dispatches', [
            'trip_id'                 => $this->trip->id,
            'original_driver_id'      => $this->originalDriver->id,
            'breakdown_lat'           => 32.88500000,
            'breakdown_lng'           => 13.18500000,
            'status'                  => TripBreakdownDispatch::STATUS_BROADCASTED,
            'stranded_children_count' => 2,
        ]);

        // التحقق من وصول الإشعار للسائقين المرشحين
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->substituteDriverUser1->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->substituteDriverUser2->id,
        ]);

        // التحقق من إشعار ولي الأمر
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->parentUser->id,
        ]);
    }

    /**
     * اختبار قبول إحداثيات العطل عبر هيكل الكائن المتداخل (Nested location object)
     */
    public function test_driver_can_report_breakdown_with_nested_location_object()
    {
        $response = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/report-breakdown", [
                'breakdown_location' => [
                    'latitude'  => 32.88910000,
                    'longitude' => 13.19520000,
                ],
                'reason' => 'عطل كهربائي مفاجئ في الحافلة',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'             => 'broadcasted',
            'breakdown_location' => [
                'latitude'  => 32.8891,
                'longitude' => 13.1952,
            ]
        ]);

        $this->assertDatabaseHas('trip_breakdown_dispatches', [
            'trip_id'       => $this->trip->id,
            'breakdown_lat' => 32.88910000,
            'breakdown_lng' => 13.19520000,
        ]);
    }

    /**
     * سيناريو 2: السائق البديل الأول يوافق على المهمة
     * - يقبل الطلب فوراً
     * - يلغي النظام الإشعار للسائق الثاني
     * - يحدّث مسار ومحطات السائق البديل فوراً
     * - يرسل تفاصيل السائق البديل لولي الأمر
     */
    public function test_first_substitute_driver_accepts_dispatch_and_updates_route_and_notifies_parents()
    {
        // 1. تشغيل العطل
        $service = app(EmergencyBreakdownService::class);
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            'ثقب في الإطارات وتوقف الحافلة'
        );

        $dispatchId = $reportResult['dispatch_id'];
        $this->assertNotNull($dispatchId);

        // 2. قبول السائق البديل الأول للطلب
        $response = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/accept");

        $response->assertStatus(200);
        $response->assertJson([
            'status'      => 'success',
            'dispatch_id' => $dispatchId,
        ]);

        // 3. التحقق من حالة الـ Dispatch
        $dispatch = TripBreakdownDispatch::findOrFail($dispatchId);
        $this->assertEquals(TripBreakdownDispatch::STATUS_ACCEPTED, $dispatch->status);
        $this->assertEquals($this->substituteDriver1->id, $dispatch->substitute_driver_id);
        $this->assertNotNull($dispatch->substitute_trip_id);

        // 4. التحقق من تحديث محطات السائق البديل فوراً
        $subTripStops = TripStop::where('trip_id', $dispatch->substitute_trip_id)->get();
        $this->assertTrue($subTripStops->isNotEmpty());
        $this->assertTrue($subTripStops->contains('lat', 32.88500000));
        $transferStop = $subTripStops->firstWhere('lat', 32.88500000);
        $this->assertNotNull($transferStop);
        $this->assertStringContainsString((string) $this->child1->id, (string) $transferStop->reason);
        $this->assertStringContainsString((string) $this->child2->id, (string) $transferStop->reason);
        $this->assertTrue($subTripStops->contains('school_id', $this->school->id));

        // 5. التحقق من إشعار الإلغاء للسائق الثاني
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->substituteDriverUser2->id,
        ]);

        // 6. التحقق من إشعار ولي الأمر ببيانات السائق البديل وتوجيهه لرحلة البديل
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->parentUser->id,
        ]);
        $parentNotif = DB::table('notifications')
            ->where('notifiable_id', $this->parentUser->id)
            ->where('data', 'like', '%' . NotificationFormatter::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_PARENT . '%')
            ->first();
        $this->assertNotNull($parentNotif);
        $notifData = json_decode($parentNotif->data, true);
        $this->assertEquals(NotificationFormatter::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_PARENT, $notifData['type']);
        $this->assertEquals((string) $dispatch->substitute_trip_id, (string) ($notifData['entity_id'] ?? null));
        $this->assertEquals((string) $this->trip->id, (string) ($notifData['payload']['original_trip_id'] ?? null));
        $this->assertEquals((string) $dispatch->substitute_trip_id, (string) ($notifData['payload']['substitute_trip_id'] ?? null));
        $this->assertEquals((string) $dispatchId, (string) ($notifData['payload']['dispatch_id'] ?? null));

        // 7. التحقق من شاشة الرحلات النشطة لولي الأمر GET /api/v1/parent/trips/active
        $activeResponse = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/v1/parent/trips/active');
        $this->assertEquals(200, $activeResponse->status(), 'Active trips error: ' . json_encode($activeResponse->json()));
        $activeData = $activeResponse->json('data');
        $this->assertNotEmpty($activeData);
        $this->assertEquals($dispatch->substitute_trip_id, $activeData[0]['trip_id']);
        $this->assertTrue($activeData[0]['is_substitute']);
        $this->assertEquals($this->trip->id, $activeData[0]['original_trip_id']);
        $this->assertEquals($dispatchId, $activeData[0]['dispatch_id']);
        $this->assertEquals($this->substituteDriver1->id, $activeData[0]['driver']['id']);
        $this->assertCount(2, $activeData[0]['children']);

        // 8. التحقق من التتبع المباشر لولي الأمر GET /api/v1/parent/trips/{substitute_trip_id}/track
        $trackResponse = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson("/api/v1/parent/trips/{$dispatch->substitute_trip_id}/track");
        $this->assertEquals(200, $trackResponse->status(), 'Track error: ' . json_encode($trackResponse->json()));
        $trackData = $trackResponse->json('data');
        $this->assertEquals($dispatch->substitute_trip_id, $trackData['trip_id']);
        $this->assertTrue($trackData['is_substitute']);
        $this->assertEquals($this->trip->id, $trackData['original_trip_id']);
        $this->assertCount(2, $trackData['children']);

        // 9. التحقق من تفاصيل الرحلة لولي الأمر GET /api/v1/parent/trips/{substitute_trip_id}
        $detailsResponse = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson("/api/v1/parent/trips/{$dispatch->substitute_trip_id}");
        $this->assertEquals(200, $detailsResponse->status(), 'Details error: ' . json_encode($detailsResponse->json()));
        $detailsData = $detailsResponse->json('data');
        $this->assertEquals($dispatch->substitute_trip_id, $detailsData['trip_id']);
        $this->assertTrue($detailsData['is_substitute']);
        $this->assertEquals($this->trip->id, $detailsData['original_trip_id']);
        $this->assertCount(2, $detailsData['children']);

        // 10. محاولة سائق آخر القبول بعد حسمها -> يجب أن تفشل بخطأ 409
        $conflictResponse = $this->actingAs($this->substituteDriverUser2, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/accept");

        $conflictResponse->assertStatus(409);
    }

    /**
     * سيناريو 3: رفض جميع السائقين أو عدم وجود سائقين مطابقين
     * - يرسل النظام إشعاراً للسائق: "امشي كلم أولياء الأمور"
     * - يرسل إشعاراً لأولياء الأمور بموقع الأبناء الحي: "تعال خذ ابنك"
     */
    public function test_fallback_notifies_driver_to_call_parents_and_parents_with_live_location()
    {
        // إيقاف جميع السائقين الآخرين لمحاكاة عدم وجود أي سائق متاح في النظام
        Driver::where('id', '!=', $this->originalDriver->id)->update(['status' => 'Suspended']);

        $response = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/report-breakdown", [
                'lat'    => 32.88720000,
                'lng'    => 13.19130000,
                'reason' => 'عطل كهربائي مفاجئ',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'no_substitutes_available',
        ]);

        $dispatch = TripBreakdownDispatch::where('trip_id', $this->trip->id)->first();
        $this->assertEquals(TripBreakdownDispatch::STATUS_UNRESOLVED, $dispatch->status);

        // التحقق من إشعار السائق الأصلي بالاتصال بأولياء الأمور
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->originalDriverUser->id,
        ]);

        // التحقق من إشعار ولي الأمر بموقع الابن لاستلامه
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->parentUser->id,
        ]);
    }

    /**
     * سيناريو 4: التسوية المالية الفورية فور إنهاء السائق البديل للرحلة
     * - يتم خصم سعر المشوار من محفظة السائق الأصلي وتحويله لمحفظة السائق البديل فوراً.
     */
    public function test_financial_fare_deducted_from_original_and_credited_to_substitute_upon_trip_completion()
    {
        $service = app(EmergencyBreakdownService::class);
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            'عطل مفاجئ'
        );

        $dispatchId = $reportResult['dispatch_id'];
        $service->acceptBreakdownDispatch($dispatchId, $this->substituteDriver1->id);

        $dispatch = TripBreakdownDispatch::findOrFail($dispatchId);
        $fareAmount = (float) $dispatch->trip_fare_amount;
        $this->assertGreaterThan(0, $fareAmount);

        $origBalBefore = (int) $this->originalDriver->fresh()->balance;
        $subBalBefore  = (int) $this->substituteDriver1->fresh()->balance;

        // وضع محطات الأطفال بحالة منتهية لتجاوز صمام أمان نسيان الأطفال عند إنهاء الرحلة
        TripStop::where('trip_id', $dispatch->substitute_trip_id)->update(['status' => TripStop::STATUS_DROPPED_OFF_SCHOOL]);

        // إنهاء رحلة السائق البديل
        $lifecycle = app(TripLifecycleService::class);
        $lifecycle->completeTrip($dispatch->substitute_trip_id);

        $origBalAfter = (int) $this->originalDriver->fresh()->balance;
        $subBalAfter  = (int) $this->substituteDriver1->fresh()->balance;

        $fareCents = (int) round($fareAmount * 100);

        // التحقق من الخصم من السائق الأصلي والإيداع للسائق البديل
        $this->assertEquals($origBalBefore - $fareCents, $origBalAfter);
        $this->assertEquals($subBalBefore + $fareCents, $subBalAfter);

        $dispatch->refresh();
        $this->assertTrue((bool) $dispatch->financial_settled);
        $this->assertEquals(TripBreakdownDispatch::STATUS_COMPLETED, $dispatch->status);
    }

    /**
     * سيناريو 5: عرض المهام المتاحة ورفض السائق الأول وقبول السائق الثاني
     */
    public function test_driver_can_list_available_dispatches_reject_and_second_driver_accepts()
    {
        $service = app(EmergencyBreakdownService::class);
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            'عطل مفاجئ'
        );

        $dispatchId = $reportResult['dispatch_id'];

        // 1. السائق البديل 1 يجلب المهام المتاحة له
        $availableRes = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->getJson('/api/v1/driver/emergency-dispatches/available');

        $availableRes->assertStatus(200);
        $availableRes->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'trip_id', 'status', 'stranded_children_count', 'trip_fare_amount']
            ]
        ]);

        // 2. السائق البديل 1 يرفض المهمة
        $rejectRes = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/reject");

        $rejectRes->assertStatus(200);
        $rejectRes->assertJson(['status' => 'rejected']);

        // 3. المهمة لم تعد تظهر في قائمة السائق 1
        $afterRejectRes = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->getJson('/api/v1/driver/emergency-dispatches/available');

        $afterRejectRes->assertStatus(200);
        $this->assertEmpty($afterRejectRes->json('data'));

        // 4. السائق البديل 2 يقبل المهمة بنجاح
        $acceptRes = $this->actingAs($this->substituteDriverUser2, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/accept");

        $acceptRes->assertStatus(200);
        $acceptRes->assertJson(['status' => 'success']);

        // 5. جلب تفاصيل المهمة
        $detailsRes = $this->actingAs($this->substituteDriverUser2, 'sanctum')
            ->getJson("/api/v1/driver/emergency-dispatches/{$dispatchId}");

        $detailsRes->assertStatus(200);
        $detailsRes->assertJson([
            'status' => 'success',
            'data'   => [
                'id'                   => $dispatchId,
                'status'               => 'accepted',
                'substitute_driver_id' => $this->substituteDriver2->id,
            ]
        ]);
    }

    public function test_original_driver_cannot_resume_trip_if_substitute_already_accepted(): void
    {
        $service = app(EmergencyBreakdownService::class);

        // 1. الإبلاغ عن عطل
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            'تعطل المحرك في الطريق'
        );

        $dispatchId = $reportResult['dispatch_id'];

        // 2. سائق بديل يقبل المهمة
        $service->acceptBreakdownDispatch($dispatchId, $this->substituteDriver1->id);

        // 3. السائق الأصلي يحاول استئناف الرحلة -> يجب منعه بـ 409
        $response = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/resume");

        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'SUBSTITUTE_ALREADY_ACCEPTED',
        ]);

        // التأكد من أن الرحلة ما زالت معلقة
        $this->trip->refresh();
        $this->assertEquals('suspended_breakdown', $this->trip->status);
    }

    public function test_original_driver_can_resume_trip_if_no_substitute_accepted_yet(): void
    {
        $service = app(EmergencyBreakdownService::class);

        // 1. الإبلاغ عن عطل
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            'عطل خفيف وتم إصلاحه سريعاً'
        );

        $dispatchId = $reportResult['dispatch_id'];

        // 2. السائق الأصلي يستأنف الرحلة قبل أن يقبل أي بديل
        $response = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$this->trip->id}/resume");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'trip_id' => $this->trip->id,
                'status'  => 'in_progress',
            ]
        ]);

        // التحقق من عودة الرحلة للعمل وإلغاء طلب الطوارئ
        $this->trip->refresh();
        $this->assertEquals('in_progress', $this->trip->status);

        $dispatch = TripBreakdownDispatch::find($dispatchId);
        $this->assertEquals(TripBreakdownDispatch::STATUS_CANCELLED, $dispatch->status);
    }

    /**
     * اختبار سيناريو: السائق الأساسي لديه 5 أطفال (صعد 2، وما زال 3 في الانتظار).
     * عند الإبلاغ عن العطل:
     * - الأطفال العالقون في الحافلة هم 2 فقط (boarded).
     * - الأطفال الـ 3 المنتظرون في منازلهم (pending) لا يذهبون للسائق البديل ولا يظهرون في مساره.
     * - مسار السائق البديل يحتوي فقط على أطفاله الأصليين + محطة استلام من الحافلة المتوقفة (للطفلين) + مدرستهما.
     * - التحقق من إشعار وصول السائق للوجهة/المحطة (وصول للحافلة المتوقفة أو للمدرسة أو للمنزل).
     */
    public function test_breakdown_with_five_children_transfers_only_boarded_to_substitute_driver(): void
    {
        $service = app(EmergencyBreakdownService::class);

        // 1. إنشاء 3 أطفال إضافيين مع عناوينهم واشتراكاتهم
        $child3 = Child::create([
            'parent_id'     => $this->parent->id,
            'school_id'     => $this->school->id,
            'address_id'    => $this->homeAddress->id,
            'full_name'     => 'يوسف أحمد (منتظر)',
            'birth_date'    => '2017-02-01',
            'gender'        => 'male',
            'grade'         => 2,
            'qr_code_token' => 'QR_CHILD_3',
        ]);
        $child4 = Child::create([
            'parent_id'     => $this->parent->id,
            'school_id'     => $this->school->id,
            'address_id'    => $this->homeAddress->id,
            'full_name'     => 'مريم أحمد (منتظرة)',
            'birth_date'    => '2019-03-01',
            'gender'        => 'female',
            'grade'         => 1,
            'qr_code_token' => 'QR_CHILD_4',
        ]);
        $child5 = Child::create([
            'parent_id'     => $this->parent->id,
            'school_id'     => $this->school->id,
            'address_id'    => $this->homeAddress->id,
            'full_name'     => 'سارة أحمد (منتظرة)',
            'birth_date'    => '2020-04-01',
            'gender'        => 'female',
            'grade'         => 1,
            'qr_code_token' => 'QR_CHILD_5',
        ]);

        // إضافة محطات الـ 3 أطفال كـ pending (لم يصعدوا بعد)
        $stop3 = TripStop::create([
            'trip_id'        => $this->trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $child3->id,
            'lat'            => 32.88100000,
            'lng'            => 13.18100000,
            'label'          => 'منزل يوسف',
            'sequence_order' => 4,
            'status'         => TripStop::STATUS_PENDING,
        ]);
        $stop4 = TripStop::create([
            'trip_id'        => $this->trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $child4->id,
            'lat'            => 32.88200000,
            'lng'            => 13.18200000,
            'label'          => 'منزل مريم',
            'sequence_order' => 5,
            'status'         => TripStop::STATUS_PENDING,
        ]);
        $stop5 = TripStop::create([
            'trip_id'        => $this->trip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $child5->id,
            'lat'            => 32.88300000,
            'lng'            => 13.18300000,
            'label'          => 'منزل سارة',
            'sequence_order' => 6,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        // التأكد من أن الرحلة بها 5 أطفال في المحطات: 2 صعدوا (boarded) و 3 في الانتظار (pending)
        $totalChildrenStops = TripStop::where('trip_id', $this->trip->id)
            ->whereNotNull('child_id')
            ->count();
        $this->assertEquals(5, $totalChildrenStops);

        // 2. السائق الأساسي يبلغ عن عطل
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            'عطل طارئ بعد ركوب طالبين'
        );

        // التأكد من أن النظام حصر الأطفال الـ 5 المتبقين لنقلهم للسائق البديل
        $this->assertEquals(5, $reportResult['stranded_children_count']);
        $dispatchId = $reportResult['dispatch_id'];
        $dispatch = TripBreakdownDispatch::find($dispatchId);
        $this->assertEquals(5, count($dispatch->stranded_children_ids));
        $this->assertContains($this->child1->id, $dispatch->stranded_children_ids);
        $this->assertContains($this->child2->id, $dispatch->stranded_children_ids);
        $this->assertContains($child3->id, $dispatch->stranded_children_ids);
        $this->assertContains($child4->id, $dispatch->stranded_children_ids);
        $this->assertContains($child5->id, $dispatch->stranded_children_ids);

        // 3. إنشاء رحلة خاصة بالسائق البديل بها طفل خاص به مسبقاً
        $subChild = Child::create([
            'parent_id'     => $this->parent->id,
            'school_id'     => $this->school->id,
            'address_id'    => $this->homeAddress->id,
            'full_name'     => 'طفل السائق البديل',
            'birth_date'    => '2016-01-01',
            'gender'        => 'male',
            'grade'         => 3,
            'qr_code_token' => 'QR_SUB_CHILD',
        ]);

        $subRoute = Route::create([
            'driver_id'   => $this->substituteDriver1->id,
            'vehicle_id'  => $this->substituteVehicle1->id,
            'route_name'  => 'مسار السائق البديل',
            'route_type'  => 'Morning',
            'shift_slot'  => 'morning_go',
            'status'      => 'Active',
            'start_time'  => '07:00:00',
        ]);

        $subTrip = Trip::create([
            'driver_id'            => $this->substituteDriver1->id,
            'route_id'             => $subRoute->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.87600000,
            'start_lng'            => 13.17600000,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        TripStop::create([
            'trip_id'        => $subTrip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $subChild->id,
            'lat'            => 32.87800000,
            'lng'            => 13.17800000,
            'label'          => 'منزل طفل البديل الأصلي',
            'sequence_order' => 1,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        TripStop::create([
            'trip_id'        => $subTrip->id,
            'stop_type'      => TripStop::TYPE_SCHOOL,
            'school_id'      => $this->school->id,
            'lat'            => 32.89000000,
            'lng'            => 13.19000000,
            'label'          => 'المدرسة',
            'sequence_order' => 2,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        // 4. السائق البديل يقبل مهمة الإنقاذ
        $acceptResult = $service->acceptBreakdownDispatch($dispatchId, $this->substituteDriver1->id);
        $this->assertEquals('success', $acceptResult['status']);

        // 5. فحص المحطات في رحلة السائق البديل
        $subStops = TripStop::where('trip_id', $subTrip->id)->get();

        // يجب أن تظهر منازل الأطفال الـ 3 الذين لم يصعدوا بعد في مسار السائق البديل لاستكمال نقلهم
        $subChildIdsInRoute = $subStops->pluck('child_id')->filter()->all();
        $this->assertContains($child3->id, $subChildIdsInRoute);
        $this->assertContains($child4->id, $subChildIdsInRoute);
        $this->assertContains($child5->id, $subChildIdsInRoute);

        // المحطات في رحلة البديل يجب أن تشمل:
        // - محطة طفله الأصلي (child_id = subChild->id)
        // - محطة استلام الأطفال من السيارة المتوقفة (الطفلين child1, child2 الصاعدين)
        // - محطات منازل الأطفال الـ 3 المنتظرين (child3, child4, child5)
        // - محطة المدرسة
        $this->assertContains($subChild->id, $subChildIdsInRoute);
        $breakdownStop = $subStops->firstWhere('label', 'السيارة المتوقفة (استلام الطلاب)');
        $this->assertNotNull($breakdownStop);
        $this->assertNull($breakdownStop->child_id);
        $reasonData = json_decode($breakdownStop->reason, true);
        $this->assertEquals([$this->child1->id, $this->child2->id], $reasonData['boarded_child_ids']);

        // التأكد من وجود محطات المنازل للأطفال الـ 3
        $this->assertNotNull($subStops->firstWhere('child_id', $child3->id));
        $this->assertNotNull($subStops->firstWhere('child_id', $child4->id));
        $this->assertNotNull($subStops->firstWhere('child_id', $child5->id));

        // 6. اختبار إشعار وصول السائق للوجهة (GPS Tracking)
        $trackingService = app(\App\Services\Trip\TripTrackingService::class);

        // السائق البديل يقترب من موقع الحافلة المتوقفة (أقل من 60 متر)
        $resLocation = $trackingService->updateDriverLocation(
            $subTrip->id,
            (float) $breakdownStop->lat,
            (float) $breakdownStop->lng,
            20.0
        );

        $this->assertEquals('success', $resLocation['status']);
        $this->assertNotEmpty($resLocation['proximity']['alerts']);
        $this->assertEquals('arrived_breakdown_bus', $resLocation['proximity']['alerts'][0]['type']);

        // السائق البديل يصل لمنزل أحد الأطفال الـ 3 المنتظرين (أقل من 60 متر)
        $child3Stop = $subStops->firstWhere('child_id', $child3->id);
        $resHome = $trackingService->updateDriverLocation(
            $subTrip->id,
            (float) $child3Stop->lat,
            (float) $child3Stop->lng,
            10.0
        );
        $this->assertEquals('success', $resHome['status']);
        $homeAlert = collect($resHome['proximity']['alerts'])->firstWhere('type', 'arrived_home');
        $this->assertNotNull($homeAlert);

        // السائق يصل إلى المدرسة (أقل من 80 متر)
        $schoolStop = $subStops->firstWhere('stop_type', TripStop::TYPE_SCHOOL);
        $resSchool = $trackingService->updateDriverLocation(
            $subTrip->id,
            (float) $schoolStop->lat,
            (float) $schoolStop->lng,
            15.0
        );

        $this->assertEquals('success', $resSchool['status']);
        $schoolAlert = collect($resSchool['proximity']['alerts'])->firstWhere('type', 'arrived_school');
        $this->assertNotNull($schoolAlert);

        // 7. اختبار استجابة API للسائق البديل: ظهور الأطفال العالقين والمنتظرين + ترتيب المدارس
        \Laravel\Sanctum\Sanctum::actingAs($this->substituteDriver1->user);

        // أ) GET /api/v1/driver/trips/{tripId} (تفاصيل الرحلة والطلاب)
        $resTripDetails = $this->getJson("/api/v1/driver/trips/{$subTrip->id}");
        $resTripDetails->assertStatus(200);
        $childrenData = $resTripDetails->json('data.children');
        $childIdsInResponse = collect($childrenData)->pluck('child_id')->all();

        // يجب أن يظهر طفل البديل الأصلي + أطفال الإنقاذ (الصاعدين والمنتظرين الذين لم يركبوا بعد)
        $this->assertContains($subChild->id, $childIdsInResponse);
        $this->assertContains($child3->id, $childIdsInResponse);
        $this->assertContains($child4->id, $childIdsInResponse);
        $this->assertContains($child5->id, $childIdsInResponse);

        // ب) GET /api/v1/driver/trips/{tripId}/stops (محطات المسار وترتيب المدارس بعد نقاط الالتقاط)
        $resStops = $this->getJson("/api/v1/driver/trips/{$subTrip->id}/stops");
        $resStops->assertStatus(200);
        $stopsList = $resStops->json('data.stops');

        $maxHomeSeq = collect($stopsList)->where('stop_type', 'home')->max('sequence_order');
        $minSchoolSeq = collect($stopsList)->where('stop_type', 'school')->min('sequence_order');

        // جميع محطات المنازل والالتقاط تسبق جميع محطات المدارس
        $this->assertGreaterThan($maxHomeSeq, $minSchoolSeq);

        // محطات الأطفال المنتظرين تحتوي على اسم مدرستهم ومعرف المدرسة
        $stopForChild3 = collect($stopsList)->firstWhere('child_id', $child3->id);
        $this->assertNotNull($stopForChild3);
        $this->assertEquals($this->school->id, $stopForChild3['school_id']);
        $this->assertNotNull($stopForChild3['school_name']);
    }

    /**
     * اختبار ظهور الرحلة المعطلة في سجل رحلات السائق الرئيسي (History) بعد قبول السائق البديل
     */
    public function test_original_driver_history_shows_broken_down_trip_after_substitute_driver_accepts()
    {
        // 1. تشغيل بلاغ العطل للرحلة
        $service = app(EmergencyBreakdownService::class);
        $reasonText = 'تعطل ناقل الحركة وتوقف الحافلة بالكامل';
        $reportResult = $service->reportBreakdown(
            $this->trip,
            32.88500000,
            13.18500000,
            $reasonText
        );

        $dispatchId = $reportResult['dispatch_id'];
        $this->assertNotNull($dispatchId);

        // 2. قبول السائق البديل للطلب
        $acceptResponse = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/accept");

        $acceptResponse->assertStatus(200);

        // 3. التحقق من تثبيت تاريخ انتهاء الرحلة الأصلية
        $this->trip->refresh();
        $this->assertEquals('suspended_breakdown', $this->trip->status);
        $this->assertNotNull($this->trip->completed_at);

        // 4. فحص ظهور الرحلة في سجل رحلات السائق الرئيسي (GET /api/v1/driver/trips/history)
        $historyResponse = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->getJson('/api/v1/driver/trips/history');

        $historyResponse->assertStatus(200);
        $historyResponse->assertJson(['status' => 'success']);

        $historyItems = $historyResponse->json('data');
        $this->assertNotEmpty($historyItems);

        $tripInHistory = collect($historyItems)->firstWhere('trip_id', $this->trip->id);
        $this->assertNotNull($tripInHistory, 'الرحلة المعطلة لم تظهر في سجل رحلات السائق الرئيسي!');

        $this->assertEquals('suspended_breakdown', $tripInHistory['status']);
        $this->assertTrue($tripInHistory['is_breakdown']);
        $this->assertEquals('رحلة معطلة', $tripInHistory['status_text']);
        $this->assertEquals('معطلة', $tripInHistory['status_label']);
        $this->assertEquals($reasonText, $tripInHistory['suspension_reason']);
        $this->assertNotNull($tripInHistory['actual_completed_at']);
        $this->assertNotNull($tripInHistory['substitute_driver']);
        $this->assertEquals($this->substituteDriver1->id, $tripInHistory['substitute_driver']['id']);
        $this->assertEquals($this->substituteDriverUser1->full_name, $tripInHistory['substitute_driver']['name']);

        // 5. فحص فلترة السجل بالحالة معطلة (?status=breakdown أو ?status=suspended_breakdown)
        $filteredResponse = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->getJson('/api/v1/driver/trips/history?status=breakdown');
        $filteredResponse->assertStatus(200);
        $filteredItems = $filteredResponse->json('data');
        $this->assertNotEmpty($filteredItems);
        $this->assertTrue(collect($filteredItems)->contains('trip_id', $this->trip->id));

        // 6. فحص تفاصيل الرحلة السابقة عبر (GET /api/v1/driver/trips/history/{tripId})
        $detailsResponse = $this->actingAs($this->originalDriverUser, 'sanctum')
            ->getJson("/api/v1/driver/trips/history/{$this->trip->id}");

        $detailsResponse->assertStatus(200);
        $detailsResponse->assertJson([
            'status' => 'success',
            'data'   => [
                'trip_id'           => $this->trip->id,
                'status'            => 'suspended_breakdown',
                'is_breakdown'      => true,
                'status_text'       => 'رحلة معطلة',
                'status_label'      => 'معطلة',
                'suspension_reason' => $reasonText,
                'substitute_driver' => [
                    'id'   => $this->substituteDriver1->id,
                    'name' => $this->substituteDriverUser1->full_name,
                ]
            ]
        ]);
    }

    /**
     * اختبار سيناريو رحلة الإياب/العودة (Return Trip):
     * - السائق الأساسي ينقل طلاباً من المدرسة إلى المنازل وتعطلت الحافلة
     * - التأكد من ظهور هواتف أولياء الأمور لكل طفل
     * - التأكد من توجيه المسار إلى منازل الطلاب كنقاط تنزيل (Dropoff at Homes)
     */
    public function test_return_trip_breakdown_integration_and_parent_phones(): void
    {
        $service = app(EmergencyBreakdownService::class);

        // 1. إنشاء رحلة إياب (Return Trip) للسائق الأساسي
        $returnRoute = Route::create([
            'driver_id'   => $this->originalDriver->id,
            'vehicle_id'  => $this->originalVehicle->id,
            'route_name'  => 'مسار الإياب من المدرسة للمنزل',
            'route_type'  => 'Afternoon',
            'shift_slot'  => 'afternoon_return',
            'status'      => 'Active',
            'start_time'  => '13:30:00',
        ]);

        $returnTrip = Trip::create([
            'driver_id'            => $this->originalDriver->id,
            'route_id'             => $returnRoute->id,
            'trip_type'            => 'Afternoon',
            'shift_slot'           => 'afternoon_return',
            'status'               => 'in_progress',
            'start_lat'            => 32.89000000,
            'start_lng'            => 13.19000000,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        // الطفل 1 صعد الحافلة بالفعل بعد خروجه من المدرسة
        TripStop::create([
            'trip_id'        => $returnTrip->id,
            'stop_type'      => TripStop::TYPE_SCHOOL,
            'school_id'      => $this->school->id,
            'child_id'       => $this->child1->id,
            'lat'            => 32.89000000,
            'lng'            => 13.19000000,
            'label'          => 'استلام الطفل من المدرسة',
            'sequence_order' => 1,
            'status'         => TripStop::STATUS_BOARDED,
        ]);

        // الطفل 2 ما زال ينتظر في المدرسة
        TripStop::create([
            'trip_id'        => $returnTrip->id,
            'stop_type'      => TripStop::TYPE_SCHOOL,
            'school_id'      => $this->school->id,
            'child_id'       => $this->child2->id,
            'lat'            => 32.89000000,
            'lng'            => 13.19000000,
            'label'          => 'انتظار الطفل بالمدرسة',
            'sequence_order' => 2,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        // منازل الطفلين للتنزيل
        TripStop::create([
            'trip_id'        => $returnTrip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $this->child1->id,
            'lat'            => 32.88000000,
            'lng'            => 13.18000000,
            'label'          => 'منزل الطفل 1',
            'sequence_order' => 3,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        TripStop::create([
            'trip_id'        => $returnTrip->id,
            'stop_type'      => TripStop::TYPE_HOME,
            'child_id'       => $this->child2->id,
            'lat'            => 32.88200000,
            'lng'            => 13.18200000,
            'label'          => 'منزل الطفل 2',
            'sequence_order' => 4,
            'status'         => TripStop::STATUS_PENDING,
        ]);

        // 2. تسجيل عطل رحلة الإياب
        $reportResult = $service->reportBreakdown(
            $returnTrip,
            32.88500000,
            13.18500000,
            'عطل في نظام التبريد خلال رحلة العودة'
        );
        $dispatchId = $reportResult['dispatch_id'];
        $this->assertNotNull($dispatchId);

        // 3. قبول السائق البديل
        $acceptResponse = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/accept");
        $acceptResponse->assertStatus(200);

        $dispatch = TripBreakdownDispatch::find($dispatchId);
        $subTripId = $dispatch->substitute_trip_id;
        $this->assertNotNull($subTripId);

        // 4. فحص استجابة GET /api/v1/driver/trips/{tripId} للسائق البديل في رحلة العودة
        $detailsRes = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->getJson("/api/v1/driver/trips/{$subTripId}");

        $detailsRes->assertStatus(200);
        $children = $detailsRes->json('data.children');
        $this->assertNotEmpty($children);

        // التأكد من وجود رقم ولي الأمر لكل طفل
        foreach ($children as $c) {
            $this->assertNotEmpty($c['parent_phone'], "الطفل {$c['name']} ليس لديه رقم ولي أمر!");
            $this->assertEquals($this->parentUser->phone_number, $c['parent_phone']);
            $this->assertEquals('home', $c['destination_type']);
        }

        // 5. فحص استجابة محطات المسار GET /api/v1/driver/trips/{tripId}/stops
        $stopsRes = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->getJson("/api/v1/driver/trips/{$subTripId}/stops");

        $stopsRes->assertStatus(200);
        $stopsData = $stopsRes->json('data.stops');
        $this->assertEquals('return', $stopsRes->json('data.trip_direction'));

        // في رحلة العودة: محطات الالتقاط تسبق محطات التنزيل في المنازل
        $maxSchoolPickupSeq = collect($stopsData)->where('stop_type', 'school')->max('sequence_order');
        $minHomeDropoffSeq = collect($stopsData)->where('stop_type', 'home')->min('sequence_order');
        $this->assertGreaterThan($maxSchoolPickupSeq, $minHomeDropoffSeq);

        // 6. فحص تأكيد استلام طلاب العودة العالقين POST /api/v1/driver/trips/{tripId}/confirm-breakdown-transfer
        $confirmRes = $this->actingAs($this->substituteDriverUser1, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/confirm-breakdown-transfer");

        $confirmRes->assertStatus(200);
        $this->assertEquals('return', $confirmRes->json('trip_direction'));
        $confirmedList = $confirmRes->json('children');
        $this->assertNotEmpty($confirmedList);

        foreach ($confirmedList as $confChild) {
            $this->assertEquals('boarded', $confChild['status']);
            $this->assertEquals('home', $confChild['destination_type']);
            $this->assertNotEmpty($confChild['parent_phone']);
            $this->assertEquals($this->parentUser->phone_number, $confChild['parent_phone']);
        }
    }
}


