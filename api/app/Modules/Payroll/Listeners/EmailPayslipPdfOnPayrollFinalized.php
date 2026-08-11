<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SettingsService;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Jobs\SendPayslipEmailJob;
use App\Modules\Payroll\Models\Payroll;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailPayslipPdfOnPayrollFinalized implements ShouldQueue
{
    private const STALE_CLAIM_MINUTES = 15;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        if (! $this->settings->requiredBool('payroll.payslip_email.enabled')) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'feature_disabled');
            return;
        }

        $staleBefore = now()->subMinutes(self::STALE_CLAIM_MINUTES);

        $payrolls = Payroll::query()
            ->where('payroll_period_id', $event->period->id)
            ->whereNull('payslip_emailed_at')
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->whereNull('payslip_email_status')
                    ->orWhereIn('payslip_email_status', [Payroll::EMAIL_PENDING, Payroll::EMAIL_FAILED])
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale
                            ->where('payslip_email_status', Payroll::EMAIL_QUEUED)
                            ->whereNotNull('payslip_email_queued_at')
                            ->where('payslip_email_queued_at', '<=', $staleBefore);
                    });
            })
            ->with('employee:id,email')
            ->get();

        $queued = 0;
        foreach ($payrolls as $payroll) {
            if (! $payroll->employee?->email || ! $this->claim($payroll->id, $staleBefore)) {
                continue;
            }

            try {
                SendPayslipEmailJob::dispatch($payroll->id);
                $queued++;
            } catch (Throwable $e) {
                $this->markDispatchFailed($payroll->id, $e->getMessage());
                Log::channel('single')->warning('EmailPayslipPdfOnPayrollFinalized dispatch failed', [
                    'payroll_id' => $payroll->id,
                    'error' => $e->getMessage(),
                ]);

                // The row is returned to a retryable state before the queued
                // listener retries the durable finalization event.
                throw $e;
            }
        }

        app(ChainListenerRunService::class)->recordOutcome(
            $queued > 0 ? 'completed' : 'skipped',
            $queued > 0 ? 'payslip_email_jobs_queued' : 'no_eligible_payslips',
            $queued > 0 ? "Queued {$queued} payslip email job(s)." : null,
        );
    }

    private function claim(int $payrollId, Carbon $staleBefore): bool
    {
        return DB::transaction(function () use ($payrollId, $staleBefore): bool {
            $payroll = Payroll::query()
                ->lockForUpdate()
                ->with('employee:id,email')
                ->find($payrollId);

            if (! $payroll || $payroll->payslip_emailed_at !== null || ! $payroll->employee?->email) {
                return false;
            }

            $status = $payroll->payslip_email_status;
            $stale = $status === Payroll::EMAIL_QUEUED
                && $payroll->payslip_email_queued_at !== null
                && $payroll->payslip_email_queued_at->lte($staleBefore);
            $claimable = $status === null
                || in_array($status, [Payroll::EMAIL_PENDING, Payroll::EMAIL_FAILED], true)
                || $stale;

            if (! $claimable) {
                return false;
            }

            $payroll->forceFill([
                'payslip_email_status' => Payroll::EMAIL_QUEUED,
                'payslip_email_attempts' => ((int) $payroll->payslip_email_attempts) + 1,
                'payslip_email_queued_at' => now(),
                'payslip_email_last_error' => null,
            ])->saveQuietly();

            return true;
        });
    }

    private function markDispatchFailed(int $payrollId, string $message): void
    {
        DB::transaction(function () use ($payrollId, $message): void {
            $payroll = Payroll::query()->lockForUpdate()->find($payrollId);
            if (! $payroll || $payroll->payslip_emailed_at !== null) {
                return;
            }

            if ($payroll->payslip_email_status !== Payroll::EMAIL_QUEUED) {
                return;
            }

            $payroll->forceFill([
                'payslip_email_status' => Payroll::EMAIL_FAILED,
                'payslip_email_queued_at' => null,
                'payslip_email_last_error' => mb_substr($message, 0, 65535),
            ])->saveQuietly();
        });
    }
}
