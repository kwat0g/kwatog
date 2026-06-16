<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
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
 * stays Running forever — blocking dashboards and leaving orphan draft auto-PRs
 * spawned mid-run.
 *
 * This command marks any Running row whose started_at is older than the
 * threshold (default 120 minutes) as Failed, and cancels the orphan draft
 * auto-generated purchase requests that were created during the dead run's
 * window (created_at >= started_at, still status=draft). Idempotent: rows
 * already Failed/Completed are ignored, and PRs already approved/converted are
 * left untouched.
 *
 * Scheduled hourly in routes/console.php (withoutOverlapping + onOneServer).
 */
class ReapStaleMrpRuns extends Command
{
    protected $signature   = 'mrp:reap-stale-runs {--minutes=120 : Age in minutes after which a Running MRP run is considered stale}';
    protected $description = 'Mark hung Running MRP runs as Failed and cancel their orphan draft auto-PRs (OGAMI-015)';

    public function handle(): int
    {
        $minutes   = max(1, (int) $this->option('minutes'));
        $threshold = Carbon::now()->subMinutes($minutes);

        $stale = MrpRun::query()
            ->where('status', MrpRunStatus::Running->value)
            ->where(function ($q) use ($threshold) {
                // Prefer started_at; fall back to run_at for legacy rows that
                // predate the OGAMI-015 columns.
                $q->where('started_at', '<', $threshold)
                  ->orWhere(function ($qq) use ($threshold) {
                      $qq->whereNull('started_at')->where('run_at', '<', $threshold);
                  });
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale MRP runs found.');
            return self::SUCCESS;
        }

        $reaped       = 0;
        $prsCancelled = 0;

        foreach ($stale as $run) {
            try {
                DB::transaction(function () use ($run, &$prsCancelled) {
                    $since = $run->started_at ?? $run->run_at;

                    $cancelled = PurchaseRequest::query()
                        ->where('is_auto_generated', true)
                        ->where('status', PurchaseRequestStatus::Draft->value)
                        ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                        ->lockForUpdate()
                        ->get();

                    foreach ($cancelled as $pr) {
                        // status is service-only / non-fillable — force it.
                        $pr->forceFill(['status' => PurchaseRequestStatus::Cancelled->value])->save();
                        $prsCancelled++;
                    }

                    $run->forceFill([
                        'status'        => MrpRunStatus::Failed->value,
                        'error_message' => 'Reaped by mrp:reap-stale-runs — run exceeded the stale threshold without completing.',
                        'heartbeat_at'  => Carbon::now(),
                    ])->save();
                });
                $reaped++;
            } catch (\Throwable $e) {
                Log::warning('mrp:reap-stale-runs — failed to reap run', [
                    'run_id' => $run->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reaped {$reaped} stale MRP run(s); cancelled {$prsCancelled} orphan draft auto-PR(s).");
        return self::SUCCESS;
    }
}
