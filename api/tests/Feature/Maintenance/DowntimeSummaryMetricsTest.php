<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Maintenance\Services\DowntimeAnalyticsService;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use Database\Seeders\MachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MTBF and availability are derived from the length of the reporting window.
 *
 * Carbon 3 made diffIn* signed, so `$to->diffInMinutes($from)` — later receiver,
 * earlier argument — yields a NEGATIVE window. That drove uptime to
 * max(0, negative) = 0, pinning MTBF at 0, and made the `$windowMinutes > 0`
 * guard false, pinning availability at null. Both are dashboard KPIs, so they
 * read as "no uptime, availability unknown" rather than failing.
 *
 * Fixed dates, not now(), so the arithmetic is exact and asserted exactly.
 */
class DowntimeSummaryMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mtbf_and_availability_are_computed_over_a_positive_window(): void
    {
        $this->seed(MachineSeeder::class);
        $machineId = (int) Machine::query()->value('id');

        // A ten-day window is 14,400 minutes.
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-11 00:00:00');

        MachineDowntime::create([
            'machine_id' => $machineId,
            'work_order_id' => null,
            'start_time' => '2026-08-05 09:00:00',
            'end_time' => '2026-08-05 11:00:00',
            'duration_minutes' => 120,
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Signed-diff regression fixture',
        ]);

        $summary = app(DowntimeAnalyticsService::class)->summary($machineId, $from, $to);

        $this->assertSame(120, $summary['total_downtime_minutes']);
        $this->assertSame(1, $summary['breakdown_count']);

        // uptime = 14,400 - 120 = 14,280 minutes = 238 hours over one breakdown.
        $this->assertSame(238.0, $summary['mtbf_hours'], 'MTBF must be derived from a positive window');

        // availability = 14,280 / 14,400 = 99.17%
        $this->assertNotNull($summary['availability_pct'], 'availability must be computed, not skipped by a negative-window guard');
        $this->assertSame(99.17, $summary['availability_pct']);
    }

    public function test_overlapping_downtime_is_unioned_and_incidents_count_only_when_they_start_in_window(): void
    {
        $this->seed(MachineSeeder::class);
        $machineId = (int) Machine::query()->value('id');
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-03 00:00:00');

        MachineDowntime::create([
            'machine_id' => $machineId,
            'start_time' => '2026-07-31 23:00:00',
            'end_time' => '2026-08-01 01:00:00',
            'duration_minutes' => 120,
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Started before the report window',
        ]);
        MachineDowntime::create([
            'machine_id' => $machineId,
            'start_time' => '2026-08-01 02:00:00',
            'end_time' => '2026-08-01 04:00:00',
            'duration_minutes' => 120,
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Breakdown interval',
        ]);
        MachineDowntime::create([
            'machine_id' => $machineId,
            'start_time' => '2026-08-01 03:00:00',
            'end_time' => '2026-08-01 05:00:00',
            'duration_minutes' => 120,
            'category' => MachineDowntimeCategory::PlannedMaintenance->value,
            'description' => 'Overlapping repair interval',
        ]);

        $summary = app(DowntimeAnalyticsService::class)->summary(null, $from, $to);

        $this->assertSame(240, $summary['total_downtime_minutes']);
        $this->assertSame(1, $summary['breakdown_count']);
        $this->assertSame(120.0, $summary['mttr_minutes']);
    }

    public function test_fleet_availability_uses_all_machine_minutes(): void
    {
        $first = Machine::factory()->create();
        Machine::factory()->create();
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-02 00:00:00');
        MachineDowntime::create([
            'machine_id' => $first->id,
            'start_time' => '2026-08-01 09:00:00',
            'end_time' => '2026-08-01 11:00:00',
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Two-hour outage on one of two machines',
        ]);

        $this->assertSame(95.83, app(DowntimeAnalyticsService::class)->summary(null, $from, $to)['availability_pct']);
    }

    public function test_mttr_uses_only_resolved_breakdowns_ending_in_window_and_their_full_duration(): void
    {
        $machine = Machine::factory()->create();
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-02 00:00:00');

        foreach ([
            ['2026-07-31 23:00:00', '2026-08-01 03:00:00', 'Resolved after window starts'],
            ['2026-08-01 09:00:00', '2026-08-01 11:00:00', 'Resolved inside window'],
            ['2026-08-01 22:00:00', null, 'Still open at window end'],
            ['2026-08-01 23:30:00', '2026-08-02 01:00:00', 'Closed after window end'],
        ] as [$start, $end, $description]) {
            MachineDowntime::create([
                'machine_id' => $machine->id,
                'start_time' => $start,
                'end_time' => $end,
                'category' => MachineDowntimeCategory::Breakdown->value,
                'description' => $description,
            ]);
        }

        $summary = app(DowntimeAnalyticsService::class)->summary((int) $machine->id, $from, $to);

        $this->assertSame(420, $summary['total_downtime_minutes']);
        $this->assertSame(3, $summary['breakdown_count']);
        $this->assertSame(2, $summary['resolved_breakdown_count']);
        $this->assertSame(2, $summary['open_breakdown_count']);
        $this->assertSame(180.0, $summary['mttr_minutes']);
        $this->assertSame(70.83, $summary['availability_pct']);
    }

    public function test_open_fault_affects_fleet_calendar_availability_but_not_mttr(): void
    {
        $first = Machine::factory()->create();
        Machine::factory()->create();
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-02 00:00:00');
        MachineDowntime::create([
            'machine_id' => $first->id,
            'start_time' => '2026-08-01 23:00:00',
            'end_time' => null,
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Open breakdown',
        ]);

        $summary = app(DowntimeAnalyticsService::class)->summary(null, $from, $to);

        $this->assertSame(60, $summary['total_downtime_minutes']);
        $this->assertSame(1, $summary['open_breakdown_count']);
        $this->assertSame(0, $summary['resolved_breakdown_count']);
        $this->assertNull($summary['mttr_minutes']);
        $this->assertSame(97.92, $summary['availability_pct']);
    }

    public function test_pareto_handles_downtime_shorter_than_one_minute(): void
    {
        $machine = Machine::factory()->create();
        MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => now()->subSeconds(30),
            'end_time' => now(),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Short interruption',
        ]);

        $pareto = app(DowntimeAnalyticsService::class)->categoryPareto();
        $this->assertSame(0, $pareto[0]['minutes']);
        $this->assertSame(100.0, $pareto[0]['percent']);
    }

    public function test_reversed_breakdown_interval_never_produces_negative_metrics(): void
    {
        $machine = Machine::factory()->create();
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-02 00:00:00');
        MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => '2026-08-01 12:00:00',
            'end_time' => '2026-08-01 11:00:00',
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Invalid reversed interval',
        ]);

        $summary = app(DowntimeAnalyticsService::class)->summary((int) $machine->id, $from, $to);

        $this->assertGreaterThanOrEqual(0, $summary['total_downtime_minutes']);
        $this->assertGreaterThanOrEqual(0, $summary['mtbf_hours']);
        $this->assertNull($summary['mttr_minutes']);
    }
}
