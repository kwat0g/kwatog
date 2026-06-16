<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI-013 — approval delegation.
 *
 * Verifies that a delegate may act on an approval step for the delegator's role
 * while the delegation window is open, may NOT act outside the window, and that
 * the self-approval guard still blocks a delegate from acting on a record the
 * actor themselves submitted.
 */
class ApprovalDelegationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function makeUser(string $roleSlug = 'system_admin'): User
    {
        $roleId = Role::where('slug', $roleSlug)->value('id');

        return User::create([
            'name'     => 'Tester ' . uniqid(),
            'email'    => 't_' . substr(uniqid(), -8) . '@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function pendingPurchaseRequest(User $requester): PurchaseRequest
    {
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);

        $pr = $svc->create([
            'date'     => '2026-06-01',
            'reason'   => 'Delegation test PR',
            'priority' => 'normal',
            'items'    => [[
                'description'          => 'Widget',
                'quantity'             => 2,
                'unit'                 => 'pcs',
                'estimated_unit_price' => '1000.00',
            ]],
        ], $requester);

        return $svc->submit($pr);
    }

    public function test_delegate_can_act_for_delegator_role_within_window(): void
    {
        // Requester is an employee so step 1 (department_head) is not self-acted.
        $requester = $this->makeUser('employee');
        $deptHead  = $this->makeUser('department_head');
        $delegate  = $this->makeUser('employee'); // no inherent dept_head role

        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $deptHead->id,
            'delegate_user_id'  => $delegate->id,
            'role_slug'         => 'department_head',
            'starts_at'         => now()->subDay()->toDateString(),
            'ends_at'           => now()->addDay()->toDateString(),
            'is_active'         => true,
        ]);

        // Delegate (an employee) may approve the department_head step.
        app(ApprovalService::class)->approve($pr, $delegate, 'Acting for dept head');

        $step1 = app(ApprovalService::class)->records($pr)
            ->where('step_order', 1)->first();

        $this->assertSame('approved', $step1->action);
        $this->assertSame($delegate->id, $step1->approver_id);
    }

    public function test_delegate_cannot_act_outside_window(): void
    {
        $requester = $this->makeUser('employee');
        $delegate  = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($requester);

        // Window already closed yesterday.
        ApprovalDelegation::create([
            'delegator_user_id' => $this->makeUser('department_head')->id,
            'delegate_user_id'  => $delegate->id,
            'role_slug'         => 'department_head',
            'starts_at'         => now()->subDays(5)->toDateString(),
            'ends_at'           => now()->subDays(2)->toDateString(),
            'is_active'         => true,
        ]);

        $this->expectExceptionMessage("Only users with role 'department_head' can approve this step.");
        app(ApprovalService::class)->approve($pr, $delegate, 'Should fail');
    }

    public function test_inactive_delegation_does_not_grant_authority(): void
    {
        $requester = $this->makeUser('employee');
        $delegate  = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $this->makeUser('department_head')->id,
            'delegate_user_id'  => $delegate->id,
            'role_slug'         => 'department_head',
            'starts_at'         => now()->subDay()->toDateString(),
            'ends_at'           => now()->addDay()->toDateString(),
            'is_active'         => false, // revoked
        ]);

        $this->expectExceptionMessage("Only users with role 'department_head' can approve this step.");
        app(ApprovalService::class)->approve($pr, $delegate, 'Should fail');
    }

    public function test_self_approval_guard_still_blocks_delegate_on_own_submission(): void
    {
        // The delegate is ALSO the requester/submitter of the PR.
        $delegateAndSubmitter = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($delegateAndSubmitter);

        // Even with a valid delegation for the current step's role, the
        // submitter cannot act on their own record.
        ApprovalDelegation::create([
            'delegator_user_id' => $this->makeUser('department_head')->id,
            'delegate_user_id'  => $delegateAndSubmitter->id,
            'role_slug'         => 'department_head',
            'starts_at'         => now()->subDay()->toDateString(),
            'ends_at'           => now()->addDay()->toDateString(),
            'is_active'         => true,
        ]);

        $this->expectExceptionMessage('You cannot act on a record you submitted.');
        app(ApprovalService::class)->approve($pr, $delegateAndSubmitter, 'Self approval attempt');
    }

    public function test_blanket_delegation_inherits_delegators_role(): void
    {
        $requester = $this->makeUser('employee');
        $deptHead  = $this->makeUser('department_head');
        $delegate  = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($requester);

        // role_slug null = cover EVERY role the delegator holds (department_head).
        ApprovalDelegation::create([
            'delegator_user_id' => $deptHead->id,
            'delegate_user_id'  => $delegate->id,
            'role_slug'         => null,
            'starts_at'         => now()->subDay()->toDateString(),
            'ends_at'           => now()->addDay()->toDateString(),
            'is_active'         => true,
        ]);

        app(ApprovalService::class)->approve($pr, $delegate, 'Blanket delegation');

        $step1 = app(ApprovalService::class)->records($pr)
            ->where('step_order', 1)->first();
        $this->assertSame('approved', $step1->action);
    }
}
