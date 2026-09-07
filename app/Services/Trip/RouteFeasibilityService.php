<?php

namespace App\Services\Trip;

use App\Models\Driver\DriverSeatSlot;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\SubscriptionRequest;
use App\Support\GeoEstimator;

/**
 * فحص إمكانية إضافة طفل/أطفال طلب اشتراك جديد إلى مسار السائق الحالي (Master Route)
 * دون حفظ أي بيانات في قاعدة البيانات — يُستخدم كمعاينة "ماذا لو" قبل قبول الطلب.
 */
class RouteFeasibilityService
{
    public const DEFAULT_MAX_TRIP_DURATION_MINUTES = 60;

    public function checkForRequest(SubscriptionRequest $req, int $maxDurationMinutes = self::DEFAULT_MAX_TRIP_DURATION_MINUTES): array
    {
        $req->loadMissing(['children.pickupAddress', 'children.school', 'driver.seatSlots']);
        $driver = $req->driver;

        $firstPivot = $req->children->first()?->pivot;
        $timing = $firstPivot?->timing ?? 'MORNING';
        $direction = $req->trip_direction ?? 'both';

        $slots = DriverSeatSlot::resolveSlots($timing, $direction);
        $childrenCount = $req->children->count() ?: max(1, (int) ($req->children_count ?? 1));

        $slotResults = [];
        $overallFeasible = !empty($slots);

        foreach ($slots as $slot) {
            $result = $this->checkSlot($driver, $slot, $req, $childrenCount, $maxDurationMinutes);
            $slotResults[] = $result;

            if (!$result['feasible']) {
                $overallFeasible = false;
            }
        }

        return [
            'overall_feasible' => $overallFeasible,
            'children_count'   => $childrenCount,
            'max_duration_minutes' => $maxDurationMinutes,
            'slots'            => $slotResults,
        ];
    }

    private function checkSlot($driver, string $slot, SubscriptionRequest $req, int $childrenCount, int $maxDurationMinutes): array
    {
        $seatSlot = $driver->seatSlots->firstWhere('slot', $slot);
        $availableSeats = $seatSlot ? $seatSlot->available_seats : 0;

        $route = Route::where('driver_id', $driver->id)
            ->where('shift_slot', $slot)
            ->where('status', 'Active')
            ->first();

        $currentStops = $route
            ? RouteStop::where('route_id', $route->id)->get()
            : collect();

        $existingHome = $currentStops->where('stop_type', RouteStop::TYPE_HOME)
            ->map(fn($s) => ['lat' => (float) $s->lat, 'lng' => (float) $s->lng])
            ->values()->all();

        $existingSchool = $currentStops->where('stop_type', RouteStop::TYPE_SCHOOL)
            ->map(fn($s) => ['lat' => (float) $s->lat, 'lng' => (float) $s->lng])
            ->values()->all();

        $isGo = DriverSeatSlot::isGoSlot($slot);

        $currentOrdered = GeoEstimator::orderStopsForDirection($existingHome, $existingSchool, $isGo);
        $currentDistanceKm = GeoEstimator::totalPathDistanceKm($currentOrdered);
        $currentTotalDuration = $route->estimated_duration ?? GeoEstimator::estimateMinutes($currentDistanceKm);

        [$newHomePoints, $newSchoolPoints] = $this->extractRequestPoints($req);

        $allHome = array_merge($existingHome, $newHomePoints);
        $allSchool = $this->dedupePoints(array_merge($existingSchool, $newSchoolPoints));

        $newOrdered = GeoEstimator::orderStopsForDirection($allHome, $allSchool, $isGo);
        $newDistanceKm = GeoEstimator::totalPathDistanceKm($newOrdered);
        $newTotalDuration = GeoEstimator::estimateMinutes($newDistanceKm);

        $seatsOk = $availableSeats >= $childrenCount;
        $durationOk = $newTotalDuration <= $maxDurationMinutes;

        $reason = null;
        if (!$seatsOk) {
            $reason = 'insufficient_seats';
        } elseif (!$durationOk) {
            $reason = 'exceeds_max_trip_duration';
        }

        return [
            'shift_slot'            => $slot,
            'feasible'              => $seatsOk && $durationOk,
            'available_seats'       => $availableSeats,
            'current_total_duration' => $currentTotalDuration,
            'new_total_duration'    => $newTotalDuration,
            'added_time_minutes'    => max(0, $newTotalDuration - $currentTotalDuration),
            'reason'                => $reason,
        ];
    }

    private function extractRequestPoints(SubscriptionRequest $req): array
    {
        $homePoints = [];
        $schoolPoints = [];

        foreach ($req->children as $child) {
            $homeLat = (float) ($req->home_lat ?? $child->address?->lat ?? $child->homeAddress?->lat ?? $child->pickupAddress?->lat ?? 0);
            $homeLng = (float) ($req->home_lng ?? $child->address?->lng ?? $child->homeAddress?->lng ?? $child->pickupAddress?->lng ?? 0);
            $schoolLat = (float) ($child->pivot->school_lat ?? $child->school?->lat ?? $child->school?->latitude ?? 0);
            $schoolLng = (float) ($child->pivot->school_lng ?? $child->school?->lng ?? $child->school?->longitude ?? 0);

            if ($homeLat && $homeLng) {
                $homePoints[] = ['lat' => $homeLat, 'lng' => $homeLng];
            }
            if ($schoolLat && $schoolLng) {
                $schoolPoints[] = ['lat' => $schoolLat, 'lng' => $schoolLng];
            }
        }

        return [$homePoints, $schoolPoints];
    }

    private function dedupePoints(array $points): array
    {
        $seen = [];
        $unique = [];

        foreach ($points as $point) {
            $key = round($point['lat'], 4) . ':' . round($point['lng'], 4);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $point;
            }
        }

        return $unique;
    }
}
