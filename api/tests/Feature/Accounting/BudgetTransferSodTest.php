<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * REC-02 — maker-checker / segregation of duties on budget-transfer approval.
 * The user who requested a transfer may not also approve it.
 */
class BudgetTransferSodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    /** Non-admin so hasPermission() does not short-circuit the override. */
    private function financeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    private function makeTransfer(int $requestedBy): BudgetTransfer
    {
        $budget = Budget::factory()->create(['status' => 'approved']);
        $accounts = Account::query()->limit(2)->pluck('id')->all();
        $from = BudgetLineItem::create([
            'budget_id'    => $budget->id,
            'account_id'   => $accounts[0],
            'jan'          => '5000.00',
            'actual_total' => '0',
        ]);
        $to = BudgetLineItem::create([
            'budget_id'    => $budget->id,
            'account_id'   => $accounts[1],
            'jan'          => '0',
            'actual_total' => '0',
        ]);

        return BudgetTransfer::create([
            'from_budget_line_id' => $from->id,
            'to_budget_line_id'   => $to->id,
            'amount'              => '1000.00',
            'reason'              => 'reallocation',
            'status'              => 'pending',
            'requested_by'        => $requestedBy,
        ]);
    }

    public function test_requester_cannot_self_approve(): void
    {
        $svc = app(BudgetTransferService::class);
        $requester = $this->financeUser();
        $transfer = $this->makeTransfer($requester->id);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('segregation of duties');
        $svc->approve($transfer, $requester->id);
    }

    public function test_different_user_can_approve(): void
    {
        $svc = app(BudgetTransferService::class);
        $requester = $this->financeUser();
        $approver = $this->financeUser();
        $transfer = $this->makeTransfer($requester->id);

        $approved = $svc->approve($transfer, $approver->id);

        $this->assertSame('approved', $approved->status);
        $this->assertSame((int) $approver->id, (int) $approved->approved_by);
    }

    public function test_override_permission_allows_self_approve(): void
    {
        $svc = app(BudgetTransferService::class);
        $requester = $this->financeUser();
        $transfer = $this->makeTransfer($requester->id);

        $perm = Permission::firstOrCreate(
            ['slug' => 'budgeting.transfers.self_approve_override'],
            ['name' => 'Approve Own Budget Transfer (override)', 'module' => 'budgeting']
        );
        UserPermissionOverride::create([
            'user_id'       => $requester->id,
            'permission_id' => $perm->id,
            'type'          => 'grant',
            'granted_by'    => $requester->id,
            'reason'        => 'Test override grant',
        ]);
        $requester->flushPermissionsCache();

        $approved = $svc->approve($transfer, $requester->id);

        $this->assertSame('approved', $approved->status);
    }
}
