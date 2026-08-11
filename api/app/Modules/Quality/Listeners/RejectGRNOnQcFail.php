<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C2. When an INCOMING inspection fails, automatically
 * reject the linked GRN. Stock is NOT incremented (GrnService::reject
 * just flips the status), and the existing NCR auto-open path inside
 * InspectionService handles the corrective-action side.
 *
 * Stage filter: only acts on incoming inspections linked to a GRN.
 * Idempotent: skips if the GRN is already in a terminal status.
 * A missing actor is a stateful configuration failure: it is rethrown so the
 * queue worker can retry and retain a failed-job record instead of reporting a
 * completed handoff while the GRN remains pending_qc.
 *
 * Why this lives here and not inside InspectionService::complete():
 * keeping the side-effect in a listener preserves the existing
 * inspection completion API (still returns the inspection, no GRN
 * coupling), and lets future consumers subscribe to InspectionFailed
 * for their own purposes without those purposes being baked in.
 */
class RejectGRNOnQcFail implements ShouldQueue
{
    public function __construct(private readonly GrnService $grns, private readonly ?SettingsService $settings = null) {}

    public function handle(InspectionFailed $event): void
    {
        $inspection = $event->inspection->fresh();
        if (!$inspection
            || $inspection->status !== InspectionStatus::Failed
            || $inspection->stage?->value !== InspectionStage::Incoming->value) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_incoming_failure');
            return;
        }

        $entityTypeValue = $inspection->entity_type instanceof \BackedEnum
            ? $inspection->entity_type->value
            : (string) $inspection->entity_type;
        if ($entityTypeValue !== 'grn') {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'non_grn_inspection');
            return;
        }

        $grn = GoodsReceiptNote::find($inspection->entity_id);
        if (! $grn) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'grn_missing');
            return;
        }

        // Idempotent — only reject GRNs sitting at pending_qc. The status is
        // checked again under the row lock below because acceptance/rejection
        // can race with a queued inspection event.
        $statusValue = $grn->status instanceof \BackedEnum
            ? $grn->status->value
            : (string) $grn->status;
        if ($statusValue !== 'pending_qc') {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'grn_already_terminal');
            return;
        }

        // Use a system user attribution — the inspection has the actual
        // inspector; we route the GRN reject through GrnService to keep
        // the audit trail consistent. If the inspection has no inspector
        // (auto-created or imported), fall back to a system_admin actor
        // so the failed GRN doesn't sit at pending_qc forever.
        $by = $inspection->inspector_id
            ? User::find($inspection->inspector_id)
            : null;
        if (! $by) {
            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('quality.grn_qc_failure.actor_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $by = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->first();
        }
        if (! $by) {
            $message = "Incoming QC failed for GRN {$grn->grn_number}, but no active actor is configured to reject it.";
            Log::warning($message, [
                'grn_id'        => $grn->id,
                'inspection_id' => $inspection->id,
            ]);
            throw new BusinessRuleException($message);
        }

        $outcomeCode = DB::transaction(function () use ($grn, $inspection, $by): string {
            $lockedGrn = GoodsReceiptNote::query()
                ->lockForUpdate()
                ->find($grn->id);
            if (! $lockedGrn) {
                return 'grn_missing';
            }
            if ($lockedGrn->status?->value !== 'pending_qc') {
                return 'grn_already_terminal';
            }

            $reason = "Auto-rejected: incoming inspection {$inspection->inspection_number} failed.";
            $this->grns->reject($lockedGrn, $reason, $by);

            return 'grn_rejected';
        });

        app(ChainListenerRunService::class)->recordOutcome(
            $outcomeCode === 'grn_rejected' ? 'completed' : 'skipped',
            $outcomeCode,
            $outcomeCode === 'grn_rejected'
                ? "Incoming QC rejected GRN {$grn->grn_number}."
                : null,
        );
    }
}
