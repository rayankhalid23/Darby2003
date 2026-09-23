<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\DriverDocument;

class AdminDriverMediaAndAvatarTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $driverUser;
    protected Driver $driver;
    protected Vehicle $vehicle;
    protected $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'SuperAdmin', 'display_name' => 'مدير النظام'],
            ['id' => 4, 'name' => 'DriverRole4', 'display_name' => 'سائق'],
        ]);

        $this->adminUser = User::create([
            'full_name'     => 'أدمن فحص الوسائط',
            'email'         => 'admin.media.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        $avatarFile = UploadedFile::fake()->image('driver_avatar.jpg');
        $avatarPath = $avatarFile->store('drivers/avatars', 'public');

        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي للاختبار',
            'email'         => 'driver.test.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'avatar_url'    => 'storage/' . $avatarPath,
            'role_id'       => 4,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'gender'         => 'male',
            'status'         => 'Approved',
            'national_id'    => (string) rand(100000000000, 999999999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
        ]);

        $vehicleFile = UploadedFile::fake()->image('my_vehicle.jpg');
        $vehiclePath = $vehicleFile->store('drivers/vehicles', 'public');

        $this->vehicle = Vehicle::create([
            'driver_id'         => $this->driver->id,
            'plate_number'      => '5 99999',
            'brand'             => 'Toyota',
            'model'             => 'Corolla',
            'year'              => 2022,
            'color'             => 'White',
            'type'              => 'Sedan',
            'capacity_manual'   => 4,
            'has_ac'            => true,
            'vehicle_image_url' => 'storage/' . $vehiclePath,
            'status'            => 'Active',
            'is_verified'       => true,
        ]);

        $docFile = UploadedFile::fake()->image('my_license.jpg');
        $docPath = $docFile->store('drivers/documents', 'public');

        $this->document = \App\Models\Driver\VehicleDocument::create([
            'vehicle_id'  => $this->vehicle->id,
            'doc_type'    => 'LOGBOOK',
            'file_url'    => 'storage/' . $docPath,
            'is_verified' => true,
            'state'       => 'active',
        ]);
    }

    /**
     * اختبار 1: استرجاع تفاصيل السائق على المضيف العادي وتأكيد وجود avatar_url في الجذر الرئيسي وداخل user_account
     */
    public function test_admin_driver_details_returns_avatar_and_standard_urls_on_localhost(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/drivers/{$this->driver->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        // التأكد من وجود avatar_url في الجذر الرئيسي
        $this->assertNotNull($response->json('data.avatar_url'));
        $this->assertStringContainsString('api/media/drivers/avatars', $response->json('data.avatar_url'));

        // التأكد من وجود avatar_url داخل user_account
        $this->assertNotNull($response->json('data.user_account.avatar_url'));
        $this->assertStringContainsString('api/media/drivers/avatars', $response->json('data.user_account.avatar_url'));

        // التأكد من وجود روابط المركبة والوثائق الطبيعية
        $vehicleUrl = $response->json('data.vehicles.0.vehicle_image_url');
        $this->assertStringContainsString('api/media/drivers/vehicles', $vehicleUrl);

        $docUrl = $response->json('data.documents.0.document_url');
        $this->assertStringContainsString('api/media/drivers/documents', $docUrl);

        // التأكد من توفر حقول data_url الصريحة
        $this->assertStringStartsWith('data:image/', $response->json('data.avatar_data_url'));
        $this->assertStringStartsWith('data:image/', $response->json('data.vehicles.0.vehicle_image_data_url'));
        $this->assertStringStartsWith('data:image/', $response->json('data.documents.0.document_data_url'));
    }

    /**
     * اختبار 2: التحويل التلقائي إلى Data URI عند الطلب عبر LocalTunnel (loca.lt) لتجاوز صفحة الحظر
     */
    public function test_media_urls_automatically_convert_to_data_uri_when_using_localtunnel(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("https://horrible-octopus-0.loca.lt/api/admin/drivers/{$this->driver->id}");

        $response->assertStatus(200);

        // في نطاق LocalTunnel، يجب أن ترجع الروابط بصيغة Data URI لمنع حجب المتصفح
        $avatarUrl = $response->json('data.avatar_url');
        $this->assertStringStartsWith('data:image/', $avatarUrl);

        $vehicleUrl = $response->json('data.vehicles.0.vehicle_image_url');
        $this->assertStringStartsWith('data:image/', $vehicleUrl);

        $docUrl = $response->json('data.documents.0.document_url');
        $this->assertStringStartsWith('data:image/', $docUrl);
    }

    /**
     * اختبار 3: التحويل إلى Data URI عند إرسال بارامتر embed_media=1 صراحة
     */
    public function test_media_urls_convert_to_data_uri_when_embed_media_param_is_passed(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/drivers/{$this->driver->id}?embed_media=1");

        $response->assertStatus(200);

        $vehicleUrl = $response->json('data.vehicles.0.vehicle_image_url');
        $this->assertStringStartsWith('data:image/', $vehicleUrl);
    }

    /**
     * اختبار 4: رفع وتحديث الصورة الشخصية للسائق من قبل الأدمن عبر PUT /api/admin/drivers/{id}
     */
    public function test_admin_can_update_driver_avatar(): void
    {
        $newAvatar = UploadedFile::fake()->image('new_admin_driver_avatar.png');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/admin/drivers/{$this->driver->id}", [
                'avatar' => $newAvatar,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // التأكد من تحديث avatar_url في قاعدة البيانات
        $this->driverUser->refresh();
        $this->assertNotNull($this->driverUser->avatar_url);
        $this->assertStringContainsString('drivers/avatars', $this->driverUser->avatar_url);

        // التأكد من وجود الملف على القرص
        $storedPath = str_replace('storage/', '', $this->driverUser->avatar_url);
        Storage::disk('public')->assertExists($storedPath);
    }

    /**
     * اختبار 5: حفظ الصورة الشخصية أثناء إكمال الملف الأولي للسائق POST /api/v1/driver/complete-profile/{id}
     */
    public function test_driver_avatar_is_saved_during_complete_profile(): void
    {
        $newDriverUser = User::create([
            'full_name'     => 'سائق جديد تسجيل',
            'email'         => 'driver.reg.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 4,
            'is_active'     => 0,
        ]);

        $newDriver = Driver::create([
            'user_id' => $newDriverUser->id,
            'status'  => 'Offline',
        ]);

        $avatarFile = UploadedFile::fake()->image('my_personal_avatar.jpg');
        $vehicleFile = UploadedFile::fake()->image('vehicle.jpg');
        $docLicense = UploadedFile::fake()->image('license.jpg');
        $docLogbook = UploadedFile::fake()->image('logbook.jpg');
        $docInsurance = UploadedFile::fake()->image('insurance.jpg');
        $docBooklet = UploadedFile::fake()->image('booklet.jpg');
        $docStamp = UploadedFile::fake()->image('stamp.jpg');
        $docInspection = UploadedFile::fake()->image('inspection.jpg');

        $payload = [
            'national_id'                 => '123456789012',
            'license_number'              => 'LIC' . rand(100000, 999999),
            'license_expiry'              => now()->addYears(2)->format('Y-m-d'),
            'insurance_expiry'            => now()->addYears(1)->format('Y-m-d'),
            'stamp_expiry'                => now()->addYears(1)->format('Y-m-d'),
            'technical_inspection_expiry' => now()->addYears(1)->format('Y-m-d'),
            'plate_number'                => '7 12345',
            'brand'                       => 'Hyundai',
            'model'                       => 'Elantra',
            'year'                        => 2021,
            'color'                       => 'Black',
            'type'                        => 'Sedan',
            'capacity_manual'             => 4,
            'has_ac'                      => 1,
            'avatar'                      => $avatarFile,
            'vehicle_image'               => $vehicleFile,
            'doc_license'                 => $docLicense,
            'doc_logbook'                 => $docLogbook,
            'doc_insurance'               => $docInsurance,
            'doc_booklet_page'            => $docBooklet,
            'doc_stamp'                   => $docStamp,
            'doc_technical_inspection'    => $docInspection,
        ];

        $response = $this->actingAs($newDriverUser)
            ->postJson("/api/v1/driver/complete-profile/{$newDriverUser->id}", $payload);

        $response->assertStatus(200);

        $newDriverUser->refresh();
        $this->assertNotNull($newDriverUser->avatar_url);
        $this->assertStringContainsString('drivers/avatars', $newDriverUser->avatar_url);

        $storedPath = str_replace('storage/', '', $newDriverUser->avatar_url);
        Storage::disk('public')->assertExists($storedPath);
    }
}
