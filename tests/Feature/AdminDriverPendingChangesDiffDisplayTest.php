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

class AdminDriverPendingChangesDiffDisplayTest extends TestCase
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
            ['id' => 1, 'name' => 'SuperAdmin', 'display_name' => 'مدير النظام'],
            ['id' => 4, 'name' => 'DriverRole4', 'display_name' => 'سائق'],
        ]);

        $this->adminUser = User::create([
            'full_name'     => 'مدير النظام تجريبي',
            'email'         => 'admin.diff.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        \App\Models\Admin\Admin::create([
            'user_id'    => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي فحص الفروقات',
            'email'         => 'driver.diff.' . uniqid() . '@darby.test',
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
            'plate_number'      => '5-11223',
            'brand'             => 'Toyota',
            'model'             => 'Hiace',
            'year'              => 2020,
            'color'             => 'White',
            'type'              => 'Van',
            'capacity_manual'   => 14,
            'has_ac'            => true,
            'vehicle_image_url' => 'storage/drivers/vehicles/old_van.jpg',
            'status'            => 'Active',
            'is_verified'       => 1,
        ]);
    }

    /**
     * اختبار عند تعديل صورة المركبة فقط:
     * يجب أن يعرض للأدمن في مقارنة المركبة الصورة القديمة والجديدة فقط دون جلب بقية الحقول غير المعدلة
     */
    public function test_when_only_vehicle_image_is_modified_admin_view_only_shows_image_in_vehicle_diff(): void
    {
        // 1. السائق يقوم بتعديل صورة المركبة فقط
        $res = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/profile/vehicles/{$this->vehicle->id}", [
                'vehicle_photo' => UploadedFile::fake()->image('brand_new_photo.jpg'),
            ]);

        $res->assertStatus(200);

        $change = DB::table('driver_profile_changes')
            ->where('driver_id', $this->driver->id)
            ->where('status', 'Pending')
            ->latest('id')
            ->first();

        $this->assertNotNull($change);

        // التحقق من أن سجل قاعدة البيانات لا يحفظ كافة قيم المركبة في old_values
        $savedOldValues = json_decode($change->old_values, true);
        $this->assertArrayNotHasKey('plate_number', $savedOldValues);
        $this->assertArrayNotHasKey('brand', $savedOldValues);
        $this->assertArrayNotHasKey('model', $savedOldValues);
        $this->assertArrayNotHasKey('year', $savedOldValues);
        $this->assertArrayNotHasKey('color', $savedOldValues);
        $this->assertArrayHasKey('vehicle_image_url', $savedOldValues);

        // 2. فحص مخرجات الأدمن عند جلب تفاصيل التعديل المعلق
        $showRes = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/drivers/pending-changes/{$change->id}");

        $showRes->assertStatus(200);

        $data = $showRes->json('data');

        // التحقق من حقول المركبة الحالية والجديدة في الهيكل المصنف
        $currentVehicle = $data['current_system_data']['vehicle'];
        $requestedVehicle = $data['requested_new_data']['vehicle'];

        $this->assertNotNull($currentVehicle);
        $this->assertNotNull($requestedVehicle);

        // يجب أن تحتوي على رابط الصورة فقط
        $this->assertArrayHasKey('vehicle_image_url', $currentVehicle);
        $this->assertArrayHasKey('vehicle_image_url', $requestedVehicle);

        // لا يجب أن تحتوي على أي من الحقول غير المعدلة
        $this->assertArrayNotHasKey('plate_number', $currentVehicle);
        $this->assertArrayNotHasKey('brand', $currentVehicle);
        $this->assertArrayNotHasKey('model', $currentVehicle);
        $this->assertArrayNotHasKey('year', $currentVehicle);
        $this->assertArrayNotHasKey('color', $currentVehicle);
        $this->assertArrayNotHasKey('type', $currentVehicle);
        $this->assertArrayNotHasKey('capacity_manual', $currentVehicle);
        $this->assertArrayNotHasKey('has_ac', $currentVehicle);

        $this->assertArrayNotHasKey('plate_number', $requestedVehicle);
        $this->assertArrayNotHasKey('brand', $requestedVehicle);
        $this->assertArrayNotHasKey('model', $requestedVehicle);
        $this->assertArrayNotHasKey('year', $requestedVehicle);
        $this->assertArrayNotHasKey('color', $requestedVehicle);
        $this->assertArrayNotHasKey('type', $requestedVehicle);
        $this->assertArrayNotHasKey('capacity_manual', $requestedVehicle);
        $this->assertArrayNotHasKey('has_ac', $requestedVehicle);

        // التحقق من old_values و new_values المباشرة
        $this->assertArrayNotHasKey('plate_number', $data['old_values']);
        $this->assertArrayNotHasKey('plate_number', $data['new_values']);
        $this->assertArrayHasKey('vehicle_image_url', $data['old_values']);
        $this->assertArrayHasKey('vehicle_image_url', $data['new_values']);
    }

    /**
     * اختبار عند تعديل حقل معين مثل اللون واللوحة:
     * يجب أن يعرض في تفاصيل التعديل الحقول المعدلة فقط (اللون واللوحة)
     */
    public function test_when_specific_fields_are_modified_only_those_fields_are_returned(): void
    {
        $newPlate = '7-55443';
        $newColor = 'Blue';

        $res = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/profile/vehicles/{$this->vehicle->id}", [
                'plate_number' => $newPlate,
                'color'        => $newColor,
            ]);

        $res->assertStatus(200);

        $change = DB::table('driver_profile_changes')
            ->where('driver_id', $this->driver->id)
            ->where('status', 'Pending')
            ->latest('id')
            ->first();

        $showRes = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/drivers/pending-changes/{$change->id}");

        $showRes->assertStatus(200);

        $data = $showRes->json('data');
        $currentVehicle = $data['current_system_data']['vehicle'];
        $requestedVehicle = $data['requested_new_data']['vehicle'];

        // الحقول المعدلة فقط
        $this->assertEquals('5-11223', $currentVehicle['plate_number']);
        $this->assertEquals('White', $currentVehicle['color']);
        $this->assertEquals($newPlate, $requestedVehicle['plate_number']);
        $this->assertEquals($newColor, $requestedVehicle['color']);

        // الحقول غير المعدلة غير موجودة
        $this->assertArrayNotHasKey('brand', $currentVehicle);
        $this->assertArrayNotHasKey('model', $currentVehicle);
        $this->assertArrayNotHasKey('year', $currentVehicle);
        $this->assertArrayNotHasKey('vehicle_image_url', $currentVehicle);

        $this->assertArrayNotHasKey('brand', $requestedVehicle);
        $this->assertArrayNotHasKey('model', $requestedVehicle);
        $this->assertArrayNotHasKey('year', $requestedVehicle);
        $this->assertArrayNotHasKey('vehicle_image_url', $requestedVehicle);
    }
}
