<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Models\Training;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function departmentHead(Employee $employee): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $employee->id,
        ]);
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

    public function test_unscheduled_duplicate_assignment_is_rejected(): void
    {
        $emp = $this->makeEmployee();
        $training = Training::create(['name' => 'Safety induction', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/trainings", [
                'training_id' => $training->hash_id,
            ])->assertCreated();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$emp->hash_id}/trainings", [
                'training_id' => $training->hash_id,
            ])->assertStatus(422);
    }

    public function test_training_lifecycle_rejects_recompletion_and_cancelling_completed_record(): void
    {
        $emp = $this->makeEmployee();
        $training = Training::create(['name' => 'Forklift', 'validity_months' => 12, 'is_active' => true]);
        $record = EmployeeTraining::create([
            'employee_id' => $emp->id,
            'training_id' => $training->id,
            'scheduled_for' => '2026-06-01',
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/employee-trainings/{$record->hash_id}/complete", [
                'completed_at' => '2026-06-01',
            ])->assertOk();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/employee-trainings/{$record->hash_id}/complete", [
                'completed_at' => '2026-06-02',
            ])->assertStatus(422);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/employee-trainings/{$record->hash_id}/cancel", [])
            ->assertStatus(422);

        $this->assertDatabaseHas('employee_trainings', [
            'id' => $record->id,
            'status' => EmployeeTrainingStatus::Completed->value,
        ]);
    }

    public function test_department_head_cannot_read_another_department_training_or_skill_rows(): void
    {
        $headDepartment = Department::firstOrCreate(['code' => 'HEAD'], ['name' => 'Head department']);
        $otherDepartment = Department::firstOrCreate(['code' => 'OTHER'], ['name' => 'Other department']);
        $headEmployee = Employee::factory()->create(['department_id' => $headDepartment->id]);
        $otherEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $head = $this->departmentHead($headEmployee);

        $training = Training::create(['name' => 'Restricted training', 'is_active' => true]);
        EmployeeTraining::create([
            'employee_id' => $otherEmployee->id,
            'training_id' => $training->id,
            'scheduled_for' => '2026-06-01',
        ]);
        $skill = Skill::create(['name' => 'Restricted skill', 'is_active' => true]);
        EmployeeSkill::create([
            'employee_id' => $otherEmployee->id,
            'skill_id' => $skill->id,
            'proficiency_level' => 'competent',
            'acquired_date' => '2026-06-01',
        ]);

        $this->actingAs($head)
            ->getJson("/api/v1/hr/employees/{$otherEmployee->hash_id}/trainings")
            ->assertNotFound();
        $this->actingAs($head)
            ->getJson("/api/v1/hr/employees/{$otherEmployee->hash_id}/skills")
            ->assertNotFound();
    }

    public function test_revoked_skill_is_restored_when_reassigned(): void
    {
        $employee = $this->makeEmployee();
        $skill = Skill::create(['name' => 'Welding', 'is_active' => true]);
        $payload = [
            'skill_id' => $skill->hash_id,
            'proficiency_level' => 'competent',
            'acquired_date' => '2026-06-01',
        ];

        $first = $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$employee->hash_id}/skills", $payload)
            ->assertCreated();
        $recordId = $first->json('data.id');
        $record = EmployeeSkill::query()->whereKey(EmployeeSkill::decodeHash($recordId))->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/hr/employee-skills/{$record->hash_id}")
            ->assertNoContent();
        $this->assertSoftDeleted('employee_skills', ['id' => $record->id]);

        $second = $this->actingAs($this->admin())
            ->postJson("/api/v1/hr/employees/{$employee->hash_id}/skills", $payload)
            ->assertCreated();
        $this->assertSame($recordId, $second->json('data.id'));
        $this->assertDatabaseHas('employee_skills', ['id' => $record->id, 'deleted_at' => null]);
    }

    public function test_certificate_upload_is_private_metadata_and_download_is_scoped(): void
    {
        Storage::fake('local');
        $employee = $this->makeEmployee();
        $training = Training::create(['name' => 'Certified training', 'is_active' => true]);
        $record = EmployeeTraining::create([
            'employee_id' => $employee->id,
            'training_id' => $training->id,
            'scheduled_for' => '2026-06-01',
        ]);
        $file = UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf');

        $response = $this->actingAs($this->admin())->call(
            'POST',
            "/api/v1/hr/employee-trainings/{$record->hash_id}/complete",
            ['_method' => 'PATCH', 'completed_at' => '2026-06-01'],
            [],
            ['certificate' => $file],
        );

        $response->assertOk()
            ->assertJsonPath('data.certificate.name', 'certificate.pdf')
            ->assertJsonMissingPath('data.certificate_path');
        $record->refresh();
        Storage::disk('local')->assertExists($record->certificate_path);

        $this->actingAs($this->admin())
            ->get("/api/v1/hr/employee-trainings/{$record->hash_id}/certificate")
            ->assertOk();
    }
}
