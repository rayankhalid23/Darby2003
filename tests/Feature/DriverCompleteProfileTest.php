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

class DriverCompleteProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected User $driverUser;
    protected Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 4, 'name' => 'DriverRole4', 'display_name' => 'سائق'],
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق اختبار إكمال الملف',
            'email'         => 'driver.complete.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 4,
            'is_active'     => 0,
        ]);

        $this->driver = Driver::create([
            'user_id' => $this->driverUser->id,
            'gender'  => 'Male',
            'status'  => 'Offline',
        ]);
    }

    protected function validPayload(): array
    {
        return [
            'national_id'       => (string) rand(100000000000, 999999999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYears(2)->format('Y-m-d'),
            'insurance_expiry'  => now()->addYear()->format('Y-m-d'),
            'stamp_expiry'                => now()->addYear()->format('Y-m-d'),
            'technical_inspection_expiry' => now()->addYear()->format('Y-m-d'),
            'plate_number'      => 'ABC-' . rand(1000, 9999),
            'brand'             => 'Toyota',
            'model'             => 'Hiace',
            'year'              => 2022,
            'color'             => 'White',
            'type'              => 'Van',
            'capacity_manual'   => 14,
            'has_ac'            => true,
            'vehicle_image'     => UploadedFile::fake()->image('vehicle.jpg'),
            'doc_license'       => UploadedFile::fake()->image('license.jpg'),
            'doc_logbook'       => UploadedFile::fake()->image('logbook.jpg'),
            'doc_insurance'     => UploadedFile::fake()->image('insurance.jpg'),
            'doc_booklet_page'         => UploadedFile::fake()->image('booklet-page.jpg'),
            'doc_stamp'                => UploadedFile::fake()->image('stamp.jpg'),
            'doc_technical_inspection' => UploadedFile::fake()->image('technical-inspection.jpg'),
        ];
    }

    /** Test 1: نجاح إكمال الملف الشخصي مع تاريخ انتهاء التأمين */
    public function test_complete_profile_success_with_insurance_expiry(): void
    {
        Storage::fake('public');

        $payload = $this->validPayload();

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/complete-profile/{$this->driverUser->id}", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $vehicle = Vehicle::where('driver_id', $this->driver->id)->first();
        $this->assertNotNull($vehicle);

        $insuranceDoc = VehicleDocument::where('vehicle_id', $vehicle->id)
            ->where('doc_type', 'INSURANCE')
            ->first();

        $this->assertNotNull($insuranceDoc);
        $this->assertEquals($payload['insurance_expiry'], $insuranceDoc->expiry_date->format('Y-m-d'));
        $this->assertEquals(VehicleDocument::STATE_PENDING, $insuranceDoc->state);
        $this->assertEquals('معلقة', $insuranceDoc->state_label);
    }

    /** Test 2: فشل عند غياب تاريخ انتهاء التأمين */
    public function test_complete_profile_fails_without_insurance_expiry(): void
    {
        Storage::fake('public');

        $payload = $this->validPayload();
        unset($payload['insurance_expiry']);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/complete-profile/{$this->driverUser->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['insurance_expiry']);
    }

    /** Test 3: فشل عند تاريخ انتهاء تأمين في الماضي */
    public function test_complete_profile_fails_when_insurance_expiry_in_past(): void
    {
        Storage::fake('public');

        $payload = $this->validPayload();
        $payload['insurance_expiry'] = now()->subDay()->format('Y-m-d');

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/complete-profile/{$this->driverUser->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['insurance_expiry']);
    }

    /** Test 4: حقل doc_vehicle_registration لم يعد مطلوباً - النجاح دون إرساله إطلاقاً */
    public function test_complete_profile_does_not_require_vehicle_registration_doc(): void
    {
        Storage::fake('public');

        $payload = $this->validPayload();
        $this->assertArrayNotHasKey('doc_vehicle_registration', $payload);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/complete-profile/{$this->driverUser->id}", $payload);

        $response->assertStatus(200);

        $vehicle = Vehicle::where('driver_id', $this->driver->id)->first();
        if ($vehicle) {
            $this->assertDatabaseMissing('vehicle_documents', [
                'vehicle_id' => $vehicle->id,
                'doc_type'   => 'VEHICLE_REGISTRATION',
            ]);
        }
    }

    /** Test 5: إرسال doc_vehicle_registration (حقل قديم من الفرونت) لا يكسر الطلب ولا يُخزَّن */
    public function test_complete_profile_ignores_stray_vehicle_registration_field(): void
    {
        Storage::fake('public');

        $payload = $this->validPayload();
        $payload['doc_vehicle_registration'] = UploadedFile::fake()->image('old-field.jpg');

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/v1/driver/complete-profile/{$this->driverUser->id}", $payload);

        $response->assertStatus(200);

        $vehicle = Vehicle::where('driver_id', $this->driver->id)->first();
        if ($vehicle) {
            $this->assertDatabaseMissing('vehicle_documents', [
                'vehicle_id' => $vehicle->id,
                'doc_type'   => 'VEHICLE_REGISTRATION',
            ]);
        }
    }
}
