<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADV7 — In-process QC auto-trigger.
 *
 * When a Work Order transitions to in_progress, automatically create an
 * in-process inspection so QC can sample parts during the production run.
 *
 * Idempotent: skips if an in-process inspection already exists for this WO.
 * Stateful failures are rethrown for queue retry; notification delivery is
 * best-effort.
 */
class TriggerInProcessQC implements ShouldQueue
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly SettingsService $settings,
    ) {}

    public function handle(WorkOrderStatusChanged $event): void
    {
        try {
            if ($event->to !== WorkOrderStatus::InProgress->value) {
                app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_in_process_transition');
                return;
            }

            // The queued event carries a serialized WO snapshot. Re-read and
            // lock the authoritative row before creating cross-module QC work.
            // Paused/completed/closed are compatible successors of a start;
            // cancellation (or a stale pre-start state) is not.
            $wo = DB::transaction(function () use ($event): ?WorkOrder {
                $lockedWo = WorkOrder::query()
                    ->with('creator')
                    ->lockForUpdate()
                    ->find($event->workOrder->id);
                if (! $lockedWo || ! in_array($lockedWo->status, [
                    WorkOrderStatus::InProgress,
                    WorkOrderStatus::Paused,
                    WorkOrderStatus::Completed,
                    WorkOrderStatus::Closed,
                ], true)) {
                    return null;
                }

                // Idempotent — if an in-process inspection already exists, skip.
                $existing = Inspection::query()
                    ->where('stage', InspectionStage::InProcess->value)
                    ->where('entity_type', InspectionEntityType::WorkOrder->value)
                    ->where('entity_id', $lockedWo->id)
                    ->exists();
                if ($existing) return null;

                // quantity_target is required for new work orders. Legacy rows can
                // still be incomplete; never invent a production batch quantity or
                // silently leave a started WO without its in-process QC gate.
                $batchQty = (int) $lockedWo->quantity_target;
                $productId = $lockedWo->product_id;
                if (! $productId) {
                    throw new BusinessRuleException(
                        "WO {$lockedWo->wo_number} cannot start in-process QC without a product. Correct the work order and replay the status event."
                    );
                }
                if ($batchQty < 1) {
                    throw new BusinessRuleException(
                        "WO {$lockedWo->wo_number} cannot start in-process QC without a positive target quantity. Correct the work order and replay the status event."
                    );
                }

                $creator = $lockedWo->creator;
                if (! $creator) {
                    throw new BusinessRuleException(
                        "WO {$lockedWo->wo_number} has no active creator to attribute its in-process QC inspection. Correct the work order before replaying the event."
                    );
                }

                try {
                    // Use the InspectionService to create a properly scaffolded
                    // inspection while the WO lock is still held. The service
                    // loads the active spec and seeds measurement rows.
                    $this->inspections->create([
                        'stage'          => InspectionStage::InProcess->value,
                        'product_id'     => (int) $productId,
                        'batch_quantity' => $batchQty,
                        'entity_type'    => InspectionEntityType::WorkOrder->value,
                        'entity_id'      => $lockedWo->id,
                        'notes'         => "Auto-created from WO {$lockedWo->wo_number} start.",
                    ], $creator);
                } catch (QueryException $e) {
                    // A direct/manual insert may have won the unique race. The
                    // listener remains replay-safe without hiding other failures.
                    if (self::isUniqueViolation($e)) return null;
                    throw $e;
                }

                return $lockedWo;
            });

            if (! $wo) {
                app(ChainListenerRunService::class)->recordOutcome(
                    'skipped',
                    'in_process_inspection_already_present_or_source_not_actionable',
                );
                return;
            }

            // Notify QC team.
            try {
                $roles = array_values(array_filter((array) $this->settings->get('quality.in_process_qc.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
                $recipients = User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                    ->where('is_active', true)
                    ->get();

                app(NotificationService::class)->send($recipients, 'chain.in_process_qc_required', [
                    'title'       => 'In-process QC required',
                    'message'     => "In-process QC required for WO {$wo->wo_number}.",
                    'link_to'     => "/production/work-orders/{$wo->hash_id}",
                    'entity_type' => 'work_order',
                    'entity_id'   => $wo->hash_id,
                    'wo_number'   => $wo->wo_number,
                ]);
            } catch (\Throwable $e) {
                Log::debug('TriggerInProcessQC notification failed', ['error' => $e->getMessage()]);
            }

            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'in_process_inspection_created',
                "Created in-process QC inspection for WO {$wo->wo_number}.",
            );
        } catch (\Throwable $e) {
            Log::error('TriggerInProcessQC failed', [
                'wo_id' => $event->workOrder->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        return str_starts_with($code, '23')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
