<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiComputeProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_monthly_kpi_failure_is_reported_as_a_failed_process(): void
    {
        KpiDefinition::query()->create([
            'code' => 'valid_no_data',
            'name' => 'Valid KPI with no observations',
            'module' => 'production',
            'unit' => 'count',
            'direction' => 'higher_is_better',
            'target_value' => 1,
            'calculation_method' => 'computeOee',
            'is_active' => true,
            'display_order' => 1,
        ]);
        KpiDefinition::query()->create([
            'code' => 'broken_calculator',
            'name' => 'Broken calculator',
            'module' => 'production',
            'unit' => 'count',
            'direction' => 'higher_is_better',
            'target_value' => 1,
            'calculation_method' => 'missingCalculator',
            'is_active' => true,
            'display_order' => 2,
        ]);

        $result = app(KpiSnapshotService::class)->computeAll(2026, 7);

        $this->assertSame(1, $result['no_data']);
        $this->assertSame(0, $result['computed']);
        $this->assertSame(['broken_calculator'], array_column($result['failed'], 'code'));
    }
}
