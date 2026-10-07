<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenAttendanceAnomalyTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_rows_raise_an_informational_flag_that_never_counts_as_unresolved(): void
    {
        $employee = Employee::factory()->create();
        $period = PayrollPeriod::factory()->create(['period_start' => '2026-01-01', 'period_end' => '2026-01-15']);
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-01-05', 'time_in' => '2026-01-05 06:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-01-06', 'time_in' => '2026-01-06 06:00:00', 'time_out' => '2026-01-06 14:00:00', 'status' => AttendanceStatus::Present->value]);

        $svc = app(PayrollAnomalyService::class);
        $svc->detect($period);

        $flag = PayrollAnomalyFlag::query()->where('payroll_id', $payroll->id)->where('flag_type', PayrollAnomalyType::OpenAttendance->value)->firstOrFail();
        $this->assertSame(['2026-01-05'], $flag->details['dates']);

        // first_payroll is also raised for this brand-new employee and DOES block; the open-attendance flag does not.
        $total = PayrollAnomalyFlag::query()->where('payroll_period_id', $period->id)->count();
        $this->assertGreaterThan($svc->unresolvedCount($period->id), $total);
        $this->assertSame(
            PayrollAnomalyFlag::query()->where('payroll_period_id', $period->id)->where('flag_type', '!=', PayrollAnomalyType::OpenAttendance->value)->where('is_resolved', false)->count(),
            $svc->unresolvedCount($period->id),
        );
    }

    public function test_no_open_rows_means_no_flag(): void
    {
        $employee = Employee::factory()->create();
        $period = PayrollPeriod::factory()->create(['period_start' => '2026-01-01', 'period_end' => '2026-01-15']);
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);

        app(PayrollAnomalyService::class)->detect($period);

        $this->assertFalse(PayrollAnomalyFlag::query()->where('payroll_id', $payroll->id)->where('flag_type', 'open_attendance')->exists());
    }
}
