<?php

namespace Tests\Feature;

use App\Models\Driver\DriverAbsence;
use App\Models\Shared\Trip;
use App\Models\Shared\TripTracking;
use Carbon\Carbon;

/**
 * SCENARIO B: constraints, state machine, ownership and input validation.
 */
class TripConstraintsAuditTest extends TripAuditFixture
{
    public function test_B01_driver_absent_cannot_start(): void
    {
        $this->makeSubscription('Tarek');
        $tripId = $this->generateTodayTrip();

        DriverAbsence::create(['driver_id' => $this->driver->id, 'absence_date' => Carbon::today()->toDateString()]);

        $r = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", [
            'latitude' => 32.875, 'longitude' => 13.175,
        ]);
        $this->out('B01 start WITH tripId while absent(' . $r->status() . '): ' . json_encode($r->json(), JSON_UNESCAPED_UNICODE));
        $this->out('B01 trip status after: ' . Trip::find($tripId)->status);
        $r->assertStatus(422);
        $this->assertEquals('DRIVER_ABSENT', $r->json('error_code'));
        $this->assertEquals('pending', Trip::find($tripId)->status, 'absent driver managed to start the trip');

        $r2 = $this->asDriver()->postJson('/api/driver/trips/start', ['trip_type' => 'Morning', 'latitude' => 32.875, 'longitude' => 13.175]);
        $this->out('B01 start WITHOUT tripId while absent(' . $r2->status() . '): ' . json_encode($r2->json(), JSON_UNESCAPED_UNICODE));
        $r2->assertStatus(400);
    }

    public function test_B02_manual_pickup_geofence_and_missing_location(): void
    {
        $s = $this->makeSubscription('Nabil');
        $tripId = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", ['latitude' => 32.875, 'longitude' => 13.175]);

        $far = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id,
            'latitude' => 32.95, 'longitude' => 13.25,
        ]);
        $this->out('B02 far pickup(' . $far->status() . '): ' . json_encode($far->json(), JSON_UNESCAPED_UNICODE));
        $far->assertStatus(422);
        $this->assertEquals('OUT_OF_RANGE', $far->json('error_code'));

        $noLoc = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id,
        ]);
        $this->out('B02 no-location pickup(' . $noLoc->status() . '): ' . json_encode($noLoc->json(), JSON_UNESCAPED_UNICODE));
        $noLoc->assertStatus(422);
        $this->assertEquals('LOCATION_REQUIRED', $noLoc->json('error_code'));

        // التأكيد اليدوي ينجح فقط عند التواجد الفعلي قرب محطة المنزل (لا يوجد أي مسار بديل يتجاوز هذا الفحص)
        $nearPickup = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id,
            'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ]);
        $this->out('B02 near pickup(' . $nearPickup->status() . ')');
        $nearPickup->assertStatus(200);
    }

    public function test_B03_state_machine_order_and_duplicates(): void
    {
        $s = $this->makeSubscription('Wissam');
        $tripId = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", ['latitude' => 32.875, 'longitude' => 13.175]);

        $d1 = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/dropoff", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG,
        ]);
        $this->out('B03 dropoff-before-pickup(' . $d1->status() . '): ' . json_encode($d1->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(409, $d1->status());

        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ])->assertStatus(200);

        $p2 = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ]);
        $this->out('B03 duplicate pickup(' . $p2->status() . '): ' . json_encode($p2->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(409, $p2->status());

        $ab = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/absent", ['trip_child_id' => $s['sub']->id]);
        $this->out('B03 absent-after-boarded(' . $ab->status() . '): ' . json_encode($ab->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(409, $ab->status());

        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/dropoff", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG,
        ])->assertStatus(200);
        $d2 = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/dropoff", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG,
        ]);
        $this->out('B03 duplicate dropoff(' . $d2->status() . '): ' . json_encode($d2->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(409, $d2->status());
    }

    public function test_B04_actions_on_not_started_and_completed_trip(): void
    {
        $s = $this->makeSubscription('Karim');
        $tripId = $this->generateTodayTrip();

        $this->out('B04 trip status before any start: ' . Trip::find($tripId)->status);
        $p = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ]);
        $this->out('B04 pickup on NOT-STARTED trip(' . $p->status() . '): ' . json_encode($p->json(), JSON_UNESCAPED_UNICODE));
        $p->assertStatus(409);
        $this->assertEquals('TRIP_NOT_STARTED', $p->json('error_code'));

        // الآن نبدأ الرحلة بشكل صحيح ثم ننفذها
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", ['latitude' => 32.875, 'longitude' => 13.175])
            ->assertStatus(200);
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ])->assertStatus(200);
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/dropoff", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG,
        ])->assertStatus(200);
        $c = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/complete");
        $this->out('B04 complete(' . $c->status() . ') status=' . Trip::find($tripId)->status);

        $rs = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", ['latitude' => 32.875, 'longitude' => 13.175]);
        $this->out('B04 re-start a COMPLETED trip(' . $rs->status() . '): ' . json_encode($rs->json(), JSON_UNESCAPED_UNICODE));
        $this->out('B04 trip status after re-start: ' . Trip::find($tripId)->status);
        $rs->assertStatus(409);
        $this->assertEquals('TRIP_NOT_STARTABLE', $rs->json('error_code'));
        $this->assertEquals('completed', Trip::find($tripId)->status, 'a completed trip was re-opened');

        $pAfter = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ]);
        $this->out('B04 pickup on COMPLETED trip(' . $pAfter->status() . '): ' . json_encode($pAfter->json(), JSON_UNESCAPED_UNICODE));
        $pAfter->assertStatus(409);
        $this->assertEquals('TRIP_ALREADY_COMPLETED', $pAfter->json('error_code'));
    }

    public function test_B05_idor_cross_driver_and_cross_parent(): void
    {
        $s = $this->makeSubscription('Ayman');
        $tripId = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", ['latitude' => 32.875, 'longitude' => 13.175]);

        $other = $this->makeOtherDriver();
        $otherParent = $this->makeOtherParent();

        $r1 = $this->actingAs($other['user'], 'sanctum')->getJson("/api/driver/trips/{$tripId}");
        $this->out('B05 other-driver GET trip(' . $r1->status() . ')');

        $latBefore = (float) $this->driver->fresh()->current_lat;
        $r2 = $this->actingAs($other['user'], 'sanctum')->postJson("/api/driver/trips/{$tripId}/location", [
            'latitude' => 30.111111, 'longitude' => 10.222222, 'speed' => 40,
        ]);
        $latAfter = (float) $this->driver->fresh()->current_lat;
        $this->out('B05 other-driver POST location(' . $r2->status() . ') victim current_lat ' . $latBefore . ' -> ' . $latAfter);
        $this->out('B05 fake tracking rows injected: ' . TripTracking::where('trip_id', $tripId)->where('latitude', 30.111111)->count());
        $r2->assertStatus(404);
        $this->assertEquals($latBefore, $latAfter, 'another driver overwrote the victim live location');
        $this->assertEquals(0, TripTracking::where('trip_id', $tripId)->where('latitude', 30.111111)->count(),
            'another driver injected fake tracking points');

        $r3 = $this->actingAs($other['user'], 'sanctum')->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ]);
        $this->out('B05 other-driver pickup(' . $r3->status() . ')');

        $r4 = $this->actingAs($other['user'], 'sanctum')->postJson("/api/driver/trips/{$tripId}/complete");
        $this->out('B05 other-driver complete(' . $r4->status() . ')');

        $r5 = $this->actingAs($otherParent, 'sanctum')->getJson("/api/parent/trips/{$tripId}");
        $this->out('B05 other-parent GET trip(' . $r5->status() . '): ' . substr(json_encode($r5->json(), JSON_UNESCAPED_UNICODE), 0, 350));
        $r5->assertStatus(400);

        $r6 = $this->actingAs($otherParent, 'sanctum')->getJson("/api/parent/trips/{$tripId}/track");
        $this->out('B05 other-parent TRACK(' . $r6->status() . '): ' . substr(json_encode($r6->json(), JSON_UNESCAPED_UNICODE), 0, 350));
        $r6->assertStatus(400);

        $r7 = $this->actingAs($otherParent, 'sanctum')->getJson("/api/parent/trips/{$tripId}/children/{$s['child']->id}/status");
        $this->out('B05 other-parent CHILD STATUS(' . $r7->status() . '): ' . substr(json_encode($r7->json(), JSON_UNESCAPED_UNICODE), 0, 350));
        $r7->assertStatus(400);

        $r8 = $this->actingAs($otherParent, 'sanctum')->getJson("/api/parent/trips/{$tripId}/timeline");
        $this->out('B05 other-parent TIMELINE(' . $r8->status() . '): ' . substr(json_encode($r8->json(), JSON_UNESCAPED_UNICODE), 0, 350));
        $r8->assertStatus(400);
    }

    public function test_B06_input_validation(): void
    {
        $this->makeSubscription('Salah');
        $tripId = $this->generateTodayTrip();

        $bad = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/location", [
            'latitude' => 999, 'longitude' => -900, 'speed' => -5, 'heading' => 400,
        ]);
        $this->out('B06 bad GPS(' . $bad->status() . '): ' . json_encode($bad->json(), JSON_UNESCAPED_UNICODE));
        $bad->assertStatus(422);
        $this->assertStringNotContainsString('validation.', json_encode($bad->json('errors'), JSON_UNESCAPED_UNICODE),
            'a raw untranslated validation key reached the client');

        $missing = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", []);
        $this->out('B06 pickup with empty body(' . $missing->status() . '): ' . json_encode($missing->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(422, $missing->status());
        $this->assertEquals('TRIP_CHILD_REQUIRED', $missing->json('error_code'));
        $this->assertStringNotContainsString('App\\Models', json_encode($missing->json()), 'internal class name leaked to the client');

        $ghost = $this->asDriver()->getJson('/api/driver/trips/99999999');
        $this->out('B06 unknown trip id(' . $ghost->status() . '): ' . json_encode($ghost->json(), JSON_UNESCAPED_UNICODE));

        $ghostLoc = $this->asDriver()->postJson('/api/driver/trips/99999999/location', ['latitude' => 32.8, 'longitude' => 13.1]);
        $this->out('B06 location on unknown trip(' . $ghostLoc->status() . '): ' . substr(json_encode($ghostLoc->json(), JSON_UNESCAPED_UNICODE), 0, 200));

        $past = $this->asDriver()->postJson('/api/driver/trips/register-absence', [
            'dates' => [Carbon::yesterday()->toDateString()],
        ]);
        $this->out('B06 backdated driver absence(' . $past->status() . '): ' . json_encode($past->json(), JSON_UNESCAPED_UNICODE));
        $past->assertStatus(422);

        $absOther = $this->asParent()->postJson('/api/parent/children/999999/absence', [
            'dates' => [Carbon::tomorrow()->toDateString()],
        ]);
        $this->out('B06 absence for a foreign child(' . $absOther->status() . '): ' . json_encode($absOther->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_B07_timeline_leaks_other_childrens_names(): void
    {
        $s = $this->makeSubscription('SecretChildName');
        $tripId = $this->generateTodayTrip();
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", ['latitude' => 32.875, 'longitude' => 13.175]);
        $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id' => $s['sub']->id, 'latitude' => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ])->assertStatus(200);

        $stranger = $this->makeOtherParent();
        $tl = $this->actingAs($stranger, 'sanctum')->getJson("/api/parent/trips/{$tripId}/timeline");
        $body = json_encode($tl->json(), JSON_UNESCAPED_UNICODE);
        $this->out('B07 stranger TIMELINE(' . $tl->status() . '): ' . $body);
        $this->out('B07 leaks another parent child name: ' . (str_contains($body, 'SecretChildName') ? 'YES' : 'no'));
        $this->assertStringNotContainsString('SecretChildName', $body, 'timeline leaks another family child name');

        $det = $this->actingAs($stranger, 'sanctum')->getJson("/api/parent/trips/{$tripId}");
        $this->out('B07 stranger TRIP DETAILS(' . $det->status() . '): '
            . json_encode($det->json(), JSON_UNESCAPED_UNICODE));
        $det->assertStatus(400);
        $this->assertNull($det->json('data.driver'), 'driver PII exposed to a stranger');
    }
}
