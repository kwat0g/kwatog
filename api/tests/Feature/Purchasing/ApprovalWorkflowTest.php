<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
            'email'    => 't_' . uniqid() . '@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
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
        // The workflow has 4 steps: department_head, production_manager, purchasing_officer, system_admin
        $records = ApprovalRecord::where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->id)
            ->orderBy('step_order')
            ->get();

        $this->assertGreaterThanOrEqual(3, $records->count());

        // First 3 steps should be pending (step 4 may be skipped due to threshold < 50000)
        $this->assertSame('pending', $records[0]->action);
        $this->assertSame('department_head', $records[0]->role_slug);
        $this->assertSame(1, $records[0]->step_order);

        $this->assertSame('pending', $records[1]->action);
        $this->assertSame('production_manager', $records[1]->role_slug);
        $this->assertSame(2, $records[1]->step_order);

        $this->assertSame('pending', $records[2]->action);
        $this->assertSame('purchasing_officer', $records[2]->role_slug);
        $this->assertSame(3, $records[2]->step_order);

        // Step 4 has a threshold of 50000; total is 10*1500 + 5*2000 = 25000 < 50000
        // so step 4 should be skipped
        $this->assertSame('skipped', $records[3]->action);
        $this->assertSame('system_admin', $records[3]->role_slug);
        $this->assertSame(4, $records[3]->step_order);
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

        // Next step should be step 1 (department_head)
        $nextStep = $approvals->nextStep($submitted);
        $this->assertNotNull($nextStep);
        $this->assertSame(1, $nextStep->step_order);
        $this->assertSame('department_head', $nextStep->role_slug);

        // Approve step 1 (system_admin can approve any step)
        $approver = $this->makeUser('system_admin');
        $approvals->approve($submitted, $approver, 'Looks good');

        // Next step should now be step 2 (production_manager)
        $nextStep = $approvals->nextStep($submitted);
        $this->assertNotNull($nextStep);
        $this->assertSame(2, $nextStep->step_order);
        $this->assertSame('production_manager', $nextStep->role_slug);

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

        // The PR total is 25000 (< 50000 threshold), so step 4 is skipped.
        // We need to approve steps 1, 2, and 3.
        $approver = $this->makeUser('system_admin');

        // Before approval chain, entity is not fully approved
        $this->assertFalse($approvals->isFullyApproved($submitted));

        // Approve step 1 (department_head)
        $result = $svc->approve($submitted, $approver, 'Step 1 approved');
        $this->assertSame(PurchaseRequestStatus::Pending, $result->status);

        // Approve step 2 (production_manager)
        $result = $svc->approve($result, $approver, 'Step 2 approved');
        $this->assertSame(PurchaseRequestStatus::Pending, $result->status);

        // Approve step 3 (purchasing_officer) — this should complete the chain
        $result = $svc->approve($result, $approver, 'Step 3 approved');

        // Entity should now be fully approved
        $this->assertSame(PurchaseRequestStatus::Approved, $result->status);
        $this->assertNotNull($result->approved_at);
        $this->assertTrue($approvals->isFullyApproved($result));

        // All non-skipped records should be 'approved'
        $records = $approvals->chain($result);
        foreach ($records as $record) {
            $this->assertContains($record->action, ['approved', 'skipped']);
        }
    }

    public function test_reject_stops_workflow(): void
    {
        $user = $this->makeUser();
        $pr = $this->createDraftPurchaseRequest($user);

        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        $approver = $this->makeUser('system_admin');

        // Approve step 1
        $svc->approve($submitted, $approver, 'Step 1 OK');
        $fresh = $submitted->fresh();

        // Reject at step 2
        $rejected = $svc->reject($fresh, $approver, 'Budget not available');

        // Entity status should be rejected
        $this->assertSame(PurchaseRequestStatus::Rejected, $rejected->status);
        $this->assertTrue($approvals->isRejected($rejected));
        $this->assertFalse($approvals->isFullyApproved($rejected));

        // Step 2 should be 'rejected'
        $step2 = ApprovalRecord::where('approvable_type', $rejected->getMorphClass())
            ->where('approvable_id', $rejected->id)
            ->where('step_order', 2)
            ->first();
        $this->assertSame('rejected', $step2->action);
        $this->assertSame('Budget not available', $step2->remarks);

        // Step 3 should be 'skipped' (subsequent steps after rejection)
        $step3 = ApprovalRecord::where('approvable_type', $rejected->getMorphClass())
            ->where('approvable_id', $rejected->id)
            ->where('step_order', 3)
            ->first();
        $this->assertSame('skipped', $step3->action);

        // No more steps to approve
        $this->assertNull($approvals->nextStep($rejected));
    }

    public function test_cannot_approve_out_of_order(): void
    {
        $user = $this->makeUser();
        $pr = $this->createDraftPurchaseRequest($user);

        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);
        $submitted = $svc->submit($pr);

        /** @var ApprovalService $approvals */
        $approvals = app(ApprovalService::class);

        // Create a user that only has the production_manager role (step 2)
        $step2Approver = $this->makeUser('production_manager');

        // Attempting to approve with a production_manager before step 1 (department_head)
        // has been approved should fail because the next pending step is step 1
        // which requires 'department_head' role
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $approvals->approve($submitted, $step2Approver, 'Trying to skip step 1');
    }
}
