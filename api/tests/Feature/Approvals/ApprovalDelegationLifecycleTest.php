<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalDelegationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function makeUser(string $roleSlug): User
    {
        return User::create([
            'name' => 'Delegation lifecycle '.uniqid(),
            'email' => 'delegation_'.substr(uniqid(), -8).'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    public function test_revoke_does_not_commit_when_its_audit_write_fails(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $delegation = ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $rejectAuditWrite = true;
        AuditLog::creating(function (AuditLog $audit) use (&$rejectAuditWrite, $delegation): void {
            if ($rejectAuditWrite
                && $audit->model_type === $delegation->getMorphClass()
                && $audit->model_id === $delegation->id
                && $audit->action === 'updated') {
                $rejectAuditWrite = false;
                throw new \RuntimeException('Injected audit storage failure.');
            }
        });

        $this->actingAs($delegator, 'sanctum')
            ->deleteJson("/api/v1/approval-delegations/{$delegation->hash_id}")
            ->assertInternalServerError();

        $this->assertTrue($delegation->fresh()->is_active);
        $this->assertDatabaseMissing('audit_logs', [
            'model_type' => $delegation->getMorphClass(),
            'model_id' => $delegation->id,
            'action' => 'updated',
        ]);
    }

    public function test_retried_grant_returns_one_active_delegation(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $payload = [
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'reason' => 'Retry safety regression',
        ];

        $created = $this->actingAs($delegator, 'sanctum')
            ->postJson('/api/v1/approval-delegations', $payload)
            ->assertCreated();
        $delegationId = $created->json('data.id');

        $retry = $this->actingAs($delegator, 'sanctum')
            ->postJson('/api/v1/approval-delegations', $payload);
        $retry->assertOk()
            ->assertJsonPath('data.id', $delegationId);

        $changedReason = $this->actingAs($delegator, 'sanctum')
            ->postJson('/api/v1/approval-delegations', [...$payload, 'reason' => 'Changed reason']);
        $changedReason->assertUnprocessable()
            ->assertJsonPath('message', 'An active approval cover already exists for this delegate, role, and date range.');
        $this->assertSame(1, ApprovalDelegation::query()
            ->where('delegator_user_id', $delegator->id)
            ->where('delegate_user_id', $delegate->id)
            ->where('is_active', true)
            ->count());

        $this->actingAs($delegator, 'sanctum')
            ->deleteJson("/api/v1/approval-delegations/{$delegationId}")
            ->assertOk();

        $this->assertSame([], ApprovalDelegation::activeDelegatesFor('finance_officer', now()));
    }

    public function test_delayed_retry_cannot_restore_a_revoked_delegation(): void
    {
        $delegator = $this->makeUser('finance_officer');
        $delegate = $this->makeUser('employee');
        $payload = [
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ];

        $created = $this->actingAs($delegator, 'sanctum')
            ->postJson('/api/v1/approval-delegations', $payload)
            ->assertCreated();
        $delegationId = $created->json('data.id');

        $this->actingAs($delegator, 'sanctum')
            ->deleteJson("/api/v1/approval-delegations/{$delegationId}")
            ->assertOk();

        $retry = $this->actingAs($delegator, 'sanctum')
            ->postJson('/api/v1/approval-delegations', $payload);
        $retry->assertUnprocessable()
            ->assertJsonPath('message', 'This approval cover was already revoked. Choose a new date range.');
        $this->assertFalse(ApprovalDelegation::query()
            ->where('delegator_user_id', $delegator->id)
            ->where('delegate_user_id', $delegate->id)
            ->firstOrFail()
            ->is_active);
        $this->assertSame([], ApprovalDelegation::activeDelegatesFor('finance_officer', now()));
    }

    public function test_department_head_cover_rejects_a_delegate_from_another_department(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(PositionSeeder::class);
        $departments = Department::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $departments);

        $delegator = $this->makeUser('department_head');
        $delegate = $this->makeUser('employee');
        $delegator->update([
            'employee_id' => Employee::factory()->create(['department_id' => $departments[0]->id])->id,
        ]);
        $delegate->update([
            'employee_id' => Employee::factory()->create(['department_id' => $departments[1]->id])->id,
        ]);

        $response = $this->actingAs($delegator, 'sanctum')->postJson('/api/v1/approval-delegations', [
            'delegate_user_id' => $delegate->hash_id,
            'role_slug' => 'department_head',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Department head approval cover must be assigned within the same department.');
        $this->assertDatabaseMissing('approval_delegations', [
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'is_active' => true,
        ]);
    }

    public function test_department_head_candidate_search_only_returns_colleagues_from_their_department(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(PositionSeeder::class);
        $departments = Department::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $departments);

        $actor = $this->makeUser('department_head');
        $sameDepartment = $this->makeUser('employee');
        $otherDepartment = $this->makeUser('employee');
        $actor->update([
            'employee_id' => Employee::factory()->create(['department_id' => $departments[0]->id])->id,
        ]);
        $sameDepartment->update([
            'name' => 'Delegate Same Department',
            'employee_id' => Employee::factory()->create(['department_id' => $departments[0]->id])->id,
        ]);
        $otherDepartment->update([
            'name' => 'Delegate Other Department',
            'employee_id' => Employee::factory()->create(['department_id' => $departments[1]->id])->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/approval-delegations/candidates?q=delegate')
            ->assertOk();

        $this->assertSame([$sameDepartment->hash_id], array_column($response->json('data'), 'id'));
    }
}
