<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\Child;
use App\Models\Parent\Address;
use App\Models\Parent\School;
use App\Models\Shared\Zone;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Municipality;
use App\Models\Shared\PricingSetting;

class DualZoneCrossSubMunicipalityMatchingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected User $driverUser1;
    protected User $driverUser2;
    protected Driver $driver1;
    protected Driver $driver2;
    protected Zone $homeZone;
    protected Zone $schoolZone;
    protected Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. تسعير المنظومة
        PricingSetting::firstOrCreate([], [
            'price_per_km_ac'           => 3.00,
            'price_per_km_non_ac'       => 2.50,
            'discount_one_child'        => 0.00,
            'discount_two_children'     => 10.00,
            'discount_three_plus_children' => 15.00,
            'platform_commission_rate'  => 8.00,
        ]);

        // 2. إنشاء بلديتين فرعيتين مختلفتين (مثال: حي الأندلس و طرابلس المركز)
        $municipality = Municipality::firstOrCreate(['name' => 'بلدية طرابلس الكبرى']);

        $subMuniHome = SubMunicipality::firstOrCreate([
            'name'            => 'بلدية حي الأندلس الفرعية',
            'municipality_id' => $municipality->id,
        ]);

        $subMuniSchool = SubMunicipality::firstOrCreate([
            'name'            => 'بلدية طرابلس المركز الفرعية',
            'municipality_id' => $municipality->id,
        ]);

        // منطقة سكن الطفل في بلدية حي الأندلس
        $this->homeZone = Zone::firstOrCreate([
            'name'                => 'منطقة غوط الشعال',
            'sub_municipality_id' => $subMuniHome->id,
        ]);

        // منطقة مدرسة الطفل في بلدية طرابلس المركز
        $this->schoolZone = Zone::firstOrCreate([
            'name'                => 'منطقة بن عاشور',
            'sub_municipality_id' => $subMuniSchool->id,
        ]);

        // 3. إنشاء ولي أمر وطفل
        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر تجريبي',
            'email'         => 'parent.dualzone.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);

        $address = Address::create([
            'user_id' => $this->parentUser->id,
            'zone_id' => $this->homeZone->id,
            'label'   => 'منزل الطفل',
            'lat'     => 32.8600,
            'lng'     => 13.1500,
        ]);

        $school = School::create([
            'name'     => 'مدرسة بن عاشور النموذجية',
            'zone_id'  => $this->schoolZone->id,
            'address'  => 'شارع بن عاشور الرئيسي',
            'lat'      => 32.8800,
            'lng'      => 13.1900,
            'is_active'=> 1,
        ]);

        $this->child = Child::create([
            'parent_id'           => $this->parentUser->id,
            'school_id'           => $school->id,
            'address_id'          => $address->id,
            'full_name'           => 'أحمد التجريبي',
            'birth_date'          => '2015-05-10',
            'grade'               => 4,
            'gender'              => 'male',
            'school_stage'        => 'primary',
            'preferred_time_slot' => 'morning',
            'is_active'           => 1,
        ]);

        // 4. السائق 1: يغطي خط السير كاملاً عبر بلديتين مختلفتين (غوط الشعال + بن عاشور)
        $this->driverUser1 = User::create([
            'full_name'     => 'السائق الشامل لخط السير',
            'email'         => 'driver1.dual.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);

        $this->driver1 = Driver::create([
            'user_id'           => $this->driverUser1->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYear()->format('Y-m-d'),
            'status'            => 'Approved',
            'morning_go'        => true,
            'morning_return'    => true,
            'subscription_type' => 'both',
            'accepted_gender'   => 'both',
            'rating_avg'        => 4.9,
        ]);

        Vehicle::create([
            'driver_id'       => $this->driver1->id,
            'brand'           => 'Toyota',
            'model'           => 'Hiace',
            'year'            => 2022,
            'color'           => 'White',
            'type'            => 'Van',
            'plate_number'    => '11-12345',
            'capacity_manual' => 14,
            'has_ac'          => true,
            'status'          => 'Active',
        ]);

        DriverSeatSlot::create([
            'driver_id'      => $this->driver1->id,
            'slot'           => 'morning_go',
            'total_seats'    => 14,
            'reserved_seats' => 0,
        ]);
        DriverSeatSlot::create([
            'driver_id'      => $this->driver1->id,
            'slot'           => 'morning_return',
            'total_seats'    => 14,
            'reserved_seats' => 0,
        ]);

        // ربط السائق بالمنطقتين عبر البلديتين المختلفتين
        $this->driver1->zones()->sync([$this->homeZone->id, $this->schoolZone->id]);

        // 5. السائق 2: يغطي فقط منطقة السكن (غوط الشعال) ولا يذهب إلى بن عاشور
        $this->driverUser2 = User::create([
            'full_name'     => 'السائق المحلي فقط',
            'email'         => 'driver2.local.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);

        $this->driver2 = Driver::create([
            'user_id'           => $this->driverUser2->id,
            'national_id'       => 'NAT' . rand(100000, 999999),
            'license_number'    => 'LIC' . rand(100000, 999999),
            'license_expiry'    => now()->addYear()->format('Y-m-d'),
            'status'            => 'Approved',
            'morning_go'        => true,
            'morning_return'    => true,
            'subscription_type' => 'both',
            'accepted_gender'   => 'both',
            'rating_avg'        => 4.8,
        ]);

        Vehicle::create([
            'driver_id'       => $this->driver2->id,
            'brand'           => 'Hyundai',
            'model'           => 'H1',
            'year'            => 2021,
            'color'           => 'Silver',
            'type'            => 'Van',
            'plate_number'    => '22-54321',
            'capacity_manual' => 12,
            'has_ac'          => true,
            'status'          => 'Active',
        ]);

        DriverSeatSlot::create([
            'driver_id'      => $this->driver2->id,
            'slot'           => 'morning_go',
            'total_seats'    => 12,
            'reserved_seats' => 0,
        ]);

        // السائق 2 يغطي فقط منطقة السكن
        $this->driver2->zones()->sync([$this->homeZone->id]);
    }

    /**
     * اختبار: فحص نجاح محرك الفلترة الذكي Dual-Zone
     * يجب أن يعثر على السائق 1 (الذي يغطي المنطقتين عبر البلديتين)
     * ويستبعد السائق 2 (الذي يغطي السكن فقط دون المدرسة)
     */
    public function test_dual_zone_search_matches_driver_spanning_sub_municipalities(): void
    {
        $response = $this->actingAs($this->parentUser)
            ->postJson('/api/parent/drivers/search', [
                'child_ids' => [$this->child->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $driverIds = collect($response->json('data'))->pluck('id')->all();

        // السائق 1 يجب أن يظهر لأن خط سيره يغطي المنطقتين
        $this->assertContains($this->driver1->id, $driverIds);

        // السائق 2 يجب استبعاده لأنه لا يغطي منطقة المدرسة
        $this->assertNotContains($this->driver2->id, $driverIds);

        // التحقق من حساب التسعير التقديري للسائق 1
        $driver1Data = collect($response->json('data'))->firstWhere('id', $this->driver1->id);
        $this->assertNotNull($driver1Data);
        $this->assertGreaterThan(0, $driver1Data['pricing']['total_price_raw']);
        $this->assertEquals(1, $driver1Data['pricing']['children_count']);
    }

    /**
     * اختبار: منع إضافة أكثر من 5 مناطق للسائق عبر Service
     */
    public function test_driver_cannot_exceed_five_zones(): void
    {
        $service = app(\App\Services\Driver\DriverPreferenceService::class);

        // إنشاء 6 مناطق إضافية
        $zones = [];
        for ($i = 1; $i <= 6; $i++) {
            $zones[] = Zone::firstOrCreate([
                'name' => "منطقة سقف إضافية {$i}",
                'sub_municipality_id' => $this->homeZone->sub_municipality_id,
            ])->id;
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('الحد الأقصى للمناطق التي يمكن للسائق تغطيتها هو 5 مناطق');

        $service->updatePreferences($this->driver1, [
            'zones' => $zones, // 6 مناطق > 5
        ]);
    }
}
