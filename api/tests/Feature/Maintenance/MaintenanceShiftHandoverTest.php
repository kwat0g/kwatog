<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceShiftHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 15:40:00', config('app.timezone')));
    }

    public function test_shift_end_suggests_present_replacement_once_without_changing_assignee(): void
    {
        $supervisor = $this->actor('maintenance_supervisor', 'SUP-1');
        $outgoing = $this->actor('maintenance_tech', 'TECH-1', '08:00', '16:00');
        $incoming = $this->actor('maintenance_tech', 'TECH-2', '14:00', '22:00');
        $order = $this->order($supervisor, $outgoing);

        $this->artisan('maintenance:check-assignment')->assertSuccessful();
        $this->artisan('maintenance:check-assignment')->assertSuccessful();

        $notices = DB::table('notifications')->where('type', 'maintenance.shift_handover')->get();
        $this->assertCount(1, $notices);
        $this->assertSame($supervisor->id, $notices[0]->notifiable_id);
        $data = json_decode($notices[0]->data, true);
        $this->assertStringContainsString($incoming->employee->full_name, $data['message']);
        $this->assertSame(MaintenanceWorkOrder::class, $data['subject_type']);
        $this->assertSame((string) $order->id, $data['subject_id']);
        $this->assertSame($outgoing->employee_id, $order->fresh()->assigned_to);
    }

    public function test_pending_verification_and_absent_replacement_do_not_suggest_handover(): void
    {
        $supervisor = $this->actor('maintenance_supervisor', 'SUP-2');
        $outgoing = $this->actor('maintenance_tech', 'TECH-3', '08:00', '16:00');
        $replacement = $this->actor('maintenance_tech', 'TECH-4', '14:00', '22:00');
        Attendance::where('employee_id', $replacement->employee_id)->update(['status' => 'absent']);
        $order = $this->order($supervisor, $outgoing);

        $this->artisan('maintenance:check-assignment')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'maintenance.shift_handover')->count());

        Attendance::where('employee_id', $replacement->employee_id)->update(['status' => 'present']);
        $order->forceFill(['repair_submitted_at' => now()])->save();
        $this->artisan('maintenance:check-assignment')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'maintenance.shift_handover')->count());
    }

    public function test_previous_day_night_shift_proposes_current_shift_replacement(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 05:40:00', config('app.timezone')));
        $supervisor = $this->actor('maintenance_supervisor', 'SUP-3');
        $outgoing = $this->actor('maintenance_tech', 'TECH-5', '22:00', '06:00');
        $incoming = $this->actor('maintenance_tech', 'TECH-6', '04:00', '12:00');
        EmployeeShiftAssignment::where('employee_id', $outgoing->employee_id)->update(['working_days' => [5]]);
        Attendance::where('employee_id', $outgoing->employee_id)->update([
            'date' => '2026-10-09',
            'time_in' => Carbon::parse('2026-10-09 22:00:00', config('app.timezone')),
        ]);
        EmployeeShiftAssignment::where('employee_id', $incoming->employee_id)->update(['working_days' => [6]]);
        $order = $this->order($supervisor, $outgoing);

        $this->artisan('maintenance:check-assignment')->assertSuccessful();

        $this->assertSame($outgoing->employee_id, $order->fresh()->assigned_to);
        $this->assertSame(1, DB::table('notifications')->where('type', 'maintenance.shift_handover')->count());
    }

    private function actor(string $roleSlug, string $employeeNo, ?string $shiftStart = null, ?string $shiftEnd = null): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create([
            'employee_no' => $employeeNo,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        if ($shiftStart !== null) {
            $shift = Shift::create(['name' => $employeeNo.' shift', 'start_time' => $shiftStart,
                'end_time' => $shiftEnd, 'is_active' => true]);
            EmployeeShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
                'effective_date' => '2026-10-01', 'working_days' => [1, 2, 3, 4, 5]]);
            Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
                'date' => now()->toDateString(), 'status' => 'present',
                'time_in' => Carbon::parse(now()->toDateString().' '.$shiftStart, config('app.timezone'))]);
        }

        return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true]);
    }

    private function order(User $creator, User $outgoing): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number' => 'HANDOVER-'.uniqid(),
            'maintainable_type' => 'machine',
            'maintainable_id' => Machine::factory()->create(['status' => 'maintenance'])->id,
            'type' => 'corrective',
            'priority' => 'high',
            'description' => 'Long-running repair',
            'status' => 'in_progress',
            'assigned_to' => $outgoing->employee_id,
            'started_at' => now()->subHours(3),
            'created_by' => $creator->id,
        ]);
    }
}
