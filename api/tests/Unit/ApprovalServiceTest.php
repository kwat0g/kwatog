<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Models\WorkflowDefinition;
use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_creates_pending_records(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'leave_request',
            'name'          => 'Leave',
            'steps'         => [
                ['order' => 1, 'role' => 'department_head'],
                ['order' => 2, 'role' => 'hr_officer'],
            ],
        ]);

        $approvable = $this->fakeApprovable();
        app(ApprovalService::class)->submit($approvable, 'leave_request');

        $records = app(ApprovalService::class)->chain($approvable);
        $this->assertCount(2, $records);
        $this->assertSame('pending', $records[0]->action);
        $this->assertSame('pending', $records[1]->action);
    }

    public function test_threshold_skips_step_below_amount(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'purchase_order',
            'name'          => 'PO',
            'steps'         => [
                ['order' => 1, 'role' => 'purchasing_officer'],
                ['order' => 2, 'role' => 'finance_officer'],
                ['order' => 3, 'role' => 'system_admin', 'threshold' => 50000.00],
            ],
        ]);

        $approvable = $this->fakeApprovable();
        app(ApprovalService::class)->submit($approvable, 'purchase_order', amount: 10000.00);

        $records = app(ApprovalService::class)->chain($approvable);
        $this->assertSame('pending', $records[0]->action);
        $this->assertSame('pending', $records[1]->action);
        $this->assertSame('skipped', $records[2]->action);
    }

    // -------------------------------------------------------------------------
    // P2.6 — new cases
    // -------------------------------------------------------------------------

    /**
     * A user whose role slug does NOT match the current step's role_slug is
     * rejected with a 403 HttpException; the approval record stays 'pending'.
     *
     * Note: abort(403) throws HttpException whose getStatusCode() === 403 but
     * whose getCode() === 0 (Symfony's HttpException uses status, not PHP code).
     * We therefore catch manually and assert getStatusCode().
     */
    public function test_wrong_role_approve_throws_403(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'p26_wrong_role',
            'name'          => 'WrongRole',
            'steps'         => [
                ['order' => 1, 'role' => 'department_head'],
            ],
        ]);

        $wrongRole = Role::firstOrCreate(['slug' => 'hr_officer'], ['name' => 'HR Officer']);
        $wrongUser = User::factory()->create(['role_id' => $wrongRole->id]);

        $approvable = $this->fakeApprovable(100);
        $svc = app(ApprovalService::class);
        $svc->submit($approvable, 'p26_wrong_role');

        $threw = false;
        try {
            $svc->approve($approvable, $wrongUser, 'should be blocked');
        } catch (HttpException $e) {
            $threw = true;
            $this->assertSame(403, $e->getStatusCode(), 'Expected HTTP 403 for wrong-role approve');
        }

        $this->assertTrue($threw, 'HttpException must be thrown when wrong-role user attempts to approve');
    }

    /**
     * After the wrong-role approve attempt is blocked, the record remains 'pending'.
     */
    public function test_wrong_role_approve_leaves_record_unchanged(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'p26_wrong_role_state',
            'name'          => 'WrongRoleState',
            'steps'         => [
                ['order' => 1, 'role' => 'department_head'],
            ],
        ]);

        $wrongRole = Role::firstOrCreate(['slug' => 'hr_officer'], ['name' => 'HR Officer']);
        $wrongUser = User::factory()->create(['role_id' => $wrongRole->id]);

        $approvable = $this->fakeApprovable(101);
        $svc = app(ApprovalService::class);
        $svc->submit($approvable, 'p26_wrong_role_state');

        try {
            $svc->approve($approvable, $wrongUser);
        } catch (HttpException $e) {
            // Expected — 403 is thrown inside DB::transaction which rolls back.
        }

        $record = $svc->nextStep($approvable);
        $this->assertNotNull($record, 'Step must still exist as pending after blocked approve');
        $this->assertSame('pending', $record->action);
        $this->assertNull($record->approver_id);
    }

    /**
     * Approving step 1 of a 3-step chain does NOT make isFullyApproved() true.
     */
    public function test_approving_first_step_of_multi_step_chain_is_not_fully_approved(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'p26_multi_step',
            'name'          => 'MultiStep',
            'steps'         => [
                ['order' => 1, 'role' => 'purchasing_officer'],
                ['order' => 2, 'role' => 'finance_officer'],
                ['order' => 3, 'role' => 'department_head'],
            ],
        ]);

        $role1 = Role::firstOrCreate(['slug' => 'purchasing_officer'], ['name' => 'Purchasing Officer']);
        $user1 = User::factory()->create(['role_id' => $role1->id]);

        $approvable = $this->fakeApprovable(200);
        $svc = app(ApprovalService::class);
        $svc->submit($approvable, 'p26_multi_step');

        $svc->approve($approvable, $user1);

        $this->assertFalse(
            $svc->isFullyApproved($approvable),
            'isFullyApproved() must be false after only step 1 of 3 is approved'
        );
    }

    /**
     * Approving ALL steps makes isFullyApproved() true.
     */
    public function test_approving_final_step_makes_fully_approved_true(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'p26_final_step',
            'name'          => 'FinalStep',
            'steps'         => [
                ['order' => 1, 'role' => 'purchasing_officer'],
                ['order' => 2, 'role' => 'finance_officer'],
            ],
        ]);

        $role1 = Role::firstOrCreate(['slug' => 'purchasing_officer'], ['name' => 'Purchasing Officer']);
        $role2 = Role::firstOrCreate(['slug' => 'finance_officer'],    ['name' => 'Finance Officer']);
        $user1 = User::factory()->create(['role_id' => $role1->id]);
        $user2 = User::factory()->create(['role_id' => $role2->id]);

        $approvable = $this->fakeApprovable(300);
        $svc = app(ApprovalService::class);
        $svc->submit($approvable, 'p26_final_step');

        $svc->approve($approvable, $user1);
        $this->assertFalse($svc->isFullyApproved($approvable), 'Not yet fully approved after step 1');

        $svc->approve($approvable, $user2);
        $this->assertTrue(
            $svc->isFullyApproved($approvable),
            'isFullyApproved() must be true after all steps approved'
        );
    }

    /**
     * reject() marks the current step 'rejected' and all subsequent pending
     * steps become 'skipped' (the real terminal state per ApprovalService::reject).
     */
    public function test_reject_marks_current_step_rejected_and_downstream_skipped(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'p26_reject',
            'name'          => 'Reject',
            'steps'         => [
                ['order' => 1, 'role' => 'department_head'],
                ['order' => 2, 'role' => 'hr_officer'],
                ['order' => 3, 'role' => 'finance_officer'],
            ],
        ]);

        $role = Role::firstOrCreate(['slug' => 'department_head'], ['name' => 'Department Head']);
        $rejecter = User::factory()->create(['role_id' => $role->id]);

        $approvable = $this->fakeApprovable(400);
        $svc = app(ApprovalService::class);
        $svc->submit($approvable, 'p26_reject');

        $svc->reject($approvable, $rejecter, 'Not approved');

        $records = $svc->chain($approvable)->keyBy('step_order');

        $this->assertSame('rejected', $records[1]->action, 'Step 1 must be rejected');
        $this->assertSame('skipped',  $records[2]->action, 'Step 2 must be skipped after rejection');
        $this->assertSame('skipped',  $records[3]->action, 'Step 3 must be skipped after rejection');

        // Chain is terminated — isRejected() returns true, no next pending step.
        $this->assertTrue($svc->isRejected($approvable));
        $this->assertNull($svc->nextStep($approvable));
    }

    /**
     * userMayActFor() (tested indirectly via approve()) —
     * the correct role can approve; an unrelated role cannot.
     *
     * We verify both halves in one test to share the fixture cheaply.
     */
    public function test_user_may_act_for_returns_true_only_for_current_step_role(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'p26_may_act',
            'name'          => 'MayAct',
            'steps'         => [
                ['order' => 1, 'role' => 'hr_officer'],
            ],
        ]);

        $correctRole = Role::firstOrCreate(['slug' => 'hr_officer'],   ['name' => 'HR Officer']);
        $wrongRole   = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);

        $correctUser = User::factory()->create(['role_id' => $correctRole->id]);
        $wrongUser   = User::factory()->create(['role_id' => $wrongRole->id]);

        $approvable = $this->fakeApprovable(500);
        $svc = app(ApprovalService::class);
        $svc->submit($approvable, 'p26_may_act');

        // Wrong user CANNOT act — 403 must be thrown.
        $threw403 = false;
        try {
            $svc->approve($approvable, $wrongUser);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                $threw403 = true;
            }
        }
        $this->assertTrue($threw403, 'Wrong-role user must receive HTTP 403');

        // Correct user CAN act — no exception, record becomes 'approved'.
        $svc->approve($approvable, $correctUser);
        $records = $svc->chain($approvable);
        $this->assertSame('approved', $records[0]->action, 'Correct-role user must be able to approve');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a transient (unsaved) Model stub that acts as an approvable.
     * Supply a distinct $id per test so ApprovalRecord rows don't collide.
     */
    private function fakeApprovable(int $id = 1): Model
    {
        return new class($id) extends Model {
            protected $table = 'fakes';
            public $exists = true;
            private int $fakeId;
            public function __construct(int $id = 1)
            {
                parent::__construct();
                $this->fakeId = $id;
            }
            public function getKey() { return $this->fakeId; }
            public function getMorphClass(): string { return 'fake_approvable'; }
        };
    }
}
