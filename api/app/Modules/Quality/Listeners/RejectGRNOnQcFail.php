<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Quality\Enums\InspectionStage;
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
 * Best-effort.
 *
 * Why this lives here and not inside InspectionService::complete():
 * keeping the side-effect in a listener preserves the existing
 * inspection completion API (still returns the inspection, no GRN
 * coupling), and lets future consumers subscribe to InspectionFailed
 * for their own purposes without those purposes being baked in.
 */
class RejectGRNOnQcFail implements ShouldQueue
{
    public function __construct(private readonly GrnService $grns) {}

    public function handle(InspectionFailed $event): void
    {
        try {
            $inspection = $event->inspection;
            if ($inspection->stage?->value !== InspectionStage::Incoming->value) return;

            $entityTypeValue = $inspection->entity_type instanceof \BackedEnum
                ? $inspection->entity_type->value
                : (string) $inspection->entity_type;
            if ($entityTypeValue !== 'grn') return;

            $grn = GoodsReceiptNote::find($inspection->entity_id);
            if (! $grn) return;

            // Idempotent — only reject GRNs sitting at pending_qc.
            $statusValue = $grn->status instanceof \BackedEnum
                ? $grn->status->value
                : (string) $grn->status;
            if ($statusValue !== 'pending_qc') return;

            // Use a system user attribution — the inspection has the actual
            // inspector; we route the GRN reject through GrnService to keep
            // the audit trail consistent. If the inspection has no inspector
            // (auto-created or imported), fall back to a system_admin actor
            // so the failed GRN doesn't sit at pending_qc forever.
            $by = $inspection->inspector_id
                ? User::find($inspection->inspector_id)
                : null;
            if (! $by) {
                $by = User::query()
                    ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
                    ->where('is_active', true)
                    ->first();
            }
            if (! $by) {
                Log::warning('RejectGRNOnQcFail: no actor available, GRN remains pending_qc', [
                    'grn_id'        => $grn->id,
                    'inspection_id' => $inspection->id,
                ]);
                return;
            }

            DB::transaction(function () use ($grn, $inspection, $by) {
                $reason = "Auto-rejected: incoming inspection {$inspection->inspection_number} failed.";
                $this->grns->reject($grn->fresh(), $reason, $by);
            });
        } catch (\Throwable $e) {
            Log::warning('RejectGRNOnQcFail failed', [
                'inspection_id' => $event->inspection->id ?? null,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
