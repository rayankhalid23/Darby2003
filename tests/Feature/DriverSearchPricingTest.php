<?php

namespace Tests\Feature;

use App\Models\Parent\Child;
use App\Models\Parent\ChildLogistics;
use App\Models\Parent\ParentModel;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\School;
use App\Models\Shared\Zone;
use App\Models\Shared\SubMunicipality;
use App\Models\Shared\Municipality;
use App\Models\Parent\Address;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اختبار endpoint بحث السائقين POST /api/parent/drivers/search:
 * - عرض بيانات النقل لكل طفل بشكل كامل (نوع الاشتراك بالتسمية العربية، الفترة،
 *   تاريخي البداية والنهاية، المدرسة وموقعها، المنزل وموقعه، الجنس، المرحلة الدراسية).
 * - دقة حساب السعر الإجمالي عند البحث بأكثر من طفل واحد.
 */
class DriverSearchPricingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected User $driverUser;
    protected Driver $driver;
    protected School $school;
    protected Address $homeAddress;
    protected Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $municipality = Municipality::firstOrCreate(['name' => 'طرابلس المركز']);
        $subMuni      = SubMunicipality::firstOrCreate(['municipality_id' => $municipality->id, 'name' => 'الظهرة']);
        $this->zone   = Zone::firstOrCreate(['sub_municipality_id' => $subMuni->id, 'name' => 'شارع النصر']);

        $this->school = School::create([
            'name'    => 'مدرسة النور النموذجية',
            'zone_id' => $this->zone->id,
            'lat'     => 32.88000000,
            'lng'     => 13.18000000,
            'address' => 'طرابلس - شارع النصر',
            'status'  => 'approved',
        ]);

        $this->parentUser = User::create([
            'full_name'     => 'أحمد سالم الفيتوري',
            'email'         => 'parent.pricing.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);
        $this->parent = ParentModel::create(['user_id' => $this->parentUser->id, 'is_trusted' => 1]);

        $this->homeAddress = Address::create([
            'parent_id'  => $this->parentUser->id,
            'zone_id'    => $this->zone->id,
            'label'      => 'المنزل الرئيسي',
            'lat'        => 32.88500000,
            'lng'        => 13.18500000,
            'is_default' => true,
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'الكابتن عبد السلام الزنتاني',
            'email'         => 'driver.pricing.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('password123'),
            'role_id'       => 4,
            'is_active'     => 1,
            'gender'        => 'male',
        ]);
        $this->driver = Driver::create([
            'user_id'           => $this->driverUser->id,
            'national_id'       => '11988' . rand(1000000, 9999999),
            'license_number'    => 'LY-' . rand(1000, 9999),
            'status'            => 'Approved',
            'gender'            => 'male',
            'accepted_gender'   => 'both',
            'subscription_type' => 'both',
        ]);

        Vehicle::create([
            'driver_id'       => $this->driver->id,
            'plate_number'    => 'PR-' . rand(1000, 9999),
            'brand'           => 'Toyota',
            'model'           => 'Hiace',
            'year'            => 2022,
            'color'           => 'White',
            'type'            => 'Van',
            'capacity_manual' => 14,
            'has_ac'          => true,
            'status'          => 'Active',
            'is_verified'     => true,
        ]);

        DB::table('driver_zone')->insert(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id]);

        foreach (['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'] as $slot) {
            DB::table('driver_seat_slots')->insert([
                'driver_id' => $this->driver->id, 'slot' => $slot,
                'total_seats' => 10, 'reserved_seats' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function makeChild(string $name, string $gender, int $grade): Child
    {
        return Child::create([
            'parent_id'  => $this->parent->id,
            'school_id'  => $this->school->id,
            'address_id' => $this->homeAddress->id,
            'full_name'  => $name,
            'birth_date' => '2016-05-10',
            'gender'     => $gender,
            'grade'      => $grade,
        ]);
    }

    /** Test 1: نوع الاشتراك يظهر بتسمية عربية واضحة (يوم واحد) بدل القيمة الخام فقط */
    public function test_single_day_subscription_shows_arabic_label_in_search(): void
    {
        $child = $this->makeChild('طفل يوم واحد', 'male', 3);
        ChildLogistics::create([
            'child_id'            => $child->id,
            'preferred_time_slot' => 'morning',
            'trip_direction'      => 'go',
            'subscription_type'   => 'single_day',
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', ['child_ids' => [$child->id]]);

        $response->assertStatus(200);
        $item = collect($response->json('data.0.pricing.breakdown'))->first();

        $this->assertEquals('single_day', $item['subscription_type']);
        $this->assertEquals('يوم واحد', $item['subscription_type_label']);
        $this->assertEquals('male', $item['gender']);
        $this->assertEquals('morning', $item['preferred_time_slot']);
    }

    /** Test 2: نفس الشيء لاشتراك عدة أيام + التحقق من ظهور تاريخي البداية والنهاية */
    public function test_multi_day_subscription_shows_label_and_dates(): void
    {
        $child = $this->makeChild('طفل عدة أيام', 'female', 8);
        $start = Carbon::now()->next(Carbon::SUNDAY)->format('Y-m-d');
        $end   = Carbon::parse($start)->addDays(6)->format('Y-m-d'); // نفس الأسبوع حتى السبت

        ChildLogistics::create([
            'child_id'            => $child->id,
            'preferred_time_slot' => 'both',
            'trip_direction'      => 'both',
            'subscription_type'   => 'multi_day',
            'start_date'          => $start,
            'end_date'            => $end,
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', ['child_ids' => [$child->id]]);

        $response->assertStatus(200);
        $item = collect($response->json('data.0.pricing.breakdown'))->first();

        $this->assertEquals('multi_day', $item['subscription_type']);
        $this->assertEquals('عدة أيام', $item['subscription_type_label']);
        // تقدير البحث يُحتسب ليوم عمل واحد؛ المدة الفعلية تُحدد عند إنشاء طلب الاشتراك
        $this->assertEquals(1, $item['working_days']);
        $this->assertEquals('female', $item['gender']);
        $this->assertNotNull($item['school_stage_label']);
    }

    /** Test 3: جميع بيانات الطفل (مدرسة/موقعها/منزل/موقعه/جنس/مرحلة) موجودة كاملة */
    public function test_breakdown_contains_full_child_information(): void
    {
        $child = $this->makeChild('طفل بيانات كاملة', 'male', 10);
        ChildLogistics::create([
            'child_id'            => $child->id,
            'preferred_time_slot' => 'evening',
            'trip_direction'      => 'return',
            'subscription_type'   => 'single_day',
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', ['child_ids' => [$child->id]]);

        $response->assertStatus(200);
        $item = collect($response->json('data.0.pricing.breakdown'))->first();

        foreach ([
            'child_id', 'child_name', 'gender', 'school_stage', 'school_stage_label',
            'subscription_type', 'subscription_type_label', 'preferred_time_slot', 'trip_direction',
            'school_name', 'school_address', 'school_location', 'home_label', 'home_location',
            'distance_km', 'price_per_km', 'working_days', 'child_price',
        ] as $key) {
            $this->assertArrayHasKey($key, $item, "المفتاح المفقود: {$key}");
        }

        $this->assertEquals('مدرسة النور النموذجية', $item['school_name']);
        $this->assertEquals('المنزل الرئيسي', $item['home_label']);
        $this->assertIsArray($item['school_location']);
        $this->assertIsArray($item['home_location']);
    }

    /** Test 4: دقة الإجمالي عند أكثر من طفل — الإجمالي = مجموع أسعار كل طفل بالضبط، والأطفال في مصفوفة */
    public function test_total_price_is_accurate_sum_of_multiple_children(): void
    {
        $child1 = $this->makeChild('الطفل الأول', 'male', 2);
        ChildLogistics::create([
            'child_id' => $child1->id, 'preferred_time_slot' => 'morning',
            'trip_direction' => 'go', 'subscription_type' => 'single_day',
        ]);

        $child2 = $this->makeChild('الطفل الثاني', 'female', 5);
        ChildLogistics::create([
            'child_id' => $child2->id, 'preferred_time_slot' => 'both',
            'trip_direction' => 'both', 'subscription_type' => 'single_day',
        ]);

        $child3 = $this->makeChild('الطفل الثالث', 'male', 9);
        ChildLogistics::create([
            'child_id' => $child3->id, 'preferred_time_slot' => 'morning',
            'trip_direction' => 'go', 'subscription_type' => 'single_day',
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', [
                'child_ids' => [$child1->id, $child2->id, $child3->id],
            ]);

        $response->assertStatus(200);
        $driverEntry = $response->json('data.0');

        $breakdown = $driverEntry['pricing']['breakdown'];
        $this->assertIsArray($breakdown);
        $this->assertCount(3, $breakdown, 'يجب أن تُرسَل بيانات الأطفال الثلاثة كمصفوفة كاملة');
        $this->assertEquals(3, $driverEntry['pricing']['children_count']);

        // ✅ تدقيق الدقة: الإجمالي المُعاد يجب أن يساوي بالضبط مجموع child_price_raw لكل طفل
        $sumOfChildren = round(collect($breakdown)->sum('child_price_raw'), 2);
        $this->assertEquals($sumOfChildren, $driverEntry['pricing']['total_price_raw']);

        // الطفل الثاني (ذهاب وإياب) يجب أن يكون سعره ضعف طفل بنفس المسافة (ذهاب فقط) تقريباً
        $child1Price = collect($breakdown)->firstWhere('child_id', $child1->id)['child_price_raw'];
        $child2Price = collect($breakdown)->firstWhere('child_id', $child2->id)['child_price_raw'];
        if ($child1Price > 0) {
            $this->assertEqualsWithDelta($child1Price * 2, $child2Price, 0.05);
        }
    }

    /** Test 5: البحث باسم السائق بدون تحديد أي أطفال ينجح ويعيد نفس هيكل البيانات */
    public function test_search_by_driver_name_without_children_returns_identical_structure(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', [
                'search_query' => 'عبد السلام',
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));

        $driverEntry = $response->json('data.0');
        $this->assertEquals($this->driver->id, $driverEntry['id']);
        $this->assertEquals('الكابتن عبد السلام الزنتاني', $driverEntry['full_name']);

        // التحقق من وجود كافة الحقول بنفس هيكلية الفلترة الذكية
        $this->assertArrayHasKey('id', $driverEntry);
        $this->assertArrayHasKey('full_name', $driverEntry);
        $this->assertArrayHasKey('phone_number', $driverEntry);
        $this->assertArrayHasKey('alternative_phone', $driverEntry);
        $this->assertArrayHasKey('avatar_url', $driverEntry);
        $this->assertArrayHasKey('gender', $driverEntry);
        $this->assertArrayHasKey('accepted_gender', $driverEntry);
        $this->assertArrayHasKey('subscription_type', $driverEntry);
        $this->assertArrayHasKey('rating', $driverEntry);
        $this->assertArrayHasKey('completed_trips', $driverEntry);
        $this->assertArrayHasKey('status', $driverEntry);
        $this->assertArrayHasKey('vehicle', $driverEntry);
        $this->assertArrayHasKey('working_zones', $driverEntry);
        $this->assertArrayHasKey('pricing', $driverEntry);

        // تفاصيل التسعير
        $pricing = $driverEntry['pricing'];
        $this->assertArrayHasKey('total_price', $pricing);
        $this->assertArrayHasKey('total_price_raw', $pricing);
        $this->assertArrayHasKey('platform_fee', $pricing);
        $this->assertArrayHasKey('driver_net_amount', $pricing);
        $this->assertArrayHasKey('price_per_km', $pricing);
        $this->assertArrayHasKey('children_count', $pricing);
        $this->assertArrayHasKey('breakdown', $pricing);

        $this->assertEquals(0, $pricing['children_count']);
        $this->assertIsArray($pricing['breakdown']);
        $this->assertNotNull($pricing['price_per_km']);
    }

    /** Test 6: البحث برقم الهاتف مع عدم وجود مصفوفة أطفال */
    public function test_search_by_driver_phone_without_children(): void
    {
        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', [
                'phone' => $this->driverUser->phone_number,
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));

        $driverEntry = $response->json('data.0');
        $this->assertEquals($this->driver->id, $driverEntry['id']);
        $this->assertEquals($this->driverUser->phone_number, $driverEntry['phone_number']);
    }

    /** Test 7: البحث برقم هاتف جزئي (مثلاً آخر 6 أرقام) بدون أطفال */
    public function test_search_by_partial_phone_number(): void
    {
        $partialPhone = substr($this->driverUser->phone_number, 3);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', [
                'search_query' => $partialPhone,
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals($this->driver->id, $response->json('data.0.id'));
    }

    /** Test 8: البحث باسم السائق مع وجود أطفال يحسب السعر ويجلب السائق المطلوب */
    public function test_search_by_driver_name_with_children_computes_pricing(): void
    {
        $child = $this->makeChild('طفل بحث مع تسعير', 'male', 4);
        ChildLogistics::create([
            'child_id'            => $child->id,
            'preferred_time_slot' => 'morning',
            'trip_direction'      => 'go',
            'subscription_type'   => 'single_day',
        ]);

        $response = $this->actingAs($this->parentUser, 'sanctum')
            ->postJson('/api/parent/drivers/search', [
                'name'      => 'عبد السلام',
                'child_ids' => [$child->id],
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
        $driverEntry = $response->json('data.0');
        $this->assertEquals($this->driver->id, $driverEntry['id']);
        $this->assertEquals(1, $driverEntry['pricing']['children_count']);
        $this->assertGreaterThan(0, $driverEntry['pricing']['total_price_raw']);
    }
}
