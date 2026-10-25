<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\DssDemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DssDemoScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_scenario_uses_real_detector_and_is_repeatable(): void
    {
        $seeder = new DssDemoScenarioSeeder;
        $seeder->run();

        $workOrder = WorkOrder::query()->where('wo_number', 'WO-DSS-DEMO-001')->firstOrFail();
        $recommendation = DssRecommendation::query()
            ->where('dedupe_key', 'work-order:'.$workOrder->id)
            ->firstOrFail();

        $this->assertSame('DSS Demo Finished Good', $workOrder->product()->value('name'));
        $this->assertSame('60', $recommendation->rationale['remaining_quantity']);
        $this->assertSame('pcs', $recommendation->rationale['unit_of_measure']);
        $this->assertSame('sales_order', $recommendation->source_references[1]['source_kind']);

        $seeder->run();

        $this->assertSame(1, WorkOrder::query()->where('wo_number', 'WO-DSS-DEMO-001')->count());
        $this->assertSame(1, DssRecommendation::query()->where('dedupe_key', 'work-order:'.$workOrder->id)->count());
    }
}
