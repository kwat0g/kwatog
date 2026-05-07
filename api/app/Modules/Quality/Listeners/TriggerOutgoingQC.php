<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\AqlSampleSizeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C1. Outgoing QC auto-trigger.
 *
 * After a Work Order completes (Chain 1, in_process → finished_goods), an
 * outgoing inspection is required before the goods can be delivered.
 * This listener creates the inspection in `draft` status; the QC
 * inspector picks it up from their queue and records measurements.
 *
 * Idempotent: if an outgoing inspection already exists for this WO,
 * skip silently. The lookup uses the entity_type/entity_id polymorphic
 * link on the inspections table.
 *
 * Best-effort: any throw is swallowed and logged. Listener failure must
 * never roll back the WO completion that triggered it.
 */
class TriggerOutgoingQC implements ShouldQueue
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function handle(WorkOrderCompleted $event): void
    {
        try {
            $wo = $event->workOrder;
            // Only trigger when the WO is tied to a customer order. Internal
            // rework / replacement WOs already inherit the parent's flow.
            if (! $wo->sales_order_id) return;

            // Idempotent — if an outgoing inspection for this WO already
            // exists (any status), don't create another one.
            $existing = Inspection::query()
                ->where('stage', InspectionStage::Outgoing->value)
                ->where('entity_type', 'work_order')
                ->where('entity_id', $wo->id)
                ->exists();
            if ($existing) return;

            DB::transaction(function () use ($wo) {
                $batchQty = (int) ($wo->quantity_good ?: $wo->quantity_produced ?: 0);
                $aql      = AqlSampleSizeService::forBatch(max(1, $batchQty));

                Inspection::create([
                    'inspection_number'  => $this->sequences->generate('inspection'),
                    'stage'              => InspectionStage::Outgoing->value,
                    'status'             => InspectionStatus::Draft->value,
                    'product_id'         => $wo->product_id,
                    'entity_type'        => 'work_order',
                    'entity_id'          => $wo->id,
                    'batch_quantity'     => max(1, $batchQty),
                    'sample_size'        => (int) $aql['sample_size'],
                    'aql_code'           => (string) $aql['code'],
                    'accept_count'       => (int) $aql['accept'],
                    'reject_count'       => (int) $aql['reject'],
                    'defect_count'       => 0,
                ]);
            });

            // Notify QC team. Notification failure is non-fatal — the
            // inspection still exists for them to find.
            try {
                User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('slug', ['qc_inspector', 'plant_manager']))
                    ->where('is_active', true)
                    ->get()
                    ->each(function (User $user) use ($wo) {
                        $user->notifications()->create([
                            'id'              => (string) Str::uuid(),
                            'type'            => 'chain.outgoing_qc_required',
                            'notifiable_type' => $user::class,
                            'notifiable_id'   => $user->id,
                            'data'            => [
                                'wo_id'     => $wo->hash_id,
                                'wo_number' => $wo->wo_number,
                                'message'   => "Outgoing QC required for WO {$wo->wo_number}.",
                                'link'      => "/production/work-orders/{$wo->hash_id}",
                            ],
                            'read_at'         => null,
                        ]);
                    });
            } catch (\Throwable $e) {
                Log::debug('TriggerOutgoingQC notification failed', ['error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::warning('TriggerOutgoingQC failed', [
                'wo_id' => $event->workOrder->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
