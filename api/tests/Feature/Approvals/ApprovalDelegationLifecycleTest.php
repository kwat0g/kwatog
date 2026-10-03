<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
}
