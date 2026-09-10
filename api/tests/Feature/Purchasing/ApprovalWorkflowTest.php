<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->department = Department::factory()->create();
    }

    /**
     * 2026-09-10 chain redesign — the purchase_request workflow is two steps:
     * finance_officer (always) → vice_president (only when total ≥ ₱50,000).
     * There is no department-scoped step anymore, so the department fixture
     * only exists to satisfy the employee FK.
     */
    private function makeUser(string $roleSlug = 'system_admin', ?Department $department = null): User
    {
        $roleId = Role::where('slug', $roleSlug)->value('id');

        return User::create([
            'name'        => 'Tester ' . uniqid(),
            'email'       => 't_' . uniqid() . '@x.test',
            'password'    => bcrypt('Password1!'),
            'role_id'     => $roleId,
            'employee_id' => Employee::factory()->create([
                'department_id' => ($department ?? $this->department)->id,
            ])->id,
        ]);
    }

    private function createDraftPurchaseRequest(User $user): PurchaseRequest
    {
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);

        return $svc->create([
            'date'     => '2026-06-01',
            'reason'   => 'Test PR for approval workflow',
            'priority' => 'normal',
            'items'    => [
                [
                    'description'          => 'Test item A',
                    'quantity'             => 10,
                    'unit'                 => 'pcs',
                    'estimated_unit_price' => '1500.00',
                ],
                [
                    'description'          => 'Test item B',
                    'quantity'             => 5,
                    'unit'                 => 'kg',
                    'estimated_unit_price' => '2000.00',
                ],
            ],
        ], $user);
    }

    public function test_submit_for_approval_creates_approval_records(): void
    {
        $user = $this->makeUser();
        $pr = $this->createDraftPurchaseRequest($user);

        // PR should start as draft
        $this->assertSame(PurchaseRequestStatus::Draft, $pr->status);

        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $submitted = $svc->submit($pr);

        // Status should now be pending
        $this->assertSame(PurchaseRequestStatus::Pending, $submitted->status);
        $this->assertNotNull($submitted->submitted_at);

        // Approval records should have been created for the purchase_request workflow.
        // The workflow has 2 steps: finance_officer, vice_president (threshold 50000).
        $records = ApprovalRecord::where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->id)
            ->orderBy('step_order')
            ->get();

        $this->assertCount(2, $records);

        // Step 1 (finance) is always pending.
        $this->assertSame('pending', $records[0]->action);
        $this->assertSame('finance_officer', $records[0]->role_slug);
        $this->assertSame(1, $records[0]->step_order);

        // Step 2 has a threshold of 50000; total is 10*1500 + 5*2000 = 25000 < 50000
        // so the VP step should be skipped.
        $this->assertSame('skipped', $records[1]->action);
        $this->assertSame('vice_president', $records[1]->role_slug);
        $this->assertSame(2, $records[1]->step_order);
    }

    public function test_vp_step_stays_pending_at_or_above_threshold(): void
    {
        $user = $this->makeUser();
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create([
            'date'     => '2026-06-01',
            'reason'   => 'Big ticket PR',
            'priority' => 'normal',
            'items'    => [
                ['description' => 'Resin', 'quantity' => 10, 'unit' => 'kg', 'estimated_unit_price' => '5000.00'],
            ],
        ], $user);
        $submitted = $svc->submit($pr);

        $vpRecord = ApprovalRecord::where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->id)
            ->where('step_order', 2)
            ->first();

        $this->assertNotNull($vpRecord);
        $this->assertSame('pending', $vpRecord->action);
        $this->assertSame('vice_president', $vpRecord->role_slug);
        $this->assertSame(PurchaseRequestStatus::Pending, $submitted->status);
    }

    public function test_approve_step_advances_to_next(): void
    {
        $user = $this->makeUser();
        $pr = $this->createDraftPurchaseRequest($user);

        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        // Next step should be step 1 (finance_officer)
        $nextStep = $approvals->nextStep($submitted);
        $this->assertNotNull($nextStep);
        $this->assertSame(1, $nextStep->step_order);
        $this->assertSame('finance_officer', $nextStep->role_slug);

        // Approve step 1 with the finance_officer role (only the exact step
        // role may approve; delegation aside).
        $approver = $this->makeUser('finance_officer');
        $approvals->approve($submitted, $approver, 'Looks good');

        // Total 25000 < 50000, so the VP step was skipped and the chain is
        // now complete.
        $this->assertNull($approvals->nextStep($submitted));

        // Verify step 1 is marked approved
        $step1 = ApprovalRecord::where('approvable_type', $submitted->getMorphClass())
            ->where('approvable_id', $submitted->id)
            ->where('step_order', 1)
            ->first();
        $this->assertSame('approved', $step1->action);
        $this->assertSame($approver->id, $step1->approver_id);
        $this->assertNotNull($step1->acted_at);
    }

    public function test_full_approval_chain_marks_entity_approved(): void
    {
        $user = $this->makeUser();
        $pr = $this->createDraftPurchaseRequest($user);

        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        // The PR total is 25000 (< 50000 threshold), so the VP step is skipped
        // and finance is the only approver.
        $finance = $this->makeUser('finance_officer');

        $this->assertFalse($approvals->isFullyApproved($submitted));

        $result = $svc->approve($submitted, $finance, 'Finance approved');

        $this->assertSame(PurchaseRequestStatus::Approved, $result->status);
        $this->assertNotNull($result->approved_at);
        $this->assertTrue($approvals->isFullyApproved($result));

        $records = $approvals->chain($result);
        foreach ($records as $record) {
            $this->assertContains($record->action, ['approved', 'skipped']);
        }
    }

    public function test_big_ticket_pr_requires_both_finance_and_vp(): void
    {
        $user = $this->makeUser();
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create([
            'date'     => '2026-06-01',
            'reason'   => 'Mold replacement',
            'priority' => 'normal',
            'items'    => [
                ['description' => 'Mold set', 'quantity' => 1, 'unit' => 'pcs', 'estimated_unit_price' => '60000.00'],
            ],
        ], $user);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        // VP cannot approve out of order.
        $this->expectException(\App\Common\Exceptions\ForbiddenActionException::class);
        $approvals->approve($submitted, $this->makeUser('vice_president'), 'Trying to skip finance');
    }

    public function test_requester_cannot_approve_own_pr_even_when_holding_step_role(): void
    {
        $finance = $this->makeUser('finance_officer');
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create($this->smallPayload(), $finance);
        $submitted = $svc->submit($pr);

        $this->expectException(\App\Common\Exceptions\ForbiddenActionException::class);
        $svc->approve($submitted, $finance, 'Self-approval attempt');
    }

    public function test_department_head_has_no_step_in_the_new_chain(): void
    {
        // 2026-09-10 redesign: creators hold the need authority (the create
        // gate), the chain is money-only. A department head therefore cannot
        // approve any step — not even their own department's request.
        $requester = $this->makeUser('system_admin');
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create($this->smallPayload(), $requester);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        $this->expectException(\App\Common\Exceptions\ForbiddenActionException::class);
        $approvals->approve($submitted, $this->makeUser('department_head'), 'Dept head has no step');
    }

    public function test_reject_stops_workflow(): void
    {
        $user = $this->makeUser();
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create([
            'date'     => '2026-06-01',
            'reason'   => 'Test PR for rejection',
            'priority' => 'normal',
            'items'    => [
                ['description' => 'Mold set', 'quantity' => 1, 'unit' => 'pcs', 'estimated_unit_price' => '60000.00'],
            ],
        ], $user);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        // Step 1 rejected by finance.
        $finance = $this->makeUser('finance_officer');

        $rejected = $svc->reject($submitted, $finance, 'Budget not available');

        $this->assertSame(PurchaseRequestStatus::Rejected, $rejected->status);
        $this->assertTrue($approvals->isRejected($rejected));
        $this->assertFalse($approvals->isFullyApproved($rejected));

        $step1 = ApprovalRecord::where('approvable_type', $rejected->getMorphClass())
            ->where('approvable_id', $rejected->id)
            ->where('step_order', 1)
            ->first();
        $this->assertSame('rejected', $step1->action);
        $this->assertSame('Budget not available', $step1->remarks);

        $step2 = ApprovalRecord::where('approvable_type', $rejected->getMorphClass())
            ->where('approvable_id', $rejected->id)
            ->where('step_order', 2)
            ->first();
        $this->assertSame('skipped', $step2->action);

        $this->assertNull($approvals->nextStep($rejected));
    }

    public function test_cannot_approve_out_of_order(): void
    {
        $user = $this->makeUser();
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create([
            'date'     => '2026-06-01',
            'reason'   => 'Out-of-order guard',
            'priority' => 'normal',
            'items'    => [
                ['description' => 'Mold set', 'quantity' => 1, 'unit' => 'pcs', 'estimated_unit_price' => '60000.00'],
            ],
        ], $user);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        // The VP step exists on this chain (60000 ≥ 50000), but step 1 (finance)
        // is still pending, so a VP attempt must be refused.
        $this->expectException(\App\Common\Exceptions\ForbiddenActionException::class);
        $approvals->approve($submitted, $this->makeUser('vice_president'), 'Trying to skip step 1');
    }

    /** Small payload keeps the PR total below the ₱50,000 VP threshold. */
    private function smallPayload(): array
    {
        return [
            'date'     => '2026-06-01',
            'reason'   => 'Small PR',
            'priority' => 'normal',
            'items'    => [
                ['description' => 'Packaging', 'quantity' => 10, 'unit' => 'pcs', 'estimated_unit_price' => '5.00'],
            ],
        ];
    }
}
