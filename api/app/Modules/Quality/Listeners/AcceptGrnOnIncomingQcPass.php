<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Series C — P2P incoming-QC handoff.
 *
 * An incoming inspection pass is the approval to release the linked GRN. The
 * listener waits until every incoming inspection for a multi-line GRN has
 * passed, then delegates to GrnService so stock movement, GL posting, the
 * accepted event, and the draft-bill listener remain one idempotent chain.
 *
 * The row lock and terminal-status guard make duplicate outbox publication,
 * queue retries, and a human acceptance racing the listener safe. Missing
 * automation attribution is a stateful configuration failure: rethrow it so
 * the queue ledger exposes the blocked handoff instead of silently leaving a
 * passed receipt in pending_qc.
 */
class AcceptGrnOnIncomingQcPass implements ShouldQueue
{
    public function __construct(
        private readonly GrnService $grns,
        private readonly SystemActorService $actors,
    ) {}

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

        $grn = GoodsReceiptNote::query()->find($inspection->entity_id);
        if (! $grn || $grn->status !== GrnStatus::PendingQc) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'grn_already_terminal_or_missing');
            return;
        }

        $by = $inspection->inspector_id
            ? User::query()->find($inspection->inspector_id)
            : null;
        $by ??= $this->actors->resolve();
        if (! $by) {
            throw new BusinessRuleException(
                "Incoming QC passed for GRN {$grn->grn_number}, but no automation actor is configured to release it."
            );
        }

        $outcomeCode = DB::transaction(function () use ($grn, $by): string {
            $lockedGrn = GoodsReceiptNote::query()
                ->lockForUpdate()
                ->find($grn->id);
            if (! $lockedGrn || $lockedGrn->status !== GrnStatus::PendingQc) {
                return 'grn_already_terminal_or_missing';
            }

            $incomingInspections = Inspection::query()
                ->where('stage', InspectionStage::Incoming->value)
                ->where('entity_type', 'grn')
                ->where('entity_id', $lockedGrn->id)
                ->get(['id', 'status']);

            // A single-line GRN normally has one row; multi-line receipts can
            // have several. Never release the whole receipt on the first pass.
            // Keep this invariant aligned with GrnService::assertQcGate(): a
            // cancelled inspection is an explicit completed logistics
            // decision, not an unresolved QC verdict. A passed sibling may
            // therefore release a multi-line GRN when the remaining sibling
            // was cancelled rather than left draft/in-progress/failed.
            if ($incomingInspections->isEmpty()
                || $incomingInspections->contains(fn (Inspection $row): bool => ! in_array(
                    $row->status,
                    [InspectionStatus::Passed, InspectionStatus::Cancelled],
                    true,
                ))) {
                return 'awaiting_sibling_qc';
            }

            $this->grns->accept($lockedGrn, $by);

            return 'grn_accepted';
        });

        app(ChainListenerRunService::class)->recordOutcome(
            $outcomeCode === 'grn_accepted' ? 'completed' : 'skipped',
            $outcomeCode,
            $outcomeCode === 'grn_accepted'
                ? "Incoming QC released GRN {$grn->grn_number}."
                : null,
        );
    }
}
