<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\RouteStop;
use App\Services\Shared\SubscriptionRequestService;

/**
 * ط§ط®طھط¨ط§ط± ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط± ط§ظ„ط±ط¦ظٹط³ظٹ (Master Route / route_stops) ط¹ظ†ط¯ ظ‚ط¨ظˆظ„ ط·ظ„ط¨ ط§ط´طھط±ط§ظƒ
 * ظˆط¹ظ†ط¯ ط¥ظ„ط؛ط§ط، ط§ط´طھط±ط§ظƒ ظ†ط´ط·.
 */
class MasterRouteStopSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected User $parentUser;
    protected ParentModel $parent;
    protected Child $child;
    protected School $school;
    protected SubscriptionRequest $subscriptionRequest;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'ط³ط§ط¦ظ‚'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ظˆظ„ظٹ ط£ظ…ط±'],
        ]);

        $this->driverUser = User::create([
            'full_name'    => 'ط³ط§ط¦ظ‚ ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط±',
            'email'        => 'driver.sync.' . uniqid() . '@darby.test',
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
            'current_lat'    => 32.8872,
            'current_lng'    => 13.1932,
        ]);

        DB::table('vehicles')->insert([
            'driver_id'       => $this->driver->id,
            'brand'           => 'طھظˆظٹظˆطھط§',
            'model'           => 'ظ‡ط§ظٹط³',
            'year'            => 2022,
            'color'           => 'ط£ط¨ظٹط¶',
            'plate_number'    => 'SYNC-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'status'          => 'Active',
            'deleted_at'      => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->parentUser = User::create([
            'full_name'    => 'ظˆظ„ظٹ ط£ظ…ط± ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط±',
            'email'        => 'parent.sync.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 3,
            'is_active'    => 1,
        ]);

        // ParentModel هو Proxy فوق جدول users في التطبيع V2، فالمعرّف نفسه معرّف المستخدم
        $this->parent = ParentModel::findOrFail($this->parentUser->id);

        // قيمة الاشتراك تُحجز في الأمانات لكل الأنواع (وليس اليومي فقط)،
        // لذا يجب أن تكون محفظة ولي الأمر ممولة قبل إرسال الطلب أو قبوله.
        $this->parent->deposit(500000);

        $this->school = School::create([
            'name'    => 'ظ…ط¯ط±ط³ط© ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط±',
            'address' => 'ط´ط§ط±ط¹ ط§ظ„ط§ط®طھط¨ط§ط±',
            'lat'     => 32.9000,
            'lng'     => 13.2000,
            'status'  => 'Approved',
        ]);

        $this->child = Child::create([
            'parent_id'            => $this->parent->id,
            'school_id'            => $this->school->id,
            'full_name'            => 'ط·ظپظ„ ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط±',
            'birth_date'           => '2018-05-10',
            'gender'               => 'male',
            'grade'                => 1,
            'notification_radius' => 500,
        ]);

        $addressId = DB::table('addresses')->insertGetId([
            'user_id'    => $this->parentUser->id,
            'label'      => 'منزل ولي الأمر',
            'lat'        => 32.88,
            'lng'        => 13.19,
        ]);

        $this->subscriptionRequest = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'total_price'                 => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'children_count'              => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $this->subscriptionRequest->id,
            'child_id'                    => $this->child->id,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'both',
            'timing'                      => 'MORNING',
            'start_date'                  => now()->addDay()->format('Y-m-d'),
            'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
            'working_days_count'          => 22,
            'distance_km'                 => 5.0,
            'trip_price'                  => 200.00,
            'price_per_child'             => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'driver_net_price'            => 184.00,
            'home_lat'                    => 32.8800,
            'home_lng'                    => 13.1900,
            'home_label'                  => 'home',
            'school_lat'                  => 32.9000,
            'school_lng'                  => 13.2000,
            'school_label'                => 'school',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
    }

    /**
     * ظٹظ†ط´ط¦ ط·ظپظ„ط§ظ‹ ط¬ط¯ظٹط¯ط§ظ‹ ظˆط·ظ„ط¨ ط§ط´طھط±ط§ظƒ ط¬ط¯ظٹط¯ط§ظ‹ (ط¨ط­ط§ظ„ط© pending) ظ„ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚/ط§ظ„ط£ط¨ ظپظٹ ظ‡ط°ط§ ط§ظ„ط§ط®طھط¨ط§ط±طŒ
     * ط¨ظپطھط±ط©/ط§طھط¬ط§ظ‡ ظ…ط­ط¯ط¯ظٹظ†طŒ ظˆظٹظڈط±ط¬ط¹ظ‡ظ…ط§ ظ…ط¹ط§ظ‹ [child, request].
     */
    private function makeChildAndRequest(string $direction, string $timing, string $label): array
    {
        $child = Child::create([
            'parent_id'            => $this->parent->id,
            'school_id'            => $this->school->id,
            'full_name'            => 'ط·ظپظ„ ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط± ' . $label,
            'birth_date'           => '2019-01-01',
            'gender'               => 'female',
            'grade'                => 1,
            'notification_radius' => 500,
        ]);

        $addressId = DB::table('addresses')->insertGetId([
            'user_id'    => $this->parentUser->id,
            'label'      => 'منزل ' . $label,
            'lat'        => 32.885,
            'lng'        => 13.195,
        ]);

        $request = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'total_price'                 => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'children_count'              => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $request->id,
            'child_id'                    => $child->id,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => $direction,
            'timing'                      => $timing,
            'start_date'                  => now()->addDay()->format('Y-m-d'),
            'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
            'working_days_count'          => 22,
            'distance_km'                 => 5.0,
            'trip_price'                  => 200.00,
            'price_per_child'             => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'driver_net_price'            => 184.00,
            'home_lat'                    => 32.8800,
            'home_lng'                    => 13.1900,
            'home_label'                  => 'home',
            'school_lat'                  => 32.9000,
            'school_lng'                  => 13.2000,
            'school_label'                => 'school',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        return [$child, $request];
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط±: ط·ظ„ط¨ط§ظ† ظ…ظ†ظپطµظ„ط§ظ† ط¨ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚طŒ ط¨ظ†ظپط³ ط§ظ„ظپطھط±ط© ظˆظ†ظپط³ ط§ظ„ط§طھط¬ط§ظ‡ ط§ظ„ظ…ظپط±ط¯
    // (طµط¨ط§ط­ظٹط© - ط°ظ‡ط§ط¨ ظپظ‚ط·)طŒ ظٹط¬ط¨ ط£ظ† ظٹط´طھط±ظƒط§ ظپظٹ ظ†ظپط³ ط§ظ„ظ…ط³ط§ط± ط§ظ„ط«ط§ط¨طھ.
    // =========================================================
    public function test_two_requests_with_same_single_direction_and_period_share_one_route(): void
    {
        $service = app(SubscriptionRequestService::class);

        [$childA, $requestA] = $this->makeChildAndRequest('go', 'MORNING', 'ط£');
        $service->updateStatus($requestA, 'accepted');

        [$childB, $requestB] = $this->makeChildAndRequest('go', 'MORNING', 'ط¨');
        $service->updateStatus($requestB, 'accepted');

        $this->assertEquals(
            1,
            RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->count(),
            'طھظ… ط¥ظ†ط´ط§ط، ط£ظƒط«ط± ظ…ظ† ظ…ط³ط§ط± [طµط¨ط§ط­ظٹط© - ط°ظ‡ط§ط¨] ط±ط؛ظ… ط£ظ† ط§ظ„ط·ظ„ط¨ظٹظ† ظ„ظ‡ظ…ط§ ظ†ظپط³ ط§ظ„ظپطھط±ط© ظˆظ†ظپط³ ط§ظ„ط§طھط¬ط§ظ‡.'
        );

        $route = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();

        $this->assertDatabaseHas('route_stops', ['route_id' => $route->id, 'stop_type' => 'home', 'child_id' => $childA->id]);
        $this->assertDatabaseHas('route_stops', ['route_id' => $route->id, 'stop_type' => 'home', 'child_id' => $childB->id]);

        $this->assertDatabaseHas('active_subscriptions', ['child_id' => $childA->id, 'route_id' => $route->id]);
        $this->assertDatabaseHas('active_subscriptions', ['child_id' => $childB->id, 'route_id' => $route->id]);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط±: ط·ظ„ط¨ ط£ظˆظ„ ط¨ط§طھط¬ط§ظ‡ ظˆط§ط­ط¯ (ط°ظ‡ط§ط¨ ظپظ‚ط·) ط«ظ… ط·ظ„ط¨ ط«ط§ظ†ظچ ظ„ط·ظپظ„ ط¢ط®ط± ط¨ط§طھط¬ط§ظ‡ظٹظ† (ط°ظ‡ط§ط¨ ظˆط¥ظٹط§ط¨)
    // ظ„ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚ ظˆظ†ظپط³ ط§ظ„ظپطھط±ط© ط§ظ„طµط¨ط§ط­ظٹط©. ظٹط¬ط¨ ط£ظ† ظٹظڈط¹ط§ط¯ ط§ط³طھط®ط¯ط§ظ… ظ…ط³ط§ط± [ط°ظ‡ط§ط¨] ط§ظ„ظ…ظˆط¬ظˆط¯
    // ظˆط£ظ† ظٹظڈظ†ط´ط£ ظ…ط³ط§ط± [ط¥ظٹط§ط¨] ط¬ط¯ظٹط¯ ظ„ظ…ط±ط© ظˆط§ط­ط¯ط© ظپظ‚ط·طŒ ط¯ظˆظ† طھظƒط±ط§ط± ط£ظٹظ‘ ظ…ظ†ظ‡ظ…ط§ ظ„ط§ط­ظ‚ط§ظ‹.
    // =========================================================
    public function test_mixed_direction_requests_reuse_shared_slot_and_create_missing_slot_once(): void
    {
        $service = app(SubscriptionRequestService::class);

        [$childA, $requestA] = $this->makeChildAndRequest('go', 'MORNING', 'ط£');
        $service->updateStatus($requestA, 'accepted');
        $goRouteAfterA = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $this->assertNotNull($goRouteAfterA);
        $this->assertNull(RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first());

        [$childB, $requestB] = $this->makeChildAndRequest('both', 'MORNING', 'ط¨');
        $service->updateStatus($requestB, 'accepted');

        [$childC, $requestC] = $this->makeChildAndRequest('return', 'MORNING', 'ط¬');
        $service->updateStatus($requestC, 'accepted');

        // ظ…ط§ ط²ط§ظ„ ظ‡ظ†ط§ظƒ ظ…ط³ط§ط± ظˆط§ط­ط¯ ظپظ‚ط· [ط°ظ‡ط§ط¨] ظˆظ…ط³ط§ط± ظˆط§ط­ط¯ ظپظ‚ط· [ط¥ظٹط§ط¨] ظ„ظ‡ط°ط§ ط§ظ„ط³ط§ط¦ظ‚
        $this->assertEquals(1, RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->count());
        $this->assertEquals(1, RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->count());

        $goRouteFinal     = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $returnRouteFinal = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();

        // ظ…ط³ط§ط± [ط°ظ‡ط§ط¨] ط§ظ„ط°ظٹ ط£ظڈظ†ط´ط¦ ظ…ط¹ ط§ظ„ط·ظپظ„ ط§ظ„ط£ظˆظ„ ظ‡ظˆ ظ†ظپط³ظ‡ ط§ظ„ظ…ظڈط³طھط®ط¯ظ… ظ„ط§ط­ظ‚ط§ظ‹ ظ…ط¹ ط§ظ„ط·ظپظ„ ط§ظ„ط«ط§ظ†ظٹ
        $this->assertEquals($goRouteAfterA->id, $goRouteFinal->id);

        // ط§ظ„ط£ط·ظپط§ظ„ ط§ظ„ط«ظ„ط§ط«ط© ظ…ظˆط²ط¹ظˆظ† ط¨ط´ظƒظ„ طµط­ظٹط­: ط£+ط¨ ط¹ظ„ظ‰ ظ…ط³ط§ط± ط§ظ„ط°ظ‡ط§ط¨طŒ ط¨+ط¬ ط¹ظ„ظ‰ ظ…ط³ط§ط± ط§ظ„ط¥ظٹط§ط¨
        $this->assertDatabaseHas('route_stops', ['route_id' => $goRouteFinal->id, 'stop_type' => 'home', 'child_id' => $childA->id]);
        $this->assertDatabaseHas('route_stops', ['route_id' => $goRouteFinal->id, 'stop_type' => 'home', 'child_id' => $childB->id]);
        $this->assertDatabaseMissing('route_stops', ['route_id' => $goRouteFinal->id, 'stop_type' => 'home', 'child_id' => $childC->id]);

        $this->assertDatabaseHas('route_stops', ['route_id' => $returnRouteFinal->id, 'stop_type' => 'home', 'child_id' => $childB->id]);
        $this->assertDatabaseHas('route_stops', ['route_id' => $returnRouteFinal->id, 'stop_type' => 'home', 'child_id' => $childC->id]);
        $this->assertDatabaseMissing('route_stops', ['route_id' => $returnRouteFinal->id, 'stop_type' => 'home', 'child_id' => $childA->id]);
    }

    public function test_acceptance_creates_route_stops_for_home_and_school(): void
    {
        $service = app(SubscriptionRequestService::class);
        $service->updateStatus($this->subscriptionRequest, 'accepted');

        $route = RouteModel::where('driver_id', $this->driver->id)
            ->where('shift_slot', 'morning_go')
            ->first();

        $this->assertNotNull($route, 'ظ„ظ… ظٹطھظ… ط¥ظ†ط´ط§ط، ظ…ط³ط§ط± morning_go ط§ظ„ط±ط¦ظٹط³ظٹ.');

        $this->assertDatabaseHas('route_stops', [
            'route_id'  => $route->id,
            'stop_type' => 'home',
            'child_id'  => $this->child->id,
        ]);

        $this->assertDatabaseHas('route_stops', [
            'route_id'  => $route->id,
            'stop_type' => 'school',
            'school_id' => $this->school->id,
        ]);
    }

    public function test_direction_both_creates_second_slot_route_with_same_child(): void
    {
        $service = app(SubscriptionRequestService::class);
        $service->updateStatus($this->subscriptionRequest, 'accepted');

        $goRoute = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $returnRoute = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();

        $this->assertNotNull($goRoute);
        $this->assertNotNull($returnRoute);
        $this->assertNotEquals($goRoute->id, $returnRoute->id);

        $this->assertDatabaseHas('route_stops', [
            'route_id'  => $returnRoute->id,
            'stop_type' => 'home',
            'child_id'  => $this->child->id,
        ]);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط±: ظ‚ط¨ظˆظ„ ط·ظ„ط¨ ط§ط´طھط±ط§ظƒ ط«ط§ظ†ظچ ظ„ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚ ظˆظ†ظپط³ ط§ظ„ظپطھط±ط©/ط§ظ„ط§طھط¬ط§ظ‡
    // ظٹط¬ط¨ ط£ظ† ظٹظڈط¶ظٹظپ ط§ظ„ط·ظپظ„ ط§ظ„ط¬ط¯ظٹط¯ ط¥ظ„ظ‰ ط§ظ„ظ…ط³ط§ط± ط§ظ„ط±ط¦ظٹط³ظٹ ط§ظ„ظ…ظˆط¬ظˆط¯ ط¨ط¯ظ„ط§ظ‹ ظ…ظ†
    // ط¥ظ†ط´ط§ط، ظ…ط³ط§ط± ط¬ط¯ظٹط¯ ظپظٹ ظƒظ„ ظ…ط±ط© (ظ‡ط°ط§ ظ‡ظˆ ط§ظ„ط³ظ„ظˆظƒ ط§ظ„ظ…ط·ظ„ظˆط¨ ط¥طµظ„ط§ط­ظ‡).
    // =========================================================
    public function test_second_subscription_for_same_driver_and_slot_reuses_existing_route(): void
    {
        $service = app(SubscriptionRequestService::class);

        // --- ظ‚ط¨ظˆظ„ ط§ظ„ط·ظ„ط¨ ط§ظ„ط£ظˆظ„ (ط·ظپظ„ 1) ---
        $service->updateStatus($this->subscriptionRequest, 'accepted');

        $goRouteAfterFirst     = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $returnRouteAfterFirst = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();
        $this->assertNotNull($goRouteAfterFirst);
        $this->assertNotNull($returnRouteAfterFirst);

        // --- ط¥ظ†ط´ط§ط، ط·ظپظ„ ط«ط§ظ†ظچ ظˆط·ظ„ط¨ ط§ط´طھط±ط§ظƒ ط«ط§ظ†ظچ ظ„ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚ ط¨ظ†ظپط³ ط§ظ„ظپطھط±ط© ظˆط§ظ„ط§طھط¬ط§ظ‡ (طµط¨ط§ط­ظٹط© - ط°ظ‡ط§ط¨ ظˆط¥ظٹط§ط¨) ---
        $secondChild = Child::create([
            'parent_id'            => $this->parent->id,
            'school_id'            => $this->school->id,
            'full_name'            => 'ط·ظپظ„ ظ…ط²ط§ظ…ظ†ط© ط§ظ„ظ…ط³ط§ط± ط§ظ„ط«ط§ظ†ظٹ',
            'birth_date'           => '2019-01-01',
            'gender'               => 'female',
            'grade'                => 1,
            'notification_radius' => 500,
        ]);

        $secondAddressId = DB::table('addresses')->insertGetId([
            'user_id'    => $this->parentUser->id,
            'label'      => 'منزل ولي الأمر الثاني',
            'lat'        => 32.885,
            'lng'        => 13.195,
        ]);

        $secondRequest = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'total_price'                 => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'children_count'              => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $secondRequest->id,
            'child_id'                    => $secondChild->id,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'both',
            'timing'                      => 'MORNING',
            'start_date'                  => now()->addDay()->format('Y-m-d'),
            'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
            'working_days_count'          => 22,
            'distance_km'                 => 5.0,
            'trip_price'                  => 200.00,
            'price_per_child'             => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'driver_net_price'            => 184.00,
            'home_lat'                    => 32.8800,
            'home_lng'                    => 13.1900,
            'home_label'                  => 'home',
            'school_lat'                  => 32.9000,
            'school_lng'                  => 13.2000,
            'school_label'                => 'school',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        // --- ظ‚ط¨ظˆظ„ ط§ظ„ط·ظ„ط¨ ط§ظ„ط«ط§ظ†ظٹ (ط·ظپظ„ 2) ---
        $service->updateStatus($secondRequest, 'accepted');

        // 1. ظ„ط§ ظٹط¬ط¨ ط¥ظ†ط´ط§ط، ط£ظٹ ظ…ط³ط§ط± ط¬ط¯ظٹط¯: ظٹط¨ظ‚ظ‰ ظ…ط³ط§ط± ظˆط§ط­ط¯ ظپظ‚ط· ظ„ظƒظ„ slot ظ„ظ‡ط°ط§ ط§ظ„ط³ط§ط¦ظ‚
        $this->assertEquals(
            1,
            RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->count(),
            'طھظ… ط¥ظ†ط´ط§ط، ط£ظƒط«ط± ظ…ظ† ظ…ط³ط§ط± [طµط¨ط§ط­ظٹط© - ط°ظ‡ط§ط¨] ظ„ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚ ط¨ط¯ظ„ط§ظ‹ ظ…ظ† ط¥ط¹ط§ط¯ط© ط§ط³طھط®ط¯ط§ظ… ط§ظ„ظ…ط³ط§ط± ط§ظ„ظ…ظˆط¬ظˆط¯.'
        );
        $this->assertEquals(
            1,
            RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->count(),
            'طھظ… ط¥ظ†ط´ط§ط، ط£ظƒط«ط± ظ…ظ† ظ…ط³ط§ط± [طµط¨ط§ط­ظٹط© - ط¥ظٹط§ط¨] ظ„ظ†ظپط³ ط§ظ„ط³ط§ط¦ظ‚ ط¨ط¯ظ„ط§ظ‹ ظ…ظ† ط¥ط¹ط§ط¯ط© ط§ط³طھط®ط¯ط§ظ… ط§ظ„ظ…ط³ط§ط± ط§ظ„ظ…ظˆط¬ظˆط¯.'
        );

        // 2. ظ†ظپط³ ط³ط¬ظ„ط§طھ ط§ظ„ظ…ط³ط§ط± (ط¨ظ†ظپط³ ط§ظ„ظ€ id) ظ‡ظٹ ط§ظ„طھظٹ ط£ظڈط¹ظٹط¯ ط§ط³طھط®ط¯ط§ظ…ظ‡ط§
        $goRouteAfterSecond     = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $returnRouteAfterSecond = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();
        $this->assertEquals($goRouteAfterFirst->id, $goRouteAfterSecond->id);
        $this->assertEquals($returnRouteAfterFirst->id, $returnRouteAfterSecond->id);

        // 3. ط§ظ„ط·ظپظ„ط§ظ† ظ…ط¶ط§ظپط§ظ† ظ…ط¹ط§ظ‹ ط¹ظ„ظ‰ ظ†ظپط³ ط§ظ„ظ…ط³ط§ط±ظٹظ† (ط°ظ‡ط§ط¨ ظˆط¥ظٹط§ط¨)
        $this->assertDatabaseHas('route_stops', [
            'route_id'  => $goRouteAfterSecond->id,
            'stop_type' => 'home',
            'child_id'  => $this->child->id,
        ]);
        $this->assertDatabaseHas('route_stops', [
            'route_id'  => $goRouteAfterSecond->id,
            'stop_type' => 'home',
            'child_id'  => $secondChild->id,
        ]);
        $this->assertDatabaseHas('route_stops', [
            'route_id'  => $returnRouteAfterSecond->id,
            'stop_type' => 'home',
            'child_id'  => $secondChild->id,
        ]);

        // 4. ط§ظ„ط§ط´طھط±ط§ظƒط§ظ† ط§ظ„ظ†ط´ط·ط§ظ† ط§ظ„ط®ط§طµط§ظ† ط¨ط§ظ„ط·ظپظ„ظٹظ† ظ…ط±طھط¨ط·ط§ظ† ط¨ظ†ظپط³ ظ…ط³ط§ط± ط§ظ„ظ€ slot ط§ظ„ط£ط³ط§ط³ظٹ (morning_go)
        $this->assertDatabaseHas('active_subscriptions', [
            'child_id' => $this->child->id,
            'route_id' => $goRouteAfterSecond->id,
        ]);
        $this->assertDatabaseHas('active_subscriptions', [
            'child_id' => $secondChild->id,
            'route_id' => $goRouteAfterSecond->id,
        ]);
    }

    public function test_cancelling_active_subscription_removes_its_route_stops(): void
    {
        $service = app(SubscriptionRequestService::class);
        $service->updateStatus($this->subscriptionRequest, 'accepted');

        $activeSub = ActiveSubscription::where('driver_id', $this->driver->id)
            ->where('child_id', $this->child->id)
            ->first();
        $this->assertNotNull($activeSub);

        $route = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $this->assertDatabaseHas('route_stops', ['route_id' => $route->id, 'child_id' => $this->child->id]);

        $service->updateActiveSubscriptionStatus($activeSub->id, 'cancelled');

        $this->assertDatabaseMissing('route_stops', [
            'route_id'  => $route->id,
            'stop_type' => 'home',
            'child_id'  => $this->child->id,
        ]);

        // ظƒط§ظ† ط§ظ„ط·ظپظ„ ط§ظ„ظˆط­ظٹط¯ ط§ظ„ظ…ط±طھط¨ط· ط¨ظ‡ط°ظ‡ ط§ظ„ظ…ط¯ط±ط³ط© ط¹ظ„ظ‰ ظ‡ط°ط§ ط§ظ„ظ…ط³ط§ط± â†’ ظٹط¬ط¨ ط­ط°ظپ ظ…ط­ط·ط© ط§ظ„ظ…ط¯ط±ط³ط© ط£ظٹط¶ط§ظ‹
        $this->assertDatabaseMissing('route_stops', [
            'route_id'  => $route->id,
            'stop_type' => 'school',
            'school_id' => $this->school->id,
        ]);

        $route->refresh();
        $this->assertEquals('Inactive', $route->status);
    }
}
