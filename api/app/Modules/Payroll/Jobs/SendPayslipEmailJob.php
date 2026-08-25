<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Jobs;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Payroll\Mail\PayslipMail;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayslipPdfService;
use App\Modules\Payroll\Services\PayrollPublicationPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Deliver one payslip and only stamp the sent marker after the mailer accepts
 * the message. The finalization listener owns claiming; this job owns the
 * retryable PDF/render/mail boundary.
 */
class SendPayslipEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $payrollId) {}

    public function handle(PayslipPdfService $pdf, ?PayrollPublicationPolicy $publication = null): void
    {
        $publication ??= app(PayrollPublicationPolicy::class);
        $missingEmail = false;

        DB::transaction(function () use ($pdf, $publication, &$missingEmail): void {
            // Lock both rows through the render/send boundary. A period void
            // cannot commit between the publication check and mail acceptance.
            $payroll = Payroll::query()
                ->whereKey($this->payrollId)
                ->lockForUpdate()
                ->with(['employee.department', 'employee.position', 'deductionDetails'])
                ->first();

            if (! $payroll || $payroll->payslip_emailed_at !== null) {
                return;
            }

            // A replayed/stale job must never send a row that another attempt
            // has already moved out of the queued claim state.
            if ($payroll->payslip_email_status !== Payroll::EMAIL_QUEUED) {
                return;
            }

            $period = PayrollPeriod::query()->lockForUpdate()->find($payroll->payroll_period_id);
            $payroll->setRelation('period', $period);

            if (! $publication->isPayrollPublishable($payroll)) {
                // Voided/error rows remain historical, but their queued email
                // is terminal for this run. A replacement finalization can
                // claim it again from the failed state.
                $payroll->forceFill([
                    'payslip_email_status' => Payroll::EMAIL_FAILED,
                    'payslip_email_queued_at' => null,
                    'payslip_email_last_error' => 'Payroll period is no longer publishable; delivery cancelled.',
                ])->saveQuietly();
                return;
            }

            $email = $payroll->employee?->email;
            if (! $email) {
                $payroll->forceFill([
                    'payslip_email_status' => Payroll::EMAIL_FAILED,
                    'payslip_email_queued_at' => null,
                    'payslip_email_last_error' => 'Employee has no email address for payslip delivery.',
                ])->saveQuietly();
                $missingEmail = true;
                return;
            }

            $binary = $pdf->generate($payroll);
            $filename = $pdf->filename($payroll);

            // PayslipMail is intentionally sent synchronously inside this
            // retryable job. The period row remains locked until the sent
            // marker is committed, so void cannot race the final send check.
            Mail::to($email)->send(new PayslipMail($payroll, $binary, $filename));

            $payroll->forceFill([
                'payslip_emailed_at' => now(),
                'payslip_email_status' => Payroll::EMAIL_SENT,
                'payslip_email_queued_at' => null,
                'payslip_email_last_error' => null,
            ])->saveQuietly();
        });

        if ($missingEmail) {
            app(EmailDeliveryFailureNotifier::class)->notifyUserId(
                $this->employeeUserId(),
                'Employee payslip',
                'Your payslip email could not be delivered. Open the payroll section in the application to view it.',
                [
                    'link_to' => '/self-service/payslips',
                    'entity_type' => 'payroll',
                    'entity_id' => Payroll::find($this->payrollId)?->hash_id,
                    'reason' => 'The employee has no email address for payslip delivery.',
                ],
            );
        }
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($e->getMessage());

        Log::error('SendPayslipEmailJob failed', [
            'payroll_id' => $this->payrollId,
            'error' => $e->getMessage(),
        ]);
    }

    private function markFailed(string $message): void
    {
        DB::transaction(function () use ($message): void {
            $row = Payroll::query()->lockForUpdate()->find($this->payrollId);
            if (! $row || $row->payslip_emailed_at !== null) {
                return;
            }

            $row->forceFill([
                'payslip_email_status' => Payroll::EMAIL_FAILED,
                'payslip_email_queued_at' => null,
                'payslip_email_last_error' => mb_substr($message, 0, 65535),
            ])->saveQuietly();
        });

        $payroll = Payroll::query()->with('employee.user')->find($this->payrollId);
        app(EmailDeliveryFailureNotifier::class)->notifyUserId(
            $payroll?->employee?->user?->id,
            'Employee payslip',
            'Your payslip email could not be delivered. Open the payroll section in the application to view it.',
            [
                'link_to' => '/self-service/payslips',
                'entity_type' => 'payroll',
                'entity_id' => $payroll?->hash_id,
                'reason' => 'The email provider rejected or could not deliver the payslip.',
            ],
        );
    }

    private function employeeUserId(): ?int
    {
        return Payroll::query()
            ->with('employee.user')
            ->find($this->payrollId)?->employee?->user?->id;
    }
}
