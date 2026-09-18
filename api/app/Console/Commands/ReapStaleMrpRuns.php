<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Models\MrpRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OGAMI-015 — reap stale MRP runs.
 *
 * MrpEngineService::runForAllActiveSalesOrders() creates a Running mrp_runs row
 * before iterating sales orders. If the worker is hard-killed (OOM, SIGKILL,
 * container restart) the row is never transitioned to Completed/Failed and
 * stays Running forever — blocking dashboards and leaving draft auto-PRs that
 * the next run must reconcile.
 *
 * This command marks any Running row whose last heartbeat is older than the
 * threshold (default 120 minutes) as Failed. Purchase requests are deliberately
 * not cancelled here: the run-to-PR ownership is not durable, so a time-window
 * cleanup can cancel a legitimate draft from another overlapping run. The next
 * MRP run reconciles superseded draft auto-PRs safely.
 *
 * Scheduled hourly in routes/console.php (withoutOverlapping + onOneServer).
 */
class ReapStaleMrpRuns extends Command
{
    protected $signature = 'mrp:reap-stale-runs {--minutes=120 : Age in minutes after which a Running MRP run is considered stale}';

    protected $description = 'Mark hung Running MRP runs as Failed for safe reconciliation (OGAMI-015)';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $threshold = Carbon::now()->subMinutes($minutes);

        $stale = MrpRun::query()
            ->where('status', MrpRunStatus::Running->value)
            ->whereRaw('COALESCE(heartbeat_at, started_at, run_at) < ?', [$threshold])
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale MRP runs found.');

            return self::SUCCESS;
        }

        $reaped = 0;
        $errors = 0;

        foreach ($stale as $run) {
            try {
                $wasReaped = DB::transaction(function () use ($run, $threshold): bool {
                    $lockedRun = MrpRun::query()
                        ->whereKey($run->id)
                        ->where('status', MrpRunStatus::Running->value)
                        ->whereRaw('COALESCE(heartbeat_at, started_at, run_at) < ?', [$threshold])
                        ->lockForUpdate()
                        ->first();

                    if ($lockedRun === null) {
                        return false;
                    }

                    $now = Carbon::now();
                    $lockedRun->forceFill([
                        'status' => MrpRunStatus::Failed->value,
                        'error_message' => 'Reaped by mrp:reap-stale-runs — run exceeded the stale threshold without completing.',
                        'error_code' => 'mrp_stale_run',
                        'recovery_action' => 'Review the run history and rerun the affected sales orders after confirming draft auto-PRs.',
                        'duration_ms' => $lockedRun->started_at
                            ? max(0, (int) $lockedRun->started_at->diffInMilliseconds($now))
                            : null,
                        'heartbeat_at' => $now,
                    ])->save();

                    return true;
                });
                if ($wasReaped) {
                    $reaped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('mrp:reap-stale-runs — failed to reap run', [
                    'run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reaped {$reaped} stale MRP run(s); draft auto-PRs were left untouched for safe reconciliation.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
