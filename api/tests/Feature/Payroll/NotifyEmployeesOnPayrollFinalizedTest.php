<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\NotifyEmployeesOnPayrollFinalized;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotifyEmployeesOnPayrollFinalizedTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_payroll_rows_do_not_receive_payslip_ready_notifications(): void
    {
        $period = PayrollPeriod::factory()->create();
        $failedEmployee = Employee::factory()->create();
        $validEmployee = Employee::factory()->create();
        $failedUser = User::factory()->create(['employee_id' => $failedEmployee->id, 'is_active' => true]);
        $validUser = User::factory()->create(['employee_id' => $validEmployee->id, 'is_active' => true]);

        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $failedEmployee->id,
            'error_message' => 'Unable to compute government deductions.',
            'net_pay' => '0.00',
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $validEmployee->id,
            'error_message' => null,
            'net_pay' => '1000.00',
        ]);

        app(NotifyEmployeesOnPayrollFinalized::class)->handle(new PayrollPeriodFinalized($period));

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $failedUser->id,
            'type' => 'chain.payslip_ready',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $validUser->id,
            'type' => 'chain.payslip_ready',
        ]);
    }
}
