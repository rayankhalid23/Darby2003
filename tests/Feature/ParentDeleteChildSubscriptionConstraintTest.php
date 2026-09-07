<?php

namespace Tests\Feature;

use App\Models\Driver\Driver;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Municipality;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Zone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentDeleteChildSubscriptionConstraintTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected School $school;
    protected Address $address;
    protected Zone $zone;
    protected Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر للاختبار ' . uniqid(),
            'email'         => 'parent.del.' . uniqid() . '@darby.test',
            'phone_number'  => '09' . rand(10000000, 99999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $driverUser = User::create([
            'full_name'     => 'سائق تجريبي ' . uniqid(),
            'email'         => 'driver.del.' . uniqid() . '@darby.test',
            'phone_number'  => '09' . rand(10000000, 99999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 4,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'                 => $driverUser->id,
            'national_id'             => '11988' . rand(1000000, 9999999),
            'license_number'          => 'LY-' . rand(1000, 9999),
            'status'                  => 'Approved',
            'subscription_type'       => 'both',
            'morning_go'              => 1,
            'morning_return'          => 1,
            'afternoon_go'            => 1,
            'afternoon_return'        => 1,
            'morning_go_capacity'     => 10,
            'morning_return_capacity' => 10,
            'evening_go_capacity'     => 10,
            'evening_return_capacity' => 10,
        ]);

        $m = Municipality::firstOrCreate(['name' => 'بلدية اختبار طرابلس']);
        $sub = SubMunicipality::firstOrCreate(['municipality_id' => $m->id, 'name' => 'محلة اختبار النصر']);
        $this->zone = Zone::firstOrCreate(['sub_municipality_id' => $sub->id, 'name' => 'منطقة اختبار النصر']);

        $this->school = School::create([
            'name'     => 'مدرسة النور ' . uniqid(),
            'zone_id'  => $this->zone->id,
            'lat'      => 32.880000,
            'lng'      => 13.180000,
            'address'  => 'طرابلس',
            'status'   => 'approved',
        ]);

        $this->address = Address::create([
            'user_id' => $this->parentUser->id,
            'zone_id' => $this->zone->id,
            'label'   => 'منزل الاختبار',
            'lat'     => 32.890000,
            'lng'     => 13.190000,
        ]);
    }

    private function createTestChild(string $name = 'طفل تجريبي'): Child
    {
        return Child::create([
            'parent_id'           => $this->parentUser->id,
            'school_id'           => $this->school->id,
            'address_id'          => $this->address->id,
            'full_name'           => $name . ' ' . uniqid(),
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 5,
            'preferred_time_slot' => 'morning',
        ]);
    }

    /**
     * 1. منع حذف الطفل إذا كان لديه اشتراك مفعل في active_subscriptions
     */
    public function test_cannot_delete_child_with_active_subscription_in_active_subscriptions(): void
    {
        Sanctum::actingAs($this->parentUser);
        $child = $this->createTestChild('أحمد النشط');

        $req = SubscriptionRequest::create([
            'parent_id'                   => $this->parentUser->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_ACCEPTED,
            'total_price'                 => 250.00,
            'total_amount_after_discount' => 250.00,
            'children_count'              => 1,
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'child_id'                => $child->id,
            'driver_id'               => $this->driver->id,
            'parent_id'               => $this->parentUser->id,
            'pickup_lat'              => 32.890000,
            'pickup_lng'              => 13.190000,
            'pickup_label'            => 'المنزل',
            'dropoff_lat'             => 32.880000,
            'dropoff_lng'             => 13.180000,
            'dropoff_label'           => 'المدرسة',
            'status'                  => 'active',
        ]);

        $response = $this->deleteJson("/api/parent/children/{$child->id}");

        $response->assertStatus(422)
                 ->assertJson([
                     'success' => false,
                     'message' => 'لا يمكن حذف الطفل لوجود اشتراك مفعل أو مجدول مرتبط به.',
                 ]);

        // التأكد من أن الطفل لم يتم حذفه
        $this->assertDatabaseHas('children', [
            'id'         => $child->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * 2. منع حذف الطفل إذا كان لديه اشتراك مجدول (Accepted Request يبدأ في المستقبل)
     */
    public function test_cannot_delete_child_with_scheduled_subscription(): void
    {
        Sanctum::actingAs($this->parentUser);
        $child = $this->createTestChild('علي المجدول');

        $req = SubscriptionRequest::create([
            'parent_id'                   => $this->parentUser->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_ACCEPTED,
            'total_price'                 => 250.00,
            'total_amount_after_discount' => 250.00,
            'children_count'              => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'        => $req->id,
            'child_id'          => $child->id,
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->addDays(5)->format('Y-m-d'),
            'end_date'          => now()->addDays(35)->format('Y-m-d'),
            'price_per_child'   => 250.00,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->deleteJson("/api/parent/children/{$child->id}");

        $response->assertStatus(422)
                 ->assertJson([
                     'success' => false,
                     'message' => 'لا يمكن حذف الطفل لوجود اشتراك مفعل أو مجدول مرتبط به.',
                 ]);

        // التأكد من عدم حذف الطفل
        $this->assertDatabaseHas('children', [
            'id'         => $child->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * 3. السماح بحذف الطفل وإلغاء كافة طلبات الاشتراك المعلقة (Pending & Acquired) تلقائياً
     */
    public function test_can_delete_child_and_cancel_pending_subscription_requests(): void
    {
        Sanctum::actingAs($this->parentUser);
        $child = $this->createTestChild('سامي المعلق');

        // طلب أول: pending
        $req1 = SubscriptionRequest::create([
            'parent_id'                   => $this->parentUser->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'total_price'                 => 100.00,
            'total_amount_after_discount' => 100.00,
            'children_count'              => 1,
        ]);
        DB::table('request_children')->insert([
            'request_id'        => $req1->id,
            'child_id'          => $child->id,
            'subscription_type' => 'single_day',
            'trip_direction'    => 'both',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // طلب ثاني: acquired
        $req2 = SubscriptionRequest::create([
            'parent_id'                   => $this->parentUser->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_ACQUIRED,
            'total_price'                 => 120.00,
            'total_amount_after_discount' => 120.00,
            'children_count'              => 1,
        ]);
        DB::table('request_children')->insert([
            'request_id'        => $req2->id,
            'child_id'          => $child->id,
            'subscription_type' => 'single_day',
            'trip_direction'    => 'both',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // تنفيذ الحذف
        $response = $this->deleteJson("/api/parent/children/{$child->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ]);

        // 1. التأكد من حذف الطفل ناعماً
        $this->assertSoftDeleted('children', ['id' => $child->id]);

        // 2. التأكد من إلغاء كافة الطلبات المعلقة المرتبطة به
        $this->assertDatabaseHas('requests', [
            'id'     => $req1->id,
            'status' => SubscriptionRequest::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('requests', [
            'id'     => $req2->id,
            'status' => SubscriptionRequest::STATUS_CANCELLED,
        ]);
    }

    /**
     * 4. حذف طفل لا يملك أي طلبات أو اشتراكات يتم بنجاح
     */
    public function test_can_delete_child_with_no_subscriptions(): void
    {
        Sanctum::actingAs($this->parentUser);
        $child = $this->createTestChild('سالم الحر');

        $response = $this->deleteJson("/api/parent/children/{$child->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ]);

        $this->assertSoftDeleted('children', ['id' => $child->id]);
    }
}
