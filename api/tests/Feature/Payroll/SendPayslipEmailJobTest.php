<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Jobs\SendPayslipEmailJob;
use App\Modules\Payroll\Mail\PayslipMail;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayslipPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendPayslipEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_sent_only_after_mail_send_succeeds(): void
    {
        Mail::fake();

        $pdf = \Mockery::mock(PayslipPdfService::class);
        $pdf->shouldReceive('generate')->once()->andReturn('PDFBYTES');
        $pdf->shouldReceive('filename')->once()->andReturn('payslip.pdf');

        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create(['email' => 'a@example.test']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);

        (new SendPayslipEmailJob($payroll->id))->handle($pdf);

        Mail::assertSent(PayslipMail::class, fn (PayslipMail $mail): bool => $mail->hasTo('a@example.test'));
        $sent = $payroll->fresh();
        $this->assertNotNull($sent->payslip_emailed_at);
        $this->assertSame(Payroll::EMAIL_SENT, $sent->payslip_email_status);
        $this->assertNull($sent->payslip_email_queued_at);
        $this->assertNull($sent->payslip_email_last_error);
    }

    public function test_terminal_job_failure_leaves_a_retryable_failed_state(): void
    {
        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create(['email' => 'a@example.test']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);

        (new SendPayslipEmailJob($payroll->id))->failed(new \RuntimeException('SMTP unavailable'));

        $failed = $payroll->fresh();
        $this->assertNull($failed->payslip_emailed_at);
        $this->assertSame(Payroll::EMAIL_FAILED, $failed->payslip_email_status);
        $this->assertNull($failed->payslip_email_queued_at);
        $this->assertSame('SMTP unavailable', $failed->payslip_email_last_error);
    }
}
