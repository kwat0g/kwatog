<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalBoardService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the board's row-level visibility matrix (2026-09 audit AQ-1/2/3).
 *
 * Before this, cards were gated on module READ permissions alone, and
 * `leave.view` is a self-scoped permission granted to every role — so every
 * employee saw every other employee's pending leave cards, dates and history.
 * The rule now pinned: a card renders a record only if the owning module's
 * own list endpoint would show that record to that user.
 */
class ApprovalBoardScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;

    private Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(PositionSeeder::class);

        $departments = Department::query()->orderBy('id')->take(2)->get();
        $this->deptA = $departments->first();
        $this->deptB = $departments->last();
        $this->assertNotSame($this->deptA->id, $this->deptB->id);
    }

    private function user(string $roleSlug, ?int $departmentId = null): User
    {
        $attributes = ['role_id' => Role::query()->where('slug', $roleSlug)->value('id')];
        if ($departmentId !== null) {
            $attributes['employee_id'] = Employee::factory()->create(['department_id' => $departmentId])->id;
        }

        return User::factory()->create($attributes);
    }

    /** @param array<string, mixed> $overrides */
    private function pendingStep(string $class, int $approvableId, string $roleSlug, array $overrides = []): void
    {
        DB::table('approval_records')->insert(array_merge([
            'approvable_type' => $class,
            'approvable_id' => $approvableId,
            'step_order' => 1,
            'role_slug' => $roleSlug,
            'action' => 'pending',
            'is_current' => true,
            'created_at' => now(),
        ], $overrides));
    }

    /** @return array<int, string> */
    private function numbers(array $cards): array
    {
        return array_values(array_map(static fn (array $card): string => (string) $card['number'], $cards));
    }

    private function board(User $user): array
    {
        return app(ApprovalBoardService::class)->board($user);
    }

    public function test_plain_employee_sees_only_their_own_leave_cards(): void
    {
        $mine = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $stranger = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'employee_id' => $mine->id,
        ]);

        $ownLeave = LeaveRequest::factory()->create(['employee_id' => $mine->id]);
        $otherLeave = LeaveRequest::factory()->create(['employee_id' => $stranger->id]);
        $this->pendingStep(LeaveRequest::class, $ownLeave->id, 'department_head');
        $this->pendingStep(LeaveRequest::class, $otherLeave->id, 'department_head');

        $board = $this->board($employee);

        $this->assertSame([$ownLeave->leave_request_no], $this->numbers($board['awaiting_others']));
        $this->assertSame([], $board['my_action']);
    }

    public function test_department_head_sees_own_department_leaves_only(): void
    {
        $alphaMember = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $betaMember = Employee::factory()->create(['department_id' => $this->deptB->id]);
        $head = $this->user('department_head', $this->deptA->id);

        $alphaLeave = LeaveRequest::factory()->create(['employee_id' => $alphaMember->id]);
        $betaLeave = LeaveRequest::factory()->create(['employee_id' => $betaMember->id]);
        $this->pendingStep(LeaveRequest::class, $alphaLeave->id, 'department_head');
        $this->pendingStep(LeaveRequest::class, $betaLeave->id, 'department_head');

        $board = $this->board($head);

        $this->assertSame([$alphaLeave->leave_request_no], $this->numbers($board['my_action']));
        $this->assertSame([], $board['awaiting_others']);
    }

    public function test_hr_sees_every_leave_card(): void
    {
        $alphaMember = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $betaMember = Employee::factory()->create(['department_id' => $this->deptB->id]);
        $hr = $this->user('hr_officer');

        $alphaLeave = LeaveRequest::factory()->create(['employee_id' => $alphaMember->id]);
        $betaLeave = LeaveRequest::factory()->create(['employee_id' => $betaMember->id]);
        $this->pendingStep(LeaveRequest::class, $alphaLeave->id, 'department_head');
        $this->pendingStep(LeaveRequest::class, $betaLeave->id, 'department_head');

        $board = $this->board($hr);

        $visible = array_merge($this->numbers($board['my_action']), $this->numbers($board['awaiting_others']));
        sort($visible);
        $expected = [$alphaLeave->leave_request_no, $betaLeave->leave_request_no];
        sort($expected);
        $this->assertSame($expected, $visible);
    }

    public function test_loan_cards_follow_the_loan_policy_scope(): void
    {
        $alphaMember = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $betaMember = Employee::factory()->create(['department_id' => $this->deptB->id]);
        $head = $this->user('department_head', $this->deptA->id);
        $hr = $this->user('hr_officer');

        $alphaLoan = EmployeeLoan::factory()->create(['employee_id' => $alphaMember->id]);
        $betaLoan = EmployeeLoan::factory()->create(['employee_id' => $betaMember->id]);
        $this->pendingStep(EmployeeLoan::class, $alphaLoan->id, 'department_head');
        $this->pendingStep(EmployeeLoan::class, $betaLoan->id, 'department_head');

        $headBoard = $this->board($head);
        $this->assertSame([$alphaLoan->loan_no], $this->numbers($headBoard['my_action']));
        $this->assertSame([], $headBoard['awaiting_others']);

        $hrBoard = $this->board($hr);
        $visible = array_merge($this->numbers($hrBoard['my_action']), $this->numbers($hrBoard['awaiting_others']));
        sort($visible);
        $expected = [$alphaLoan->loan_no, $betaLoan->loan_no];
        sort($expected);
        $this->assertSame($expected, $visible);
    }

    public function test_pr_cards_follow_the_pr_policy_scope(): void
    {
        $head = $this->user('department_head', $this->deptA->id);
        $purchasing = $this->user('purchasing_officer');

        $alphaPr = PurchaseRequest::factory()->create(['department_id' => $this->deptA->id]);
        $betaPr = PurchaseRequest::factory()->create(['department_id' => $this->deptB->id]);
        $this->pendingStep(PurchaseRequest::class, $alphaPr->id, 'department_head');
        $this->pendingStep(PurchaseRequest::class, $betaPr->id, 'department_head');

        $headBoard = $this->board($head);
        $this->assertSame([$alphaPr->pr_number], $this->numbers($headBoard['my_action']));
        $this->assertSame([], $headBoard['awaiting_others']);

        $purchasingBoard = $this->board($purchasing);
        $visible = array_merge($this->numbers($purchasingBoard['my_action']), $this->numbers($purchasingBoard['awaiting_others']));
        sort($visible);
        $expected = [$alphaPr->pr_number, $betaPr->pr_number];
        sort($expected);
        $this->assertSame($expected, $visible);
    }

    public function test_history_columns_obey_the_same_row_scope(): void
    {
        $mine = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $stranger = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'employee_id' => $mine->id,
        ]);

        $ownLeave = LeaveRequest::factory()->approved()->create(['employee_id' => $mine->id]);
        $otherLeave = LeaveRequest::factory()->approved()->create(['employee_id' => $stranger->id]);
        foreach ([$ownLeave, $otherLeave] as $leave) {
            DB::table('approval_records')->insert([
                'approvable_type' => LeaveRequest::class,
                'approvable_id' => $leave->id,
                'step_order' => 2,
                'role_slug' => 'hr_officer',
                'action' => 'approved',
                'is_current' => true,
                'acted_at' => now()->subHour(),
                'approver_id' => $this->user('hr_officer')->id,
                'created_at' => now()->subDay(),
            ]);
        }

        $board = $this->board($employee);

        $this->assertSame([$ownLeave->leave_request_no], $this->numbers($board['approved']));
        $this->assertSame([], $board['rejected']);
        $this->assertSame([], $board['awaiting_others']);
    }

    public function test_payroll_cards_stay_permission_gated(): void
    {
        $hr = $this->user('hr_officer');
        $employee = $this->user('employee', $this->deptA->id);

        $period = PayrollPeriod::factory()->create();
        $this->pendingStep(PayrollPeriod::class, $period->id, 'finance_officer');

        $this->assertCount(1, $this->board($hr)['awaiting_others']);
        $this->assertSame([], $this->board($employee)['awaiting_others']);
    }

    public function test_out_of_scope_step_participant_still_gets_a_masked_card(): void
    {
        $delegator = $this->user('department_head', $this->deptA->id);
        $delegate = $this->user('employee', $this->deptB->id);
        $alphaMember = Employee::factory()->create(['department_id' => $this->deptA->id]);
        $pr = PurchaseRequest::factory()->create(['department_id' => $this->deptA->id]);
        $this->pendingStep(PurchaseRequest::class, $pr->id, 'department_head');

        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $board = $this->board($delegate);

        $this->assertCount(1, $board['my_action']);
        $this->assertSame('Restricted', $board['my_action'][0]['number']);
        $this->assertNull($board['my_action'][0]['amount']);
        $this->assertNull($board['my_action'][0]['requester']);
    }
}
