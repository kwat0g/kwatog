<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

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
 * event never stacks bills.
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
            Log::warning('AutoCreateBillOnGrnAccepted: no automation actor configured, skipping', [
                'grn_id' => $grn->id,
            ]);
            return;
        }

        try {
            $bill = $this->bills->createDraftForGrn($grn->fresh(), $by);
            if ($bill) {
                Log::info('AutoCreateBillOnGrnAccepted: staged draft supplier bill', [
                    'grn_id' => $grn->id,
                    'bill_id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'total_amount' => (string) $bill->total_amount,
                ]);
            }
        } catch (\Throwable $e) {
            // Never kill the queue job — the bill can still be created
            // manually. The GRN is already accepted; AP is not blocked.
            Log::warning('AutoCreateBillOnGrnAccepted failed — manual bill entry remains available', [
                'grn_id' => $grn->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
