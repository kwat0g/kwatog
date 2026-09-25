<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\MaterialReturnConflictException;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderMaterialUsageService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Returns unused production material against the immutable issue movement.
 * Every return is a normal receipt in the stock ledger, keyed to its source
 * issue so lot, location, cost, GL reversal and retry history stay connected.
 */
class MaterialReturnService
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly WorkOrderMaterialUsageService $materialUsage,
    ) {}

    /** Operator view for one original issue movement. */
    public function options(StockMovement $movement): array
    {
        try {
            $source = StockMovement::query()->findOrFail($movement->id);
            $context = $this->readContext($source);
            $this->assertOwnedMaterialIssue($source, $context['work_order'], $context['slip'], $context['line']);
            $summary = $this->summary($source, $context['work_order']);

            return array_merge(['eligible' => bccomp($summary['returnable_quantity'], '0', 3) > 0], $summary, [
                'message' => $summary['returnable_quantity'] === '0.000'
                    ? $this->blockedMessage($context['work_order'], $summary)
                    : null,
            ]);
        } catch (BusinessRuleException $e) {
            return [
                'eligible' => false,
                'source_movement_id' => $movement->hash_id,
                'message' => $e->getMessage(),
                'issued_quantity' => (string) $movement->quantity,
                'returned_quantity' => '0.000',
                'returnable_quantity' => '0.000',
                'returned_cost' => '0.00',
            ];
        }
    }

    /**
     * @param array{quantity_returned:string,expected_returned_quantity:string,reason:string,idempotency_key?:?string} $data
     */
    public function returnUnused(StockMovement $movement, array $data, ?User $by): StockMovement
    {
        $quantity = bcadd((string) ($data['quantity_returned'] ?? '0'), '0', 3);
        $expectedReturned = bcadd((string) ($data['expected_returned_quantity'] ?? '0'), '0', 3);
        $reason = trim((string) ($data['reason'] ?? ''));
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new BusinessRuleException('Return quantity must be greater than zero.');
        }
        if ($reason === '' || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new BusinessRuleException('Please provide a reason of 10 to 500 characters for the unused-material return.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/D', $idempotencyKey)) {
            throw new BusinessRuleException('A valid Idempotency-Key is required for this material return.');
        }

        $fingerprint = hash('sha256', json_encode([
            'operation' => 'inventory.material_return',
            'actor_id' => $by?->id,
            'source_movement_id' => (int) $movement->id,
            'quantity_returned' => $quantity,
            'expected_returned_quantity' => $expectedReturned,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $existing = $this->idempotentMovement($idempotencyKey, $fingerprint, (int) $movement->id);
        if ($existing !== null) {
            return $existing;
        }

        // Read the immutable source owner to establish the mandated lock order.
        $sourceSnapshot = StockMovement::query()->findOrFail($movement->id);
        $preliminary = $this->readContext($sourceSnapshot);
        $workOrderId = $preliminary['work_order']?->id;
        $slipId = $preliminary['slip']?->id;

        try {
            return DB::transaction(function () use (
                $sourceSnapshot,
                $workOrderId,
                $slipId,
                $quantity,
                $expectedReturned,
                $reason,
                $idempotencyKey,
                $fingerprint,
                $by,
            ): StockMovement {
                // All issue mutations use WO → MIS/issue source → stock. Keep
                // that order for returns and cancellation reversals as well.
                $workOrder = $workOrderId !== null
                    ? WorkOrder::query()->lockForUpdate()->findOrFail($workOrderId)
                    : null;
                $slip = $slipId !== null
                    ? MaterialIssueSlip::query()->lockForUpdate()->findOrFail($slipId)
                    : null;
                $line = $slip !== null
                    ? MaterialIssueSlipItem::query()
                        ->where('material_issue_slip_id', $slip->id)
                        ->where('stock_movement_id', $sourceSnapshot->id)
                        ->lockForUpdate()
                        ->first()
                    : null;
                $source = StockMovement::query()->lockForUpdate()->findOrFail($sourceSnapshot->id);

                $existing = $this->idempotentMovement($idempotencyKey, $fingerprint, (int) $source->id);
                if ($existing !== null) {
                    return $existing;
                }

                $context = $this->assertOwnedMaterialIssue($source, $workOrder, $slip, $line);
                $summary = $this->summary($source, $context['work_order']);
                $alreadyReturned = [
                    'quantity' => (string) $summary['returned_quantity'],
                    'cost' => (string) $summary['returned_cost'],
                ];
                if (bccomp($summary['returned_quantity'], $expectedReturned, 3) !== 0) {
                    throw new MaterialReturnConflictException(
                        "The issue movement already has {$summary['returned_quantity']} returned. Reload the movement and review the updated returnable quantity before trying again."
                    );
                }
                if (bccomp($quantity, $summary['returnable_quantity'], 3) > 0) {
                    throw new BusinessRuleException($this->blockedMessage($context['work_order'], $summary));
                }

                $return = $this->movements->move(new StockMovementInput(
                    type: StockMovementType::MaterialReturn,
                    itemId: (int) $source->item_id,
                    quantity: $quantity,
                    toLocationId: (int) $source->from_location_id,
                    unitCost: (string) $source->unit_cost,
                    referenceType: 'stock_movement',
                    referenceId: (int) $source->id,
                    remarks: "Unused material returned from issue movement {$source->hash_id}: {$reason}",
                    createdBy: $by?->id,
                    lotNumber: $source->lot_number,
                    expiryDate: $source->expiry_date?->toDateString(),
                    idempotencyKey: $idempotencyKey,
                    idempotencyFingerprint: $fingerprint,
                    totalCostOverride: $this->returnCostForQuantity($source, $alreadyReturned, $quantity),
                ));

                if ($context['work_order'] !== null) {
                    $this->materialUsage->refreshLotReferences($context['work_order']);
                }

                return $return;
            }, 3);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505' || ! str_contains($e->getMessage(), 'stock_movements_idempotency_key_unique')) {
                throw $e;
            }

            return $this->idempotentMovement($idempotencyKey, $fingerprint, (int) $sourceSnapshot->id)
                ?? throw new MaterialReturnConflictException('The material-return idempotency key was used by another request. Reload the movement and retry with a new key.');
        }
    }

    /** @return array{work_order:?WorkOrder,slip:?MaterialIssueSlip,line:?MaterialIssueSlipItem} */
    private function readContext(StockMovement $source): array
    {
        if ($source->movement_type !== StockMovementType::MaterialIssue
            || $source->from_location_id === null
            || $source->to_location_id !== null
            || $source->reference_id === null) {
            throw new BusinessRuleException('Only a real material-issue movement with a source location can be returned.');
        }

        if ($source->reference_type === 'work_order') {
            $workOrder = WorkOrder::query()->find($source->reference_id);
            if (! $workOrder || ! $workOrder->materials()->where('item_id', $source->item_id)->exists()) {
                throw new BusinessRuleException('This material issue is not linked to a work order material plan and cannot be returned through production. Ask Production to reconcile the issue history.');
            }

            return ['work_order' => $workOrder, 'slip' => null, 'line' => null];
        }

        if ($source->reference_type !== 'material_issue_slip') {
            throw new BusinessRuleException('This movement is not a linked work-order or material-issue slip source.');
        }

        $slip = MaterialIssueSlip::query()->find($source->reference_id);
        if (! $slip || $slip->status !== MaterialIssueStatus::Issued) {
            throw new BusinessRuleException('The source material-issue slip is missing or no longer issued.');
        }
        $line = MaterialIssueSlipItem::query()
            ->where('material_issue_slip_id', $slip->id)
            ->where('stock_movement_id', $source->id)
            ->first();
        if (! $line) {
            throw new BusinessRuleException('The original material-issue movement is not uniquely linked to this slip line. Ask Warehouse to reconcile the issue history before returning stock.');
        }
        $workOrder = $slip->work_order_id !== null
            ? WorkOrder::query()->find($slip->work_order_id)
            : null;

        return ['work_order' => $workOrder, 'slip' => $slip, 'line' => $line];
    }

    /** @return array{work_order:?WorkOrder} */
    private function assertOwnedMaterialIssue(
        StockMovement $source,
        ?WorkOrder $workOrder,
        ?MaterialIssueSlip $slip,
        ?MaterialIssueSlipItem $line,
    ): array {
        if ($source->movement_type !== StockMovementType::MaterialIssue
            || $source->from_location_id === null
            || $source->to_location_id !== null
            || $source->reference_id === null) {
            throw new BusinessRuleException('Only a real material-issue movement with a source location can be returned.');
        }

        if ($source->reference_type === 'work_order') {
            if ($workOrder === null
                || (int) $source->reference_id !== (int) $workOrder->id
                || ! $workOrder->materials()->where('item_id', $source->item_id)->exists()) {
                throw new BusinessRuleException('This issue movement no longer matches its work-order material plan. Reload and ask Production to reconcile it.');
            }

            return ['work_order' => $workOrder];
        }

        if ($source->reference_type !== 'material_issue_slip'
            || $slip === null
            || (int) $source->reference_id !== (int) $slip->id
            || $slip->status !== MaterialIssueStatus::Issued) {
            throw new BusinessRuleException('The source material-issue slip is missing or no longer issued.');
        }
        if ($slip->work_order_id !== null
            && ($workOrder === null || (int) $slip->work_order_id !== (int) $workOrder->id)) {
            throw new BusinessRuleException('The source slip no longer matches its work order. Reload and ask Warehouse to reconcile the issue history.');
        }
        if ($line === null
            || (int) $line->item_id !== (int) $source->item_id
            || (int) $line->location_id !== (int) $source->from_location_id
            || bccomp((string) $line->quantity_issued, (string) $source->quantity, 3) !== 0
            || bccomp((string) $line->unit_cost, (string) $source->unit_cost, 4) !== 0
            || bccomp((string) $line->total_cost, (string) $source->total_cost, 2) !== 0
            || (string) ($line->lot_number ?? '') !== (string) ($source->lot_number ?? '')) {
            throw new BusinessRuleException('The slip line does not exactly match its source movement. Ask Warehouse to reconcile the issue history before returning stock.');
        }

        return ['work_order' => $workOrder];
    }

    /** @param array{work_order:?WorkOrder,slip:?MaterialIssueSlip,line:?MaterialIssueSlipItem} $context */
    private function summary(StockMovement $source, ?WorkOrder $workOrder): array
    {
        $returns = $this->returnedTotals((int) $source->id);
        $remainingAtSource = bcsub((string) $source->quantity, $returns['quantity'], 3);
        $floor = '0.000';
        $netIssued = (string) $source->quantity;
        $grossUnits = '0.0000';
        $group = null;

        if ($workOrder !== null) {
            $grossUnits = $this->materialUsage->grossProductionUnits($workOrder);
            $group = collect($this->materialUsage->groups($workOrder, includeReservations: false))
                ->first(static fn (array $row): bool => (int) $row['item_id'] === (int) $source->item_id);
            $netIssued = (string) ($group['actual_quantity_issued'] ?? '0.000');
            $floor = $this->materialUsage->returnFloorForItem($workOrder, (int) $source->item_id, $grossUnits);
        }

        $aboveFloor = bcsub($netIssued, $floor, 3);
        if (bccomp($aboveFloor, '0', 3) < 0) {
            $aboveFloor = '0.000';
        }
        $returnable = bccomp($remainingAtSource, $aboveFloor, 3) <= 0 ? $remainingAtSource : $aboveFloor;
        $hasSavedItemPlan = $group !== null && bccomp((string) $group['required_quantity'], '0', 3) > 0;
        $basis = $workOrder === null
            ? 'unlinked_issue'
            : (bccomp($grossUnits, '0', 4) <= 0
                ? 'preproduction'
                : ($workOrder->material_plan_source === 'bom' && $hasSavedItemPlan
                    ? 'saved_bom_norm'
                    : ($hasSavedItemPlan ? 'fixed_saved_plan' : 'no_recipe')));

        return [
            'source_movement_id' => $source->hash_id,
            'issued_quantity' => (string) $source->quantity,
            'returned_quantity' => $returns['quantity'],
            'returned_cost' => $returns['cost'],
            'returnable_quantity' => $returnable,
            'unit_cost' => (string) $source->unit_cost,
            'unit_of_measure' => $source->item()->value('unit_of_measure'),
            'total_returned_at_original_cost' => $returns['cost'],
            'location' => $source->fromLocation()->first()?->code,
            'lot_number' => $source->lot_number,
            'gross_production_units' => $grossUnits,
            'consumption_floor' => $floor,
            'consumption_basis' => $basis,
        ];
    }

    private function blockedMessage(?WorkOrder $workOrder, array $summary): string
    {
        if ($workOrder === null) {
            return 'The requested return exceeds the unreturned quantity on this original material-issue movement.';
        }

        if ($workOrder !== null
            && bccomp((string) $summary['gross_production_units'], '0', 4) > 0
            && in_array($summary['consumption_basis'], ['fixed_saved_plan', 'no_recipe'], true)) {
            return 'No recipe: usage cannot be calculated for this manual/no-BOM plan. Contact Production to reconcile before returning more material.';
        }

        return 'The requested return would put this work order below the planned material usage floor for recorded production.';
    }

    /** @return array{quantity:string,cost:string} */
    private function returnedTotals(int $sourceMovementId): array
    {
        $totals = StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->where('reference_type', 'stock_movement')
            ->where('reference_id', $sourceMovementId)
            ->selectRaw('COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(total_cost), 0) AS cost')
            ->first();

        return [
            'quantity' => bcadd((string) ($totals->quantity ?? '0'), '0', 3),
            'cost' => bcadd((string) ($totals->cost ?? '0'), '0', 2),
        ];
    }

    /**
     * Allocate the immutable issue value cumulatively so split returns cannot
     * create or lose pennies through per-row rounding. The final quantity
     * return always receives the exact residual source value for the reversal.
     *
     * @param array{quantity:string,cost:string} $alreadyReturned
     */
    private function returnCostForQuantity(StockMovement $source, array $alreadyReturned, string $quantity): string
    {
        $nextReturnedQuantity = bcadd($alreadyReturned['quantity'], $quantity, 3);
        if (bccomp($nextReturnedQuantity, (string) $source->quantity, 3) >= 0) {
            return Money::sub((string) $source->total_cost, $alreadyReturned['cost']);
        }

        $ratio = bcdiv($nextReturnedQuantity, (string) $source->quantity, 12);
        $targetReturnedCost = Money::round2(bcmul((string) $source->total_cost, $ratio, 8));
        $incrementalCost = Money::sub($targetReturnedCost, $alreadyReturned['cost']);
        $remainingCost = Money::sub((string) $source->total_cost, $alreadyReturned['cost']);

        if (bccomp($incrementalCost, '0', 2) < 0) {
            return '0.00';
        }

        return bccomp($incrementalCost, $remainingCost, 2) > 0 ? $remainingCost : $incrementalCost;
    }

    private function idempotentMovement(string $key, string $fingerprint, int $sourceMovementId): ?StockMovement
    {
        $existing = StockMovement::query()->where('idempotency_key', $key)->first();
        if (! $existing) {
            return null;
        }
        if (! hash_equals((string) $existing->idempotency_fingerprint, $fingerprint)
            || $existing->movement_type !== StockMovementType::MaterialReturn
            || $existing->reference_type !== 'stock_movement'
            || (int) $existing->reference_id !== $sourceMovementId) {
            throw new MaterialReturnConflictException('The material-return idempotency key was already used for different content. Reload and retry with a new key.');
        }

        return $existing;
    }
}
