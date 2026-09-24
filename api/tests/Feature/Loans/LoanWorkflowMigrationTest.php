<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Common\Models\WorkflowDefinition;
use App\Common\Services\SettingsService;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanWorkflowMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, WorkflowSeeder::class]);
        $settings = app(SettingsService::class);
        $settings->set('loans.cash_advance.annual_interest_rate', '0', 'loans');
        $settings->set('loans.cash_advance.max_salary_multiplier', '1', 'loans');
        $settings->set('loans.max_pay_periods', '60', 'loans');
    }

    public function test_deployed_pending_loan_step_is_migrated_to_the_vice_president(): void
    {
        $workflow = WorkflowDefinition::query()->where('workflow_type', 'cash_advance')->firstOrFail();
        $oldSteps = [
            ['order' => 1, 'role' => 'department_head', 'label' => 'Department Head'],
            ['order' => 2, 'role' => 'finance_officer', 'label' => 'Finance / Accounting'],
            ['order' => 3, 'role' => 'system_admin', 'label' => 'VP / Approver'],
        ];
        $workflow->update(['steps' => $oldSteps]);
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00']);
        $loan = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal' => '5000.00',
            'pay_periods' => 5,
        ]);

        $this->assertSame('system_admin', $loan->approvalRecords->last()->role_slug);
        $migration = require database_path('migrations/0534_migrate_pending_loan_approvals_to_vp.php');
        $migration->up();

        $definition = WorkflowDefinition::query()->where('workflow_type', 'cash_advance')->firstOrFail();
        $pendingStep = $loan->approvalRecords()->where('action', 'pending')->where('step_order', 3)->firstOrFail();
        $snapshot = $pendingStep->workflow_snapshot;

        $this->assertSame('vice_president', $definition->steps[2]['role']);
        $this->assertSame('vice_president', $pendingStep->role_slug);
        $this->assertSame('vice_president', $snapshot['steps'][2]['role']);
        $this->assertSame(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), $pendingStep->workflow_version);
    }
}
