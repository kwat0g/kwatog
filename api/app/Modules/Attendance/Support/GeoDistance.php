<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

final class GeoDistance
{
    private const EARTH_RADIUS_M = 6371008.8;

    /** Great-circle (haversine) distance in metres. */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
