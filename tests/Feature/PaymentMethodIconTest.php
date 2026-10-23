<?php

namespace Tests\Feature;

use App\Models\Shared\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentMethodIconTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected User $driverUser;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentUser = User::create([
            'full_name'         => 'ولي أمر اختبار الأيقونات',
            'email'             => 'parent.icon.' . uniqid() . '@darby.test',
            'phone_number'      => '091' . rand(1000000, 9999999),
            'password_hash'     => Hash::make('Pass1234'),
            'role_id'           => 3,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        $this->driverUser = User::create([
            'full_name'         => 'سائق اختبار الأيقونات',
            'email'             => 'driver.icon.' . uniqid() . '@darby.test',
            'phone_number'      => '092' . rand(1000000, 9999999),
            'password_hash'     => Hash::make('Pass1234'),
            'role_id'           => 4,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        $this->adminUser = User::create([
            'full_name'         => 'مدير مالي للاختبار',
            'email'             => 'admin.icon.' . uniqid() . '@darby.test',
            'phone_number'      => '093' . rand(1000000, 9999999),
            'password_hash'     => Hash::make('Pass1234'),
            'role_id'           => 1,
            'is_active'         => 1,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * 1. التحقق من ظهور طرق الدفع لولي الأمر مع أيقونة صالحة دائماً (ليست null)
     */
    public function test_parent_wallet_payment_methods_returns_valid_icons(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/wallet/payment-methods');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $methods = $response->json('data');
        $this->assertNotEmpty($methods);

        foreach ($methods as $method) {
            $this->assertNotNull($method['icon_url'], "Icon URL should not be null for {$method['name_ar']}");
            $this->assertStringContainsString('api/media/payment_methods/icons/', $method['icon_url']);

            // فحص إمكانية فتح الصورة عبر الرابط
            $path = parse_url($method['icon_url'], PHP_URL_PATH);
            $mediaRes = $this->get($path);
            $mediaRes->assertStatus(200);
            $this->assertStringContainsString('image/', $mediaRes->headers->get('Content-Type'));
        }
    }

    /**
     * 2. التحقق من ظهور طرق الدفع للسائق مع أيقونة صالحة وتضمين الوسائل العامة
     */
    public function test_driver_wallet_payment_methods_returns_valid_icons(): void
    {
        $response = $this->actingAs($this->driverUser, 'sanctum')
            ->getJson('/api/driver/wallet/payment-methods');

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $methods = $response->json('data');
        $this->assertNotEmpty($methods, 'Driver should receive payment methods configured for driver/both');

        foreach ($methods as $method) {
            $this->assertNotNull($method['icon_url'], "Driver Icon URL should not be null for {$method['name_ar']}");
            $path = parse_url($method['icon_url'], PHP_URL_PATH);
            $mediaRes = $this->get($path);
            $mediaRes->assertStatus(200);
        }
    }

    /**
     * 3. رفع أيقونة مخصصة من شاشة الإدارة والتأكد من ظهورها للطرفين
     */
    public function test_admin_can_upload_custom_icon_and_appears_for_users(): void
    {
        Storage::fake('public');
        $customIcon = UploadedFile::fake()->image('custom_sadad.png', 200, 200);

        // إنشاء وسيلة جديدة برفع صورة
        $resStore = $this->actingAs($this->adminUser, 'sanctum')
            ->post('/api/admin/payment-methods', [
                'name_ar'         => 'وسيلة تجريبية خاصة',
                'code'            => 'custom_test_' . uniqid(),
                'target_audience' => 'both',
                'min_amount'      => 5,
                'max_amount'      => 1000,
                'is_active'       => 1,
                'icon'            => $customIcon,
            ]);

        $resStore->assertStatus(201);
        $iconUrl = $resStore->json('data.icon_url');
        $this->assertNotNull($iconUrl);
        $this->assertStringContainsString('api/media/payment_methods/icons/', $iconUrl);

        // التحقق من أن الوسيلة تظهر لولي الأمر برابط الأيقونة المرفوعة
        $resParent = $this->actingAs($this->parentUser, 'sanctum')
            ->getJson('/api/parent/wallet/payment-methods');
        $resParent->assertStatus(200);

        $parentMethods = collect($resParent->json('data'));
        $found = $parentMethods->firstWhere('name_ar', 'وسيلة تجريبية خاصة');
        $this->assertNotNull($found);
        $this->assertStringContainsString('api/media/payment_methods/icons/', $found['icon_url']);

        // الملف المرفوع يجب أن يكون محفوظاً فعلاً ومقروءاً عبر مسار الوسائط
        $id = $resStore->json('data.id');
        $stored = str_replace('storage/', '', PaymentMethod::find($id)->getRawOriginal('icon_url'));
        Storage::disk('public')->assertExists($stored);
        $this->get(parse_url($iconUrl, PHP_URL_PATH))->assertStatus(200);

        // تعديل الأيقونة عبر POST (multipart) واستبدال الملف القديم
        $newIcon = UploadedFile::fake()->image('new_icon.png', 200, 200);
        $resUpdate = $this->actingAs($this->adminUser, 'sanctum')
            ->post("/api/admin/payment-methods/{$id}", ['icon' => $newIcon]);
        $resUpdate->assertStatus(200);

        Storage::disk('public')->assertMissing($stored);
        $newStored = str_replace('storage/', '', PaymentMethod::find($id)->getRawOriginal('icon_url'));
        Storage::disk('public')->assertExists($newStored);
        $this->assertNotEquals($stored, $newStored);
    }

    /**
     * 4. الفرونت قد يرسل الملف باسم حقل مختلف (image / icon_url)
     */
    public function test_admin_icon_upload_accepts_alias_field_names(): void
    {
        Storage::fake('public');

        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->post('/api/admin/payment-methods', [
                'name_ar'    => 'وسيلة alias',
                'code'       => 'alias_' . uniqid(),
                'min_amount' => 5,
                'max_amount' => 100,
                'image'      => UploadedFile::fake()->image('a.jpg', 100, 100),
            ]);

        $res->assertStatus(201);
        $this->assertStringContainsString('api/media/payment_methods/icons/', $res->json('data.icon_url'));
        $raw = PaymentMethod::find($res->json('data.id'))->getRawOriginal('icon_url');
        $this->assertStringStartsWith('storage/payment_methods/icons/', $raw);
    }
}
