<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Models\EmployeeLoan;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfServiceLoanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkflowSeeder::class);
    }

    public function test_self_service_loan_submission_uses_the_canonical_workflow_and_outbox(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => 30000]);
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/hr/self-service/loans', [
                'loan_type' => 'cash_advance',
                'amount' => 5000,
                'periods' => 5,
                'reason' => 'Emergency household expense.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Loan request submitted for approval.')
            ->assertJsonPath('data.status', LoanStatus::Pending->value);

        $loan = EmployeeLoan::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame($loan->hash_id, $response->json('data.id'));
        $this->assertSame(LoanStatus::Pending, $loan->status);
        $this->assertNotNull($loan->loan_no);
        $this->assertSame('5000.00', (string) $loan->balance);
        $this->assertCount(3, $loan->approvalRecords);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => LoanSubmitted::class,
        ]);
    }

    public function test_self_service_loan_list_reads_authoritative_employee_loan_columns(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => 30000]);
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/hr/self-service/loans', [
                'loan_type' => 'cash_advance',
                'amount' => 5000,
                'periods' => 5,
                'reason' => 'List-shape regression.',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/loans')
            ->assertOk()
            ->assertJsonCount(1, 'data.active')
            ->assertJsonPath('data.active.0.loan_type', 'cash_advance')
            ->assertJsonPath('data.active.0.periods', 5)
            ->assertJsonPath('data.active.0.periods_remaining', 5)
            ->assertJsonPath('data.active.0.outstanding_balance', '5000.00');
    }

    public function test_duplicate_self_service_loan_type_is_rejected_without_a_second_row(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => 30000]);
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $payload = [
            'loan_type' => 'cash_advance',
            'amount' => 5000,
            'periods' => 5,
            'reason' => 'Duplicate request regression.',
        ];

        $this->actingAs($user)
            ->postJson('/api/v1/hr/self-service/loans', $payload)
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/v1/hr/self-service/loans', $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'An active or pending cash_advance already exists for this employee.']);

        $this->assertSame(1, EmployeeLoan::query()->where('employee_id', $employee->id)->count());
    }
}
