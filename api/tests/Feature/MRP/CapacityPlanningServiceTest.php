<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\HasAssignedProductionCrew;
use Tests\TestCase;

class CapacityPlanningServiceTest extends TestCase
{
    use HasAssignedProductionCrew;
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
            'part_number' => 'CAP-'.substr(uniqid(), -8),
            'name' => 'Capacity Test Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);
        $this->tomorrow = Carbon::tomorrow()->startOfDay();
        $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee', 'is_system' => true]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $this->user->forceFill(['employee_id' => $employee->id, 'role_id' => $role->id])->save();
        $shift = Shift::create(['name' => 'Planner working shift', 'start_time' => '00:00', 'end_time' => '00:00', 'is_night_shift' => true, 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($employee->id, $shift->id, now()->subDay()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
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

    public function test_planner_places_production_after_machine_and_mold_maintenance_windows(): void
    {
        $machine = $this->machine('CAP-M-MAINT', 100);
        $mold = $this->mold('CAP-D-MAINT');
        $mold->compatibleMachines()->sync([$machine->id]);
        foreach (['machine' => $machine, 'mold' => $mold] as $type => $equipment) {
            $order = \App\Modules\Maintenance\Models\MaintenanceWorkOrder::create([
                'mwo_number' => 'CAP-MAINT-'.$type, 'maintainable_type' => $type, 'maintainable_id' => $equipment->id,
                'type' => 'preventive', 'priority' => 'medium', 'description' => 'Inspection', 'status' => 'open', 'created_by' => $this->user->id,
            ]);
            \App\Modules\Maintenance\Models\MaintenanceWindow::create([
                'maintainable_type' => $type, 'maintainable_id' => $equipment->id, 'work_order_id' => $order->id,
                'planned_start_at' => $this->tomorrow->copy()->setTime($type === 'machine' ? 8 : 9, 0),
                'planned_end_at' => $this->tomorrow->copy()->setTime($type === 'machine' ? 9 : 10, 0),
                'state' => 'confirmed', 'confirmed_by' => $this->user->id, 'confirmed_at' => now(),
            ]);
        }
        $target = $this->workOrder('CAP-AFTER-MAINT', WorkOrderStatus::Planned, '100', 2);
        $target->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(8, 0)])->save();
        $result = $this->planner->run([$target->id]);
        $this->assertCount(1, $result['scheduled']);
        $this->assertSame($this->tomorrow->copy()->setTime(10, 0)->toDateTimeString(),
            ProductionSchedule::where('work_order_id', $target->id)->firstOrFail()->scheduled_start->toDateTimeString());
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
            'part_number' => 'CAP-CHILD-'.substr(uniqid(), -8),
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

    public function test_confirmation_without_crew_preserves_pending_state(): void
    {
        $machine = $this->machine('CREW-GUARD-M', 100);
        $mold = $this->mold('CREW-GUARD-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CREW-GUARD-WO', WorkOrderStatus::Planned, '100', 1);
        $slot = $this->schedule($wo, $machine, $mold, 8, 10);
        try {
            $this->planner->confirm([$slot->id], $this->user->id);
            $this->fail('Confirmation without a crew must be rejected.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('crew', strtolower($error->getMessage()));
        }
        $this->assertSame(WorkOrderStatus::Planned, $wo->fresh()->status);
        $this->assertSame(ProductionScheduleStatus::Pending, $slot->fresh()->status);
        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_confirm_promotes_the_work_order_and_schedule_as_one_process(): void
    {
        $machine = $this->machine('CAP-M-CONFIRM', 100);
        $mold = $this->mold('CAP-D-CONFIRM');
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-CONFIRM', WorkOrderStatus::Planned, '100', 1);

        $this->planner->run([$wo->id]);
        $schedule = ProductionSchedule::query()->where('work_order_id', $wo->id)->firstOrFail();

        $this->assignProductionCrew($wo, $this->user);
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
            'part_number' => 'CAP-OTHER-'.substr(uniqid(), -6),
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

    public function test_snapshot_keeps_all_same_day_work_orders_and_their_planning_context(): void
    {
        $machine = $this->machine('CAP-SNAPSHOT', 100);
        $mold = $this->mold('CAP-SNAPSHOT-MOLD');
        $orders = collect(range(1, 3))->map(function (int $index) use ($machine, $mold): WorkOrder {
            $wo = $this->workOrder('CAP-SNAPSHOT-'.$index, WorkOrderStatus::Planned, '50', $index);
            $this->schedule($wo, $machine, $mold, $index, $index + 1);

            return $wo;
        });

        $snapshot = $this->planner->snapshot($this->tomorrow, $this->tomorrow->copy()->addDay());
        $bars = collect($snapshot['rows'])->firstWhere('machine_id', $machine->hash_id)['bars'];

        $this->assertCount(3, $bars);
        $this->assertSame(config('app.timezone'), $snapshot['timezone']);
        $this->assertSame($orders[0]->wo_number, $bars[0]['wo_number']);
        $this->assertSame($this->product->part_number, $bars[0]['product_part_number']);
        $this->assertSame(50, $bars[0]['quantity_target']);
        $this->assertArrayHasKey('sales_order', $bars[0]);
    }

    public function test_shared_mold_waits_on_another_machine_without_charging_its_hours(): void
    {
        $first = $this->machine('SHARED-A', 200);
        $next = $this->machine('SHARED-B', 100);
        $next->update(['available_hours_per_day' => 2]);
        $mold = $this->mold('SHARED');
        $mold->compatibleMachines()->sync([$first->id, $next->id]);
        $blocker = $this->workOrder('SHARED-BLOCK', WorkOrderStatus::Confirmed, '100', 1);
        $this->schedule($blocker, $first, $mold, 8, 12);
        $target = $this->workOrder('SHARED-NEXT', WorkOrderStatus::Planned, '100', 1);
        $result = $this->planner->run([$target->id]);
        $this->assertCount(1, $result['scheduled']);
        $row = $target->schedules()->firstOrFail();
        $this->assertSame($next->id, $row->machine_id);
        $this->assertSame('12:00', $row->scheduled_start->format('H:i'));
    }

    public function test_reassign_recalculates_slower_mold_duration(): void
    {
        $machine = $this->machine('SLOW-M', 100);
        $fast = $this->mold('FAST-D');
        $slow = $this->mold('SLOW-D');
        $slow->update(['output_rate_per_hour' => 50]);
        $fast->compatibleMachines()->sync([$machine->id]);
        $slow->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('SLOW-WO', WorkOrderStatus::Planned, '100', 1);
        $row = $this->schedule($wo, $machine, $fast, 8, 10);
        $updated = $this->planner->reassign($row->id, $machine->id, $slow->id);
        $this->assertSame('10:25', $updated->scheduled_end->format('H:i'));
    }

    public function test_terminal_execution_history_releases_capacity(): void
    {
        $machine = $this->machine('HISTORY-M', 100);
        $mold = $this->mold('HISTORY-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $finished = $this->workOrder('HISTORY-DONE', WorkOrderStatus::Completed, '100', 1);
        $row = $this->schedule($finished, $machine, $mold, 8, 12);
        $row->update(['status' => 'executed', 'reserves_capacity' => false]);
        $next = $this->workOrder('HISTORY-NEXT', WorkOrderStatus::Planned, '50', 1);
        $this->assertCount(1, $this->planner->run([$next->id])['scheduled']);
        $this->assertSame('08:00', $next->schedules()->firstOrFail()->scheduled_start->format('H:i'));
        $this->assertDatabaseHas('production_schedules', ['id' => $row->id, 'status' => 'executed', 'reserves_capacity' => false]);
    }

    public function test_planner_counts_prior_run_mold_life_commitments(): void
    {
        $machine = $this->machine('LIFE-M', 100);
        $mold = $this->mold('LIFE-D');
        $mold->update(['max_shots_before_maintenance' => 150]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $first = $this->workOrder('LIFE-FIRST', WorkOrderStatus::Planned, '100', 1);
        $second = $this->workOrder('LIFE-SECOND', WorkOrderStatus::Planned, '100', 1);
        $this->assertCount(1, $this->planner->run([$first->id])['scheduled']);
        $result = $this->planner->run([$second->id]);
        $this->assertCount(0, $result['scheduled']);
        $this->assertCount(1, $result['conflicts']);
    }

    public function test_stale_batch_approval_rejects_and_rolls_back_prior_rows(): void
    {
        $machine = $this->machine('BATCH-M', 100);
        $mold = $this->mold('BATCH-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $a = $this->workOrder('BATCH-A', WorkOrderStatus::Planned, '50', 1);
        $b = $this->workOrder('BATCH-B', WorkOrderStatus::Planned, '50', 1);
        $first = $this->schedule($a, $machine, $mold, 8, 9);
        $second = $this->schedule($b, $machine, $mold, 9, 10);
        $crewA = $this->assignProductionCrew($a, $this->user);
        $crewB = $this->assignProductionCrew($b, $this->user);
        $reviews = [['schedule_id' => $first->id, 'schedule_updated_at' => $first->updated_at->toISOString(), 'expected_crew_id' => $crewA->id],
            ['schedule_id' => $second->id, 'schedule_updated_at' => $second->updated_at->copy()->subSecond()->toISOString(), 'expected_crew_id' => $crewB->id]];
        try {
            $this->planner->confirm([$first->id, $second->id], $this->user->id, $reviews);
            $this->fail('Stale second row must reject entire batch.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('changed', $error->getMessage());
        }
        $this->assertSame(WorkOrderStatus::Planned, $a->fresh()->status);
        $this->assertSame(WorkOrderStatus::Planned, $b->fresh()->status);
        $this->assertSame(ProductionScheduleStatus::Pending, $first->fresh()->status);
        $this->assertSame(ProductionScheduleStatus::Pending, $second->fresh()->status);
    }

    public function test_worker_aware_planning_keeps_old_proposal_when_coverage_disappears_and_persists_diagnostic(): void
    {
        $machine = $this->machine('HR-M', 100);
        $mold = $this->mold('HR-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('HR-WO', WorkOrderStatus::Planned, '50', 1);
        $this->planner->run([$wo->id]);
        $old = $wo->schedules()->firstOrFail();
        $this->assignProductionCrew($wo, $this->user);
        EmployeeShiftAssignment::where('employee_id', $this->user->employee_id)->update(['working_days' => null]);
        $result = $this->planner->run([$wo->id]);
        $this->assertCount(0, $result['scheduled']);
        $this->assertTrue($old->fresh()->reserves_capacity);
        $this->assertSame(ProductionScheduleStatus::Pending, $old->fresh()->status);
        $snapshot = $this->planner->snapshot($this->tomorrow, $this->tomorrow->copy()->addDay());
        $this->assertSame('HR-WO', $snapshot['conflicts'][0]['wo_number']);
        $this->assertCount(1, $snapshot['conflicts'][0]['reasons']);
    }

    public function test_night_shift_proposal_crosses_midnight_and_counts_both_days(): void
    {
        $machine = $this->machine('NIGHT-M', 100);
        $machine->update(['available_hours_per_day' => 1.5]);
        $mold = $this->mold('NIGHT-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $shift = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_night_shift' => true, 'is_active' => true]);
        EmployeeShiftAssignment::where('employee_id', $this->user->employee_id)->update(['shift_id' => $shift->id]);
        $wo = $this->workOrder('NIGHT-WO', WorkOrderStatus::Planned, '100', 1);
        $wo->update(['planned_start' => $this->tomorrow->copy()->setTime(23, 30), 'planned_end' => $this->tomorrow->copy()->addDay()->setTime(6, 0)]);
        $result = $this->planner->run([$wo->id]);
        $this->assertCount(1, $result['scheduled']);
        $slot = $wo->schedules()->firstOrFail();
        $this->assertSame('23:30', $slot->scheduled_start->format('H:i'));
        $this->assertSame('01:30', $slot->scheduled_end->format('H:i'));
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
            'name' => 'Capacity Mold '.$code,
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

    public function test_preferred_day_and_staffing_override_reach_placement(): void
    {
        $machine = $this->machine('CAP-PREF', 100);
        $mold = $this->mold('CAP-PREF-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-PREF-W', WorkOrderStatus::Planned, '100', 1);
        $wo->update(['planned_end' => $this->tomorrow->copy()->addDays(4)]);
        $day = $this->tomorrow->copy()->addDay()->toDateString();
        $result = $this->planner->run([$wo->id], [$wo->id => ['preferred_production_date' => $day]]);
        $this->assertCount(1, $result['scheduled']);
        $slot = ProductionSchedule::where('work_order_id', $wo->id)->where('reserves_capacity', true)->firstOrFail();
        $this->assertSame($day, $slot->scheduled_start->toDateString());
        $result = $this->planner->run([$wo->id], [$wo->id => ['minimum_workers' => 4]]);
        $this->assertCount(0, $result['scheduled']);
        $this->assertTrue($slot->fresh()->reserves_capacity);
    }

    public function test_earliest_machine_wins_before_tonnage_preference(): void
    {
        $small = $this->machine('CAP-SMALL', 100);
        $large = $this->machine('CAP-LARGE', 200);
        $mold = $this->mold('CAP-EARLY-D');
        $mold->compatibleMachines()->sync([$small->id, $large->id]);
        $blocker = $this->workOrder('CAP-EARLY-B', WorkOrderStatus::Confirmed, '100', 1);
        $otherMold = $this->mold('CAP-BLOCK-D');
        $otherMold->compatibleMachines()->sync([$small->id]);
        ProductionSchedule::create([
            'work_order_id' => $blocker->id, 'machine_id' => $small->id, 'mold_id' => $otherMold->id,
            'scheduled_start' => $this->tomorrow->copy()->setTime(8, 0),
            'scheduled_end' => $this->tomorrow->copy()->setTime(12, 0),
            'status' => 'confirmed', 'is_confirmed' => true, 'priority_order' => 1,
        ]);
        $wo = $this->workOrder('CAP-EARLY-W', WorkOrderStatus::Planned, '100', 1);
        $this->planner->run([$wo->id]);
        $slot = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
        $this->assertSame($large->id, $slot->machine_id);
        $this->assertSame('08:00', $slot->scheduled_start->format('H:i'));
    }

    public function test_legacy_mrp_start_is_not_a_lower_bound_but_manual_start_is(): void
    {
        $machine = $this->machine('LEGACY-M', 100);
        $mold = $this->mold('LEGACY-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $manual = $this->workOrder('LEGACY-MANUAL', WorkOrderStatus::Planned, '100', 1);
        $reference = now()->addDays(8)->startOfDay();
        $manual->update(['planned_start' => $reference, 'planned_end' => $reference->copy()->addDay()]);
        $plan = MrpPlan::create(['mrp_plan_no' => 'MRP-LEGACY', 'sales_order_id' => SalesOrder::factory()->create()->id,
            'version' => 1, 'status' => 'active', 'generated_by' => $this->user->id, 'generated_at' => now()]);
        $generated = $this->workOrder('LEGACY-MRP', WorkOrderStatus::Planned, '100', 1);
        $generated->update(['mrp_plan_id' => $plan->id, 'planned_start' => $reference, 'planned_end' => $reference->copy()->addDay()]);
        $this->planner->run([$manual->id, $generated->id]);
        $this->assertTrue($manual->currentProductionSchedule()->firstOrFail()->scheduled_start->gte($reference));
        $this->assertTrue($generated->currentProductionSchedule()->firstOrFail()->scheduled_start->lt($reference));
        $this->assertTrue($generated->fresh()->planned_start->eq($reference));
    }

    public function test_lower_priority_work_order_can_fill_gap_before_later_commitment(): void
    {
        $machine = $this->machine('GAP-M', 100);
        $mold = $this->mold('GAP-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $later = $this->workOrder('GAP-LATER', WorkOrderStatus::Planned, '100', 10);
        $later->update(['planned_start' => $this->tomorrow->copy()->setTime(14, 0)]);
        $earlier = $this->workOrder('GAP-EARLIER', WorkOrderStatus::Planned, '100', 1);
        $this->planner->run([$later->id, $earlier->id]);
        $this->assertSame('08:00', $earlier->currentProductionSchedule()->firstOrFail()->scheduled_start->format('H:i'));
        $this->assertSame('14:00', $later->currentProductionSchedule()->firstOrFail()->scheduled_start->format('H:i'));
    }

    public function test_scoped_parent_cannot_schedule_without_required_child_proposal(): void
    {
        $machine = $this->machine('SCOPED-M', 100);
        $mold = $this->mold('SCOPED-D');
        $mold->compatibleMachines()->sync([$machine->id]);
        $parent = $this->workOrder('SCOPED-P', WorkOrderStatus::Planned, '100', 10);
        $child = $this->workOrder('SCOPED-C', WorkOrderStatus::Planned, '100', 1);
        $child->update(['parent_wo_id' => $parent->id]);
        $result = $this->planner->run([$parent->id]);
        $this->assertCount(0, $result['scheduled']);
        $this->assertStringContainsString('subassembly', $result['conflicts'][0]['reasons'][0]);
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
