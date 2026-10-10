<?php
namespace Tests\Feature\Production;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Attendance\Services\ShiftService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use Tests\Feature\Attendance\Concerns\PunchFixtures;

class ProductionAttendanceRiskProbeTest extends WorkOrderExecutionReadinessTest
{
    use PunchFixtures;
    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
    }
    private function context(): array
    {
        $wo = WorkOrder::firstOrFail();
        $service = app(WorkOrderAssignmentService::class);
        $crew = $service->currentCrew($wo);
        $employee = $crew->members->first()->employee;
        return [$wo, $service, $crew, $employee, Attendance::where('employee_id', $employee->id)->firstOrFail()];
    }
    private function punch($employee)
    {
        return app(PunchService::class)->punch($employee, 14.2860, 120.9345, 20, '127.0.0.1', 'isolated-audit');
    }
    private function evidence(string $scenario, array $result): void
    {
        fwrite(STDOUT, '\nAUDIT '.json_encode(['scenario' => $scenario] + $result)."\n");
    }
    public function test_probe_overnight_rotation_clock_out(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $attendance->forceDelete();
        $night = Shift::create(['name'=>'Audit night','start_time'=>'22:00','end_time'=>'06:00','is_night_shift'=>true,'is_active'=>true]);
        $day = Shift::create(['name'=>'Audit day','start_time'=>'08:00','end_time'=>'16:00','is_active'=>true]);
        EmployeeShiftAssignment::where('employee_id',$employee->id)->update(['shift_id'=>$night->id,'end_date'=>'2026-10-06']);
        app(ShiftAssignmentService::class)->assignToEmployee($employee->id,$day->id,'2026-10-07',null,[1,2,3,4,5,6,7]);
        $this->at('2026-10-06 22:00:00');
        $this->punch($employee);
        $this->at('2026-10-07 06:00:00');
        $reason = null;
        try {$this->punch($employee);} catch (PunchRejectedException $e) {$reason = $e->errorCode();}
        $this->assertNotNull($reason);
        $this->assertNull(Attendance::where('employee_id',$employee->id)->firstOrFail()->time_out);
        $this->evidence('Night shift ends during day-shift reassignment', ['clock_out_rejected'=>$reason,'attendance_open'=>true]);
    }
    public function test_probe_clock_back_in_during_shift(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $shift = Shift::findOrFail($attendance->shift_id);
        $shift->update(['end_time'=>'16:00']);
        $this->at('2026-10-06 08:00:00'); $this->punch($employee);
        $this->at('2026-10-06 15:45:00'); $this->punch($employee);
        $this->at('2026-10-06 15:47:00'); $returned = $this->punch($employee);
        $row = $attendance->fresh();
        $presence = $service->presence($employee, now());
        $this->assertSame('in',$returned->direction->value);
        $this->assertSame('blocked',$presence['state']);
        $this->evidence('Accepted re-entry before shift ends',['latest_punch'=>$returned->direction->value,'time_out_retained'=>$row->time_out->format('g:i A'),'production_presence'=>$presence]);
    }
    public function test_probe_future_attendance_does_not_count_as_presence(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $attendance->update(['time_in'=>now()->addMinute()]);
        $presence = $service->presence($employee,now());
        $this->assertSame('blocked',$presence['state']);
        $this->evidence('Future punch is rejected',['presence'=>$presence]);
    }
    public function test_probe_running_non_recorder_clock_out(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $second = \App\Modules\HR\Models\Employee::factory()->create(['department_id'=>$employee->department_id,'status'=>'active']);
        \App\Modules\Auth\Models\User::factory()->withRole('employee')->create(['employee_id'=>$second->id,'is_active'=>true]);
        app(ShiftAssignmentService::class)->assignToEmployee($second->id,$attendance->shift_id,now()->toDateString(),null,[1,2,3,4,5,6,7]);
        $other = Attendance::create(['employee_id'=>$second->id,'date'=>now()->toDateString(),'shift_id'=>$attendance->shift_id,'time_in'=>now(),'status'=>'present']);
        $crew->members()->create(['employee_id'=>$second->id,'employee_snapshot'=>$service->employeeOption($second)]);
        $wo->update(['minimum_workers'=>2,'maximum_workers'=>2,'status'=>'in_progress']);
        $other->update(['time_out'=>now()]);
        $allowed = $service->assertCanRecord($employee->user,$wo,$crew->id);
        $this->assertSame($crew->id,$allowed->id);
        $this->assertSame('in_progress',$wo->fresh()->status->value);
        $this->evidence('Required non-recorder clocks out while running',['required_workers'=>2,'one_clocked_out'=>true,'recorder_access_retained'=>true,'work_order_status'=>$wo->fresh()->status->value]);
    }
    public function test_probe_shift_edit_invalidates_approved_work_order(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $before = app(WorkOrderService::class)->executionReadiness($wo);
        $this->assertTrue($before['ready']);
        app(ShiftService::class)->update(Shift::findOrFail($attendance->shift_id),['start_time'=>'09:00']);
        $after = app(WorkOrderService::class)->executionReadiness($wo->fresh());
        $this->assertFalse($after['ready']);
        $this->assertSame('confirmed',$wo->fresh()->status->value);
        $this->evidence('HR edits shift hours after WO approval',['before_ready'=>$before['ready'],'after_ready'=>$after['ready'],'status_unchanged'=>$wo->fresh()->status->value,'blockers'=>$after['blockers']]);
    }
    public function test_probe_default_shift_without_hr_calendar(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $shift = Shift::findOrFail($attendance->shift_id);
        Shift::query()->update(['is_default'=>false]);
        $shift->update(['is_default'=>true]);
        $attendance->forceDelete();
        EmployeeShiftAssignment::where('employee_id',$employee->id)->delete();
        $this->punch($employee);
        $presence = $service->presence($employee,now());
        $this->assertSame('present',Attendance::where('employee_id',$employee->id)->firstOrFail()->status->value);
        $this->assertSame('blocked',$presence['state']);
        $this->evidence('Default shift accepts clock-in without employee HR calendar',['attendance_saved'=>true,'production_presence'=>$presence]);
    }
    public function test_probe_overtime_does_not_extend_production_shift(): void
    {
        [$wo, $service, $crew, $employee, $attendance] = $this->context();
        $this->approveOvertime($employee,now()->toDateString());
        $attendance->update(['time_in'=>now(),'time_out'=>null]);
        $this->at('2026-10-06 10:15:00');
        $presence = $service->presence($employee,now());
        $this->assertSame('blocked',$presence['state']);
        $this->evidence('Approved overtime after configured shift',['overtime_hours'=>2,'no_clock_out'=>true,'production_presence'=>$presence]);
    }

}
