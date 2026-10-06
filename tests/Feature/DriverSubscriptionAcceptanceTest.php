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
use App\Models\Shared\Contract;
use App\Models\Shared\ActiveSubscription;
use App\Services\Shared\SubscriptionRequestService;
use App\Services\Shared\ContractService;
use Mockery;

/**
 * ط§ط®طھط¨ط§ط± ط¯ط§ظ„ط© ظ‚ط¨ظˆظ„/ط±ظپط¶ ط·ظ„ط¨ ط§ط´طھط±ط§ظƒ ظ…ظ† ظ‚ظگط¨ظژظ„ ط§ظ„ط³ط§ط¦ظ‚
 *
 * ظٹط³طھط®ط¯ظ… DatabaseTransactions: ظƒظ„ ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ظ…ظڈظ†ط´ط£ط© ط®ظ„ط§ظ„ ط§ظ„ط§ط®طھط¨ط§ط±
 * طھظڈط­ط°ظپ طھظ„ظ‚ط§ط¦ظٹط§ظ‹ ط¨ط¹ط¯ ط§ظ†طھظ‡ط§ط¦ظ‡ â†گ ظ„ط§ ط¶ط±ط± ط¹ظ„ظ‰ ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ط­ظ‚ظٹظ‚ظٹط©.
 */
class DriverSubscriptionAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    // =========================================================
    // ط¨ظٹط§ظ†ط§طھ ظ…ط´طھط±ظƒط© ط¨ظٹظ† ط¬ظ…ظٹط¹ ط§ظ„ط§ط®طھط¨ط§ط±ط§طھ
    // =========================================================
    protected User              $driverUser;
    protected Driver            $driver;
    protected User              $parentUser;
    protected ParentModel       $parent;
    protected Child             $child;
    protected School            $school;
    protected SubscriptionRequest $subscriptionRequest;

    // =========================================================
    // setUp: ط¥ط¹ط¯ط§ط¯ ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ط£ط³ط§ط³ظٹط© ظ‚ط¨ظ„ ظƒظ„ ط§ط®طھط¨ط§ط±
    // =========================================================
    protected function setUp(): void
    {
        parent::setUp();

        // --- ط¥ط¯ط±ط§ط¬ ط§ظ„ط£ط¯ظˆط§ط± ط¥ط°ط§ ظ„ظ… طھظƒظ† ظ…ظˆط¬ظˆط¯ط© ---
        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver',  'display_name' => 'ط³ط§ط¦ظ‚'],
            ['id' => 3, 'name' => 'Parent',  'display_name' => 'ظˆظ„ظٹ ط£ظ…ط±'],
        ]);

        // ---- 1. ظ…ط³طھط®ط¯ظ… ط§ظ„ط³ط§ط¦ظ‚ ----
        $this->driverUser = User::create([
            'full_name'    => 'ط³ط§ط¦ظ‚ ط§ظ„ط§ط®طھط¨ط§ط±',
            'email'        => 'driver.test.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999),
            'password_hash'=> bcrypt('password123'),
            'role_id'      => 2,
            'is_active'    => 1,
        ]);

        // ---- 2. ط³ط¬ظ„ ط§ظ„ط³ط§ط¦ظ‚ ظپظٹ ط¬ط¯ظˆظ„ drivers ----
        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
            'current_lat'    => 32.8872,
            'current_lng'    => 13.1932,
        ]);

        // ---- 3. ط¥ط¯ط±ط§ط¬ ظ…ط±ظƒط¨ط© ظ†ط´ط·ط© ظ„ظ„ط³ط§ط¦ظ‚ ----
        DB::table('vehicles')->insert([
            'driver_id'       => $this->driver->id,
            'brand'           => 'طھظˆظٹظˆطھط§',
            'model'           => 'ظ‡ط§ظٹط³',
            'year'            => 2022,
            'color'           => 'ط£ط¨ظٹط¶',
            'plate_number'    => 'TEST-' . rand(1000, 9999),
            'capacity_manual' => 10,
            'status'          => 'Active',
            'deleted_at'      => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // ---- 4. ظ…ط³طھط®ط¯ظ… ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ----
        $this->parentUser = User::create([
            'full_name'    => 'ظˆظ„ظٹ ط£ظ…ط± ط§ظ„ط§ط®طھط¨ط§ط±',
            'email'        => 'parent.test.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash'=> bcrypt('password123'),
            'role_id'      => 3,
            'is_active'    => 1,
        ]);

        // ---- 5. سجل ولي الأمر ----
        $this->parent = ParentModel::find($this->parentUser->id);
        $this->parentUser->deposit(50000); // 500 دينار بالمحفظة

        // ---- 6.1 مدرسة الاختبار ----
        $this->school = School::create([
            'name'    => 'مدرسة الاختبار',
            'address' => 'شارع الاختبار',
            'lat'     => 32.9000,
            'lng'     => 13.2000,
            'status'  => 'Approved',
        ]);

        // ---- 6.2 عنوان ولي الأمر ----
        $addressId = DB::table('addresses')->insertGetId([
            'user_id'    => $this->parentUser->id,
            'label'      => 'منزل ولي الأمر',
            'lat'        => 32.88,
            'lng'        => 13.19,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ---- 6. الطفل ----
        $this->child = Child::create([
            'parent_id' => $this->parent->id,
            'full_name' => 'طفل الاختبار',
            'birth_date'=> '2018-05-10',
            'gender'    => 'male',
            'grade'     => 1,
            'notification_radius' => 500,
        ]);

        // ---- 7. طلب الاشتراك المعلق ----
        $this->subscriptionRequest = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'total_price'                 => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'pickup_time'                 => '07:00:00',
            'dropoff_time'                => '14:00:00',
            'max_waiting_time'            => 15,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'children_count'              => 1,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'both',
            'start_date'                  => now()->addDay()->format('Y-m-d'),
            'end_date'                    => now()->addMonth()->format('Y-m-d'),
            'working_days_count'          => 22,
            'home_label'                  => 'منزل ولي الأمر',
            'home_lat'                    => 32.8800,
            'home_lng'                    => 13.1900,
        ]);

        // ---- 8. ربط الطفل بالطلب في جدول request_children ----
        DB::table('request_children')->insert([
            'request_id'                  => $this->subscriptionRequest->id,
            'child_id'                    => $this->child->id,
            'school_id'                   => $this->school->id,
            'timing'                      => 'MORNING',
            'school_label'                => 'مدرسة الاختبار',
            'school_lat'                  => 32.9000,
            'school_lng'                  => 13.2000,
            'distance_km'                 => 4.0,
            'price_per_child'             => 200.00,
            'trip_price'                  => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'driver_net_price'            => 184.00,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================
    // اختبار 1: السائق يقبل الطلب بنجاح
    // =========================================================
    public function test_driver_can_accept_subscription_request(): void
    {
        // --- إرسال طلب القبول ---
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
                'status' => 'accepted',
            ]);

        // --- التحققات ---
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // تحقق أن حالة الطلب تغيّرت إلى accepted
        $this->assertDatabaseHas('requests', [
            'id'     => $this->subscriptionRequest->id,
            'status' => 'accepted',
        ]);

        // تحقق من إنشاء سجلات active_subscriptions
        $this->assertDatabaseHas('active_subscriptions', [
            'subscription_request_id' => $this->subscriptionRequest->id,
            'status'                  => 'active',
        ]);

        // جلب معرف الاشتراك النشط من قاعدة البيانات للتحقق من تطابقه مع الاستجابة
        $activeSub = ActiveSubscription::where('subscription_request_id', $this->subscriptionRequest->id)->first();
        
        $this->assertNotNull($activeSub);

        // التحقق من وجود المعرّف (child_id) وتطابقه داخل تفاصيل الطفل في مصفوفة الإخراج
        $response->assertJsonPath('data.children.0.child_id', $this->child->id);
        $this->assertContains($response->json('data.status'), ['accepted', 'pending_start', 'active']);
    }

    // =========================================================
    // اختبار 1.5: قبول الطلب ينشئ مساراً ويخصم المقاعد المتاحة (بزيادة الاشتراكات النشطة)
    // =========================================================
    public function test_accepting_subscription_creates_route_and_deducts_available_seats(): void
    {
        $service = app(SubscriptionRequestService::class);

        $beforeActiveCount = ActiveSubscription::whereHas('subscriptionRequest', fn($q) => $q->where('driver_id', $this->driver->id))->where('status', 'active')->count();

        // إكمال قبول الطلب
        $updatedRequest = $service->updateStatus($this->subscriptionRequest, 'accepted');

        // 1. تحقق من تغير حالة الطلب إلى accepted
        $this->assertEquals('accepted', $updatedRequest->status);

        // 2. تحقق من زيادة المقاعد المحجوزة (عدد الاشتراكات النشطة) بناءً على عدد الأطفال
        $afterActiveCount = ActiveSubscription::whereHas('subscriptionRequest', fn($q) => $q->where('driver_id', $this->driver->id))->where('status', 'active')->count();
        $this->assertEquals($beforeActiveCount + $this->subscriptionRequest->children_count, $afterActiveCount);

        // 3. تحقق من إنشاء المسار في جدول routes
        $this->assertDatabaseHas('routes', [
            'driver_id' => $this->driver->id,
            'status'    => 'Active',
        ]);

        // 4. تحقق من ربط active_subscriptions بـ route_id المولد
        $route = DB::table('routes')->where('driver_id', $this->driver->id)->first();
        $this->assertNotNull($route);
        $this->assertDatabaseHas('active_subscriptions', [
            'subscription_request_id' => $updatedRequest->id,
            'route_id'                => $route->id,
            'status'                  => 'active',
        ]);
    }

    // =========================================================
    // اختبار 1.6: السائق لا يمكنه قبول طلب إذا توازت أو تجاوزت سعة المركبة المتاحة
    // =========================================================
    public function test_accepting_subscription_fails_when_vehicle_capacity_is_exceeded(): void
    {
        // تحديد سعة المركبة بـ 1 مقعد
        DB::table('vehicles')->where('driver_id', $this->driver->id)->update(['capacity_manual' => 1]);

        // السائق يقبل الطلب الأول فيمتلئ المقعد المتاح
        app(SubscriptionRequestService::class)->updateStatus($this->subscriptionRequest, 'accepted');

        // إنشاء طفل ثانٍ لطلب جديد لنفس التوقيت
        $secondChild = Child::create([
            'parent_id'           => $this->parent->id,
            'full_name'           => 'طفل الاختبار الثاني',
            'birth_date'          => '2019-01-01',
            'gender'              => 'female',
            'grade'               => 2,
            'notification_radius' => 500,
        ]);

        // إنشاء طلب جديد لنفس التوقيت
        $newReq = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'total_price'                 => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'children_count'              => 1,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => 'both',
            'start_date'                  => now()->addDays(1)->format('Y-m-d'),
            'end_date'                    => now()->addMonth()->format('Y-m-d'),
            'working_days_count'          => 22,
            'home_label'                  => 'منزل ولي الأمر',
            'home_lat'                    => 32.8800,
            'home_lng'                    => 13.1900,
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $newReq->id,
            'child_id'                    => $secondChild->id,
            'school_id'                   => $this->school->id,
            'timing'                      => 'MORNING',
            'school_label'                => 'مدرسة الاختبار',
            'school_lat'                  => 32.9000,
            'school_lng'                  => 13.2000,
            'distance_km'                 => 4.0,
            'price_per_child'             => 200.00,
            'trip_price'                  => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'driver_net_price'            => 184.00,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        // محاولة قبول طلب جديد يتطلب مقعداً يفشل بسبب تجاوز السعة
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/{$newReq->id}/status", [
                'status' => 'accepted',
            ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('لا توجد مقاعد كافية', $response->json('message'));
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 2: ط§ظ„ط³ط§ط¦ظ‚ ظٹط±ظپط¶ ط§ظ„ط·ظ„ط¨ ط¨ظ†ط¬ط§ط­ ظ…ط¹ ط³ط¨ط¨ ط§ظ„ط±ظپط¶
    // =========================================================
    public function test_driver_can_reject_subscription_request_with_reason(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
                'status'           => 'rejected',
                'rejection_reason' => 'ط§ظ„ظ…ظ†ط·ظ‚ط© ط¨ط¹ظٹط¯ط© ط¹ظ† ظ…ط³ط§ط±ظٹ ط§ظ„ظٹظˆظ…ظٹ.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // طھط­ظ‚ظ‚ ظ…ظ† طھط­ط¯ظٹط« ط§ظ„ط­ط§ظ„ط© ظˆط³ط¨ط¨ ط§ظ„ط±ظپط¶ ظپظٹ ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ
        $this->assertDatabaseHas('requests', [
            'id'               => $this->subscriptionRequest->id,
            'status'           => 'rejected',
            'rejection_reason' => 'ط§ظ„ظ…ظ†ط·ظ‚ط© ط¨ط¹ظٹط¯ط© ط¹ظ† ظ…ط³ط§ط±ظٹ ط§ظ„ظٹظˆظ…ظٹ.',
        ]);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 3: ط±ظپط¶ ط¨ط¯ظˆظ† ط³ط¨ط¨ ظٹظپط´ظ„ ط¨ظ€ 422
    // =========================================================
    public function test_reject_without_reason_fails_validation(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
                'status' => 'rejected',
                // rejection_reason ظ…ظپظ‚ظˆط¯ ط¹ظ…ط¯ط§ظ‹
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rejection_reason']);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 4: ط·ظ„ط¨ ط¨ظ€ status ط؛ظٹط± طµط§ظ„ط­ ظٹظپط´ظ„ ط¨ظ€ 422
    // =========================================================
    public function test_invalid_status_fails_validation(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
                'status' => 'pending', // ط؛ظٹط± ظ…ط³ظ…ظˆط­ ط¨ظ‡
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 5: ظ…ط­ط§ظˆظ„ط© ظ‚ط¨ظˆظ„ ط·ظ„ط¨ ظ„ظٹط³ ظ…ط®طµطµط§ظ‹ ظ„ظ„ط³ط§ط¦ظ‚ طھظڈط±ظپط¶ ط¨ظ€ 403
    // =========================================================
    public function test_driver_cannot_accept_another_drivers_request(): void
    {
        // ط¥ظ†ط´ط§ط، ط³ط§ط¦ظ‚ ط¢ط®ط±
        $anotherDriverUser = User::create([
            'full_name'    => 'ط³ط§ط¦ظ‚ ط¢ط®ط±',
            'email'        => 'another.driver.' . uniqid() . '@darby.test',
            'phone_number' => '095' . rand(1000000, 9999999),
            'password_hash'=> bcrypt('password123'),
            'role_id'      => 2,
            'is_active'    => 1,
        ]);

        Driver::create([
            'user_id'        => $anotherDriverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);

        // ط§ظ„ط³ط§ط¦ظ‚ ط§ظ„ط¢ط®ط± ظٹط­ط§ظˆظ„ ظ‚ط¨ظˆظ„ ط·ظ„ط¨ ظ„ظٹط³ ظ„ظ‡
        $response = $this->actingAs($anotherDriverUser)
            ->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
                'status' => 'accepted',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 6: ظ…ط­ط§ظˆظ„ط© ظ‚ط¨ظˆظ„ ط·ظ„ط¨ ط؛ظٹط± ظ…ظˆط¬ظˆط¯ طھظڈط±ط¬ط¹ 404
    // =========================================================
    public function test_accepting_nonexistent_request_returns_404(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/999999/status", [
                'status' => 'accepted',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 7: ظ…ط³طھط®ط¯ظ… ظ„ظٹط³ ط³ط§ط¦ظ‚ط§ظ‹ ظٹظڈط±ط¬ط¹ 403
    // =========================================================
    public function test_non_driver_user_gets_403(): void
    {
        // ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ظٹط­ط§ظˆظ„ ط§ط³طھط®ط¯ط§ظ… ظ…ط³ط§ط± ط§ظ„ط³ط§ط¦ظ‚
        $response = $this->actingAs($this->parentUser)
            ->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
                'status' => 'accepted',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================
    // ط§ط®طھط¨ط§ط± 8: ظ…ط³طھط®ط¯ظ… ط؛ظٹط± ظ…ط³ط¬ظ‘ظ„ ط¯ط®ظˆظ„ظ‡ ظٹظڈط±ط¬ط¹ 401
    // =========================================================
    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->putJson("/api/driver/{$this->subscriptionRequest->id}/status", [
            'status' => 'accepted',
        ]);

        $response->assertStatus(401);
    }
}
