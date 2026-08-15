<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Jobs;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Payroll\Mail\PayslipMail;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Services\PayslipPdfService;
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

    public function handle(PayslipPdfService $pdf): void
    {
        $payroll = Payroll::query()
            ->whereKey($this->payrollId)
            ->with(['employee.department', 'employee.position', 'period', 'deductionDetails'])
            ->first();

        if (! $payroll || $payroll->payslip_emailed_at !== null) {
            return;
        }

        // A replayed/stale job must never send a row that another attempt has
        // already moved out of the queued claim state.
        if ($payroll->payslip_email_status !== Payroll::EMAIL_QUEUED) {
            return;
        }

        $email = $payroll->employee?->email;
        if (! $email) {
            $this->markFailed('Employee has no email address for payslip delivery.');
            return;
        }

        $binary = $pdf->generate($payroll);
        $filename = $pdf->filename($payroll);

        // PayslipMail is intentionally sent synchronously inside this
        // retryable job. Marking the row before this call would confuse queue
        // acceptance with delivery and suppress recovery after a mail error.
        Mail::to($email)->send(new PayslipMail($payroll, $binary, $filename));

        DB::transaction(function (): void {
            $row = Payroll::query()->lockForUpdate()->find($this->payrollId);
            if (! $row || $row->payslip_emailed_at !== null) {
                return;
            }

            if ($row->payslip_email_status !== Payroll::EMAIL_QUEUED) {
                return;
            }

            $row->forceFill([
                'payslip_emailed_at' => now(),
                'payslip_email_status' => Payroll::EMAIL_SENT,
                'payslip_email_queued_at' => null,
                'payslip_email_last_error' => null,
            ])->saveQuietly();
        });
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
}
