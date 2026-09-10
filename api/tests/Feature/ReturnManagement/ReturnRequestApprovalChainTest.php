<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RM-01 — pending RMA approvals used to be invisible: the approval board
 * dropped every return_request card and the detail resource exposed no chain.
 * Pins both surfaces: the resource returns the approval records with the
 * pending step, and the step advances as approvers act.
 */
class ReturnRequestApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    private function draftRma(User $by): ReturnRequest
    {
        return ReturnRequest::query()->create([
            'rma_number' => 'RMA-T-'.substr(uniqid(), -5),
            'type' => 'supplier_return',
            'status' => 'draft',
            'created_by' => $by->id,
        ]);
    }

    public function test_detail_exposes_the_approval_chain_and_pending_step(): void
    {
        $submitter = $this->userWithRole('system_admin');
        $rma = $this->draftRma($submitter);

        $this->actingAs($submitter)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $this->actingAs($this->userWithRole('department_head'))
            ->getJson("/api/v1/return-management/return-requests/{$rma->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.pending_approval_step', 1)
            ->assertJsonPath('data.has_overdue_approval', false)
            ->assertJsonCount(2, 'data.approval_records')
            ->assertJsonPath('data.approval_records.0.step_order', 1)
            ->assertJsonPath('data.approval_records.0.role_slug', 'department_head')
            ->assertJsonPath('data.approval_records.0.action', 'pending')
            ->assertJsonPath('data.approval_records.1.step_order', 2)
            ->assertJsonPath('data.approval_records.1.role_slug', 'production_manager')
            ->assertJsonPath('data.approval_records.1.action', 'pending');
    }

    public function test_pending_step_advances_after_a_partial_approval(): void
    {
        $submitter = $this->userWithRole('system_admin');
        $rma = $this->draftRma($submitter);

        $this->actingAs($submitter)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/submit")
            ->assertOk();

        $head = $this->userWithRole('department_head');
        $this->actingAs($head)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/approve", [
                'remarks' => 'Confirmed off-spec.',
            ])
            ->assertOk();

        $this->actingAs($this->userWithRole('production_manager'))
            ->getJson("/api/v1/return-management/return-requests/{$rma->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.pending_approval_step', 2)
            ->assertJsonPath('data.approval_records.0.action', 'approved')
            ->assertJsonPath('data.approval_records.0.approver.name', $head->name)
            ->assertJsonPath('data.approval_records.1.action', 'pending');
    }
}
