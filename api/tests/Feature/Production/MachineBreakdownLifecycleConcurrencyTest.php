<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Listeners\HandleMachineBreakdown;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MachineBreakdownLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_breakdown_pauses_work_order_and_keeps_machine_in_breakdown(): void
    {
        Queue::fake();

        $machine = Machine::factory()->create(['status' => MachineStatus::Running->value]);
        $workOrder = WorkOrder::factory()->create([
            'machine_id' => $machine->id,
            'status' => WorkOrderStatus::InProgress->value,
        ]);
        $machine->update([
            'status' => MachineStatus::Breakdown->value,
            'current_work_order_id' => $workOrder->id,
        ]);

        app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
            $machine->fresh(),
            MachineStatus::Running->value,
            MachineStatus::Breakdown->value,
            'Hydraulic failure',
        ));

        $this->assertSame(MachineStatus::Breakdown, $machine->fresh()->status);
        $this->assertSame(WorkOrderStatus::Paused, $workOrder->fresh()->status);
        $this->assertNull($machine->fresh()->current_work_order_id);

        $downtime = MachineDowntime::query()
            ->where('machine_id', $machine->id)
            ->where('work_order_id', $workOrder->id)
            ->first();

        $this->assertNotNull($downtime);
        $this->assertSame(MachineDowntimeCategory::Breakdown, $downtime->category);
        $this->assertNull($downtime->end_time);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => MachineBreakdownDetected::class,
        ]);
    }

    public function test_stale_breakdown_event_does_not_pause_after_machine_restoration(): void
    {
        Queue::fake();

        $machine = Machine::factory()->create([
            'status' => MachineStatus::Idle->value,
            'current_work_order_id' => null,
        ]);
        $workOrder = WorkOrder::factory()->create([
            'machine_id' => $machine->id,
            'status' => WorkOrderStatus::InProgress->value,
        ]);

        app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
            $machine->fresh(),
            MachineStatus::Running->value,
            MachineStatus::Breakdown->value,
            'Stale breakdown notification',
        ));

        $this->assertSame(MachineStatus::Idle, $machine->fresh()->status);
        $this->assertSame(WorkOrderStatus::InProgress, $workOrder->fresh()->status);
        $this->assertDatabaseCount('machine_downtimes', 0);
        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => MachineBreakdownDetected::class,
        ]);
    }

    public function test_stale_restoration_event_does_not_close_downtime_while_machine_is_not_restored(): void
    {
        Queue::fake();

        $machine = Machine::factory()->create(['status' => MachineStatus::Maintenance->value]);
        $downtime = MachineDowntime::create([
            'machine_id' => $machine->id,
            'work_order_id' => null,
            'start_time' => now()->subMinutes(15),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Open breakdown',
        ]);

        app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
            $machine->fresh(),
            MachineStatus::Breakdown->value,
            MachineStatus::Idle->value,
            'Stale restoration notification',
        ));

        $this->assertNull($downtime->fresh()->end_time);
    }

    public function test_restoration_closes_open_machine_downtime_after_authoritative_transition(): void
    {
        Queue::fake();

        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $downtime = MachineDowntime::create([
            'machine_id' => $machine->id,
            'work_order_id' => null,
            'start_time' => now()->subMinutes(15),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Open breakdown',
        ]);

        app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
            $machine->fresh(),
            MachineStatus::Breakdown->value,
            MachineStatus::Idle->value,
            'Restoration complete',
        ));

        $closed = $downtime->fresh();
        $this->assertNotNull($closed->end_time);
        $this->assertNotNull($closed->duration_minutes);
        $this->assertGreaterThanOrEqual(15, $closed->duration_minutes);
    }
}
