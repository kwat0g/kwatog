<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C1 / ADV7. Outgoing QC auto-trigger.
 *
 * After a Work Order completes (Chain 1, in_process → finished_goods), an
 * outgoing inspection is required before the goods can be delivered.
 * Uses InspectionService::create() so measurement rows are properly seeded
 * from the product's active inspection spec.
 *
 * Idempotent: if an outgoing inspection already exists for this WO,
 * skip silently. Falls back to a bare Inspection record when no active
 * spec exists for the product.
 *
 * Best-effort: any throw is swallowed and logged. Listener failure must
 * never roll back the WO completion that triggered it.
 */
class TriggerOutgoingQC implements ShouldQueue
{
    public function __construct(
        private readonly InspectionService $inspections,
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        try {
            $wo = $event->workOrder;
            // Only trigger when the WO is tied to a customer order. Internal
            // rework / replacement WOs already inherit the parent's flow.
            if (! $wo->sales_order_id) return;

            $productId = $wo->product_id;
            $batchQty  = max(1, (int) ($wo->quantity_good ?: $wo->quantity_produced ?: 0));
            if (! $productId) return;

            // Guard columns covered by the DB unique index
            // (inspections_stage_entity_unique). Using an existence check first
            // is still a fast-path optimisation for the common case (WO
            // completed exactly once). The real idempotency guarantee comes from
            // the DB index + the QueryException catch below — if two workers
            // both pass this check simultaneously, only one INSERT wins; the
            // loser catches the unique-violation and exits silently.
            $guardColumns = [
                'stage'       => InspectionStage::Outgoing->value,
                'entity_type' => InspectionEntityType::WorkOrder->value,
                'entity_id'   => $wo->id,
            ];

            if (Inspection::query()->where($guardColumns)->exists()) return;

            try {
                // Use InspectionService::create() to get full measurement scaffold.
                $this->inspections->create([
                    'stage'          => InspectionStage::Outgoing->value,
                    'product_id'     => (int) $productId,
                    'batch_quantity' => $batchQty,
                    'entity_type'    => InspectionEntityType::WorkOrder->value,
                    'entity_id'      => $wo->id,
                ], $wo->creator ?? User::query()->first());
            } catch (\Illuminate\Database\QueryException $e) {
                // Unique constraint violation — a concurrent worker already
                // inserted the outgoing inspection. Silently no-op.
                if ($this->isUniqueViolation($e)) {
                    Log::debug('TriggerOutgoingQC: duplicate suppressed by DB unique index', [
                        'wo_id' => $wo->id,
                    ]);
                    return;
                }
                throw $e;
            } catch (\Throwable $e) {
                // Fallback: no active inspection spec for this product.
                // Use firstOrCreate on the guard columns so the fallback path
                // is also race-safe against the DB unique index.
                Log::debug('TriggerOutgoingQC fallback — no active spec', [
                    'product_id' => $productId,
                    'error'      => $e->getMessage(),
                ]);
                $aql = \App\Modules\Quality\Services\AqlSampleSizeService::forBatch($batchQty);
                try {
                    Inspection::firstOrCreate(
                        $guardColumns,
                        [
                            'inspection_number' => app(\App\Common\Services\DocumentSequenceService::class)->generate('inspection'),
                            'status'            => \App\Modules\Quality\Enums\InspectionStatus::Draft->value,
                            'product_id'        => $productId,
                            'batch_quantity'    => $batchQty,
                            'sample_size'       => (int) $aql['sample_size'],
                            'aql_code'          => (string) $aql['code'],
                            'accept_count'      => (int) $aql['accept'],
                            'reject_count'      => (int) $aql['reject'],
                            'defect_count'      => 0,
                        ]
                    );
                } catch (\Illuminate\Database\QueryException $qe) {
                    if ($this->isUniqueViolation($qe)) {
                        Log::debug('TriggerOutgoingQC: fallback duplicate suppressed', ['wo_id' => $wo->id]);
                        return;
                    }
                    throw $qe;
                }
            }

            // Notify QC team.
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

    /**
     * Returns true when a QueryException is caused by a unique-constraint violation.
     * SQLSTATE 23000 / 23505 covers PostgreSQL; SQLite surfaces SQLSTATE HY000 but
     * embeds "UNIQUE constraint failed" in the message.
     */
    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $code = (string) $e->getCode();
        return str_starts_with($code, '23')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
