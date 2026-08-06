<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * ADV7 — In-process QC auto-trigger.
 *
 * When a Work Order transitions to in_progress, automatically create an
 * in-process inspection so QC can sample parts during the production run.
 *
 * Idempotent: skips if an in-process inspection already exists for this WO.
 * Best-effort: failures are logged but never throw.
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
            if ($event->to !== 'in_progress') return;
            $wo = $event->workOrder;

            // Idempotent — if an in-process inspection already exists, skip.
            $existing = Inspection::query()
                ->where('stage', InspectionStage::InProcess->value)
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $wo->id)
                ->exists();
            if ($existing) return;

            // quantity_target is required for new work orders. Legacy rows can
            // still be incomplete; never invent a production batch quantity.
            $batchQty = (int) $wo->quantity_target;
            $productId = $wo->product_id;
            if (! $productId || $batchQty < 1) return;

            // Use the InspectionService to create a properly scaffolded inspection.
            // The service loads the active spec and seeds measurement rows.
            $this->inspections->create([
                'stage'         => InspectionStage::InProcess->value,
                'product_id'    => (int) $productId,
                'batch_quantity' => $batchQty,
                'entity_type'   => InspectionEntityType::WorkOrder->value,
                'entity_id'     => $wo->id,
                'notes'         => "Auto-created from WO {$wo->wo_number} start.",
            ], $wo->creator ?? User::query()->first());

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
        } catch (\Throwable $e) {
            Log::warning('TriggerInProcessQC failed', [
                'wo_id' => $event->workOrder->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
