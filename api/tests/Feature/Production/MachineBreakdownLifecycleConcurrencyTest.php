<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Listeners\HandleMachineBreakdown;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MachineBreakdownLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_breakdown_pauses_work_order_and_keeps_machine_in_breakdown(): void
    {
        Queue::fake();
        User::factory()->withRole('system_admin')->create();
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

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

    public function test_breakdown_creates_one_linked_corrective_maintenance_work_order(): void
    {
        Queue::fake();
        User::factory()->withRole('system_admin')->create();
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $machine = Machine::factory()->create(['status' => MachineStatus::Running->value]);
        $workOrder = WorkOrder::factory()->create([
            'machine_id' => $machine->id,
            'status' => WorkOrderStatus::InProgress->value,
        ]);
        $machine->update([
            'status' => MachineStatus::Breakdown->value,
            'current_work_order_id' => $workOrder->id,
        ]);

        $event = new MachineStatusChanged(
            $machine->fresh(),
            MachineStatus::Running->value,
            MachineStatus::Breakdown->value,
            'Hydraulic failure',
        );
        app(HandleMachineBreakdown::class)->handle($event);
        app(HandleMachineBreakdown::class)->handle($event);

        $maintenanceWorkOrder = MaintenanceWorkOrder::query()
            ->where('maintainable_type', 'machine')
            ->where('maintainable_id', $machine->id)
            ->firstOrFail();

        $this->assertSame(1, MaintenanceWorkOrder::query()
            ->where('maintainable_type', 'machine')
            ->where('maintainable_id', $machine->id)
            ->where('type', 'corrective')
            ->where('status', 'open')
            ->count());
        $this->assertSame($maintenanceWorkOrder->id, MachineDowntime::query()
            ->where('machine_id', $machine->id)
            ->where('work_order_id', $workOrder->id)
            ->value('maintenance_order_id'));
    }

    public function test_breakdown_without_a_running_work_order_records_downtime_and_maintenance_work_order(): void
    {
        Queue::fake();
        User::factory()->withRole('system_admin')->create();
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $machine = Machine::factory()->create([
            'status' => MachineStatus::Breakdown->value,
            'current_work_order_id' => null,
        ]);

        app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
            $machine->fresh(),
            MachineStatus::Running->value,
            MachineStatus::Breakdown->value,
            'Idle machine hydraulic failure',
        ));

        $downtime = MachineDowntime::query()
            ->where('machine_id', $machine->id)
            ->where('category', MachineDowntimeCategory::Breakdown->value)
            ->firstOrFail();
        $this->assertNull($downtime->work_order_id);
        $this->assertNotNull($downtime->maintenance_order_id);
        $this->assertDatabaseHas('maintenance_work_orders', [
            'id' => $downtime->maintenance_order_id,
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
        ]);
    }

    public function test_breakdown_does_not_commit_without_an_automation_actor(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('system.automation.actor_roles', []);
        $machine = Machine::factory()->create([
            'status' => MachineStatus::Breakdown->value,
            'current_work_order_id' => null,
        ]);

        try {
            app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
                $machine->fresh(),
                MachineStatus::Running->value,
                MachineStatus::Breakdown->value,
                'No automation actor configured',
            ));
            $this->fail('A breakdown must not commit without its corrective maintenance order.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('configured automation actor', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, MaintenanceWorkOrder::query()
            ->where('maintainable_type', 'machine')
            ->where('maintainable_id', $machine->id)
            ->count());
        $this->assertSame(0, MachineDowntime::query()->where('machine_id', $machine->id)->count());
        $this->assertDatabaseMissing('event_outbox', ['event_type' => MachineBreakdownDetected::class]);
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

    public function test_breakdown_pause_uses_the_machine_status_event_flow(): void
    {
        Queue::fake();

        $machine = Machine::factory()->create(['status' => MachineStatus::Running->value]);
        $workOrder = WorkOrder::factory()->create([
            'machine_id' => $machine->id,
            'status' => WorkOrderStatus::InProgress->value,
        ]);
        $machine->update(['current_work_order_id' => $workOrder->id]);

        app(WorkOrderService::class)->pause(
            $workOrder,
            'Operator reported a hydraulic failure',
            MachineDowntimeCategory::Breakdown,
        );

        $this->assertSame(MachineStatus::Breakdown, $machine->fresh()->status);
        $this->assertSame(WorkOrderStatus::Paused, $workOrder->fresh()->status);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => MachineStatusChanged::class,
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
