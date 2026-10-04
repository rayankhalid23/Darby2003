<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;

class SubscriptionRequestFullLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $driverUser;
    protected Driver $driver;
    protected School $school;
    protected int $addressId;
    protected Child $child1;
    protected Child $child2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        // تثبيت إعدادات التسعير: السعر يُحسب على الخادم، فترك الإعدادات لما
        // تصادف وجوده في قاعدة البيانات يجعل كل رقم متوقع في هذا الملف عشوائياً.
        \App\Models\Shared\PricingSetting::query()->delete();
        \App\Models\Shared\PricingSetting::create([
            'discount_one_child'           => 0.00,
            'discount_two_children'        => 10.00,
            'discount_three_plus_children' => 15.00,
            'platform_commission_rate'     => 8.00,
            'price_per_km_ac'              => 2.50,
            'price_per_km_non_ac'          => 2.00,
        ]);

        // 1. Driver User & Model
        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي دورة حياة',
            'email'         => 'driver.life.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('secret123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'          => $this->driverUser->id,
            'national_id'      => 'NAT' . rand(100000, 999999),
            'license_number'   => 'LIC' . rand(100000, 999999),
            'license_expiry'   => now()->addYears(3)->format('Y-m-d'),
            'status'           => 'Approved',
            'current_lat'      => 32.8872,
            'current_lng'      => 13.1932,
            'morning_go'       => 1,
            'morning_return'   => 1,
            'afternoon_go'     => 1,
            'afternoon_return' => 1,
        ]);

        DB::table('vehicles')->insert([
            'driver_id'       => $this->driver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هايس',
            'year'            => 2023,
            'color'           => 'أبيض',
            'plate_number'    => 'LIFE-' . rand(1000, 9999),
            'capacity_manual' => 12,
            'status'          => 'Active',
            'deleted_at'      => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // 2. Parent User & Model
        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر دورة حياة',
            'email'         => 'parent.life.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('secret123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $this->parent = ParentModel::find($this->parentUser->id);

        // قيمة الاشتراك تُفحص وتُحجز لكل الأنواع (وليس اليومي فقط)،
        // لذا يجب أن تكون محفظة ولي الأمر ممولة قبل إرسال الطلب.
        $this->parent->deposit(500000);

        // 3. School
        $this->school = School::create([
            'name'    => 'مدرسة داربي النموذجية',
            'address' => 'طريق الشط، طرابلس',
            'lat'     => 32.8950,
            'lng'     => 13.1950,
            'status'  => 'Approved',
        ]);

        // 4. Address
        $this->addressId = DB::table('addresses')->insertGetId([
            'parent_id'  => $this->parentUser->id,
            'label'      => 'المنزل الرئيسي',
            'lat'        => 32.8800,
            'lng'        => 13.1800,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Children
        $this->child1 = Child::create([
            'parent_id' => $this->parent->id,
            'school_id' => $this->school->id,
            'full_name' => 'أحمد طفل 1',
            'birth_date'=> '2016-04-12',
            'gender'    => 'male',
            'grade'     => 3,
        ]);

        $this->child2 = Child::create([
            'parent_id' => $this->parent->id,
            'school_id' => $this->school->id,
            'full_name' => 'فاطمة طفل 2',
            'birth_date'=> '2018-09-20',
            'gender'    => 'female',
            'grade'     => 1,
        ]);
    }

    /**
     * 1. إنشاء طلب اشتراك جديد من ولي الأمر
     */
    public function test_parent_can_create_subscription_request_for_multiple_children(): void
    {
        $payload = [
            'driver_id'   => $this->driver->id,
            'total_price' => 500.00,
            'notes'       => 'ملاحظات تجريبية للطلب',
            'children'    => [
                [
                    'child_id'          => $this->child1->id,
                    'subscription_type' => 'multi_day',
                    'trip_direction'    => 'both',
                    'timing'            => 'MORNING',
                    // نافذة ثابتة (الأحد 3 يناير -> الخميس 14 يناير) = 10 أيام عمل.
                    // 5 كم × 2.50 د.ل/كم = 12.50 للاتجاه × 2 = 25 لليوم × 10 = 250 د.ل للطفل.
                    'start_date'        => '2027-01-03',
                    'end_date'          => '2027-01-14',
                    'distance_km'       => 5.0,
                ],
                [
                    'child_id'          => $this->child2->id,
                    'subscription_type' => 'multi_day',
                    'trip_direction'    => 'both',
                    'timing'            => 'MORNING',
                    // نافذة ثابتة (الأحد 3 يناير -> الخميس 14 يناير) = 10 أيام عمل.
                    // 5 كم × 2.50 د.ل/كم = 12.50 للاتجاه × 2 = 25 لليوم × 10 = 250 د.ل للطفل.
                    'start_date'        => '2027-01-03',
                    'end_date'          => '2027-01-14',
                    'distance_km'       => 5.0,
                ]
            ]
        ];

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $requestId = $response->json('data.id');

        $this->assertDatabaseHas('requests', [
            'id'          => $requestId,
            'parent_id'   => $this->parent->id,
            'driver_id'   => $this->driver->id,
            'status'      => 'pending',
            'total_price' => 500.00,
        ]);

        $this->assertDatabaseHas('request_children', [
            'request_id' => $requestId,
            'child_id'   => $this->child1->id,
        ]);
        $this->assertDatabaseHas('request_children', [
            'request_id' => $requestId,
            'child_id'   => $this->child2->id,
        ]);
    }

    /**
     * 2. دورة القبول الكاملة من السائق: إنشاء ActiveSubscriptions وتوليد Routes وتحديث المقاعد
     */
    public function test_driver_accepting_request_executes_full_activation_lifecycle(): void
    {
        $request = SubscriptionRequest::create([
            'parent_id'   => $this->parent->id,
            'driver_id'   => $this->driver->id,
            'total_price' => 500.00,
            'status'      => SubscriptionRequest::STATUS_PENDING,
        ]);

        DB::table('request_children')->insert([
            [
                'request_id'                  => $request->id,
                'child_id'                    => $this->child1->id,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => 'both',
                'timing'                      => 'MORNING',
                'start_date'                  => now()->addDays(2)->format('Y-m-d'),
                'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
                'working_days_count'          => 22,
                'distance_km'                 => 5.0,
                'trip_price'                  => 25.0,
                'price_per_child'             => 250.00,
                'discount_amount'             => 0.0,
                'total_amount_after_discount' => 250.00,
                'driver_net_price'            => 230.00,
                'child_notes'                 => 'ملاحظة طفل 1',
            ],
            [
                'request_id'                  => $request->id,
                'child_id'                    => $this->child2->id,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => 'both',
                'timing'                      => 'MORNING',
                'start_date'                  => now()->addDays(2)->format('Y-m-d'),
                'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
                'working_days_count'          => 22,
                'distance_km'                 => 5.0,
                'trip_price'                  => 25.0,
                'price_per_child'             => 250.00,
                'discount_amount'             => 0.0,
                'total_amount_after_discount' => 250.00,
                'driver_net_price'            => 230.00,
                'child_notes'                 => 'ملاحظة طفل 2',
            ]
        ]);

        // قبول الطلب بواسطة السائق
        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/requests/{$request->id}/status", [
                'status' => 'accepted',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // التحقق من حالة الطلب
        $this->assertDatabaseHas('requests', [
            'id'     => $request->id,
            'status' => 'accepted',
        ]);

        // التحقق من إنشاء الاشتراكات النشطة
        $this->assertDatabaseHas('active_subscriptions', [
            'subscription_request_id' => $request->id,
            'child_id'                => $this->child1->id,
            'status'                  => 'active',
        ]);
        $this->assertDatabaseHas('active_subscriptions', [
            'subscription_request_id' => $request->id,
            'child_id'                => $this->child2->id,
            'status'                  => 'active',
        ]);

        // التحقق من إنشاء المسار في جدول routes
        $this->assertDatabaseHas('routes', [
            'subscription_request_id' => $request->id,
            'driver_id'               => $this->driver->id,
            'status'                  => 'Active',
        ]);

        // التحقق من حجز المقاعد في driver_seat_slots
        $this->assertDatabaseHas('driver_seat_slots', [
            'driver_id'      => $this->driver->id,
            'slot'           => DriverSeatSlot::MORNING_GO,
            'reserved_seats' => 2,
        ]);
        $this->assertDatabaseHas('driver_seat_slots', [
            'driver_id'      => $this->driver->id,
            'slot'           => DriverSeatSlot::MORNING_RETURN,
            'reserved_seats' => 2,
        ]);

        // 3. اختبار استعراض السائق للاشتراكات النشطة
        $driverActiveRes = $this->actingAs($this->driverUser)->getJson('/api/driver/active-subscriptions');
        $driverActiveRes->assertStatus(200);
        $driverActiveRes->assertJsonPath('status', true);
        $this->assertNotEmpty($driverActiveRes->json('data'));

        // 4. اختبار استعراض ولي الأمر للاشتراكات النشطة
        $parentActiveRes = $this->actingAs($this->parentUser)->getJson('/api/parent/active-subscriptions');
        $parentActiveRes->assertStatus(200);
        $parentActiveRes->assertJsonPath('status', true);
        $this->assertNotEmpty($parentActiveRes->json('data'));

        // 5. اختبار إلغاء أحد الاشتراكات النشطة من قبل ولي الأمر وتحرير المقعد
        $activeSub1 = ActiveSubscription::where('subscription_request_id', $request->id)
            ->where('child_id', $this->child1->id)
            ->first();

        $cancelRes = $this->actingAs($this->parentUser)
            ->postJson("/api/parent/active-subscriptions/{$activeSub1->id}/cancel");
        $cancelRes->assertStatus(200);
        $cancelRes->assertJsonPath('success', true);

        // التحقق من تغير حالة الاشتراك الملغى إلى cancelled
        $this->assertDatabaseHas('active_subscriptions', [
            'id'     => $activeSub1->id,
            'status' => 'cancelled',
        ]);

        // التحقق من خفض المقاعد المحجوزة من 2 إلى 1
        $this->assertDatabaseHas('driver_seat_slots', [
            'driver_id'      => $this->driver->id,
            'slot'           => DriverSeatSlot::MORNING_GO,
            'reserved_seats' => 1,
        ]);
        $this->assertDatabaseHas('driver_seat_slots', [
            'driver_id'      => $this->driver->id,
            'slot'           => DriverSeatSlot::MORNING_RETURN,
            'reserved_seats' => 1,
        ]);
    }

    /**
     * 3. رفض السائق لطلب الاشتراك مع سبب الرفض
     */
    public function test_driver_rejection_lifecycle(): void
    {
        $request = SubscriptionRequest::create([
            'parent_id'   => $this->parent->id,
            'driver_id'   => $this->driver->id,
            'total_price' => 250.00,
            'status'      => SubscriptionRequest::STATUS_PENDING,
        ]);

        DB::table('request_children')->insert([
            [
                'request_id'                  => $request->id,
                'child_id'                    => $this->child1->id,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => 'both',
                'timing'                      => 'MORNING',
                'start_date'                  => now()->addDays(2)->format('Y-m-d'),
                'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
                'working_days_count'          => 22,
                'distance_km'                 => 5.0,
                'trip_price'                  => 25.0,
                'price_per_child'             => 250.00,
                'discount_amount'             => 0.0,
                'total_amount_after_discount' => 250.00,
                'driver_net_price'            => 230.00,
            ]
        ]);

        $response = $this->actingAs($this->driverUser)
            ->putJson("/api/driver/requests/{$request->id}/status", [
                'status'           => 'rejected',
                'rejection_reason' => 'عدم تطابق المسار الزمني.',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('requests', [
            'id'               => $request->id,
            'status'           => 'rejected',
            'rejection_reason' => 'عدم تطابق المسار الزمني.',
        ]);

        $this->assertDatabaseMissing('active_subscriptions', [
            'subscription_request_id' => $request->id,
        ]);
    }

    /**
     * 4. إلغاء ولي الأمر لطلب اشتراك معلق
     */
    public function test_parent_can_cancel_pending_request(): void
    {
        $request = SubscriptionRequest::create([
            'parent_id'   => $this->parent->id,
            'driver_id'   => $this->driver->id,
            'total_price' => 250.00,
            'status'      => SubscriptionRequest::STATUS_PENDING,
        ]);

        DB::table('request_children')->insert([
            [
                'request_id'                  => $request->id,
                'child_id'                    => $this->child1->id,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => 'both',
                'timing'                      => 'MORNING',
                'start_date'                  => now()->addDays(2)->format('Y-m-d'),
                'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
                'working_days_count'          => 22,
                'distance_km'                 => 5.0,
                'trip_price'                  => 25.0,
                'price_per_child'             => 250.00,
                'discount_amount'             => 0.0,
                'total_amount_after_discount' => 250.00,
                'driver_net_price'            => 230.00,
            ]
        ]);

        $response = $this->actingAs($this->parentUser)
            ->postJson("/api/parent/requests/{$request->id}/cancel");

        $response->assertStatus(200);
        $this->assertDatabaseHas('requests', [
            'id'     => $request->id,
            'status' => 'cancelled',
        ]);
    }
}