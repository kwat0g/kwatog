<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Services\OeeService;
use App\Modules\Production\Services\ProductionDashboardService;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderDefect;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ProductionDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dashboard_payload_calculates_all_machine_oee_once(): void
    {
        Cache::forget('dashboard:production');
        app(SettingsService::class)->set('production.dashboard.defect_history_days', 7);
        app(SettingsService::class)->set('production.oee.world_class_ratio', 0.85);
        app(SettingsService::class)->set('production.oee.on_track_ratio', 0.70);
        app(SettingsService::class)->set('production.dashboard.mold_warning_ratio', 0.80);

        $oee = Mockery::mock(OeeService::class);
        $oee->shouldReceive('calculateForAllMachines')->once()->andReturn(new Collection([
            ['machine_id' => 'machine-1', 'oee' => 0.75],
        ]));
        $this->app->instance(OeeService::class, $oee);

        $payload = app(ProductionDashboardService::class)->payload();

        $this->assertSame(0.75, $payload['kpis']['avg_oee_today']);
        $this->assertCount(1, $payload['machine_utilization']);
    }

    public function test_defect_history_uses_parent_output_recorded_at(): void
    {
        ProductionDashboardService::forgetCache();
        app(SettingsService::class)->set('production.dashboard.defect_history_days', 7);
        app(SettingsService::class)->set('production.oee.world_class_ratio', 0.85);
        app(SettingsService::class)->set('production.oee.on_track_ratio', 0.70);
        app(SettingsService::class)->set('production.dashboard.mold_warning_ratio', 0.80);

        $actor = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['created_by' => $actor->id]);
        $type = DefectType::query()->create(['code' => 'SCR', 'name' => 'Surface scratch']);
        foreach ([['recorded_at' => now()->subDay(), 'count' => 2], ['recorded_at' => now()->subDays(9), 'count' => 5]] as $row) {
            $output = WorkOrderOutput::query()->create([
                'work_order_id' => $workOrder->id,
                'recorded_by' => $actor->id,
                'recorded_at' => $row['recorded_at'],
                'good_count' => 5,
                'reject_count' => $row['count'],
            ]);
            WorkOrderDefect::query()->create([
                'output_id' => $output->id,
                'defect_type_id' => $type->id,
                'count' => $row['count'],
            ]);
        }

        $oee = Mockery::mock(OeeService::class);
        $oee->shouldReceive('calculateForAllMachines')->once()->andReturn(new Collection);
        $this->app->instance(OeeService::class, $oee);

        $payload = app(ProductionDashboardService::class)->payload();

        $this->assertSame([[
            'defect_code' => 'SCR',
            'defect_name' => 'Surface scratch',
            'count' => 2,
            'percent' => 100.0,
        ]], $payload['defect_pareto']);
    }

    public function test_today_output_kpis_are_aggregated_from_the_database(): void
    {
        ProductionDashboardService::forgetCache();
        app(SettingsService::class)->set('production.dashboard.defect_history_days', 7);
        app(SettingsService::class)->set('production.oee.world_class_ratio', 0.85);
        app(SettingsService::class)->set('production.oee.on_track_ratio', 0.70);
        app(SettingsService::class)->set('production.dashboard.mold_warning_ratio', 0.80);

        $actor = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['created_by' => $actor->id]);
        WorkOrderOutput::query()->create([
            'work_order_id' => $workOrder->id,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
            'good_count' => 8,
            'reject_count' => 2,
        ]);
        $oee = Mockery::mock(OeeService::class);
        $oee->shouldReceive('calculateForAllMachines')->once()->andReturn(new Collection);
        $this->app->instance(OeeService::class, $oee);

        $payload = app(ProductionDashboardService::class)->payload();

        $this->assertSame(10, $payload['kpis']['today_output_total']);
        $this->assertSame(8, $payload['kpis']['today_output_good']);
        $this->assertSame(2, $payload['kpis']['today_output_reject']);
    }

    public function test_chain_stage_breakdown_groups_active_orders_in_sql(): void
    {
        SalesOrder::factory()->count(2)->create(['status' => 'draft']);
        SalesOrder::factory()->create(['status' => 'confirmed']);
        SalesOrder::factory()->create(['status' => 'in_production']);
        SalesOrder::factory()->create(['status' => 'delivered']);

        $stages = collect(app(ProductionDashboardService::class)->chainStageBreakdown())->keyBy('label');

        $this->assertSame(3, $stages['Order Entered']['count']);
        $this->assertSame(1, $stages['In Production']['count']);
        $this->assertSame(4, $stages->sum('count'));
    }
}
