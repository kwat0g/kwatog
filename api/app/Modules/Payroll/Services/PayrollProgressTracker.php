<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\Cache;

/**
 * Last-known progress snapshot for an in-flight compute run.
 *
 * PayrollProgressEvent pushes live updates over Reverb, but a websocket only
 * carries events fired while the browser is subscribed. A user who opens (or
 * refreshes) the period page halfway through a 200-employee run would see an
 * empty bar until the next broadcast — and if the queue is backed up that can
 * be tens of seconds.
 *
 * So every broadcast also writes here, and PayrollPeriodResource reads it. The
 * page therefore renders real progress on first paint, and the 3s poll keeps
 * it moving even when the websocket is unavailable.
 *
 * Cache-only by design: this is disposable UI telemetry, not an audit record.
 * A cache flush mid-run costs a progress bar, never payroll data.
 */
class PayrollProgressTracker
{
    /** Comfortably longer than ProcessPayrollJob's 30-minute timeout. */
    private const TTL_SECONDS = 3600;

    public function key(PayrollPeriod|int $period): string
    {
        $id = $period instanceof PayrollPeriod ? $period->id : $period;

        return "payroll:progress:{$id}";
    }

    /**
     * @return array{processed:int,total:int,failures:int,percent:int,updated_at:string}
     */
    public function put(PayrollPeriod $period, int $processed, int $total, int $failures): array
    {
        $snapshot = [
            'processed'  => $processed,
            'total'      => $total,
            'failures'   => $failures,
            'percent'    => $total > 0 ? (int) round(($processed / $total) * 100) : 0,
            'updated_at' => now()->toIso8601String(),
        ];

        try {
            Cache::put($this->key($period), $snapshot, self::TTL_SECONDS);
        } catch (\Throwable) {
            // A cache outage must never break a payroll run — the bar just
            // falls back to the row counts the resource already exposes.
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
        } catch (\Throwable) {
            return null;
        }

        return is_array($snapshot) ? $snapshot : null;
    }

    public function forget(PayrollPeriod $period): void
    {
        try {
            Cache::forget($this->key($period));
        } catch (\Throwable) {
            // Best-effort — a stale entry expires on its own via the TTL.
        }
    }
}
