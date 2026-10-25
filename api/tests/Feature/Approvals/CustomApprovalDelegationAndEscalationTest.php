<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalBoardService;
use App\Common\Services\ApprovalEscalationService;
use App\Common\Services\CustomApprovalEscalationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomApprovalDelegationAndEscalationTest extends TestCase
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
            'is_active' => true,
        ]);
    }

    private function delegate(User $delegator, User $delegate): void
    {
        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => $delegator->role->slug,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
    }

    public function test_custom_approval_delegation_requires_existing_queue_read_access(): void
    {
        $delegator = $this->user('finance_officer');
        $canRead = $this->user('purchasing_officer');
        $cannotRead = $this->user('employee');
        $this->delegate($delegator, $canRead);
        $this->delegate($delegator, $cannotRead);

        $this->assertTrue(ApprovalDelegation::canActForPermission($canRead, 'inventory.costs.approve'));
        $this->assertFalse(ApprovalDelegation::canActForPermission($cannotRead, 'inventory.costs.approve'));
        $this->assertContains('inventory.costs.approve', ApprovalDelegation::delegatedApprovalPermissions($canRead));
        $this->assertNotContains('inventory.costs.approve', ApprovalDelegation::delegatedApprovalPermissions($cannotRead));
    }

    public function test_custom_sweep_notifies_stale_payroll_period_without_resolving_it(): void
    {
        Queue::fake();
        Mail::fake();
        $maker = $this->user('hr_officer');
        $approver = $this->user('finance_officer');
        $old = now()->subHours(3);
        app(SettingsService::class)->set('approvals.reminder_hours', 1, 'approval');
        app(SettingsService::class)->set('approvals.escalation_hours', 1, 'approval');
        app(SettingsService::class)->set('approvals.auto_resolve.enabled', false, 'approval');
        app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 0, 'inventory');

        $periodId = DB::table('payroll_periods')->insertGetId([
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-15',
            'payroll_date' => '2026-10-15',
            'is_first_half' => true,
            'status' => 'computed',
            'created_by' => $maker->id,
            'computed_by' => $maker->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $outcome = app(ApprovalEscalationService::class)->runWithOutcome();
        $this->assertSame(1, $outcome['reminders']);
        $this->assertSame(1, $outcome['escalations']);
        $this->assertSame(0, $outcome['auto_resolved']);
        $this->assertSame(0, $outcome['failed']);
        $this->assertSame(0, app(CustomApprovalEscalationService::class)->runReminders());

        $period = DB::table('payroll_periods')->where('id', $periodId)->first();
        $this->assertSame('computed', $period->status);
        $this->assertNull($period->approved_by);
        $this->assertDatabaseCount('approval_records', 0);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $approver->id,
            'type' => 'approval_reminder',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $approver->id,
            'type' => 'approval_escalation',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $maker->id,
            'type' => 'approval_reminder',
        ]);
    }

    public function test_custom_sweep_preserves_budget_different_role_rule(): void
    {
        Queue::fake();
        Mail::fake();
        $maker = $this->user('finance_officer');
        $sameRoleApprover = $this->user('finance_officer');
        $differentRoleApprover = $this->user('vice_president');
        $actionOnlyApprover = $this->user('employee');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actionOnlyApprover->id,
            'permission_id' => DB::table('permissions')->where('slug', 'budgeting.approve')->value('id'),
            'type' => 'grant',
            'granted_by' => $maker->id,
            'reason' => 'Audience regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actionOnlyApprover->flushPermissionsCache();
        $this->assertFalse($actionOnlyApprover->hasPermission('budgeting.view'));
        $ineligibleApprover = $this->user('vice_president');
        foreach (['budgeting.view', 'budgeting.approve'] as $permission) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $ineligibleApprover->id,
                'permission_id' => DB::table('permissions')->where('slug', $permission)->value('id'),
                'type' => 'revoke',
                'granted_by' => $maker->id,
                'reason' => 'Audience regression test.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $ineligibleApprover->flushPermissionsCache();
        $this->assertFalse($ineligibleApprover->hasPermission('budgeting.view'));
        $this->assertFalse($ineligibleApprover->hasPermission('budgeting.approve'));
        $old = now()->subHours(3);
        app(SettingsService::class)->set('approvals.reminder_hours', 1, 'approval');
        app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 0, 'inventory');
        $fiscalYearId = DB::table('fiscal_years')->insertGetId([
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $budgetId = DB::table('budgets')->insertGetId([
            'fiscal_year_id' => $fiscalYearId,
            'budget_type' => 'department',
            'name' => 'Stale budget',
            'status' => 'submitted',
            'submitted_by' => $maker->id,
            'submitted_at' => $old,
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $accountId = DB::table('accounts')->insertGetId([
            'code' => 'SLA-'.substr(uniqid(), -6),
            'name' => 'SLA test account',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lineItemId = DB::table('budget_line_items')->insertGetId([
            'budget_id' => $budgetId,
            'account_id' => $accountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('budget_transfers')->insert([
            'transfer_number' => 'SLA-'.substr(uniqid(), -6),
            'from_line_item_id' => $lineItemId,
            'to_line_item_id' => $lineItemId,
            'month' => 'jan',
            'amount' => '1.00',
            'reason' => 'SLA reminder fixture',
            'status' => 'pending',
            'requested_by' => $maker->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $this->assertSame(2, app(CustomApprovalEscalationService::class)->runReminders());
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $sameRoleApprover->id,
            'type' => 'approval_reminder',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $differentRoleApprover->id,
            'type' => 'approval_reminder',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $actionOnlyApprover->id,
            'type' => 'approval_reminder',
        ]);
        $differentRoleNoticeLinks = DB::table('notifications')
            ->where('notifiable_id', $differentRoleApprover->id)
            ->where('type', 'approval_reminder')
            ->pluck('data')
            ->map(fn (string $json): ?string => json_decode($json, true)['link_to'] ?? null);
        $this->assertTrue($actionOnlyNoticeLinks->contains(fn (?string $link): bool => str_starts_with($link ?? '', '/budgeting/transfers/')));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $viewRevokedApprover->id,
            'type' => 'approval_reminder',
        ]);
        $viewRevokedNoticeLinks = DB::table('notifications')
            ->where('notifiable_id', $viewRevokedApprover->id)
            ->where('type', 'approval_reminder')
            ->pluck('data')
            ->map(fn (string $json): ?string => json_decode($json, true)['link_to'] ?? null);
        $this->assertTrue($viewRevokedNoticeLinks->contains(fn (?string $link): bool => str_starts_with($link ?? '', '/budgeting/transfers/')));
    }

    public function test_escalation_does_not_readd_an_excluded_system_admin_invoice_editor(): void
    {
        $maker = $this->user('hr_officer');
        $approver = $this->user('finance_officer');
        $editor = $this->user('system_admin');
        $old = now()->subHours(3);
        app(SettingsService::class)->set('approvals.escalation_hours', 1, 'approval');
        app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 0, 'inventory');

        $invoice = Invoice::factory()->create([
            'lifecycle_type' => 'prebill',
            'prebill_reason' => 'Stale prebill review.',
            'prebill_approved_by' => null,
            'prebill_approved_at' => null,
            'status' => 'draft',
            'created_by' => $maker->id,
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        DB::table('audit_logs')->insert([
            'user_id' => $editor->id,
            'action' => 'updated',
            'model_type' => $invoice->getMorphClass(),
            'model_id' => $invoice->id,
            'new_values' => json_encode(['prebill_reason' => 'Edited after submission.']),
            'created_at' => now(),
        ]);

        $this->assertSame(1, app(CustomApprovalEscalationService::class)->runEscalations());
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $approver->id,
            'type' => 'approval_escalation',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $editor->id,
            'type' => 'approval_escalation',
        ]);
    }

    public function test_budget_board_hides_same_role_approvals(): void
    {
        $approver = $this->user('finance_officer');
        $sameRoleMaker = $this->user('finance_officer');
        $differentRoleMaker = $this->user('vice_president');
        $fiscalYearId = DB::table('fiscal_years')->insertGetId([
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $budgetIds = [];
        foreach ([[$sameRoleMaker, 'Same role'], [$differentRoleMaker, 'Different role']] as [$maker, $name]) {
            $budgetIds[$name] = DB::table('budgets')->insertGetId([
                'fiscal_year_id' => $fiscalYearId,
                'budget_type' => 'department',
                'name' => $name,
                'status' => 'submitted',
                'submitted_by' => $maker->id,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $accountId = DB::table('accounts')->insertGetId([
            'code' => 'BRD-'.substr(uniqid(), -6),
            'name' => 'Board test account',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lineItemId = DB::table('budget_line_items')->insertGetId([
            'budget_id' => $budgetIds['Different role'],
            'account_id' => $accountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([[$sameRoleMaker, 'BT-SAME'], [$differentRoleMaker, 'BT-DIFF']] as [$maker, $number]) {
            DB::table('budget_transfers')->insert([
                'transfer_number' => $number,
                'from_line_item_id' => $lineItemId,
                'to_line_item_id' => $lineItemId,
                'month' => 'jan',
                'amount' => '1.00',
                'reason' => 'Board test transfer',
                'status' => 'pending',
                'requested_by' => $maker->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $board = app(ApprovalBoardService::class)->board($approver);
        $budgetCards = array_values(array_filter($board['my_action'], static fn (array $card): bool => $card['type'] === 'budget'));
        $transferCards = array_values(array_filter($board['my_action'], static fn (array $card): bool => $card['type'] === 'budget_transfer'));

        $this->assertSame(['Different role'], array_column($budgetCards, 'number'));
        $this->assertSame(['BT-DIFF'], array_column($transferCards, 'number'));
    }
}
