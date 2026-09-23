<?php

namespace App\Services\Trip;

use App\Models\Shared\TripStop;
use App\Support\GeoEstimator;
use Exception;

class GeofenceViolationException extends Exception
{
    protected string $errorCode;

    public function __construct(string $message, string $errorCode = 'OUT_OF_RANGE', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

/**
 * يفرض قيود الـ GPS على تأكيد الصعود/النزول اليدوي (زر السائق):
 * ≤100م للمنازل، ≤200م للمدارس — لمنع التأكيد الوهمي عن بُعد.
 */
class GeofenceService
{
    public const HOME_RADIUS_METERS   = 100;
    public const SCHOOL_RADIUS_METERS = 200;

    public function isWithinRadius(float $lat1, float $lng1, float $lat2, float $lng2, float $maxMeters): bool
    {
        $distanceMeters = GeoEstimator::haversineKm($lat1, $lng1, $lat2, $lng2) * 1000;
        return $distanceMeters <= $maxMeters;
    }

    /**
     * @throws GeofenceViolationException إذا كان موقع السائق خارج النطاق المسموح لهذه المحطة
     */
    public function assertWithinRadius(float $driverLat, float $driverLng, TripStop $stop): void
    {
        $this->assertWithinCoordinates(
            $driverLat,
            $driverLng,
            (float) $stop->lat,
            (float) $stop->lng,
            $stop->stop_type ?? TripStop::TYPE_HOME
        );
    }

    /**
     * @throws GeofenceViolationException إذا كان موقع السائق خارج النطاق المسموح للإحداثيات المحددة
     */
    public function assertWithinCoordinates(float $driverLat, float $driverLng, float $targetLat, float $targetLng, string $stopType = TripStop::TYPE_HOME): void
    {
        $maxMeters = $stopType === TripStop::TYPE_SCHOOL
            ? self::SCHOOL_RADIUS_METERS
            : self::HOME_RADIUS_METERS;

        $distanceMeters = GeoEstimator::haversineKm($driverLat, $driverLng, $targetLat, $targetLng) * 1000;

        if ($distanceMeters > $maxMeters) {
            throw new GeofenceViolationException(
                sprintf(
                    'أنت بعيد عن موقع المحطة (%.0f م)، الحد المسموح %d م. يرجى الاقتراب من الموقع.',
                    $distanceMeters,
                    $maxMeters
                ),
                'OUT_OF_RANGE',
                422
            );
        }
    }
}
