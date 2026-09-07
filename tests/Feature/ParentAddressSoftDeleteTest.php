<?php

namespace Tests\Feature;

use App\Models\Parent\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentAddressSoftDeleteTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected \App\Models\Shared\Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر للاختبار',
            'email'         => 'parent.addr.' . uniqid() . '@darby.test',
            'phone_number'  => '09' . rand(10000000, 99999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $m = \App\Models\Shared\Municipality::firstOrCreate(['name' => 'بلدية اختبار']);
        $sub = \App\Models\Shared\SubMunicipality::firstOrCreate(['municipality_id' => $m->id, 'name' => 'محلة اختبار']);
        $this->zone = \App\Models\Shared\Zone::firstOrCreate(['sub_municipality_id' => $sub->id, 'name' => 'منطقة اختبار']);
    }

    /**
     * اختبار: المنطقة (zone_id) أصبحت إجبارية عند إضافة عنوان.
     */
    public function test_zone_id_is_required_when_adding_address(): void
    {
        Sanctum::actingAs($this->parentUser);

        $response = $this->postJson('/api/parent/addresses', [
            'label' => 'منزل بدون منطقة',
            'lat'   => 32.889999,
            'lng'   => 13.189999,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['zone_id']);
    }

    /**
     * اختبار: إضافة عنوان، حذفه ناعماً، ثم إمكانية إضافته مرة أخرى بنفس البيانات والإحداثيات.
     */
    public function test_parent_can_readd_previously_deleted_address(): void
    {
        Sanctum::actingAs($this->parentUser);

        $addressPayload = [
            'label'   => 'منزل الاختبار الجديد',
            'lat'     => 32.88123456,
            'lng'     => 13.18123456,
            'zone_id' => $this->zone->id,
        ];

        // 1. إضافة العنوان لأول مرة -> يجب أن ينجح (201)
        $response1 = $this->postJson('/api/parent/addresses', $addressPayload);
        $response1->assertStatus(201)
                  ->assertJson(['success' => true]);

        $createdId = $response1->json('data.id');
        $this->assertNotNull($createdId);

        // 2. محاولة إضافة نفس العنوان والعنوان لا يزال نشطاً -> يجب أن يفشل (422)
        $duplicateResponse = $this->postJson('/api/parent/addresses', $addressPayload);
        $duplicateResponse->assertStatus(422);

        // 3. حذف العنوان (Soft Delete)
        $deleteResponse = $this->deleteJson("/api/parent/addresses/{$createdId}");
        $deleteResponse->assertStatus(200);

        // التأكد من أن العنوان تم حذفه ناعماً في قاعدة البيانات
        $this->assertSoftDeleted('addresses', ['id' => $createdId]);

        // 4. إعادة إضافة نفس العنوان بنفس المسمى ونفس الإحداثيات -> يجب أن ينجح (201)
        $readdResponse = $this->postJson('/api/parent/addresses', $addressPayload);
        // العنوان المعاد إضافته هو العنوان الوحيد المتبقي لولي الأمر، فيصبح رئيسياً تلقائياً.
        $readdResponse->assertStatus(201)
                      ->assertJson([
                          'success' => true,
                          'message' => 'تم إضافة العنوان الجديد وتعيينه كعنوان رئيسي وإسناد جميع أطفالك إليه.',
                      ])
                      ->assertJsonPath('data.is_default', true);

        $newAddressId = $readdResponse->json('data.id');
        $this->assertNotEquals($createdId, $newAddressId);
        $this->assertDatabaseHas('addresses', [
            'id'         => $newAddressId,
            'user_id'    => $this->parentUser->id,
            'label'      => $addressPayload['label'],
            'deleted_at' => null,
        ]);

        // 5. محاولة إضافته مرة أخرى بينما النسخة الجديدة نشطة -> يجب أن يفشل (422)
        $failAgainResponse = $this->postJson('/api/parent/addresses', $addressPayload);
        $failAgainResponse->assertStatus(422);
    }

    /**
     * اختبار: منع إسناد طفل إلى عنوان محذوف ناعماً مع إرجاع رسالة خطأ دقيقة.
     */
    public function test_cannot_assign_child_to_deleted_address(): void
    {
        Sanctum::actingAs($this->parentUser);

        $school = \App\Models\Parent\School::create([
            'name'     => 'مدرسة النور ' . uniqid(),
            'zone_id'  => $this->zone->id,
            'lat'      => 32.880000,
            'lng'      => 13.180000,
            'address'  => 'طرابلس',
            'status'   => 'approved',
        ]);

        $address = \App\Models\Parent\Address::create([
            'user_id' => $this->parentUser->id,
            'zone_id' => $this->zone->id,
            'label'   => 'عنوان محذوف للاختبار',
            'lat'     => 32.890000,
            'lng'     => 13.190000,
        ]);
        $address->delete();

        $nextWorkingDay = now()->addDays(2);
        while ($nextWorkingDay->isFriday() || $nextWorkingDay->isSaturday()) {
            $nextWorkingDay->addDay();
        }

        $payload = [
            'school_id'           => $school->id,
            'address_id'          => $address->id,
            'full_name'           => 'عمر أحمد علي',
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 4,
            'preferred_time_slot' => 'morning',
            'trip_direction'      => 'both',
            'start_date'          => $nextWorkingDay->format('Y-m-d'),
            'end_date'            => $nextWorkingDay->copy()->addMonths(1)->format('Y-m-d'),
            'subscription_type'   => 'multi_day',
        ];

        $response = $this->postJson('/api/parent/children', $payload);

        $response->assertStatus(422)
                 ->assertJson([
                     'success' => false,
                     'message' => 'العنوان المختار تم حذفه، لا يمكن إسناد طفل لعنوان محذوف.',
                     'errors'  => [
                         'address_id' => ['العنوان المختار تم حذفه، لا يمكن إسناد طفل لعنوان محذوف.']
                     ]
                 ]);
    }

    /**
     * اختبار: إمكانية إسناد طفل إلى عنوان نشط بصورة طبيعية.
     */
    public function test_can_assign_child_to_active_address(): void
    {
        Sanctum::actingAs($this->parentUser);

        $school = \App\Models\Parent\School::create([
            'name'     => 'مدرسة النور ' . uniqid(),
            'zone_id'  => $this->zone->id,
            'lat'      => 32.880000,
            'lng'      => 13.180000,
            'address'  => 'طرابلس',
            'status'   => 'approved',
        ]);

        $address = \App\Models\Parent\Address::create([
            'user_id' => $this->parentUser->id,
            'zone_id' => $this->zone->id,
            'label'   => 'عنوان نشط للاختبار',
            'lat'     => 32.895000,
            'lng'     => 13.195000,
        ]);

        $nextWorkingDay = now()->addDays(2);
        while ($nextWorkingDay->isFriday() || $nextWorkingDay->isSaturday()) {
            $nextWorkingDay->addDay();
        }

        $payload = [
            'school_id'           => $school->id,
            'address_id'          => $address->id,
            'full_name'           => 'سالم أحمد علي',
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 4,
            'preferred_time_slot' => 'morning',
            'trip_direction'      => 'both',
            'start_date'          => $nextWorkingDay->format('Y-m-d'),
            'end_date'            => $nextWorkingDay->copy()->addMonths(1)->format('Y-m-d'),
            'subscription_type'   => 'multi_day',
        ];

        $response = $this->postJson('/api/parent/children', $payload);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);
    }

    /**
     * اختبار: منع تكرار إضافة طفل مضاف مسبقاً لنفس ولي الأمر مع إرجاع 422 ورسالة دقيقة.
     */
    public function test_cannot_add_duplicate_child_for_same_parent(): void
    {
        Sanctum::actingAs($this->parentUser);

        $school = \App\Models\Parent\School::create([
            'name'     => 'مدرسة النور ' . uniqid(),
            'zone_id'  => $this->zone->id,
            'lat'      => 32.880000,
            'lng'      => 13.180000,
            'address'  => 'طرابلس',
            'status'   => 'approved',
        ]);

        $address = \App\Models\Parent\Address::create([
            'user_id' => $this->parentUser->id,
            'zone_id' => $this->zone->id,
            'label'   => 'عنوان نشط مكرر',
            'lat'     => 32.895000,
            'lng'     => 13.195000,
        ]);

        $nextWorkingDay = now()->addDays(2);
        while ($nextWorkingDay->isFriday() || $nextWorkingDay->isSaturday()) {
            $nextWorkingDay->addDay();
        }

        $payload = [
            'school_id'           => $school->id,
            'address_id'          => $address->id,
            'full_name'           => 'حسين طارق الفيتوري',
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 5,
            'preferred_time_slot' => 'morning',
            'trip_direction'      => 'both',
            'start_date'          => $nextWorkingDay->format('Y-m-d'),
            'end_date'            => $nextWorkingDay->copy()->addMonths(1)->format('Y-m-d'),
            'subscription_type'   => 'multi_day',
        ];

        // 1. الإضافة الأولى بنجاح
        $firstResponse = $this->postJson('/api/parent/children', $payload);
        $firstResponse->assertStatus(201);

        // 2. المحاولة الثانية بنفس الاسم لنفس ولي الأمر -> يجب أن ترجع 422 برسالة الخطأ الصحيحة
        $duplicateResponse = $this->postJson('/api/parent/children', $payload);
        $duplicateResponse->assertStatus(422)
                           ->assertJson([
                               'success' => false,
                               'message' => 'هذا الطفل مضاف مسبقاً في حسابك.',
                               'errors'  => [
                                   'full_name' => ['هذا الطفل مضاف مسبقاً في حسابك.']
                               ]
                           ]);
    }

    /**
     * اختبار: منع مسح عنوان مرتبط بطفل مع إرجاع رسالة خطأ واضحة.
     */
    public function test_cannot_delete_address_assigned_to_child(): void
    {
        Sanctum::actingAs($this->parentUser);

        $school = \App\Models\Parent\School::create([
            'name'     => 'مدرسة النور ' . uniqid(),
            'zone_id'  => $this->zone->id,
            'lat'      => 32.880000,
            'lng'      => 13.180000,
            'address'  => 'طرابلس',
            'status'   => 'approved',
        ]);

        $address = \App\Models\Parent\Address::create([
            'user_id' => $this->parentUser->id,
            'zone_id' => $this->zone->id,
            'label'   => 'عنوان منزل الطفل المرتبط',
            'lat'     => 32.895000,
            'lng'     => 13.195000,
        ]);

        $child = \App\Models\Parent\Child::create([
            'parent_id'           => $this->parentUser->id,
            'school_id'           => $school->id,
            'address_id'          => $address->id,
            'full_name'           => 'طارق عمر الفيتوري',
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 5,
            'preferred_time_slot' => 'morning',
        ]);

        // محاولة حذف العنوان المرتبط بالطفل أعلاه
        $deleteResponse = $this->deleteJson("/api/parent/addresses/{$address->id}");

        $deleteResponse->assertStatus(422)
                       ->assertJson([
                           'success' => false,
                           'message' => 'لا يمكن حذف هذا العنوان لأنه مرتبط بطفل مضاف في حسابك.'
                       ]);

        // التأكد من أن العنوان لم يتم حذفه وظل موجوداً ونشطاً
        $this->assertDatabaseHas('addresses', [
            'id'         => $address->id,
            'deleted_at' => null,
        ]);
    }
}
