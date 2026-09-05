<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalBoardService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
        ]);
    }

    private function pendingPr(string $role, ?int $departmentId = null): PurchaseRequest
    {
        $pr = PurchaseRequest::factory()->create(
            $departmentId === null ? [] : ['department_id' => $departmentId],
        );
        DB::table('approval_records')->insert([
            'approvable_type' => PurchaseRequest::class,
            'approvable_id' => $pr->id,
            'step_order' => 1,
            'role_slug' => $role,
            'action' => 'pending',
            'created_at' => now(),
        ]);

        return $pr;
    }

    public function test_active_delegate_is_classified_in_my_action_with_masked_module_data(): void
    {
        $delegator = $this->user('department_head');
        $delegate = $this->user('employee');
        $pr = $this->pendingPr('department_head');

        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $board = app(ApprovalBoardService::class)->board($delegate);

        $this->assertCount(1, $board['my_action']);
        $this->assertSame('pr', $board['my_action'][0]['type']);
        $this->assertSame('Restricted', $board['my_action'][0]['number']);
        $this->assertNull($board['my_action'][0]['amount']);
        $this->assertNotSame('', $pr->pr_number);
    }

    public function test_broad_board_access_does_not_expose_foreign_module_cards(): void
    {
        $employee = $this->user('employee');
        $this->pendingPr('department_head');

        $board = app(ApprovalBoardService::class)->board($employee);

        $this->assertSame([], $board['my_action']);
        $this->assertSame([], $board['awaiting_others']);
    }

    public function test_board_reports_bounded_pending_results_and_consistent_summary(): void
    {
        // The board now reuses the module's row scope, so the approver must
        // actually be able to see the PRs in the Purchasing module: a
        // department head linked to the requesting department.
        $department = Department::factory()->create();
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $this->pendingPr('department_head', $department->id);
        $this->pendingPr('department_head', $department->id);

        $board = app(ApprovalBoardService::class)->board($approver, null, 1, 1);

        $this->assertCount(1, $board['my_action']);
        $this->assertSame(1, $board['summary']['my_action']);
        $this->assertTrue($board['meta']['pending_truncated']);
        $this->assertSame(1, $board['meta']['pending_limit']);
    }
}
