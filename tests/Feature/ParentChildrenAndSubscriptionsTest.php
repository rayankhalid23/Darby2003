<?php

namespace Tests\Feature;

use App\Models\Parent\Child;
use App\Models\Parent\ChildLogistics;
use App\Models\Parent\ParentModel;
use App\Models\Driver\Driver;
use App\Models\Parent\School;
use App\Models\Shared\Zone;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Municipality;
use App\Models\Parent\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ParentChildrenAndSubscriptionsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $driverUser;
    protected Driver $driver;
    protected School $school;
    protected Address $address;
    protected Address $schoolAddress;
    protected Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. إنشاء منطقة جغرافية ومدرسة
        $municipality = Municipality::firstOrCreate(['name' => 'طرابلس المركز']);
        $subMuni = SubMunicipality::firstOrCreate(['municipality_id' => $municipality->id, 'name' => 'الظهرة']);
        $this->zone = Zone::firstOrCreate(['sub_municipality_id' => $subMuni->id, 'name' => 'شارع النصر']);

        $this->school = School::create([
            'name'         => 'مدرسة النور النموذجية',
            'zone_id'      => $this->zone->id,
            'lat'          => 32.88000000,
            'lng'          => 13.18000000,
            'address'      => 'طرابلس - شارع النصر',
            'status'       => 'approved',
        ]);

        // 2. إنشاء حساب ولي أمر
        $this->parentUser = User::create([
            'full_name'     => 'أحمد سالم الفيتوري',
            'email'         => 'parent.test.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);
        $this->parent = ParentModel::create([
            'user_id'    => $this->parentUser->id,
            'is_trusted' => 1,
        ]);

        // قيمة الاشتراك تُفحص وتُحجز لكل الأنواع (وليس اليومي فقط)،
        // لذا يجب أن تكون محفظة ولي الأمر ممولة قبل إرسال الطلب.
        $this->parent->deposit(500000);

        // 3. إنشاء عنوان سكن وعنوان مدرسة لولي الأمر
        $this->address = Address::create([
            'parent_id'   => $this->parentUser->id,
            'zone_id'     => $this->zone->id,
            'label'       => 'المنزل الرئيسي',
            'lat'         => 32.88500000,
            'lng'         => 13.18500000,
            'is_default'  => true,
        ]);

        $this->schoolAddress = Address::create([
            'parent_id'   => $this->parentUser->id,
            'zone_id'     => $this->zone->id,
            'label'       => 'موقع المدرسة',
            'lat'         => 32.88000000,
            'lng'         => 13.18000000,
            'is_default'  => false,
        ]);

        // 4. إنشاء حساب سائق
        $this->driverUser = User::create([
            'full_name'     => 'الكابتن عبد السلام الزنتاني',
            'email'         => 'driver.test.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 4,
            'is_active'     => 1,
        ]);
        $this->driver = Driver::create([
            'user_id'                 => $this->driverUser->id,
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

        DB::table('driver_seat_slots')->insert([
            ['driver_id' => $this->driver->id, 'slot' => 'morning_go', 'total_seats' => 10, 'reserved_seats' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['driver_id' => $this->driver->id, 'slot' => 'morning_return', 'total_seats' => 10, 'reserved_seats' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ربط السائق بالمنطقة
        DB::table('driver_zone')->insert([
            'driver_id' => $this->driver->id,
            'zone_id'   => $this->zone->id,
        ]);
    }

    /**
     * 1. اختبار التحقق من وجود أطفال لولي الأمر (has-children)
     */
    public function test_check_has_children_returns_correct_boolean(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/children/has-children');

        $response->assertStatus(200)
            ->assertJson([
                'success'      => true,
                'has_children' => false,
            ]);
    }

    /**
     * 2. اختبار إضافة طفل جديد مع بيانات النقل اللوجستية (Add Child)
     */
    public function test_add_child_with_logistics_successfully(): void
    {
        $payload = [
            'school_id'           => $this->school->id,
            'address_id'          => $this->address->id,
            'full_name'           => 'محمد أحمد الفيتوري',
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 4,
            'medical_notes'       => 'حساسية من الغبار',
            'notification_radius' => 500,
            'preferred_time_slot' => 'morning',
            'pickup_time'         => '07:15',
            'dropoff_time'        => '13:30',
        ];

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/children', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'تم إضافة بيانات الطفل بنجاح.',
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'full_name',
                    'gender',
                    'grade',
                    'school',
                    'address',
                    'logistics',
                ]
            ]);

        $this->assertDatabaseHas('children', [
            'full_name' => 'محمد أحمد الفيتوري',
            'parent_id' => $this->parent->id,
            'gender'    => 'male',
        ]);
    }

    /**
     * 3. اختبار جلب قائمة أطفال ولي الأمر وعرض تفاصيل طفل محدد
     */
    public function test_list_and_show_child(): void
    {
        $child = Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school->id,
            'address_id'          => $this->address->id,
            'full_name'           => 'فاطمة أحمد الفيتوري',
            'birth_date'          => '2016-08-15',
            'gender'              => 'female',
            'grade'               => 3,
            'notification_radius' => 500,
            'qr_code_token'       => 'QR_' . uniqid(),
        ]);

        ChildLogistics::create([
            'child_id'            => $child->id,
            'preferred_time_slot' => 'both',
            'trip_direction'      => 'both',
            'start_date'          => now()->addDays(2)->format('Y-m-d'),
            'end_date'            => now()->addMonths(2)->format('Y-m-d'),
            'subscription_type'   => 'multi_day',
        ]);

        // قائمة الأطفال
        $listRes = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/children');

        $listRes->assertStatus(200)
            ->assertJsonPath('success', true);

        // عرض تفاصيل طفل محدد
        $showRes = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson("/api/parent/children/{$child->id}");

        $showRes->assertStatus(200)
            ->assertJsonPath('data.full_name', 'فاطمة أحمد الفيتوري')
            ->assertJsonPath('data.gender', 'female');

        // عرض بيانات النقل واللوجستيات
        $subRes = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson("/api/parent/children/{$child->id}/subscription");

        $subRes->assertStatus(200)
            ->assertJsonPath('data.preferred_time_slot', 'both');
    }

    /**
     * 4. اختبار تعديل بيانات الطفل والنقل كاملاً
     */
    public function test_update_child_and_logistics_successfully(): void
    {
        $child = Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school->id,
            'address_id'          => $this->address->id,
            'full_name'           => 'علي أحمد الفيتوري',
            'birth_date'          => '2014-02-10',
            'gender'              => 'male',
            'grade'               => 5,
            'notification_radius' => 500,
            'qr_code_token'       => 'QR_' . uniqid(),
        ]);

        ChildLogistics::create([
            'child_id'            => $child->id,
            'preferred_time_slot' => 'morning',
            'trip_direction'      => 'go',
            'start_date'          => now()->addDays(2)->format('Y-m-d'),
            'end_date'            => now()->addMonth()->format('Y-m-d'),
            'subscription_type'   => 'multi_day',
        ]);

        $updatePayload = [
            'full_name'           => 'علي أحمد الفيتوري المعدل',
            'grade'               => 6,
            'preferred_time_slot' => 'both',
            'trip_direction'      => 'both',
            'notification_radius' => 600,
        ];

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson("/api/parent/children/{$child->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'تم تحديث بيانات الطفل بنجاح.',
            ]);

        $this->assertDatabaseHas('children', [
            'id'        => $child->id,
            'full_name' => 'علي أحمد الفيتوري المعدل',
            'grade'     => 6,
        ]);
    }

    /**
     * 5. اختبار حذف طفل
     */
    public function test_delete_child_successfully(): void
    {
        $child = Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school->id,
            'address_id'          => $this->address->id,
            'full_name'           => 'سارة أحمد الفيتوري',
            'birth_date'          => '2017-09-01',
            'gender'              => 'female',
            'grade'               => 2,
            'notification_radius' => 500,
            'qr_code_token'       => 'QR_' . uniqid(),
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->deleteJson("/api/parent/children/{$child->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'تم حذف بيانات الطفل وإلغاء اشتراكه بنجاح.',
            ]);

        $this->assertDatabaseMissing('children', [
            'id' => $child->id,
        ]);
    }

    /**
     * 6. اختبار إرسال طلب اشتراك، جلب الطلبات، وإلغاء طلب اشتراك معلق
     */
    public function test_parent_subscription_request_lifecycle(): void
    {
        $child = Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school->id,
            'address_id'          => $this->address->id,
            'full_name'           => 'طارق أحمد الفيتوري',
            'birth_date'          => '2015-01-01',
            'gender'              => 'male',
            'grade'               => 4,
            'notification_radius' => 500,
            'qr_code_token'       => 'QR_' . uniqid(),
        ]);

        // إرسال طلب اشتراك
        $reqPayload = [
            'driver_id'         => $this->driver->id,
            'school_id'         => $this->school->id,
            'subscription_type' => 'multi_day',
            'direction'         => 'both',
            'timing'            => 'MORNING',
            'start_date'        => now()->addDays(2)->format('Y-m-d'),
            'end_date'          => now()->addMonth()->format('Y-m-d'),
            'children'          => [
                [
                    'child_id'          => $child->id,
                    'subscription_type' => 'multi_day',
                    'trip_direction'    => 'both',
                    'timing'            => 'MORNING',
                    'start_date'        => now()->addDays(2)->format('Y-m-d'),
                    'end_date'          => now()->addMonth()->format('Y-m-d'),
                    'price_per_child'   => 150.00,
                ]
            ]
        ];

        $storeRes = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent', $reqPayload);

        $storeRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'تم إرسال طلب الاشتراك بنجاح.',
            ]);

        $requestId = $storeRes->json('data.id');

        // جلب قائمة الطلبات
        $listReqRes = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/requests');

        $listReqRes->assertStatus(200)
            ->assertJsonPath('success', true);

        // إلغاء الطلب المعلق
        $cancelRes = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson("/api/parent/requests/{$requestId}/cancel");

        $cancelRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'تم إلغاء طلب الاشتراك بنجاح.',
            ]);

        $this->assertDatabaseHas('requests', [
            'id'     => $requestId,
            'status' => 'cancelled',
        ]);
    }
}
