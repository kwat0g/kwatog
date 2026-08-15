<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\AqlSampleSizeService;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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
 * skip that batch silently. Falls back to a bare Inspection record when no
 * active spec exists for the product.
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
            // Closed is a compatible successor of completed; cancellation is
            // not, because it invalidates the shipment-quality obligation.
            $wo = DB::transaction(function () use ($event): ?WorkOrder {
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

                // REC-07 — Trigger outgoing QC when the WO is tied to a customer
                // order OR when it is a rework/replacement WO born from an NCR
                // (parent_ncr_id set). The latter case was previously skipped on
                // the false assumption that rework WOs "inherit the parent's
                // flow" — they do not, so reworked parts shipped without any
                // re-measurement (IATF §8.7.1.4 violation). A rework WO carries
                // its own id, so the (stage, entity_type, entity_id) unique index
                // still yields a distinct inspection; no SO fulfilment state is
                // touched, only re-verification is enforced.
                if (! $lockedWo->sales_order_id && ! $lockedWo->parent_ncr_id) {
                    return null;
                }

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
                if ($outputs->isEmpty()) {
                    throw new BusinessRuleException(
                        "Completed WO {$lockedWo->wo_number} has no good output batch available for outgoing QC. Correct production output and replay the completion event."
                    );
                }

                $createdAny = false;
                foreach ($outputs as $output) {
                    $batchQty = (int) $output->good_count;
                    $guardColumns = [
                        'stage' => InspectionStage::Outgoing->value,
                        'entity_type' => InspectionEntityType::WorkOrder->value,
                        'entity_id' => $lockedWo->id,
                        'work_order_output_id' => $output->id,
                    ];

                    if (Inspection::query()->where($guardColumns)->exists()) {
                        continue;
                    }

                    try {
                        $this->inspections->create([
                            'stage' => InspectionStage::Outgoing->value,
                            'product_id' => (int) $productId,
                            'batch_quantity' => $batchQty,
                            'entity_type' => InspectionEntityType::WorkOrder->value,
                            'entity_id' => $lockedWo->id,
                            'work_order_output_id' => $output->id,
                        ], $creator);
                        $createdAny = true;
                    } catch (QueryException $e) {
                        if ($this->isUniqueViolation($e) && Inspection::query()->where($guardColumns)->exists()) {
                            Log::debug('TriggerOutgoingQC: duplicate output inspection suppressed', [
                                'wo_id' => $lockedWo->id,
                                'output_id' => $output->id,
                            ]);

                            continue;
                        }
                        throw $e;
                    } catch (BusinessRuleException $e) {
                        Log::debug('TriggerOutgoingQC fallback — no active spec', [
                            'product_id' => $productId,
                            'output_id' => $output->id,
                            'error' => $e->getMessage(),
                        ]);
                        $aql = AqlSampleSizeService::forBatch($batchQty);
                        try {
                            DB::transaction(function () use ($guardColumns, $productId, $batchQty, $aql, $output, $lockedWo): void {
                                Inspection::firstOrCreate(
                                    $guardColumns,
                                    [
                                        'inspection_number' => app(DocumentSequenceService::class)->generate('inspection'),
                                        'status' => InspectionStatus::Draft->value,
                                        'product_id' => $productId,
                                        'entity_type' => InspectionEntityType::WorkOrder->value,
                                        'entity_id' => $lockedWo->id,
                                        'work_order_output_id' => $output->id,
                                        'batch_quantity' => $batchQty,
                                        'accepted_quantity' => 0,
                                        'sample_size' => (int) $aql['sample_size'],
                                        'aql_code' => (string) $aql['code'],
                                        'accept_count' => (int) $aql['accept'],
                                        'reject_count' => (int) $aql['reject'],
                                        'defect_count' => 0,
                                    ]
                                );
                            });
                            $createdAny = true;
                        } catch (QueryException $qe) {
                            if ($this->isUniqueViolation($qe) && Inspection::query()->where($guardColumns)->exists()) {
                                continue;
                            }
                            throw $qe;
                        }
                    }
                }

                return $createdAny ? $lockedWo : null;
            });

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
                    ->get();

                app(NotificationService::class)->send($recipients, 'chain.outgoing_qc_required', [
                    'title' => 'Outgoing QC required',
                    'message' => "Outgoing QC required for WO {$wo->wo_number}.",
                    'link_to' => "/production/work-orders/{$wo->hash_id}",
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

    /**
     * Returns true when a QueryException is caused by a unique-constraint violation.
     * SQLSTATE 23000 / 23505 covers PostgreSQL; SQLite surfaces SQLSTATE HY000 but
     * embeds "UNIQUE constraint failed" in the message.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        return str_starts_with($code, '23')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
