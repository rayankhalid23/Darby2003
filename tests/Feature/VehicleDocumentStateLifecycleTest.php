<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Admin\Admin;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\VehicleDocument;
use App\Services\Driver\DriverExpiryNotificationService;

class VehicleDocumentStateLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'مدير النظام'],
            ['id' => 4, 'name' => 'DriverRole4', 'display_name' => 'سائق'],
        ]);

        $this->adminUser = User::create([
            'full_name'     => 'مدير اختبار دورة المستندات',
            'email'         => 'admin.doc.test.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        $this->admin = Admin::find($this->adminUser->id);
    }

    protected function createDriverWithVehicle(string $driverStatus = 'Pending'): array
    {
        $user = User::create([
            'full_name'     => 'سائق اختبار الحالة',
            'email'         => 'driver.test.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 4,
            'is_active'     => $driverStatus === 'Approved' ? 1 : 0,
        ]);

        $driver = Driver::create([
            'user_id'        => $user->id,
            'gender'         => 'Male',
            'status'         => $driverStatus,
            'national_id'    => (string) rand(100000000000, 999999999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
        ]);

        $vehicle = Vehicle::create([
            'driver_id'       => $driver->id,
            'plate_number'    => 'ABC-' . rand(1000, 9999),
            'brand'           => 'Toyota',
            'model'           => 'Hiace',
            'year'            => 2022,
            'color'           => 'White',
            'type'            => 'Van',
            'capacity_manual' => 14,
            'has_ac'          => true,
            'status'          => 'Active',
            'is_verified'     => $driverStatus === 'Approved',
        ]);

        return [$user, $driver, $vehicle];
    }

    /**
     * 1. دالة إنشاء حساب السائق وإكمال الملف: إسناد حالة الوثائق إلى معلقة (pending)
     */
    public function test_1_complete_profile_assigns_pending_state_to_vehicle_documents(): void
    {
        Storage::fake('public');

        $user = User::create([
            'full_name'     => 'سائق جديد للتسجيل',
            'email'         => 'driver.reg.' . uniqid() . '@darby.test',
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

        $payload = [
            'national_id'                 => (string) rand(100000000000, 999999999999),
            'license_number'              => 'LIC' . rand(100000, 999999),
            'license_expiry'              => now()->addYears(2)->format('Y-m-d'),
            'insurance_expiry'            => now()->addYear()->format('Y-m-d'),
            'stamp_expiry'                => now()->addYear()->format('Y-m-d'),
            'technical_inspection_expiry' => now()->addYear()->format('Y-m-d'),
            'plate_number'                => 'REG-' . rand(1000, 9999),
            'brand'                       => 'Hyundai',
            'model'                       => 'H1',
            'year'                        => 2021,
            'color'                       => 'Silver',
            'type'                        => 'Van',
            'capacity_manual'             => 12,
            'has_ac'                      => true,
            'vehicle_image'               => UploadedFile::fake()->image('car.jpg'),
            'doc_license'                 => UploadedFile::fake()->image('license.jpg'),
            'doc_logbook'                 => UploadedFile::fake()->image('logbook.jpg'),
            'doc_insurance'               => UploadedFile::fake()->image('insurance.jpg'),
            'doc_booklet_page'            => UploadedFile::fake()->image('booklet.jpg'),
            'doc_stamp'                   => UploadedFile::fake()->image('stamp.jpg'),
            'doc_technical_inspection'    => UploadedFile::fake()->image('inspection.jpg'),
        ];

        $response = $this->actingAs($user)
            ->postJson("/api/v1/driver/complete-profile/{$user->id}", $payload);

        $response->assertStatus(200);

        $vehicle = Vehicle::where('driver_id', $driver->id)->first();
        $this->assertNotNull($vehicle);

        $documents = VehicleDocument::where('vehicle_id', $vehicle->id)->get();
        $this->assertNotEmpty($documents);

        foreach ($documents as $doc) {
            $this->assertEquals(VehicleDocument::STATE_PENDING, $doc->state);
            $this->assertEquals('معلقة', $doc->state_label);
            $this->assertFalse((bool) $doc->is_verified);
        }
    }

    /**
     * 2. دالة موافقة الأدمن: تحويل حالة الوثائق إلى مفعلة (active) و is_verified = true
     */
    public function test_2_admin_approval_sets_active_state_on_vehicle_documents(): void
    {
        [$user, $driver, $vehicle] = $this->createDriverWithVehicle('Pending');

        $doc = VehicleDocument::create([
            'vehicle_id'  => $vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/ins.jpg',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_PENDING,
            'is_verified' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/review", [
                'status' => 'Approved',
            ]);

        $response->assertStatus(200);

        $doc->refresh();
        $this->assertEquals(VehicleDocument::STATE_ACTIVE, $doc->state);
        $this->assertEquals('مفعلة', $doc->state_label);
        $this->assertTrue((bool) $doc->is_verified);
    }

    /**
     * 3. دالة رفض الأدمن: تحويل حالة الوثائق إلى مرفوضة (rejected) و is_verified = false
     */
    public function test_3_admin_rejection_sets_rejected_state_on_vehicle_documents(): void
    {
        [$user, $driver, $vehicle] = $this->createDriverWithVehicle('Pending');

        $doc = VehicleDocument::create([
            'vehicle_id'  => $vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/ins.jpg',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_PENDING,
            'is_verified' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/admin/drivers/{$driver->id}/review", [
                'status'           => 'Rejected',
                'rejection_reason' => 'مستند غير صالح',
            ]);

        $response->assertStatus(200);

        $doc->refresh();
        $this->assertEquals(VehicleDocument::STATE_REJECTED, $doc->state);
        $this->assertEquals('مرفوضة', $doc->state_label);
        $this->assertFalse((bool) $doc->is_verified);
    }

    /**
     * 4. دالة طلب تعديل وثيقة من قبل السائق: تحويل حالة الوثيقة إلى معلقة (pending)
     */
    public function test_4_driver_document_update_sets_pending_state(): void
    {
        Storage::fake('public');
        [$user, $driver, $vehicle] = $this->createDriverWithVehicle('Approved');

        $doc = VehicleDocument::create([
            'vehicle_id'  => $vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/old_ins.jpg',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_ACTIVE,
            'is_verified' => true,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/driver/profile/legal-data', [
                'doc_insurance'    => UploadedFile::fake()->image('new_insurance.jpg'),
                'insurance_expiry' => now()->addYears(2)->format('Y-m-d'),
            ]);

        $response->assertStatus(200);

        $doc->refresh();
        $this->assertEquals(VehicleDocument::STATE_PENDING, $doc->state);
        $this->assertEquals('معلقة', $doc->state_label);
        $this->assertFalse((bool) $doc->is_verified);
    }

    /**
     * 5. دالة موافقة الأدمن على طلب تعديل الوثائق: إعادة تفعيل الوثيقة (active)
     */
    public function test_5_admin_approving_profile_change_activates_pending_document(): void
    {
        [$user, $driver, $vehicle] = $this->createDriverWithVehicle('Approved');

        $doc = VehicleDocument::create([
            'vehicle_id'  => $vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/updated_ins.jpg',
            'expiry_date' => now()->addYears(2)->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_PENDING,
            'is_verified' => false,
        ]);

        $changeId = DB::table('driver_profile_changes')->insertGetId([
            'driver_id'  => $driver->id,
            'old_values' => json_encode(['insurance_expiry' => '2025-01-01']),
            'new_values' => json_encode(['insurance_expiry' => '2027-01-01']),
            'status'     => 'Pending',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/admin/drivers/pending-changes/{$changeId}/review", [
                'decision' => 'Approved',
            ]);

        $response->assertStatus(200);

        $doc->refresh();
        $this->assertEquals(VehicleDocument::STATE_ACTIVE, $doc->state);
        $this->assertEquals('مفعلة', $doc->state_label);
        $this->assertTrue((bool) $doc->is_verified);
    }

    /**
     * 6. دالة الكشف على الوثائق المنتهية صلاحيتها: تحويل الحالة إلى منتهية الصلاحية (expired)
     */
    public function test_6_document_expiry_detection_marks_expired_state(): void
    {
        [$user, $driver, $vehicle] = $this->createDriverWithVehicle('Approved');

        $doc = VehicleDocument::create([
            'vehicle_id'  => $vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/ins.jpg',
            'expiry_date' => now()->subDay()->format('Y-m-d'), // منتهية أمس
            'state'       => VehicleDocument::STATE_ACTIVE,
            'is_verified' => true,
        ]);

        $service = app(DriverExpiryNotificationService::class);
        $stats = $service->run();

        $this->assertGreaterThanOrEqual(1, $stats['insurance_expired']);

        $doc->refresh();
        $this->assertEquals(VehicleDocument::STATE_EXPIRED, $doc->state);
        $this->assertEquals('منتهية الصلاحية', $doc->state_label);
        $this->assertFalse((bool) $doc->is_verified);
    }

    /**
     * 7. التحقق من توافق مخرجات الـ Resources ونقاط النهاية مع state و state_label
     */
    public function test_7_output_endpoints_contain_state_and_state_label(): void
    {
        [$user, $driver, $vehicle] = $this->createDriverWithVehicle('Approved');

        $doc = VehicleDocument::create([
            'vehicle_id'  => $vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/ins.jpg',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_ACTIVE,
            'is_verified' => true,
        ]);

        // أ) دالة عرض الوثائق للسائق: GET /api/v1/driver/profile/legal-data
        $response1 = $this->actingAs($user)->getJson('/api/v1/driver/profile/legal-data');
        $response1->assertStatus(200);
        $response1->assertJsonPath('status', true);
        $uploadedFile = $response1->json('data.uploaded_files.0');
        $this->assertNotNull($uploadedFile);
        $this->assertEquals(VehicleDocument::STATE_ACTIVE, $uploadedFile['state']);
        $this->assertEquals('مفعلة', $uploadedFile['state_label']);
        $this->assertTrue($uploadedFile['is_verified']);

        // ب) دالة عرض الملف الشخصي للسائق: GET /api/v1/driver/profile
        $response2 = $this->actingAs($user)->getJson('/api/v1/driver/profile');
        $response2->assertStatus(200);
        $profileDoc = $response2->json('data.documents.0');
        $this->assertNotNull($profileDoc);
        $this->assertEquals(VehicleDocument::STATE_ACTIVE, $profileDoc['state']);
        $this->assertEquals('مفعلة', $profileDoc['state_label']);
        $this->assertTrue($profileDoc['is_verified']);

        // ج) دالة عرض السائق للأدمن: GET /api/v1/admin/drivers/{id}
        $response3 = $this->actingAs($this->adminUser)->getJson("/api/v1/admin/drivers/{$driver->id}");
        $response3->assertStatus(200);
        $adminDoc = $response3->json('data.documents.0');
        $this->assertNotNull($adminDoc);
        $this->assertEquals(VehicleDocument::STATE_ACTIVE, $adminDoc['state']);
        $this->assertEquals('مفعلة', $adminDoc['state_label']);
        $this->assertTrue($adminDoc['is_verified']);
    }
}
