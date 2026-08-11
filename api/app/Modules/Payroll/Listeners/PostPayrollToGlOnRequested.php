<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Modules\Payroll\Enums\PayrollGlHandoffStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retry only the failed payroll-period → journal-entry handoff. */
class PostPayrollToGlOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly PayrollGlPostingService $postings) {}

    public function handle(PayrollGlPostingRequested $event): void
    {
        $period = PayrollPeriod::query()->find($event->period->id);
        if (! $period) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'payroll_period_missing',
            );
            return;
        }

        if (! in_array($period->status, [PayrollPeriodStatus::Finalized, PayrollPeriodStatus::Disbursed], true)) {
            if ($period->status === PayrollPeriodStatus::Voided && $period->journal_entry_id === null) {
                $period->markGlNotRequired('period_voided');
            }
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'payroll_period_not_postable',
                'The payroll period is no longer finalized or disbursed; the GL handoff is no longer applicable.',
            );
            return;
        }

        if ($period->journal_entry_id !== null) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'payroll_gl_entry_already_linked',
            );
            return;
        }

        try {
            $updated = $this->postings->retry($period);
        } catch (BusinessRuleException $e) {
            Log::warning('PostPayrollToGlOnRequested requires manual action', [
                'period_id' => $period->id,
                'reason_code' => $event->reasonCode,
                'error' => $e->getMessage(),
            ]);
            $this->postings->markManual($period->id, $e->getMessage());
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'payroll_gl_posting_manual_required',
                'Fix the Accounting configuration or posting period, then replay this handoff.',
            );
            return;
        } catch (Throwable $e) {
            Log::error('PostPayrollToGlOnRequested failed unexpectedly', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $status = $updated->gl_handoff_status instanceof PayrollGlHandoffStatus
            ? $updated->gl_handoff_status
            : PayrollGlHandoffStatus::tryFrom((string) $updated->gl_handoff_status);

        if ($status === PayrollGlHandoffStatus::Posted) {
            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'payroll_gl_posted',
                "Payroll period {$updated->id} was posted to the General Ledger.",
            );
            return;
        }

        if ($status === PayrollGlHandoffStatus::ManualRequired) {
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'payroll_gl_posting_manual_required',
                'Fix the Accounting configuration or posting period, then replay this handoff.',
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'payroll_gl_posting_not_required',
        );
    }
}
