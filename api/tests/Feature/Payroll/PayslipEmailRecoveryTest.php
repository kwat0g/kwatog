<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Jobs\SendPayslipEmailJob;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayslipEmailRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_payslip_delivery_is_requeued_without_replaying_finalization_listeners(): void
    {
        Queue::fake();

        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized])->saveQuietly();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_FAILED,
            'payslip_email_attempts' => 2,
            'payslip_email_last_error' => 'SMTP unavailable',
        ]);

        $this->artisan('payroll:reconcile-payslip-emails')
            ->assertSuccessful()
            ->expectsOutputToContain('inspected 1 finalized period(s)');

        Queue::assertPushed(SendPayslipEmailJob::class, function (SendPayslipEmailJob $job) use ($payroll): bool {
            return $job->payrollId === $payroll->id;
        });
        $this->assertSame(Payroll::EMAIL_QUEUED, $payroll->fresh()->payslip_email_status);
    }

    public function test_live_queued_claim_is_not_replayed_and_draft_periods_are_ignored(): void
    {
        Queue::fake();

        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized])->saveQuietly();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_attempts' => 1,
            'payslip_email_queued_at' => now(),
        ]);

        $draft = PayrollPeriod::factory()->create();
        $draft->forceFill(['status' => PayrollPeriodStatus::Draft])->saveQuietly();
        Payroll::factory()->create([
            'payroll_period_id' => $draft->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_FAILED,
            'payslip_email_attempts' => 1,
        ]);

        $this->artisan('payroll:reconcile-payslip-emails')
            ->assertSuccessful()
            ->expectsOutputToContain('inspected 0 finalized period(s)');

        Queue::assertNotPushed(SendPayslipEmailJob::class);
    }
}
