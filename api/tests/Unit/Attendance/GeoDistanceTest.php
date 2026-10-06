<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Modules\Attendance\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_same_point_is_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, GeoDistance::meters(14.2860, 120.9345, 14.2860, 120.9345), 0.001);
    }

    public function test_one_millidegree_of_latitude_is_about_111_metres(): void
    {
        $this->assertEqualsWithDelta(111.2, GeoDistance::meters(14.2860, 120.9345, 14.2870, 120.9345), 0.5);
    }

    public function test_is_symmetric(): void
    {
        $a = GeoDistance::meters(14.2860, 120.9345, 14.30, 120.95);
        $b = GeoDistance::meters(14.30, 120.95, 14.2860, 120.9345);
        $this->assertEqualsWithDelta($a, $b, 0.001);
    }
}
