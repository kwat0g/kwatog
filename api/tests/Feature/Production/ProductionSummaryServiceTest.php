<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_counts_only_output_recorded_in_the_requested_day(): void
    {
        $day = Carbon::create(2026, 9, 18, 12, 0, 0);
        $workOrder = WorkOrder::factory()->create([
            'planned_start' => $day->copy()->subDay(),
            'planned_end' => $day->copy()->addDay(),
        ]);

        DB::table('work_order_outputs')->insert([
            [
                'work_order_id' => $workOrder->id,
                'recorded_by' => $workOrder->created_by,
                'recorded_at' => $day->copy()->subDay(),
                'good_count' => 100,
                'reject_count' => 5,
            ],
            [
                'work_order_id' => $workOrder->id,
                'recorded_by' => $workOrder->created_by,
                'recorded_at' => $day->copy()->setTime(8, 0),
                'good_count' => 10,
                'reject_count' => 1,
            ],
        ]);

        $summary = app(ProductionSummaryService::class)->forDate($day);

        $this->assertSame(10, $summary['totals']['good']);
        $this->assertSame(1, $summary['totals']['reject']);
        $this->assertSame(10, $summary['wos'][0]['good']);
    }

    public function test_daily_summary_includes_only_breakdowns_that_overlap_the_day(): void
    {
        $day = Carbon::create(2026, 9, 18, 12, 0, 0);
        $machine = Machine::factory()->create();

        MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => $day->copy()->subHour(),
            'end_time' => $day->copy()->addHour(),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Overlapping breakdown',
        ]);
        MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => $day->copy()->subHour(),
            'end_time' => $day->copy()->addHour(),
            'category' => MachineDowntimeCategory::MaterialShortage->value,
            'description' => 'Not a breakdown',
        ]);
        MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => $day->copy()->subDays(2),
            'end_time' => $day->copy()->subDay(),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Outside day',
        ]);

        $summary = app(ProductionSummaryService::class)->forDate($day);

        $this->assertCount(1, $summary['breakdowns']);
        $this->assertSame('Overlapping breakdown', $summary['breakdowns'][0]['description']);
    }
}
