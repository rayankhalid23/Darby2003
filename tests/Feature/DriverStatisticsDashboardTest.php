<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Parent\Child;
use App\Models\Parent\ParentModel;
use App\Services\Driver\DriverStatisticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class DriverStatisticsDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_driver_statistics_resolves_all_children_from_trips_and_subscriptions(): void
    {
        $service = new DriverStatisticsService();

        // 1. Get driver 909 or any test driver
        $driver = Driver::find(909);
        if (!$driver) {
            $this->markTestSkipped('Driver 909 not found');
        }

        $stats = $service->getSubscriptionAndPassengerStats($driver);

        // Subscribed children (without emergency) should be 2 for driver 909
        $this->assertEquals(2, $stats['active_students_count']);
        // Emergency rescue students should be 5
        $this->assertEquals(5, $stats['emergency_rescue_students_count']);
        // Total passengers on board should be 7
        $this->assertEquals(7, $stats['total_current_passengers_count']);

        // Check subscriptions counts are present
        $this->assertArrayHasKey('subscriptions', $stats);
        $this->assertArrayHasKey('pending_requests_count', $stats['subscriptions']);
        $this->assertArrayHasKey('active_subscriptions_count', $stats['subscriptions']);
        $this->assertArrayHasKey('completed_subscriptions_count', $stats['subscriptions']);
        $this->assertArrayHasKey('cancelled_subscriptions_count', $stats['subscriptions']);
        $this->assertArrayHasKey('total_subscriptions_count', $stats['subscriptions']);

        // Check backward compatible history counts
        $this->assertArrayHasKey('history', $stats);
        $this->assertArrayHasKey('pending_requests_count', $stats['history']);
        $this->assertArrayHasKey('active_subscriptions_count', $stats['history']);
        $this->assertArrayHasKey('completed_subscriptions_count', $stats['history']);
        $this->assertArrayHasKey('cancelled_subscriptions_count', $stats['history']);

        // Check trip operations stats and today_trips_overview
        $tripStats = $service->getTripOperationsStats($driver);
        $this->assertArrayHasKey('today_trips_overview', $tripStats);
        $this->assertArrayHasKey('total_scheduled', $tripStats['today_trips_overview']);
        $this->assertArrayHasKey('completed', $tripStats['today_trips_overview']);
        $this->assertArrayHasKey('in_progress', $tripStats['today_trips_overview']);
        $this->assertArrayHasKey('pending', $tripStats['today_trips_overview']);
        $this->assertArrayHasKey('absences_and_breakdowns', $tripStats);
        $this->assertArrayHasKey('absence_days_count', $tripStats['absences_and_breakdowns']);
        $this->assertArrayHasKey('vehicle_breakdowns_count', $tripStats['absences_and_breakdowns']);
        // Check new summary cards and custom metrics
        $this->assertArrayHasKey('summary_cards', $tripStats);
        $this->assertArrayHasKey('completed_trips_count', $tripStats['summary_cards']);
        $this->assertArrayHasKey('absent_trips_count', $tripStats['summary_cards']);
        $this->assertArrayHasKey('vehicle_breakdowns_count', $tripStats['summary_cards']);
        $this->assertArrayHasKey('substitute_trips_count', $tripStats['summary_cards']);

        // Check substitute_trips block
        $this->assertArrayHasKey('substitute_trips', $tripStats);
        $this->assertArrayHasKey('as_substitute_trips_count', $tripStats['substitute_trips']);
        $this->assertArrayHasKey('completed_substitute_trips_count', $tripStats['substitute_trips']);
        $this->assertArrayHasKey('all_time_substitute_trips_count', $tripStats['substitute_trips']);
    }

    public function test_driver_statistics_filters_by_month_and_year_correctly(): void
    {
        $service = new DriverStatisticsService();
        $driver = Driver::find(908);
        if (!$driver) {
            $this->markTestSkipped('Driver 908 not found');
        }

        // September 2026 for Driver 908
        $sepStats = $service->getDashboardStatistics($driver, 9, 2026);
        $this->assertEquals(9, $sepStats['period']['month']);
        $this->assertEquals(2026, $sepStats['period']['year']);

        $sepTripStats = $sepStats['trip_operations_stats'];
        $this->assertEquals(23, $sepTripStats['summary_cards']['completed_trips_count']);
        $this->assertEquals(2, $sepTripStats['summary_cards']['absent_trips_count']);
        $this->assertEquals(0, $sepTripStats['summary_cards']['vehicle_breakdowns_count']);
        $this->assertEquals(0, $sepTripStats['summary_cards']['substitute_trips_count']);

        // October 2026 for Driver 908
        $octStats = $service->getDashboardStatistics($driver, 10, 2026);
        $this->assertEquals(10, $octStats['period']['month']);
        $this->assertEquals(2026, $octStats['period']['year']);

        $octTripStats = $octStats['trip_operations_stats'];
        $this->assertEquals(0, $octTripStats['summary_cards']['completed_trips_count']);
        $this->assertEquals(0, $octTripStats['summary_cards']['absent_trips_count']);
        $this->assertEquals(7, $octTripStats['summary_cards']['vehicle_breakdowns_count']);
        $this->assertEquals(0, $octTripStats['summary_cards']['substitute_trips_count']);

        // Driver 909 who took trips as a substitute driver
        $driver909 = Driver::find(909);
        if ($driver909) {
            $octStats909 = $service->getDashboardStatistics($driver909, 10, 2026);
            $this->assertEquals(5, $octStats909['trip_operations_stats']['summary_cards']['substitute_trips_count']);
            $this->assertEquals(5, $octStats909['trip_operations_stats']['substitute_trips']['as_substitute_trips_count']);
        }
    }

    public function test_driver_statistics_api_endpoint(): void
    {
        $driver = Driver::whereNotNull('user_id')->first();
        if (!$driver || !$driver->user) {
            $this->markTestSkipped('Driver with user not found');
        }

        $response = $this->actingAs($driver->user, 'sanctum')
            ->getJson('/api/driver/statistics?month=9&year=2026');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'status',
                'success',
                'message',
                'data' => [
                    'period' => ['month', 'year'],
                    'financial_stats',
                    'subscription_and_passenger_stats',
                    'trip_operations_stats' => [
                        'period' => ['month', 'year'],
                        'summary_cards' => [
                            'completed_trips_count',
                            'absent_trips_count',
                            'vehicle_breakdowns_count',
                            'substitute_trips_count',
                        ],
                        'completed_trips',
                        'punctuality_rate',
                        'absences_and_breakdowns',
                        'substitute_trips',
                    ],
                    'quick_widgets',
                ],
            ]);
    }

    public function test_driver_cumulative_and_wallet_balance_consistency(): void
    {
        $service = new DriverStatisticsService();

        // Test Driver 909 (driver14)
        $driver909 = Driver::find(909);
        if ($driver909) {
            $stats = $service->getDashboardStatistics($driver909, 10, 2026);
            $fin = $stats['financial_stats'];
            // Wallet balance and cumulative earnings must both be positive and consistent
            $this->assertGreaterThan(0, $fin['pending_and_due']['wallet_balance']);
            $this->assertGreaterThan(0, $fin['net_earnings']['total']);
            $this->assertEquals($fin['pending_and_due']['wallet_balance'], $fin['net_earnings']['total']);
        }

        // Test Driver 908 (driver13)
        $driver908 = Driver::find(908);
        if ($driver908) {
            $stats908 = $service->getDashboardStatistics($driver908, 10, 2026);
            $fin908 = $stats908['financial_stats'];
            $this->assertGreaterThan(0, $fin908['pending_and_due']['wallet_balance']);
            $this->assertGreaterThan(0, $fin908['net_earnings']['total']);
            $this->assertEquals($fin908['pending_and_due']['wallet_balance'], $fin908['net_earnings']['total']);
            $this->assertGreaterThan(0, $fin908['net_earnings']['current_month']);
        }
    }
}
