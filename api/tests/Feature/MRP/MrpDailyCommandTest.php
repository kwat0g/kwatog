<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\MRP\Services\MrpAutomationService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MrpDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_run_with_some_successful_plans_is_a_successful_command_with_warning_data(): void
    {
        $run = $this->makeRun(MrpRunStatus::Partial, 2, 1, 1);
        $automation = Mockery::mock(MrpAutomationService::class);
        $automation->shouldReceive('run')
            ->once()
            ->with(null, MrpRunTrigger::Scheduled, null, 'daily_fallback')
            ->andReturn($run);
        $this->app->instance(MrpAutomationService::class, $automation);

        $this->artisan('mrp:run-daily')->assertExitCode(Command::SUCCESS);
    }

    public function test_partial_run_with_no_successful_plan_is_a_command_failure(): void
    {
        $run = $this->makeRun(MrpRunStatus::Partial, 1, 0, 1);
        $automation = Mockery::mock(MrpAutomationService::class);
        $automation->shouldReceive('run')
            ->once()
            ->with(null, MrpRunTrigger::Scheduled, null, 'daily_fallback')
            ->andReturn($run);
        $this->app->instance(MrpAutomationService::class, $automation);

        $this->artisan('mrp:run-daily')->assertExitCode(Command::FAILURE);
    }

    private function makeRun(MrpRunStatus $status, int $evaluated, int $planned, int $failed): MrpRun
    {
        $run = new MrpRun;
        $run->forceFill([
            'id' => 1,
            'status' => $status->value,
            'triggered_by' => MrpRunTrigger::Scheduled->value,
            'sales_orders_evaluated' => $evaluated,
            'plans_generated' => $planned,
            'failed_sales_orders' => $failed,
            'shortages_found' => 0,
            'prs_created' => 0,
            'prs_updated' => 0,
            'summary' => [],
        ]);

        return $run;
    }
}
