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
use App\Services\Notification\NotificationFormatter;
use App\Services\Trip\EmergencyBreakdownService;
use App\Services\Trip\TripLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ComprehensiveEmergencyAndAdminScenariosTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $parentUser1;
    protected ParentModel $parent1;
    protected User $parentUser2;
    protected ParentModel $parent2;
    protected User $origDriverUser;
    protected Driver $origDriver;
    protected Vehicle $origVehicle;
    protected Route $origRouteMorning;
    protected Route $origRouteReturn;
    protected User $subDriverUserNoTrip;
    protected Driver $subDriverNoTrip;
    protected Vehicle $subVehicleNoTrip;
    protected User $subDriverUserWithTrip;
    protected Driver $subDriverWithTrip;
    protected Vehicle $subVehicleWithTrip;
    protected School $schoolA;
    protected School $schoolB;
    protected Zone $zone;
    protected Child $child1;
    protected Child $child2;
    protected Child $child3;
    protected Child $child4;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'Admin', 'display_name' => 'مشرف النظام'],
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        PricingSetting::firstOrCreate([], [
            'base_fare'                => 15.00,
            'km_rate'                  => 1.50,
            'price_per_km_ac'          => 2.50,
            'price_per_km_non_ac'      => 2.00,
            'platform_commission_rate' => 8.00,
            'driver_split_percent'     => 85.00,
            'company_split_percent'    => 15.00,
            'is_active'                => true,
        ]);

        $muni = Municipality::firstOrCreate(['name' => 'طرابلس الكبرى']);
        $subMuni = SubMunicipality::firstOrCreate(['municipality_id' => $muni->id, 'name' => 'سوق الجمعة']);
        $this->zone = Zone::firstOrCreate(['name' => 'طريق الشط'], [
            'sub_municipality_id' => $subMuni->id,
            'is_active'           => true,
        ]);

        // 1. مشرف النظام (Admin)
        $this->adminUser = User::create([
            'full_name'     => 'مدير العمليات والطوارئ',
            'email'         => 'admin.ops.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        // 2. المدارس
        $this->schoolA = School::create([
            'name'    => 'مدرسة المستقبل النموذجية',
            'zone_id' => $this->zone->id,
            'lat'     => 32.8950,
            'lng'     => 13.1950,
            'address' => 'طرابلس - طريق الشط',
            'status'  => 'Approved',
        ]);

        $this->schoolB = School::create([
            'name'    => 'مدرسة النور الخاصة',
            'zone_id' => $this->zone->id,
            'lat'     => 32.9050,
            'lng'     => 13.2050,
            'address' => 'طرابلس - بالقرب من الجامعة',
            'status'  => 'Approved',
        ]);

        // 3. أولياء الأمور
        $this->parentUser1 = User::create([
            'full_name'     => 'فرج عبد الله',
            'email'         => 'parent1.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);
        $this->parent1 = ParentModel::find($this->parentUser1->id);
        $this->parent1->deposit(50000);

        $this->parentUser2 = User::create([
            'full_name'     => 'منير القمودي',
            'email'         => 'parent2.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);
        $this->parent2 = ParentModel::find($this->parentUser2->id);
        $this->parent2->deposit(50000);

        // عناوين المنازل
        $addr1 = Address::create(['user_id' => $this->parentUser1->id, 'label' => 'منزل فرج 1', 'lat' => 32.8750, 'lng' => 13.1750]);
        $addr2 = Address::create(['user_id' => $this->parentUser1->id, 'label' => 'منزل فرج 2', 'lat' => 32.8760, 'lng' => 13.1760]);
        $addr3 = Address::create(['user_id' => $this->parentUser2->id, 'label' => 'منزل منير 3', 'lat' => 32.8770, 'lng' => 13.1770]);
        $addr4 = Address::create(['user_id' => $this->parentUser2->id, 'label' => 'منزل منير 4', 'lat' => 32.8780, 'lng' => 13.1780]);

        // الأطفال
        $this->child1 = Child::create(['parent_id' => $this->parent1->id, 'school_id' => $this->schoolA->id, 'address_id' => $addr1->id, 'full_name' => 'إبراهيم فرج', 'birth_date' => '2016-01-01', 'gender' => 'male', 'grade' => 3, 'qr_code_token' => 'QR1']);
        $this->child2 = Child::create(['parent_id' => $this->parent1->id, 'school_id' => $this->schoolB->id, 'address_id' => $addr2->id, 'full_name' => 'عائشة فرج', 'birth_date' => '2018-05-01', 'gender' => 'female', 'grade' => 1, 'qr_code_token' => 'QR2']);
        $this->child3 = Child::create(['parent_id' => $this->parent2->id, 'school_id' => $this->schoolA->id, 'address_id' => $addr3->id, 'full_name' => 'صهيب منير', 'birth_date' => '2017-02-01', 'gender' => 'male', 'grade' => 2, 'qr_code_token' => 'QR3']);
        $this->child4 = Child::create(['parent_id' => $this->parent2->id, 'school_id' => $this->schoolB->id, 'address_id' => $addr4->id, 'full_name' => 'روان منير', 'birth_date' => '2019-03-01', 'gender' => 'female', 'grade' => 1, 'qr_code_token' => 'QR4']);

        // 4. السائق الأصلي
        $this->origDriverUser = User::create([
            'full_name'     => 'كابتن محمود الأصلي',
            'email'         => 'driver.orig.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);
        $this->origDriver = Driver::create([
            'user_id'           => $this->origDriverUser->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->toDateString(),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
            'morning_go'        => 1,
            'morning_return'    => 1,
            'current_lat'       => 32.8850,
            'current_lng'       => 13.1850,
        ]);
        $this->origDriver->deposit(50000);
        $this->origVehicle = Vehicle::create([
            'driver_id'       => $this->origDriver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هاي آس',
            'year'            => 2022,
            'color'           => 'أبيض',
            'type'            => 'van',
            'plate_number'    => 'LY-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $this->origDriver->id, 'zone_id' => $this->zone->id]);

        $this->origRouteMorning = Route::create([
            'driver_id'   => $this->origDriver->id,
            'vehicle_id'  => $this->origVehicle->id,
            'route_name'  => 'مسار صباحي طريق الشط',
            'route_type'  => 'Morning',
            'shift_slot'  => 'morning_go',
            'status'      => 'Active',
            'start_time'  => '07:00:00',
        ]);

        $this->origRouteReturn = Route::create([
            'driver_id'   => $this->origDriver->id,
            'vehicle_id'  => $this->origVehicle->id,
            'route_name'  => 'مسار إياب طريق الشط',
            'route_type'  => 'Afternoon',
            'shift_slot'  => 'afternoon_return',
            'status'      => 'Active',
            'start_time'  => '13:30:00',
        ]);

        // ربط الاشتراكات
        $this->createSubscriptionForChild($this->parent1, $this->child1, $this->origDriver, $this->origRouteMorning, $addr1, $this->schoolA);
        $this->createSubscriptionForChild($this->parent1, $this->child2, $this->origDriver, $this->origRouteMorning, $addr2, $this->schoolB);
        $this->createSubscriptionForChild($this->parent2, $this->child3, $this->origDriver, $this->origRouteMorning, $addr3, $this->schoolA);
        $this->createSubscriptionForChild($this->parent2, $this->child4, $this->origDriver, $this->origRouteMorning, $addr4, $this->schoolB);

        // 5. السائق البديل 1: ليس لديه رحلة جارية
        $this->subDriverUserNoTrip = User::create([
            'full_name'     => 'كابتن وسام البديل المتفرغ',
            'email'         => 'sub.notrip.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);
        $this->subDriverNoTrip = Driver::create([
            'user_id'           => $this->subDriverUserNoTrip->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->toDateString(),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
            'morning_go'        => 1,
            'morning_return'    => 1,
            'current_lat'       => 32.8840,
            'current_lng'       => 13.1840,
        ]);
        $this->subVehicleNoTrip = Vehicle::create([
            'driver_id'       => $this->subDriverNoTrip->id,
            'brand'           => 'نيسان',
            'model'           => 'أورفان',
            'year'            => 2023,
            'color'           => 'فضي',
            'type'            => 'van',
            'plate_number'    => 'LY-' . rand(1000, 9999),
            'capacity_manual' => 12,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $this->subDriverNoTrip->id, 'zone_id' => $this->zone->id]);
        DriverSeatSlot::create([
            'driver_id' => $this->subDriverNoTrip->id,
            'slot'      => 'morning_go',
            'date'      => Carbon::today()->toDateString(),
            'booked'    => 0, // متفرغ بالكامل
        ]);
        DriverSeatSlot::create([
            'driver_id' => $this->subDriverNoTrip->id,
            'slot'      => 'afternoon_return',
            'date'      => Carbon::today()->toDateString(),
            'booked'    => 0,
        ]);

        // 6. السائق البديل 2: لديه رحلة جارية مسبقاً وبها أطفال
        $this->subDriverUserWithTrip = User::create([
            'full_name'     => 'كابتن كمال البديل المشغول برحلة',
            'email'         => 'sub.withtrip.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);
        $this->subDriverWithTrip = Driver::create([
            'user_id'           => $this->subDriverUserWithTrip->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->toDateString(),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
            'morning_go'        => 1,
            'morning_return'    => 1,
            'current_lat'       => 32.8830,
            'current_lng'       => 13.1830,
        ]);
        $this->subVehicleWithTrip = Vehicle::create([
            'driver_id'       => $this->subDriverWithTrip->id,
            'brand'           => 'هيونداي',
            'model'           => 'H1',
            'year'            => 2021,
            'color'           => 'أسود',
            'type'            => 'van',
            'plate_number'    => 'LY-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'status'          => 'Active',
            'is_verified'     => 1,
        ]);
        DB::table('driver_zone')->insertOrIgnore(['driver_id' => $this->subDriverWithTrip->id, 'zone_id' => $this->zone->id]);
        DriverSeatSlot::create([
            'driver_id' => $this->subDriverWithTrip->id,
            'slot'      => 'morning_go',
            'date'      => Carbon::today()->toDateString(),
            'booked'    => 2, // محجوز مقعدان من 10، يتبقى 8 مقاعد
        ]);
    }

    private function createSubscriptionForChild($parent, $child, $driver, $route, $address, $school): void
    {
        $req = SubscriptionRequest::create([
            'parent_id'                   => $parent->id,
            'driver_id'                   => $driver->id,
            'status'                      => 'accepted',
            'total_price'                 => 100.00,
            'total_amount_after_discount' => 100.00,
            'children_count'              => 1,
        ]);
        $req->children()->attach([$child->id => ['total_amount_after_discount' => 100.00]]);
        $reqChildId = RequestChild::where('request_id', $req->id)->where('child_id', $child->id)->value('id');

        ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'request_child_id'        => $reqChildId,
            'route_id'                => $route->id,
            'pickup_lat'              => $address->lat,
            'pickup_lng'              => $address->lng,
            'dropoff_lat'             => $school->lat,
            'dropoff_lng'             => $school->lng,
            'status'                  => 'active',
        ]);
    }

    /**
     * سيناريو 1: السائق الأساسي في رحلة صباحية صعد 2 أطفال في السيارة وما زال 2 في الانتظار بالمنزل
     * والسائق البديل ليس لديه رحلة جارية (No active trip).
     * فحص:
     * - إنشاء بلاغ العطل
     * - قبول السائق البديل وإنشاء رحلة إنقاذ خاصة به
     * - المحطات: السيارة المتوقفة للطفلين الصاعدين + منازل الطفلين المنتظرين + مدرستي الأطفال مرتبة بالأقرب
     * - تنفيذ صعود أطفال السيارة المتوقفة عبر confirm-breakdown-transfer
     * - تنفيذ صعود طفل من المنزل
     * - تنفيذ النزول بالمدرسة لكلا النوعين من الأطفال
     * - إشعار المشرف والأهالي
     * - إمكانية إنهاء الرحلة بنجاح والتسوية المالية
     */
    public function test_morning_emergency_partial_boarded_substitute_without_trip_and_school_deliveries(): void
    {
        // 1. إنشاء رحلة السائق الأساسي
        $trip = Trip::create([
            'driver_id'            => $this->origDriver->id,
            'route_id'             => $this->origRouteMorning->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.8800,
            'start_lng'            => 13.1800,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        // الطفل 1 و 2 صعدا في الحافلة (boarded)
        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => 32.8750, 'lng' => 13.1750, 'label' => 'منزل 1']);
        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child2->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 2, 'status' => TripStop::STATUS_BOARDED, 'lat' => 32.8760, 'lng' => 13.1760, 'label' => 'منزل 2']);
        // الطفل 3 و 4 ما زالا ينتظران في المنزل (pending)
        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child3->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING, 'lat' => 32.8770, 'lng' => 13.1770, 'label' => 'منزل 3']);
        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child4->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 4, 'status' => TripStop::STATUS_PENDING, 'lat' => 32.8780, 'lng' => 13.1780, 'label' => 'منزل 4']);
        // المدارس
        TripStop::create(['trip_id' => $trip->id, 'school_id' => $this->schoolA->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 5, 'status' => TripStop::STATUS_PENDING, 'lat' => $this->schoolA->lat, 'lng' => $this->schoolA->lng, 'label' => $this->schoolA->name]);
        TripStop::create(['trip_id' => $trip->id, 'school_id' => $this->schoolB->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 6, 'status' => TripStop::STATUS_PENDING, 'lat' => $this->schoolB->lat, 'lng' => $this->schoolB->lng, 'label' => $this->schoolB->name]);

        // 2. إبلاغ السائق الأصلي عن العطل عبر API
        $response = $this->actingAs($this->origDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$trip->id}/report-breakdown", [
                'latitude'  => 32.8850,
                'longitude' => 13.1850,
                'reason'    => 'عطل في الإطارات وتوقف الحافلة',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'                  => 'broadcasted',
            'stranded_children_count' => 4,
        ]);

        $dispatchId = $response->json('dispatch_id');
        $this->assertNotNull($dispatchId);

        // التحقق من إشعار مشرف النظام (Admin)
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
        ]);

        // 3. قبول السائق البديل (الذي ليس لديه رحلة)
        $acceptRes = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/emergency-dispatches/{$dispatchId}/accept");

        $acceptRes->assertStatus(200);
        $subTripId = $acceptRes->json('substitute_trip_id');
        $this->assertNotNull($subTripId);

        // تم إنشاء رحلة بديلة بحالة in_progress
        $subTrip = Trip::findOrFail($subTripId);
        $this->assertEquals($this->subDriverNoTrip->id, $subTrip->driver_id);
        $this->assertEquals('in_progress', $subTrip->status);

        // 4. التحقق من محطات رحلة البديل وترتيبها
        $subStops = TripStop::where('trip_id', $subTripId)->orderBy('sequence_order')->get();

        // المحطة الأولى: استلام الطفلين الصاعدين من السيارة المتوقفة
        $firstStop = $subStops->first();
        $this->assertEquals('السيارة المتوقفة (استلام الطلاب)', $firstStop->label);
        $reasonData = json_decode($firstStop->reason, true);
        $this->assertEquals([$this->child1->id, $this->child2->id], $reasonData['boarded_child_ids']);

        // محطات منازل الطفلين المنتظرين (3 و 4)
        $homeStopsWaiting = $subStops->where('stop_type', TripStop::TYPE_HOME)->whereNotNull('child_id')->values();
        $this->assertCount(2, $homeStopsWaiting);
        $this->assertEquals([$this->child3->id, $this->child4->id], $homeStopsWaiting->pluck('child_id')->all());

        // محطات المدارس تلي جميع نقاط الالتقاط
        $schoolStops = $subStops->where('stop_type', TripStop::TYPE_SCHOOL)->values();
        $this->assertCount(2, $schoolStops);
        $maxHomeSeq = $subStops->where('stop_type', TripStop::TYPE_HOME)->max('sequence_order');
        $minSchoolSeq = $subStops->where('stop_type', TripStop::TYPE_SCHOOL)->min('sequence_order');
        $this->assertGreaterThan($maxHomeSeq, $minSchoolSeq);

        // 5. تأكيد استلام أطفال الحافلة المعطلة (1 و 2)
        $confirmTransferRes = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/confirm-breakdown-transfer", [
                'latitude'  => 32.8850,
                'longitude' => 13.1850,
            ]);
        $confirmTransferRes->assertStatus(200);
        $this->assertEquals(2, $confirmTransferRes->json('transferred_children_count'));

        // 6. التقاط الطفل 3 من منزله
        $pickup3Res = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/children/{$this->child3->id}/status", [
                'action'    => 'pickup',
                'latitude'  => 32.8770,
                'longitude' => 13.1770,
            ]);
        $pickup3Res->assertStatus(200);

        // تسجيل غياب الطفل 4
        $absent4Res = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/children/{$this->child4->id}/status", [
                'action' => 'absent',
            ]);
        $absent4Res->assertStatus(200);

        // 7. الوصول للمدرسة A وتنزيل الطفل 1 والطفل 3
        $dropoff1Res = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/children/{$this->child1->id}/status", [
                'action'    => 'dropoff',
                'latitude'  => $this->schoolA->lat,
                'longitude' => $this->schoolA->lng,
            ]);
        $dropoff1Res->assertStatus(200);

        $dropoff3Res = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/children/{$this->child3->id}/status", [
                'action'    => 'dropoff',
                'latitude'  => $this->schoolA->lat,
                'longitude' => $this->schoolA->lng,
            ]);
        $dropoff3Res->assertStatus(200);

        // 8. الوصول للمدرسة B وتنزيل الطفل 2
        $dropoff2Res = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/children/{$this->child2->id}/status", [
                'action'    => 'dropoff',
                'latitude'  => $this->schoolB->lat,
                'longitude' => $this->schoolB->lng,
            ]);
        $dropoff2Res->assertStatus(200);

        // 9. إنهاء رحلة الإنقاذ بنجاح وفحص التسوية المالية التلقائية
        $completeRes = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/complete");

        $completeRes->assertStatus(200);
        $subTrip->refresh();
        $this->assertEquals('completed', $subTrip->status);

        $dispatch = TripBreakdownDispatch::find($dispatchId);
        $this->assertEquals(TripBreakdownDispatch::STATUS_COMPLETED, $dispatch->status);
        $this->assertTrue((bool) $dispatch->financial_settled);
    }

    /**
     * سيناريو 2: السائق البديل لديه رحلة جارية مسبقاً (With Active Trip)
     * فحص:
     * - دمج المحطات في رحلته القائمة دون إنشاء رحلة مكررة
     * - فحص السعة المتاحة
     * - دمج الأطفال الأصليين مع أطفال الإنقاذ
     */
    public function test_emergency_substitute_already_has_active_trip_merges_stops_and_checks_capacity(): void
    {
        // 1. إنشاء رحلة جارية للسائق البديل 2 وبها طفل خاص به
        $subOwnChild = Child::create([
            'parent_id'     => $this->parent1->id,
            'school_id'     => $this->schoolA->id,
            'address_id'    => Address::create(['user_id' => $this->parentUser1->id, 'label' => 'منزل طفل البديل', 'lat' => 32.8800, 'lng' => 13.1800])->id,
            'full_name'     => 'طفل السائق البديل الخاص',
            'birth_date'    => '2016-01-01',
            'gender'        => 'male',
            'grade'         => 3,
            'qr_code_token' => 'QR_SUB_OWN',
        ]);

        $subTrip = Trip::create([
            'driver_id'            => $this->subDriverWithTrip->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.8830,
            'start_lng'            => 13.1830,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        TripStop::create(['trip_id' => $subTrip->id, 'child_id' => $subOwnChild->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 1, 'status' => TripStop::STATUS_PENDING, 'lat' => 32.8800, 'lng' => 13.1800, 'label' => 'منزل طفل البديل']);
        TripStop::create(['trip_id' => $subTrip->id, 'school_id' => $this->schoolA->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 2, 'status' => TripStop::STATUS_PENDING, 'lat' => $this->schoolA->lat, 'lng' => $this->schoolA->lng, 'label' => $this->schoolA->name]);

        // 2. إنشاء رحلة السائق الأصلي وبها طفلان عالقان في الحافلة
        $origTrip = Trip::create([
            'driver_id'            => $this->origDriver->id,
            'route_id'             => $this->origRouteMorning->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.8850,
            'start_lng'            => 13.1850,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        TripStop::create(['trip_id' => $origTrip->id, 'child_id' => $this->child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => 32.8750, 'lng' => 13.1750, 'label' => 'منزل 1']);
        TripStop::create(['trip_id' => $origTrip->id, 'child_id' => $this->child2->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 2, 'status' => TripStop::STATUS_BOARDED, 'lat' => 32.8760, 'lng' => 13.1760, 'label' => 'منزل 2']);
        TripStop::create(['trip_id' => $origTrip->id, 'school_id' => $this->schoolA->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING, 'lat' => $this->schoolA->lat, 'lng' => $this->schoolA->lng, 'label' => $this->schoolA->name]);

        // 3. الإبلاغ عن العطل
        $service = app(EmergencyBreakdownService::class);
        $result = $service->reportBreakdown($origTrip, 32.8850, 13.1850, 'عطل مفاجئ أثناء الرحلة');
        $dispatchId = $result['dispatch_id'];

        // 4. قبول السائق البديل 2 المشغول
        $acceptRes = $service->acceptBreakdownDispatch($dispatchId, $this->subDriverWithTrip->id);
        $this->assertEquals('success', $acceptRes['status']);
        // يجب أن تكون نفس رحلته القائمة subTrip->id وليست رحلة جديدة!
        $this->assertEquals($subTrip->id, $acceptRes['substitute_trip_id']);

        // 5. فحص المحطات المدمجة
        $allStops = TripStop::where('trip_id', $subTrip->id)->orderBy('sequence_order')->get();
        // المحطات تشمل: طفل البديل الأصلي + السيارة المتوقفة للطفلين العالقين + المدارس
        $this->assertTrue($allStops->contains('child_id', $subOwnChild->id));
        $this->assertTrue($allStops->contains(fn($s) => str_contains($s->label ?? '', 'السيارة المتوقفة')));
        $this->assertTrue($allStops->contains('school_id', $this->schoolA->id));
        $this->assertTrue($allStops->contains('school_id', $this->schoolB->id));
    }

    /**
     * سيناريو 3: رحلة العودة / الإياب (Afternoon / Return Trip)
     * - الأطفال صعدوا من المدرسة وتعطلت الحافلة في طريق العودة إلى المنازل
     * - دمج المسار وتنزيل الأطفال في منازلهم (Home dropoff)
     * - اختبار دالة النزول بالمنزل والتأكد من نجاحها وإرسال الإشعارات
     */
    public function test_return_trip_emergency_breakdown_and_home_deliveries(): void
    {
        // 1. إنشاء رحلة إياب للسائق الأصلي
        $returnTrip = Trip::create([
            'driver_id'            => $this->origDriver->id,
            'route_id'             => $this->origRouteReturn->id,
            'trip_type'            => 'Afternoon',
            'shift_slot'           => 'afternoon_return',
            'status'               => 'in_progress',
            'start_lat'            => $this->schoolA->lat,
            'start_lng'            => $this->schoolA->lng,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        // الطفل 1 صعد من المدرسة وهو بالحافلة
        TripStop::create(['trip_id' => $returnTrip->id, 'school_id' => $this->schoolA->id, 'child_id' => $this->child1->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => $this->schoolA->lat, 'lng' => $this->schoolA->lng, 'label' => 'استلام من المدرسة']);
        // منزل الطفل 1 للتنزيل
        TripStop::create(['trip_id' => $returnTrip->id, 'child_id' => $this->child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 2, 'status' => TripStop::STATUS_PENDING, 'lat' => 32.8750, 'lng' => 13.1750, 'label' => 'منزل الطفل 1']);

        // 2. الإبلاغ عن عطل
        $service = app(EmergencyBreakdownService::class);
        $result = $service->reportBreakdown($returnTrip, 32.8850, 13.1850, 'عطل في رحلة العودة');
        $dispatchId = $result['dispatch_id'];

        // 3. قبول السائق البديل
        $acceptRes = $service->acceptBreakdownDispatch($dispatchId, $this->subDriverNoTrip->id);
        $subTripId = $acceptRes['substitute_trip_id'];

        // 4. تأكيد استلام طلاب العودة العالقين
        $confirmRes = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/confirm-breakdown-transfer", [
                'latitude'  => 32.8850,
                'longitude' => 13.1850,
            ]);
        $confirmRes->assertStatus(200);
        $this->assertEquals('return', $confirmRes->json('trip_direction'));

        // 5. توصيل الطفل لمنزله وتأكيد النزول (Dropoff at Home)
        $dropoffRes = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/children/{$this->child1->id}/status", [
                'action'    => 'dropoff',
                'latitude'  => 32.8750,
                'longitude' => 13.1750,
            ]);

        $dropoffRes->assertStatus(200);
        $dropoffRes->assertJson([
            'status' => 'success',
            'child'  => [
                'child_id' => $this->child1->id,
                'status'   => TripStop::STATUS_DELIVERED_HOME,
            ],
        ]);

        // التحقق من وصول إشعار التوصيل لولي الأمر
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->parentUser1->id,
        ]);

        // 6. إنهاء رحلة العودة بنجاح
        $completeRes = $this->actingAs($this->subDriverUserNoTrip, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$subTripId}/complete");
        $completeRes->assertStatus(200);
    }

    /**
     * سيناريو 4: تعذر العثور على سائق بديل (No substitutes available / Fallback)
     * فحص:
     * - إشعار السائق الأصلي بالاتصال بأولياء الأمور
     * - إشعار أولياء الأمور بموقع الأبناء ورابط الخريطة للاستلام
     * - إشعار مشرف النظام بحالة طوارئ حرجة غير محلولة (Unresolved alert)
     * - ظهور الحالة بوضوح للوحة تحكم الأدمن
     */
    public function test_no_substitute_available_notifies_driver_parents_and_admin(): void
    {
        // تعليق السائقين البدلاء لمحاكاة عدم توفر أي بديل
        Driver::where('id', '!=', $this->origDriver->id)->update(['status' => 'Suspended']);

        $trip = Trip::create([
            'driver_id'            => $this->origDriver->id,
            'route_id'             => $this->origRouteMorning->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.8850,
            'start_lng'            => 13.1850,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => 32.8750, 'lng' => 13.1750, 'label' => 'منزل 1']);

        $response = $this->actingAs($this->origDriverUser, 'sanctum')
            ->postJson("/api/v1/driver/trips/{$trip->id}/report-breakdown", [
                'latitude'  => 32.8850,
                'longitude' => 13.1850,
                'reason'    => 'عطل كامل وتعذر توفير بديل',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'no_substitutes_available',
        ]);

        $dispatchId = $response->json('dispatch_id');
        $dispatch = TripBreakdownDispatch::find($dispatchId);
        $this->assertEquals(TripBreakdownDispatch::STATUS_UNRESOLVED, $dispatch->status);

        // إشعار السائق بالتواصل
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->origDriverUser->id,
        ]);

        // إشعار ولي الأمر
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->parentUser1->id,
        ]);

        // إشعار المشرف بحالة حرجة غير محلولة
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
        ]);
    }

    /**
     * سيناريو 5: فحص شاشات واستجابات الأدمن في حالة الطوارئ (Admin Radar & Emergency Endpoints)
     * فحص:
     * 1. GET /api/admin/dashboard/active-trips (الرادار الحي يشمل الرحلة المعطلة مع تفاصيلها)
     * 2. GET /api/admin/trips/emergencies (قائمة البلاغات مع الإحصائيات)
     * 3. GET /api/admin/trips/emergencies/{id} (تفاصيل 360 درجة تشمل الرحلة، السائقين، الأطفال، أرقام الأهالي)
     * 4. POST /api/admin/trips/emergencies/{id}/assign-substitute (تدخل الأدمن اليدوي لتكليف بديل)
     * 5. POST /api/admin/trips/emergencies/{id}/resolve (إغلاق البلاغ يدوياً)
     */
    public function test_admin_radar_and_emergency_management_endpoints(): void
    {
        // 1. إنشاء رحلة وإبلاغ عن عطل
        $trip = Trip::create([
            'driver_id'            => $this->origDriver->id,
            'route_id'             => $this->origRouteMorning->id,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => 'in_progress',
            'start_lat'            => 32.8850,
            'start_lng'            => 13.1850,
            'scheduled_start_time' => now(),
            'actual_start_time'    => now(),
            'trip_date'            => Carbon::today()->toDateString(),
        ]);

        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child1->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 1, 'status' => TripStop::STATUS_BOARDED, 'lat' => 32.8750, 'lng' => 13.1750, 'label' => 'منزل 1']);
        TripStop::create(['trip_id' => $trip->id, 'child_id' => $this->child2->id, 'stop_type' => TripStop::TYPE_HOME, 'sequence_order' => 2, 'status' => TripStop::STATUS_PENDING, 'lat' => 32.8760, 'lng' => 13.1760, 'label' => 'منزل 2']);
        TripStop::create(['trip_id' => $trip->id, 'school_id' => $this->schoolA->id, 'stop_type' => TripStop::TYPE_SCHOOL, 'sequence_order' => 3, 'status' => TripStop::STATUS_PENDING, 'lat' => $this->schoolA->lat, 'lng' => $this->schoolA->lng, 'label' => $this->schoolA->name]);

        $service = app(EmergencyBreakdownService::class);
        $result = $service->reportBreakdown($trip, 32.8850, 13.1850, 'عطل مفاجئ في المحرك');
        $dispatchId = $result['dispatch_id'];

        // أ) فحص الرادار الحي للأدمن GET /api/admin/dashboard/active-trips
        $radarRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/dashboard/active-trips');

        $radarRes->assertStatus(200);
        $radarData = $radarRes->json('data');
        $this->assertNotEmpty($radarData);
        $emergencyTripInRadar = collect($radarData)->firstWhere('trip_id', $trip->id);
        $this->assertNotNull($emergencyTripInRadar, 'الرحلة المعطلة لم تظهر في رادار الأدمن الحي!');
        $this->assertTrue($emergencyTripInRadar['is_emergency']);
        $this->assertEquals('suspended_breakdown', $emergencyTripInRadar['status_key']);
        $this->assertNotNull($emergencyTripInRadar['emergency_details']);
        $this->assertEquals($dispatchId, $emergencyTripInRadar['emergency_details']['dispatch_id']);

        // ب) فحص قائمة البلاغات للأدمن GET /api/admin/trips/emergencies
        $listRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/trips/emergencies');

        $listRes->assertStatus(200);
        $listRes->assertJsonStructure([
            'status',
            'stats' => ['total_emergencies', 'active_now', 'unresolved_count', 'completed_count'],
            'data'  => [
                '*' => ['dispatch_id', 'trip_id', 'status', 'status_label', 'reason', 'stranded_children_count', 'original_driver', 'breakdown_location']
            ]
        ]);

        // ج) فحص التفاصيل الشاملة 360 درجة GET /api/admin/trips/emergencies/{dispatchId}
        $detailRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/trips/emergencies/{$dispatchId}");

        $detailRes->assertStatus(200);
        $detailRes->assertJson([
            'status' => 'success',
            'data'   => [
                'dispatch_id'      => $dispatchId,
                'reason'           => 'عطل مفاجئ في المحرك',
                'original_driver'  => [
                    'id'   => $this->origDriver->id,
                    'name' => $this->origDriverUser->full_name,
                ],
                'children_summary' => [
                    'total_stranded_count'    => 2,
                    'boarded_in_bus_count'    => 1,
                    'waiting_at_pickup_count' => 1,
                ]
            ]
        ]);

        // التحقق من ظهور أرقام هواتف أولياء الأمور والمدارس في التفاصيل
        $childrenInDetail = $detailRes->json('data.children_details');
        $this->assertCount(2, $childrenInDetail);
        foreach ($childrenInDetail as $cItem) {
            $this->assertNotEmpty($cItem['parent']['phone']);
            $this->assertNotEmpty($cItem['school']['name']);
        }

        // د) قيام الأدمن بتكليف سائق بديل يدوياً POST /api/admin/trips/emergencies/{dispatchId}/assign-substitute
        $assignRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/admin/trips/emergencies/{$dispatchId}/assign-substitute", [
                'substitute_driver_id' => $this->subDriverNoTrip->id,
            ]);

        $assignRes->assertStatus(200);
        $assignRes->assertJson([
            'status'            => 'success',
            'substitute_driver' => [
                'id' => $this->subDriverNoTrip->id,
            ]
        ]);

        $dispatch = TripBreakdownDispatch::find($dispatchId);
        $this->assertEquals(TripBreakdownDispatch::STATUS_ACCEPTED, $dispatch->status);
        $this->assertEquals($this->subDriverNoTrip->id, $dispatch->substitute_driver_id);

        // هـ) قيام الأدمن بإغلاق البلاغ يدوياً POST /api/admin/trips/emergencies/{dispatchId}/resolve
        $resolveRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/admin/trips/emergencies/{$dispatchId}/resolve");

        $resolveRes->assertStatus(200);
        $dispatch->refresh();
        $this->assertEquals(TripBreakdownDispatch::STATUS_COMPLETED, $dispatch->status);
    }
}
