<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Models\ApprovalRecord;
use App\Common\Exceptions\BusinessRuleException;
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
        $sameDepartmentRequester = $this->user('employee', $department);
        $otherDepartmentRequester = $this->user('employee', $otherDepartment);

        $visible = PurchaseRequest::factory()->create([
            'requested_by' => $sameDepartmentRequester->id,
            'department_id' => $department->id,
        ]);
        $hidden = PurchaseRequest::factory()->create([
            'requested_by' => $otherDepartmentRequester->id,
            'department_id' => $otherDepartment->id,
        ]);

        $this->actingAs($head)
            ->getJson('/api/v1/purchasing/purchase-requests')
            ->assertOk()
            ->assertJsonFragment(['id' => $visible->hash_id])
            ->assertJsonMissing(['id' => $hidden->hash_id]);

        $this->actingAs($head)
            ->getJson("/api/v1/purchasing/purchase-requests/{$hidden->hash_id}")
            ->assertForbidden();
    }

    public function test_pending_count_excludes_self_submissions_and_other_departments(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = $this->user('department_head', $department);
        $sameDepartmentRequester = $this->user('employee', $department);
        $otherDepartmentRequester = $this->user('employee', $otherDepartment);
        $service = app(PurchaseRequestService::class);

        $sameDepartment = $service->create($this->payload(), $sameDepartmentRequester);
        $service->submit($sameDepartment);
        $otherDepartmentPr = $service->create($this->payload(), $otherDepartmentRequester);
        $service->submit($otherDepartmentPr);
        $own = $service->create($this->payload(), $head);
        $service->submit($own);

        $this->actingAs($head)
            ->getJson('/api/v1/purchasing/purchase-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
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
