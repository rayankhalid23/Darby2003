<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Parent\ParentModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class ParentProfileUpdateTest extends TestCase
{
    // ⚠️ نفس الثغرة: بلا هذه السمة كانت بيانات كل اختبار تُكتب فعلياً وتبقى
    // دائماً في قاعدة البيانات المتصلة بدل أن تُلغى تلقائياً بعد الاختبار.
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parentProfile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // إنشاء حساب ولي أمر معزول للاختبار
        $this->parentUser = User::create([
            'full_name'         => 'سالم محمد التاجوري',
            'email'             => 'salem.parent.' . uniqid() . '@darby.test',
            'phone_number'      => '091' . rand(1000000, 9999999),
            'password_hash'     => Hash::make('Pass1234'),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        $this->parentProfile = ParentModel::find($this->parentUser->id);
    }

    /**
     * 1. فحص الحماية: منع التعديل بدون تسجيل دخول (401 Unauthorized)
     */
    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $response = $this->postJson('/api/parent/profile/update', [
            'full_name' => 'اسم جديد غير مصرح',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 2. فحص أخطاء التحقق (Validation Errors - 422)
     */
    public function test_validation_rules_on_parent_profile_update(): void
    {
        // اسم قصير جداً (أقل من 3 حروف)
        $resShortName = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'full_name' => 'أح',
        ]);
        $resShortName->assertStatus(422)
            ->assertJsonStructure(['status', 'error_code', 'message', 'errors' => ['full_name']]);

        // صيغة بريد إلكتروني غير صالحة
        $resInvalidEmail = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'email' => 'not-a-valid-email',
        ]);
        $resInvalidEmail->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        // بريد إلكتروني مستخدم بالفعل لحساب آخر
        $anotherUser = User::create([
            'full_name'     => 'مستخدم آخر بالنظام',
            'email'         => 'existing.other.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Pass1234'),
            'role_id'       => 3,
        ]);

        $resDupEmail = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'email' => $anotherUser->email,
        ]);
        $resDupEmail->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'هذا البريد الإلكتروني مستخدم بالفعل لحساب آخر في النظام.');

        // رقم هاتف أقل من 7 أرقام
        $resShortPhone = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'phone_number' => '123',
        ]);
        $resShortPhone->assertStatus(422)
            ->assertJsonStructure(['errors' => ['phone_number']]);

        // كلمة مرور تحتوي على رموز خاصة ممنوعة
        $resSpecialPass = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'password' => 'Pass@1234',
        ]);
        $resSpecialPass->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'كلمة المرور الجديدة يجب أن تحتوي على أرقام وحروف، ويُمنع استخدام الرموز الخاصة.');

        // كلمة مرور قصيرة أقل من 7 خانات
        $resShortPass = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'password' => 'Pass1',
        ]);
        $resShortPass->assertStatus(422);
    }

    /**
     * 3. التعديل الجزئي: تحديث الاسم ورقم الهاتف الأساسي والبديل
     */
    public function test_partial_update_name_and_phone_numbers(): void
    {
        $newFullName = 'سالم محمد مصطفى التاجوري';
        $newPhone = '092' . rand(1000000, 9999999);
        $newAltPhone = '021' . rand(1000000, 9999999);

        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'full_name'         => $newFullName,
            'phone_number'      => $newPhone,
            'alternative_phone' => $newAltPhone,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.full_name', $newFullName)
            ->assertJsonPath('data.profile.phone_number', $newPhone)
            ->assertJsonPath('data.profile.alternative_phone', $newAltPhone);

        $this->assertDatabaseHas('users', [
            'id'                => $this->parentUser->id,
            'full_name'         => $newFullName,
            'phone_number'      => $newPhone,
            'alternative_phone' => $newAltPhone,
        ]);
    }

    /**
     * 4. التعديل مع رفع وتحديث الصورة الشخصية (Avatar Upload)
     */
    public function test_update_profile_with_avatar_upload(): void
    {
        Storage::fake('public');
        $avatarFile = UploadedFile::fake()->image('parent_new_photo.jpg', 400, 400);

        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'avatar' => $avatarFile,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->parentUser->refresh();
        $this->assertNotNull($this->parentUser->avatar_url);
        $this->assertStringContainsString('parents/avatars', $this->parentUser->avatar_url);

        $storedPath = str_replace('storage/', '', $this->parentUser->avatar_url);
        Storage::disk('public')->assertExists($storedPath);

        // التحقق من أن رابط الـ avatar_url المرجع يمكن الوصول له عبر MediaController
        $mediaResponse = $this->get('/api/media/' . $storedPath);
        $mediaResponse->assertStatus(200);

        // رفع صورة أخرى والتأكد من حذف الصورة السابقة
        $newAvatarFile = UploadedFile::fake()->image('parent_second_photo.png', 400, 400);
        $resSecond = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'avatar' => $newAvatarFile,
        ]);
        $resSecond->assertStatus(200);

        Storage::disk('public')->assertMissing($storedPath);
        $this->parentUser->refresh();
        $newStoredPath = str_replace('storage/', '', $this->parentUser->avatar_url);
        Storage::disk('public')->assertExists($newStoredPath);
    }

    /**
     * 5. تحديث كلمة المرور والتأكد من إمكانية استخدامها
     */
    public function test_update_password_hashes_properly(): void
    {
        $newPassword = 'NewSecretPassword99';

        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'password' => $newPassword,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->parentUser->refresh();
        $this->assertTrue(Hash::check($newPassword, $this->parentUser->password_hash));
    }

    /**
     * 6. تحديث حالة الموثوقية بجدول parents (is_trusted)
     */
    public function test_update_is_trusted_status_in_parents_table(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'is_trusted' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.is_trusted', true);

        $this->assertDatabaseHas('users', [
            'id'         => $this->parentUser->id,
            'is_trusted' => 1,
        ]);
    }

    /**
     * 7. دورة حياة تغيير البريد الإلكتروني (طلب تغيير، فحص الحالة، وإلغاء أو تأكيد)
     */
    public function test_email_change_lifecycle(): void
    {
        $newEmail = 'salem.new.email.' . uniqid() . '@darby.test';

        // 1. إرسال طلب تغيير الإيميل
        $resRequest = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'email' => $newEmail,
        ]);

        $resRequest->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_verification.status', 'pending')
            ->assertJsonPath('data.email_verification.new_email', $newEmail);

        // البريد القديم لا يزال كما هو في جدول users حتى يتم التأكيد
        $this->assertDatabaseHas('users', [
            'id'    => $this->parentUser->id,
            'email' => $this->parentUser->email,
        ]);

        // 2. فحص حالة التغيير
        $resStatus = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/profile/email-change/status');
        $resStatus->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        // 3. إعادة إرسال رابط التأكيد
        $resResend = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/email-change/resend');
        $resResend->assertStatus(200)
            ->assertJsonPath('success', true);

        // 4. تأكيد وتفعيل البريد الإلكتروني الجديد (Signed Route)
        $approveUrl = URL::temporarySignedRoute('parent.profile.email.approve', now()->addMinutes(30), ['id' => $this->parentUser->id]);
        $resApprove = $this->getJson($approveUrl);
        $resApprove->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_changed', true);

        // التأكد من تحديث البريد في قاعدة البيانات
        $this->assertDatabaseHas('users', [
            'id'    => $this->parentUser->id,
            'email' => $newEmail,
        ]);
    }

    /**
     * 8. إمكانية إلغاء تغيير البريد الإلكتروني (Cancel Email Change)
     */
    public function test_cancel_email_change_request(): void
    {
        $newEmail = 'temp.cancel.' . uniqid() . '@darby.test';

        $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'email' => $newEmail,
        ]);

        $resCancel = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/email-change/cancel');
        $resCancel->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertFalse(Cache::has("parent_email_change_{$this->parentUser->id}"));
    }
}
