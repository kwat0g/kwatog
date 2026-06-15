<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\CopqSnapshotComputed;
use App\Modules\Quality\Models\CopqSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * T3.6.C — Notify QC + production when month-over-month COPQ jumps ≥ +25%.
 *
 * Compares the freshly persisted snapshot's `total_cost` against the prior
 * calendar month's persisted snapshot. Skips silently when:
 *  - no prior month snapshot exists (no baseline)
 *  - prior snapshot's total_cost is 0 or negative (division by zero / no signal)
 *  - delta is below the +25% threshold
 *
 * Whole body wrapped in try/catch + Log::warning so a notification failure
 * never crashes the queue worker.
 */
class AlertOnCopqSpike implements ShouldQueue
{
    private const THRESHOLD = 0.25;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(CopqSnapshotComputed $event): void
    {
        try {
            $snap  = $event->snapshot;
            $prior = $this->priorMonth($snap);

            if (! $prior) {
                return;
            }

            $priorTotal = (float) $prior->total_cost;
            if ($priorTotal <= 0.0) {
                return;
            }

            $newTotal = (float) $snap->total_cost;
            $delta    = ($newTotal - $priorTotal) / $priorTotal;

            if ($delta < self::THRESHOLD) {
                return;
            }

            $audience = User::whereHas('role', fn ($q) =>
                    $q->whereIn('slug', ['qc_inspector', 'production_manager'])
                )
                ->where('is_active', true)
                ->get();

            if ($audience->isEmpty()) {
                return;
            }

            $pct   = number_format($delta * 100, 1);
            $label = sprintf('%04d-%02d', $snap->period_year, $snap->period_month);

            $this->notifications->send($audience, 'copq.spike', [
                'title'       => "COPQ Spike — {$label}",
                'message'     => "COPQ jumped {$pct}% MoM to ₱" . number_format($newTotal, 2) . '.',
                'link_to'     => '/quality/copq',
                'entity_type' => 'copq_snapshot',
                'entity_id'   => $snap->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AlertOnCopqSpike failed', ['error' => $e->getMessage()]);
        }
    }

    private function priorMonth(CopqSnapshot $snap): ?CopqSnapshot
    {
        $py = $snap->period_year;
        $pm = $snap->period_month - 1;
        if ($pm === 0) {
            $pm = 12;
            $py--;
        }

        return CopqSnapshot::where('period_year', $py)
            ->where('period_month', $pm)
            ->first();
    }
}
