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
 * An incoming inspection pass is one signal that a GRN's QC is progressing.
 * The listener waits until every incoming inspection for the GRN is terminal,
 * then delegates to GrnService::settleIncomingQc() to decide: no failed lines
 * → accept, all failed → reject, mixed → partial accept + reject remainder.
 *
 * This prevents the old behavior where each pass checked only its siblings
 * without considering later failures. Now settlement is deterministic once
 * every line's QC is terminal.
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
            || ! $inspection->isMakerChecked()
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

            return $this->grns->settleIncomingQc($lockedGrn, $by);
        });

        $isCompleted = in_array($outcomeCode, ['grn_accepted', 'grn_rejected', 'grn_partially_accepted'], true);
        $message = null;
        if ($isCompleted) {
            $grn = $grn->fresh();
            match ($outcomeCode) {
                'grn_accepted' => $message = "Incoming QC released GRN {$grn->grn_number}.",
                'grn_rejected' => $message = "Incoming QC rejected GRN {$grn->grn_number}.",
                'grn_partially_accepted' => $message = "Incoming QC settled GRN {$grn->grn_number} with mixed results.",
                default => null,
            };
        } elseif ($outcomeCode === 'awaiting_mrb') {
            $message = "Incoming QC failure awaiting material review board disposition.";
        }

        app(ChainListenerRunService::class)->recordOutcome(
            $isCompleted ? 'completed' : 'skipped',
            $outcomeCode,
            $message,
        );
    }
}
