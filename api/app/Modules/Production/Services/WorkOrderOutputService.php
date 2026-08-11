<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MoldService;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Exceptions\ProductionReceiptHandoffException;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Auth\Models\User;
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
    private const RECEIPT_MANUAL_MESSAGE = 'Finished-goods inventory receipt could not be created automatically. Fix the item/location setup, then replay the handoff or create the receipt manually.';

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
                'production_receipt_handoff_status' => $good > 0
                    ? ProductionReceiptHandoffStatus::NotStarted->value
                    : ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_at' => Carbon::now(),
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
            $receiptNeedsRecovery = false;
            $receiptReasonCode = 'automatic_production_receipt_failed';
            if ($good > 0) {
                try {
                    $this->createProductionReceipt($output, $fresh, $recordedBy);
                } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $e) {
                    $receiptNeedsRecovery = true;
                    $receiptReasonCode = $e instanceof ProductionReceiptHandoffException
                        ? $e->reasonCode
                        : 'production_receipt_business_rule';
                    $this->markProductionReceiptManual($output->id);
                    Log::warning('F-04: ProductionReceipt handoff requires recovery', [
                        'wo_id' => $fresh->id,
                        'output_id' => $output->id,
                        'reason_code' => $receiptReasonCode,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $eventOutput = $output->load('defects.defectType');
            app(OutboxService::class)->record(
                new WorkOrderOutputRecorded($fresh->fresh(), $eventOutput),
            );

            if ($receiptNeedsRecovery) {
                app(OutboxService::class)->recordForChain(
                    new ProductionReceiptRequested($eventOutput, $receiptReasonCode),
                    $fresh,
                    'production',
                    'work_order',
                    'production_receipt',
                    'production-receipt-request:'.$output->id,
                );
            }

            return $eventOutput;
        });

        // Cache idempotency key (Redis or array driver — service container picks).
        if ($idempotencyKey) {
            Cache::put("production:idem:{$idempotencyKey}", $output->id, self::IDEMPOTENCY_TTL_SECONDS);
        }

        return $output;
    }

    /**
     * Retry only the output → stock receipt handoff. The output and WO facts
     * are already committed, so this method never re-records production or
     * increments WO totals a second time.
     */
    public function retryProductionReceipt(WorkOrderOutput $output, User $by): WorkOrderOutput
    {
        try {
            return DB::transaction(function () use ($output, $by): WorkOrderOutput {
                $lockedOutput = WorkOrderOutput::query()
                    ->whereKey($output->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedOutput) {
                    throw new ProductionReceiptHandoffException(
                        'The production output no longer exists.',
                        'work_order_output_missing',
                    );
                }

                if ((int) $lockedOutput->good_count <= 0) {
                    $lockedOutput->forceFill([
                        'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::NotRequired->value,
                        'production_receipt_handoff_message' => null,
                        'production_receipt_handoff_at' => $lockedOutput->production_receipt_handoff_at ?? now(),
                    ])->save();

                    return $lockedOutput->fresh();
                }

                $workOrder = WorkOrder::query()
                    ->whereKey($lockedOutput->work_order_id)
                    ->lockForUpdate()
                    ->first();
                if (! $workOrder) {
                    throw new ProductionReceiptHandoffException(
                        'The parent work order no longer exists.',
                        'work_order_missing',
                    );
                }

                return $this->createProductionReceipt($lockedOutput, $workOrder, (int) $by->id);
            });
        } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $e) {
            $this->markProductionReceiptManual($output->id);
            throw $e;
        }
    }

    /** Persist the safe operator-facing state for a failed receipt handoff. */
    public function markProductionReceiptManual(int $outputId): void
    {
        DB::transaction(function () use ($outputId): void {
            $output = WorkOrderOutput::query()->whereKey($outputId)->lockForUpdate()->first();
            if (! $output || (int) $output->good_count <= 0 || $output->production_receipt_movement_id !== null) {
                return;
            }

            $output->forceFill([
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::ManualRequired->value,
                'production_receipt_handoff_message' => self::RECEIPT_MANUAL_MESSAGE,
                'production_receipt_handoff_at' => now(),
            ])->save();
        });
    }

    /** @throws ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException */
    private function createProductionReceipt(
        WorkOrderOutput $output,
        WorkOrder $workOrder,
        int $createdBy,
    ): WorkOrderOutput {
        if ((int) $output->good_count <= 0) {
            $output->forceFill([
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_message' => null,
                'production_receipt_handoff_at' => $output->production_receipt_handoff_at ?? now(),
            ])->save();

            return $output->fresh();
        }

        if ($output->production_receipt_movement_id !== null) {
            $linked = StockMovement::query()->find($output->production_receipt_movement_id);
            if ($linked) {
                return $this->markProductionReceiptGenerated($output, (int) $linked->id);
            }
        }

        $existing = StockMovement::query()
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->orderBy('id')
            ->get();
        if ($existing->count() > 1) {
            throw new ProductionReceiptHandoffException(
                'More than one finished-goods receipt is linked to this production output.',
                'duplicate_production_receipts',
            );
        }
        if ($existing->count() === 1) {
            return $this->markProductionReceiptGenerated($output, (int) $existing->first()->id);
        }

        // Pre-existing installations used the parent WO as the reference. A
        // single-output/one-movement match is safe to adopt; multiple legacy
        // rows remain manual because guessing would double-count inventory.
        $legacy = StockMovement::query()
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order')
            ->where('reference_id', $workOrder->id)
            ->orderBy('id')
            ->get();
        $goodOutputCount = WorkOrderOutput::query()
            ->where('work_order_id', $workOrder->id)
            ->where('good_count', '>', 0)
            ->count();
        if ($legacy->count() > 0) {
            if ($legacy->count() === 1 && $goodOutputCount === 1) {
                return $this->markProductionReceiptGenerated($output, (int) $legacy->first()->id);
            }

            throw new ProductionReceiptHandoffException(
                'Legacy finished-goods receipts cannot be assigned to this output without reconciliation.',
                'legacy_production_receipt_ambiguous',
            );
        }

        $product = $workOrder->relationLoaded('product')
            ? $workOrder->product
            : $workOrder->product()->first();
        if (! $product || ! $product->part_number) {
            throw new ProductionReceiptHandoffException(
                'The work order product has no part number for finished-goods inventory.',
                'product_part_number_missing',
            );
        }

        $fgItem = Item::query()
            ->where('code', $product->part_number)
            ->where('item_type', ItemType::FinishedGood)
            ->first();
        if (! $fgItem) {
            throw new ProductionReceiptHandoffException(
                'No finished-goods inventory item matches the work order product.',
                'finished_good_item_missing',
            );
        }

        $fgLocation = WarehouseLocation::query()
            ->where('is_active', true)
            ->whereHas('zone', fn ($q) => $q->where('zone_type', WarehouseZoneType::FinishedGoods->value))
            ->orderBy('id')
            ->first();
        if (! $fgLocation) {
            throw new ProductionReceiptHandoffException(
                'No active finished-goods warehouse location is configured.',
                'finished_good_location_missing',
            );
        }

        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::ProductionReceipt,
            itemId: $fgItem->id,
            quantity: (string) $output->good_count,
            toLocationId: $fgLocation->id,
            referenceType: 'work_order_output',
            referenceId: $output->id,
            remarks: "WO {$workOrder->wo_number} batch {$output->batch_code}",
            createdBy: $createdBy,
            bypassCountFreeze: true,
        ));

        return $this->markProductionReceiptGenerated($output, (int) $movement->id);
    }

    private function markProductionReceiptGenerated(WorkOrderOutput $output, int $movementId): WorkOrderOutput
    {
        $output->forceFill([
            'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::Generated->value,
            'production_receipt_handoff_message' => null,
            'production_receipt_handoff_at' => now(),
            'production_receipt_movement_id' => $movementId,
        ])->save();

        return $output->fresh();
    }
}
