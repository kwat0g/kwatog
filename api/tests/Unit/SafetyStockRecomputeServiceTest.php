<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\SettingsService;
use App\Modules\Inventory\Services\SafetyStockRecomputeService;
use Mockery;
use PHPUnit\Framework\TestCase;

class SafetyStockRecomputeServiceTest extends TestCase
{
    private SafetyStockRecomputeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = Mockery::mock(SettingsService::class);
        $this->svc = new SafetyStockRecomputeService($settings);
    }

    public function test_stddev_zero_for_flat_series(): void
    {
        $this->assertSame(0.0, $this->svc->stddev([5, 5, 5, 5, 5]));
    }

    public function test_stddev_known_value(): void
    {
        // Sample stddev (n-1) of [2,4,4,4,5,5,7,9]: mean=5, sumSq=32,
        // variance=32/7, σ=sqrt(32/7) ≈ 2.138.
        $this->assertEqualsWithDelta(2.138, $this->svc->stddev([2, 4, 4, 4, 5, 5, 7, 9]), 0.001);
    }

    public function test_stddev_single_element_returns_zero(): void
    {
        $this->assertSame(0.0, $this->svc->stddev([42]));
    }

    public function test_stddev_two_elements(): void
    {
        $this->assertEqualsWithDelta(7.071, $this->svc->stddev([10, 20]), 0.01);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
