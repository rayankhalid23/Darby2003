<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Role;
use App\Models\Driver\Driver;
use Illuminate\Support\Facades\Hash;

class DriverChangePasswordTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;
    protected string $currentPassword = 'OldPass123';

    protected function setUp(): void
    {
        parent::setUp();

        $driverRoleId = Role::where('name', 'driver')->value('id');

        $this->driverUser = User::create([
            'full_name'     => 'سائق اختبار تغيير كلمة المرور',
            'email'         => 'driver.pwd.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make($this->currentPassword),
            'role_id'       => $driverRoleId,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);
    }

    private function endpoint(): string
    {
        return '/api/driver/profile/change-password';
    }

    public function test_unauthenticated_driver_cannot_change_password(): void
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
        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), []);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['old_password', 'password', 'password_confirmation']]);
    }

    public function test_new_password_must_match_confirmation(): void
    {
        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => 'NewPass456',
            'password_confirmation' => 'DifferentPass789',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.password_confirmation.0', 'تأكيد كلمة المرور غير مطابق.');
    }

    public function test_new_password_must_contain_letter_and_digit_matching_registration_rules(): void
    {
        // كلمة مرور أرقام فقط، بدون حرف إنجليزي — نفس شرط RegisterAccountRequest
        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => '123456789',
            'password_confirmation' => '123456789',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'كلمة المرور الجديدة يجب أن تحتوي على حرف إنجليزي ورقم على الأقل.');
    }

    public function test_new_password_rejects_short_length(): void
    {
        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => 'Ab1',
            'password_confirmation' => 'Ab1',
        ]);

        $response->assertStatus(422);
    }

    public function test_wrong_old_password_is_rejected(): void
    {
        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => 'WrongOldPass1',
            'password'              => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'كلمة المرور الحالية غير صحيحة.');

        $this->driverUser->refresh();
        $this->assertTrue(Hash::check($this->currentPassword, $this->driverUser->password_hash));
    }

    public function test_new_password_identical_to_old_password_is_rejected(): void
    {
        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => $this->currentPassword,
            'password_confirmation' => $this->currentPassword,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'كلمة المرور الجديدة يجب أن تختلف عن كلمة المرور الحالية.');
    }

    public function test_driver_can_change_password_successfully(): void
    {
        $newPassword = 'NewPass456';

        $response = $this->actingAs($this->driverUser, 'sanctum')->postJson($this->endpoint(), [
            'old_password'          => $this->currentPassword,
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->driverUser->refresh();
        $this->assertTrue(Hash::check($newPassword, $this->driverUser->password_hash));
        $this->assertFalse(Hash::check($this->currentPassword, $this->driverUser->password_hash));

        // تسجيل الدخول يعمل بكلمة المرور الجديدة فقط، ولا يعمل بعد الآن بالقديمة
        $loginWithOld = $this->postJson('/api/auth/login', [
            'email'    => $this->driverUser->email,
            'password' => $this->currentPassword,
            'platform' => 'web',
        ]);
        $loginWithOld->assertStatus(401);

        $loginWithNew = $this->postJson('/api/auth/login', [
            'email'    => $this->driverUser->email,
            'password' => $newPassword,
            'platform' => 'web',
        ]);
        $loginWithNew->assertStatus(200)
            ->assertJsonPath('status', true);
    }
}
