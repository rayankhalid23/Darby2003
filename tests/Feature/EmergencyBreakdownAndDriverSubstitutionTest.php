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
        // وجود محطة الالتقاط ومحطات الأطفال العالقين
        $this->assertTrue($subTripStops->contains('lat', 32.88500000));
        $this->assertTrue($subTripStops->contains('child_id', $this->child1->id));
        $this->assertTrue($subTripStops->contains('child_id', $this->child2->id));

        // 5. التحقق من إشعار الإلغاء للسائق الثاني
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->substituteDriverUser2->id,
        ]);

        // 6. التحقق من إشعار ولي الأمر ببيانات السائق البديل
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->parentUser->id,
        ]);

        // 7. محاولة سائق آخر القبول بعد حسمها -> يجب أن تفشل بخطأ 409
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
}
