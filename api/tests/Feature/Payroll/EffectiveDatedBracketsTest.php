<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use App\Modules\Payroll\Services\GovernmentContributionTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EffectiveDatedBracketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function sssRow(string $effective, float $ee, float $er): void
    {
        GovernmentContributionTable::create([
            'agency'         => 'sss',
            'bracket_min'    => 0.00,
            'bracket_max'    => 999999.99,
            'ee_amount'      => $ee,
            'er_amount'      => $er,
            'effective_date' => $effective,
            'is_active'      => true,
        ]);
    }

    public function test_picks_latest_schedule_on_or_before_the_date(): void
    {
        $this->sssRow('2024-01-01', 100.00, 200.00);
        $this->sssRow('2025-01-01', 150.00, 300.00);

        $svc = app(GovernmentContributionTableService::class);

        $in2024 = $svc->bracketsEffectiveOn(ContributionAgency::Sss, '2024-06-15');
        $this->assertSame('100.0000', (string) $in2024->first()->ee_amount);

        $in2025 = $svc->bracketsEffectiveOn(ContributionAgency::Sss, '2025-06-15');
        $this->assertSame('150.0000', (string) $in2025->first()->ee_amount);
    }

    public function test_falls_back_to_active_set_when_no_dated_rows_match(): void
    {
        $this->sssRow('2025-01-01', 150.00, 300.00);

        $svc = app(GovernmentContributionTableService::class);
        // A date before any effective_date → fall back to active set, not empty.
        $rows = $svc->bracketsEffectiveOn(ContributionAgency::Sss, '2020-01-01');
        $this->assertCount(1, $rows);
    }

    public function test_sss_service_uses_schedule_in_force_on_pay_date(): void
    {
        $this->sssRow('2024-01-01', 100.00, 200.00);
        $this->sssRow('2025-01-01', 150.00, 300.00);

        $svc = app(\App\Modules\Payroll\Services\Government\SssComputationService::class);

        $r2024 = $svc->compute('20000', \Illuminate\Support\Carbon::parse('2024-06-15'));
        $this->assertSame('100.00', $r2024['ee']);

        $r2025 = $svc->compute('20000', \Illuminate\Support\Carbon::parse('2025-06-15'));
        $this->assertSame('150.00', $r2025['ee']);
    }

    public function test_payroll_engine_deducts_using_schedule_on_pay_date(): void
    {
        // SSS flat amounts: 2024 EE 100 / ER 200; 2025 EE 150 / ER 300.
        $this->sssRow('2024-01-01', 100.00, 200.00);
        $this->sssRow('2025-01-01', 150.00, 300.00);
        // No PhilHealth/Pag-IBIG/BIR rows seeded → those deductions resolve to 0; only SSS moves sss_ee.

        $employee = \App\Modules\HR\Models\Employee::factory()->create([
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => 20000.00,
            'date_hired'           => '2020-01-01',
        ]);
        // Pay date in 2024 → engine must use the 2024 schedule (EE 100), NOT today's date
        // (now()=2026 → 2025 schedule → 150). This proves payroll_date is threaded through.
        $period = \App\Modules\Payroll\Models\PayrollPeriod::factory()->create([
            'status'              => 'draft',
            'period_start'        => '2024-06-01',
            'period_end'          => '2024-06-15',
            'payroll_date'        => '2024-06-15',
            'is_first_half'       => true,
            'is_thirteenth_month' => false,
        ]);

        $payroll = app(\App\Modules\Payroll\Services\PayrollCalculatorService::class)
            ->computeForEmployee($period, $employee);

        $this->assertSame('100.00', (string) $payroll->sss_ee);
    }

    public function test_2025_seeder_makes_sss_effective_2025(): void
    {
        $this->seed(\Database\Seeders\GovernmentTableSeeder::class);      // 2024
        $this->seed(\Database\Seeders\GovernmentTable2025Seeder::class);  // 2025
        Cache::flush();

        $svc = app(\App\Modules\Payroll\Services\Government\SssComputationService::class);

        // MSC 20,000 in 2025 → EE = 20000 * 5% = 1000.00
        $r = $svc->compute('20000', \Illuminate\Support\Carbon::parse('2025-07-15'));
        $this->assertSame('1000.00', $r['ee']);
        $this->assertSame('2000.00', $r['er']); // 20000 * 10%
    }
}
