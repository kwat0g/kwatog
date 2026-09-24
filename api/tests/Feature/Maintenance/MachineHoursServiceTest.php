<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Maintenance\Services\MachineHoursService;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unowned_in_progress_work_order_does_not_accrue_hours_to_now(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        WorkOrder::factory()->create([
            'machine_id' => $machine->id,
            'status' => WorkOrderStatus::InProgress->value,
            'actual_start' => now()->subHours(10),
            'actual_end' => null,
        ]);

        app(MachineHoursService::class)->recompute();

        $this->assertSame('0.00', (string) $machine->fresh()->running_hours_total);
    }

    public function test_downtime_is_clipped_to_its_work_order_and_overlaps_are_unioned(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $workOrder = WorkOrder::factory()->create([
            'machine_id' => $machine->id,
            'status' => WorkOrderStatus::Completed->value,
            'actual_start' => now()->subHours(3),
            'actual_end' => now()->subHours(2),
        ]);

        foreach (range(1, 2) as $_) {
            MachineDowntime::create([
                'machine_id' => $machine->id,
                'work_order_id' => $workOrder->id,
                'start_time' => now()->subHours(3)->addMinutes(15),
                'end_time' => now()->subHours(3)->addMinutes(45),
                'duration_minutes' => 30,
                'category' => 'breakdown',
            ]);
        }
        // This unrelated downtime is outside the work-order interval and must
        // not be subtracted from its runtime total.
        MachineDowntime::create([
            'machine_id' => $machine->id,
            'start_time' => now()->subMinutes(90),
            'end_time' => now()->subMinutes(30),
            'duration_minutes' => 60,
            'category' => 'planned_maintenance',
        ]);

        app(MachineHoursService::class)->recompute();

        // One hour of production minus one 30-minute downtime interval.
        $this->assertSame('0.50', (string) $machine->fresh()->running_hours_total);
    }
}
