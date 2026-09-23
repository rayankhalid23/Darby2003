<?php

namespace Tests\Feature;

use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\PlatformFinance;
use App\Models\Shared\TripStop;

/**
 * SCENARIO A: one full day, generation -> driver flow -> parent view -> settlement.
 */
class FullTripScenarioAuditTest extends TripAuditFixture
{
    public function test_scenario_A_full_single_day_happy_path(): void
    {
        $s = $this->makeSubscription('Ahmed', 'x', 'single_day', 0, 100.00);
        $finance = $this->holdEscrow($s['req']);

        $vaultBefore  = MasterEscrowVault::getVault()->fresh();
        $driverBefore = (int) $this->driver->fresh()->balance;

        $today = $this->asDriver()->getJson('/api/driver/trips/today');
        $today->assertStatus(200);
        $tripsList = $today->json('data');
        $this->assertNotEmpty($tripsList, 'driver sees no trip today');
        $tripId = $tripsList[0]['trip_id'];
        $this->out('A1 TODAY: ' . json_encode($tripsList[0], JSON_UNESCAPED_UNICODE));

        $show = $this->asDriver()->getJson("/api/driver/trips/{$tripId}");
        $show->assertStatus(200);
        $this->out('A2 SHOW: ' . substr(json_encode($show->json('data'), JSON_UNESCAPED_UNICODE), 0, 1400));

        $stops = $this->asDriver()->getJson("/api/driver/trips/{$tripId}/stops");
        $stops->assertStatus(200);
        $this->out('A3 STOPS: ' . json_encode($stops->json('data.stops'), JSON_UNESCAPED_UNICODE));

        $upcoming = $this->asParent()->getJson('/api/parent/trips/upcoming');
        $this->out('A4 PARENT UPCOMING(' . $upcoming->status() . '): ' . substr(json_encode($upcoming->json('data'), JSON_UNESCAPED_UNICODE), 0, 900));

        $start = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/start", [
            'latitude' => 32.87500000, 'longitude' => 13.17500000,
        ]);
        $start->assertStatus(200)->assertJsonPath('data.status', 'in_progress');

        $loc = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/location", [
            'latitude' => 32.87800000, 'longitude' => 13.17800000, 'speed' => 30, 'heading' => 90,
        ]);
        $this->out('A5 LOCATION(' . $loc->status() . '): ' . substr(json_encode($loc->json(), JSON_UNESCAPED_UNICODE), 0, 500));

        $liveBefore = $this->asDriver()->getJson("/api/driver/trips/{$tripId}/live");
        $this->out('A6 DRIVER LIVE before pickup: ' . json_encode($liveBefore->json('data.progress'), JSON_UNESCAPED_UNICODE));

        $active = $this->asParent()->getJson('/api/parent/trips/active');
        $this->out('A7 PARENT ACTIVE(' . $active->status() . '): ' . substr(json_encode($active->json('data'), JSON_UNESCAPED_UNICODE), 0, 1200));

        $track = $this->asParent()->getJson("/api/parent/trips/{$tripId}/track");
        $this->out('A8 PARENT TRACK(' . $track->status() . '): ' . substr(json_encode($track->json('data'), JSON_UNESCAPED_UNICODE), 0, 800));

        $pick = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/pickup", [
            'trip_child_id'       => $s['sub']->id,
            'latitude'            => self::HOME_LAT, 'longitude' => self::HOME_LNG,
        ]);
        $this->out('A9 PICKUP(' . $pick->status() . '): ' . json_encode($pick->json(), JSON_UNESCAPED_UNICODE));
        $pick->assertStatus(200);

        $active2 = $this->asParent()->getJson('/api/parent/trips/active');
        $this->out('A10 PARENT ACTIVE after pickup - occupancy: ' . json_encode($active2->json('data.0.bus_occupancy'), JSON_UNESCAPED_UNICODE)
            . ' child_status=' . json_encode($active2->json('data.0.children.0.child_status'), JSON_UNESCAPED_UNICODE));

        $cs = $this->asParent()->getJson("/api/parent/trips/{$tripId}/children/{$s['child']->id}/status");
        $this->out('A11 CHILD STATUS(' . $cs->status() . '): ' . json_encode($cs->json('data'), JSON_UNESCAPED_UNICODE));
        $cp = $this->asParent()->getJson("/api/parent/trips/{$tripId}/children/{$s['child']->id}/progress");
        $this->out('A12 CHILD PROGRESS(' . $cp->status() . '): ' . substr(json_encode($cp->json('data'), JSON_UNESCAPED_UNICODE), 0, 700));

        // safety valve: cannot complete while a child is still on the bus
        $badComplete = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/complete");
        $this->out('A13 COMPLETE-WHILE-BOARDED(' . $badComplete->status() . '): ' . json_encode($badComplete->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(422, $badComplete->status());
        $this->assertEquals('FORGOTTEN_CHILDREN_ON_BUS', $badComplete->json('error_code'));

        $drop = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/dropoff", [
            'trip_child_id'       => $s['sub']->id,
            'latitude'            => self::SCHOOL_LAT, 'longitude' => self::SCHOOL_LNG,
        ]);
        $this->out('A14 DROPOFF(' . $drop->status() . '): ' . json_encode($drop->json('next_stop'), JSON_UNESCAPED_UNICODE));
        $drop->assertStatus(200);

        $done = $this->asDriver()->postJson("/api/driver/trips/{$tripId}/complete");
        $done->assertStatus(200);
        $this->out('A15 COMPLETE: ' . json_encode($done->json(), JSON_UNESCAPED_UNICODE));

        $this->out('A16 STOPS after completion: ' . json_encode(
            TripStop::where('trip_id', $tripId)->get(['stop_type', 'status', 'sequence_order'])->toArray(),
            JSON_UNESCAPED_UNICODE
        ));

        $finance->refresh();
        $vaultAfter  = MasterEscrowVault::getVault()->fresh();
        $driverAfter = (int) $this->driver->fresh()->balance;

        $this->out(sprintf(
            'A17 FINANCE: status=%s | driver %d -> %d (delta %d) | escrow %d -> %d | revenue %d -> %d',
            $finance->status, $driverBefore, $driverAfter, $driverAfter - $driverBefore,
            $vaultBefore->parents_escrow_pool, $vaultAfter->parents_escrow_pool,
            $vaultBefore->platform_revenue_pool, $vaultAfter->platform_revenue_pool
        ));

        // الاشتراك يغطي رحلتين (ذهاب + عودة)، ورحلة الذهاب وحدها نُفّذت:
        // تُصرف حصة رحلة واحدة فقط (50 د.ل) وتبقى الأمانة مفتوحة لرحلة العودة.
        $this->assertEquals(2, (int) $finance->expected_trips_count, 'expected_trips_count wrong');
        $this->assertEquals(1, (int) $finance->settled_trips_count, 'only one trip should be settled');
        $this->assertEquals(
            PlatformFinance::STATUS_HELD,
            $finance->status,
            'escrow must stay held until every paid trip has run'
        );
        $this->assertEquals(4600, $driverAfter - $driverBefore, 'driver must be paid one trip share only (50 LYD - 8%)');
        $this->assertEquals(
            $vaultBefore->parents_escrow_pool - 5000,
            $vaultAfter->parents_escrow_pool,
            'escrow pool must drop by one trip share only'
        );
        $this->assertEquals(
            $vaultBefore->platform_revenue_pool + 400,
            $vaultAfter->platform_revenue_pool,
            'commission must be proportional too'
        );

        // محطة المدرسة يجب أن تُغلق بعد اكتمال الرحلة (لا تبقى pending للأبد)
        $schoolStop = TripStop::where('trip_id', $tripId)->where('stop_type', 'school')->first();
        $this->assertNotContains(
            $schoolStop->status,
            TripStop::NON_FINAL_STATUSES,
            'school stop was left unfinished after trip completion'
        );

        // المدة والمسافة يجب أن تكونا محسوبتين لا ثابتتين
        $this->assertNotEquals(48, $done->json('summary.duration'), 'duration is still the hardcoded 48');
        $this->assertNotEquals(19.3, $done->json('summary.distance'), 'distance is still the hardcoded 19.3');

        // اسم المسار ووقت الانطلاق يجب أن يتطابقا بين today و show
        $this->assertEquals($tripsList[0]['route_name'], $show->json('data.route_name'), 'route_name differs between endpoints');
        $this->assertEquals(
            $tripsList[0]['recommended_departure'],
            $show->json('data.recommended_departure'),
            'recommended_departure differs between endpoints'
        );

        // اسم الوجهة لم يعد null
        $this->assertNotNull($track->json('data.destination.name'), 'tracking destination name is still null');

        $ph = $this->asParent()->getJson('/api/parent/trips/history');
        $this->out('A18 PARENT HISTORY(' . $ph->status() . '): ' . substr(json_encode($ph->json('data'), JSON_UNESCAPED_UNICODE), 0, 1000));
        $dh = $this->asDriver()->getJson('/api/driver/trips/history');
        $this->out('A19 DRIVER HISTORY(' . $dh->status() . '): ' . substr(json_encode($dh->json('data'), JSON_UNESCAPED_UNICODE), 0, 700));
        $dhd = $this->asDriver()->getJson("/api/driver/trips/history/{$tripId}");
        $this->out('A20 DRIVER HISTORY DETAILS(' . $dhd->status() . '): ' . substr(json_encode($dhd->json('data'), JSON_UNESCAPED_UNICODE), 0, 800));
        $tl = $this->asParent()->getJson("/api/parent/trips/{$tripId}/timeline");
        $this->out('A21 TIMELINE(' . $tl->status() . '): ' . substr(json_encode($tl->json('data'), JSON_UNESCAPED_UNICODE), 0, 900));

        // سعر الرحلة الواحدة يجب أن يتطابق بين "القادمة" و"السجل"
        $upcomingCost = $upcoming->json('data.0.pricing.cost_per_child');
        $historyCost  = $ph->json('data.data.0.pricing.cost_per_child');
        $this->out('A23 PRICE upcoming=' . $upcomingCost . ' history=' . $historyCost);
        $this->assertEquals($historyCost, $upcomingCost, 'per-trip price differs between upcoming and history');

        $this->out('A22 LEDGER: ' . json_encode(
            \Illuminate\Support\Facades\DB::table('financial_ledger')->orderByDesc('id')->limit(6)
                ->get(['type', 'source_account', 'destination_account', 'amount', 'reference_number'])->toArray(),
            JSON_UNESCAPED_UNICODE
        ));
    }
}
