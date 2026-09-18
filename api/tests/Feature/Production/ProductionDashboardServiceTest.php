<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Services\SettingsService;
use App\Modules\Production\Services\OeeService;
use App\Modules\Production\Services\ProductionDashboardService;
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
}
