<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\WorkflowDefinition;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-10 approval-chain audit — drift guard.
 *
 * A seeded workflow step names a ROLE; the act endpoint gates on a
 * PERMISSION. Nothing structural links the two, so a chain whose step role
 * lost (or never received) its route permission stalls invisibly: the board
 * badge counts the card, the module hides the row, nothing ever advances.
 * That exact defect shipped in three workflows (loans steps 2–4, salary
 * adjustment step 2) on top of the original PR one. This test re-derives the
 * contract for EVERY active workflow from the route map, so the next chain
 * added without grants fails here instead of in production.
 */
class ApprovalChainRolePermissionDriftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * workflow_type => permission(s) accepted by its act route. Mirrors the
     * `permission:` middleware on the approve endpoint. A chain whose step
     * roles hold NONE of these cannot advance — the PS-01 stall class.
     *
     * @var array<string, array<int, string>>
     */
    private const ACT_ROUTE_PERMISSIONS = [
        'leave_request' => ['leave.approve_dept', 'leave.approve_hr'],
        'overtime_request' => ['attendance.ot.approve'],
        'cash_advance' => ['loans.approve'],
        'company_loan' => ['loans.approve'],
        'purchase_request' => ['purchasing.pr.approve'],
        'purchase_order' => ['purchasing.po.approve'],
        'bill_payment' => ['accounting.bills.pay'],
        'salary_adjustment' => ['hr.salary_adjustments.act'],
        'return_request' => ['return_management.approve'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            PositionSeeder::class,
            WorkflowSeeder::class,
        ]);
    }

    public function test_every_active_workflow_step_role_holds_an_act_route_permission(): void
    {
        $workflows = WorkflowDefinition::query()->where('is_active', true)->get();

        $this->assertGreaterThanOrEqual(7, $workflows->count(), 'Expected the wired workflows to be seeded.');

        $violations = [];
        foreach ($workflows as $workflow) {
            $accepted = self::ACT_ROUTE_PERMISSIONS[$workflow->workflow_type] ?? null;
            if ($accepted === null) {
                // Reserved/unwired workflows have no act route yet — nothing
                // to drift against. WorkflowSeeder documents which are wired.
                continue;
            }

            foreach ($workflow->steps ?? [] as $step) {
                $roleSlug = (string) ($step['role'] ?? '');
                $roleId = Role::query()->where('slug', $roleSlug)->value('id');
                if ($roleId === null) {
                    $violations[] = "{$workflow->workflow_type} step {$step['order']}: role '{$roleSlug}' does not exist";
                    continue;
                }

                $user = User::factory()->make(['role_id' => $roleId]);
                $holdsAny = collect($accepted)->contains(fn (string $slug): bool => $user->hasPermission($slug));

                if (! $holdsAny) {
                    $violations[] = sprintf(
                        '%s step %d: role "%s" holds none of [%s]',
                        $workflow->workflow_type,
                        $step['order'],
                        $roleSlug,
                        implode(', ', $accepted),
                    );
                }
            }
        }

        self::assertSame([], $violations, "Approval chains name step roles whose act routes they cannot pass:\n".implode("\n", $violations));
    }

    public function test_every_enforced_chain_is_covered_by_the_approval_board_registry(): void
    {
        // Chains a service actually submits (WorkflowSeeder's wiredTypes),
        // which therefore MUST render on the board — an unregistered kind is
        // dropped silently and its actors never see work waiting on them.
        $enforced = [
            'leave_request' => \App\Modules\Leave\Models\LeaveRequest::class,
            'purchase_request' => \App\Modules\Purchasing\Models\PurchaseRequest::class,
            'purchase_order' => \App\Modules\Purchasing\Models\PurchaseOrder::class,
            'cash_advance' => \App\Modules\Loans\Models\EmployeeLoan::class,
            'company_loan' => \App\Modules\Loans\Models\EmployeeLoan::class,
            'salary_adjustment' => \App\Modules\HR\Models\SalaryAdjustment::class,
            'return_request' => \App\Modules\ReturnManagement\Models\ReturnRequest::class,
        ];

        $registered = ApprovalTypeRegistry::all();

        $missing = [];
        foreach ($enforced as $workflowType => $class) {
            if (! isset($registered[$class])) {
                $missing[] = "{$workflowType} ({$class})";
            }
        }

        self::assertSame([], $missing, "Enforced approval chains missing from ApprovalTypeRegistry (board drops their cards):\n".implode("\n", $missing));
    }

    public function test_chain_participants_hold_their_route_permissions(): void
    {
        // The roles this audit found broken: production_manager (company_loan
        // step 2) and vice_president (loan final steps + salary final step).
        $vp = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'vice_president')->value('id'),
        ]);

        $this->assertTrue($vp->hasPermission('loans.approve'), 'vice_president must hold loans.approve (cash_advance step 3, company_loan step 4)');
        $this->assertTrue($vp->hasPermission('hr.salary_adjustments.act'), 'vice_president must hold hr.salary_adjustments.act (salary_adjustment step 2)');

        $manager = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
            'employee_id' => Employee::factory()->create()->id,
        ]);

        $this->assertTrue($manager->hasPermission('loans.approve'), 'production_manager must hold loans.approve (company_loan step 2)');
    }
}
