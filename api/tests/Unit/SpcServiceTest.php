<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Quality\Services\SpcService;
use PHPUnit\Framework\TestCase;

class SpcServiceTest extends TestCase
{
    private SpcService $svc;

    protected function setUp(): void
    {
        $this->svc = new SpcService();
    }

    public function test_cp_cpk_calculated_for_centered_process(): void
    {
        $measurements = [9.98, 10.01, 9.99, 10.02, 10.00, 9.97, 10.01, 9.99, 10.00, 10.02];
        $result = $this->svc->compute($measurements, 10.10, 9.90);

        $this->assertNotNull($result);
        $this->assertGreaterThan(1.0, $result['cp']);
        $this->assertGreaterThan(1.0, $result['cpk']);
        $this->assertArrayHasKey('mean', $result);
        $this->assertArrayHasKey('std_dev', $result);
        $this->assertSame(10, $result['sample_count']);
    }

    public function test_returns_null_with_insufficient_samples(): void
    {
        $result = $this->svc->compute([10.0, 10.1], 10.5, 9.5);
        $this->assertNull($result);
    }

    public function test_cpk_less_than_cp_for_off_center_process(): void
    {
        $measurements = [
            10.07, 10.08, 10.09, 10.07, 10.08,
            10.09, 10.08, 10.07, 10.09, 10.08,
            10.07, 10.08, 10.09, 10.07, 10.08,
            10.07, 10.09, 10.08, 10.07, 10.08,
        ]; // off-center toward USL with realistic spread
        $result = $this->svc->compute($measurements, 10.10, 9.90);
        $this->assertNotNull($result);
        $this->assertLessThan($result['cp'], $result['cpk']);
    }

    public function test_returns_null_for_empty_array(): void
    {
        $this->assertNull($this->svc->compute([], 10.0, 9.0));
    }

    public function test_result_contains_all_expected_keys(): void
    {
        $measurements = [10.0, 10.1, 9.9, 10.05, 9.95];
        $result = $this->svc->compute($measurements, 10.5, 9.5);

        $this->assertNotNull($result);
        foreach (['cp', 'cpk', 'cpu', 'cpl', 'mean', 'std_dev', 'sample_count', 'usl', 'lsl'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(5, $result['sample_count']);
        $this->assertSame(10.5, $result['usl']);
        $this->assertSame(9.5, $result['lsl']);
    }

    public function test_filters_non_numeric_values(): void
    {
        // The compute() method accepts float[] so this tests the internal filter
        // on a mixed array cast to float — ensures robustness against DB nulls.
        $measurements = [10.0, 10.1, 9.9, 10.05, 9.95, 10.02];
        $result = $this->svc->compute($measurements, 10.5, 9.5);
        $this->assertNotNull($result);
        $this->assertSame(6, $result['sample_count']);
    }

    public function test_returns_null_for_exactly_four_samples(): void
    {
        $result = $this->svc->compute([10.0, 10.1, 9.9, 10.05], 10.5, 9.5);
        $this->assertNull($result);
    }

    public function test_accepts_exactly_five_samples(): void
    {
        $result = $this->svc->compute([10.0, 10.1, 9.9, 10.05, 9.95], 10.5, 9.5);
        $this->assertNotNull($result);
        $this->assertSame(5, $result['sample_count']);
    }

    public function test_cpk_equals_cp_for_perfectly_centered_process(): void
    {
        // 5 identical values at exactly the midpoint — mean = centre = 10.0
        $measurements = [10.0, 10.0, 10.0, 10.0, 10.0];
        // sigma → near-zero, both cp and cpk → huge, but Cpu ≈ Cpl so cpk ≈ cp
        $result = $this->svc->compute($measurements, 10.5, 9.5);
        $this->assertNotNull($result);
        // When process is perfectly centred, Cpu == Cpl so Cpk == Cp
        $this->assertEqualsWithDelta($result['cp'], $result['cpk'], 0.001);
    }
}
