<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Support\CacheGeneration;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Last-known progress snapshot for an in-flight compute run.
 *
 * PayrollProgressEvent pushes live updates over Reverb, but a websocket only
 * carries events fired while the browser is subscribed. A user who opens (or
 * refreshes) the period page halfway through a 200-employee run would see an
 * empty bar until the next broadcast — and if the queue is backed up that can
 * be tens of seconds.
 *
 * So every progress signal also writes here, and PayrollPeriodResource reads
 * it. The page renders current progress on first paint; the root SPA provider
 * refetches active queries with backoff while Reverb is unavailable.
 *
 * Cache-only by design: this is disposable UI telemetry, not an audit record.
 * A cache flush mid-run costs a progress bar, never payroll data.
 */
class PayrollProgressTracker
{
    /** Comfortably longer than ProcessPayrollJob's 30-minute timeout. */
    private const TTL_SECONDS = 3600;

    private const RESTORE_VERSION_KEY = 'payroll:progress:restore_version';

    private const RESTORE_VERSION_TTL_SECONDS = 604800;

    public function key(PayrollPeriod|int $period): string
    {
        $id = $period instanceof PayrollPeriod ? $period->id : $period;

        $version = CacheGeneration::currentDefault(
            self::RESTORE_VERSION_KEY,
            self::RESTORE_VERSION_TTL_SECONDS,
            'payroll.progress.restore',
        );

        return "payroll:progress:v{$version}:{$id}";
    }

    /**
     * @return array{processed:int,total:int,failures:int,percent:int,updated_at:string}
     */
    public function put(PayrollPeriod $period, int $processed, int $total, int $failures): array
    {
        $snapshot = [
            'processed' => $processed,
            'total' => $total,
            'failures' => $failures,
            'percent' => $total > 0 ? (int) round(($processed / $total) * 100) : 0,
            'updated_at' => now()->toIso8601String(),
        ];

        // The API exposes this snapshot only while a run is Processing. Keep
        // terminal progress on the broadcast signal, not in Redis for an hour.
        if ($period->status !== PayrollPeriodStatus::Processing) {
            return $snapshot;
        }

        try {
            Cache::put($this->key($period), $snapshot, self::TTL_SECONDS);
        } catch (\Throwable $exception) {
            // A cache outage must never break a payroll run — the bar just
            // falls back to the row counts the resource already exposes.
            Log::warning('Payroll progress cache write failed.', ['exception' => $exception, 'period_id' => $period->getKey()]);
        }

        return $snapshot;
    }

    /**
     * @return array{processed:int,total:int,failures:int,percent:int,updated_at:string}|null
     */
    public function get(PayrollPeriod $period): ?array
    {
        try {
            $snapshot = Cache::get($this->key($period));
        } catch (\Throwable $exception) {
            Log::warning('Payroll progress cache read failed.', ['exception' => $exception, 'period_id' => $period->getKey()]);

            return null;
        }

        return is_array($snapshot) ? $snapshot : null;
    }

    public function forget(PayrollPeriod $period): void
    {
        $key = $this->key($period);
        $periodId = $period->getKey();

        // Claims and force-unlocks call this inside a database transaction.
        // Defer cache retirement so a rollback cannot erase the last committed
        // progress snapshot, and readers cannot observe the new cache state
        // before the matching period transition is committed.
        DB::afterCommit(static function () use ($key, $periodId): void {
            try {
                Cache::forget($key);
            } catch (\Throwable $exception) {
                // Best-effort — a stale entry expires on its own via the TTL.
                Log::warning('Payroll progress cache invalidation failed.', [
                    'exception' => $exception,
                    'period_id' => $periodId,
                ]);
            }
        });
    }

    /** Hide all snapshots produced for the database that was just replaced. */
    public function invalidateForDatabaseRestore(): bool
    {
        return CacheGeneration::rotateDefault(
            self::RESTORE_VERSION_KEY,
            self::RESTORE_VERSION_TTL_SECONDS,
            'payroll.progress.database_restore',
        );
    }
}
