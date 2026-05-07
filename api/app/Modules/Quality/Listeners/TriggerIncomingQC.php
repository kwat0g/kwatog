<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\AqlSampleSizeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C2. Incoming QC auto-trigger.
 *
 * GRN created → an incoming inspection must precede stock acceptance
 * (per IATF — resin certs, moisture, dimensional checks). This listener
 * creates one pending inspection per GRN line item that has a product
 * link. The QC inspector picks it up; on pass the GRN service is then
 * cleared to accept (assertQcGate already enforces this).
 *
 * Idempotent: skips if any inspection already exists pointing at the GRN.
 * Best-effort.
 */
class TriggerIncomingQC implements ShouldQueue
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function handle(GoodsReceiptNoteCreated $event): void
    {
        try {
            $grn = $event->grn->loadMissing('items');

            $exists = Inspection::query()
                ->where('stage', InspectionStage::Incoming->value)
                ->where('entity_type', 'goods_receipt_note')
                ->where('entity_id', $grn->id)
                ->exists();
            if ($exists) return;

            DB::transaction(function () use ($grn) {
                // Take the largest line as the representative inspection.
                $line = $grn->items->sortByDesc(fn ($i) => (float) $i->quantity_received)->first();
                if (! $line) return;

                $batchQty = max(1, (int) (float) $line->quantity_received);
                $aql      = AqlSampleSizeService::forBatch($batchQty);

                $inspection = Inspection::create([
                    'inspection_number' => $this->sequences->generate('inspection'),
                    'stage'             => InspectionStage::Incoming->value,
                    'status'            => InspectionStatus::Draft->value,
                    'product_id'        => $line->item_id, // resin / RM SKU
                    'entity_type'       => 'goods_receipt_note',
                    'entity_id'         => $grn->id,
                    'batch_quantity'    => $batchQty,
                    'sample_size'       => (int) $aql['sample_size'],
                    'aql_code'          => (string) $aql['code'],
                    'accept_count'      => (int) $aql['accept'],
                    'reject_count'      => (int) $aql['reject'],
                    'defect_count'      => 0,
                ]);

                // Link GRN to the inspection so assertQcGate can enforce it.
                $grn->forceFill(['qc_inspection_id' => $inspection->id])->save();
            });

            try {
                User::query()
                    ->whereHas('role', fn ($q) => $q->where('slug', 'qc_inspector'))
                    ->where('is_active', true)
                    ->get()
                    ->each(function (User $user) use ($grn) {
                        $user->notifications()->create([
                            'id'              => (string) Str::uuid(),
                            'type'            => 'chain.incoming_qc_required',
                            'notifiable_type' => $user::class,
                            'notifiable_id'   => $user->id,
                            'data'            => [
                                'grn_id'     => $grn->hash_id,
                                'grn_number' => $grn->grn_number,
                                'message'    => "Incoming QC required for GRN {$grn->grn_number}.",
                                'link'       => "/inventory/grns/{$grn->hash_id}",
                            ],
                            'read_at'         => null,
                        ]);
                    });
            } catch (\Throwable $e) {
                Log::debug('TriggerIncomingQC notification failed', ['error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::warning('TriggerIncomingQC failed', [
                'grn_id' => $event->grn->id ?? null,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
