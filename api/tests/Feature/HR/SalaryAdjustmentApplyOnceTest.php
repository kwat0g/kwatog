<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Services\SalaryAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on salary adjustments (money): approve()'s "apply once" guard
 * (`applied_at !== null`) ran on the *passed* model, so two concurrent
 * approvers of the final step of a multi-step chain could both pass it and
 * double-apply the raise — duplicate salary-history + employment-history rows.
 * The fix locks the adjustment row before applying, so the guard serializes.
 * This pins the invariant: a full 2-step approval applies exactly once, and a
 * repeated approve is blocked without a second history row.
 */
class SalaryAdjustmentApplyOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, WorkflowSeeder::class]);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    public function test_full_approval_applies_salary_change_exactly_once(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => '30000.00']);
        $requester = $this->user('hr_officer');
        $checker = $this->user('production_manager');
        $finalApprover = $this->user('system_admin');

        $svc = app(SalaryAdjustmentService::class);
        $adjustment = $svc->request($employee, [
            'to_basic_monthly_salary' => '35000.00',
            'effective_date'          => '2026-09-01',
            'reason'                  => 'Promotion',
        ], $requester);

        // Step 1 (production_manager) — not yet fully approved, nothing applied.
        $svc->approve(SalaryAdjustment::find($adjustment->id), $checker);
        $this->assertNull($adjustment->refresh()->applied_at);

        // Step 2 (system_admin) — fully approved → applied exactly once.
        $svc->approve(SalaryAdjustment::find($adjustment->id), $finalApprover);
        $this->assertNotNull($adjustment->refresh()->applied_at);
        $this->assertSame('35000.00', $employee->refresh()->basic_monthly_salary);
        $this->assertSame(1, EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->count());

        // A repeated approve must not double-apply.
        try {
            $svc->approve(SalaryAdjustment::find($adjustment->id), $finalApprover);
            $this->fail('A repeated approve must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('pending', strtolower($e->getMessage()));
        }

        $this->assertSame(1, EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->count());
    }
}
