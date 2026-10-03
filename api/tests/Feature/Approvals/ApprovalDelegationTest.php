<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Models\AuditLog;
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
            'name' => 'Tester '.uniqid(),
            'email' => 't_'.substr(uniqid(), -8).'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function pendingPurchaseRequest(User $requester): PurchaseRequest
    {
        /** @var PurchaseRequestService $svc */
        $svc = app(PurchaseRequestService::class);

        $pr = $svc->create([
            'date' => '2026-06-01',
            'reason' => 'Delegation test PR',
            'priority' => 'normal',
            'items' => [[
                'description' => 'Widget',
                'quantity' => 2,
                'unit' => 'pcs',
                'estimated_unit_price' => '1000.00',
            ]],
        ], $requester);

        return $svc->submit($pr);
    }

    public function test_delegate_can_act_for_delegator_role_within_window(): void
    {
        // Requester is an employee so step 1 (department_head) is not self-acted.
        $requester = $this->makeUser('employee');
        $deptHead = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee'); // no inherent dept_head role

        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $deptHead->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        // Delegate (an employee) may approve the finance_officer step.
        app(ApprovalService::class)->approve($pr, $delegate, 'Acting for dept head');

        $step1 = app(ApprovalService::class)->records($pr)
            ->where('step_order', 1)->first();

        $this->assertSame('approved', $step1->action);
        $this->assertSame($delegate->id, $step1->approver_id);
    }

    public function test_delegate_cannot_act_outside_window(): void
    {
        $requester = $this->makeUser('employee');
        $delegate = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($requester);

        // Window already closed yesterday.
        ApprovalDelegation::create([
            'delegator_user_id' => $this->makeUser('finance_officer')->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDays(5)->toDateString(),
            'ends_at' => now()->subDays(2)->toDateString(),
            'is_active' => true,
        ]);

        $this->expectExceptionMessage("Only users with role 'finance_officer' can approve this step.");
        app(ApprovalService::class)->approve($pr, $delegate, 'Should fail');
    }

    public function test_inactive_delegation_does_not_grant_authority(): void
    {
        $requester = $this->makeUser('employee');
        $delegate = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $this->makeUser('finance_officer')->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => false, // revoked
        ]);

        $this->expectExceptionMessage("Only users with role 'finance_officer' can approve this step.");
        app(ApprovalService::class)->approve($pr, $delegate, 'Should fail');
    }

    public function test_deactivated_delegate_cannot_act_on_an_existing_delegation(): void
    {
        $requester = $this->makeUser('employee');
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $delegate->update(['is_active' => false]);

        $this->actingAs($delegator, 'sanctum')
            ->getJson('/api/v1/approval-delegations')
            ->assertOk()
            ->assertJsonPath('data.0.delegate.is_active', false);

        $this->assertSame([], ApprovalDelegation::actsForRoles($delegate->id, now()));
        $this->assertNotContains(
            $delegate->id,
            ApprovalDelegation::activeDelegatesFor('finance_officer', now()),
        );

        try {
            app(ApprovalService::class)->approve($pr, $delegate, 'Inactive delegate attempt');
            $this->fail('An inactive delegate must not approve a pending step.');
        } catch (ForbiddenActionException $exception) {
            $this->assertSame("Only users with role 'finance_officer' can approve this step.", $exception->getMessage());
        }

        $step = app(ApprovalService::class)->records($pr)->where('step_order', 1)->first();
        $this->assertSame('pending', $step->action);
        $this->assertNull($step->approver_id);
    }

    public function test_deactivated_role_holder_cannot_approve_a_pending_step(): void
    {
        $requester = $this->makeUser('employee');
        $approver = $this->makeUser('finance_officer');
        $pr = $this->pendingPurchaseRequest($requester);

        $approver->update(['is_active' => false]);

        try {
            app(ApprovalService::class)->approve($pr, $approver, 'Inactive approver attempt');
            $this->fail('An inactive role holder must not approve a pending step.');
        } catch (ForbiddenActionException $exception) {
            $this->assertSame("Only users with role 'finance_officer' can approve this step.", $exception->getMessage());
        }

        $step = app(ApprovalService::class)->records($pr)->where('step_order', 1)->first();
        $this->assertSame('pending', $step->action);
        $this->assertNull($step->approver_id);
    }

    public function test_deactivating_a_delegator_revokes_the_delegate_from_an_open_step(): void
    {
        $requester = $this->makeUser('employee');
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $admin = $this->makeUser('system_admin');
        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        $this->assertSame(['finance_officer'], ApprovalDelegation::actsForRoles($delegate->id, now()));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$delegator->hash_id}/deactivate")
            ->assertOk();

        $this->assertSame([], ApprovalDelegation::actsForRoles($delegate->id, now()));
        $this->assertNotContains(
            $delegate->id,
            ApprovalDelegation::activeDelegatesFor('finance_officer', now()),
        );

        try {
            app(ApprovalService::class)->approve($pr, $delegate, 'Inactive delegator attempt');
            $this->fail('A deactivated delegator must not leave delegated approval authority active.');
        } catch (ForbiddenActionException $exception) {
            $this->assertSame("Only users with role 'finance_officer' can approve this step.", $exception->getMessage());
        }

        $step = app(ApprovalService::class)->records($pr)->where('step_order', 1)->first();
        $this->assertSame('pending', $step->action);
        $this->assertNull($step->approver_id);
    }

    public function test_self_approval_guard_still_blocks_delegate_on_own_submission(): void
    {
        // The delegate is ALSO the requester/submitter of the PR.
        $delegateAndSubmitter = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($delegateAndSubmitter);

        // Even with a valid delegation for the current step's role, the
        // submitter cannot act on their own record.
        ApprovalDelegation::create([
            'delegator_user_id' => $this->makeUser('finance_officer')->id,
            'delegate_user_id' => $delegateAndSubmitter->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->expectExceptionMessage('You cannot act on a record you submitted.');
        app(ApprovalService::class)->approve($pr, $delegateAndSubmitter, 'Self approval attempt');
    }

    public function test_blanket_delegation_inherits_delegators_role(): void
    {
        $requester = $this->makeUser('employee');
        $deptHead = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');

        $pr = $this->pendingPurchaseRequest($requester);

        // role_slug null = cover EVERY role the delegator holds (department_head).
        ApprovalDelegation::create([
            'delegator_user_id' => $deptHead->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        app(ApprovalService::class)->approve($pr, $delegate, 'Blanket delegation');

        $step1 = app(ApprovalService::class)->records($pr)
            ->where('step_order', 1)->first();
        $this->assertSame('approved', $step1->action);
    }

    public function test_employee_cannot_post_a_foreign_role_delegation(): void
    {
        $employee = $this->makeUser('employee');
        $delegate = $this->makeUser('employee');
        $response = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/approval-delegations', [
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('approval_delegations', [
            'delegator_user_id' => $employee->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
        ]);
    }

    public function test_http_creation_allows_delegating_the_current_role(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');

        $response = $this->actingAs($delegator, 'sanctum')->postJson('/api/v1/approval-delegations', [
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('approval_delegations', [
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'is_active' => true,
        ]);
    }

    public function test_candidate_search_returns_only_other_active_users_with_minimal_fields(): void
    {
        $actor = $this->makeUser('employee');
        $candidate = $this->makeUser('finance_officer');
        $candidate->update(['name' => 'Delegate Alpha']);
        $inactive = $this->makeUser('employee');
        $inactive->update(['name' => 'Delegate Beta', 'is_active' => false]);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/approval-delegations/candidates?q=delegate');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertSame([$candidate->hash_id], array_column($rows, 'id'));
        $this->assertSame(['id', 'name', 'role'], array_keys($rows[0]));
        $this->assertSame('Delegate Alpha', $rows[0]['name']);
        $this->assertStringNotContainsString('@x.test', $response->getContent());
    }

    public function test_candidate_search_requires_two_characters(): void
    {
        $actor = $this->makeUser('employee');

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/approval-delegations/candidates?q=a')
            ->assertUnprocessable();
    }

    public function test_listing_marks_only_delegations_the_actor_can_revoke(): void
    {
        $actor = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $otherDelegator = $this->makeUser('finance_officer');

        $outbound = ApprovalDelegation::create([
            'delegator_user_id' => $actor->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDays(2)->toDateString(),
            'is_active' => true,
        ]);
        $inbound = ApprovalDelegation::create([
            'delegator_user_id' => $otherDelegator->id,
            'delegate_user_id' => $actor->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDays(2)->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/approval-delegations')
            ->assertOk();
        $rows = $response->json('data');

        $outboundRow = array_values(array_filter($rows, fn (array $row) => $row['id'] === $outbound->hash_id))[0];
        $inboundRow = array_values(array_filter($rows, fn (array $row) => $row['id'] === $inbound->hash_id))[0];
        $this->assertTrue($outboundRow['can_revoke']);
        $this->assertTrue($outboundRow['delegate']['is_active']);
        $this->assertFalse($inboundRow['can_revoke']);
        $this->assertSame(['id', 'name', 'is_active'], array_keys($outboundRow['delegate']));
        $this->assertSame(['id', 'name', 'is_active'], array_keys($inboundRow['delegator']));
        $this->assertStringNotContainsString('@x.test', $response->getContent());
    }

    public function test_listing_marks_when_the_delegator_is_inactive(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        $delegator->update(['is_active' => false]);

        $this->actingAs($delegate, 'sanctum')
            ->getJson('/api/v1/approval-delegations')
            ->assertOk()
            ->assertJsonPath('data.0.delegator.is_active', false)
            ->assertJsonPath('data.0.delegate.is_active', true);

        $this->assertSame([], ApprovalDelegation::actsForRoles($delegate->id, now()));
    }

    public function test_revoke_api_enforces_owner_or_admin_and_audits_actor(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $otherDelegator = $this->makeUser('hr_officer');
        $otherDelegate = $this->makeUser('employee');
        $admin = $this->makeUser('system_admin');
        $owned = ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        $foreign = ApprovalDelegation::create([
            'delegator_user_id' => $otherDelegator->id,
            'delegate_user_id' => $otherDelegate->id,
            'role_slug' => 'hr_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($delegate, 'sanctum')
            ->deleteJson("/api/v1/approval-delegations/{$owned->hash_id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the delegator or an administrator may revoke this delegation.');
        $this->assertTrue($owned->fresh()->is_active);

        $this->actingAs($delegator, 'sanctum')
            ->deleteJson("/api/v1/approval-delegations/{$owned->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse($owned->fresh()->is_active);

        $ownerAudit = AuditLog::query()
            ->where('model_type', $owned->getMorphClass())
            ->where('model_id', $owned->id)
            ->where('action', 'updated')
            ->firstOrFail();
        $this->assertSame($delegator->id, $ownerAudit->user_id);
        $this->assertTrue((bool) $ownerAudit->old_values['is_active']);
        $this->assertFalse((bool) $ownerAudit->new_values['is_active']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/approval-delegations/{$foreign->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse($foreign->fresh()->is_active);
    }

    public function test_http_creation_rejects_an_inactive_delegate_without_writing(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $delegate->update(['is_active' => false]);

        $response = $this->actingAs($delegator, 'sanctum')->postJson('/api/v1/approval-delegations', [
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'The selected delegate must be an active user.');
        $this->assertDatabaseMissing('approval_delegations', [
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'is_active' => true,
        ]);
    }

    public function test_system_admin_cannot_assign_a_role_the_target_delegator_lacks(): void
    {
        $admin = $this->makeUser('system_admin');
        $delegator = $this->makeUser('employee');
        $delegate = $this->makeUser('employee');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/approval-delegations', [
            'delegator_user_id' => $delegator->hash_id,
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('approval_delegations', [
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
        ]);
    }

    public function test_exact_delegation_stops_working_after_delegator_role_changes(): void
    {
        $requester = $this->makeUser('employee');
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $pr = $this->pendingPurchaseRequest($requester);

        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $delegator->update(['role_id' => Role::where('slug', 'employee')->value('id')]);

        $this->expectExceptionMessage("Only users with role 'finance_officer' can approve this step.");
        app(ApprovalService::class)->approve($pr, $delegate, 'Stale delegation must fail');
    }
}
