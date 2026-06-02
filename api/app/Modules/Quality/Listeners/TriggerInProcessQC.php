<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\DocumentSequenceService;
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

            $batchQty = (int) ($wo->quantity_target ?: 100);
            $productId = $wo->product_id;
            if (! $productId) return;

            // Use the InspectionService to create a properly scaffolded inspection.
            // The service loads the active spec and seeds measurement rows.
            $this->inspections->create([
                'stage'         => InspectionStage::InProcess->value,
                'product_id'    => (int) $productId,
                'batch_quantity' => max(1, $batchQty),
                'entity_type'   => InspectionEntityType::WorkOrder->value,
                'entity_id'     => $wo->id,
                'notes'         => "Auto-created from WO {$wo->wo_number} start.",
            ], $wo->creator ?? User::query()->first());

            // Notify QC team.
            try {
                User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', ['qc_inspector', 'plant_manager']))
                    ->where('is_active', true)
                    ->get()
                    ->each(function (User $user) use ($wo) {
                        $user->notifications()->create([
                            'id'              => (string) \Illuminate\Support\Str::uuid(),
                            'type'            => 'chain.in_process_qc_required',
                            'notifiable_type' => $user::class,
                            'notifiable_id'   => $user->id,
                            'data'            => [
                                'wo_id'     => $wo->hash_id,
                                'wo_number' => $wo->wo_number,
                                'message'   => "In-process QC required for WO {$wo->wo_number}.",
                                'link'      => "/production/work-orders/{$wo->hash_id}",
                            ],
                            'read_at'         => null,
                        ]);
                    });
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
