<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use Illuminate\Support\Facades\Hash;

class ParentContractedDriversTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $driverUser1;
    protected Driver $driver1;
    protected User $driverUser2;
    protected Driver $driver2;
    protected User $driverUser3;
    protected Driver $driver3;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. إنشاء حساب ولي أمر
        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر للاختبار ' . uniqid(),
            'email'         => 'test.parent.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 7, // parent
            'is_active'     => 1,
        ]);
        $this->parent = ParentModel::find($this->parentUser->id) ?? new ParentModel();

        // 2. سائق 1: سيكون لديه طلب معلق فقط (pending)
        $this->driverUser1 = User::create([
            'full_name'     => 'سائق طلب معلق ' . uniqid(),
            'email'         => 'driver.pending.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 8, // driver
            'is_active'     => 1,
        ]);
        $this->driver1 = Driver::create([
            'user_id'        => $this->driverUser1->id,
            'national_id'    => '1199' . rand(100000, 999999),
            'license_number' => 'LY-' . rand(1000, 9999),
            'status'         => 'Approved',
        ]);

        // 3. سائق 2: سيكون لديه اشتراك نشط (active)
        $this->driverUser2 = User::create([
            'full_name'     => 'سائق اشتراك نشط ' . uniqid(),
            'email'         => 'driver.active.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 8, // driver
            'is_active'     => 1,
        ]);
        $this->driver2 = Driver::create([
            'user_id'        => $this->driverUser2->id,
            'national_id'    => '1199' . rand(100000, 999999),
            'license_number' => 'LY-' . rand(1000, 9999),
            'status'         => 'Approved',
        ]);

        // 4. سائق 3: لديه اشتراك مكتمل + طلب جديد معلق
        $this->driverUser3 = User::create([
            'full_name'     => 'سائق مكتمل ومعلق ' . uniqid(),
            'email'         => 'driver.comp.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 8, // driver
            'is_active'     => 1,
        ]);
        $this->driver3 = Driver::create([
            'user_id'        => $this->driverUser3->id,
            'national_id'    => '1199' . rand(100000, 999999),
            'license_number' => 'LY-' . rand(1000, 9999),
            'status'         => 'Approved',
        ]);
    }

    /**
     * اختبار 1: السائق الذي لديه طلب معلق فقط (pending) لا يظهر إطلاقاً في قائمة السائقين
     */
    public function test_driver_with_only_pending_request_is_not_returned(): void
    {
        // إنشاء طلب اشتراك بحالة pending مع السائق 1
        SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver1->id,
            'status'            => 'pending',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->addDays(2)->toDateString(),
            'end_date'          => now()->addDays(30)->toDateString(),
            'total_price'       => 300,
        ]);

        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/subscriptions/drivers');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('count', 0);
        $this->assertEmpty($response->json('data'));

        // التأكد أيضاً من المسار البديل /api/parent/drivers
        $responseAlt = $this->actingAs($this->parentUser)->getJson('/api/parent/drivers');
        $responseAlt->assertStatus(200);
        $responseAlt->assertJsonPath('count', 0);
    }

    /**
     * اختبار 2: السائق الذي لديه اشتراك نشط يظهر بحالة active
     */
    public function test_driver_with_active_subscription_is_returned_with_active_status(): void
    {
        $request = SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver2->id,
            'status'            => 'accepted',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->subDays(5)->toDateString(),
            'end_date'          => now()->addDays(25)->toDateString(),
            'total_price'       => 400,
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $request->id,
            'status'                  => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/drivers');

        $response->assertStatus(200);
        $response->assertJsonPath('count', 1);

        $driverData = $response->json('data.0');
        $this->assertEquals($this->driver2->id, $driverData['id']);
        $this->assertEquals('active', $driverData['subscription_status']);
        $this->assertEquals('اشتراك نشط', $driverData['subscription_status_label']);
        $this->assertContains('active', $driverData['available_statuses']);
        $this->assertNotContains('pending', $driverData['available_statuses']);
    }

    /**
     * اختبار 3: السائق الذي لديه اشتراك سابق مكتمل وطلب جديد معلق
     * يظهر بحالة completed فقط ولا تظهر له حالة pending إطلاقاً
     */
    public function test_driver_with_completed_sub_and_new_pending_request_shows_only_completed(): void
    {
        // 1. اشتراك قديم مكتمل
        $completedReq = SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver3->id,
            'status'            => 'accepted',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->subDays(60)->toDateString(),
            'end_date'          => now()->subDays(30)->toDateString(),
            'total_price'       => 350,
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $completedReq->id,
            'status'                  => 'completed',
        ]);

        // 2. طلب اشتراك جديد معلق مع نفس السائق
        SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver3->id,
            'status'            => 'pending',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->addDays(1)->toDateString(),
            'end_date'          => now()->addDays(30)->toDateString(),
            'total_price'       => 400,
        ]);

        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/drivers');

        $response->assertStatus(200);
        $response->assertJsonPath('count', 1);

        $driverData = $response->json('data.0');
        $this->assertEquals($this->driver3->id, $driverData['id']);
        $this->assertEquals('completed', $driverData['subscription_status']);
        $this->assertEquals('اشتراك مكتمل', $driverData['subscription_status_label']);
        $this->assertNotContains('pending', $driverData['available_statuses']);
        $this->assertContains('completed', $driverData['available_statuses']);
        $this->assertEquals(1, $driverData['subscriptions_count']);
    }

    /**
     * اختبار 4: الفلترة بـ filter=pending تعيد قائمة فارغة
     */
    public function test_filtering_by_pending_returns_empty_list(): void
    {
        // إضافة طلب معلق للسائق 1
        SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver1->id,
            'status'            => 'pending',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->addDays(2)->toDateString(),
            'end_date'          => now()->addDays(30)->toDateString(),
            'total_price'       => 300,
        ]);

        // إضافة اشتراك نشط للسائق 2
        $request2 = SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver2->id,
            'status'            => 'accepted',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->subDays(5)->toDateString(),
            'end_date'          => now()->addDays(25)->toDateString(),
            'total_price'       => 400,
        ]);
        ActiveSubscription::create([
            'subscription_request_id' => $request2->id,
            'status'                  => 'active',
        ]);

        // استدعاء الفلترة بـ pending
        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/drivers?filter=pending');
        $response->assertStatus(200);
        $response->assertJsonPath('count', 0);
        $this->assertEmpty($response->json('data'));

        // استدعاء الفلترة بـ active
        $responseActive = $this->actingAs($this->parentUser)->getJson('/api/parent/drivers?filter=active');
        $responseActive->assertStatus(200);
        $responseActive->assertJsonPath('count', 1);
        $this->assertEquals($this->driver2->id, $responseActive->json('data.0.id'));
    }

    /**
     * اختبار 5: الطلبات الملغاة من ولي الأمر أو المرفوضة من السائق تظهر بحالتها الصحيحة
     */
    public function test_cancelled_requests_are_returned_with_correct_status(): void
    {
        // طلب تم إلغاؤه من ولي الأمر
        SubscriptionRequest::create([
            'parent_id'         => $this->parentUser->id,
            'driver_id'         => $this->driver1->id,
            'status'            => 'cancelled',
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->addDays(2)->toDateString(),
            'end_date'          => now()->addDays(30)->toDateString(),
            'total_price'       => 300,
        ]);

        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/drivers');
        $response->assertStatus(200);
        $response->assertJsonPath('count', 1);

        $driverData = $response->json('data.0');
        $this->assertEquals($this->driver1->id, $driverData['id']);
        $this->assertEquals('cancelled_by_parent', $driverData['subscription_status']);
        $this->assertEquals('اشتراك ملغي من ولي الأمر', $driverData['subscription_status_label']);
    }
}
