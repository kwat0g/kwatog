<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * OGAMI-005 / trace §7.7 — COA verification is a QUALITY decision, so the
 * flag is set here and nowhere else.
 *
 * Receiving captures the CoA document reference (`coa_document_path`) but
 * always stores `coa_verified = false` — the column had no writer at all
 * before this listener, which made it dead data. Now: when the incoming
 * inspection for a line PASSES, and the line actually carries a CoA document,
 * the pass itself is the verification evidence and the flag flips true.
 *
 * Deliberately narrow:
 *  - Only incoming-stage, grn-entity inspections (an outgoing pass verifies
 *    finished goods, not purchased material).
 *  - Only per-line inspections (`grn_item_id` set). The legacy whole-GRN
 *    fallback inspection has no line attribution, so it cannot verify any
 *    single document reference.
 *  - No document reference → the flag stays false. "Passed without a CoA"
 *    is a true statement about the receipt and must not be laundered into
 *    verified.
 *
 * Idempotent: an already-verified line is a skipped no-op, and the row lock
 * serialises a redelivered event against a concurrent human edit.
 */
class VerifyCoaOnIncomingQcPass implements ShouldQueue
{
    public function handle(InspectionPassed $event): void
    {
        $inspection = $event->inspection->fresh();
        if (! $inspection
            || $inspection->status !== InspectionStatus::Passed
            || $inspection->stage?->value !== InspectionStage::Incoming->value) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_incoming_pass');

            return;
        }

        $entityType = $inspection->entity_type instanceof \BackedEnum
            ? $inspection->entity_type->value
            : (string) $inspection->entity_type;
        if ($entityType !== 'grn') {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'non_grn_inspection');

            return;
        }

        if ($inspection->grn_item_id === null) {
            // Legacy whole-GRN verdict — no line attribution to verify.
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'legacy_whole_grn_inspection');

            return;
        }

        $verified = DB::transaction(function () use ($inspection): ?string {
            $row = GrnItem::query()->whereKey($inspection->grn_item_id)->lockForUpdate()->first();
            if (! $row) {
                return 'grn_item_missing';
            }
            if ($row->coa_verified) {
                return 'already_verified';
            }
            if ($row->coa_document_path === null || trim((string) $row->coa_document_path) === '') {
                return 'no_coa_document';
            }

            $row->forceFill(['coa_verified' => true])->save();

            return 'coa_verified';
        });

        app(ChainListenerRunService::class)->recordOutcome(
            $verified === 'coa_verified' ? 'completed' : 'skipped',
            $verified,
            $verified === 'coa_verified'
                ? "CoA marked verified for GRN line {$inspection->grn_item_id} (incoming QC pass)."
                : null,
        );
    }
}
