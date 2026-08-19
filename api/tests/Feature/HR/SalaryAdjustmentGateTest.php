<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\SalaryAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-03 — salary changes must pass a maker-checker approval gate.
 * Direct employee edits can no longer change pay; only a fully-approved
 * SalaryAdjustment applies a new salary.
 */
class SalaryAdjustmentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    /** The hole: editing an employee must NOT change pay directly. */
    public function test_direct_employee_update_cannot_change_salary(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00']);

        app(EmployeeService::class)->update($employee, [
            'basic_monthly_salary' => '99000.00',
            'semi_monthly_rate'    => '5000.00',
        ]);

        $this->assertSame('20000.00', (string) $employee->fresh()->basic_monthly_salary);
    }

    /** Requesting an adjustment defers the write — employee unchanged while pending. */
    public function test_request_does_not_apply_until_approved(): void
    {
        $hr = $this->userWithRole('hr_officer');
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00']);

        $adj = app(SalaryAdjustmentService::class)->request($employee, [
            'to_basic_monthly_salary' => '25000.00',
            'effective_date'          => '2026-08-01',
            'reason'                  => 'Annual merit increase',
        ], $hr);

        $this->assertSame('pending', $adj->status->value);
        $this->assertSame('20000.00', (string) $employee->fresh()->basic_monthly_salary);
    }

    /** Full approval applies the new salary + writes an effective-dated history row. */
    public function test_full_approval_applies_salary(): void
    {
        $svc = app(SalaryAdjustmentService::class);
        $hr = $this->userWithRole('hr_officer');
        $checker = $this->userWithRole('production_manager');
        $approver = $this->userWithRole('system_admin');
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00']);

        $adj = $svc->request($employee, [
            'to_basic_monthly_salary' => '25000.00',
            'effective_date'          => '2026-08-01',
            'reason'                  => 'Merit',
        ], $hr);

        $svc->approve($adj, $checker);          // step 1 — Checked by
        $adj = $svc->approve($adj, $approver);  // step 2 — Approved by → applies

        $this->assertSame('approved', $adj->fresh()->status->value);
        $this->assertSame('25000.00', (string) $employee->fresh()->basic_monthly_salary);
        $this->assertDatabaseHas('employee_salary_history', [
            'employee_id'          => $employee->id,
            'basic_monthly_salary' => '25000.00',
            'effective_date'       => '2026-08-01',
        ]);
    }

    /** Rejection leaves salary untouched. */
    public function test_rejection_leaves_salary_unchanged(): void
    {
        $svc = app(SalaryAdjustmentService::class);
        $hr = $this->userWithRole('hr_officer');
        $checker = $this->userWithRole('production_manager');
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00']);

        $adj = $svc->request($employee, [
            'to_basic_monthly_salary' => '25000.00',
            'effective_date'          => '2026-08-01',
        ], $hr);

        $svc->reject($adj, $checker, 'Budget not approved');

        $this->assertSame('rejected', $adj->fresh()->status->value);
        $this->assertSame('20000.00', (string) $employee->fresh()->basic_monthly_salary);
    }

    /** The requester cannot approve their own adjustment (SoD, via requested_by). */
    public function test_requester_cannot_self_approve(): void
    {
        $svc = app(SalaryAdjustmentService::class);
        // hr_officer who ALSO holds the checker role would still be blocked as submitter.
        $hr = $this->userWithRole('production_manager');
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00']);

        $adj = $svc->request($employee, [
            'to_basic_monthly_salary' => '25000.00',
            'effective_date'          => '2026-08-01',
        ], $hr);

        $this->expectException(ForbiddenActionException::class);
        $this->expectExceptionMessage('cannot act on a record you submitted');
        $svc->approve($adj, $hr);
    }
}
