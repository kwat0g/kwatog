<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    public function test_department_head_rows_are_scoped_on_list_and_direct_show(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = $this->user('department_head', $department);
        $sameDepartmentRequester = $this->user('purchasing_officer', $department);
        $otherDepartmentRequester = $this->user('purchasing_officer', $otherDepartment);

        $visible = PurchaseRequest::factory()->create([
            'requested_by' => $sameDepartmentRequester->id,
            'department_id' => $department->id,
        ]);
        $hidden = PurchaseRequest::factory()->create([
            'requested_by' => $otherDepartmentRequester->id,
            'department_id' => $otherDepartment->id,
        ]);

        // Assert on the collection's own ids, not a loose JSON fragment. A hash
        // id encodes the integer key with no per-model salt, so PR #N and user
        // #N hash identically — assertJsonMissing(['id' => …]) then matches the
        // nested `requester` object and fails on row counts that are correct.
        $listed = $this->actingAs($head)
            ->getJson('/api/v1/purchasing/purchase-requests')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$visible->hash_id], $listed);

        $this->actingAs($head)
            ->getJson("/api/v1/purchasing/purchase-requests/{$hidden->hash_id}")
            ->assertForbidden();
    }

    public function test_pending_count_excludes_self_submissions_and_other_roles_steps(): void
    {
        // 2026-09-10 chain: the badge counts steps the caller can act on. A
        // finance officer sees both submitted PRs (step 1 is theirs on each)
        // but NOT their own submission; a department head — holder of no step
        // in the money-only chain — sees zero.
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $finance = $this->user('finance_officer', $department);
        $requesterOne = $this->user('purchasing_officer', $department);
        $requesterTwo = $this->user('department_head', $otherDepartment);
        $service = app(PurchaseRequestService::class);

        $one = $service->create($this->payload(), $requesterOne);
        $service->submit($one);
        $two = $service->create($this->payload(), $requesterTwo);
        $service->submit($two);
        $own = $service->create($this->payload(), $finance);
        $service->submit($own);

        $this->actingAs($finance)
            ->getJson('/api/v1/purchasing/purchase-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->actingAs($this->user('department_head', $department))
            ->getJson('/api/v1/purchasing/purchase-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_total_estimate_and_line_total_keep_centavo_precision(): void
    {
        $pr = PurchaseRequest::factory()->create();
        $line = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'description' => 'Precision item',
            'quantity' => '0.10',
            'unit' => 'pcs',
            'estimated_unit_price' => '0.10',
        ]);

        $this->assertSame('0.01', $line->estimated_total);
        $this->assertSame('0.01', $pr->fresh()->totalEstimatedAmount());
    }

    public function test_generated_pr_must_resolve_a_department_before_submit(): void
    {
        $service = app(PurchaseRequestService::class);
        $pr = PurchaseRequest::factory()->create([
            'department_id' => null,
            'is_auto_generated' => true,
        ]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'description' => 'Generated item',
            'quantity' => '1.00',
            'estimated_unit_price' => '10.00',
        ]);

        $this->expectException(BusinessRuleException::class);
        $service->submit($pr);
    }

    public function test_generated_pr_inherits_requester_department_before_budget_assessment(): void
    {
        $department = Department::factory()->create();
        $requester = $this->user('employee', $department);
        $service = app(PurchaseRequestService::class);
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $requester->id,
            'department_id' => null,
            'is_auto_generated' => true,
        ]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'description' => 'Generated item',
            'quantity' => '1.00',
            'estimated_unit_price' => '10.00',
        ]);

        $submitted = $service->submit($pr);

        $this->assertSame(PurchaseRequestStatus::Pending, $submitted->status);
        $this->assertSame($department->id, $submitted->fresh()->department_id);
    }

    public function test_second_submit_cannot_recreate_workflow_records(): void
    {
        $service = app(PurchaseRequestService::class);
        $pr = $service->create($this->payload(), $this->user('system_admin'));
        $service->submit($pr);
        $attemptsBefore = ApprovalRecord::query()
            ->where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->id)
            ->count();

        $this->expectException(BusinessRuleException::class);
        try {
            $service->submit($pr->fresh());
        } finally {
            $this->assertSame($attemptsBefore, ApprovalRecord::query()
                ->where('approvable_type', $pr->getMorphClass())
                ->where('approvable_id', $pr->id)
                ->count());
        }
    }

    public function test_priority_marks_request_urgent_for_workflow_and_wire_contract(): void
    {
        $service = app(PurchaseRequestService::class);
        $pr = $service->create([
            ...$this->payload(),
            'priority' => 'critical',
        ], $this->user('system_admin'));

        $this->assertTrue($pr->is_urgent);
        $submitted = $service->submit($pr);

        $this->assertTrue($submitted->is_urgent);
        $this->assertSame('critical', $submitted->priority->value);
        $this->assertSame('pending', ApprovalRecord::query()
            ->where('approvable_id', $submitted->id)
            ->where('step_order', 1)
            ->value('action'));
    }

    public function test_show_returns_persisted_suggested_vendor(): void
    {
        $admin = $this->user('system_admin');
        $vendor = Vendor::factory()->create();
        $pr = PurchaseRequest::factory()->create();
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'description' => 'Vendor item',
            'quantity' => '1.00',
            'estimated_unit_price' => '25.00',
            'suggested_vendor_id' => $vendor->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.suggested_vendor.id', $vendor->hash_id);
    }

    /**
     * Drift guard. A workflow step whose role cannot pass the route middleware
     * is a stall, not a control: the approve route is
     * `permission:purchasing.pr.approve` and show is `permission:purchasing.view`,
     * so every role the seeded chain names must hold both. This is the defect
     * L-37 already recorded for return_management.approve, and it recurred here
     * on production_manager (step 2, "Manager").
     */
    public function test_every_seeded_approval_step_role_can_reach_the_approve_route(): void
    {
        $workflow = WorkflowDefinition::query()
            ->where('workflow_type', 'purchase_request')
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertNotEmpty($workflow->steps);

        foreach ($workflow->steps as $step) {
            $slug = (string) $step['role'];
            $user = $this->user($slug);

            $this->assertTrue(
                $user->hasPermission('purchasing.pr.approve'),
                "Chain step {$step['order']} routes to '{$slug}', which cannot reach the approve route.",
            );
            $this->assertTrue(
                $user->hasPermission('purchasing.view'),
                "Chain step {$step['order']} routes to '{$slug}', which cannot open the request it must approve.",
            );
        }
    }

    public function test_finance_can_open_and_approve_any_department_request(): void
    {
        $department = Department::factory()->create();
        $requester = $this->user('purchasing_officer', $department);
        // Deliberately a different department: finance is a company-level
        // office, so its step must not be department-scoped.
        $finance = $this->user('finance_officer', Department::factory()->create());
        $service = app(PurchaseRequestService::class);

        $pr = $service->create($this->payload(), $requester);
        $service->submit($pr);

        $this->actingAs($finance)
            ->getJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}")
            ->assertOk();

        $this->actingAs($finance)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve", [
                'remarks' => 'Finance approved',
            ])
            ->assertOk();

        $this->assertSame('approved', ApprovalRecord::query()
            ->where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->id)
            ->where('step_order', 1)
            ->value('action'));
    }

    public function test_department_head_cannot_approve_the_finance_step(): void
    {
        $department = Department::factory()->create();
        $requester = $this->user('purchasing_officer', $department);
        $head = $this->user('department_head', $department);
        $service = app(PurchaseRequestService::class);

        $pr = $service->create($this->payload(), $requester);
        $service->submit($pr);

        // The chain is money-only now: the department head holds no step, so
        // ApprovalService refuses them even on their own department's request.
        $this->expectException(ForbiddenActionException::class);
        $service->approve($pr->fresh(), $head, 'Not my step');
    }

    public function test_purchasing_cannot_assign_foreign_department_on_create(): void
    {
        // PU-06 — purchasing (central desk) must record the REQUESTING
        // department, not charge a foreign department's budget. Admin may
        // assign any department; purchasing may assign any too (desk), but a
        // department head may only create for their own.
        $department = Department::factory()->create();
        $head = $this->user('department_head', $department);
        $service = app(PurchaseRequestService::class);

        $this->expectException(ForbiddenActionException::class);
        $service->create([...$this->payload(), 'department_id' => Department::factory()->create()->id], $head);
    }

    public function test_department_head_creates_for_own_department(): void
    {
        $department = Department::factory()->create();
        $head = $this->user('department_head', $department);
        $service = app(PurchaseRequestService::class);

        $pr = $service->create([...$this->payload(), 'department_id' => $department->id], $head);

        $this->assertSame($department->id, $pr->department_id);
        $this->assertSame($head->id, (int) $pr->requested_by);
    }

    public function test_purchasing_desk_can_raise_for_a_chosen_department(): void
    {
        $department = Department::factory()->create();
        $officer = $this->user('purchasing_officer', Department::factory()->create());
        $service = app(PurchaseRequestService::class);

        $pr = $service->create([...$this->payload(), 'department_id' => $department->id], $officer);

        $this->assertSame($department->id, $pr->department_id);
    }

    public function test_auto_pr_bypasses_department_assignment_guard(): void
    {
        $department = Department::factory()->create();
        $service = app(PurchaseRequestService::class);

        // MRP/low-stock automation passes is_auto_generated and a department
        // resolved from demand — no human creator to scope.
        $pr = $service->create([...$this->payload(), 'department_id' => $department->id, 'is_auto_generated' => true], $this->user('system_admin'));

        $this->assertSame($department->id, $pr->department_id);
    }

    /** @return array{priority:string,items:array<int,array<string,string>>} */
    private function payload(): array
    {
        return [
            'priority' => 'normal',
            'items' => [[
                'description' => 'Test item',
                'quantity' => '1.00',
                'unit' => 'pcs',
                'estimated_unit_price' => '10.00',
            ]],
        ];
    }

    private function user(string $role, ?Department $department = null): User
    {
        $employeeId = null;
        if ($department !== null) {
            $employeeId = Employee::factory()->create(['department_id' => $department->id])->id;
        }

        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
            'employee_id' => $employeeId,
        ]);
    }
}
