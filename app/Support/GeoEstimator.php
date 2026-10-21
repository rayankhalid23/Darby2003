<?php

namespace App\Support;

/**
 * حسابات جغرافية بسيطة (Haversine) لا تعتمد على أي خدمة خارجية.
 * تُستخدم كأداة حساب أساسية دائمة التوفر لترتيب المحطات وتقدير الأزمنة،
 * بينما يبقى OSRM إثراءً اختيارياً غير إلزامي (قد لا يكون متاحاً في بيئة التطوير/الاختبار).
 */
class GeoEstimator
{
    public const DEFAULT_AVG_SPEED_KMH = 25.0;

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        if (($lat1 == 0.0 && $lng1 == 0.0) || ($lat2 == 0.0 && $lng2 == 0.0)) {
            return 0.0;
        }

        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function estimateMinutes(float $km, float $avgSpeedKmh = self::DEFAULT_AVG_SPEED_KMH): int
    {
        if ($km <= 0 || $avgSpeedKmh <= 0) {
            return 0;
        }

        return (int) ceil(($km / $avgSpeedKmh) * 60);
    }

    /**
     * يرتب مجموعة نقاط بخوارزمية أقرب جار (Nearest Neighbor) بدءاً من نقطة انطلاق محددة.
     * كل عنصر في $points يجب أن يحتوي مفتاحي 'lat' و 'lng'، ويُعاد بنفس الترتيب الجديد.
     */
    public static function orderByNearestNeighbor(array $points, float $startLat, float $startLng): array
    {
        $remaining = $points;
        $ordered   = [];
        $curLat    = $startLat;
        $curLng    = $startLng;

        while (!empty($remaining)) {
            $bestIndex = null;
            $bestDist  = null;

            foreach ($remaining as $index => $point) {
                $dist = self::haversineKm($curLat, $curLng, (float) $point['lat'], (float) $point['lng']);
                if ($bestDist === null || $dist < $bestDist) {
                    $bestDist  = $dist;
                    $bestIndex = $index;
                }
            }

            $chosen = $remaining[$bestIndex];
            $ordered[] = $chosen;
            $curLat = (float) $chosen['lat'];
            $curLng = (float) $chosen['lng'];
            unset($remaining[$bestIndex]);
        }

        return $ordered;
    }

    /**
     * يرتب مجموعة محطات "منازل" و"مدارس" حسب اتجاه الوردية ونقطة انطلاق السائق:
     * - ذهاب (go): إذا توفر موقع السائق، يبدأ من أقرب منزل للسائق، ثم المنازل بأقرب جار، ثم المدارس.
     *   إذا لم يتوفر موقع السائق، يبدأ من المنزل الأبعد عن المدرسة.
     * - إياب (return): مدرسة/مدارس أولاً، ثم منازل بأقرب جار.
     * كل نقطة يجب أن تحتوي 'lat' و'lng' (وأي مفاتيح إضافية مثل 'id' تُحفظ كما هي).
     */
    public static function orderStopsForDirection(array $homePoints, array $schoolPoints, bool $isGoDirection, ?array $driverStartPoint = null): array
    {
        if (empty($homePoints) && empty($schoolPoints)) {
            return [];
        }

        if ($isGoDirection) {
            // إذا توفر موقع انطلاق السائق (المنزل عند بناء المسار، أو الموقع الحي عند بدء الرحلة)
            if ($driverStartPoint && isset($driverStartPoint['lat'], $driverStartPoint['lng'])) {
                $orderedHomes = self::orderByNearestNeighbor($homePoints, (float) $driverStartPoint['lat'], (float) $driverStartPoint['lng']);
            } else {
                $orderedHomes = self::orderHomesFarthestFirst($homePoints, $schoolPoints[0] ?? null);
            }

            $lastPoint = !empty($orderedHomes) ? end($orderedHomes) : ($driverStartPoint ?? null);
            $orderedSchools = $lastPoint
                ? self::orderByNearestNeighbor($schoolPoints, (float) $lastPoint['lat'], (float) $lastPoint['lng'])
                : $schoolPoints;

            return array_merge($orderedHomes, $orderedSchools);
        }

        // رحلة الإياب:
        if ($driverStartPoint && isset($driverStartPoint['lat'], $driverStartPoint['lng']) && !empty($schoolPoints)) {
            $orderedSchools = self::orderByNearestNeighbor($schoolPoints, (float) $driverStartPoint['lat'], (float) $driverStartPoint['lng']);
        } else {
            $orderedSchools = $schoolPoints;
        }

        $lastSchool = !empty($orderedSchools) ? end($orderedSchools) : ($driverStartPoint ?? ($homePoints[0] ?? ['lat' => 0, 'lng' => 0]));
        $orderedHomes = self::orderByNearestNeighbor($homePoints, (float) $lastSchool['lat'], (float) $lastSchool['lng']);

        return array_merge($orderedSchools, $orderedHomes);
    }

    /**
     * يبدأ من المنزل الأبعد عن المدرسة (بحيث تقترب الحافلة تدريجياً من الوجهة)، ثم يكمل بأقرب جار.
     */
    private static function orderHomesFarthestFirst(array $homePoints, ?array $anchorSchool): array
    {
        if (empty($homePoints)) {
            return [];
        }

        if ($anchorSchool) {
            usort($homePoints, function ($a, $b) use ($anchorSchool) {
                $distA = self::haversineKm($a['lat'], $a['lng'], $anchorSchool['lat'], $anchorSchool['lng']);
                $distB = self::haversineKm($b['lat'], $b['lng'], $anchorSchool['lat'], $anchorSchool['lng']);
                return $distB <=> $distA;
            });
        }

        $start = array_shift($homePoints);
        $rest  = self::orderByNearestNeighbor($homePoints, $start['lat'], $start['lng']);

        return array_merge([$start], $rest);
    }

    /**
     * يحسب إجمالي مسافة السير (كم) على طول سلسلة نقاط مرتبة.
     */
    public static function totalPathDistanceKm(array $orderedPoints): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($orderedPoints); $i++) {
            $total += self::haversineKm(
                (float) $orderedPoints[$i - 1]['lat'],
                (float) $orderedPoints[$i - 1]['lng'],
                (float) $orderedPoints[$i]['lat'],
                (float) $orderedPoints[$i]['lng']
            );
        }

        return $total;
    }
}
