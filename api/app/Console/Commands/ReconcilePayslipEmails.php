<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\EmailPayslipPdfOnPayrollFinalized;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Recover payslip email jobs after the finalization listener has already
 * completed.
 *
 * The finalization event queues one child job per employee. A child job can
 * later fail, or a worker can die after a row is claimed. Replaying the
 * finalization event manually is an unsafe recovery contract because it can
 * repeat unrelated finalization listeners. This command targets only rows
 * whose delivery state is pending, failed, or stale-queued.
 */
class ReconcilePayslipEmails extends Command
{
    protected $signature = 'payroll:reconcile-payslip-emails
        {--limit=50 : Maximum number of finalized payroll periods to inspect}
        {--stale-minutes=15 : Age after which a queued payslip claim can be reclaimed}
        {--max-attempts=10 : Maximum automatic delivery claims per payslip}';

    protected $description = 'Recover failed or stale payslip email delivery jobs';

    public function handle(SettingsService $settings, EmailPayslipPdfOnPayrollFinalized $listener): int
    {
        if (! $settings->requiredBool('payroll.payslip_email.enabled')) {
            $this->info('Payslip email delivery is disabled.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $staleMinutes = (int) $this->option('stale-minutes');
        $maxAttempts = (int) $this->option('max-attempts');
        if ($limit < 1 || $limit > 500
            || $staleMinutes < 1 || $staleMinutes > 1440
            || $maxAttempts < 1 || $maxAttempts > 1000) {
            $this->error('--limit must be 1..500, --stale-minutes 1..1440, and --max-attempts 1..1000.');

            return self::FAILURE;
        }

        $staleBefore = Carbon::now()->subMinutes($staleMinutes);
        $periods = PayrollPeriod::query()
            ->whereIn('status', [
                PayrollPeriodStatus::Finalized->value,
                PayrollPeriodStatus::Disbursed->value,
            ])
            ->whereHas('payrolls', function ($query) use ($staleBefore, $maxAttempts): void {
                $query
                    ->whereNull('payslip_emailed_at')
                    ->where('payslip_email_attempts', '<', $maxAttempts)
                    ->whereHas('employee', fn ($employee) => $employee->whereNotNull('email')->where('email', '<>', ''))
                    ->where(function ($candidate) use ($staleBefore): void {
                        $candidate
                            ->whereNull('payslip_email_status')
                            ->orWhereIn('payslip_email_status', [
                                Payroll::EMAIL_PENDING,
                                Payroll::EMAIL_FAILED,
                            ])
                            ->orWhere(function ($stale) use ($staleBefore): void {
                                $stale
                                    ->where('payslip_email_status', Payroll::EMAIL_QUEUED)
                                    ->whereNotNull('payslip_email_queued_at')
                                    ->where('payslip_email_queued_at', '<=', $staleBefore);
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $failed = 0;
        foreach ($periods as $period) {
            try {
                $listener->handle(new PayrollPeriodFinalized($period));
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf(
                    'Payslip reconciliation failed for period #%d: %s',
                    $period->id,
                    $exception->getMessage(),
                ));
            }
        }

        $this->info(sprintf(
            'Payslip email reconciliation inspected %d finalized period(s); failures=%d.',
            $periods->count(),
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
