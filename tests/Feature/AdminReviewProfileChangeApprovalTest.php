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

class AdminReviewProfileChangeApprovalTest extends TestCase
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
            'full_name'     => 'أدمن المراجعة والاعتماد',
            'email'         => 'admin.review.' . uniqid() . '@darby.test',
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
            'full_name'     => 'سائق تجريبي فحص الاعتماد',
            'email'         => 'driver.rev.' . uniqid() . '@darby.test',
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
            'plate_number'      => '5-77889',
            'brand'             => 'Hyundai',
            'model'             => 'H1',
            'year'              => 2019,
            'color'             => 'Grey',
            'type'              => 'Van',
            'capacity_manual'   => 12,
            'has_ac'            => true,
            'vehicle_image_url' => 'storage/drivers/vehicles/old_h1.jpg',
            'status'            => 'Active',
        ]);
    }

    /**
     * 1. اختبار قبول تعديل بيانات وصورة المركبة:
     * التحقق من تحديث جدول vehicles، تحول حالة المركبة إلى Active، واعتماد سجل التعديل Approved
     */
    public function test_admin_approves_vehicle_and_image_change_successfully(): void
    {
        $newPlate = '9-12345';
        $newColor = 'Black';
        $newYear  = 2022;

        // السائق يرسل طلب تعديل
        $updateRes = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/profile/vehicles/{$this->vehicle->id}", [
                'plate_number'  => $newPlate,
                'color'         => $newColor,
                'year'          => $newYear,
                'vehicle_photo' => UploadedFile::fake()->image('new_h1.jpg'),
            ]);
        $updateRes->assertStatus(200);

        // المركبة تصبح معلقة Pending مؤقتاً
        $this->vehicle->refresh();
        $this->assertEquals('Pending', $this->vehicle->status);

        $change = DB::table('driver_profile_changes')
            ->where('driver_id', $this->driver->id)
            ->where('status', 'Pending')
            ->latest('id')
            ->first();

        $this->assertNotNull($change);

        // الأدمن يوافق على الطلب
        $reviewRes = $this->actingAs($this->adminUser)
            ->postJson("/api/admin/drivers/pending-changes/{$change->id}/review", [
                'decision' => 'Approved',
            ]);

        $reviewRes->assertStatus(200);
        $reviewRes->assertJsonPath('status', true);

        // التحقق من تطبيق التعديلات على جدول المركبات وعودتها لحالة Active
        $this->vehicle->refresh();
        $this->assertEquals($newPlate, $this->vehicle->plate_number);
        $this->assertEquals($newColor, $this->vehicle->color);
        $this->assertEquals($newYear, $this->vehicle->year);
        $this->assertEquals('Active', $this->vehicle->status);
        $this->assertStringContainsString('drivers/vehicles', $this->vehicle->vehicle_image_url);

        // التحقق من توثيق قرار الأدمن في جدول driver_profile_changes
        $this->assertDatabaseHas('driver_profile_changes', [
            'id'        => $change->id,
            'status'    => 'Approved',
            'action_by' => $this->adminUser->id,
        ]);
    }

    /**
     * 2. اختبار محاولة معالجة طلب تم البت فيه مسبقاً (منع التكرار / Race Conditions)
     */
    public function test_cannot_review_already_processed_change_request(): void
    {
        $changeId = DB::table('driver_profile_changes')->insertGetId([
            'driver_id'  => $this->driver->id,
            'old_values' => json_encode(['color' => 'Grey']),
            'new_values' => json_encode(['color' => 'Red']),
            'status'     => 'Approved',
            'created_at' => now(),
        ]);

        $res = $this->actingAs($this->adminUser)
            ->postJson("/api/admin/drivers/pending-changes/{$changeId}/review", [
                'decision' => 'Approved',
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('status', false);
    }

    /**
     * 3. اختبار التحقق من إلزامية سبب الرفض عند اتخاذ قرار Rejected
     */
    public function test_rejection_requires_valid_reason(): void
    {
        $changeId = DB::table('driver_profile_changes')->insertGetId([
            'driver_id'  => $this->driver->id,
            'old_values' => json_encode(['color' => 'Grey']),
            'new_values' => json_encode(['color' => 'Red']),
            'status'     => 'Pending',
            'created_at' => now(),
        ]);

        // إرسال Rejected بدون سبب رفض
        $res = $this->actingAs($this->adminUser)
            ->postJson("/api/admin/drivers/pending-changes/{$changeId}/review", [
                'decision'         => 'Rejected',
                'rejection_reason' => '',
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('status', false);
    }
}
