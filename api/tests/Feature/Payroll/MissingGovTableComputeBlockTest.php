<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PY-02 — a missing or deactivated government contribution table used to
 * silently compute ₱0.00 deductions for every employee. Compute must now
 * hard-block (mirroring the de minimis block) and name every agency whose
 * table is not effective on the period's payroll_date.
 */
class MissingGovTableComputeBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // gov-table service caches results for 5 min — flush per test.
    }

    private function seedAgencyRow(string $agency, string $effectiveDate, bool $active = true): void
    {
        GovernmentContributionTable::create([
            'agency' => $agency,
            'bracket_min' => 0.00,
            'bracket_max' => 999999.99,
            'ee_amount' => $agency === 'bir' ? 0.00 : 0.02,
            'er_amount' => $agency === 'bir' ? 0.00 : 0.02,
            'effective_date' => $effectiveDate,
            'is_active' => $active,
        ]);
    }

    private function seedAllAgencies(string $effectiveDate = '2024-01-01'): void
    {
        foreach (['sss', 'philhealth', 'pagibig', 'bir'] as $agency) {
            $this->seedAgencyRow($agency, $effectiveDate);
        }
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create([
            'pay_type' => 'monthly',
            'basic_monthly_salary' => 20000.00,
            'date_hired' => '2020-01-01',
        ]);
    }

    private function makePeriod(bool $firstHalf = true): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15',
            'is_first_half' => $firstHalf,
            'is_thirteenth_month' => false,
        ]);
    }

    public function test_missing_single_agency_blocks_compute_and_names_it(): void
    {
        // Everything except BIR. Migration 0467 ships the BIR TRAIN brackets on
        // every fresh database, so remove them to simulate the missing agency.
        GovernmentContributionTable::query()->where('agency', 'bir')->delete();
        foreach (['sss', 'philhealth', 'pagibig'] as $agency) {
            $this->seedAgencyRow($agency, '2024-01-01');
        }
        Cache::flush();

        $period = $this->makePeriod();
        $employee = $this->makeEmployee();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/BIR.*2026-04-15/');

        try {
            app(PayrollCalculatorService::class)->computeForEmployee($period, $employee);
        } finally {
            $this->assertDatabaseCount('payrolls', 0);
            $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status, 'the period must remain uncomputed');
        }
    }

    public function test_all_four_agencies_present_allows_compute(): void
    {
        $this->seed(GovernmentTableSeeder::class);

        $period = $this->makePeriod();
        $employee = $this->makeEmployee();

        $payroll = app(PayrollCalculatorService::class)->computeForEmployee($period, $employee);

        $this->assertNotSame('0.00', (string) $payroll->sss_ee);
        $this->assertNotSame('0.00', (string) $payroll->philhealth_ee);
        $this->assertNotSame('0.00', (string) $payroll->pagibig_ee);
        $this->assertGreaterThan(0, (float) $payroll->net_pay);
    }

    public function test_deactivated_table_not_effective_on_payroll_date_blocks_compute(): void
    {
        // SSS has a table, but it is deactivated and only takes effect AFTER the
        // payroll date — nothing resolves for 2026-04-15 (dated lookup finds no
        // row on/before it, and the active-set fallback is empty).
        $this->seedAgencyRow('sss', '2027-01-01', active: false);
        foreach (['philhealth', 'pagibig', 'bir'] as $agency) {
            $this->seedAgencyRow($agency, '2024-01-01');
        }

        $period = $this->makePeriod();
        $employee = $this->makeEmployee();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/SSS.*2026-04-15/');

        app(PayrollCalculatorService::class)->computeForEmployee($period, $employee);
    }

    public function test_message_names_all_missing_agencies(): void
    {
        // No seeded gov tables. Migration 0467 ships the BIR TRAIN brackets on
        // every fresh database, so remove them too — the guard must then name
        // ALL FOUR agencies in one refusal.
        GovernmentContributionTable::query()->where('agency', 'bir')->delete();
        Cache::flush();

        $period = $this->makePeriod();
        $employee = $this->makeEmployee();

        try {
            app(PayrollCalculatorService::class)->computeForEmployee($period, $employee);
            $this->fail('Compute must be refused when no gov tables are seeded.');
        } catch (BusinessRuleException $e) {
            foreach (['SSS', 'PhilHealth', 'Pag-IBIG', 'BIR'] as $label) {
                $this->assertStringContainsString($label, $e->getMessage());
            }
        }
    }

    public function test_second_half_period_computes_without_any_gov_tables(): void
    {
        // Second-half periods take no gov deductions, so the guard must not
        // engage — exactly mirroring the computation branch.
        $period = $this->makePeriod(firstHalf: false);
        $employee = $this->makeEmployee();

        $payroll = app(PayrollCalculatorService::class)->computeForEmployee($period, $employee);

        $this->assertSame('0.00', (string) $payroll->sss_ee);
        $this->assertSame('0.00', (string) $payroll->withholding_tax);
        $this->assertGreaterThan(0, (float) $payroll->net_pay);
    }
}
