<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalBoardService;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
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
        $this->assertSame(2, $board['summary']['my_action']);
        $this->assertTrue($board['meta']['pending_truncated']);
        $this->assertSame(1, $board['meta']['pending_limit']);

        $pageTwo = app(ApprovalBoardService::class)->board($approver, null, 1, 1, 2);
        $this->assertSame(2, $pageTwo['summary']['my_action']);
        $this->assertCount(1, $pageTwo['my_action']);
    }

    public function test_every_advertised_approval_filter_is_accepted_by_the_board(): void
    {
        $approver = $this->user('department_head');
        $kinds = $this->actingAs($approver)
            ->getJson('/api/v1/approvals/options')
            ->assertOk()
            ->json('data.kinds');

        $this->assertSame(ApprovalTypeRegistry::kindOptions($approver), $kinds);
        foreach ($kinds as $kind) {
            $this->getJson('/api/v1/approvals/board?type='.urlencode($kind['value']))->assertOk();
        }
    }

    public function test_filter_options_and_requests_follow_the_users_permissions(): void
    {
        $employee = $this->user('employee');
        $kinds = $this->actingAs($employee)
            ->getJson('/api/v1/approvals/options')
            ->assertOk()
            ->json('data.kinds');
        $values = array_column($kinds, 'value');

        $this->assertContains('leave', $values);
        $this->assertContains('overtime', $values);
        $this->assertNotContains('pr', $values);
        $this->getJson('/api/v1/approvals/board?type=pr')->assertUnprocessable();
    }

    public function test_approval_only_user_sees_only_actionable_budget_board_cards(): void
    {
        $approver = $this->user('employee');
        $maker = $this->user('finance_officer');
        UserPermissionOverride::create([
            'user_id' => $approver->id,
            'permission_id' => Permission::query()->where('slug', 'budgeting.approve')->firstOrFail()->id,
            'type' => 'grant',
            'granted_by' => $maker->id,
            'reason' => 'Approval-only budget board visibility test.',
        ]);
        $approver->flushPermissionsCache();
        $actionable = Budget::factory()->create([
            'status' => 'submitted',
            'submitted_by' => $maker->id,
            'submitted_at' => now(),
        ]);
        $transferBudget = Budget::factory()->create(['status' => 'active']);
        $accounts = collect(['A', 'B'])->map(fn (string $suffix): Account => Account::create([
            'code' => 'BOARD-'.$suffix.'-'.substr(uniqid(), -6),
            'name' => 'Board transfer '.$suffix,
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]));
        $lines = $accounts->map(fn (Account $account): BudgetLineItem => BudgetLineItem::create([
            'budget_id' => $transferBudget->id,
            'account_id' => $account->id,
            'jan' => '100.00',
        ]));
        DB::table('budget_transfers')->insert([
            'transfer_number' => 'BOARD-'.substr(uniqid(), -6),
            'from_line_item_id' => $lines[0]->id,
            'to_line_item_id' => $lines[1]->id,
            'month' => 'jan',
            'amount' => '1.00',
            'reason' => 'Approval-only board visibility test.',
            'status' => 'pending',
            'requested_by' => $maker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Budget::factory()->create([
            'status' => 'submitted',
            'submitted_by' => $approver->id,
            'submitted_at' => now(),
        ]);

        $this->assertFalse($approver->hasPermission('budgeting.view'));
        $kinds = array_column(ApprovalTypeRegistry::kindOptions($approver), 'value');
        $this->assertContains('budget', $kinds);
        $this->assertContains('budget_transfer', $kinds);
        $board = app(ApprovalBoardService::class)->board($approver);
        $budgetCards = array_values(array_filter($board['my_action'], static fn (array $card): bool => $card['type'] === 'budget'));
        $transferCards = array_values(array_filter($board['my_action'], static fn (array $card): bool => $card['type'] === 'budget_transfer'));

        $this->assertSame([$actionable->hash_id], array_column($budgetCards, 'id'));
        $this->assertCount(1, $transferCards);
        $this->assertSame([], $board['awaiting_others']);
    }
}
