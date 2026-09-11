<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseOrderPdfService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Pins the purchase-order LIST row ladder now that it lives in
 * PurchaseOrderAccessPolicy (extracted verbatim from PurchaseOrderService::list
 * on 2026-09 so global search and the approval board share one source of
 * truth):
 *
 *   - purchasing.po.approve / system_admin → every PO;
 *   - purchasing_officer (company-wide operator) → every PO;
 *   - department_head → own creations + POs whose linked PR is in their dept;
 *   - everyone else → own creations only.
 */
class PurchaseOrderListScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array<int, string> */
    private function poNumbers(User $user): array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders?per_page=100')
            ->assertOk();

        return array_map(
            static fn (array $row): string => (string) $row['po_number'],
            $response->json('data'),
        );
    }

    private function userWithRole(string $slug, ?Department $department = null): User
    {
        $attributes = ['role_id' => Role::query()->where('slug', $slug)->value('id')];
        if ($department !== null) {
            $attributes['employee_id'] = Employee::factory()
                ->create(['department_id' => $department->id])->id;
        }

        return User::factory()->create($attributes);
    }

    private function poIn(?Department $department, ?int $createdBy = null): PurchaseOrder
    {
        return PurchaseOrder::factory()->create(array_filter([
            'created_by' => $createdBy,
            'purchase_request_id' => $department === null
                ? null
                : PurchaseRequest::factory()->create(['department_id' => $department->id])->id,
        ], static fn ($value) => $value !== null));
    }

    public function test_purchasing_officers_see_every_po(): void
    {
        $alpha = Department::factory()->create();
        $beta = Department::factory()->create();
        $this->poIn($alpha);
        $this->poIn($beta);

        $officer = $this->userWithRole('purchasing_officer');

        $this->assertCount(2, $this->poNumbers($officer));
    }

    public function test_department_head_sees_own_and_own_department_pos_only(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $head = $this->userWithRole('department_head', $own);

        $mine = $this->poIn($own);
        $colleague = $this->poIn($own, User::factory()->create()->id);
        $foreign = $this->poIn($other);

        $numbers = $this->poNumbers($head);

        $this->assertContains($mine->po_number, $numbers);
        $this->assertContains($colleague->po_number, $numbers);
        $this->assertNotContains($foreign->po_number, $numbers);
    }

    public function test_other_roles_see_only_their_own_pos_even_with_a_department(): void
    {
        $department = Department::factory()->create();
        $role = Role::create([
            'name' => 'PO scope custom '.substr(uniqid(), -5),
            'slug' => 'po_scope_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['purchasing.view'])->pluck('id')->all(),
        );
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);

        $own = $this->poIn(null, $user->id);
        $this->poIn($department, User::factory()->create()->id);

        $this->assertSame([$own->po_number], $this->poNumbers($user));
    }

    public function test_department_head_cannot_show_or_pdf_a_po_outside_their_row_scope(): void
    {
        $headDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = $this->userWithRole('department_head', $headDepartment);
        $hidden = $this->poIn($otherDepartment, User::factory()->create()->id);

        $this->actingAs($head, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$hidden->hash_id)
            ->assertForbidden();

        $this->actingAs($head, 'sanctum')
            ->get('/api/v1/purchasing/purchase-orders/'.$hidden->hash_id.'/pdf')
            ->assertForbidden();
    }

    /**
     * PS-01b — a chain approver with no creations and no department (finance
     * on PO step 2) must still SEE the POs waiting on their step. Before the
     * chain-participant branch the board badge counted their card while the
     * list hid it — the workflow stalled invisibly.
     */
    public function test_chain_participant_sees_pos_waiting_on_their_step(): void
    {
        $this->seed(WorkflowSeeder::class);
        $finance = $this->userWithRole('finance_officer');

        $po = PurchaseOrder::factory()->create([
            'status' => \App\Modules\Purchasing\Enums\PurchaseOrderStatus::PendingApproval->value,
            'created_by' => User::factory()->create()->id,
        ]);
        \App\Common\Models\ApprovalRecord::create([
            'approvable_type' => $po->getMorphClass(),
            'approvable_id' => $po->id,
            'step_order' => 2,
            'role_slug' => 'finance_officer',
            'action' => 'pending',
            'is_current' => true,
        ]);

        $this->assertContains($po->po_number, $this->poNumbers($finance));

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk();
    }

    public function test_delegate_sees_pos_waiting_on_the_delegated_step(): void
    {
        // Finance holds po.approve, so it sees every PO through the top tier
        // already. The chain-participant branch's real beneficiary is a
        // DELEGATE: leave-cover that can open the module (purchasing.view)
        // but holds no approver-tier permission. The delegator's pending step
        // must surface on the delegate's list and on the approval board.
        $this->seed(WorkflowSeeder::class);
        $role = Role::create([
            'name' => 'PO delegate '.substr(uniqid(), -5),
            'slug' => 'po_delegate_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['purchasing.view'])->pluck('id')->all(),
        );
        $delegate = User::factory()->create(['role_id' => $role->id]);
        $delegator = $this->userWithRole('finance_officer');
        \App\Common\Models\ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->startOfDay(),
            'ends_at' => now()->addDay()->startOfDay(),
            'is_active' => true,
        ]);

        $po = PurchaseOrder::factory()->create([
            'status' => \App\Modules\Purchasing\Enums\PurchaseOrderStatus::PendingApproval->value,
            'created_by' => User::factory()->create()->id,
        ]);
        \App\Common\Models\ApprovalRecord::create([
            'approvable_type' => $po->getMorphClass(),
            'approvable_id' => $po->id,
            'step_order' => 2,
            'role_slug' => 'finance_officer',
            'action' => 'pending',
            'is_current' => true,
        ]);

        $this->assertContains($po->po_number, $this->poNumbers($delegate));
    }

    public function test_view_only_role_without_delegation_sees_no_chain_pos(): void
    {
        $this->seed(WorkflowSeeder::class);
        // Same shape as the delegate test — purchasing.view, no approver tier —
        // but NO delegation. The pending finance step must stay hidden.
        $role = Role::create([
            'name' => 'PO viewer '.substr(uniqid(), -5),
            'slug' => 'po_viewer_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['purchasing.view'])->pluck('id')->all(),
        );
        $plainViewer = User::factory()->create(['role_id' => $role->id]);

        $po = PurchaseOrder::factory()->create([
            'status' => \App\Modules\Purchasing\Enums\PurchaseOrderStatus::PendingApproval->value,
            'created_by' => User::factory()->create()->id,
        ]);
        \App\Common\Models\ApprovalRecord::create([
            'approvable_type' => $po->getMorphClass(),
            'approvable_id' => $po->id,
            'step_order' => 2,
            'role_slug' => 'finance_officer',
            'action' => 'pending',
            'is_current' => true,
        ]);

        $this->assertNotContains($po->po_number, $this->poNumbers($plainViewer));
    }

    public function test_po_owner_and_global_role_can_show_and_pdf_a_po(): void
    {
        $role = Role::create([
            'name' => 'PO owner '.substr(uniqid(), -5),
            'slug' => 'po_owner_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['purchasing.view'])->pluck('id')->all(),
        );
        $owner = User::factory()->create(['role_id' => $role->id]);
        $global = $this->userWithRole('purchasing_officer');
        $po = $this->poIn(null, $owner->id);
        $pdfResponse = new StreamedResponse(static function (): void {}, 200, ['Content-Type' => 'application/pdf']);

        $this->mock(PurchaseOrderPdfService::class, function ($mock) use ($pdfResponse): void {
            $mock->shouldReceive('render')->andReturn($pdfResponse);
        });

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/pdf')
            ->assertOk();
        $this->actingAs($global, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk();
        $this->actingAs($global, 'sanctum')
            ->get('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/pdf')
            ->assertOk();
    }

    /**
     * PU-13 — the show endpoint must expose the server-computed action map.
     * PurchaseOrderResource calls actionsFor() on every detail response; when
     * the method went missing the endpoint 500'd outright, so this pins the
     * contract that the resource and the policy agree.
     */
    public function test_show_exposes_server_computed_actions_map(): void
    {
        $this->seed(WorkflowSeeder::class);
        $owner = $this->userWithRole('purchasing_officer');
        $draft = $this->poIn(null, $owner->id);

        $actions = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$draft->hash_id)
            ->assertOk()
            ->json('data.actions');

        $this->assertIsArray($actions);
        $this->assertTrue($actions['can_view']);
        $this->assertTrue($actions['can_update']);
        $this->assertTrue($actions['can_delete']);
        $this->assertTrue($actions['can_submit']);
        $this->assertFalse($actions['can_approve']);
        $this->assertFalse($actions['can_reject']);
        $this->assertFalse($actions['can_send']);
        $this->assertFalse($actions['can_close']);
    }

    public function test_actions_hide_approve_from_the_submitter_but_show_it_to_a_peer_approver(): void
    {
        $this->seed(WorkflowSeeder::class);
        $submitter = $this->userWithRole('finance_officer');
        $approver = $this->userWithRole('finance_officer');

        $po = PurchaseOrder::factory()->create([
            'status' => \App\Modules\Purchasing\Enums\PurchaseOrderStatus::PendingApproval->value,
            'created_by' => $submitter->id,
        ]);
        \App\Common\Models\ApprovalRecord::create([
            'approvable_type' => $po->getMorphClass(),
            'approvable_id' => $po->id,
            'step_order' => 2,
            'role_slug' => 'finance_officer',
            'action' => 'pending',
            'is_current' => true,
        ]);

        $selfActions = $this->actingAs($submitter, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk()
            ->json('data.actions');
        $this->assertFalse($selfActions['can_approve']);
        $this->assertFalse($selfActions['can_reject']);

        $peerActions = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk()
            ->json('data.actions');
        $this->assertTrue($peerActions['can_approve']);
        $this->assertTrue($peerActions['can_reject']);
    }

    /**
     * An approver tier (finance_officer holds purchasing.po.approve) can SEE a
     * draft via the global row scope, but must not be offered draft actions:
     * submit/update/delete are gated by purchasing.po.create on the route. The
     * action map used to call them "owner" and advertise a Submit button that
     * the route then refused with a 403.
     */
    public function test_approver_without_create_permission_is_not_offered_draft_actions(): void
    {
        $this->seed(WorkflowSeeder::class);
        $finance = $this->userWithRole('finance_officer');
        $buyer = $this->userWithRole('purchasing_officer');
        $draft = $this->poIn(null, $buyer->id);

        $actions = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-orders/'.$draft->hash_id)
            ->assertOk()
            ->json('data.actions');

        $this->assertTrue($actions['can_view']);
        $this->assertFalse($actions['can_submit']);
        $this->assertFalse($actions['can_update']);
        $this->assertFalse($actions['can_delete']);
        $this->assertFalse($actions['can_send']);
        $this->assertFalse($actions['can_close']);
    }
}
