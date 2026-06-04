<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirAlphalistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $roleId = Role::query()->orderBy('id')->value('id');

        return User::create([
            'name'     => 'Tester '.uniqid(),
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function makeFinalizedPeriod(int $year = 2026): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'status'              => 'finalized',
            'period_start'        => "{$year}-01-01",
            'period_end'          => "{$year}-01-15",
            'payroll_date'        => "{$year}-01-15",
            'is_first_half'       => true,
            'is_thirteenth_month' => false,
        ]);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_bir_alphalist_returns_csv_with_employee_data(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'tin'        => '123-456-789-000',
        ]);
        $period = $this->makeFinalizedPeriod(2026);

        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $period->id,
            'gross_pay'         => 15000.00,
            'total_deductions'  => 1500.00,
            'net_pay'           => 13500.00,
            'withholding_tax'   => 500.00,
            'error_message'     => null,
        ]);

        $user     = $this->makeUser();
        $response = $this->actingAs($user)
            ->get('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(200);

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type') ?? '');

        $csv = $response->getContent();
        $this->assertStringContainsString('DELA CRUZ', $csv);
        $this->assertStringContainsString('JUAN', $csv);
        $this->assertStringContainsString('123-456-789-000', $csv);
        $this->assertStringContainsString('15000.00', $csv);
        $this->assertStringContainsString('1500.00', $csv);
        $this->assertStringContainsString('500.00', $csv);

        // Header row must be present
        $this->assertStringContainsString('TIN,Last Name,First Name', $csv);

        // Content-Disposition header
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('BIR-2316-Alphalist-2026.csv', $disposition);
    }

    public function test_bir_alphalist_aggregates_multiple_periods_for_same_employee(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
            'tin'        => '999-888-777-000',
        ]);

        // Two periods in the same year
        $period1 = $this->makeFinalizedPeriod(2026);
        $period2 = PayrollPeriod::factory()->create([
            'status'              => 'finalized',
            'period_start'        => '2026-01-16',
            'period_end'          => '2026-01-31',
            'payroll_date'        => '2026-01-31',
            'is_first_half'       => false,
            'is_thirteenth_month' => false,
        ]);

        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $period1->id,
            'gross_pay'         => 10000.00,
            'total_deductions'  => 500.00,
            'net_pay'           => 9500.00,
            'withholding_tax'   => 200.00,
            'error_message'     => null,
        ]);
        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $period2->id,
            'gross_pay'         => 10000.00,
            'total_deductions'  => 500.00,
            'net_pay'           => 9500.00,
            'withholding_tax'   => 200.00,
            'error_message'     => null,
        ]);

        $user = $this->makeUser();
        $csv  = $this->actingAs($user)
            ->get('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(200)
            ->getContent();

        // Aggregated totals: 20000 gross, 1000 deductions, 400 tax
        $this->assertStringContainsString('20000.00', $csv);
        $this->assertStringContainsString('1000.00', $csv);
        $this->assertStringContainsString('400.00', $csv);

        // Only ONE data row for this employee
        $lines = array_filter(explode("\r\n", trim($csv)));
        $this->assertCount(2, $lines); // header + 1 data row
    }

    public function test_bir_alphalist_excludes_thirteenth_month_periods(): void
    {
        $employee = Employee::factory()->create(['last_name' => 'Reyes']);
        $regular  = $this->makeFinalizedPeriod(2026);
        $thirteenth = PayrollPeriod::factory()->create([
            'status'              => 'finalized',
            'period_start'        => '2026-12-01',
            'period_end'          => '2026-12-31',
            'payroll_date'        => '2026-12-25',
            'is_first_half'       => false,
            'is_thirteenth_month' => true,
        ]);

        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $regular->id,
            'gross_pay'         => 15000.00,
            'total_deductions'  => 0,
            'net_pay'           => 15000.00,
            'withholding_tax'   => 0,
            'error_message'     => null,
        ]);
        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $thirteenth->id,
            'gross_pay'         => 20000.00,
            'total_deductions'  => 0,
            'net_pay'           => 20000.00,
            'withholding_tax'   => 0,
            'error_message'     => null,
        ]);

        $user = $this->makeUser();
        $csv  = $this->actingAs($user)
            ->get('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(200)
            ->getContent();

        // Should only see the regular period gross (15000), not 13th-month (20000)
        $this->assertStringContainsString('15000.00', $csv);
        $this->assertStringNotContainsString('20000.00', $csv);
    }

    public function test_bir_alphalist_excludes_error_payroll_rows(): void
    {
        $employee = Employee::factory()->create(['last_name' => 'Cruz']);
        $period   = $this->makeFinalizedPeriod(2026);

        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $period->id,
            'gross_pay'         => 15000.00,
            'total_deductions'  => 0,
            'net_pay'           => 15000.00,
            'withholding_tax'   => 0,
            'error_message'     => 'Missing shift assignment',  // error row
        ]);

        $user = $this->makeUser();
        $csv  = $this->actingAs($user)
            ->get('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(200)
            ->getContent();

        $lines = array_filter(explode("\r\n", trim($csv)));
        $this->assertCount(1, $lines); // header only — error row excluded
    }

    public function test_bir_alphalist_empty_when_no_finalized_periods(): void
    {
        $user = $this->makeUser();
        $csv  = $this->actingAs($user)
            ->get('/api/v1/payroll/bir-alphalist?year=2099')
            ->assertStatus(200)
            ->getContent();

        $lines = array_filter(explode("\r\n", trim($csv)));
        $this->assertCount(1, $lines); // header only
    }

    public function test_bir_alphalist_requires_authentication(): void
    {
        $this->get('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(401);
    }

    public function test_bir_alphalist_includes_disbursed_periods(): void
    {
        $employee = Employee::factory()->create(['last_name' => 'Lim']);

        // A disbursed period should also appear (disbursed = post-finalized)
        $period = PayrollPeriod::factory()->create([
            'status'              => 'disbursed',
            'period_start'        => '2026-02-01',
            'period_end'          => '2026-02-15',
            'payroll_date'        => '2026-02-15',
            'is_first_half'       => true,
            'is_thirteenth_month' => false,
        ]);
        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $period->id,
            'gross_pay'         => 12000.00,
            'total_deductions'  => 600.00,
            'net_pay'           => 11400.00,
            'withholding_tax'   => 100.00,
            'error_message'     => null,
        ]);

        $user = $this->makeUser();
        $csv  = $this->actingAs($user)
            ->get('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('LIM', $csv);
        $this->assertStringContainsString('12000.00', $csv);
    }
}
