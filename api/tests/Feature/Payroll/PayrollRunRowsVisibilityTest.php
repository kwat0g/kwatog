<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 — a run is REVIEWED while it is still `computed`, so the staff working
 * view of a period (the SPA period-detail Employees/Failures tabs) must see
 * its rows before finalize.
 *
 * The publication boundary still governs the employee-facing collection
 * (/payrolls) and the payslip PDF: a payroll row is not a public document
 * until its period is finalized. These tests pin BOTH sides, because the
 * defect this fixes was exactly a staff surface bound to the public predicate.
 */
class PayrollRunRowsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    public function test_period_rows_are_listable_before_finalize_by_payroll_staff(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
            'gross_pay' => 915207,
            'net_pay' => 914707,
        ]);

        $response = $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}/payrolls");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('914707.00', $response->json('data.0.net_pay'));
        $this->assertSame('computed', $response->json('data.0.period_status'));
    }

    public function test_period_rows_are_listable_for_finance_who_holds_periods_view_without_view_all(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        $this->actingAs($this->userWithRole('finance_officer'))
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}/payrolls")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_run_rows_endpoint_requires_periods_view(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        // Rank-and-file employee: has payroll.view (self-service payslips) but
        // must not read the run.
        $this->actingAs($this->userWithRole('employee'))
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}/payrolls")
            ->assertStatus(403);
    }

    public function test_run_row_detail_is_readable_before_finalize_by_payroll_staff(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        $this->actingAs($this->userWithRole('finance_officer'))
            ->getJson("/api/v1/payrolls/{$payroll->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payroll->hash_id);
    }

    public function test_publication_collection_still_excludes_unfinalized_rows(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        // hr_officer holds payroll.payslip.view_all, so the empty result here is
        // the publication predicate at work, not row scoping.
        $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson("/api/v1/payrolls?period_id={$period->hash_id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_payslip_pdf_still_refuses_before_finalize(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson("/api/v1/payrolls/{$payroll->hash_id}/payslip")
            ->assertStatus(422);
    }

    public function test_self_scoped_user_cannot_read_another_employees_run_row(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        $this->actingAs($this->userWithRole('employee'))
            ->getJson("/api/v1/payrolls/{$payroll->hash_id}")
            ->assertStatus(403);
    }

    public function test_run_rows_respect_search_and_failed_only_filters(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        $wanted = Employee::factory()->create(['employee_no' => 'H2R-0001']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $wanted->id,
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create(['employee_no' => 'H2R-0002'])->id,
            'error_message' => 'Missing shift assignment.',
        ]);

        $user = $this->userWithRole('hr_officer');

        $this->actingAs($user)
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}/payrolls?search=H2R-0001")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}/payrolls?failed_only=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.employee_no', 'H2R-0002');
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'employee_id' => Employee::factory()->create()->id,
        ]);
    }
}
