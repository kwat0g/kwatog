<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionScheduleRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_create_and_update_coalesce_one_refresh_signal_after_commit(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Planned->value]);
        $machine = Machine::factory()->create();
        $mold = Mold::query()->create([
            'mold_code' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Realtime Test Mold',
            'product_id' => $workOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $workOrder->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();

        DB::transaction(function () use ($workOrder, $machine, $mold): void {
            $schedule = ProductionSchedule::query()->create([
                'work_order_id' => $workOrder->id,
                'machine_id' => $machine->id,
                'mold_id' => $mold->id,
                'scheduled_start' => now()->addDay(),
                'scheduled_end' => now()->addDay()->addHour(),
                'status' => ProductionScheduleStatus::Pending->value,
                'priority_order' => 1,
            ]);

            $schedule->update(['priority_order' => 2]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\ProductionScheduleChanged')
            ->get();

        $this->assertCount(1, $messages, 'A schedule aggregate write transaction must stage one refresh signal.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }

    public function test_schedule_reservation_refreshes_other_work_orders_using_the_same_equipment(): void
    {
        $scheduledWorkOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed->value]);
        $affectedWorkOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed->value]);
        $machine = Machine::factory()->create();
        $mold = Mold::query()->create([
            'mold_code' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Shared Reservation Mold',
            'product_id' => $scheduledWorkOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $scheduledWorkOrder->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();
        $affectedWorkOrder->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();
        DB::table('event_outbox')->delete();

        ProductionSchedule::query()->create([
            'work_order_id' => $scheduledWorkOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(),
            'status' => ProductionScheduleStatus::Pending->value,
            'priority_order' => 1,
        ]);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();
        $affectedPayloads = $messages->map(fn ($message) => json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR));

        $this->assertTrue(
            $affectedPayloads->contains(fn (array $payload): bool => $payload['workOrderHashId'] === $affectedWorkOrder->hash_id),
            'Reserving equipment for one work order must refresh other work orders that use that equipment.',
        );
    }

    public function test_moving_a_schedule_refreshes_work_orders_on_its_previous_equipment(): void
    {
        $scheduledWorkOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed->value]);
        $affectedWorkOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed->value]);
        $machine = Machine::factory()->create();
        $replacementMachine = Machine::factory()->create();
        $mold = Mold::query()->create([
            'mold_code' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Previous Reservation Mold',
            'product_id' => $scheduledWorkOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $replacementMold = Mold::query()->create([
            'mold_code' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Replacement Reservation Mold',
            'product_id' => $scheduledWorkOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $scheduledWorkOrder->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();
        $affectedWorkOrder->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();
        $schedule = ProductionSchedule::query()->create([
            'work_order_id' => $scheduledWorkOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(),
            'status' => ProductionScheduleStatus::Pending->value,
            'priority_order' => 1,
        ]);
        DB::table('event_outbox')->delete();

        $schedule->update(['machine_id' => $replacementMachine->id, 'mold_id' => $replacementMold->id]);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();
        $affectedPayloads = $messages->map(fn ($message) => json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR));

        $this->assertTrue(
            $affectedPayloads->contains(fn (array $payload): bool => $payload['workOrderHashId'] === $affectedWorkOrder->hash_id),
            'Moving a reservation must refresh work orders whose previous equipment was released.',
        );
    }

    public function test_machine_breakdown_bulk_schedule_transition_is_observed(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Planned->value]);
        $machine = Machine::factory()->create();
        $mold = Mold::query()->create([
            'mold_code' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Breakdown Realtime Test Mold',
            'product_id' => $workOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $workOrder->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();
        $schedule = ProductionSchedule::query()->create([
            'work_order_id' => $workOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(),
            'status' => ProductionScheduleStatus::Pending->value,
            'priority_order' => 1,
        ]);

        $machine->update(['status' => 'breakdown']);

        $message = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\ProductionScheduleChanged')
            ->where('dedupe_key', 'like', "production-schedule-refresh:{$schedule->id}:%")
            ->first();

        $this->assertNotNull($message, 'Machine breakdown must publish a scheduler refresh when it retires a pending slot.');
        $payload = json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('status_changed', $payload['action']);
        $this->assertSame($schedule->hash_id, $payload['scheduleHashId']);
        $this->assertSame(ProductionScheduleStatus::Superseded->value, $schedule->fresh()->status->value);
    }
}
