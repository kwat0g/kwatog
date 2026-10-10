<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Attendance\Services\ShiftService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class ProductionAttendanceConnectionTest extends TestCase
{
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;
    use PunchFixtures;
    use RefreshDatabase;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', config('app.timezone')));
        $manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $this->actingAs($manager);
        $this->wo = WorkOrder::factory()->create(['status' => 'confirmed', 'quantity_target' => 50,
            'planned_start' => now(), 'planned_end' => now()->addDays(2),
            'work_order_class' => 'non_stock', 'exception_reason' => 'Readiness regression.', 'exception_authorized_by' => $manager->id, 'created_by' => $manager->id]);
        $machine = Machine::factory()->create(['status' => 'idle', 'available_hours_per_day' => 8]);
        $mold = Mold::create(['product_id' => $this->wo->product_id, 'mold_code' => 'READY-MOLD', 'name' => 'Readiness mold',
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 10000, 'lifetime_max_shots' => 100000, 'status' => 'available']);
        $mold->compatibleMachines()->sync([$machine->id]);
        $this->wo->update(['machine_id' => $machine->id, 'mold_id' => $mold->id]);
        ProductionSchedule::create(['work_order_id' => $this->wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $this->assignProductionCrew($this->wo, $manager);
        app(InspectionSpecService::class)->upsertForProduct($this->wo->product_id,
            [['parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'is_critical' => false]], $manager->id);
    }

    private function context(): array
    {
        $service = app(WorkOrderAssignmentService::class);
        $crew = $service->currentCrew($this->wo);
        $employee = $crew->members->first()->employee;

        return [$service, $crew, $employee, Attendance::where('employee_id', $employee->id)->firstOrFail()];
    }

    public function test_accepted_reentry_is_present_without_erasing_payroll_clock_out(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        Shift::findOrFail($attendance->shift_id)->update(['end_time' => '16:00']);
        $punch = fn () => app(PunchService::class)->punch($employee, 14.2860, 120.9345, 20, '127.0.0.1', 'regression');
        $punch();
        $this->at('2026-10-06 15:45:00');
        $punch();
        $this->at('2026-10-06 15:47:00');
        $punch();
        $this->assertSame('present', $service->presence($employee, now())['state']);
        $this->assertSame('08:00', $attendance->fresh()->time_in->format('H:i'));
        $this->assertSame('15:45', $attendance->fresh()->time_out->format('H:i'));
        $slot = new ProductionSchedule(['scheduled_start' => now(), 'scheduled_end' => now()->addMinutes(5)]);
        $this->assertTrue($service->workerOptions($this->wo, $slot)->firstWhere('id', $employee->hash_id)['can_assign']);
        $attendance->update(['is_manual_entry' => true]);
        $this->assertSame('blocked', $service->presence($employee->fresh(), now())['state']);
    }

    public function test_clock_in_before_shift_is_explained_as_shift_coverage_not_missing_attendance(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        Shift::findOrFail($attendance->shift_id)->update(['start_time' => '09:00', 'end_time' => '16:00']);
        $this->wo->schedules()->update(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2)]);
        $presence = $service->presence($employee, now());
        $this->assertTrue($presence['clocked_in']);
        $this->assertSame('shift_coverage', $presence['reason_code']);
        $result = app(WorkOrderService::class)->executionReadiness($this->wo);
        $messages = collect($result['blockers'])->pluck('message')->implode(' ');
        $this->assertStringNotContainsString('needs verified attendance', $messages);
        $this->assertStringContainsString('shift', $messages);
    }

    public function test_worker_options_batch_reads_and_do_not_reuse_stale_attendance(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        for ($i = 0; $i < 40; $i++) {
            $worker = Employee::factory()->create(['department_id' => $employee->department_id, 'status' => 'active']);
            User::factory()->withRole('employee')->create(['employee_id' => $worker->id, 'is_active' => true]);
            app(ShiftAssignmentService::class)->assignToEmployee($worker->id, $attendance->shift_id, now()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $options = $service->workerOptions($this->wo, $service->schedule($this->wo));
        $this->assertCount(41, $options);
        $this->assertLessThan(100, $queries);
        fwrite(STDOUT, 'Worker options: '.$options->count().' employees, '.$queries." queries\n");
        $attendance->update(['status' => 'absent']);
        $options = $service->workerOptions($this->wo, $service->schedule($this->wo));
        $this->assertFalse($options->firstWhere('id', $employee->hash_id)['can_assign']);
    }

    public function test_missing_calendar_and_overtime_do_not_imply_shift_coverage(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        $this->approveOvertime($employee, now()->toDateString());
        $this->at('2026-10-06 11:00:00');
        $this->assertSame('shift_coverage', $service->presence($employee, now())['reason_code']);
        EmployeeShiftAssignment::where('employee_id', $employee->id)->update(['working_days' => null]);
        $this->assertSame('missing_calendar', $service->presence($employee, now())['reason_code']);
    }

    public function test_shift_and_calendar_edits_cannot_break_a_committed_work_order(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        try {
            app(ShiftService::class)->update(Shift::findOrFail($attendance->shift_id), ['start_time' => '09:00']);
            $this->fail('Committed schedule must protect shift coverage.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($this->wo->wo_number, $e->getMessage());
        }
        $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->firstOrFail();
        try {
            app(ShiftAssignmentService::class)->configureWorkingDays($assignment, [7]);
            $this->fail('Committed schedule must protect working days.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($this->wo->wo_number, $e->getMessage());
        }
        $this->assertSame('08:00:00', Shift::findOrFail($attendance->shift_id)->start_time);
        $this->assertContains(2, $assignment->fresh()->working_days);
    }

    public function test_clock_out_notifies_managers_and_blocks_new_operations_not_historical_output(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        $second = Employee::factory()->create(['department_id' => $employee->department_id, 'status' => 'active']);
        User::factory()->withRole('employee')->create(['employee_id' => $second->id, 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($second->id, $attendance->shift_id, now()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        $other = Attendance::create(['employee_id' => $second->id, 'date' => now()->toDateString(), 'shift_id' => $attendance->shift_id, 'time_in' => now(), 'status' => 'present']);
        $crew->members()->create(['employee_id' => $second->id, 'employee_snapshot' => $service->employeeOption($second)]);
        $this->wo->update(['minimum_workers' => 2, 'maximum_workers' => 2, 'status' => 'in_progress']);
        $this->passInProcessQc($this->wo);
        $other->update(['time_out' => now()]);
        $this->assertDatabaseHas('notifications', ['type' => 'production.assignment.review']);
        $op = WoOperation::create(['work_order_id' => $this->wo->id, 'sequence' => 1, 'operation_name' => 'Staffed operation', 'status' => 'pending', 'qty_planned' => 50]);
        try {
            app(WoOperationService::class)->startSetup($op, $employee);
            $this->fail('New operation needs the required crew.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($second->full_name, $e->getMessage());
        }
        $this->assertSame('pending', $op->fresh()->status->value);
        $op->update(['status' => 'in_progress', 'operator_id' => $employee->id]);
        try {
            app(WoOperationService::class)->recordOutput($op, 1);
            $this->fail('Unavailable crew requires a manager reason for earlier output.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        app(WoOperationService::class)->recordOutput($op->fresh(), 1, recordingReason: 'Reporting output completed before clock-out.');
        $this->assertSame('1.0000', $op->fresh()->qty_completed);
        $this->assertSame($crew->id, $service->assertCanRecord($employee->user, $this->wo, $crew->id)->id);
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
    }

    public function test_punched_shift_history_requires_a_future_assignment_and_new_shift_definition(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        app(PunchService::class)->punch($employee, 14.2860, 120.9345, 20, '127.0.0.1', 'regression');
        $replacement = Shift::create(['name' => 'Future shift', 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        try {
            app(ShiftAssignmentService::class)->assignToEmployee($employee->id, $replacement->id, now()->toDateString());
            $this->fail('Recorded shift date must be preserved.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        }
        try {
            app(ShiftService::class)->update(Shift::findOrFail($attendance->shift_id), ['grace_minutes' => 10]);
            $this->fail('Punch shift rules must be preserved.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        }
        app(ShiftAssignmentService::class)->assignToEmployee($employee->id, $replacement->id, now()->addDay()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        $this->assertSame($attendance->shift_id, app(ShiftAssignmentService::class)->current($employee->fresh(), now())->id);
        $this->assertSame($replacement->id, app(ShiftAssignmentService::class)->current($employee->fresh(), now()->addDay())->id);
    }

    public function test_expired_active_work_requires_hr_impact_review(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        $this->wo->update(['status' => 'in_progress']);
        $this->at('2026-10-06 11:00:00');
        $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->firstOrFail();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage($this->wo->wo_number);
        app(ShiftAssignmentService::class)->configureWorkingDays($assignment, [7]);
    }

    public function test_future_half_day_leave_and_account_deactivation_notify_owners(): void
    {
        [$service, $crew, $employee, $attendance] = $this->context();
        $this->wo->schedules()->update(['scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHours(2)]);
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $employee->id, 'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'half_day_period' => 'am',
        ]);
        $leave->forceFill(['status' => 'approved'])->save();
        $notices = DB::table('notifications')->where('type', 'production.assignment.review')->count();
        $this->assertGreaterThan(0, $notices);
        $leave->save();
        $this->assertSame($notices, DB::table('notifications')->where('type', 'production.assignment.review')->count());
        $employee->user->update(['is_active' => false]);
        $this->assertGreaterThan($notices, DB::table('notifications')->where('type', 'production.assignment.review')->count());
        $this->assertSame('confirmed', $this->wo->fresh()->status->value);
    }
}
