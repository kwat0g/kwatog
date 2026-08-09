<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Common\Services\SystemActorService;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * 2026-08-08 — When a PO is sent to the supplier, pre-create its expected
 * goods receipt as a DRAFT GRN (one line per PO line, zero quantities). The
 * warehouse then finalizes it when the goods land: assign bins + actual
 * quantities → pending_qc → incoming QC → stock.
 *
 * Idempotent: GrnService::createDraftForPo() returns the existing draft for
 * the PO, so a stale or duplicate event never stacks expectations.
 */
class CreateDraftGrnOnPoSent implements ShouldQueue
{
    public function __construct(
        private readonly GrnService $grns,
        private readonly SystemActorService $actors,
    ) {}

    public function handle(PurchaseOrderSent $event): void
    {
        $po = $event->purchaseOrder;

        if ($po->status !== PurchaseOrderStatus::Sent) {
            return; // stale copy, or the PO was cancelled after the event queued
        }
        if (! $po->items()->exists()) {
            return; // nothing to receive — a PO without lines stages nothing
        }

        $by = $this->actors->resolve();
        if (! $by) {
            Log::warning('CreateDraftGrnOnPoSent: no automation actor configured, skipping', [
                'po_id' => $po->id,
            ]);
            return;
        }

        try {
            $grn = $this->grns->createDraftForPo($po, $by);
            if ($grn) {
                Log::info('CreateDraftGrnOnPoSent: staged expected GRN', [
                    'po_id' => $po->id,
                    'grn_id' => $grn->id,
                    'grn_number' => $grn->grn_number,
                ]);
            }
        } catch (\Throwable $e) {
            // Never kill the queue job — the PO is still receivable manually.
            Log::warning('CreateDraftGrnOnPoSent failed — manual GRN creation remains available', [
                'po_id' => $po->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
