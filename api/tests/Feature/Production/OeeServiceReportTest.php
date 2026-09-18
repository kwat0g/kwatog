<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Services\OeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OeeServiceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_oee_excludes_machine_states_not_available_for_production(): void
    {
        $active = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        Machine::factory()->create(['status' => MachineStatus::Maintenance->value]);
        Machine::factory()->create(['status' => MachineStatus::Breakdown->value]);

        $rows = app(OeeService::class)->calculateForAllMachines(
            Carbon::parse('2026-09-18 00:00:00'),
            Carbon::parse('2026-09-18 23:59:59'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame($active->hash_id, $rows->first()['machine_id']);
    }

    public function test_long_oee_report_returns_weekly_trend_buckets_instead_of_empty_trend(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $from = Carbon::parse('2026-01-01 00:00:00');
        $to = Carbon::parse('2026-04-30 23:59:59');

        $report = app(OeeService::class)->report($from, $to);

        $this->assertNotEmpty($report['trend']);
        $this->assertLessThanOrEqual(19, count($report['trend']));
        $this->assertSame($from->toDateString(), $report['trend'][0]['date']);
        $this->assertContains($machine->hash_id, $report['machines']->pluck('machine_id')->all());
    }
}
