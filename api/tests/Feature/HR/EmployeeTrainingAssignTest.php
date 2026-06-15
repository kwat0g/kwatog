<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTrainingAssignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function makeEmployee(): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'WHS'], ['name' => 'Warehouse']);
        return Employee::factory()->create(['department_id' => $dept->id]);
    }

    public function test_admin_can_assign_training_to_employee(): void
    {
        $emp = $this->makeEmployee();
        $t   = Training::create(['name' => 'Forklift', 'validity_months' => 12, 'is_active' => true]);

        $resp = $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/trainings", [
                'training_id'   => $t->hash_id,
                'scheduled_for' => '2026-07-01',
            ]);

        $resp->assertCreated()
            ->assertJsonPath('data.status', EmployeeTrainingStatus::Scheduled->value)
            ->assertJsonPath('data.scheduled_for', '2026-07-01');
        $this->assertDatabaseHas('employee_trainings', [
            'employee_id' => $emp->id, 'training_id' => $t->id,
        ]);
    }

    public function test_duplicate_open_assignment_is_rejected(): void
    {
        $emp = $this->makeEmployee();
        $t   = Training::create(['name' => 'Forklift', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/trainings", [
                'training_id' => $t->hash_id, 'scheduled_for' => '2026-07-01',
            ])->assertCreated();

        $resp = $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/trainings", [
                'training_id' => $t->hash_id, 'scheduled_for' => '2026-07-01',
            ]);

        $resp->assertStatus(422);
    }

    public function test_admin_can_complete_training(): void
    {
        $emp = $this->makeEmployee();
        $t   = Training::create(['name' => 'Forklift', 'validity_months' => 12, 'is_active' => true]);
        $rec = EmployeeTraining::create([
            'employee_id' => $emp->id, 'training_id' => $t->id,
            'scheduled_for' => '2026-06-01',
        ]);

        $resp = $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/employee-trainings/{$rec->hash_id}/complete", [
                'completed_at' => '2026-06-01',
            ]);

        $resp->assertOk()
            ->assertJsonPath('data.status', EmployeeTrainingStatus::Completed->value);
        $this->assertNotNull($rec->refresh()->expires_at);
    }

    public function test_admin_can_cancel_training(): void
    {
        $emp = $this->makeEmployee();
        $t   = Training::create(['name' => 'Forklift', 'is_active' => true]);
        $rec = EmployeeTraining::create([
            'employee_id' => $emp->id, 'training_id' => $t->id,
            'scheduled_for' => '2026-06-01',
        ]);

        $resp = $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/employee-trainings/{$rec->hash_id}/cancel", [
                'reason' => 'Schedule conflict',
            ]);

        $resp->assertOk()
            ->assertJsonPath('data.status', EmployeeTrainingStatus::Cancelled->value);
    }
}
