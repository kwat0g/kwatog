<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Services\SettingsService;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAnomalyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_deduction_ratio_controls_anomaly_detection(): void
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-15',
            'payroll_date' => '2026-07-17',
            'is_first_half' => true,
        ]);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'gross_pay' => 1000,
            'total_deductions' => 600,
            'net_pay' => 400,
        ]);

        app(SettingsService::class)->set('payroll.anomaly.deduction_ratio', 0.70, 'payroll');
        app(PayrollAnomalyService::class)->detect($period);
        $this->assertFalse($this->hasFlag($payroll, PayrollAnomalyType::HighDeduction));

        app(SettingsService::class)->set('payroll.anomaly.deduction_ratio', 0.50, 'payroll');
        app(PayrollAnomalyService::class)->detect($period);
        $this->assertTrue($this->hasFlag($payroll, PayrollAnomalyType::HighDeduction));
    }

    private function hasFlag(Payroll $payroll, PayrollAnomalyType $type): bool
    {
        return PayrollAnomalyFlag::query()
            ->where('payroll_id', $payroll->id)
            ->where('flag_type', $type->value)
            ->exists();
    }
}
