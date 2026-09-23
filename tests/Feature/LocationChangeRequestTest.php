<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Parent\Address;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\RequestChild;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\LocationChangeRequest;
use App\Models\Shared\PricingSetting;
use Carbon\Carbon;

/**
 * اختبار شامل لسيناريو المستخدم الحقيقي لميزة "تغيير عنوان الاستلام/التسليم ليوم واحد":
 * ولي الأمر يفتح شاشة الخيارات -> يختار طفلاً ورحلة في تاريخ معين -> يعاين السعر
 * المُجمَّع حسب السائق -> يرسل الطلب -> السائق يوافق/يرفض -> يتحدّث مسار رحلة اليوم
 * فقط (لا الاشتراك ولا المسار المرجعي) وتُحصَّل الرسوم من محفظة ولي الأمر.
 */
class LocationChangeRequestTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected Vehicle $vehicle;
    protected User $parentUser;
    protected ParentModel $parent;
    protected Child $child;
    protected School $school;
    protected Address $address;
    protected Route $route;
    protected SubscriptionRequest $subReq;
    protected ActiveSubscription $activeSub;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        PricingSetting::firstOrCreate([], [
            'price_per_km_ac'     => 2.50,
            'price_per_km_non_ac' => 2.00,
        ]);

        $this->driverUser = User::create([
            'full_name' => 'سائق الاختبار', 'email' => 'driver.loc.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999), 'password_hash' => bcrypt('password123'),
            'role_id' => 2, 'is_active' => 1,
        ]);
        $this->driver = Driver::create([
            'user_id' => $this->driverUser->id, 'national_id' => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999), 'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status' => 'Approved',
        ]);

        $this->parentUser = User::create([
            'full_name' => 'ولي أمر الاختبار', 'email' => 'parent.loc.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999), 'password_hash' => bcrypt('password123'),
            'role_id' => 3, 'is_active' => 1, 'is_trusted' => 1,
        ]);
        $this->parent = ParentModel::find($this->parentUser->id);

        $this->school = School::create([
            'name' => 'مدرسة الاختبار', 'address' => 'شارع الاختبار', 'lat' => 32.90, 'lng' => 13.20, 'status' => 'Approved',
        ]);

        $this->child = Child::create([
            'parent_id' => $this->parent->id, 'school_id' => $this->school->id, 'full_name' => 'طفل الاختبار',
            'birth_date' => '2018-05-10', 'gender' => 'male', 'grade' => 1, 'notification_radius' => 500,
        ]);

        // الموقع الجديد المحفوظ الذي سيطلب ولي الأمر التغيير إليه
        $this->address = Address::create([
            'user_id' => $this->parentUser->id, 'label' => 'منزل الجدة', 'lat' => 32.95, 'lng' => 13.25,
        ]);

        $this->vehicle = Vehicle::create([
            'driver_id' => $this->driver->id, 'plate_number' => '5-' . rand(1000, 9999),
            'brand' => 'Toyota', 'model' => 'Hiace', 'year' => 2022, 'color' => 'White',
            'type' => 'Van', 'capacity_manual' => 14, 'is_verified' => 1, 'status' => 'Active',
        ]);

        $this->subReq = SubscriptionRequest::create([
            'parent_id' => $this->parent->id, 'driver_id' => $this->driver->id,
            'total_price' => 100, 'total_amount_after_discount' => 100, 'status' => SubscriptionRequest::STATUS_ACCEPTED,
            'children_count' => 1,
        ]);
        // child_id/driver_id على active_subscriptions أعمدة مشتقة الآن (V2) عبر
        // request_child_id/subscription_request_id — لازم نمرّ عبر pivot الطلب.
        $this->subReq->children()->attach($this->child->id, ['total_amount_after_discount' => 100.00]);
        $requestChild = RequestChild::where('request_id', $this->subReq->id)->where('child_id', $this->child->id)->first();

        $this->route = Route::create([
            'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id, 'subscription_request_id' => $this->subReq->id,
            'route_name' => 'مسار الاختبار', 'route_type' => 'Morning', 'shift_slot' => 'morning_go',
            'start_time' => '07:00:00', 'status' => 'Active',
        ]);

        $this->activeSub = ActiveSubscription::create([
            'subscription_request_id' => $this->subReq->id, 'request_child_id' => $requestChild->id,
            'route_id' => $this->route->id, 'status' => 'active',
            'pickup_lat' => 32.88, 'pickup_lng' => 13.19, 'pickup_label' => 'المنزل', 'pickup_time' => '07:00:00',
            'dropoff_lat' => 32.90, 'dropoff_lng' => 13.20, 'dropoff_label' => 'المدرسة', 'dropoff_time' => '14:00:00',
        ]);

        RouteStop::create([
            'route_id' => $this->route->id, 'stop_type' => RouteStop::TYPE_HOME, 'child_id' => $this->child->id,
            'lat' => 32.88, 'lng' => 13.19, 'label' => 'المنزل', 'sequence_order' => 1,
        ]);
        RouteStop::create([
            'route_id' => $this->route->id, 'stop_type' => RouteStop::TYPE_SCHOOL, 'school_id' => $this->school->id,
            'lat' => 32.90, 'lng' => 13.20, 'label' => 'المدرسة', 'sequence_order' => 2,
        ]);
    }

    private function setTierFees(float $under2, float $twoToSix, float $sixToTen, float $commissionRate = 8.00): void
    {
        PricingSetting::query()->update([
            'location_change_fee_under_2km' => $under2,
            'location_change_fee_2_to_6km'  => $twoToSix,
            'location_change_fee_6_to_10km' => $sixToTen,
            'platform_commission_rate'      => $commissionRate,
        ]);
    }

    /**
     * ينشئ عنواناً محفوظاً لولي الأمر على بُعد مسافة محددة (كم) شمال نقطة الاستلام الحالية.
     * درجة خط عرض واحدة = 111.19492664455873 كم في صيغة Haversine المستخدمة في النظام.
     */
    private function addressAtDistance(float $km, string $label = 'موقع اختبار'): Address
    {
        return Address::create([
            'user_id' => $this->parentUser->id,
            'label'   => $label,
            'lat'     => 32.88 + ($km / 111.19492664455873),
            'lng'     => 13.19,
        ]);
    }

    public function test_parent_can_fetch_change_options(): void
    {
        $response = $this->actingAs($this->parentUser)
            ->getJson('/api/parent/location-change-requests/options');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.children.0.id', $this->child->id);
        $response->assertJsonPath('data.children.0.has_active_subscription', true);
        $response->assertJsonPath('data.addresses.0.id', $this->address->id);
    }

    public function test_parent_can_fetch_available_trips_for_a_child_on_a_date(): void
    {
        $targetDate = now()->addDays(2)->toDateString();

        $response = $this->actingAs($this->parentUser)
            ->postJson('/api/parent/location-change-requests/available-trips', [
                'child_ids' => [$this->child->id],
                'date'      => $targetDate,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.children.0.trips.0.active_subscription_id', $this->activeSub->id);
        $response->assertJsonPath('data.children.0.trips.0.direction', 'to_school');
        $response->assertJsonPath('data.children.0.trips.0.is_editable', true);
    }

    public function test_preview_returns_grouped_pricing_without_creating_a_request(): void
    {
        $this->setTierFees(5.00, 10.00, 15.00);
        $before = LocationChangeRequest::count();

        $response = $this->actingAs($this->parentUser)
            ->postJson('/api/parent/location-change-requests/preview', [
                'point_type'  => 'pickup',
                'date'        => now()->addDays(2)->toDateString(),
                'selections'  => [
                    ['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]],
                ],
                'address_id'  => $this->address->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        // العنوان الجديد يبعد 9.59 كم عن نقطة الاستلام الحالية (32.88,13.19) => الشريحة الثالثة
        $this->assertEqualsWithDelta(9.59, $response->json('data.grouped_requests.0.distance_km'), 0.01);
        $response->assertJsonPath('data.grouped_requests.0.fee_tier', '6_to_10km');
        $this->assertEqualsWithDelta(15.0, $response->json('data.grouped_requests.0.fee_breakdown.gross_fee'), 0.001);
        $response->assertJsonPath('data.summary.requests_count', 1);

        // المعاينة لا تنشئ أي سجل
        $this->assertSame($before, LocationChangeRequest::count());
    }

    public function test_parent_can_request_single_day_location_change_and_driver_receives_it(): void
    {
        $this->setTierFees(5.00, 10.00, 15.00);
        $targetDate = now()->addDays(2)->toDateString();

        $response = $this->actingAs($this->parentUser)
            ->postJson('/api/parent/location-change-requests', [
                'point_type' => 'pickup',
                'date'       => $targetDate,
                'selections' => [
                    ['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]],
                ],
                'address_id' => $this->address->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertEqualsWithDelta(15.0, $response->json('data.created_requests.0.fee_amount'), 0.001);
        $response->assertJsonPath('data.created_requests.0.driver.id', $this->driver->id);

        $this->assertDatabaseHas('location_change_requests', [
            'driver_id'      => $this->driver->id,
            'parent_id'      => $this->parentUser->id,
            'point_type'     => 'pickup',
            'change_date'    => $targetDate,
            'is_single_day'  => 1,
            'distance_km'    => 9.59,
            'fee_tier'       => '6_to_10km',
            'fee_amount'     => 15.00,
            'status'         => LocationChangeRequest::STATUS_PENDING,
            'new_address_id' => $this->address->id,
        ]);

        $requestId = $response->json('data.created_requests.0.id');
        $this->assertDatabaseHas('location_change_request_children', [
            'location_change_request_id' => $requestId,
            'child_id'                   => $this->child->id,
            'active_subscription_id'     => $this->activeSub->id,
        ]);

        // السائق يرى الطلب في قائمته
        $driverList = $this->actingAs($this->driverUser)
            ->getJson('/api/v1/driver/location-change-requests?status=pending');

        $driverList->assertStatus(200);
        $driverList->assertJsonPath('data.0.id', $requestId);
        $driverList->assertJsonPath('data.0.children.0.id', $this->child->id);
    }

    public function test_request_beyond_max_distance_is_rejected_and_nothing_is_saved(): void
    {
        $this->setTierFees(5.00, 10.00, 15.00);
        $address = $this->addressAtDistance(12.0, 'موقع بعيد جداً');
        $before  = LocationChangeRequest::count();

        $response = $this->actingAs($this->parentUser)
            ->postJson('/api/parent/location-change-requests', [
                'point_type' => 'pickup',
                'date'       => now()->addDays(2)->toDateString(),
                'selections' => [
                    ['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]],
                ],
                'address_id' => $address->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame($before, LocationChangeRequest::count());
    }

    public function test_parent_cannot_submit_duplicate_pending_request_for_same_driver_point_and_date(): void
    {
        $targetDate = now()->addDays(1)->toDateString();
        $payload = [
            'point_type' => 'pickup',
            'date'       => $targetDate,
            'selections' => [
                ['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]],
            ],
            'address_id' => $this->address->id,
        ];

        $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', $payload)
            ->assertStatus(201);

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(1, LocationChangeRequest::where('parent_id', $this->parentUser->id)->count());
    }

    public function test_driver_approval_for_single_day_updates_only_trip_stop_and_preserves_master(): void
    {
        $targetDate = now()->addDays(1)->toDateString();

        // رحلة اليوم مولّدة مسبقاً (كما تفعل DailyTripGenerationService كل صباح)
        $trip = Trip::create([
            'driver_id' => $this->driver->id, 'route_id' => $this->route->id,
            'trip_type' => 'Morning', 'shift_slot' => 'morning_go', 'status' => 'pending',
            'scheduled_at' => now(), 'scheduled_start_time' => '07:00:00', 'trip_date' => $targetDate,
        ]);
        $tripStop = TripStop::create([
            'trip_id' => $trip->id, 'stop_type' => TripStop::TYPE_HOME, 'child_id' => $this->child->id,
            'lat' => 32.88, 'lng' => 13.19, 'label' => 'المنزل', 'sequence_order' => 1,
            'status' => TripStop::STATUS_PENDING,
        ]);

        $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', [
            'point_type' => 'pickup', 'date' => $targetDate,
            'selections' => [['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]]],
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $requestId = LocationChangeRequest::where('parent_id', $this->parentUser->id)->where('change_date', $targetDate)->firstOrFail()->id;

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/location-change-requests/{$requestId}/respond", ['status' => 'approved']);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'approved');

        // محطة رحلة اليوم تحدّثت للموقع الجديد
        $this->assertDatabaseHas('trip_stops', [
            'id' => $tripStop->id, 'label' => 'منزل الجدة', 'lat' => $this->address->lat, 'lng' => $this->address->lng,
        ]);

        // الاشتراك النشط والمسار المرجعي بقيا بلا تغيير دائم
        $this->assertDatabaseHas('active_subscriptions', [
            'id' => $this->activeSub->id, 'pickup_label' => 'المنزل',
        ]);
        $this->assertDatabaseHas('route_stops', [
            'route_id' => $this->route->id, 'child_id' => $this->child->id,
            'stop_type' => RouteStop::TYPE_HOME, 'label' => 'المنزل',
        ]);
    }

    public function test_location_change_fee_is_collected_from_parent_wallet_on_driver_approval(): void
    {
        $this->setTierFees(5.00, 10.00, 15.00);
        $this->parent->deposit(10000); // 100 د.ل

        $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', [
            'point_type' => 'pickup', 'date' => now()->addDays(1)->toDateString(),
            'selections' => [['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]]],
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $requestId = LocationChangeRequest::where('parent_id', $this->parentUser->id)->firstOrFail()->id;

        $parentBefore = (int) $this->parent->fresh()->balance;
        $driverBefore = (int) $this->driver->fresh()->balance;

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/location-change-requests/{$requestId}/respond", ['status' => 'approved'])
            ->assertStatus(200);

        // 15 د.ل = 1500 قرش، عمولة 8٪ = 120 قرشاً، صافي السائق 1380 قرشاً
        $this->assertEquals($parentBefore - 1500, (int) $this->parent->fresh()->balance);
        $this->assertEquals($driverBefore + 1380, (int) $this->driver->fresh()->balance);

        $this->assertDatabaseHas('location_change_requests', ['id' => $requestId, 'is_settled' => 1]);
    }

    public function test_driver_can_reject_change_with_reason_and_nothing_is_modified(): void
    {
        $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', [
            'point_type' => 'pickup', 'date' => now()->addDays(1)->toDateString(),
            'selections' => [['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]]],
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $requestId = LocationChangeRequest::where('parent_id', $this->parentUser->id)->firstOrFail()->id;

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/location-change-requests/{$requestId}/respond", [
                'status'           => 'rejected',
                'rejection_reason' => 'المسار الجديد بعيد جداً عن باقي الأطفال.',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('location_change_requests', [
            'id' => $requestId, 'status' => LocationChangeRequest::STATUS_REJECTED,
            'rejection_reason' => 'المسار الجديد بعيد جداً عن باقي الأطفال.',
        ]);
        $this->assertDatabaseHas('active_subscriptions', [
            'id' => $this->activeSub->id, 'pickup_label' => 'المنزل',
        ]);
    }

    public function test_driver_cannot_respond_to_another_drivers_request(): void
    {
        $otherDriverUser = User::create([
            'full_name' => 'سائق آخر', 'email' => 'driver.other.' . uniqid() . '@darby.test',
            'phone_number' => '095' . rand(1000000, 9999999), 'password_hash' => bcrypt('password123'),
            'role_id' => 2, 'is_active' => 1,
        ]);
        Driver::create([
            'user_id' => $otherDriverUser->id, 'national_id' => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999), 'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status' => 'Approved',
        ]);

        $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', [
            'point_type' => 'pickup', 'date' => now()->addDays(1)->toDateString(),
            'selections' => [['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]]],
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $requestId = LocationChangeRequest::where('parent_id', $this->parentUser->id)->firstOrFail()->id;

        $response = $this->actingAs($otherDriverUser)
            ->postJson("/api/v1/driver/location-change-requests/{$requestId}/respond", ['status' => 'approved']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('location_change_requests', [
            'id' => $requestId, 'status' => LocationChangeRequest::STATUS_PENDING,
        ]);
    }

    public function test_parent_can_cancel_pending_request_before_driver_responds(): void
    {
        $this->actingAs($this->parentUser)->postJson('/api/parent/location-change-requests', [
            'point_type' => 'pickup', 'date' => now()->addDays(1)->toDateString(),
            'selections' => [['child_id' => $this->child->id, 'trip_ids' => [$this->activeSub->id]]],
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $requestId = LocationChangeRequest::where('parent_id', $this->parentUser->id)->firstOrFail()->id;

        $response = $this->actingAs($this->parentUser)
            ->deleteJson("/api/parent/location-change-requests/{$requestId}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('location_change_requests', [
            'id' => $requestId, 'status' => LocationChangeRequest::STATUS_CANCELLED,
        ]);
    }
}
