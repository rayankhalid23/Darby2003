<?php

namespace Tests\Feature;

use App\Models\Shared\OtpCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParentAvatarStorageTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->parentUser = User::create([
            'full_name'         => 'ولي أمر تجريبي',
            'email'             => 'avatar.test.' . uniqid() . '@darby.test',
            'phone_number'      => '091' . rand(1000000, 9999999),
            'password_hash'     => Hash::make('Pass1234'),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * 1. التحقق من رفع الصورة الشخصية أثناء تحديث الملف الشخصي وتخزينها في storage
     */
    public function test_parent_profile_avatar_upload_uses_storage_disk(): void
    {
        $avatar = UploadedFile::fake()->image('profile_pic.jpg', 300, 300);

        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'avatar' => $avatar,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->parentUser->refresh();
        $this->assertNotNull($this->parentUser->avatar_url);
        $this->assertStringContainsString('parents/avatars', $this->parentUser->avatar_url);

        $storedPath = str_replace('storage/', '', $this->parentUser->avatar_url);
        Storage::disk('public')->assertExists($storedPath);

        // التأكد من استجابة MediaController
        $mediaResponse = $this->get('/api/media/' . $storedPath);
        $mediaResponse->assertStatus(200);
        $mediaResponse->assertHeader('Content-Type', 'image/jpeg');
    }

    /**
     * 2. التحقق من دعم الأسماء البديلة للحقل (avatar_url و photo)
     */
    public function test_parent_profile_avatar_supports_alias_field_names(): void
    {
        $photoFile = UploadedFile::fake()->image('alias_pic.png', 200, 200);

        // إرسال الحقل باسم photo بدل avatar
        $response = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'photo' => $photoFile,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->parentUser->refresh();
        $storedPath = str_replace('storage/', '', $this->parentUser->avatar_url);
        Storage::disk('public')->assertExists($storedPath);
    }

    /**
     * 3. التحقق من حذف الصورة القديمة عند رفع صورة جديدة
     */
    public function test_parent_avatar_replacement_deletes_old_file(): void
    {
        $firstAvatar = UploadedFile::fake()->image('old_pic.jpg', 300, 300);

        $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'avatar' => $firstAvatar,
        ]);

        $this->parentUser->refresh();
        $firstPath = str_replace('storage/', '', $this->parentUser->avatar_url);
        Storage::disk('public')->assertExists($firstPath);

        $secondAvatar = UploadedFile::fake()->image('new_pic.jpg', 300, 300);
        $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'avatar' => $secondAvatar,
        ]);

        $this->parentUser->refresh();
        $secondPath = str_replace('storage/', '', $this->parentUser->avatar_url);

        // الصورة الأولى يجب أن تُحذف والصورة الثانية يجب أن توجد
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    /**
     * 4. التحقق من رفع الصورة الشخصية أثناء التسجيل الجديد وتخزينها في storage
     */
    public function test_parent_registration_with_avatar_uses_storage(): void
    {
        $email = 'new.parent.' . uniqid() . '@darby.test';
        $phone = '092' . rand(1000000, 9999999);

        OtpCode::create([
            'email'      => $email,
            'code_hash'  => Hash::make('123456'),
            'purpose'    => 'REGISTER',
            'expires_at' => Carbon::now()->addMinutes(10),
            'is_used'    => false,
        ]);

        $avatar = UploadedFile::fake()->image('reg_avatar.jpg', 300, 300);

        $response = $this->post('/api/parent/register', [
            'full_name'             => 'علي محمد الزنتاني',
            'email'                 => $email,
            'phone_number'          => $phone,
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
            'otp'                   => 123456,
            'avatar'                => $avatar,
        ]);

        $response->assertStatus(201);
        $newUser = User::where('email', $email)->first();
        $this->assertNotNull($newUser);
        $this->assertNotNull($newUser->avatar_url);
        $this->assertStringContainsString('parents/avatars', $newUser->avatar_url);

        $storedPath = str_replace('storage/', '', $newUser->avatar_url);
        Storage::disk('public')->assertExists($storedPath);
    }
}
