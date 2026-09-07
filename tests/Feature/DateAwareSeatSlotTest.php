<?php

namespace Tests\Feature;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverAbsence;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Driver\Vehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DateAwareSeatSlotTest extends TestCase
{
    use DatabaseTransactions;

    private Driver $driver;
    private int $capacity = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'full_name'     => 'خالد اختبار الخانات',
            'email'         => 'khalid.slot.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'         => $user->id,
            'status'          => 'Approved',
            'morning_go'      => true,
            'morning_return'  => true,
            'afternoon_go'    => true,
            'afternoon_return'=> true,
            'accepted_gender' => 'both',
            'license_expiry'  => now()->addYear()->toDateString(),
        ]);

        Vehicle::create([
            'driver_id'       => $this->driver->id,
            'plate_number'    => 'TEST-' . rand(1000, 9999),
            'brand'           => 'Toyota',
            'model'           => 'Hiace',
            'year'            => 2022,
            'status'          => 'Active',
            'capacity_manual' => $this->capacity,
            'has_ac'          => true,
            'color'           => 'white',
        ]);
    }

    // ── القاعدة 1: خانة بلا صف = متاح كامل ──
    public function test_empty_slot_returns_full_capacity(): void
    {
        $avail = DriverSeatSlot::available($this->driver->id, 'morning_go', '2026-09-09', $this->capacity);
        $this->assertEquals(4, $avail, 'خانة بلا صف يجب أن تُرجع الطاقة الكاملة');
    }

    // ── القاعدة 2: يوم غياب = 0 ──
    public function test_absence_day_returns_zero(): void
    {
        DriverAbsence::create([
            'driver_id'    => $this->driver->id,
            'absence_date' => '2026-09-09',
            'reason'       => 'مرض',
        ]);

        $avail = DriverSeatSlot::available($this->driver->id, 'morning_go', '2026-09-09', $this->capacity);
        $this->assertEquals(0, $avail, 'يوم الغياب يجب أن يُرجع 0');
    }

    // ── القاعدة 3: خانة بها booked = متاح = capacity - booked ──
    public function test_booked_slot_reduces_available(): void
    {
        DriverSeatSlot::create([
            'driver_id' => $this->driver->id,
            'slot'      => 'morning_go',
            'date'      => '2026-09-09',
            'booked'    => 2,
        ]);

        $avail = DriverSeatSlot::available($this->driver->id, 'morning_go', '2026-09-09', $this->capacity);
        $this->assertEquals(2, $avail, 'متاح = 4 - 2 = 2');
    }

    // ── مثال اللقطة الكاملة من الطلب ──
    public function test_snapshot_scenario_from_spec(): void
    {
        // سالم: morning_go + morning_return، 09-01 → 09-30
        // ليان: morning_go، 09-06 → 09-10
        // عمر: morning_return، 09-09 فقط
        // نور: afternoon_return، 09-01 → 09-30

        // محاكاة الحجوزات ليوم 09-09:
        // morning_go: سالم + ليان = 2
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_go', 'date' => '2026-09-09', 'booked' => 2]);
        // morning_return: سالم + عمر = 2
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_return', 'date' => '2026-09-09', 'booked' => 2]);
        // afternoon_return: نور = 1
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'afternoon_return', 'date' => '2026-09-09', 'booked' => 1]);
        // afternoon_go: لا صف (فارغة)

        $this->assertEquals(2, DriverSeatSlot::available($this->driver->id, 'morning_go', '2026-09-09', 4));
        $this->assertEquals(2, DriverSeatSlot::available($this->driver->id, 'morning_return', '2026-09-09', 4));
        $this->assertEquals(3, DriverSeatSlot::available($this->driver->id, 'afternoon_return', '2026-09-09', 4));
        $this->assertEquals(4, DriverSeatSlot::available($this->driver->id, 'afternoon_go', '2026-09-09', 4));
    }

    // ── minAvailableOverPeriod: طفلان صباحي ذهاب+إياب 09-09→09-10 ──
    public function test_min_available_over_period_two_children(): void
    {
        // 09-09: morning_go booked=2, morning_return booked=2
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_go', 'date' => '2026-09-09', 'booked' => 2]);
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_return', 'date' => '2026-09-09', 'booked' => 2]);
        // 09-10: morning_return booked=1 (عمر انتهى) → morning_go لا صف
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_return', 'date' => '2026-09-10', 'booked' => 1]);

        $min = DriverSeatSlot::minAvailableOverPeriod(
            $this->driver->id,
            ['morning_go', 'morning_return'],
            '2026-09-09',
            '2026-09-10',
            4
        );

        // أدنى خانة: morning_go@09-09 أو morning_return@09-09 = 4-2 = 2
        $this->assertEquals(2, $min, 'min = 2 (09-09 morning_go=2 متاح و morning_return=2 متاح)');

        // 2 أطفال يُقبل
        $this->assertGreaterThanOrEqual(2, $min, 'طفلان يجب أن يُقبلا');
        // 3 أطفال يُرفض
        $this->assertLessThan(3, $min, '3 أطفال يجب أن يُرفضوا بسبب 09-09');
    }

    // ── incrementBooked / decrementBooked ──
    public function test_increment_and_decrement_booked(): void
    {
        $date = '2026-09-15';
        $slot = 'morning_go';

        // لا صف في البداية
        $this->assertEquals(4, DriverSeatSlot::available($this->driver->id, $slot, $date, 4));

        // increment
        DriverSeatSlot::incrementBooked($this->driver->id, $slot, $date);
        $this->assertEquals(3, DriverSeatSlot::available($this->driver->id, $slot, $date, 4));

        DriverSeatSlot::incrementBooked($this->driver->id, $slot, $date);
        $this->assertEquals(2, DriverSeatSlot::available($this->driver->id, $slot, $date, 4));

        // decrement إلى 1
        DriverSeatSlot::decrementBooked($this->driver->id, $slot, $date);
        $this->assertEquals(3, DriverSeatSlot::available($this->driver->id, $slot, $date, 4));

        // decrement إلى 0 → يحذف الصف
        DriverSeatSlot::decrementBooked($this->driver->id, $slot, $date);
        $this->assertNull(
            DriverSeatSlot::where('driver_id', $this->driver->id)->where('slot', $slot)->where('date', $date)->first(),
            'الصف يجب أن يُحذف عند booked=0'
        );
        // ويعود للطاقة الكاملة
        $this->assertEquals(4, DriverSeatSlot::available($this->driver->id, $slot, $date, 4));
    }

    // ── الـ unique constraint ──
    public function test_unique_constraint_driver_slot_date(): void
    {
        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_go', 'date' => '2026-09-09', 'booked' => 1]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DriverSeatSlot::create(['driver_id' => $this->driver->id, 'slot' => 'morning_go', 'date' => '2026-09-09', 'booked' => 1]);
    }
}
