<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
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
        // Queue payloads contain a serialized model snapshot. Re-read it before
        // resolving the actor or doing any work so a late sent event becomes a
        // harmless no-op after cancellation or receipt.
        $po = $event->purchaseOrder->fresh();
        if (! $po) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'purchase_order_missing');
            return;
        }

        if ($po->status !== PurchaseOrderStatus::Sent) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_sent');
            return; // stale copy, or the PO was cancelled after the event queued
        }
        if (! $po->items()->exists()) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'no_purchase_order_lines');
            return; // nothing to receive — a PO without lines stages nothing
        }

        $by = $this->actors->resolve();
        if (! $by) {
            throw new BusinessRuleException(
                "PO {$po->po_number} was sent, but no active automation actor is configured to stage its expected GRN."
            );
        }

        $grn = $this->grns->createDraftForPo($po, $by);
        if ($grn) {
            Log::info('CreateDraftGrnOnPoSent: staged expected GRN', [
                'po_id' => $po->id,
                'grn_id' => $grn->id,
                'grn_number' => $grn->grn_number,
            ]);

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'expected_grn_staged',
                "Staged expected GRN {$grn->grn_number} for PO {$po->po_number}.",
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'expected_grn_already_present_or_not_applicable',
        );
    }
}
