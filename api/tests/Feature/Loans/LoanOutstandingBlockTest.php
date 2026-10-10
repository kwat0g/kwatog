<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoanOutstandingBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, WorkflowSeeder::class]);
    }

    public static function outstandingLoans(): array
    {
        $cases = [];
        foreach (['pending', 'active', 'write_off_pending'] as $status) {
            foreach (['company_loan', 'cash_advance'] as $existingType) {
                foreach (['employee', 'HR'] as $side) {
                    $cases["{$side} with {$status} {$existingType}"] = [$status, $existingType, $side];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('outstandingLoans')]
    public function test_either_type_blocks_both_creation_routes(string $status, string $existingType, string $side): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => '30000.00']);
        $borrower = User::factory()->withRole('employee')->create(['employee_id' => $employee->id]);
        $hr = User::factory()->withRole('hr_officer')->create();
        $loan = EmployeeLoan::factory()->create(['employee_id' => $employee->id, 'loan_type' => $existingType]);
        $loan->forceFill(['status' => $status])->save();
        $before = $loan->fresh()->toArray();

        foreach (array_reverse(LoanType::supportedValues()) as $requestedType) {
            $this->actingAs($hr)->getJson("/api/v1/loans/limits/{$employee->hash_id}?loan_type={$requestedType}")
                ->assertOk()->assertJsonPath('data.has_active', true);
            $response = $side === 'employee'
                ? $this->actingAs($borrower)->postJson('/api/v1/hr/self-service/loans', [
                    'loan_type' => $requestedType, 'amount' => '1000.00', 'periods' => 2,
                ])
                : $this->actingAs($hr)->postJson('/api/v1/loans', [
                    'employee_id' => $employee->hash_id, 'loan_type' => $requestedType,
                    'principal' => '1000.00', 'pay_periods' => 2,
                ]);
            $response->assertUnprocessable()->assertJsonPath('message', 'This employee already has a pending or active loan or cash advance.');
        }

        $this->actingAs($borrower)->getJson('/api/v1/hr/self-service/loans')
            ->assertOk()->assertJsonCount(1, 'data.active');
        $this->assertSame(1, EmployeeLoan::where('employee_id', $employee->id)->count());
        $this->assertSame($before, $loan->fresh()->toArray());
        $this->assertDatabaseCount('approval_records', 0);
        $this->assertDatabaseCount('event_outbox', 0);
    }

    public static function closedStatuses(): array
    {
        return [['paid'], ['cancelled'], ['rejected'], ['written_off']];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_loans_and_other_employees_loans_do_not_block_creation(string $status): void
    {
        EmployeeLoan::factory()->create();
        $hr = User::factory()->withRole('hr_officer')->create();
        foreach (['employee', 'HR'] as $side) {
            $employee = Employee::factory()->create(['basic_monthly_salary' => '30000.00']);
            $borrower = User::factory()->withRole('employee')->create(['employee_id' => $employee->id]);
            $loan = EmployeeLoan::factory()->create(['employee_id' => $employee->id, 'loan_type' => 'company_loan']);
            $loan->forceFill(['status' => $status])->save();
            $this->actingAs($hr)->getJson("/api/v1/loans/limits/{$employee->hash_id}?loan_type=cash_advance")
                ->assertOk()->assertJsonPath('data.has_active', false);
            $response = $side === 'employee'
                ? $this->actingAs($borrower)->postJson('/api/v1/hr/self-service/loans', [
                    'loan_type' => 'cash_advance', 'amount' => '1000.00', 'periods' => 2,
                ])
                : $this->actingAs($hr)->postJson('/api/v1/loans', [
                    'employee_id' => $employee->hash_id, 'loan_type' => 'cash_advance',
                    'principal' => '1000.00', 'pay_periods' => 2,
                ]);
            $response->assertCreated();
            $this->assertSame(2, EmployeeLoan::where('employee_id', $employee->id)->count());
            $this->assertSame(LoanStatus::from($status), $loan->fresh()->status);
        }
    }
}
