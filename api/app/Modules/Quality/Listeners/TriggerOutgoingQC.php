<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C1 / ADV7. Outgoing QC auto-trigger.
 *
 * After a Work Order completes (Chain 1, in_process → finished_goods), an
 * outgoing inspection is required before the goods can be delivered.
 * Uses InspectionService::create() so measurement rows are properly seeded
 * from the product's active inspection spec.
 *
 * Idempotent: if an outgoing inspection already exists for an output batch,
 * skip that batch silently. A missing active spec is rethrown so the queue
 * records a failed handoff instead of creating an uncompletable inspection.
 *
 * Stateful failures are rethrown for queue retry; notification delivery is
 * best-effort. The listener runs after the WO transition is committed.
 */
class TriggerOutgoingQC implements ShouldQueue
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly SettingsService $settings,
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        try {
            // The queued event carries a serialized WO snapshot. Re-read and
            // lock the authoritative row before creating cross-module QC work.
            // Closed sources are inspected for missing evidence and escalated;
            // only completed sources permit automatic QC creation.
            $created = [];
            $reconciliationRequired = false;
            $wo = DB::transaction(function () use ($event, &$created, &$reconciliationRequired): ?WorkOrder {
                $lockedWo = WorkOrder::query()
                    ->with('creator')
                    ->lockForUpdate()
                    ->find($event->workOrder->id);
                if (! $lockedWo || ! in_array($lockedWo->status, [
                    WorkOrderStatus::Completed,
                    WorkOrderStatus::Closed,
                ], true)) {
                    return null;
                }

                // Every stock output, including internal production and repair,
                // requires its own outgoing QC before consumption or delivery.
                $productId = $lockedWo->product_id;
                if (! $productId) {
                    throw new BusinessRuleException(
                        "Completed WO {$lockedWo->wo_number} cannot enter outgoing QC without a product. Correct the work order and replay the completion event."
                    );
                }

                $creator = $lockedWo->creator;
                if (! $creator) {
                    throw new BusinessRuleException(
                        "Completed WO {$lockedWo->wo_number} has no active creator to attribute its outgoing QC inspection. Correct the work order before replaying the completion event."
                    );
                }

                $outputs = WorkOrderOutput::query()
                    ->where('work_order_id', $lockedWo->id)
                    ->where('good_count', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($outputs->isEmpty() && $lockedWo->rework_source_output_id && (int) $lockedWo->quantity_good === 0) {
                    return null;
                }
                if ($outputs->isEmpty()) {
                    throw new BusinessRuleException(
                        "Completed WO {$lockedWo->wo_number} has no good output batch available for outgoing QC. Correct production output and replay the completion event."
                    );
                }

                if ($lockedWo->status === WorkOrderStatus::Closed) {
                    $reconciliationRequired = $outputs->contains(fn ($output) => ! $output->inspections()
                        ->where('stage', 'outgoing')->where('status', '!=', 'cancelled')->exists());
                    if ($reconciliationRequired) {
                        Log::warning('Closed work order requires controlled outgoing QC reconciliation', ['wo_id' => $lockedWo->id]);
                    }

                    return null;
                }

                if ($lockedWo->usesFinishFirstQc()) {
                    $created = app(\App\Modules\Quality\Services\WorkOrderFinalInspectionService::class)
                        ->ensureForCompletedWorkOrder($lockedWo, $creator)->all();

                    return collect($created)->contains(static fn (Inspection $inspection): bool => $inspection->wasRecentlyCreated)
                        ? $lockedWo : null;
                }

                $createdAny = false;
                foreach ($outputs as $output) {
                    // createForOutput is the one creation path (shared with the
                    // qc:sweep-missing-outgoing repair): idempotent under the
                    // (stage, work_order_output_id) partial unique index, and
                    // surfaces the same stateful refusals (missing spec, missing
                    // revision) for queue retry. A pre-existing row comes back
                    // as a reuse; only a fresh insert advances the chain.
                    $inspection = $this->inspections->createForOutput($output, $lockedWo, $creator);
                    $created[] = $inspection;
                    if ($inspection->wasRecentlyCreated || $inspection->completed_at === null) {
                        $createdAny = true;
                    }
                }

                return $createdAny ? $lockedWo : null;
            });

            if ($reconciliationRequired) {
                app(ChainListenerRunService::class)->recordOutcome('manual_required', 'closed_work_order_missing_outgoing_qc', 'Closed work order has missing outgoing QC. Controlled reconciliation required; qc:sweep-missing-outgoing reports this blocker.');

                return;
            }

            if (! $wo) {
                app(ChainListenerRunService::class)->recordOutcome(
                    'skipped',
                    'outgoing_inspection_already_present_or_source_not_actionable',
                );

                return;
            }

            // Notify QC team.
            try {
                $roles = array_values(array_filter((array) $this->settings->get('quality.outgoing_qc.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
                $recipients = User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $user): bool => $user->hasPermission('quality.inspections.view'));

                $finalWorkOrderUrl = $wo->usesFinishFirstQc()
                    ? "/quality/work-orders/{$wo->hash_id}/final-inspection"
                    : null;
                app(NotificationService::class)->send($recipients, 'chain.outgoing_qc_required', [
                    'title' => 'Outgoing QC required',
                    'message' => "Outgoing QC required for WO {$wo->wo_number}.",
                    // One batch opens directly; several open the filtered inspection queue.
                    'link_to' => $finalWorkOrderUrl ?? (count($created) === 1
                        ? "/quality/inspections/{$created[0]->hash_id}"
                        : "/quality/inspections?entity_type=work_order&entity_id={$wo->hash_id}&stage=outgoing"),
                    'entity_type' => 'work_order',
                    'entity_id' => $wo->hash_id,
                    'wo_number' => $wo->wo_number,
                ]);
            } catch (\Throwable $e) {
                Log::debug('TriggerOutgoingQC notification failed', ['error' => $e->getMessage()]);
            }

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'outgoing_inspection_created',
                "Created outgoing QC inspection for WO {$wo->wo_number}.",
            );
        } catch (\Throwable $e) {
            Log::error('TriggerOutgoingQC failed', [
                'wo_id' => $event->workOrder->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
