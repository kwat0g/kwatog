<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\SupplyChain\Enums\DeliveryCostHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryCostingMode;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcome;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcomeItem;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcomeMovement;
use App\Modules\SupplyChain\Models\DeliveryCostHandoff;
use App\Modules\SupplyChain\Models\DeliveryCustomerReturnAllocation;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns the future-only delivery transit asset and its source-cost handoffs.
 * Historical deliveries stay on their `legacy` mode and are never backfilled.
 */
class DeliveryCostRecognitionService
{
    private const HANDOFF_COGS = 'customer_cogs';
    private const HANDOFF_LOSS = 'unaccounted_loss';

    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly AccountingAccountPolicyService $accountPolicies,
        private readonly SettingsService $settings,
        private readonly MovementGlPostingService $movementGl,
        private readonly StockMovementService $stockMovements,
    ) {}

    /** Recognize only the cost of goods still held by the customer. */
    public function recognizeCustomerReceipt(Delivery $delivery, User $by): ?DeliveryCostHandoff
    {
        return DB::transaction(function () use ($delivery, $by): ?DeliveryCostHandoff {
            $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->cost_recognition_mode !== DeliveryCostingMode::Transit) {
                return null;
            }

            $latest = DeliveryCostHandoff::query()->where('delivery_id', $locked->id)
                ->where('handoff_type', self::HANDOFF_COGS)->orderByDesc('id')->lockForUpdate()->first();
            $allocations = $this->customerCostAllocations($locked);
            $target = $this->sumAmounts($allocations);
            if ($latest?->status === DeliveryCostHandoffStatus::Generated
                || ($latest?->status === DeliveryCostHandoffStatus::NotRequired
                    && ($this->isZero($target) || $this->settings->get('modules.accounting', false) !== true))) {
                return $latest;
            }
            $fingerprint = $this->fingerprint(self::HANDOFF_COGS, $locked, $target, $allocations);
            $key = $latest?->request_key ?? Str::uuid()->toString();

            return $this->postHandoff(
                delivery: $locked,
                type: self::HANDOFF_COGS,
                key: $key,
                fingerprint: $fingerprint,
                target: $target,
                delta: $target,
                allocations: $allocations,
                by: $by,
                expectedDependencies: true,
                existing: $latest,
            );
        }, 3);
    }

    /**
     * Reconcile the durable transit balance to the latest actual depot count.
     * A late recovery lowers the target loss; its negative delta reverses only
     * the earlier delivery-loss journal and is posted in the same transaction
     * as the physical receipt by the caller.
     */
    public function reconcileUnaccounted(DeliveryAttemptOutcome $outcome, string $requestKey, User $by): ?DeliveryCostHandoff
    {
        $key = strtolower(trim($requestKey));
        if (! Str::isUuid($key)) {
            throw new BusinessRuleException('A valid UUID request key is required for delivery cost reconciliation.');
        }

        return DB::transaction(function () use ($outcome, $key, $by): ?DeliveryCostHandoff {
            $deliveryId = DeliveryAttemptOutcome::query()->whereKey($outcome->id)->value('delivery_id');
            $delivery = Delivery::query()->lockForUpdate()->findOrFail((int) $deliveryId);
            $lockedOutcome = DeliveryAttemptOutcome::query()->whereKey($outcome->id)->lockForUpdate()->firstOrFail();
            if ($delivery->cost_recognition_mode !== DeliveryCostingMode::Transit) {
                return null;
            }
            if (! $lockedOutcome->reconciled_at) {
                throw new BusinessRuleException('The depot receipt must be final before delivery loss can be reconciled.');
            }

            $allocations = $this->lossAllocations($delivery, $lockedOutcome);
            $target = $this->sumAmounts($allocations);
            $posted = DeliveryCostHandoff::query()->where('delivery_id', $delivery->id)
                ->where('handoff_type', self::HANDOFF_LOSS)
                ->where('status', DeliveryCostHandoffStatus::Generated->value)
                ->orderByDesc('id')->lockForUpdate()->first();
            // Manual-required rows record desired state, not recognized GL.
            $priorTarget = (string) ($posted?->target_amount ?? '0.00');
            $delta = bcsub($target, $priorTarget, 2);
            $fingerprint = $this->fingerprint(self::HANDOFF_LOSS, $delivery, $target, $allocations, (int) $lockedOutcome->id);

            $sameKey = DeliveryCostHandoff::query()->where('request_key', $key)->lockForUpdate()->first();
            if ($sameKey) {
                if ((int) $sameKey->delivery_id !== (int) $delivery->id
                    || $sameKey->handoff_type !== self::HANDOFF_LOSS
                    || ! hash_equals((string) $sameKey->payload_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This delivery-cost request key was already used for a different payload.');
                }
                if ($sameKey->status === DeliveryCostHandoffStatus::Generated
                    || ($sameKey->status === DeliveryCostHandoffStatus::NotRequired
                        && ($this->isZero($target) || $this->settings->get('modules.accounting', false) !== true))) {
                    return $sameKey;
                }
            }

            return $this->postHandoff(
                delivery: $delivery,
                type: self::HANDOFF_LOSS,
                key: $key,
                fingerprint: $fingerprint,
                target: $target,
                delta: $delta,
                allocations: $allocations,
                by: $by,
                expectedDependencies: true,
                outcome: $lockedOutcome,
                existing: $sameKey,
            );
        }, 3);
    }

    /** Retry the current COGS/loss handoff after Accounting is repaired. */
    public function retry(Delivery $delivery, string $kind, string $requestKey, User $by): ?DeliveryCostHandoff
    {
        if (! Str::isUuid(strtolower($requestKey))) {
            throw new BusinessRuleException('A valid UUID request key is required for Accounting recovery.');
        }
        if ($kind === self::HANDOFF_COGS) {
            return DB::transaction(function () use ($delivery, $requestKey, $by): ?DeliveryCostHandoff {
                $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
                if ($locked->cost_recognition_mode !== DeliveryCostingMode::Transit) return null;
                if ($locked->status !== \App\Modules\SupplyChain\Enums\DeliveryStatus::Confirmed) {
                    throw new BusinessRuleException('Customer COGS can only be retried after delivery confirmation.');
                }
                $existing = DeliveryCostHandoff::query()->where('delivery_id', $locked->id)
                    ->where('handoff_type', self::HANDOFF_COGS)->orderByDesc('id')->lockForUpdate()->first();
                if ($existing?->status === DeliveryCostHandoffStatus::Generated) return $existing;
                $allocations = $this->customerCostAllocations($locked);
                $target = $this->sumAmounts($allocations);
                $fingerprint = $this->fingerprint(self::HANDOFF_COGS, $locked, $target, $allocations);
                return $this->postHandoff($locked, self::HANDOFF_COGS, strtolower($requestKey), $fingerprint,
                    $target, $target, $allocations, $by, true, existing: null);
            }, 3);
        }
        if ($kind === self::HANDOFF_LOSS) {
            $outcome = DeliveryAttemptOutcome::query()->where('delivery_id', $delivery->id)->first();
            if (! $outcome) throw new BusinessRuleException('This delivery has no reconciled exception to reconcile.');
            return $this->reconcileUnaccounted($outcome, $requestKey, $by);
        }
        throw new BusinessRuleException('Choose a supported delivery cost handoff.');
    }

    /**
     * Authoritative incremental cost for a source-bound physical truck receipt.
     * Late-found goods release the same source value previously assigned to loss.
     */
    public function deliveryReturnCost(ReturnRequestItem $item, string $quantity): string
    {
        $source = $this->truckReturnSource($item);
        $this->assertPositive($quantity);
        $prior = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)
            ->where('reference_type', 'stock_movement')->where('reference_id', $source->id)->lockForUpdate()->get();
        $priorQuantity = $this->sumMovementQuantity($prior);
        $priorValue = $this->sumMovementValue($prior);
        $nextQuantity = bcadd($priorQuantity, $quantity, 3);

        $loss = DeliveryCostHandoff::query()->where('delivery_id', $this->deliveryForSource($source)->id)
            ->where('handoff_type', self::HANDOFF_LOSS)->orderByDesc('id')->first();
        $lossAllocation = collect($loss?->source_allocations ?? [])->firstWhere('source_stock_movement_id', (int) $source->id);
        if ($lossAllocation && bccomp((string) ($lossAllocation['loss_quantity'] ?? '0'), '0', 3) > 0) {
            $lossQuantity = (string) $lossAllocation['loss_quantity'];
            $lossAmount = (string) ($lossAllocation['loss_amount'] ?? '0.00');
            if (bccomp($quantity, $lossQuantity, 3) > 0) {
                throw new BusinessRuleException('A late truck return exceeds the remaining source quantity reconciled as lost.');
            }
            $newLossQuantity = bcsub($lossQuantity, $quantity, 3);
            $newLossAmount = bccomp($newLossQuantity, '0', 3) === 0
                ? '0.00'
                : $this->proportionalValue($lossAmount, $lossQuantity, $newLossQuantity);
            return $this->round2(bcsub($lossAmount, $newLossAmount, 2));
        }

        if (bccomp($nextQuantity, (string) $source->quantity, 3) > 0) {
            throw new BusinessRuleException('Cumulative truck returns cannot exceed the original delivery issue.');
        }
        return $this->round2(bcsub($this->sourceValueAtQuantity($source, $nextQuantity), $priorValue, 2));
    }

    /**
     * Source-aware customer return receipt. Legacy delivery returns fall back
     * to ReturnRequestService's existing AdjustmentIn path.
     */
    public function receiveCustomerReturn(ReturnRequestItem $item, string $quantity, int $locationId, User $by): ?StockMovement
    {
        $deliveryItem = $item->sourceDeliveryItem()->with('delivery')->first();
        $delivery = $deliveryItem?->delivery;
        if (! $delivery || $delivery->cost_recognition_mode !== DeliveryCostingMode::Transit) {
            return null;
        }

        return DB::transaction(function () use ($item, $quantity, $locationId, $by, $delivery, $deliveryItem): StockMovement {
            $lockedDelivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $lockedItem = ReturnRequestItem::query()->with('returnRequest')
                ->lockForUpdate()->findOrFail($item->id);
            $this->assertPositive($quantity);
            if ((int) $lockedItem->source_delivery_item_id !== (int) $deliveryItem->id
                || (int) $deliveryItem->delivery_id !== (int) $lockedDelivery->id
                || (int) $lockedItem->item_id !== (int) $this->itemIdForDeliveryItem($deliveryItem)) {
                throw new BusinessRuleException('The customer-return line does not match its delivery source.');
            }

            $priorAllocations = DeliveryCustomerReturnAllocation::query()
                ->where('return_request_item_id', $lockedItem->id)->lockForUpdate()->get();
            $priorForItem = $priorAllocations->reduce(
                static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
            if (bccomp(bcadd($priorForItem, $quantity, 3), (string) $lockedItem->quantity, 3) > 0) {
                throw new BusinessRuleException('Physical customer-return receipts cannot exceed the approved RMA quantity.');
            }

            $existingSourceReturns = DeliveryCustomerReturnAllocation::query()
                ->whereHas('sourceMovement', fn ($query) => $query->where('reference_type', 'delivery_item')
                    ->where('reference_id', $deliveryItem->id))
                ->get();
            $truckBySource = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)
                ->where('reference_type', 'stock_movement')
                ->whereIn('reference_id', $this->sourceMovements($deliveryItem)->pluck('id'))
                ->get()->groupBy('reference_id');
            $sourceIssues = $this->sourceMovements($deliveryItem)->sortBy('id')->values();
            $acceptedBySource = $this->acceptedSourceQuantities($deliveryItem, $sourceIssues, $truckBySource);
            $remaining = $quantity;
            $allocations = [];
            foreach ($sourceIssues as $source) {
                $alreadyReturned = $existingSourceReturns->where('source_stock_movement_id', $source->id)->reduce(
                    static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
                $capacity = bcsub($acceptedBySource[$source->id] ?? '0.000', $alreadyReturned, 3);
                if (bccomp($capacity, '0', 3) <= 0) continue;
                $part = bccomp($remaining, $capacity, 3) < 0 ? $remaining : $capacity;
                if (bccomp($part, '0', 3) <= 0) continue;
                $allocations[] = ['source' => $source, 'quantity' => $part];
                $remaining = bcsub($remaining, $part, 3);
                if (bccomp($remaining, '0', 3) === 0) break;
            }
            if (bccomp($remaining, '0', 3) > 0) {
                throw new BusinessRuleException('This customer return exceeds the delivery source quantity that the customer actually received.');
            }

            $priorValue = '0.00';
            $costAllocations = [];
            foreach ($allocations as $allocation) {
                /** @var StockMovement $source */
                $source = $allocation['source'];
                $priorTruckRows = $truckBySource->get($source->id, collect());
                $priorTruckQty = $this->sumMovementQuantity($priorTruckRows);
                $priorTruckValue = $this->sumMovementValue($priorTruckRows);
                $allCustomerRows = $existingSourceReturns->where('source_stock_movement_id', $source->id);
                $allCustomerQty = $allCustomerRows->reduce(static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
                $allCustomerValue = $allCustomerRows->reduce(static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->total_cost, 2), '0.00');
                $nextRecoveredQty = bcadd(bcadd($priorTruckQty, $allCustomerQty, 3), $allocation['quantity'], 3);
                $increment = bcsub($this->sourceValueAtQuantity($source, $nextRecoveredQty), bcadd($priorTruckValue, $allCustomerValue, 2), 2);
                if (bccomp($increment, '0', 2) < 0) $increment = '0.00';
                $priorValue = bcadd($priorValue, $increment, 2);
                $costAllocations[] = ['source_stock_movement_id' => (int) $source->id, 'quantity' => $allocation['quantity'], 'total_cost' => $increment];
            }
            $this->assertQuarantineLocation($locationId);
            $totalCost = $priorValue;
            $unitCost = bccomp($quantity, '0', 3) > 0 ? bcdiv($totalCost, $quantity, 4) : '0.0000';
            $movement = $this->stockMovements->move(new StockMovementInput(
                type: StockMovementType::DeliveryCustomerReturn,
                itemId: (int) $lockedItem->item_id,
                toLocationId: $locationId,
                quantity: $quantity,
                unitCost: $unitCost,
                referenceType: 'return_request_item',
                referenceId: (int) $lockedItem->id,
                remarks: 'Customer return '.$lockedItem->returnRequest?->rma_number.'; original delivery '.$lockedDelivery->delivery_number,
                createdBy: $by->id,
                lotNumber: $sourceIssues->first()?->lot_number,
                expiryDate: $sourceIssues->first()?->expiry_date?->toDateString(),
                totalCostOverride: $totalCost,
                customerReturnItemId: (int) $lockedItem->id,
            ));
            foreach ($costAllocations as $allocation) {
                DeliveryCustomerReturnAllocation::create([
                    'return_request_item_id' => $lockedItem->id,
                    'source_stock_movement_id' => $allocation['source_stock_movement_id'],
                    'stock_movement_id' => $movement->id,
                    'quantity' => $allocation['quantity'],
                    'total_cost' => $allocation['total_cost'],
                ]);
            }
            return $movement;
        }, 3);
    }

    /** Exact-cent incremental cost of an ordinary customer return. */
    public function customerReturnCost(ReturnRequestItem $item, string $quantity): string
    {
        $sourceMovements = $this->sourceMovements($item->sourceDeliveryItem()->firstOrFail())->sortBy('id')->values();
        $used = DeliveryCustomerReturnAllocation::query()->where('return_request_item_id', $item->id)->get();
        $priorQuantity = $used->reduce(static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
        if (bccomp(bcadd($priorQuantity, $quantity, 3), (string) $item->quantity, 3) > 0) {
            throw new BusinessRuleException('The source-linked customer-return quantity exceeds its approved RMA line.');
        }
        return $this->costForCustomerRmaItem($item, $quantity, $sourceMovements);
    }

    private function customerCostAllocations(Delivery $delivery): array
    {
        $delivery->loadMissing(['items.stockMovements']);
        $allocations = [];
        foreach ($delivery->items as $line) {
            $issues = $this->sourceMovements($line)->sortBy('id')->values();
            if ($issues->isEmpty()) continue;
            $truckRows = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)
                ->where('reference_type', 'stock_movement')->whereIn('reference_id', $issues->pluck('id'))->get()->groupBy('reference_id');
            $accepted = (string) ($line->customer_received_quantity ?? $line->quantity);
            $customerReturns = DeliveryCustomerReturnAllocation::query()->whereIn('source_stock_movement_id', $issues->pluck('id'))->get()->groupBy('source_stock_movement_id');
            $remaining = $accepted;
            foreach ($issues as $source) {
                $truck = $truckRows->get($source->id, collect());
                $truckQty = $this->sumMovementQuantity($truck);
                $capacity = bcsub((string) $source->quantity, $truckQty, 3);
                $acceptedPart = bccomp($capacity, $remaining, 3) < 0 ? $capacity : $remaining;
                if (bccomp($acceptedPart, '0', 3) < 0) $acceptedPart = '0.000';
                $returnRows = $customerReturns->get($source->id, collect());
                $customerReturnQty = $returnRows->reduce(static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
                $outcomeMovement = DeliveryAttemptOutcomeMovement::query()->where('stock_movement_id', $source->id)->first();
                if ($outcomeMovement?->customer_received_quantity !== null) {
                    $acceptedPart = (string) $outcomeMovement->customer_received_quantity;
                }
                $acceptedNet = bcsub($acceptedPart, $customerReturnQty, 3);
                if (bccomp($acceptedNet, '0', 3) < 0) $acceptedNet = '0.000';
                if (bccomp($acceptedNet, '0', 3) > 0) {
                    $amount = $this->sourceValueAtQuantity($source, $acceptedNet);
                    if (bccomp($amount, '0', 2) > 0) {
                        $allocations[] = [
                            'source_stock_movement_id' => (int) $source->id,
                            'quantity' => $acceptedNet,
                            'amount' => $amount,
                        ];
                    }
                }
                $remaining = bcsub($remaining, $acceptedPart, 3);
            }
        }
        return $allocations;
    }

    private function lossAllocations(Delivery $delivery, DeliveryAttemptOutcome $outcome): array
    {
        $outcome->loadMissing('items.movements.stockMovement');
        $allocations = [];
        $cogsBySource = collect($this->customerCostAllocations($delivery))->keyBy('source_stock_movement_id');
        $previousCogs = DeliveryCostHandoff::query()->where('delivery_id', $delivery->id)
            ->where('handoff_type', self::HANDOFF_COGS)->where('status', DeliveryCostHandoffStatus::Generated->value)
            ->orderByDesc('id')->first();
        if ($previousCogs) {
            $cogsBySource = collect($previousCogs->source_allocations ?? [])->keyBy('source_stock_movement_id');
        }
        $sourceRows = $outcome->items->flatMap(fn (DeliveryAttemptOutcomeItem $line) => $line->movements)->keyBy('stock_movement_id');
        foreach ($outcome->items as $outcomeLine) {
            $remainingLoss = (string) $outcomeLine->unaccounted_quantity;
            foreach ($outcomeLine->movements as $outcomeMovement) {
                $source = $outcomeMovement->stockMovement;
                if (! $source) continue;
                $received = (string) ($outcomeMovement->received_quantity ?? '0.000');
                $accepted = $this->acceptedForSource($outcomeLine, $outcomeLine->movements, $source->id);
                $capacity = bcsub(bcsub((string) $source->quantity, $received, 3), $accepted, 3);
                $lossQty = bccomp($remainingLoss, $capacity, 3) < 0 ? $remainingLoss : $capacity;
                if (bccomp($lossQty, '0', 3) < 0) $lossQty = '0.000';
                if (bccomp($lossQty, '0', 3) > 0) {
                    $truckValue = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)
                        ->where('reference_type', 'stock_movement')->where('reference_id', $source->id)
                        ->sum('total_cost');
                    $customerValue = DeliveryCustomerReturnAllocation::query()->where('source_stock_movement_id', $source->id)->sum('total_cost');
                    $cogs = $cogsBySource->get($source->id);
                    $cogsAmount = (string) (is_array($cogs) ? ($cogs['amount'] ?? '0.00') : ($cogs['amount'] ?? '0.00'));
                    $amount = bcsub(bcsub(bcsub((string) $source->total_cost, (string) $truckValue, 2), (string) $customerValue, 2), $cogsAmount, 2);
                    $amount = $this->boundedAmount($amount, (string) $source->total_cost);
                    $allocations[] = [
                        'source_stock_movement_id' => (int) $source->id,
                        'loss_quantity' => $lossQty,
                        'loss_amount' => $amount,
                        'truck_return_quantity' => $received,
                        'truck_return_amount' => $this->round2((string) $truckValue),
                        'customer_return_amount' => $this->round2((string) $customerValue),
                        'customer_cogs_amount' => $this->round2($cogsAmount),
                    ];
                }
                $remainingLoss = bcsub($remainingLoss, $lossQty, 3);
            }
        }
        return $allocations;
    }

    private function acceptedForSource(DeliveryAttemptOutcomeItem $line, $movements, int $sourceId): string
    {
        $truckRemaining = (string) $line->customer_received_quantity;
        foreach ($movements as $row) {
            $source = $row->stockMovement;
            if (! $source) continue;
            if ((int) $source->id === $sourceId && $row->customer_received_quantity !== null) {
                return (string) $row->customer_received_quantity;
            }
            $truck = (string) ($row->received_quantity ?? '0.000');
            $capacity = bcsub((string) $source->quantity, $truck, 3);
            $part = bccomp($truckRemaining, $capacity, 3) < 0 ? $truckRemaining : $capacity;
            if (bccomp($part, '0', 3) < 0) $part = '0.000';
            if ((int) $source->id === $sourceId) return $part;
            $truckRemaining = bcsub($truckRemaining, $part, 3);
        }
        return '0.000';
    }

    private function postHandoff(
        Delivery $delivery,
        string $type,
        string $key,
        string $fingerprint,
        string $target,
        string $delta,
        array $allocations,
        User $by,
        bool $expectedDependencies,
        ?DeliveryAttemptOutcome $outcome = null,
        ?DeliveryCostHandoff $existing = null,
    ): DeliveryCostHandoff {
        $handoff = $existing;
        if (! $handoff) {
            $handoff = DeliveryCostHandoff::query()->where('request_key', $key)->lockForUpdate()->first();
            if ($handoff && ((int) $handoff->delivery_id !== (int) $delivery->id
                || $handoff->handoff_type !== $type
                || ! hash_equals((string) $handoff->payload_fingerprint, $fingerprint))) {
                throw new BusinessRuleException('This delivery-cost request key was already used for a different payload.');
            }
        }
        if ($handoff && ($handoff->status === DeliveryCostHandoffStatus::Generated
            || ($handoff->status === DeliveryCostHandoffStatus::NotRequired
                && ($this->isZero($delta) || $this->settings->get('modules.accounting', false) !== true)))) {
            return $handoff;
        }
        $handoff ??= DeliveryCostHandoff::create([
            'delivery_id' => $delivery->id,
            'delivery_attempt_outcome_id' => $outcome?->id,
            'request_key' => strtolower($key),
            'payload_fingerprint' => $fingerprint,
            'handoff_type' => $type,
            'status' => DeliveryCostHandoffStatus::ManualRequired,
            'target_amount' => $target,
            'delta_amount' => $delta,
            'source_allocations' => $allocations,
            'created_by' => $by->id,
        ]);
        $handoff->forceFill([
            'delivery_attempt_outcome_id' => $outcome?->id ?? $handoff->delivery_attempt_outcome_id,
            'payload_fingerprint' => $fingerprint,
            'target_amount' => $target,
            'delta_amount' => $delta,
            'source_allocations' => $allocations,
            'created_by' => $by->id,
            'attempted_at' => now(),
        ])->save();

        if ($this->isZero($delta)) {
            $handoff->forceFill([
                'status' => DeliveryCostHandoffStatus::NotRequired,
                'journal_entry_id' => null,
                'message' => null,
            ])->save();
            return $handoff->fresh();
        }
        if ($this->settings->get('modules.accounting', false) !== true) {
            $handoff->forceFill([
                'status' => DeliveryCostHandoffStatus::NotRequired,
                'journal_entry_id' => null,
                'message' => 'Accounting is disabled. This positive source-cost handoff can be retried if Accounting is enabled later.',
            ])->save();
            return $handoff->fresh();
        }

        try {
            if ($expectedDependencies && ! $this->retryMovementDependencies($delivery)) {
                throw new BusinessRuleException('One or more source stock movements still need General Ledger posting. Repair those movement handoffs and retry delivery cost recognition.');
            }
            $journalId = $this->postJournal($delivery, $type, $delta, $by);
            $handoff->forceFill([
                'status' => DeliveryCostHandoffStatus::Generated,
                'journal_entry_id' => $journalId,
                'message' => null,
            ])->save();
        } catch (BusinessRuleException|RuntimeException $error) {
            $handoff->forceFill([
                'status' => DeliveryCostHandoffStatus::ManualRequired,
                'journal_entry_id' => null,
                'message' => mb_substr($error->getMessage(), 0, 4000),
            ])->save();
        }

        return $handoff->fresh();
    }

    private function postJournal(Delivery $delivery, string $type, string $delta, User $by): int
    {
        $amount = $this->round2(ltrim($delta, '-'));
        if ($this->isZero($amount)) throw new BusinessRuleException('There is no delivery-cost amount to post.');
        $transitId = $this->accountPolicies->controlAccountIdForSetting('accounting.accounts.inventory_delivery_transit_code');
        $counterSetting = $type === self::HANDOFF_COGS
            ? 'accounting.accounts.delivery_cogs_code'
            : 'accounting.accounts.delivery_loss_code';
        $counterId = $this->accountPolicies->controlAccountIdForSetting($counterSetting);
        $description = $type === self::HANDOFF_COGS
            ? 'Customer receipt COGS — '.$delivery->delivery_number
            : 'Unaccounted delivery loss — '.$delivery->delivery_number;
        $positive = bccomp($delta, '0', 2) > 0;
        $counterIsDebit = $type === self::HANDOFF_COGS || $positive;
        $lines = $counterIsDebit
            ? [
                ['account_id' => $counterId, 'debit' => $amount, 'credit' => '0.00', 'description' => $description],
                ['account_id' => $transitId, 'debit' => '0.00', 'credit' => $amount, 'description' => $description],
            ]
            : [
                ['account_id' => $transitId, 'debit' => $amount, 'credit' => '0.00', 'description' => 'Reverse delivery loss — '.$delivery->delivery_number],
                ['account_id' => $counterId, 'debit' => '0.00', 'credit' => $amount, 'description' => 'Reverse delivery loss — '.$delivery->delivery_number],
            ];

        return DB::transaction(function () use ($delivery, $by, $description, $lines): int {
            $journal = $this->journals->create([
                'date' => now()->toDateString(),
                'description' => $description,
                'reference_type' => 'delivery',
                'reference_id' => $delivery->id,
                'lines' => $lines,
            ]);
            return (int) $this->journals->postSystem($journal, $by->id)->id;
        }, 3);
    }

    private function retryMovementDependencies(Delivery $delivery): bool
    {
        $lineIds = DeliveryItem::query()->where('delivery_id', $delivery->id)->pluck('id');
        $issues = StockMovement::query()->where('movement_type', StockMovementType::Delivery->value)
            ->where('reference_type', 'delivery_item')->whereIn('reference_id', $lineIds)->get();
        $sourceIds = $issues->pluck('id');
        $returns = StockMovement::query()->whereIn('movement_type', [StockMovementType::DeliveryReturn->value, StockMovementType::DeliveryCustomerReturn->value])
            ->where(function ($query) use ($sourceIds, $lineIds): void {
                $query->where(function ($q) use ($sourceIds): void {
                    $q->where('movement_type', StockMovementType::DeliveryReturn->value)
                        ->where('reference_type', 'stock_movement')->whereIn('reference_id', $sourceIds);
                })->orWhere(function ($q) use ($lineIds): void {
                    $q->where('movement_type', StockMovementType::DeliveryCustomerReturn->value)
                        ->where('reference_type', 'return_request_item')
                        ->whereIn('reference_id', ReturnRequestItem::query()->whereIn('source_delivery_item_id', $lineIds)->select('id'));
                });
            })->get();
        foreach ($issues->concat($returns) as $movement) {
            if ($movement->gl_handoff_status === MovementGlHandoffStatus::ManualRequired) {
                try {
                    $movement = $this->movementGl->retry($movement);
                } catch (BusinessRuleException|RuntimeException) {
                    return false;
                }
            }
            if ($movement->gl_handoff_status === MovementGlHandoffStatus::ManualRequired) return false;
        }
        return true;
    }

    private function costForCustomerRmaItem(ReturnRequestItem $item, string $quantity, $sourceMovements): string
    {
        $total = '0.00';
        $remaining = $quantity;
        foreach ($sourceMovements as $source) {
            $rows = DeliveryCustomerReturnAllocation::query()->where('source_stock_movement_id', $source->id)->get();
            $priorQty = $rows->reduce(static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
            $priorValue = $rows->reduce(static fn (string $sum, DeliveryCustomerReturnAllocation $row): string => bcadd($sum, (string) $row->total_cost, 2), '0.00');
            $truck = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)
                ->where('reference_type', 'stock_movement')->where('reference_id', $source->id)->get();
            $truckQty = $this->sumMovementQuantity($truck);
            $truckValue = $this->sumMovementValue($truck);
            $capacity = bcsub(bcsub((string) $source->quantity, $truckQty, 3), $priorQty, 3);
            $part = bccomp($remaining, $capacity, 3) < 0 ? $remaining : $capacity;
            if (bccomp($part, '0', 3) <= 0) continue;
            $nextRecovered = bcadd(bcadd($truckQty, $priorQty, 3), $part, 3);
            $value = bcsub($this->sourceValueAtQuantity($source, $nextRecovered), bcadd($truckValue, $priorValue, 2), 2);
            $total = bcadd($total, $this->boundedAmount($value, (string) $source->total_cost), 2);
            $remaining = bcsub($remaining, $part, 3);
            if (bccomp($remaining, '0', 3) === 0) break;
        }
        if (bccomp($remaining, '0', 3) > 0) {
            throw new BusinessRuleException('The customer return exceeds remaining source stock value.');
        }
        return $this->round2($total);
    }

    private function truckReturnSource(ReturnRequestItem $item): StockMovement
    {
        $item->loadMissing('deliveryAttemptOutcomeMovement.outcomeItem.outcome');
        $outcomeMovement = $item->deliveryAttemptOutcomeMovement;
        $source = $outcomeMovement?->stockMovement;
        if (! $outcomeMovement || ! $source || $source->movement_type !== StockMovementType::Delivery
            || ! $outcomeMovement->outcomeItem?->outcome?->reconciled_at
            || ! $item->quarantine_location_id) {
            throw new BusinessRuleException('The truck-return RMA line is not linked to a reconciled original delivery issue.');
        }
        return $source;
    }

    private function sourceMovements(DeliveryItem $line)
    {
        return StockMovement::query()->where('movement_type', StockMovementType::Delivery->value)
            ->where('reference_type', 'delivery_item')->where('reference_id', $line->id)->orderBy('id')->get();
    }

    private function acceptedSourceQuantities(DeliveryItem $line, $issues, $truckBySource): array
    {
        $remaining = (string) ($line->customer_received_quantity ?? $line->quantity);
        $result = [];
        foreach ($issues as $source) {
            $outcomeMovement = DeliveryAttemptOutcomeMovement::query()->where('stock_movement_id', $source->id)->first();
            if ($outcomeMovement?->customer_received_quantity !== null) {
                $result[$source->id] = (string) $outcomeMovement->customer_received_quantity;
                $remaining = bcsub($remaining, $result[$source->id], 3);
                continue;
            }
            $truck = $truckBySource->get($source->id, collect());
            $capacity = bcsub((string) $source->quantity, $this->sumMovementQuantity($truck), 3);
            $part = bccomp($remaining, $capacity, 3) < 0 ? $remaining : $capacity;
            if (bccomp($part, '0', 3) < 0) $part = '0.000';
            $result[$source->id] = $part;
            $remaining = bcsub($remaining, $part, 3);
        }
        return $result;
    }

    private function itemIdForDeliveryItem(DeliveryItem $line): int
    {
        $partNumber = $line->salesOrderItem?->product?->part_number;
        $itemId = $partNumber ? \App\Modules\Inventory\Models\Item::query()->where('code', $partNumber)->value('id') : null;
        return (int) ($itemId ?? 0);
    }

    private function deliveryForSource(StockMovement $source): Delivery
    {
        $deliveryItemId = $source->reference_type === 'delivery_item' ? $source->reference_id : null;
        $deliveryId = $deliveryItemId ? DeliveryItem::query()->whereKey($deliveryItemId)->value('delivery_id') : null;
        if (! $deliveryId) throw new BusinessRuleException('The original delivery issue has no delivery source.');
        return Delivery::query()->findOrFail($deliveryId);
    }

    private function assertQuarantineLocation(int $locationId): void
    {
        $location = \App\Modules\Inventory\Models\WarehouseLocation::query()->with('zone.warehouse')->find($locationId);
        $zoneType = $location?->zone?->zone_type;
        $zoneValue = $zoneType instanceof \App\Modules\Inventory\Enums\WarehouseZoneType ? $zoneType->value : (string) $zoneType;
        if (! $location || ! $location->is_active || $location->is_blocked
            || ! $location->zone?->warehouse?->is_active || $zoneValue !== 'quarantine') {
            throw new BusinessRuleException('Customer return stock must enter an active, unblocked quarantine location.');
        }
    }

    private function sourceValueAtQuantity(StockMovement $source, string $quantity): string
    {
        if (bccomp($quantity, (string) $source->quantity, 3) === 0) return $this->round2((string) $source->total_cost);
        return $this->round2(bcmul(bcdiv((string) $source->total_cost, (string) $source->quantity, 8), $quantity, 4));
    }

    private function proportionalValue(string $amount, string $baseQuantity, string $quantity): string
    {
        if (bccomp($quantity, $baseQuantity, 3) === 0) return $this->round2($amount);
        return $this->round2(bcmul(bcdiv($amount, $baseQuantity, 8), $quantity, 4));
    }

    private function sumMovementQuantity(iterable $movements): string
    {
        $sum = '0.000';
        foreach ($movements as $movement) $sum = bcadd($sum, (string) $movement->quantity, 3);
        return $sum;
    }

    private function sumMovementValue(iterable $movements): string
    {
        $sum = '0.00';
        foreach ($movements as $movement) $sum = bcadd($sum, (string) $movement->total_cost, 2);
        return $sum;
    }

    private function sumAmounts(array $allocations): string
    {
        return collect($allocations)->reduce(static fn (string $sum, array $row): string => bcadd($sum, (string) ($row['amount'] ?? $row['loss_amount'] ?? '0.00'), 2), '0.00');
    }

    private function fingerprint(string $type, Delivery $delivery, string $target, array $allocations, ?int $outcomeId = null): string
    {
        return hash('sha256', json_encode([
            'delivery_id' => (int) $delivery->id,
            'outcome_id' => $outcomeId,
            'kind' => $type,
            'target' => $this->round2($target),
            'allocations' => $allocations,
        ], JSON_THROW_ON_ERROR));
    }

    private function boundedAmount(string $amount, string $maximum): string
    {
        if (bccomp($amount, '0', 2) < 0) return '0.00';
        return bccomp($amount, $maximum, 2) > 0 ? $this->round2($maximum) : $this->round2($amount);
    }

    private function assertPositive(string $quantity): void
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $quantity) || bccomp($quantity, '0', 3) <= 0) {
            throw new BusinessRuleException('A positive quantity with at most three decimal places is required.');
        }
    }

    private function isZero(string $amount): bool { return bccomp($amount, '0', 2) === 0; }

    private function round2(string $amount): string
    {
        $rounded = bcadd($amount, '0', 2);
        $third = (int) substr(strrchr($amount, '.') ?: '.000', 3, 1);
        return $third >= 5 ? bcadd($rounded, '0.01', 2) : $rounded;
    }
}
