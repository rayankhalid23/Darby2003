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
use App\Models\Driver\VehicleDocument;

class AdminApprovalRequiredFieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $driverUser;
    protected Driver $driver;
    protected Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'مدير النظام', 'kind' => 'staff'],
            ['id' => 4, 'name' => 'driver', 'display_name' => 'سائق', 'kind' => 'account'],
        ]);

        $this->adminUser = User::create([
            'full_name'     => 'مدير النظام للاختبار',
            'email'         => 'admin.req.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي فحص الموافقات',
            'email'         => 'driver.req.' . uniqid() . '@darby.test',
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

        $this->vehicle = Vehicle::create([
            'driver_id'         => $this->driver->id,
            'plate_number'      => '5-55443',
            'brand'             => 'Toyota',
            'model'             => 'Coaster',
            'year'              => 2021,
            'color'             => 'White',
            'type'              => 'Bus',
            'capacity_manual'   => 20,
            'has_ac'            => true,
            'vehicle_image_url' => 'storage/drivers/vehicles/coaster_orig.jpg',
            'status'            => 'Active',
        ]);
    }

    /**
     * 1. التأكد أن بيانات الملف الشخصي العادية (الاسم، الهاتف، الصورة الشخصية) لا تتطلب موافقة الأدمن
     */
    public function test_driver_profile_updates_immediately_without_admin_approval(): void
    {
        $res = $this->actingAs($this->driverUser)
            ->postJson('/api/v1/driver/profile/update', [
                'full_name'         => 'سائق معدل الاسم الثلاثي',
                'alternative_phone' => '0929988776',
                'avatar'            => UploadedFile::fake()->image('driver_avatar.jpg'),
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('status', true);

        // البيانات الشخصية تتحدث فوراً في جدول users
        $this->driverUser->refresh();
        $this->assertEquals('سائق معدل الاسم الثلاثي', $this->driverUser->full_name);
        $this->assertEquals('0929988776', $this->driverUser->alternative_phone);
        $this->assertNotEmpty($this->driverUser->avatar_url);

        // لا يتم إنشاء طلب معلق Pending للأدمن
        $pendingChange = DB::table('driver_profile_changes')
            ->where('driver_id', $this->driver->id)
            ->where('status', 'Pending')
            ->first();
        $this->assertNull($pendingChange);

        // لا يظهر السائق في قائمة التعديلات المعلقة للأدمن
        $adminListRes = $this->actingAs($this->adminUser)
            ->getJson('/api/admin/drivers/pending-changes');
        $adminListRes->assertStatus(200);
        $this->assertFalse(collect($adminListRes->json('data'))->contains('driver_id', $this->driver->id));
    }

    /**
     * 2. التأكد أن تعديل وثيقة معينة يغير حالتها هي فقط إلى معلقة، وترجع حالتها في المخرجات، وتظهر للأدمن كطلب
     */
    public function test_updating_specific_document_changes_only_that_document_to_pending_and_outputs_to_admin(): void
    {
        // تهيئة وثيقتين: تأمين وفحص فني، كلتاهما بحالة مفعلة Active
        $insuranceDoc = VehicleDocument::create([
            'vehicle_id'  => $this->vehicle->id,
            'doc_type'    => 'INSURANCE',
            'file_url'    => 'storage/drivers/documents/old_ins.jpg',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_ACTIVE,
            'is_verified' => true,
        ]);

        $inspectionDoc = VehicleDocument::create([
            'vehicle_id'  => $this->vehicle->id,
            'doc_type'    => 'INSPECTION',
            'file_url'    => 'storage/drivers/documents/old_insp.jpg',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'state'       => VehicleDocument::STATE_ACTIVE,
            'is_verified' => true,
        ]);

        // السائق يقوم بتعديل وثيقة التأمين فقط
        $newInsuranceExpiry = now()->addYears(2)->format('Y-m-d');
        $res = $this->actingAs($this->driverUser)
            ->postJson('/api/v1/driver/profile/legal-data', [
                'doc_insurance'    => UploadedFile::fake()->image('new_insurance.jpg'),
                'insurance_expiry' => $newInsuranceExpiry,
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('status', true);
        // ترجع حالتها في بيانات الإخراج
        $res->assertJsonPath('data.status', 'pending');
        $res->assertJsonPath('data.state', 'pending');
        $res->assertJsonPath('data.state_label', 'معلقة');

        // أ) التحقق من أن وثيقة التأمين فقط أصبحت معلقة
        $insuranceDoc->refresh();
        $this->assertEquals(VehicleDocument::STATE_PENDING, $insuranceDoc->state);
        $this->assertEquals('معلقة', $insuranceDoc->state_label);
        $this->assertFalse((bool) $insuranceDoc->is_verified);

        // ب) التحقق من أن الوثيقة الأخرى (الفحص الفني) لم تتغير حالتها وظلت مفعلة كما هي
        $inspectionDoc->refresh();
        $this->assertEquals(VehicleDocument::STATE_ACTIVE, $inspectionDoc->state);
        $this->assertEquals('مفعلة', $inspectionDoc->state_label);
        $this->assertTrue((bool) $inspectionDoc->is_verified);

        // ج) التحقق من ظهور الطلب للأدمن كطلب تعديل معلق Pending
        $adminListRes = $this->actingAs($this->adminUser)
            ->getJson('/api/admin/drivers/pending-changes');
        $adminListRes->assertStatus(200);
        $driverEntry = collect($adminListRes->json('data'))->firstWhere('driver_id', $this->driver->id);
        $this->assertNotNull($driverEntry);

        $change = DB::table('driver_profile_changes')
            ->where('driver_id', $this->driver->id)
            ->where('status', 'Pending')
            ->latest('id')
            ->first();
        $this->assertNotNull($change);
        $this->assertEquals($change->id, $driverEntry['request_id']);
    }

    /**
     * 3. التأكد أن تعديل بيانات وصورة المركبة يتطلب موافقة الأدمن ويرجع بحالة معلقة ولا يطبق مباشرة
     */
    public function test_updating_vehicle_and_image_creates_pending_request_and_does_not_apply_before_admin_approval(): void
    {
        $originalPlate = $this->vehicle->plate_number;
        $originalColor = $this->vehicle->color;
        $newPlate      = '5-99881';
        $newColor      = 'Black';

        // السائق يرسل طلب تعديل بيانات وصورة المركبة
        $res = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/profile/vehicles/{$this->vehicle->id}", [
                'plate_number'  => $newPlate,
                'color'         => $newColor,
                'vehicle_photo' => UploadedFile::fake()->image('new_bus.jpg'),
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('status', true);
        // ترجع حالتها في بيانات الإخراج معلقة
        $res->assertJsonPath('data.status', 'pending');
        $res->assertJsonPath('data.state_label', 'معلقة');
        $res->assertJsonPath('data.is_verified', false);

        // 1) التحقق من عدم تعديل المركبة مباشرة في جدول vehicles قبل اعتماد الأدمن
        $this->vehicle->refresh();
        $this->assertEquals($originalPlate, $this->vehicle->plate_number);
        $this->assertEquals($originalColor, $this->vehicle->color);

        // 2) التحقق من تسجيل الطلب في driver_profile_changes بالحالة Pending
        $change = DB::table('driver_profile_changes')
            ->where('driver_id', $this->driver->id)
            ->where('status', 'Pending')
            ->latest('id')
            ->first();
        $this->assertNotNull($change);

        // 3) التحقق من عرض الطلب للأدمن في قائمة التعديلات المعلقة
        $adminListRes = $this->actingAs($this->adminUser)
            ->getJson('/api/admin/drivers/pending-changes');
        $adminListRes->assertStatus(200);
        $driverEntry = collect($adminListRes->json('data'))->firstWhere('driver_id', $this->driver->id);
        $this->assertNotNull($driverEntry);
        $this->assertEquals($change->id, $driverEntry['request_id']);

        // 4) الأدمن يوافق على الطلب
        $reviewRes = $this->actingAs($this->adminUser)
            ->postJson("/api/admin/drivers/pending-changes/{$change->id}/review", [
                'decision' => 'Approved',
            ]);
        $reviewRes->assertStatus(200);

        // 5) بعد موافقة الأدمن، يتم تطبيق التعديلات وصورة المركبة فعلياً في جدول vehicles
        $this->vehicle->refresh();
        $this->assertEquals($newPlate, $this->vehicle->plate_number);
        $this->assertEquals($newColor, $this->vehicle->color);
        $this->assertEquals('Active', $this->vehicle->status);
        $this->assertEquals(1, $this->vehicle->is_verified);
        $this->assertStringContainsString('drivers/vehicles', $this->vehicle->vehicle_image_url);
    }
}
