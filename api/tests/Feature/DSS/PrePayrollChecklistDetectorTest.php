<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Detectors\PrePayrollChecklistDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D21 — pre-payroll checklist reuses PayrollAnomalyService's flag rows (the
 * same gate PayrollPeriodService::finalize blocks on). One emission per open
 * (computed/approved) period with unresolved flags; resolved flags and
 * locked periods stay out.
 */
class PrePayrollChecklistDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('alerts.ar.warning_overdue_days', 30);
        app(SettingsService::class)->set('alerts.ar.critical_overdue_days', 60);
    }

    private function detector(): PrePayrollChecklistDetector
    {
        return app(PrePayrollChecklistDetector::class);
    }

    private function period(PayrollPeriodStatus $status): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => now()->subDays(20)->toDateString(),
            'period_end' => now()->subDays(6)->toDateString(),
            'payroll_date' => now()->subDays(4)->toDateString(),
        ]);

        $period->forceFill(['status' => $status])->save();

        return $period;
    }

    private array $payrollIds = [];

    private function flag(
        PayrollPeriod $period,
        Employee $employee,
        string $type,
        bool $resolved = false,
    ): void {
        $key = $period->id.':'.$employee->id;
        $payrollId = $this->payrollIds[$key] ??= Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ])->id;

        PayrollAnomalyFlag::create([
            'payroll_id' => $payrollId,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'flag_type' => $type,
            'details' => [],
            'is_resolved' => $resolved,
            'resolved_by' => $resolved ? User::factory()->create()->id : null,
        ]);
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect());
    }

    public function test_emits_one_row_per_open_period_and_skips_resolved_and_locked(): void
    {
        $critical = $this->period(PayrollPeriodStatus::Computed);
        $alice = Employee::factory()->create(['first_name' => 'Alice']);
        $bob = Employee::factory()->create(['first_name' => 'Bob']);
        $this->flag($critical, $alice, 'zero_pay');
        $this->flag($critical, $bob, 'large_change');

        $warning = $this->period(PayrollPeriodStatus::Approved);
        $carol = Employee::factory()->create(['first_name' => 'Carol']);
        $this->flag($warning, $carol, 'excessive_ot');
        $this->flag($warning, $carol, 'open_attendance');

        $resolvedDone = $this->period(PayrollPeriodStatus::Computed); // all flags resolved → out
        $dan = Employee::factory()->create();
        $this->flag($resolvedDone, $dan, 'large_change', resolved: true);

        $locked = $this->period(PayrollPeriodStatus::Finalized); // locked → out
        $erin = Employee::factory()->create();
        $this->flag($locked, $erin, 'zero_pay');

        $byKey = [];
        foreach ($this->detector()->detect() as $emission) {
            $byKey[$emission->dedupeKey] = $emission;
        }

        $this->assertCount(2, $byKey);
        $this->assertArrayHasKey("prepayroll:{$critical->id}", $byKey);
        $this->assertArrayHasKey("prepayroll:{$warning->id}", $byKey);

        $first = $byKey["prepayroll:{$critical->id}"];
        $this->assertSame('prepayroll_checklist', $first->type);
        $this->assertSame('critical', $first->severity);
        $this->assertSame(2, $first->rationale['unresolved_total']);
        $this->assertSame(2, $first->rationale['blocking_count']);
        $this->assertSame(['zero_pay' => 1, 'large_change' => 1], $first->rationale['by_type']);
        $this->assertSame(['hr_officer', 'finance_officer'], $first->rationale['audience_roles']);
        $this->assertSame('/payroll/periods', $first->action['route']);
        $this->assertSame(1.0, $first->confidence);
        $this->assertStringContainsString('Alice', $first->rationale['evidence'][0]);

        $second = $byKey["prepayroll:{$warning->id}"];
        $this->assertSame('warning', $second->severity);
        $this->assertSame(2, $second->rationale['unresolved_total']);
        $this->assertSame(1, $second->rationale['blocking_count']);
        $this->assertSame(['excessive_ot' => 1, 'open_attendance' => 1], $second->rationale['by_type']);
        $this->assertStringContainsString('Carol', $second->rationale['evidence'][0]);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.prepayroll_checklist', $detector->id());
        $this->assertSame('payroll', $detector->domain());
        $this->assertSame(['dss.view'], $detector->ownerPermissions());
        $this->assertSame('v1', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.prepayroll_checklist'));
    }
}
