<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trace §7.9 — there was no automatic retry of a failed incoming-QC handoff:
 * a GRN whose synchronous TriggerIncomingQC call threw was marked
 * `manual_required`/`not_started` and stayed that way until a human happened
 * to POST /grn/{id}/retry-incoming-qc. This sweep closes that gap.
 *
 * It deliberately targets two states:
 *  - `not_started` on a pending_qc GRN — the handoff never fired at all
 *    (e.g. queue down at receipt time and the afterCommit replay also lost).
 *  - `manual_required` — the stateful failure (missing quality plan, bad
 *    setup) that TriggerIncomingQC recorded. Retrying is safe: the listener
 *    is idempotent, and a failure simply re-records the same manual state
 *    with a fresh message.
 *
 * Distinguish nothing-done from everything-failed (repo rule): exit FAILURE
 * when any retry errored, even if others succeeded. The listener's own
 * stateful failures are NOT command errors — they keep the GRN in
 * manual_required and count as retried, not failed.
 */
class RetryPendingIncomingQc extends Command
{
    protected $signature = 'grn:retry-pending-incoming-qc {--limit=50}';

    protected $description = 'Retry the GRN → incoming-QC handoff for receipts whose QC staging failed or never started';

    public function handle(GrnService $grns): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $grnIds = GoodsReceiptNote::query()
            ->where('status', GrnStatus::PendingQc->value)
            ->whereIn('incoming_qc_handoff_status', [
                IncomingQcHandoffStatus::NotStarted->value,
                IncomingQcHandoffStatus::ManualRequired->value,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($grnIds->isEmpty()) {
            $this->info('No GRNs awaiting incoming-QC handoff retry.');

            return self::SUCCESS;
        }

        $retried = 0;
        $errors = 0;
        foreach ($grnIds as $grnId) {
            try {
                $grns->retryIncomingQcHandoff(GoodsReceiptNote::findOrFail($grnId));
                $retried++;
            } catch (Throwable $e) {
                $errors++;
                Log::error('grn:retry-pending-incoming-qc failed', [
                    'grn_id' => $grnId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Retried incoming-QC handoff for {$retried} GRN(s).".($errors ? " {$errors} failed." : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
