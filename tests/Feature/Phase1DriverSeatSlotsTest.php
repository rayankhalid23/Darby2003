<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase1DriverSeatSlotsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_driver_shifts_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('drivers', [
            'morning_go',
            'morning_return',
            'afternoon_go',
            'afternoon_return'
        ]));
    }

    public function test_driver_seat_slots_has_date_and_booked_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('driver_seat_slots', [
            'driver_id',
            'slot',
            'date',
            'booked',
        ]));
        $this->assertFalse(
            Schema::hasColumn('driver_seat_slots', 'reserved_seats'),
            'reserved_seats يجب أن يكون محذوفاً في النموذج الجديد'
        );
        $this->assertFalse(
            Schema::hasColumn('driver_seat_slots', 'total_seats'),
            'total_seats يجب أن يكون محذوفاً في النموذج الجديد'
        );
    }
}