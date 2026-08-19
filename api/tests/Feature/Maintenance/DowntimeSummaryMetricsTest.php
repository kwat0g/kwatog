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

        $summary = app(DowntimeAnalyticsService::class)->summary(null, $from, $to);

        $this->assertSame(120, $summary['total_downtime_minutes']);
        $this->assertSame(1, $summary['breakdown_count']);

        // uptime = 14,400 - 120 = 14,280 minutes = 238 hours over one breakdown.
        $this->assertSame(238.0, $summary['mtbf_hours'], 'MTBF must be derived from a positive window');

        // availability = 14,280 / 14,400 = 99.17%
        $this->assertNotNull($summary['availability_pct'], 'availability must be computed, not skipped by a negative-window guard');
        $this->assertSame(99.17, $summary['availability_pct']);
    }
}
