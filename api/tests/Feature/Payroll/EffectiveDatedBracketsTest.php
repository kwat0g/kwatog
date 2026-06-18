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
}
