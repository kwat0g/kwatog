<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\EmailPayslipPdfOnPayrollFinalized;
use App\Modules\Payroll\Mail\PayslipMail;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayslipPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailPayslipOnFinalizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the PDF service so the test does not depend on the Blade
        // payslip template / DomPDF / vault wiring. The listener only cares
        // about the binary bytes + filename.
        $stub = \Mockery::mock(PayslipPdfService::class);
        $stub->shouldReceive('generate')->andReturn('PDFBYTES');
        $stub->shouldReceive('filename')->andReturn('payslip.pdf');
        $this->instance(PayslipPdfService::class, $stub);
    }

    public function test_listener_queues_email_for_each_payroll_with_employee_email(): void
    {
        Mail::fake();

        $period = PayrollPeriod::factory()->create();
        $employeeA = Employee::factory()->create(['email' => 'a@example.test']);
        $employeeB = Employee::factory()->create(['email' => null]);
        $payrollA = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employeeA->id,
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employeeB->id,
        ]);

        app(EmailPayslipPdfOnPayrollFinalized::class)->handle(new PayrollPeriodFinalized($period));

        Mail::assertQueued(PayslipMail::class, 1);
        Mail::assertQueued(PayslipMail::class, fn (PayslipMail $m) => $m->hasTo('a@example.test'));
        $this->assertNotNull($payrollA->fresh()->payslip_emailed_at);
    }

    public function test_listener_is_idempotent_via_payslip_emailed_at(): void
    {
        Mail::fake();

        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create(['email' => 'x@example.test']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_emailed_at' => now(),
        ]);

        app(EmailPayslipPdfOnPayrollFinalized::class)->handle(new PayrollPeriodFinalized($period));

        Mail::assertNothingQueued();
    }

    public function test_feature_flag_off_disables_emailing(): void
    {
        Mail::fake();
        app(\App\Common\Services\SettingsService::class)
            ->set('payroll.payslip_email.enabled', false, 'payroll');
        Cache::forget('settings:payroll.payslip_email.enabled');

        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create(['email' => 'y@example.test']);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        app(EmailPayslipPdfOnPayrollFinalized::class)->handle(new PayrollPeriodFinalized($period));

        Mail::assertNothingQueued();
    }
}
