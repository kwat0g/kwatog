<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestBulkApproveTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_approve_dept_partial_success(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $deptHeadRole = Role::query()->where('slug', 'department_head')->firstOrFail();
        $approver = User::factory()->create([
            'role_id'   => $deptHeadRole->id,
            'is_active' => true,
        ]);

        $svc = app(LeaveRequestService::class);

        $emp  = Employee::factory()->create();
        $type = LeaveType::query()->first();
        $date = now()->addWeek()->toDateString();

        // r1: properly submitted -> PendingDept with approval records.
        $r1 = $svc->submit($emp->id, [
            'start_date'    => $date,
            'end_date'      => $date,
            'leave_type_id' => $type->id,
        ]);

        // r2: wrong-state row (PendingHr) without approval records -> should fail.
        $r2 = LeaveRequest::factory()->create([
            'employee_id' => $emp->id,
            'status'      => LeaveRequestStatus::PendingHr->value,
        ]);

        $result = $svc->bulkApproveDept([$r1->id, $r2->id, 999999], $approver, 'batch ok');

        $this->assertCount(1, $result['approved'], 'failed='.json_encode($result['failed']));
        $this->assertCount(2, $result['failed']);

        $failedIds = array_column($result['failed'], 'id');
        $this->assertContains($r2->id, $failedIds);
        $this->assertContains(999999, $failedIds);

        $this->assertSame(LeaveRequestStatus::PendingHr, $r1->fresh()->status);
    }

    public function test_list_search_filters_by_request_no_and_employee(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $emp  = Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Dela Cruz']);
        $type = LeaveType::query()->first();
        $svc  = app(LeaveRequestService::class);

        $r1 = $svc->submit($emp->id, [
            'start_date'    => now()->addWeek()->toDateString(),
            'end_date'      => now()->addWeek()->toDateString(),
            'leave_type_id' => $type->id,
        ]);
        $r2 = $svc->submit($emp->id, [
            'start_date'    => now()->addWeeks(2)->toDateString(),
            'end_date'      => now()->addWeeks(2)->toDateString(),
            'leave_type_id' => $type->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/leaves/requests?search=dela%20cruz')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)
            ->getJson('/api/v1/leaves/requests?search=' . $r1->leave_request_no)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $r1->hash_id);

        $this->actingAs($admin)
            ->getJson('/api/v1/leaves/requests?search=no-such-name-xyz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
