<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class ParentChangePasswordTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected string $currentPassword = 'OldPass123';

    protected function setUp(): void
    {
        parent::setUp();

        $parentRoleId = Role::where('name', 'parent')->value('id');

        $this->parentUser = User::create([
            'full_name'         => 'خالد علي المسلاتي',
            'email'             => 'khaled.parent.' . uniqid() . '@darby.test',
            'phone_number'      => '091' . rand(1000000, 9999999),
            'password_hash'     => Hash::make($this->currentPassword),
            'role_id'           => $parentRoleId,
            'is_active'         => 1,
            'is_trusted'        => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function endpoint(): string
    {
        return '/api/parent/profile/change-password';
    }

    public function test_unauthenticated_user_cannot_change_password(): void
    {
        $response = $this->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertStatus(401);
    }

    public function test_validation_requires_all_fields(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), []);

        $response->assertStatus(422)
            ->assertJsonStructure(['status', 'error_code', 'message', 'errors' => ['old_password', 'password', 'password_confirmation']]);
    }

    public function test_new_password_must_match_confirmation(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => 'NewPass456',
            'password_confirmation' => 'DifferentPass789',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.password_confirmation.0', 'تأكيد كلمة المرور غير مطابق.');
    }

    public function test_new_password_rejects_special_characters_matching_registration_rules(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => 'New@Pass456',
            'password_confirmation' => 'New@Pass456',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'كلمة المرور الجديدة يجب أن تحتوي على أرقام وحرف إنجليزي واحد على الأقل، ويُمنع استخدام الرموز الخاصة والمسافات.');
    }

    public function test_new_password_rejects_short_length(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => 'Ab1',
            'password_confirmation' => 'Ab1',
        ]);

        $response->assertStatus(422);
    }

    public function test_wrong_old_password_is_rejected(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => 'WrongOldPass1',
            'password'              => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'كلمة المرور الحالية غير صحيحة.');

        $this->parentUser->refresh();
        $this->assertTrue(Hash::check($this->currentPassword, $this->parentUser->password_hash));
    }

    public function test_new_password_identical_to_old_password_is_rejected(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => $this->currentPassword,
            'password_confirmation' => $this->currentPassword,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'كلمة المرور الجديدة يجب أن تختلف عن كلمة المرور الحالية.');
    }

    public function test_parent_can_change_password_successfully(): void
    {
        $newPassword = 'NewPass456';

        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $this->parentUser->refresh();
        $this->assertTrue(Hash::check($newPassword, $this->parentUser->password_hash));
        $this->assertFalse(Hash::check($this->currentPassword, $this->parentUser->password_hash));

        // تسجيل الدخول يعمل بكلمة المرور الجديدة فقط، ولا يعمل بعد الآن بالقديمة
        $loginWithOld = $this->postJson('/api/auth/login', [
            'email'    => $this->parentUser->email,
            'password' => $this->currentPassword,
            'platform' => 'web',
        ]);
        $loginWithOld->assertStatus(401);

        $loginWithNew = $this->postJson('/api/auth/login', [
            'email'    => $this->parentUser->email,
            'password' => $newPassword,
            'platform' => 'web',
        ]);
        $loginWithNew->assertStatus(200)
            ->assertJsonPath('status', true);
    }
}
