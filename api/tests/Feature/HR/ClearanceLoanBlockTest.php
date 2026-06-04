<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\Loans\Models\EmployeeLoan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 3 — Clearance blocks finalization when employee has outstanding loans.
 *
 * SeparationService::finalize() must reject with 422 if the employee has any
 * employee_loans row where status IN ('active','pending') AND balance > 0.
 * Finance must settle or deduct from final pay before the clearance can be
 * finalized, preventing the hard-block from being bypassed.
 */
class ClearanceLoanBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function makeClearance(Employee $employee, array $overrides = []): Clearance
    {
        return Clearance::factory()->create(array_merge([
            'employee_id'        => $employee->id,
            'status'             => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Blocked — active loan with balance
    // ─────────────────────────────────────────────────────────────

    public function test_finalize_blocked_when_active_loan_with_balance_exists(): void
    {
        $employee = Employee::factory()->create();

        EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'balance'     => '500.00',
            'status'      => 'active',
        ]);

        $clearance = $this->makeClearance($employee);

        $this->actingAs($this->makeAdmin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outstanding_loans']);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Blocked — pending loan with balance
    // ─────────────────────────────────────────────────────────────

    public function test_finalize_blocked_when_pending_loan_with_balance_exists(): void
    {
        $employee = Employee::factory()->create();

        EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'balance'     => '1200.00',
            'status'      => 'pending',
        ]);

        $clearance = $this->makeClearance($employee);

        $this->actingAs($this->makeAdmin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outstanding_loans']);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Not blocked — active loan with zero balance (fully paid)
    // ─────────────────────────────────────────────────────────────

    public function test_finalize_passes_when_active_loan_has_zero_balance(): void
    {
        $employee = Employee::factory()->create();

        EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'balance'     => '0.00',
            'status'      => 'active',
        ]);

        $clearance = $this->makeClearance($employee);

        // Mock FinalPayService so the test does not require full accounting setup.
        // journal_entry_id is nullable; a non-persisted JE with id=null is fine here.
        $this->mock(FinalPayService::class)
            ->shouldReceive('postJournalEntry')
            ->once()
            ->andReturn(new \App\Modules\Accounting\Models\JournalEntry());

        $this->actingAs($this->makeAdmin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Not blocked — paid/settled loan (non-blocking status)
    // ─────────────────────────────────────────────────────────────

    public function test_finalize_passes_when_only_paid_loans_exist(): void
    {
        $employee = Employee::factory()->create();

        EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'balance'     => '0.00',
            'status'      => 'paid',
        ]);

        $clearance = $this->makeClearance($employee);

        // Mock FinalPayService so the test does not require full accounting setup.
        // journal_entry_id is nullable; a non-persisted JE with id=null is fine here.
        $this->mock(FinalPayService::class)
            ->shouldReceive('postJournalEntry')
            ->once()
            ->andReturn(new \App\Modules\Accounting\Models\JournalEntry());

        $this->actingAs($this->makeAdmin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Not blocked — no loans at all
    // ─────────────────────────────────────────────────────────────

    public function test_finalize_passes_when_employee_has_no_loans(): void
    {
        $employee  = Employee::factory()->create();
        $clearance = $this->makeClearance($employee);

        // Mock FinalPayService so the test does not require full accounting setup.
        // journal_entry_id is nullable; a non-persisted JE with id=null is fine here.
        $this->mock(FinalPayService::class)
            ->shouldReceive('postJournalEntry')
            ->once()
            ->andReturn(new \App\Modules\Accounting\Models\JournalEntry());

        $this->actingAs($this->makeAdmin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);
    }
}
