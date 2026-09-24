<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Services\EmployeeDocumentService;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\EmployeeTrainingService;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use App\Modules\HR\Services\SkillService;
use App\Modules\HR\Services\TrainingService;
use App\Modules\HR\Support\EmployeeStateMachine;
use App\Modules\HR\Enums\EmployeeStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class HRAuditRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role, ?int $employeeId = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'employee_id' => $employeeId,
        ]);
    }

    public function test_bank_details_cannot_use_generic_employee_update(): void
    {
        $employee = Employee::factory()->create(['bank_account_no' => '11112222']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('profile-change workflow');
        app(EmployeeService::class)->update($employee, ['bank_account_no' => '99998888']);
    }

    public function test_profile_changes_are_validated_and_review_actions_are_row_scoped(): void
    {
        $departmentA = Department::factory()->create(['code' => 'A'.substr(uniqid(), -5)]);
        $departmentB = Department::factory()->create(['code' => 'B'.substr(uniqid(), -5)]);
        $employeeA = Employee::factory()->create(['department_id' => $departmentA->id]);
        $employeeB = Employee::factory()->create(['department_id' => $departmentB->id]);
        $reviewerB = $this->user('department_head', $employeeB->id);
        $requester = $this->user('employee');
        $service = app(ProfileUpdateRequestService::class);

        $this->expectException(ValidationException::class);
        $service->submit($employeeA, $requester, ['mobile_number' => 'not-a-phone']);
    }

    public function test_department_head_cannot_mutate_another_department_profile_request(): void
    {
        $departmentA = Department::factory()->create(['code' => 'C'.substr(uniqid(), -5)]);
        $departmentB = Department::factory()->create(['code' => 'D'.substr(uniqid(), -5)]);
        $employeeA = Employee::factory()->create(['department_id' => $departmentA->id]);
        $employeeB = Employee::factory()->create(['department_id' => $departmentB->id]);
        $reviewerB = $this->user('department_head', $employeeB->id);
        $requester = $this->user('employee');
        $request = app(ProfileUpdateRequestService::class)->submit(
            $employeeA,
            $requester,
            ['mobile_number' => '09170000000'],
        );

        $this->expectException(ModelNotFoundException::class);
        app(ProfileUpdateRequestService::class)->approve($request, $reviewerB);
    }

    public function test_department_head_can_manage_competence_in_their_department(): void
    {
        $department = Department::factory()->create(['code' => 'F'.substr(uniqid(), -5)]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $head = $this->user('department_head', $employee->id);
        $training = Training::create(['name' => 'Dept training', 'is_active' => true]);

        $record = app(EmployeeTrainingService::class)->assign($employee, $training, null, $head);

        $this->assertSame($employee->id, $record->employee_id);
    }

    public function test_bank_profile_change_requires_hr_then_finance(): void
    {
        $department = Department::factory()->create(['code' => 'E'.substr(uniqid(), -5)]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $hr = $this->user('department_head', $employee->id);
        $finance = $this->user('finance_officer');
        $requester = $this->user('employee');
        $service = app(ProfileUpdateRequestService::class);
        $request = $service->submit($employee, $requester, [
            'bank_name' => 'BDO',
            'bank_account_no' => '1234567890',
        ]);

        $service->approve($request, $hr);
        $this->assertSame('pending_finance', $request->refresh()->status);
        $service->financeApprove($request->fresh(), $finance);

        $this->assertSame('approved', $request->refresh()->status);
        $this->assertSame('1234567890', $employee->refresh()->bank_account_no);
    }

    public function test_archiving_and_restoring_employee_restores_archive_disabled_account(): void
    {
        $employee = Employee::factory()->create();
        $user = $this->user('employee', $employee->id);
        $service = app(EmployeeService::class);

        $service->delete($employee);
        $this->assertFalse($user->refresh()->is_active);

        $service->restore(Employee::withTrashed()->findOrFail($employee->id));
        $this->assertTrue($user->refresh()->is_active);
        $this->assertNull(Employee::withTrashed()->findOrFail($employee->id)->deleted_at);
    }

    public function test_soft_deleted_document_can_be_restored_and_downloaded(): void
    {
        Storage::fake('local');
        $employee = Employee::factory()->create();
        $admin = $this->user('system_admin');
        $path = 'employee-documents/'.$employee->id.'/proof.pdf';
        Storage::disk('local')->put($path, 'pdf');
        $document = EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type' => 'contract',
            'file_name' => 'proof.pdf',
            'file_path' => $path,
            'uploaded_at' => now(),
        ]);
        $service = app(EmployeeDocumentService::class);

        $service->delete($employee, $document, $admin);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $service->restore($employee, $document->fresh(['employee']), $admin);

        $this->assertSame(Storage::disk('local')->path($path), $service->download($document->fresh(['employee']), $admin));
    }

    public function test_active_false_filters_return_inactive_master_data(): void
    {
        $activeSkill = Skill::create(['name' => 'Active skill', 'is_active' => true]);
        $inactiveSkill = Skill::create(['name' => 'Inactive skill', 'is_active' => false]);
        $activeTraining = Training::create(['name' => 'Active training', 'is_active' => true]);
        $inactiveTraining = Training::create(['name' => 'Inactive training', 'is_active' => false]);

        $skills = app(SkillService::class)->list(['active' => 'false'])->getCollection();
        $trainings = app(TrainingService::class)->list(['active' => 'false'])->getCollection();

        $this->assertTrue($skills->contains('id', $inactiveSkill->id));
        $this->assertFalse($skills->contains('id', $activeSkill->id));
        $this->assertTrue($trainings->contains('id', $inactiveTraining->id));
        $this->assertFalse($trainings->contains('id', $activeTraining->id));
    }

    public function test_generic_state_machine_cannot_enter_terminal_status(): void
    {
        $employee = Employee::factory()->create(['status' => EmployeeStatus::Active]);

        $this->expectException(BusinessRuleException::class);
        app(EmployeeStateMachine::class)->transition($employee, EmployeeStatus::Resigned);
    }

    public function test_training_catalog_cannot_be_archived_after_assignment(): void
    {
        $training = Training::create(['name' => 'Assigned training', 'is_active' => true]);
        $employee = Employee::factory()->create();
        EmployeeTraining::create([
            'employee_id' => $employee->id,
            'training_id' => $training->id,
            'scheduled_for' => '2026-01-01',
        ]);

        $this->expectException(BusinessRuleException::class);
        app(TrainingService::class)->delete($training);
    }
}
