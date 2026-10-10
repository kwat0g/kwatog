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
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceWorkRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_detail_labels_elapsed_maintenance_as_provisional_until_repair_submission(): void
    {
        $department = Department::query()->firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance & Engineering']);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $technician = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'maintenance_tech')->value('id'),
            'employee_id' => $employee->id,
        ]);
        $machine = Machine::factory()->create();
        $order = MaintenanceWorkOrder::query()->create([
            'mwo_number' => 'MWO-METRIC-'.uniqid(), 'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id, 'type' => 'corrective', 'priority' => 'high',
            'description' => 'Inspect', 'status' => 'in_progress', 'created_by' => $technician->id,
            'started_at' => '2026-10-09 12:20:00', 'verification_required' => true,
            'assigned_to' => $employee->id,
        ]);
        $order->forceFill(['created_at' => '2026-10-09 12:00:00'])->save();
        Carbon::setTestNow('2026-10-09 13:00:00');
        try {
            $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
            $this->actingAs($technician)->getJson($url)->assertOk()
                ->assertJsonPath('data.response_minutes', 20)
                ->assertJsonPath('data.maintenance_elapsed_minutes', 40)
                ->assertJsonPath('data.maintenance_elapsed_provisional', true);

            $order->forceFill(['repair_submitted_at' => '2026-10-09 12:50:00'])->save();
            $this->actingAs($technician)->getJson($url)->assertOk()
                ->assertJsonPath('data.technician_repair_minutes', 30)
                ->assertJsonPath('data.maintenance_elapsed_minutes', 30)
                ->assertJsonPath('data.maintenance_elapsed_provisional', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_assigned_technician_records_work_before_completing_order(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', config('app.timezone')));
        $department = Department::query()->firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance & Engineering']);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $shift = Shift::create([
            'name' => 'Maintenance work record shift', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => '2026-01-01', 'working_days' => [1, 2, 3, 4, 5],
        ]);
        Attendance::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour(),
        ]);
        $technician = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'maintenance_tech')->value('id'),
            'employee_id' => $employee->id,
        ]);
        $other = User::factory()->create([
            'role_id' => $technician->role_id,
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $machine = Machine::factory()->create(['status' => 'idle']);
        $order = MaintenanceWorkOrder::query()->create([
            'mwo_number' => 'MWO-WORK-'.uniqid(), 'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id, 'type' => 'preventive', 'priority' => 'medium',
            'description' => 'Inspect machine', 'status' => 'open', 'created_by' => $technician->id,
        ]);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";

        $this->actingAs($technician)->patchJson($url.'/start')->assertOk();
        $this->actingAs($technician)->postJson($url.'/logs', ['description' => 'Checked guards.'])->assertCreated();
        $this->actingAs($technician)->getJson($url)->assertOk()
            ->assertJsonPath('data.closure_readiness.can_close_work', false)
            ->assertJsonPath('data.closure_readiness.latest_work_result', null);
        $this->actingAs($technician)->patchJson($url.'/complete')->assertUnprocessable()->assertJsonValidationErrors('work_record');
        $this->actingAs($technician)->postJson($url.'/work-records', ['finding' => 'Guards intact'])
            ->assertUnprocessable()->assertJsonValidationErrors(['action_taken', 'result']);
        $this->actingAs($other)->postJson($url.'/work-records', [
            'finding' => 'Guards intact', 'action_taken' => 'Cleaned guards', 'result' => 'passed',
        ])->assertForbidden();

        $this->actingAs($technician)->postJson($url.'/work-records', [
            'finding' => 'Guard still loose', 'action_taken' => 'Tightened guard', 'result' => 'needs_follow_up',
        ])->assertCreated();
        $this->actingAs($technician)->getJson($url)->assertOk()
            ->assertJsonPath('data.closure_readiness.can_close_work', false)
            ->assertJsonPath('data.closure_readiness.latest_work_result', 'needs_follow_up');
        $this->actingAs($technician)->patchJson($url.'/complete')
            ->assertUnprocessable()->assertJsonValidationErrors('work_record');

        $this->actingAs($technician)->postJson($url.'/work-records', [
            'finding' => 'Guards intact', 'action_taken' => 'Cleaned guards', 'result' => 'passed',
        ])->assertCreated()->assertJsonPath('data.finding', 'Guards intact');
        $this->actingAs($technician)->getJson($url)->assertOk()
            ->assertJsonPath('data.logs.3.finding', 'Guards intact')
            ->assertJsonPath('data.logs.3.action_taken', 'Cleaned guards')
            ->assertJsonPath('data.logs.3.result', 'passed')
            ->assertJsonPath('data.closure_readiness.can_close_work', true)
            ->assertJsonPath('data.closure_readiness.latest_work_result', 'passed');
        $this->actingAs($technician)->postJson($url.'/work-records', [
            'finding' => 'Guard shifted during retest', 'action_taken' => 'Investigating', 'result' => 'in_progress',
        ])->assertCreated();
        $this->actingAs($technician)->getJson($url)->assertOk()
            ->assertJsonPath('data.closure_readiness.can_close_work', false)
            ->assertJsonPath('data.closure_readiness.latest_work_result', 'in_progress');
        $this->actingAs($technician)->patchJson($url.'/complete')
            ->assertUnprocessable()->assertJsonValidationErrors('work_record');
        $this->actingAs($technician)->postJson($url.'/work-records', [
            'finding' => 'Guard secured', 'action_taken' => 'Retested under load', 'result' => 'passed',
        ])->assertCreated();
        $this->actingAs($technician)->postJson($url.'/logs', ['description' => 'Final check recorded.'])->assertCreated();
        $this->actingAs($technician)->getJson($url)->assertOk()
            ->assertJsonPath('data.closure_readiness.can_close_work', true)
            ->assertJsonPath('data.closure_readiness.latest_work_result', 'passed');
        $this->actingAs($technician)->patchJson($url.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
    }
}
