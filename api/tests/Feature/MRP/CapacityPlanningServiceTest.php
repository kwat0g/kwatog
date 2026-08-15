<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CapacityPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    private CapacityPlanningService $planner;
    private WorkOrderService $workOrders;
    private User $user;
    private Product $product;
    private Carbon $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = app(CapacityPlanningService::class);
        $this->workOrders = app(WorkOrderService::class);
        $this->user = User::factory()->create();
        $this->product = Product::create([
            'part_number' => 'CAP-' . substr(uniqid(), -8),
            'name' => 'Capacity Test Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);
        $this->tomorrow = Carbon::tomorrow()->startOfDay();
    }

    public function test_run_moves_a_new_work_order_after_a_persisted_active_window(): void
    {
        $machine = $this->machine('CAP-M-1', 100);
        $mold = $this->mold('CAP-D-1');
        $mold->compatibleMachines()->sync([$machine->id]);

        $blocker = $this->workOrder('CAP-BLOCK', WorkOrderStatus::Confirmed, '100', 1);
        $blocker->forceFill(['machine_id' => $machine->id, 'mold_id' => $mold->id])->save();
        ProductionSchedule::create([
            'work_order_id' => $blocker->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => $this->tomorrow->copy()->setTime(8, 0),
            'scheduled_end' => $this->tomorrow->copy()->setTime(12, 0),
            'priority_order' => 1,
            'status' => ProductionScheduleStatus::Confirmed->value,
            'is_confirmed' => true,
        ]);

        $target = $this->workOrder('CAP-TARGET', WorkOrderStatus::Planned, '100', 2);
        $target->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(9, 0)])->save();

        $result = $this->planner->run([$target->id]);

        $this->assertCount(1, $result['scheduled']);
        $schedule = ProductionSchedule::query()->where('work_order_id', $target->id)->firstOrFail();
        $this->assertSame(
            $this->tomorrow->copy()->setTime(12, 0)->toDateTimeString(),
            $schedule->scheduled_start->toDateTimeString(),
            'A persisted confirmed schedule must occupy the machine window before new proposals are placed.',
        );
    }

    public function test_run_stacks_new_work_orders_without_overlap(): void
    {
        $machine = $this->machine('CAP-M-2', 100);
        $mold = $this->mold('CAP-D-2');
        $mold->compatibleMachines()->sync([$machine->id]);

        $first = $this->workOrder('CAP-FIRST', WorkOrderStatus::Planned, '100', 10);
        $second = $this->workOrder('CAP-SECOND', WorkOrderStatus::Planned, '100', 1);
        $first->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(8, 0)])->save();
        $second->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(8, 0)])->save();

        $this->planner->run([$first->id, $second->id]);

        $rows = ProductionSchedule::query()
            ->whereIn('work_order_id', [$first->id, $second->id])
            ->orderBy('scheduled_start')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]->scheduled_end->lessThanOrEqualTo($rows[1]->scheduled_start));
    }

    public function test_run_schedules_subassembly_children_before_their_parent(): void
    {
        $machine = $this->machine('CAP-M-HIER', 100);
        $parentMold = $this->mold('CAP-D-PARENT');
        $parentMold->compatibleMachines()->sync([$machine->id]);

        $childProduct = Product::create([
            'part_number' => 'CAP-CHILD-' . substr(uniqid(), -8),
            'name' => 'Capacity Child Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '8.00',
            'is_active' => true,
        ]);
        $childMold = Mold::create([
            'mold_code' => 'CAP-D-CHILD',
            'name' => 'Capacity Child Mold',
            'product_id' => $childProduct->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $childMold->compatibleMachines()->sync([$machine->id]);

        $parent = $this->workOrder('CAP-PARENT', WorkOrderStatus::Planned, '100', 100);
        $child = WorkOrder::factory()->create([
            'wo_number' => 'CAP-CHILD',
            'product_id' => $childProduct->id,
            'parent_wo_id' => $parent->id,
            'quantity_target' => 100,
            'planned_start' => $this->tomorrow->copy()->setTime(8, 0),
            'planned_end' => $this->tomorrow->copy()->setTime(17, 0),
            'priority' => 1,
            'status' => WorkOrderStatus::Planned->value,
            'created_by' => $this->user->id,
        ]);

        $result = $this->planner->run([$parent->id, $child->id]);

        $this->assertCount(2, $result['scheduled']);
        $parentSchedule = ProductionSchedule::query()->where('work_order_id', $parent->id)->firstOrFail();
        $childSchedule = ProductionSchedule::query()->where('work_order_id', $child->id)->firstOrFail();
        $this->assertTrue($childSchedule->scheduled_end->lessThanOrEqualTo($parentSchedule->scheduled_start));
    }

    public function test_confirm_promotes_the_work_order_and_schedule_as_one_process(): void
    {
        $machine = $this->machine('CAP-M-CONFIRM', 100);
        $mold = $this->mold('CAP-D-CONFIRM');
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-CONFIRM', WorkOrderStatus::Planned, '100', 1);

        $this->planner->run([$wo->id]);
        $schedule = ProductionSchedule::query()->where('work_order_id', $wo->id)->firstOrFail();

        $confirmed = $this->planner->confirm([$schedule->id], $this->user->id);

        $this->assertCount(1, $confirmed);
        $this->assertSame(WorkOrderStatus::Confirmed, $wo->fresh()->status);
        $this->assertSame(ProductionScheduleStatus::Confirmed, $schedule->fresh()->status);
        $this->assertTrue((bool) $schedule->fresh()->is_confirmed);
    }

    public function test_reassign_rejects_an_incompatible_machine(): void
    {
        $sourceMachine = $this->machine('CAP-M-3', 100);
        $targetMachine = $this->machine('CAP-M-4', 100);
        $mold = $this->mold('CAP-D-3');
        $mold->compatibleMachines()->sync([$sourceMachine->id]);
        $wo = $this->workOrder('CAP-REASSIGN', WorkOrderStatus::Planned, '100', 1);
        $schedule = $this->schedule($wo, $sourceMachine, $mold, 8, 9);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not compatible');

        $this->planner->reassign($schedule->id, $targetMachine->id, $mold->id);
    }

    public function test_reassign_rejects_a_persisted_overlap_on_target_machine(): void
    {
        $sourceMachine = $this->machine('CAP-M-5', 100);
        $targetMachine = $this->machine('CAP-M-6', 100);
        $sourceMold = $this->mold('CAP-D-5');
        $targetMold = $this->mold('CAP-D-6');
        $sourceMold->compatibleMachines()->sync([$sourceMachine->id]);
        $targetMold->compatibleMachines()->sync([$targetMachine->id]);

        $blocker = $this->workOrder('CAP-OVERLAP', WorkOrderStatus::Confirmed, '100', 1);
        $blocker->forceFill(['machine_id' => $targetMachine->id, 'mold_id' => $targetMold->id])->save();
        ProductionSchedule::create([
            'work_order_id' => $blocker->id,
            'machine_id' => $targetMachine->id,
            'mold_id' => $targetMold->id,
            'scheduled_start' => $this->tomorrow->copy()->setTime(8, 30),
            'scheduled_end' => $this->tomorrow->copy()->setTime(10, 0),
            'priority_order' => 1,
            'status' => ProductionScheduleStatus::Confirmed->value,
            'is_confirmed' => true,
        ]);

        $wo = $this->workOrder('CAP-MOVE', WorkOrderStatus::Planned, '100', 1);
        $schedule = $this->schedule($wo, $sourceMachine, $sourceMold, 8, 9);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('overlaps work order CAP-OVERLAP');

        $this->planner->reassign($schedule->id, $targetMachine->id, $targetMold->id);
    }

    public function test_direct_work_order_confirmation_rejects_incompatible_assignment(): void
    {
        $machine = $this->machine('CAP-M-7', 100);
        $mold = $this->mold('CAP-D-7');
        $otherProduct = Product::create([
            'part_number' => 'CAP-OTHER-' . substr(uniqid(), -6),
            'name' => 'Other Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);
        $mold->forceFill(['product_id' => $otherProduct->id])->save();
        $wo = $this->workOrder('CAP-DIRECT', WorkOrderStatus::Planned, '100', 1);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not configured for this work-order product');

        $this->workOrders->confirm($wo, $machine->id, $mold->id);
    }

    private function machine(string $code, int $tonnage): Machine
    {
        return Machine::factory()->create([
            'machine_code' => $code,
            'tonnage' => $tonnage,
            'status' => 'idle',
        ]);
    }

    private function mold(string $code): Mold
    {
        return Mold::create([
            'mold_code' => $code,
            'name' => 'Capacity Mold ' . $code,
            'product_id' => $this->product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
    }

    private function workOrder(string $number, WorkOrderStatus $status, string $quantity, int $priority): WorkOrder
    {
        return WorkOrder::factory()->create([
            'wo_number' => $number,
            'product_id' => $this->product->id,
            'quantity_target' => $quantity,
            'planned_start' => $this->tomorrow->copy()->setTime(8, 0),
            'planned_end' => $this->tomorrow->copy()->setTime(17, 0),
            'priority' => $priority,
            'status' => $status->value,
            'created_by' => $this->user->id,
        ]);
    }

    private function schedule(
        WorkOrder $wo,
        Machine $machine,
        Mold $mold,
        int $startHour,
        int $endHour,
    ): ProductionSchedule {
        return ProductionSchedule::create([
            'work_order_id' => $wo->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => $this->tomorrow->copy()->setTime($startHour, 0),
            'scheduled_end' => $this->tomorrow->copy()->setTime($endHour, 0),
            'priority_order' => $wo->priority,
            'status' => ProductionScheduleStatus::Pending->value,
            'is_confirmed' => false,
        ]);
    }
}
