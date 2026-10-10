<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPeriodSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_period_summary_reconciles_deductions_and_shows_signed_adjustments_separately(): void
    {
        $finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'sss_ee' => '500.00',
            'philhealth_ee' => '200.00',
            'pagibig_ee' => '100.00',
            'withholding_tax' => '300.00',
            'loan_deductions' => '400.00',
            'other_deductions' => '25.00',
            'total_deductions' => '1500.00',
            'adjustment_amount' => '-75.00',
            'gross_pay' => '10000.00',
            'net_pay' => '8425.00',
        ]);

        $this->actingAs($finance)
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_deductions', '1500.00')
            ->assertJsonPath('data.summary.deduction_breakdown.sss_employee_share', '500.00')
            ->assertJsonPath('data.summary.deduction_breakdown.philhealth_employee_share', '200.00')
            ->assertJsonPath('data.summary.deduction_breakdown.pagibig_employee_share', '100.00')
            ->assertJsonPath('data.summary.deduction_breakdown.withholding_tax', '300.00')
            ->assertJsonPath('data.summary.deduction_breakdown.loan_deductions', '400.00')
            ->assertJsonPath('data.summary.uncategorized_deductions', '25.00')
            ->assertJsonPath('data.summary.net_adjustments', '-75.00');
    }
}
