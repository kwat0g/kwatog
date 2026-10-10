<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use Tests\Support\HasPassedInProcessQc;

class WorkOrderAttendanceSafetyTest extends WorkOrderExecutionReadinessTest
{
    use HasPassedInProcessQc;

    private function fixture(): array
    {
        $wo = WorkOrder::firstOrFail();
        $service = app(WorkOrderAssignmentService::class);
        $crew = $service->currentCrew($wo);
        $employee = $crew->members->first()->employee;

        return [$wo, $service, $employee, Attendance::where('employee_id', $employee->id)->firstOrFail(), $crew];
    }

    public function test_early_punch_is_present_but_does_not_allow_start_before_shift(): void
    {
        [$wo, $service, $employee, $attendance] = $this->fixture();
        $attendance->update(['time_in' => now()->subMinutes(10)]);
        $this->assertSame('present', $service->presence($employee, now())['state']);
        $this->assertSame('blocked', $service->presence($employee, now()->subMinute())['state']);
    }

    public function test_archived_attendance_and_recorded_short_session_block_assignment(): void
    {
        [$wo, $service, $employee, $attendance] = $this->fixture();
        $slot = $service->schedule($wo);
        $attendance->update(['time_out' => now()->addHour()]);
        $this->assertFalse($service->workerOptions($wo, $slot)->firstWhere('id', $employee->hash_id)['can_assign']);
        $attendance->update(['status' => 'absent', 'time_in' => null, 'time_out' => null]);
        $attendance->delete();
        $this->assertSame('blocked', $service->presence($employee, now())['state']);
        $this->assertFalse($service->workerOptions($wo, $slot)->firstWhere('id', $employee->hash_id)['can_assign']);
    }

    public function test_later_shift_is_provisional_and_exposes_selected_shift_times(): void
    {
        [$wo, $service, $employee, $attendance] = $this->fixture();
        $attendance->forceDelete();
        $late = Shift::create(['name' => 'Later safety shift', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        EmployeeShiftAssignment::where('employee_id', $employee->id)->update(['shift_id' => $late->id]);
        $slot = new ProductionSchedule(['scheduled_start' => now()->setTime(15, 0), 'scheduled_end' => now()->setTime(17, 0)]);
        $option = $service->workerOptions($wo, $slot)->firstWhere('id', $employee->hash_id);
        $this->assertTrue($option['can_assign']);
        $this->assertSame('upcoming', $option['schedule_attendance']['state']);
        $this->assertStringContainsString('14:00', $option['shift']['scheduled_start']);
        $this->assertFalse($service->workerOptions($wo, new ProductionSchedule(['scheduled_start' => now()->setTime(12, 0), 'scheduled_end' => now()->setTime(13, 0)]))->firstWhere('id', $employee->hash_id)['can_assign']);
    }

    public function test_immediate_suggestion_prefers_present_replacement_without_changing_approved_crew(): void
    {
        [$wo, $service, $employee, $attendance, $crew] = $this->fixture();
        $attendance->forceDelete();
        $replacement = Employee::factory()->create(['department_id' => $employee->department_id, 'status' => 'active']);
        User::factory()->withRole('employee')->create(['employee_id' => $replacement->id, 'is_active' => true]);
        $shift = Shift::create(['name' => 'Available safety shift', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($replacement->id, $shift->id, now()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        Attendance::create(['employee_id' => $replacement->id, 'date' => now()->toDateString(), 'shift_id' => $shift->id, 'time_in' => now(), 'status' => 'present']);
        $suggestion = $service->suggest($wo, $service->schedule($wo));
        $this->assertSame($replacement->hash_id, $suggestion['members'][0]['id']);
        $this->assertSame('present', $suggestion['members'][0]['schedule_attendance']['state']);
        $this->assertSame($crew->id, $service->currentCrew($wo)->id);
        $this->assertSame($employee->id, $service->currentCrew($wo)->recorder_employee_id);
    }

    public function test_clocked_out_recorder_is_blocked_and_manager_requires_explicit_late_reason(): void
    {
        [$wo, $service, $employee, $attendance, $crew] = $this->fixture();
        $wo->update(['status' => 'in_progress']);
        $attendance->update(['time_out' => now()]);
        try {
            $service->assertCanRecord($employee->user, $wo, $crew->id);
            $this->fail('Clocked-out employee must not record.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Production management', $error->getMessage());
        }
        $manager = User::findOrFail($wo->created_by);
        try {
            $service->assertCanRecord($manager, $wo, $crew->id);
            $this->fail('Management must explain late reporting.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('reason', $error->getMessage());
        }
        $this->assertSame($crew->id, $service->assertCanRecord($manager, $wo, $crew->id, null, 'Reporting goods made before the recorder clocked out.')->id);
        $this->assertSame('present', $attendance->fresh()->status->value);
    }

    public function test_missing_punch_can_use_current_start_attestation_but_explicit_absence_cannot(): void
    {
        [$wo, $service, $employee, $attendance, $crew] = $this->fixture();
        $attendance->forceDelete();
        $wo->update(['status' => 'in_progress']);
        $service->assertPresence($wo, collect([$employee]), User::findOrFail($wo->created_by), [$employee->id]);
        $this->assertSame($crew->id, $service->assertCanRecord($employee->user, $wo, $crew->id)->id);
        Attendance::create(['employee_id' => $employee->id, 'date' => now()->toDateString(), 'shift_id' => $attendance->shift_id, 'status' => 'absent']);
        $this->expectException(BusinessRuleException::class);
        $service->assertCanRecord($employee->user, $wo, $crew->id);
    }

    public function test_previous_presence_confirmation_does_not_cover_a_replacement_slot(): void
    {
        [$wo, $service, $employee, $attendance, $crew] = $this->fixture();
        $attendance->forceDelete();
        $wo->update(['status' => 'in_progress']);
        $service->assertPresence($wo, collect([$employee]), User::findOrFail($wo->created_by), [$employee->id]);
        $old = $service->schedule($wo);
        $old->update(['reserves_capacity' => false]);
        $new = $old->replicate();
        $new->reserves_capacity = true;
        $new->save();
        $this->expectException(BusinessRuleException::class);
        $service->assertCanRecord($employee->user, $wo, $crew->id);
    }

    public function test_expired_slot_blocks_employee_output_but_preserves_management_late_reporting(): void
    {
        [$wo, $service, $employee, $attendance, $crew] = $this->fixture();
        $service->schedule($wo)->update(['scheduled_end' => now()->addMinute()]);
        $wo->update(['status' => 'in_progress']);
        $this->travel(2)->minutes();
        try {
            $service->assertCanRecord($employee->user, $wo, $crew->id);
            $this->fail('Expired slot must not authorize employee output.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Production management', $error->getMessage());
        }
        $this->assertSame($crew->id, $service->assertCanRecord(User::findOrFail($wo->created_by), $wo, $crew->id, null, 'Reporting production finished within the approved slot.')->id);
    }

    public function test_half_day_leave_requires_explicit_hr_review_without_invented_clock_times(): void
    {
        [$wo, $service, $employee] = $this->fixture();
        LeaveRequest::factory()->approved()->create(['employee_id' => $employee->id, 'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(), 'half_day_period' => 'am', 'days' => 0.5]);
        $option = $service->workerOptions($wo, $service->schedule($wo))->firstWhere('id', $employee->hash_id);
        $this->assertFalse($option['can_assign']);
        $this->assertStringContainsString('half-day leave', $option['blocked_reason']);
        $this->assertStringContainsString('HR', $option['blocked_reason']);
        $this->assertSame('blocked', $service->presence($employee, now())['state']);
    }

    public function test_invalid_punch_cannot_be_overridden_by_presence_confirmation(): void
    {
        [$wo, $service, $employee, $attendance] = $this->fixture();
        $attendance->update(['time_in' => now()->subDays(2)]);
        $this->assertSame('blocked', $service->presence($employee, now())['state']);
        $this->expectException(BusinessRuleException::class);
        $service->assertPresence($wo, collect([$employee]), User::findOrFail($wo->created_by), [$employee->id]);
    }

    public function test_http_output_blocks_clocked_out_worker_and_accepts_explained_management_report(): void
    {
        [$wo, $service, $employee, $attendance, $crew] = $this->fixture();
        $manager = User::findOrFail($wo->created_by);
        app(WorkOrderService::class)->start($wo, $manager->id, ['expected_crew_id' => $crew->id]);
        $this->passInProcessQc($wo);
        $attendance->update(['time_out' => now()]);
        $defect = DefectType::create(['code' => 'ATT-SCRAP', 'name' => 'Recorded earlier scrap', 'is_active' => true]);
        $payload = ['crew_id' => $crew->hash_id, 'good_count' => 0, 'reject_count' => 1,
            'defects' => [['defect_type_id' => $defect->hash_id, 'count' => 1]]];
        $this->actingAs($employee->user, 'sanctum')->postJson('/api/v1/production/my-work-orders/'.$wo->hash_id.'/outputs', $payload)
            ->assertUnprocessable();
        $this->assertSame(0, $wo->outputs()->count());
        $reason = 'Recording scrap produced before the recorder clocked out.';
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/outputs', $payload + ['recording_reason' => $reason])
            ->assertCreated();
        $this->assertSame(1, $wo->outputs()->count());
        $this->assertSame($reason, $wo->outputs()->first()->recording_reason);
        $this->assertSame($manager->id, $wo->outputs()->first()->recorded_by);
        $this->assertSame('present', $attendance->fresh()->status->value);
    }
}
