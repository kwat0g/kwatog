<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Jobs\SendPayslipEmailJob;
use App\Modules\Payroll\Mail\PayslipMail;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayslipPdfService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Mail\Markdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendPayslipEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_sent_only_after_mail_send_succeeds(): void
    {
        Mail::fake();

        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'finalized'])->saveQuietly();
        $employee = Employee::factory()->create(['email' => 'a@example.test']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);

        (new SendPayslipEmailJob($payroll->id))->handle();

        Mail::assertSent(PayslipMail::class, function (PayslipMail $mail): bool {
            $html = $mail->render();
            $mail->assertSeeInText('secure Self-Service portal');

            return $mail->hasTo('a@example.test')
                && str_contains($html, 'https://portal.example.test/self-service/payslips')
                && str_contains($html, '<title>Payslip ready</title>')
                && str_contains($html, 'Open secure payslip portal')
                && ! $mail->hasAttachment('payslip.pdf');
        });
        $sent = $payroll->fresh();
        $this->assertNotNull($sent->payslip_emailed_at);
        $this->assertSame(Payroll::EMAIL_SENT, $sent->payslip_email_status);
        $this->assertNull($sent->payslip_email_queued_at);
        $this->assertNull($sent->payslip_email_last_error);
    }

    public function test_invalid_employee_email_fails_fast_without_attempting_delivery(): void
    {
        Mail::fake();

        $pdf = \Mockery::mock(PayslipPdfService::class);
        $pdf->shouldNotReceive('generate');

        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'finalized'])->saveQuietly();
        $employee = Employee::factory()->create(['email' => 'not-an-email']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);

        (new SendPayslipEmailJob($payroll->id))->handle($pdf);

        Mail::assertNothingSent();
        $failed = $payroll->fresh();
        $this->assertNull($failed->payslip_emailed_at);
        $this->assertSame(Payroll::EMAIL_FAILED, $failed->payslip_email_status);
        $this->assertSame(
            'Employee has no usable email address for payslip delivery.',
            $failed->payslip_email_last_error,
        );
    }

    public function test_terminal_job_failure_leaves_a_retryable_failed_state(): void
    {
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'finalized'])->saveQuietly();
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

    public function test_delivery_failure_notifies_only_active_users_with_own_payslip_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $employee = Employee::factory()->create();
        $user = User::factory()->withRole('employee')->create(['employee_id' => $employee->id]);
        $restrictedEmployee = Employee::factory()->create();
        $restrictedUser = User::factory()->withRole('employee')->create(['employee_id' => $restrictedEmployee->id]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $restrictedUser->id,
            'permission_id' => Permission::query()->where('slug', 'payroll.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $user->id,
            'reason' => 'Payroll access revoked for failure recipient test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'finalized'])->saveQuietly();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);
        $restrictedPayroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $restrictedEmployee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);

        (new SendPayslipEmailJob($payroll->id))->failed(new \RuntimeException('SMTP unavailable'));
        (new SendPayslipEmailJob($restrictedPayroll->id))->failed(new \RuntimeException('SMTP unavailable'));

        $notice = DB::table('notifications')->where('notifiable_id', $user->id)
            ->where('type', 'email.delivery_failed')->first();
        $this->assertNotNull($notice);
        $data = json_decode((string) $notice->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/self-service/payslips/'.$payroll->hash_id, $data['link_to']);
        $this->assertSame($payroll->hash_id, $data['entity_id']);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $restrictedUser->id,
            'type' => 'email.delivery_failed',
        ]);
    }

    public function test_job_cancels_a_queued_payslip_when_the_period_is_voided(): void
    {
        Mail::fake();
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'voided'])->saveQuietly();
        $employee = Employee::factory()->create(['email' => 'a@example.test']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'payslip_email_status' => Payroll::EMAIL_QUEUED,
            'payslip_email_queued_at' => now(),
        ]);

        (new SendPayslipEmailJob($payroll->id))->handle();

        Mail::assertNothingSent();
        $cancelled = $payroll->fresh();
        $this->assertSame(Payroll::EMAIL_FAILED, $cancelled->payslip_email_status);
        $this->assertSame(
            'Payroll period is no longer publishable; delivery cancelled.',
            $cancelled->payslip_email_last_error,
        );
    }

    public function test_payslip_notice_links_to_owner_only_page_without_attaching_payroll_data(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-09-16',
            'period_end' => '2026-09-30',
        ]);
        $employee = Employee::factory()->create(['email' => 'a@example.test']);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'gross_pay' => '987654.32',
            'net_pay' => '876543.21',
        ]);
        $mail = new PayslipMail($payroll->load('period'));
        $content = $mail->content();
        $html = $mail->render();
        $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);

        $this->assertSame([], $mail->attachments);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertSame([], $mail->diskAttachments);
        $this->assertStringContainsString('[Ogami Philippines] Payslip available', $mail->envelope()->subject);
        $this->assertStringContainsString('Sep 16, 2026 – Sep 30, 2026', $html);
        $this->assertStringContainsString('https://erp.example.test/self-service/payslips/'.$payroll->hash_id, $html);
        $this->assertStringContainsString('https://erp.example.test/self-service/payslips/'.$payroll->hash_id, $text);
        $this->assertStringNotContainsString('987654.32', $html);
        $this->assertStringNotContainsString('876543.21', $html);
        $this->assertStringNotContainsString('987654.32', $text);
        $this->assertStringNotContainsString('876543.21', $text);
        $this->assertStringContainsString('Philippine Ogami Corporation', $html);
    }
}
