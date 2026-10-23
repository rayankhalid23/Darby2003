<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Driver\Driver;

class DriverAvatarRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 4, 'name' => 'DriverRole4', 'display_name' => 'سائق'],
        ]);
    }

    /**
     * اختبار رفع الصورة الشخصية أثناء إكمال الملف (complete-profile) بحقل avatar
     */
    public function test_avatar_is_uploaded_and_saved_during_complete_profile_with_avatar_field(): void
    {
        Storage::fake('public');

        $user = User::create([
            'full_name'     => 'سائق اختبار صورة أصلية',
            'email'         => 'driver.avatar.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 4,
            'is_active'     => 0,
        ]);

        $driver = Driver::create([
            'user_id' => $user->id,
            'gender'  => 'Male',
            'status'  => 'Offline',
        ]);

        $avatarFile = UploadedFile::fake()->image('my_personal_avatar.jpg', 300, 300);

        $payload = [
            'national_id'                 => (string) rand(100000000000, 999999999999),
            'license_number'              => 'LIC' . rand(100000, 999999),
            'license_expiry'              => now()->addYears(2)->format('Y-m-d'),
            'insurance_expiry'            => now()->addYear()->format('Y-m-d'),
            'stamp_expiry'                => now()->addYear()->format('Y-m-d'),
            'technical_inspection_expiry' => now()->addYear()->format('Y-m-d'),
            'plate_number'                => 'ABC-' . rand(1000, 9999),
            'brand'                       => 'Toyota',
            'model'                       => 'Hiace',
            'year'                        => 2022,
            'color'                       => 'White',
            'type'                        => 'Van',
            'capacity_manual'             => 14,
            'has_ac'                      => true,
            'vehicle_image'               => UploadedFile::fake()->image('vehicle.jpg'),
            'doc_license'                 => UploadedFile::fake()->image('license.jpg'),
            'doc_logbook'                 => UploadedFile::fake()->image('logbook.jpg'),
            'doc_insurance'               => UploadedFile::fake()->image('insurance.jpg'),
            'doc_booklet_page'            => UploadedFile::fake()->image('booklet-page.jpg'),
            'doc_stamp'                   => UploadedFile::fake()->image('stamp.jpg'),
            'doc_technical_inspection'    => UploadedFile::fake()->image('technical-inspection.jpg'),
            'avatar'                      => $avatarFile,
        ];

        $response = $this->actingAs($user)
            ->postJson("/api/v1/driver/complete-profile/{$user->id}", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        // التحقق من تحديث المستخدم بقيمة avatar_url
        $user->refresh();
        $this->assertNotNull($user->avatar_url, 'User avatar_url should not be null');
        $this->assertStringStartsWith('storage/drivers/avatars/', $user->avatar_url);

        // التحقق من وجود الملف في التخزين
        $relativePath = str_replace('storage/', '', $user->avatar_url);
        Storage::disk('public')->assertExists($relativePath);

        // التحقق من وجود avatar_url في الاستجابة
        $responseData = $response->json('data');
        $this->assertNotNull($responseData['avatar_url'] ?? null);
    }

    /**
     * اختبار رفع الصورة الشخصية أثناء إكمال الملف باستخدام الاسم البديل avatar_url
     */
    public function test_avatar_is_uploaded_using_avatar_url_alias(): void
    {
        Storage::fake('public');

        $user = User::create([
            'full_name'     => 'سائق اختبار صورة بديل',
            'email'         => 'driver.avatar.alias.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 4,
            'is_active'     => 0,
        ]);

        $driver = Driver::create([
            'user_id' => $user->id,
            'gender'  => 'Male',
            'status'  => 'Offline',
        ]);

        $avatarFile = UploadedFile::fake()->image('driver_alias.png', 200, 200);

        $payload = [
            'national_id'                 => (string) rand(100000000000, 999999999999),
            'license_number'              => 'LIC' . rand(100000, 999999),
            'license_expiry'              => now()->addYears(2)->format('Y-m-d'),
            'insurance_expiry'            => now()->addYear()->format('Y-m-d'),
            'stamp_expiry'                => now()->addYear()->format('Y-m-d'),
            'technical_inspection_expiry' => now()->addYear()->format('Y-m-d'),
            'plate_number'                => 'ABC-' . rand(1000, 9999),
            'brand'                       => 'Toyota',
            'model'                       => 'Hiace',
            'year'                        => 2022,
            'color'                       => 'White',
            'type'                        => 'Van',
            'capacity_manual'             => 14,
            'has_ac'                      => true,
            'vehicle_image'               => UploadedFile::fake()->image('vehicle.jpg'),
            'doc_license'                 => UploadedFile::fake()->image('license.jpg'),
            'doc_logbook'                 => UploadedFile::fake()->image('logbook.jpg'),
            'doc_insurance'               => UploadedFile::fake()->image('insurance.jpg'),
            'doc_booklet_page'            => UploadedFile::fake()->image('booklet-page.jpg'),
            'doc_stamp'                   => UploadedFile::fake()->image('stamp.jpg'),
            'doc_technical_inspection'    => UploadedFile::fake()->image('technical-inspection.jpg'),
            'avatar_url'                  => $avatarFile, // إرسال الاسم كـ avatar_url
        ];

        $response = $this->actingAs($user)
            ->postJson("/api/v1/driver/complete-profile/{$user->id}", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $user->refresh();
        $this->assertNotNull($user->avatar_url);
        $this->assertStringStartsWith('storage/drivers/avatars/', $user->avatar_url);
    }

    /**
     * اختبار ما إذا كان إرسال الصورة في خطوة /register يحفظ الصورة مؤقتاً ويربطها بالحساب فور التحقق في /verify-otp
     * حتى لو لم يُعد الفرونت إرسال ملف الصورة في خطوة /verify-otp
     */
    public function test_photo_in_initial_register_endpoint_is_persisted_after_verify_otp(): void
    {
        Storage::fake('public');

        $email = 'driver.init.' . uniqid() . '@darby.test';
        $phone = '091' . rand(1000000, 9999999);
        $fullName = 'سائق خطوة أولى كاملة';
        $password = 'Password123';
        $avatarFile = UploadedFile::fake()->image('first_step_avatar.jpg', 400, 400);

        $res = $this->postJson('/api/v1/driver/register', [
            'full_name'    => $fullName,
            'email'        => $email,
            'phone_number' => $phone,
            'gender'       => 'male',
            'password'     => $password,
            'avatar_url'   => $avatarFile,
        ]);

        $res->assertStatus(200);

        // المستخدم لا يُنشأ في خطوة register قبل التحقق من OTP
        $userBeforeOtp = User::where('email', $email)->first();
        $this->assertNull($userBeforeOtp, 'User should not be created yet during register endpoint');

        // محاكاة إدخال رمز الـ OTP في الخطوة الثانية كـ JSON بدون إعادة رفع ملف الصورة
        DB::table('otp_codes')->where('email', $email)->where('purpose', 'REGISTER')->update([
            'code_hash'  => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'is_used'    => 0,
        ]);

        $verifyRes = $this->postJson('/api/v1/driver/verify-otp', [
            'email'        => $email,
            'otp'          => '123456',
            'full_name'    => $fullName,
            'phone_number' => $phone,
            'gender'       => 'male',
            'password'     => $password,
        ]);

        $verifyRes->assertStatus(201);
        $this->assertNotNull($verifyRes->json('avatar_url'), 'verify-otp response should return avatar_url');

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->avatar_url, 'Avatar uploaded in step 1 (/register) must be saved on user after /verify-otp');
        $this->assertStringStartsWith('storage/drivers/avatars/', $user->avatar_url);

        $relativePath = str_replace('storage/', '', $user->avatar_url);
        Storage::disk('public')->assertExists($relativePath);
    }

    /**
     * اختبار تحديث صورة السائق من الملف الشخصي بمختلف المسميات (avatar, avatar_url, photo, image)
     * ومع وجود حقول فارغة من الفورم في الفرونت
     */
    public function test_driver_profile_update_uploads_avatar_with_any_field_alias_and_empty_form_fields(): void
    {
        Storage::fake('public');

        $user = User::create([
            'full_name'     => 'سائق تحديث الملف الشخصي',
            'email'         => 'driver.prof.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('Password123'),
            'role_id'       => 4,
            'is_active'     => 1,
        ]);

        Driver::create([
            'user_id' => $user->id,
            'gender'  => 'male',
            'status'  => 'Approved',
        ]);

        foreach (['avatar', 'avatar_url', 'photo', 'image'] as $fieldName) {
            $file = UploadedFile::fake()->image("driver_{$fieldName}.jpg", 250, 250);

            $response = $this->actingAs($user)->post("/api/v1/driver/profile/update", [
                $fieldName  => $file,
                'full_name' => '', // محاكاة إرسال حقل نصي فارغ في FormData من الفرونت
                'password'  => '',
            ], ['Accept' => 'application/json']);

            $response->assertStatus(200);
            $response->assertJsonPath('status', true);
            $this->assertNotNull($response->json('data.avatar_url'), "data.avatar_url should be present for {$fieldName}");
            $this->assertNotNull($response->json('data.profile.avatar_url'), "data.profile.avatar_url should be present for {$fieldName}");

            $user->refresh();
            $this->assertNotNull($user->avatar_url, "User avatar_url should be saved when uploaded as {$fieldName}");
            $relativePath = str_replace('storage/', '', $user->avatar_url);
            Storage::disk('public')->assertExists($relativePath);
        }
    }

    /**
     * اختبار ما إذا كان إرسال الصورة في خطوة /verify-otp يحفظ الصورة
     */
    public function test_photo_in_verify_otp_endpoint(): void
    {
        Storage::fake('public');

        $email = 'driver.otp.' . uniqid() . '@darby.test';
        $phone = '091' . rand(1000000, 9999999);
        $password = 'Password123';
        $fullName = 'سائق خطوة التحقق';

        // إرسال OTP
        $this->postJson('/api/v1/driver/register', [
            'full_name'    => $fullName,
            'email'        => $email,
            'phone_number' => $phone,
            'gender'       => 'male',
            'password'     => $password,
        ]);

        DB::table('otp_codes')->where('email', $email)->where('purpose', 'REGISTER')->update([
            'code_hash'  => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'is_used'    => 0,
        ]);

        $avatarFile = UploadedFile::fake()->image('otp_step_avatar.jpg');

        $verifyRes = $this->postJson('/api/v1/driver/verify-otp', [
            'email'        => $email,
            'otp'          => '123456',
            'full_name'    => $fullName,
            'phone_number' => $phone,
            'gender'       => 'male',
            'password'     => $password,
            'avatar_url'   => $avatarFile,
        ]);

        $verifyRes->assertStatus(201);
        $userId = $verifyRes->json('user_id');
        $user = User::find($userId);

        // التحقق من حفظ الصورة الشخصية في خطوة verify-otp
        $this->assertNotNull($user->avatar_url, 'Avatar URL should be stored on the user');
        $this->assertStringStartsWith('storage/drivers/avatars/', $user->avatar_url);

        $relativePath = str_replace('storage/', '', $user->avatar_url);
        Storage::disk('public')->assertExists($relativePath);
    }
}
