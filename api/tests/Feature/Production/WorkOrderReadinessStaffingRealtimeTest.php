<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Services\OutboxEventCodec;
use App\Modules\Attendance\Events\ShiftAssignmentChanged;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Events\AssignedWorkOrderChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkOrderReadinessStaffingRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_status_change_refreshes_active_work_order_readiness(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        DB::table('event_outbox')->delete();

        $employee->forceFill(['status' => 'on_leave'])->save();

        $this->assertReadinessRefreshFor($workOrder);
    }

    public function test_employee_account_eligibility_change_refreshes_active_work_order_readiness(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        $role = Role::query()->where('slug', 'employee')->firstOrFail();
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        DB::table('event_outbox')->delete();

        $user->update(['is_active' => false]);

        $this->assertReadinessRefreshFor($workOrder);
    }

    public function test_production_department_eligibility_change_refreshes_active_work_order_readiness(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        $department = $employee->department;
        DB::table('event_outbox')->delete();

        $department->update(['is_active' => false]);

        $this->assertReadinessRefreshFor($workOrder);
    }

    public function test_employee_role_definition_change_refreshes_active_work_order_readiness(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        $role = Role::query()->where('slug', 'employee')->firstOrFail();
        User::factory()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        DB::table('event_outbox')->delete();

        $role->update(['slug' => 'employee-role-revised']);

        $this->assertReadinessRefreshFor($workOrder);
    }

    public function test_employee_shift_assignment_model_write_refreshes_active_work_order_readiness(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        $shift = Shift::query()->create(['name' => 'Readiness shift', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        DB::table('event_outbox')->delete();

        EmployeeShiftAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(),
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);

        $this->assertReadinessRefreshFor($workOrder);
    }

    public function test_service_shift_assignment_write_and_observer_coalesce_to_one_work_order_ping(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        $shift = Shift::query()->create(['name' => 'Service readiness shift', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $assignment = EmployeeShiftAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(),
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        DB::table('event_outbox')->delete();

        app(ShiftAssignmentService::class)->configureWorkingDays($assignment, [7, 1, 2, 3, 4, 5, 6]);

        $messages = DB::table('event_outbox')
            ->where('event_type', AssignedWorkOrderChanged::class)
            ->get();
        $this->assertCount(1, $messages, 'The model observer and business service must coalesce readiness into one signal per WO and transaction.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
    }

    public function test_employee_reassignment_refreshes_work_orders_for_both_crew_members(): void
    {
        [$oldWorkOrder, $oldEmployee] = $this->assignedWorker();
        $newEmployee = Employee::factory()->create(['department_id' => $oldEmployee->department_id]);
        $newWorkOrder = WorkOrder::factory()->create(['status' => 'confirmed']);
        $actor = User::factory()->create();
        $newCrew = WorkOrderCrew::query()->create([
            'work_order_id' => $newWorkOrder->id,
            'recorder_employee_id' => $newEmployee->id,
            'assigned_by' => $actor->id,
            'reason' => 'Readiness realtime reassignment',
            'assigned_at' => now(),
        ]);
        $newCrew->members()->create([
            'employee_id' => $newEmployee->id,
            'employee_snapshot' => ['name' => $newEmployee->full_name],
        ]);
        $shift = Shift::query()->create(['name' => 'Reassigned readiness shift', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $assignment = EmployeeShiftAssignment::query()->create([
            'employee_id' => $oldEmployee->id,
            'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(),
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        DB::table('event_outbox')->delete();

        $assignment->update(['employee_id' => $newEmployee->id]);

        $payloads = DB::table('event_outbox')
            ->where('event_type', AssignedWorkOrderChanged::class)
            ->pluck('payload')
            ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertTrue($payloads->contains(fn (array $payload): bool => ($payload['workOrderHashId'] ?? null) === $oldWorkOrder->hash_id));
        $this->assertTrue($payloads->contains(fn (array $payload): bool => ($payload['workOrderHashId'] ?? null) === $newWorkOrder->hash_id));

        $assignmentSignal = DB::table('event_outbox')->where('event_type', ShiftAssignmentChanged::class)->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode(
            $assignmentSignal->event_type,
            json_decode((string) $assignmentSignal->payload, true, flags: JSON_THROW_ON_ERROR),
        );
        $channels = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
        $this->assertContains('private-attendance.employee-shift.'.$oldEmployee->hash_id, $channels);
        $this->assertSame(
            ['refresh', 'event_id', 'entity_type', 'action', 'version', 'timestamp'],
            array_keys($event->broadcastWith()),
        );
    }

    public function test_shift_definition_model_write_refreshes_active_work_order_readiness(): void
    {
        [$workOrder, $employee] = $this->assignedWorker();
        $shift = Shift::query()->create(['name' => 'Readiness shift', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        EmployeeShiftAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(),
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        DB::table('event_outbox')->delete();

        $shift->update(['start_time' => '09:00']);

        $this->assertReadinessRefreshFor($workOrder);
    }

    /** @return array{WorkOrder, Employee} */
    private function assignedWorker(): array
    {
        $employee = Employee::factory()->create([
            'department_id' => Department::factory()->state(['code' => 'PROD']),
        ]);
        $actor = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'confirmed']);
        $crew = WorkOrderCrew::query()->create([
            'work_order_id' => $workOrder->id,
            'recorder_employee_id' => $employee->id,
            'assigned_by' => $actor->id,
            'reason' => 'Readiness realtime regression',
            'assigned_at' => now(),
        ]);
        $crew->members()->create([
            'employee_id' => $employee->id,
            'employee_snapshot' => ['name' => $employee->full_name],
        ]);

        return [$workOrder, $employee];
    }

    private function assertReadinessRefreshFor(WorkOrder $workOrder): void
    {
        $payloads = DB::table('event_outbox')
            ->where('event_type', AssignedWorkOrderChanged::class)
            ->pluck('payload')
            ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR));

        $this->assertTrue(
            $payloads->contains(fn (array $payload): bool => ($payload['workOrderHashId'] ?? null) === $workOrder->hash_id),
            'Changing a readiness input must stage the content-free work-order refresh signal.',
        );
    }
}
