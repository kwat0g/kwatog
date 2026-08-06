<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MoldService;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 — Task 55.
 *
 * Records production output for an in-progress WO. Idempotent at the
 * X-Idempotency-Key header — duplicate keys within 24h return the cached
 * payload instead of double-recording.
 *
 * On success:
 *  - Persists work_order_outputs + work_order_defects rows.
 *  - Updates WO totals (quantity_produced/good/rejected) + scrap_rate.
 *  - Increments mold shot count atomically (which may auto-flip mold to
 *    Maintenance via MoldService).
 *  - F-04: records a ProductionReceipt stock movement for good output at a
 *    Finished-Goods zone location, linking product part_number to item code.
 *  - Dispatches WorkOrderOutputRecorded event for live dashboard.
 */
class WorkOrderOutputService
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly MoldService $molds,
        private readonly StockMovementService $movements,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param array $data {
     *   good_count: int, reject_count: int, shift?: string, remarks?: string,
     *   defects?: array<int, array{defect_type_id: int, count: int}>
     * }
     */
    public function record(
        WorkOrder $wo,
        array $data,
        int $recordedBy,
        ?string $idempotencyKey = null,
    ): WorkOrderOutput {
        // Idempotency: replay the cached output for the same key.
        if ($idempotencyKey) {
            $cacheKey = "production:idem:{$idempotencyKey}";
            if (($outputId = Cache::get($cacheKey)) !== null) {
                $cached = WorkOrderOutput::with('defects.defectType')->find($outputId);
                if ($cached) return $cached;
            }
        }

        if ($wo->status !== WorkOrderStatus::InProgress) {
            throw new BusinessRuleException('Only in-progress work orders can record output.');
        }

        $good   = (int) ($data['good_count'] ?? 0);
        $reject = (int) ($data['reject_count'] ?? 0);
        $total  = $good + $reject;
        if ($total <= 0) {
            throw new BusinessRuleException('At least one of Good count or Reject count must be greater than zero.');
        }

        $defects = $data['defects'] ?? [];
        $defectSum = 0;
        $uniqueDefectTypes = [];
        
        foreach ($defects as $d) {
            $count = (int) ($d['count'] ?? 0);
            if ($count > 0) {
                $defectSum += $count;
                $uniqueDefectTypes[] = $d['defect_type_id'];
            }
        }
        
        if ($reject > 0 && $defectSum !== $reject) {
            throw new BusinessRuleException("The total sum of defects ({$defectSum}) must exactly equal the Reject count ({$reject}).");
        }
        
        if (count(array_unique($uniqueDefectTypes)) !== count($uniqueDefectTypes)) {
            throw new BusinessRuleException('Duplicate defect types are not allowed.');
        }

        $output = DB::transaction(function () use ($wo, $data, $recordedBy, $good, $reject, $total, $defects) {
            // Lock + reload the WO so concurrent recordings don't lose increments.
            $fresh = WorkOrder::lockForUpdate()->find($wo->id);

            if (($fresh->quantity_produced + $total) > $fresh->quantity_target) {
                throw new BusinessRuleException("Recording this output would exceed the work order target quantity ({$fresh->quantity_target}).");
            }

            // Generate batch code: {wo}-B{seq}.
            $existing = $fresh->outputs()->count();
            $batchCode = sprintf('%s-B%02d', $fresh->wo_number, $existing + 1);

            $output = WorkOrderOutput::create([
                'work_order_id' => $fresh->id,
                'recorded_by'   => $recordedBy,
                'recorded_at'   => Carbon::now(),
                'good_count'    => $good,
                'reject_count'  => $reject,
                'shift'         => $data['shift'] ?? null,
                'batch_code'    => $batchCode,
                'remarks'       => $data['remarks'] ?? null,
            ]);

            foreach ($defects as $d) {
                if ((int) ($d['count'] ?? 0) <= 0) continue;
                $output->defects()->create([
                    'defect_type_id' => (int) $d['defect_type_id'],
                    'count'          => (int) $d['count'],
                ]);
            }

            $fresh->update([
                'quantity_produced' => (int) $fresh->quantity_produced + $total,
                'quantity_good'     => (int) $fresh->quantity_good + $good,
                'quantity_rejected' => (int) $fresh->quantity_rejected + $reject,
                'scrap_rate'        => (int) $fresh->quantity_produced + $total > 0
                    ? round((((int) $fresh->quantity_rejected + $reject) /
                            ((int) $fresh->quantity_produced + $total)) * 100, 2)
                    : 0,
            ]);

            // Bump mold shot count (may auto-flip mold→Maintenance at threshold).
            // Sprint 6 audit §1.6: lock the mold row inside this transaction
            // so concurrent output recordings on the same mold cannot lose a
            // shot (lockForUpdate matches the WO row lock taken above).
            if ($fresh->mold_id) {
                $mold = Mold::lockForUpdate()->find($fresh->mold_id);
                if ($mold) {
                    $this->molds->incrementShots($mold, $total);
                }
            }

            // F-04 — record ProductionReceipt for good output at an FG-zone location.
            if ($good > 0) {
                try {
                    $product = $fresh->relationLoaded('product') ? $fresh->product : $fresh->product()->first();
                    if ($product && $product->part_number) {
                        $fgItem = Item::query()
                            ->where('code', $product->part_number)
                            ->where('item_type', ItemType::FinishedGood)
                            ->first();
                        if ($fgItem) {
                            $fgLocation = WarehouseLocation::query()
                                ->where('is_active', true)
                                ->whereHas('zone', fn ($q) => $q->where('zone_type', WarehouseZoneType::FinishedGoods->value))
                                ->orderBy('id')
                                ->first();
                            if ($fgLocation) {
                                $this->movements->move(new StockMovementInput(
                                    type: StockMovementType::ProductionReceipt,
                                    itemId: $fgItem->id,
                                    quantity: (string) $good,
                                    toLocationId: $fgLocation->id,
                                    referenceType: 'work_order',
                                    referenceId: $fresh->id,
                                    remarks: "WO {$fresh->wo_number} batch {$batchCode}",
                                    createdBy: $recordedBy,
                                    bypassCountFreeze: true,
                                ));
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('F-04: ProductionReceipt movement skipped', [
                        'wo_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $output->load('defects.defectType');
        });

        // Cache idempotency key (Redis or array driver — service container picks).
        if ($idempotencyKey) {
            Cache::put("production:idem:{$idempotencyKey}", $output->id, self::IDEMPOTENCY_TTL_SECONDS);
        }

        // Broadcast.
        WorkOrderOutputRecorded::dispatch($wo->fresh(), $output);

        return $output;
    }
}
