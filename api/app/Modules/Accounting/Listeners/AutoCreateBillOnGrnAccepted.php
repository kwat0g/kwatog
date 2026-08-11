<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SystemActorService;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * 2026-08-08 — Auto-bill chain: when a GRN is fully accepted, pre-create the
 * supplier bill as a DRAFT (lines from the accepted receipt, default expense
 * account, vendor terms) so the payables team reviews and posts it. Nothing
 * touches the ledger until postDraft() runs.
 *
 * Idempotent: BillService::createDraftForGrn() returns null when a bill
 * already exists for the GRN or nothing was accepted — a stale or duplicate
 * event never stacks bills. Stateful failures are allowed to reach the queue
 * worker so transient errors are retried and permanent failures are visible
 * in failed_jobs.
 */
class AutoCreateBillOnGrnAccepted implements ShouldQueue
{
    public function __construct(
        private readonly BillService $bills,
        private readonly SystemActorService $actors,
    ) {}

    public function handle(GoodsReceiptNoteAccepted $event): void
    {
        $grn = $event->grn;

        $by = $this->actors->resolve();
        if (! $by) {
            throw new BusinessRuleException(
                "GRN {$grn->grn_number} was accepted, but no active automation actor is configured to stage its supplier bill."
            );
        }

        $bill = $this->bills->createDraftForGrn($grn->fresh(), $by);
        if ($bill) {
            Log::info('AutoCreateBillOnGrnAccepted: staged draft supplier bill', [
                'grn_id' => $grn->id,
                'bill_id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'total_amount' => (string) $bill->total_amount,
            ]);

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'supplier_bill_staged',
                "Staged supplier bill {$bill->bill_number} from GRN {$grn->grn_number}.",
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'bill_already_present_or_not_applicable',
        );
    }
}
