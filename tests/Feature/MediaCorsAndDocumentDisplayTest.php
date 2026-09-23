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

class MediaCorsAndDocumentDisplayTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $driverUser;
    protected Driver $driver;
    protected Vehicle $vehicle;
    protected $docLicense;
    protected $docInsurance;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'SuperAdmin', 'display_name' => 'مدير النظام'],
            ['id' => 4, 'name' => 'DriverRole4', 'display_name' => 'سائق'],
        ]);

        $this->adminUser = User::create([
            'full_name'     => 'مدير النظام تجريبي',
            'email'         => 'admin.media.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي فحص الصور',
            'email'         => 'driver.media.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 4,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'gender'         => 'Male',
            'status'         => 'Approved',
            'national_id'    => (string) rand(100000000000, 999999999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
        ]);

        // تخزين ملفات حقيقية على القرص الوهمي
        $licenseFile = UploadedFile::fake()->image('my_license.webp');
        $licensePath = $licenseFile->store('drivers/documents', 'public');
        $this->driver->update(['license_image_url' => 'storage/' . $licensePath]);

        $insuranceFile = UploadedFile::fake()->create('my_insurance.pdf', 500, 'application/pdf');
        $insurancePath = $insuranceFile->store('drivers/documents', 'public');

        $this->vehicle = Vehicle::create([
            'driver_id'         => $this->driver->id,
            'plate_number'      => '5 12345',
            'brand'             => 'Toyota',
            'model'             => 'Corolla',
            'year'              => 2022,
            'color'             => 'White',
            'type'              => 'Sedan',
            'capacity_manual'   => 4,
            'has_ac'            => true,
            'vehicle_image_url' => 'storage/' . $licensePath,
            'status'            => 'Active',
            'is_verified'       => true,
        ]);

        $this->docLicense = \App\Models\Driver\VehicleDocument::create([
            'vehicle_id'  => $this->vehicle->id,
            'doc_type'    => 'LOGBOOK',
            'file_url'    => 'storage/' . $licensePath,
            'is_verified' => true,
            'state'       => 'active',
        ]);

        $this->docInsurance = \App\Models\Driver\VehicleDocument::create([
            'vehicle_id'            => $this->vehicle->id,
            'doc_type'              => 'INSURANCE',
            'file_url'              => 'storage/' . $insurancePath,
            'expiry_date'           => now()->addYears(1)->format('Y-m-d'),
            'is_verified'           => true,
            'state'                 => 'active',
        ]);
    }

    /**
     * 1. اختبار مسار تقديم الوسائط /api/media/{path} والتأكد من إرجاع الملف مع ترويسات CORS
     */
    public function test_media_endpoint_serves_document_with_cors_headers(): void
    {
        $relativePath = str_replace('storage/', '', $this->docLicense->file_url);

        $response = $this->getJson('/api/media/' . $relativePath, [
            'Origin' => 'http://localhost:3000',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeader('Content-Type', 'image/webp');
    }

    /**
     * 2. اختبار تقديم ملفات PDF مع الترويسات الصحيحة
     */
    public function test_media_endpoint_serves_pdf_documents_inline(): void
    {
        $relativePath = str_replace('storage/', '', $this->docInsurance->file_url);

        $response = $this->getJson('/api/media/' . $relativePath, [
            'Origin' => 'http://localhost:3000',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition') ?? '');
    }

    /**
     * 3. اختبار دالة عرض الوثائق للسائق والتأكد من أن الروابط تعمل وتمر عبر مسار الـ media
     */
    public function test_driver_legal_data_returns_accessible_media_urls(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->getJson('/api/v1/driver/profile/legal-data');

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $data = $response->json('data');
        $this->assertNotEmpty($data['uploaded_files']);
        $this->assertNotEmpty($data['doc_license_url']);

        // التأكد من أن الرابط يحتوي على api/media
        $this->assertStringContainsString('api/media/drivers/documents', $data['doc_license_url']);

        // طلب الصورة مباشرة بالرابط للتأكد من وصولها للفرونت
        $urlPath = parse_url($data['doc_license_url'], PHP_URL_PATH);
        $mediaResponse = $this->getJson($urlPath, ['Origin' => 'http://localhost:3000']);
        $mediaResponse->assertStatus(200);
        $mediaResponse->assertHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * 4. اختبار دالة تفاصيل السائق للأدمن والتأكد من إرجاع روابط وثائق قابلة للعرض في لوحة التحكم
     */
    public function test_admin_driver_details_returns_cors_enabled_document_urls(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/drivers/{$this->driver->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $documents = $response->json('data.documents');
        $this->assertNotEmpty($documents);

        $docUrl = $documents[0]['document_url'];
        $this->assertNotEmpty($docUrl);
        $this->assertStringContainsString('api/media', $docUrl);
    }
}
