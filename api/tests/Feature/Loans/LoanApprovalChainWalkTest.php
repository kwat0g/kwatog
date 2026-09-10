<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Common\Services\ApprovalService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-10 approval-chain audit — the cash_advance and company_loan chains
 * named step roles (production_manager step 2, vice_president final steps)
 * that held neither the route permission nor any row scope, so a submitted
 * loan could never advance past step 1 and the board hid the stall. Pins the
 * full walk: every step role can see and approve its step, and the final
 * approval activates the loan.
 */
class LoanApprovalChainWalkTest extends TestCase
{
    use RefreshDatabase;

    private LoanService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            PositionSeeder::class,
            WorkflowSeeder::class,
        ]);

        // LoanService::request reads live settings; migrations that seed them
        // don't run under RefreshDatabase's subset used here.
        $settings = app(SettingsService::class);
        foreach (['company_loan', 'cash_advance'] as $type) {
            $settings->set("loans.{$type}.annual_interest_rate", '0', 'loans');
            $settings->set("loans.{$type}.max_salary_multiplier", '1.0', 'loans');
        }
        $settings->set('loans.max_pay_periods', '60', 'loans');

        $this->service = app(LoanService::class);
    }

    /** Build a pending loan for an employee through the real service. */
    private function pendingLoanFor(Employee $employee, string $type): EmployeeLoan
    {
        return $this->service->request(
            $employee->id,
            \App\Modules\Loans\Enums\LoanType::from($type),
            ['principal' => '5000.00', 'pay_periods' => 5, 'purpose' => 'Chain walk test'],
        )->fresh(['employee']);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'employee_id' => Employee::factory()->create()->id,
        ]);
    }

    public function test_cash_advance_chain_advances_through_department_head_to_vp(): void
    {
        $requester = Employee::factory()->create();
        $loan = $this->pendingLoanFor($requester, 'cash_advance');

        $deptHeadUser = $this->userWithRole('department_head');
        $deptHeadUser->employee->update(['department_id' => $requester->department_id]);

        $finance = $this->userWithRole('finance_officer');
        $vp = $this->userWithRole('vice_president');

        // Step 1: department head.
        $this->actingAs($deptHeadUser)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", ['remarks' => 'dept ok'])
            ->assertOk();
        $this->assertFalse(app(ApprovalService::class)->isFullyApproved($loan->fresh()), 'later steps must still be pending');

        // Step 2: finance.
        $this->actingAs($finance)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve")
            ->assertOk();
        $this->assertFalse(app(ApprovalService::class)->isFullyApproved($loan->fresh()), 'VP step must still be pending');

        // Step 3: VP — the step that was dead before the fix.
        $this->actingAs($vp)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", ['remarks' => 'final'])
            ->assertOk();

        $this->assertTrue(app(ApprovalService::class)->isFullyApproved($loan->fresh()));
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_company_loan_chain_advances_through_manager_to_vp(): void
    {
        $requester = Employee::factory()->create();
        $loan = $this->pendingLoanFor($requester, 'company_loan');

        // Step 1: department head (the requester's own department head).
        $deptHeadUser = $this->userWithRole('department_head');
        $deptHeadUser->employee->update(['department_id' => $requester->department_id]);
        $this->actingAs($deptHeadUser)->patchJson("/api/v1/loans/{$loan->hash_id}/approve")->assertOk();

        // Step 2: production manager — previously invisible AND unauthorized.
        $manager = $this->userWithRole('production_manager');
        $this->actingAs($manager)->patchJson("/api/v1/loans/{$loan->hash_id}/approve")->assertOk();

        // Step 3: finance.
        $finance = $this->userWithRole('finance_officer');
        $this->actingAs($finance)->patchJson("/api/v1/loans/{$loan->hash_id}/approve")->assertOk();

        // Step 4: VP — previously dead.
        $vp = $this->userWithRole('vice_president');
        $this->actingAs($vp)->patchJson("/api/v1/loans/{$loan->hash_id}/approve")->assertOk();

        $this->assertTrue(app(ApprovalService::class)->isFullyApproved($loan->fresh()));
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_loan_chain_participant_can_see_and_decide_the_row(): void
    {
        // Row visibility (PS-01 class): the VP's policy scope must surface a
        // cash_advance waiting on their step, and canDecide must agree.
        $requester = Employee::factory()->create();
        $loan = $this->pendingLoanFor($requester, 'cash_advance');
        $vp = $this->userWithRole('vice_president');

        $visible = app(LoanAccessPolicy::class)->visibleTo(
            EmployeeLoan::query(),
            $vp,
        )->pluck('id')->all();

        $this->assertContains($loan->id, $visible, 'VP row scope must surface a cash_advance waiting on the VP step');

        $this->assertTrue(app(LoanAccessPolicy::class)->canView($vp, $loan->fresh()));
        $this->assertTrue(app(LoanAccessPolicy::class)->canDecide($vp, $loan->fresh()));
    }

    public function test_vp_cannot_approve_before_their_step(): void
    {
        // Role-match ordering still holds: VP cannot jump the queue at step 1.
        // The route permission now passes, so the refusal comes from
        // ApprovalService (ForbiddenActionException → 403), not the
        // controller's row-scope guard (BusinessRuleException → 422).
        $requester = Employee::factory()->create();
        $loan = $this->pendingLoanFor($requester, 'cash_advance');
        $vp = $this->userWithRole('vice_president');

        $this->actingAs($vp)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve")
            ->assertStatus(403);
    }

    public function test_requester_cannot_approve_own_loan(): void
    {
        // Self-approval guard via a requester who WOULD pass every other
        // gate: a department head holds loans.approve, is their own
        // department's step-1 approver, and can see their own row — but
        // approvalSubmitterId (the linked user account) makes the service
        // refuse. Plain employees are refused earlier, at the route
        // middleware, which is why the guard is proven with this actor.
        $headUser = $this->userWithRole('department_head');

        $loan = $this->service->request(
            $headUser->employee_id,
            \App\Modules\Loans\Enums\LoanType::CashAdvance,
            ['principal' => '3000.00', 'pay_periods' => 3],
        );

        $this->actingAs($headUser)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve")
            ->assertStatus(403);
    }
}
