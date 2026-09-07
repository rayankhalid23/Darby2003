<?php

namespace Tests\Feature;

use App\Models\Driver\Driver;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\ParentModel;
use App\Models\Parent\School;
use App\Models\Shared\Municipality;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Zone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اختبارات العنوان الرئيسي (is_default) لدفتر عناوين ولي الأمر.
 *
 * قواعد العمل المُختبَرة:
 *   1. عنوان رئيسي واحد فقط لكل ولي أمر، وأول عنوان يصبح رئيسياً تلقائياً.
 *   2. كل أطفال ولي الأمر يُسنَدون تلقائياً للعنوان الرئيسي.
 *   3. تغيير العنوان الرئيسي مرفوض عند وجود اشتراك مفعّل لأي طفل.
 *   4. عند عدم وجود اشتراكات مفعّلة لكن وجود طلبات معلّقة ⇒ تُلغى كلها ويتم التغيير.
 *   5. لا يمكن حذف العنوان الرئيسي ولا إلغاء تفعيله مباشرة.
 */
class ParentDefaultAddressTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected Zone $zone;
    protected School $school;
    protected Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $municipality = Municipality::firstOrCreate(['name' => 'بلدية اختبار العناوين']);
        $subMuni      = SubMunicipality::firstOrCreate(['municipality_id' => $municipality->id, 'name' => 'محلة اختبار العناوين']);
        $this->zone   = Zone::firstOrCreate(['sub_municipality_id' => $subMuni->id, 'name' => 'منطقة اختبار العناوين']);

        $this->school = School::create([
            'name'    => 'مدرسة اختبار العناوين ' . uniqid(),
            'zone_id' => $this->zone->id,
            'lat'     => 32.88000000,
            'lng'     => 13.18000000,
            'address' => 'طرابلس - اختبار',
            'status'  => 'approved',
        ]);

        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر اختبار العناوين',
            'email'         => 'parent.default.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        // ParentModel هو Proxy فوق جدول users (ولي الأمر = مستخدم بدور role_id = 3)،
        // فلا يوجد صف منفصل يُنشأ له — نلتقطه من نفس المستخدم.
        $this->parent = ParentModel::findOrFail($this->parentUser->id);

        $driverUser = User::create([
            'full_name'     => 'سائق اختبار العناوين',
            'email'         => 'driver.default.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 4,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $driverUser->id,
            'national_id'    => '11988' . rand(1000000, 9999999),
            'license_number' => 'LY-' . rand(1000, 9999),
            'status'         => 'Approved',
        ]);
    }

    // ─────────────────────── أدوات مساعدة ───────────────────────

    private function makeAddress(string $label, float $lat, float $lng, bool $isDefault = false): Address
    {
        return Address::create([
            'user_id'    => $this->parentUser->id,
            'zone_id'    => $this->zone->id,
            'label'      => $label,
            'lat'        => $lat,
            'lng'        => $lng,
            'is_default' => $isDefault,
        ]);
    }

    private function makeChild(Address $address, string $name = 'طفل اختبار العناوين'): Child
    {
        return Child::create([
            'parent_id'         => $this->parentUser->id,
            'school_id'         => $this->school->id,
            'address_id'        => $address->id,
            'full_name'         => $name . ' ' . uniqid(),
            'birth_date'        => now()->subYears(10)->format('Y-m-d'),
            'gender'            => 'male',
            'grade'             => 4,
            'is_active'         => 1,
        ]);
    }

    private function makeRequest(Child $child, string $status): int
    {
        $requestId = DB::table('requests')->insertGetId([
            'parent_id'      => $this->parentUser->id,
            'driver_id'      => $this->driver->id,
            'status'         => $status,
            'total_price'    => 100,
            'children_count' => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('request_children')->insert([
            'request_id'        => $requestId,
            'child_id'          => $child->id,
            'subscription_type' => 'multi_day',
            'trip_direction'    => 'both',
            'start_date'        => now()->toDateString(),
            'end_date'          => now()->addMonth()->toDateString(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $requestId;
    }

    private function makeActiveSubscription(Child $child): void
    {
        // active_subscriptions.subscription_request_id إجباري، فنربطها بطلب مقبول حقيقي.
        DB::table('active_subscriptions')->insert([
            'subscription_request_id' => $this->makeRequest($child, 'accepted'),
            'child_id'                => $child->id,
            'driver_id'               => $this->driver->id,
            'parent_id'               => $this->parentUser->id,
            'status'                  => 'active',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    // ─────────────────────── الاختبارات ───────────────────────

    /**
     * 1. أول عنوان يُضاف يصبح رئيسياً تلقائياً حتى لو لم يُطلب ذلك.
     */
    public function test_first_address_becomes_default_automatically(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/addresses', [
                'label'   => 'منزل الاختبار الأول',
                'lat'     => 32.881111,
                'lng'     => 13.181111,
                'zone_id' => $this->zone->id,
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.is_default', true);
    }

    /**
     * 2. العنوان الثاني يُضاف ثانوياً (is_default = false) ولا يزيح العنوان الرئيسي.
     */
    public function test_second_address_is_created_as_non_default(): void
    {
        $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/addresses', [
                'label'   => 'منزل الجدة',
                'lat'     => 32.886666,
                'lng'     => 13.186666,
                'zone_id' => $this->zone->id,
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.is_default', false);

        $this->assertSame(1, Address::where('user_id', $this->parentUser->id)->where('is_default', true)->count());
    }

    /**
     * 3. إضافة عنوان جديد بـ is_default = true تنقل التفعيل إليه وتسند الأطفال إليه.
     */
    public function test_creating_address_with_is_default_true_switches_and_reassigns_children(): void
    {
        $home  = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $child = $this->makeChild($home);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/addresses', [
                'label'      => 'المنزل الجديد',
                'lat'        => 32.887777,
                'lng'        => 13.187777,
                'zone_id'    => $this->zone->id,
                'is_default' => true,
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.is_default', true);

        $newAddressId = $response->json('data.id');

        $this->assertFalse((bool) $home->fresh()->is_default);
        $this->assertSame((int) $newAddressId, (int) $child->fresh()->address_id);
        $this->assertSame(1, Address::where('user_id', $this->parentUser->id)->where('is_default', true)->count());
    }

    /**
     * 4. تعيين عنوان ثانوي كرئيسي عبر المسار المخصص ينقل كل الأطفال إليه.
     */
    public function test_set_default_endpoint_switches_and_reassigns_all_children(): void
    {
        $home   = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);

        $childA = $this->makeChild($home, 'طفل أول');
        $childB = $this->makeChild($home, 'طفل ثاني');

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$second->id}/set-default");

        $response->assertStatus(200)
                 ->assertJsonPath('changed', true)
                 ->assertJsonPath('cancelled_requests_count', 0)
                 ->assertJsonPath('data.is_default', true);

        $this->assertFalse((bool) $home->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame($second->id, $childA->fresh()->address_id);
        $this->assertSame($second->id, $childB->fresh()->address_id);
    }

    /**
     * 5. الحارس الأول: رفض تغيير العنوان الرئيسي عند وجود اشتراك مفعّل لطفل.
     */
    public function test_switching_default_is_rejected_when_child_has_active_subscription(): void
    {
        $home   = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);
        $child  = $this->makeChild($home);

        $this->makeActiveSubscription($child);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$second->id}/set-default");

        $response->assertStatus(422)
                 ->assertJsonPath('error_code', 'ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS');

        $this->assertStringContainsString('اشتراكات مفعّلة', $response->json('message'));

        // لم يتغير شيء
        $this->assertTrue((bool) $home->fresh()->is_default);
        $this->assertFalse((bool) $second->fresh()->is_default);
        $this->assertSame($home->id, $child->fresh()->address_id);
    }

    /**
     * 5-ب. الاشتراك المجدول (طلب مقبول لم تنتهِ مدته) يمنع التغيير أيضاً.
     */
    public function test_switching_default_is_rejected_when_child_has_accepted_request(): void
    {
        $home   = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);
        $child  = $this->makeChild($home);

        $this->makeRequest($child, 'accepted');

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$second->id}/set-default");

        $response->assertStatus(422)
                 ->assertJsonPath('error_code', 'ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS');

        $this->assertTrue((bool) $home->fresh()->is_default);
    }

    /**
     * 6. الحارس الثاني: بلا اشتراكات مفعّلة + طلبات معلّقة ⇒ إلغاء كل الطلبات ونجاح التغيير.
     */
    public function test_pending_requests_are_cancelled_when_switching_default(): void
    {
        $home   = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);
        $child  = $this->makeChild($home);

        $pendingId  = $this->makeRequest($child, 'pending');
        $acquiredId = $this->makeRequest($child, 'acquired');

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$second->id}/set-default");

        $response->assertStatus(200)
                 ->assertJsonPath('changed', true)
                 ->assertJsonPath('cancelled_requests_count', 2);

        $this->assertStringContainsString('تم إلغاء جميع طلبات الاشتراك', $response->json('message'));

        $this->assertSame('cancelled', DB::table('requests')->where('id', $pendingId)->value('status'));
        $this->assertSame('cancelled', DB::table('requests')->where('id', $acquiredId)->value('status'));

        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame($second->id, $child->fresh()->address_id);
    }

    /**
     * 7. تعيين العنوان الرئيسي الحالي مرة أخرى لا يغيّر شيئاً ولا يلغي أي طلب.
     */
    public function test_setting_already_default_address_is_a_noop(): void
    {
        $home  = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $child = $this->makeChild($home);
        $pendingId = $this->makeRequest($child, 'pending');

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$home->id}/set-default");

        $response->assertStatus(200)
                 ->assertJsonPath('changed', false)
                 ->assertJsonPath('cancelled_requests_count', 0);

        // الطلب المعلّق لم يُمس
        $this->assertSame('pending', DB::table('requests')->where('id', $pendingId)->value('status'));
    }

    /**
     * 8. لا يمكن حذف العنوان الرئيسي طالما أطفال ولي الأمر مسنَدون إليه.
     */
    public function test_default_address_with_children_cannot_be_deleted(): void
    {
        $home = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $this->makeAddress('المنزل البديل', 32.889, 13.189, false);
        $this->makeChild($home);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->deleteJson("/api/parent/addresses/{$home->id}");

        $response->assertStatus(422)
                 ->assertJsonPath('error_code', 'ADDRESS_DEFAULT_CANNOT_BE_DELETED');

        $this->assertNotNull(Address::find($home->id));
        $this->assertTrue((bool) $home->fresh()->is_default);
    }

    /**
     * 8-ب. حذف عنوان رئيسي بلا أطفال يُرقّي العنوان التالي تلقائياً.
     */
    public function test_deleting_childless_default_promotes_next_address(): void
    {
        $home   = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->deleteJson("/api/parent/addresses/{$home->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('addresses', ['id' => $home->id]);
        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame(1, Address::where('user_id', $this->parentUser->id)->where('is_default', true)->count());
    }

    /**
     * 9. حذف عنوان ثانوي غير مرتبط بطفل مسموح.
     */
    public function test_non_default_address_can_be_deleted(): void
    {
        $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->deleteJson("/api/parent/addresses/{$second->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSoftDeleted('addresses', ['id' => $second->id]);
    }

    /**
     * 10. لا يمكن إلغاء تفعيل العنوان الرئيسي مباشرة عبر التعديل.
     */
    public function test_default_flag_cannot_be_unset_directly(): void
    {
        $home = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $this->makeAddress('المنزل البديل', 32.889, 13.189, false);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$home->id}", ['is_default' => false]);

        $response->assertStatus(422)
                 ->assertJsonPath('error_code', 'ADDRESS_DEFAULT_CANNOT_BE_UNSET');

        $this->assertTrue((bool) $home->fresh()->is_default);
    }

    /**
     * 11. التعديل بـ is_default = true يمر عبر نفس حراسات الاشتراكات المفعّلة.
     */
    public function test_update_with_is_default_true_respects_active_subscription_guard(): void
    {
        $home   = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);
        $child  = $this->makeChild($home);

        $this->makeActiveSubscription($child);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$second->id}", ['is_default' => true]);

        $response->assertStatus(422)
                 ->assertJsonPath('error_code', 'ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS');

        $this->assertFalse((bool) $second->fresh()->is_default);
    }

    /**
     * 12. قائمة العناوين تُرجع العنوان الرئيسي أولاً مع معرّفه في الجذر.
     */
    public function test_index_returns_default_first_and_exposes_default_id(): void
    {
        $this->makeAddress('المنزل البديل', 32.889, 13.189, false);
        $home = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/addresses');

        $response->assertStatus(200)
                 ->assertJsonPath('default_address_id', $home->id)
                 ->assertJsonPath('data.0.id', $home->id)
                 ->assertJsonPath('data.0.is_default', true)
                 ->assertJsonPath('data.1.is_default', false);
    }

    /**
     * 13. لا يمكن تعيين عنوان مستخدم آخر كعنوان رئيسي (حماية IDOR).
     */
    public function test_cannot_set_default_on_another_users_address(): void
    {
        $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);

        $otherUser = User::create([
            'full_name'     => 'ولي أمر آخر',
            'email'         => 'parent.other.' . uniqid() . '@darby.test',
            'phone_number'  => '093' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $foreignAddress = Address::create([
            'user_id'    => $otherUser->id,
            'zone_id'    => $this->zone->id,
            'label'      => 'عنوان مستخدم آخر',
            'lat'        => 32.870000,
            'lng'        => 13.170000,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->patchJson("/api/parent/addresses/{$foreignAddress->id}/set-default");

        $response->assertStatus(404);
        $this->assertTrue((bool) $foreignAddress->fresh()->is_default);
    }

    /**
     * 14. إضافة طفل بدون address_id تُسنِده تلقائياً للعنوان الرئيسي.
     */
    public function test_child_is_auto_assigned_to_default_address(): void
    {
        $home = $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/children', [
                'school_id'           => $this->school->id,
                'full_name'           => 'محمد علي سالم',
                'birth_date'          => now()->subYears(10)->format('Y-m-d'),
                'gender'              => 'male',
                'grade'               => 4,
                'preferred_time_slot' => 'morning',
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.address_id', $home->id);
    }

    /**
     * 15. إسناد طفل لعنوان ثانوي مرفوض برسالة واضحة.
     */
    public function test_child_cannot_be_assigned_to_non_default_address(): void
    {
        $this->makeAddress('المنزل الرئيسي', 32.885, 13.185, true);
        $second = $this->makeAddress('المنزل البديل', 32.889, 13.189, false);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/children', [
                'school_id'           => $this->school->id,
                'address_id'          => $second->id,
                'full_name'           => 'خالد علي سالم',
                'birth_date'          => now()->subYears(10)->format('Y-m-d'),
                'gender'              => 'male',
                'grade'               => 4,
                'preferred_time_slot' => 'morning',
            ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['address_id']);
    }
}
