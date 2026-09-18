<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\WorkflowSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoanPolicyConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkflowSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Event::fake();
    }

    public function test_persisted_interest_rate_and_workflow_drive_new_loan_terms(): void
    {
        app(SettingsService::class)->set('loans.cash_advance.annual_interest_rate', '0.105', 'loans');
        $employee = Employee::factory()->create(['basic_monthly_salary' => 30000]);

        $loan = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal' => '12000.00',
            'pay_periods' => 12,
            'purpose' => 'Configured policy regression',
        ]);

        $this->assertSame('0.105000', (string) $loan->interest_rate);
        $this->assertGreaterThan(1000.0, (float) $loan->monthly_amortization);
        $this->assertGreaterThan(12000.0, (float) $loan->balance);
        $this->assertSame(3, $loan->approval_chain_size);
        $this->assertCount(3, $loan->approvalRecords);
    }

    public function test_types_are_sourced_from_configured_workflows_and_settings(): void
    {
        $types = collect(app(LoanService::class)->types())->keyBy('value');

        $this->assertSame(['cash_advance', 'company_loan'], $types->keys()->sort()->values()->all());
        $this->assertSame(3, $types['cash_advance']['approval_steps']);
        $this->assertSame('0', $types['cash_advance']['interest_rate']);
        $this->assertSame(4, $types['company_loan']['approval_steps']);
    }

    public function test_government_loan_types_are_rejected_before_schedule_generation(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => 30000]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Government loan types are not currently supported.');

        app(LoanService::class)->request($employee->id, LoanType::SssLoan, [
            'principal' => '12000.00',
            'pay_periods' => 12,
        ]);
    }

    public function test_amortization_preview_rejects_government_loan_types_at_the_api_boundary(): void
    {
        $user = User::factory()->withRole('employee')->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/loans/preview-amortization', [
                'loan_type' => LoanType::PagibigLoan->value,
                'principal' => '12000.00',
                'pay_periods' => 12,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loan_type');
    }

    public function test_loan_creation_rejects_government_loan_types_at_request_validation(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => 30000]);
        $admin = User::factory()->withRole('system_admin')->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/loans', [
                'employee_id' => $employee->hash_id,
                'loan_type' => LoanType::SssLoan->value,
                'principal' => '12000.00',
                'pay_periods' => 12,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loan_type');
    }
}
