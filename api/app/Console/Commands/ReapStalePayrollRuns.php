<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reap stale payroll compute claims.
 *
 * PayrollPeriodService::claimForCompute() flips a period to Processing before
 * ProcessPayrollJob is dispatched. If the worker is hard-killed (OOM, SIGKILL,
 * container restart) the job's finally block never runs and the row stays
 * Processing forever — the period page shows a permanent "Computing…" spinner
 * and every Compute click returns "already being computed".
 *
 * claimForCompute() now takes over a stale claim on the next click, which fixes
 * the detail page. This command covers the rest: a period nobody revisits stays
 * wrong on the list and pipeline views indefinitely. Hourly sweep puts it back
 * on its correct terminal status (Computed when the dead run produced rows,
 * Draft when it produced none) so the whole UI self-heals.
 *
 * Idempotent: only Processing rows past the threshold are touched, and the
 * transition is the same releaseClaim() every other path uses.
 *
 * Scheduled hourly in routes/console.php (withoutOverlapping + onOneServer).
 */
class ReapStalePayrollRuns extends Command
{
    protected $signature = 'payroll:reap-stale-runs
        {--minutes= : Age in minutes after which a Processing claim is stale (defaults to the payroll.compute.stale_after_minutes setting)}';

    protected $description = 'Release payroll periods wedged at Processing by a crashed compute worker';

    public function handle(PayrollPeriodService $periods, PayrollProgressTracker $progress): int
    {
        // The service floors this above ProcessPayrollJob's timeout so a
        // healthy long run is never reclaimed out from under its own worker.
        $minutes = $this->option('minutes') !== null
            ? max(1, (int) $this->option('minutes'))
            : $periods->staleAfterMinutes();

        $threshold = Carbon::now()->subMinutes($minutes);

        $stale = PayrollPeriod::query()
            ->where('status', PayrollPeriodStatus::Processing->value)
            ->where(function ($q) use ($threshold) {
                // A null stamp means the claim predates processing_started_at
                // tracking — treat it as stale rather than wedging it forever.
                $q->where('processing_started_at', '<', $threshold)
                  ->orWhereNull('processing_started_at');
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale payroll compute runs found.');

            return self::SUCCESS;
        }

        $reaped = 0;
        $errors = 0;

        foreach ($stale as $period) {
            try {
                $released = $periods->reapStaleClaim($period, $threshold);
                if ($released === null) {
                    continue;
                }

                $progress->forget($released);

                $this->line(sprintf(
                    '  Released period #%d (%s) → %s',
                    $released->id,
                    $released->label(),
                    $released->status?->value ?? 'unknown',
                ));
                $reaped++;
            } catch (\Throwable $e) {
                $errors++;
                // One bad row must not abort the sweep.
                Log::warning('payroll:reap-stale-runs — failed to release claim', [
                    'period_id' => $period->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->info("Released {$reaped} stale payroll compute claim(s) older than {$minutes} minute(s).");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
