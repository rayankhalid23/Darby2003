<?php

namespace Tests\Feature;

use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\PlatformFinance;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use Carbon\Carbon;

/**
 * SCENARIO C: multi-day subscription across several days —
 * child absence, driver absence, idempotency, expiry, and the money flow.
 */
class TripMultiDayAuditTest extends TripAuditFixture
{
    public function test_C_multi_day_lifecycle(): void
    {
        // اشتراك 5 أيام (اليوم + 4)، ذهاب وعودة => 5 × 2 = 10 رحلات مدفوعة
        $s = $this->makeSubscription('Mohannad', 'x', 'multi_day', 4, 500.00);
        $childId = $s['child']->id;

        $finance = $this->holdEscrow($s['req']);
        $this->out('C0 multi_day escrow held: total=' . $finance->total_amount
            . ' expected_trips=' . $finance->expected_trips_count);
        $this->assertEquals(
            PlatformFinance::STATUS_HELD,
            $finance->status,
            'multi_day subscriptions must hold funds in escrow, not stay unfunded'
        );

        $driverStart = (int) $this->driver->fresh()->balance;
        $expectedTrips = (int) $finance->expected_trips_count;
        $shareCents = intdiv(50000, $expectedTrips);           // 5000
        $netPerTrip = $shareCents - (int) round($shareCents * 0.08); // 4600

        // ---------- DAY 1 ----------
        $t1 = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$t1}/start", ['latitude' => 32.875, 'longitude' => 13.175])->assertStatus(200);
        $this->asDriver()->postJson("/api/driver/trips/{$t1}/pickup", ['trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG])->assertStatus(200);
        $this->asDriver()->postJson("/api/driver/trips/{$t1}/dropoff", ['trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG])->assertStatus(200);
        $c1 = $this->asDriver()->postJson("/api/driver/trips/{$t1}/complete");
        $c1->assertStatus(200);

        $afterDay1 = (int) $this->driver->fresh()->balance;
        $this->out('C1 DAY1 complete: driver paid ' . ($afterDay1 - $driverStart) . ' cents (one trip share of ' . $expectedTrips . ')');
        $this->assertEquals($netPerTrip, $afterDay1 - $driverStart, 'day 1 must pay exactly one trip share');
        $this->assertEquals(PlatformFinance::STATUS_HELD, $finance->fresh()->status, 'escrow closed far too early');
        $this->assertEquals(1, (int) $finance->fresh()->settled_trips_count);

        // idempotency
        $again = $this->asDriver()->getJson('/api/driver/trips/today');
        $this->out('C2 DAY1 todayTrips called again: ' . json_encode($again->json('data'), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(
            1,
            Trip::where('route_id', $s['route']->id)->whereDate('trip_date', Carbon::today())->count(),
            'duplicate trips created for the same day'
        );

        // إعادة إنهاء نفس الرحلة يجب ألا تصرف حصة ثانية
        $this->asDriver()->postJson("/api/driver/trips/{$t1}/complete");
        $this->assertEquals(
            $netPerTrip,
            (int) $this->driver->fresh()->balance - $driverStart,
            'completing the same trip twice paid the driver twice'
        );

        // ---------- DAY 2 : غياب الطفل ----------
        $day2 = Carbon::today()->addDay();
        $abs = $this->asParent()->postJson("/api/parent/children/{$childId}/absence", [
            'dates' => [$day2->toDateString()], 'absence_type' => 'pickup',
        ]);
        $abs->assertStatus(200);

        $d2 = $this->asDriver()->getJson('/api/driver/trips/today?date=' . $day2->toDateString());
        $t2 = (int) $d2->json('data.0.trip_id');
        $stop2 = TripStop::where('trip_id', $t2)->where('child_id', $childId)->first();
        $this->out('C3 DAY2 child stop status=' . ($stop2->status ?? 'NULL') . ' seq=' . ($stop2->sequence_order ?? 'NULL'));
        $this->assertEquals(TripStop::STATUS_ABSENT_PRE, $stop2->status, 'pre-absence not applied on day 2');

        // الطفل الغائب يجب ألا يظهر لولي أمره ضمن الرحلات القادمة
        $p2 = $this->asParent()->getJson('/api/parent/trips/upcoming?date=' . $day2->toDateString());
        $this->out('C4 DAY2 parent upcoming while absent: ' . json_encode($p2->json('data'), JSON_UNESCAPED_UNICODE));
        $this->assertEmpty($p2->json('data'), 'an absent child is still listed in upcoming trips');

        // رحلة بلا أي طفل فعلي يجب ألا تُشغَّل
        $s2 = $this->asDriver()->postJson("/api/driver/trips/{$t2}/start", ['latitude' => 32.875, 'longitude' => 13.175]);
        $this->out('C5 DAY2 start an all-absent trip(' . $s2->status() . '): ' . json_encode($s2->json(), JSON_UNESCAPED_UNICODE));
        $s2->assertStatus(422);
        $this->assertEquals('NO_ACTIVE_CHILDREN', $s2->json('error_code'));

        $balanceBeforeDay2 = (int) $this->driver->fresh()->balance;
        $this->asDriver()->postJson("/api/driver/trips/{$t2}/complete");
        $this->assertEquals(
            $balanceBeforeDay2,
            (int) $this->driver->fresh()->balance,
            'an all-absent trip paid the driver'
        );

        // ---------- DAY 3 : غياب السائق ----------
        $day3 = Carbon::today()->addDays(2);
        $da = $this->asDriver()->postJson('/api/driver/trips/register-absence', [
            'dates' => [$day3->toDateString()], 'reason' => 'sick',
        ]);
        $da->assertStatus(200);

        $d3 = $this->asDriver()->getJson('/api/driver/trips/today?date=' . $day3->toDateString());
        $this->out('C6 DAY3 driver todayTrips: ' . json_encode($d3->json('data'), JSON_UNESCAPED_UNICODE));
        $this->assertEmpty($d3->json('data'), 'a trip was generated on an approved driver absence day');

        // ---------- DAY 4 ----------
        $day4 = Carbon::today()->addDays(3);
        $d4 = $this->asDriver()->getJson('/api/driver/trips/today?date=' . $day4->toDateString());
        $t4 = (int) $d4->json('data.0.trip_id');
        $this->out('C7 DAY4 trip=' . $t4 . ' child_stop='
            . (TripStop::where('trip_id', $t4)->where('child_id', $childId)->first()->status ?? 'NULL'));
        $this->assertNotEmpty($d4->json('data'), 'day 4 is inside the subscription and must have a trip');

        // scheduled_for يجب أن يحمل تاريخ الرحلة الحقيقي لا تاريخ اليوم
        $p4 = $this->asParent()->getJson('/api/parent/trips/upcoming?date=' . $day4->toDateString());
        $scheduledFor = $p4->json('data.0.scheduled_for');
        $this->out('C8 DAY4 parent scheduled_for=' . $scheduledFor . ' (trip date ' . $day4->toDateString() . ')');
        $this->assertStringStartsWith($day4->toDateString(), (string) $scheduledFor, 'scheduled_for carries the wrong date');

        // ---------- بعد انتهاء الاشتراك ----------
        $afterEnd = Carbon::today()->addDays(40);
        $dx = $this->asDriver()->getJson('/api/driver/trips/today?date=' . $afterEnd->toDateString());
        $this->out('C9 AFTER-END (' . $afterEnd->toDateString() . '): ' . json_encode($dx->json('data'), JSON_UNESCAPED_UNICODE)
            . ' | persisted rows=' . Trip::where('route_id', $s['route']->id)->whereDate('trip_date', $afterEnd)->count());
        $this->assertEquals(
            0,
            Trip::where('route_id', $s['route']->id)->whereDate('trip_date', $afterEnd)->count(),
            'a trip was generated after the subscription ended'
        );

        // ---------- تاريخ ماضٍ ----------
        $past = Carbon::today()->subDays(3);
        $dp = $this->asDriver()->getJson('/api/driver/trips/today?date=' . $past->toDateString());
        $this->out('C10 PAST-DATE (' . $past->toDateString() . ') persisted rows='
            . Trip::where('route_id', $s['route']->id)->whereDate('trip_date', $past)->count());
        $this->assertEquals(
            0,
            Trip::where('route_id', $s['route']->id)->whereDate('trip_date', $past)->count(),
            'a backdated trip was fabricated through the date query parameter'
        );

        $this->out('C11 FINANCE: driver ' . $driverStart . ' -> ' . $this->driver->fresh()->balance
            . ' | settled_trips=' . $finance->fresh()->settled_trips_count . '/' . $expectedTrips
            . ' | settled_amount=' . $finance->fresh()->settled_amount
            . ' | escrow_pool=' . MasterEscrowVault::getVault()->fresh()->parents_escrow_pool);
    }

    /**
     * اشتراك يوم واحد يغطي الذهاب والعودة: رحلة الذهاب وحدها يجب ألا تصرف المبلغ كاملاً.
     */
    public function test_C2_single_day_both_directions_pays_only_one_share(): void
    {
        $s = $this->makeSubscription('Bilal', 'x', 'single_day', 0, 100.00);
        $finance = $this->holdEscrow($s['req']);
        $driverBefore = (int) $this->driver->fresh()->balance;

        $t = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$t}/start", ['latitude' => 32.875, 'longitude' => 13.175]);
        $this->asDriver()->postJson("/api/driver/trips/{$t}/pickup", ['trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG]);
        $this->asDriver()->postJson("/api/driver/trips/{$t}/dropoff", ['trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG]);
        $this->asDriver()->postJson("/api/driver/trips/{$t}/complete")->assertStatus(200);

        $paid = (int) $this->driver->fresh()->balance - $driverBefore;
        $finance->refresh();
        $this->out('C2 morning trip only: paid=' . $paid . ' cents, settled=' . $finance->settled_trips_count
            . '/' . $finance->expected_trips_count . ', status=' . $finance->status);

        $this->assertEquals(4600, $paid, 'the morning trip must pay half the both-directions subscription');
        $this->assertEquals(PlatformFinance::STATUS_HELD, $finance->status, 'the return trip share must stay in escrow');
    }

    /**
     * حجزان منفصلان مع نفس السائق: إنهاء رحلة اليوم يجب ألا يمسّ أمانة حجز الغد.
     */
    public function test_C3_completing_one_trip_must_not_touch_a_future_booking_escrow(): void
    {
        $s = $this->makeSubscription('Zaid', 'x', 'single_day', 0, 100.00);
        $financeToday = $this->holdEscrow($s['req']);

        $req2 = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => 'accepted',
            'subscription_type'           => 'single_day',
            'total_price'                 => 250.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 250.00,
        ]);
        $req2->children()->attach($s['child']->id, [
            'subscription_type'           => 'single_day',
            'trip_direction'              => 'both',
            'timing'                      => 'MORNING',
            'start_date'                  => Carbon::tomorrow()->toDateString(),
            'end_date'                    => Carbon::tomorrow()->toDateString(),
            'working_days_count'          => 1,
            'distance_km'                 => 4.5,
            'trip_price'                  => 250.00,
            'price_per_child'             => 250.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 250.00,
            'driver_net_price'            => 230.00,
            'created_at'                  => now(), 'updated_at' => now(),
        ]);
        ActiveSubscription::create([
            'subscription_request_id' => $req2->id,
            'route_id'                => $s['route']->id,
            'child_id'                => $s['child']->id,
            'driver_id'               => $this->driver->id,
            'parent_id'               => $this->parentUser->id,
            'status'                  => 'active',
            'pickup_lat'              => self::HOME_LAT, 'pickup_lng' => self::HOME_LNG,
            'dropoff_lat'             => self::SCHOOL_LAT, 'dropoff_lng' => self::SCHOOL_LNG,
            'pickup_time'             => '07:00:00', 'dropoff_time' => '14:00:00',
        ]);
        $financeTomorrow = $this->holdEscrow($req2);

        $driverBefore = (int) $this->driver->fresh()->balance;

        $t = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$t}/start", ['latitude' => 32.875, 'longitude' => 13.175]);
        $this->asDriver()->postJson("/api/driver/trips/{$t}/pickup", ['trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG]);
        $this->asDriver()->postJson("/api/driver/trips/{$t}/dropoff", ['trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG]);
        $this->asDriver()->postJson("/api/driver/trips/{$t}/complete")->assertStatus(200);

        $financeToday->refresh();
        $financeTomorrow->refresh();
        $paid = (int) $this->driver->fresh()->balance - $driverBefore;

        $this->out('C3 today_escrow settled=' . $financeToday->settled_trips_count . '/' . $financeToday->expected_trips_count
            . ' | TOMORROW_escrow settled=' . $financeTomorrow->settled_trips_count . '/' . $financeTomorrow->expected_trips_count
            . ' status=' . $financeTomorrow->status
            . ' | driver paid=' . $paid . ' cents');

        $this->assertEquals(0, (int) $financeTomorrow->settled_trips_count, "tomorrow's booking was paid out today");
        $this->assertEquals(PlatformFinance::STATUS_HELD, $financeTomorrow->status, "tomorrow's escrow was closed");
        $this->assertEquals(4600, $paid, 'driver was paid more than the single trip actually performed');
    }
}
