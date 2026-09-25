<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use App\Modules\SupplyChain\Enums\DeliveryAttemptReason;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcome;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcomeItem;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcomeMovement;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable driver-declared delivery outcomes and depot reconciliation. */
class DeliveryAttemptOutcomeService
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function report(Delivery $delivery, User $by, array $data, bool $driverSurface = false): Delivery
    {
        $deliveryId = (int) $delivery->id;
        $key = strtolower(trim((string) $data['request_key']));
        $normalized = $this->normalizeReport($deliveryId, $data);
        $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($deliveryId, $by, $data, $driverSurface, $key, $normalized, $fingerprint): Delivery {
            $salesOrderId = Delivery::query()->whereKey($deliveryId)->value('sales_order_id');
            $salesOrder = $salesOrderId ? SalesOrder::query()->lockForUpdate()->find($salesOrderId) : null;
            $locked = Delivery::query()->lockForUpdate()->find($deliveryId);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }
            if ($driverSurface && (int) $locked->driver_id !== (int) $by->id) {
                abort(404);
            }
            if ((int) $locked->sales_order_id !== (int) $salesOrderId || ($salesOrderId && ! $salesOrder)) {
                throw new BusinessRuleException('The delivery order changed. Reload before reporting its outcome.');
            }

            $replay = DeliveryAttemptOutcome::query()->where('request_key', $key)->lockForUpdate()->first();
            if ($replay) {
                if ((int) $replay->delivery_id !== $deliveryId || ! hash_equals((string) $replay->payload_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This attempt request key was already used with different delivery details.');
                }
                return $this->deliveries->show($locked);
            }
            if ($locked->attemptOutcome()->exists()) {
                throw new BusinessRuleException('A delivery attempt outcome has already been recorded for this shipment.');
            }
            if ($locked->status !== DeliveryStatus::InTransit) {
                throw new BusinessRuleException('Only an in-transit delivery can report a failed or partial delivery attempt.');
            }

            $lines = $this->lockDeliveryLines($locked, $normalized['lines']);
            $outcome = DeliveryAttemptOutcome::create([
                'delivery_id' => $locked->id,
                'request_key' => $key,
                'payload_fingerprint' => $fingerprint,
                'reason_code' => $normalized['reason_code'],
                'notes' => $normalized['notes'],
                'reported_by' => $by->id,
                'reported_at' => now(),
            ]);

            $acceptedTotal = '0.000';
            $damagedTotal = '0.000';
            foreach ($lines as $line) {
                $row = $normalized['lines'][(int) $line->id];
                $shipped = (string) $line->quantity;
                $accepted = $row['customer_received_quantity'];
                $customerDamaged = $row['customer_received_damaged_quantity'];
                $truckReturn = $row['truck_return_quantity'];
                $truckDamaged = $row['truck_return_damaged_quantity'];
                $unaccounted = $row['unaccounted_quantity'];

                if (bccomp(bcadd($accepted, $truckReturn, 3), $shipped, 3) > 0
                    || bccomp(bcadd(bcadd($accepted, $truckReturn, 3), $unaccounted, 3), $shipped, 3) !== 0) {
                    throw ValidationException::withMessages([
                        'lines' => "Quantities for {$line->hash_id} must add up exactly to the shipped quantity ({$shipped}).",
                    ]);
                }
                if (bccomp($customerDamaged, $accepted, 3) > 0 || bccomp($truckDamaged, $truckReturn, 3) > 0) {
                    throw ValidationException::withMessages(['lines' => 'Damaged quantity must be part of the goods in the corresponding location.']);
                }
                if (! $this->hasAtMostTwoDecimals($accepted)) {
                    throw ValidationException::withMessages(['lines' => 'Customer-received quantities must use at most two decimal places for the sales-order and invoice ledger.']);
                }

                $issues = $this->originalIssues($line, $shipped);
                $outcomeLine = DeliveryAttemptOutcomeItem::create([
                    'delivery_attempt_outcome_id' => $outcome->id,
                    'delivery_item_id' => $line->id,
                    'shipped_quantity' => $shipped,
                    'customer_received_quantity' => $accepted,
                    'customer_received_damaged_quantity' => $customerDamaged,
                    'truck_return_quantity' => $truckReturn,
                    'truck_return_damaged_quantity' => $truckDamaged,
                    'declared_unaccounted_quantity' => $unaccounted,
                ]);

                // The immutable issue rows are retained as the source budget
                // for a later, actual depot receipt. Record every row, including
                // zero declared return allocations, so a depot variance can be
                // traced without inventing stock provenance.
                $declaredRemaining = $truckReturn;
                foreach ($issues as $issue) {
                    $declared = bccomp($declaredRemaining, '0', 3) > 0
                        ? (bccomp($declaredRemaining, (string) $issue->quantity, 3) < 0 ? $declaredRemaining : (string) $issue->quantity)
                        : '0.000';
                    DeliveryAttemptOutcomeMovement::create([
                        'delivery_attempt_outcome_item_id' => $outcomeLine->id,
                        'stock_movement_id' => $issue->id,
                        'declared_quantity' => $declared,
                    ]);
                    $declaredRemaining = bcsub($declaredRemaining, $declared, 3);
                }

                $line->forceFill(['customer_received_quantity' => $accepted])->save();
                $acceptedTotal = bcadd($acceptedTotal, $accepted, 3);
                $damagedTotal = bcadd($damagedTotal, $customerDamaged, 3);
            }

            $locked->forceFill(['status' => DeliveryStatus::ReturnPending->value])->save();
            if ($damagedTotal !== '0.000') {
                app(ReturnCaseService::class)->createDeliveryAttemptDamageCase($locked, $outcome, $by);
            }

            $this->notifyWarehouseOfAttempt($locked, $outcome);

            return $this->deliveries->show($locked);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function receive(Delivery $delivery, User $by, array $data): Delivery
    {
        $deliveryId = (int) $delivery->id;
        $key = strtolower(trim((string) $data['request_key']));
        $normalized = $this->normalizeReceipt($deliveryId, $data);
        $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($deliveryId, $by, $data, $key, $normalized, $fingerprint): Delivery {
            $salesOrderId = Delivery::query()->whereKey($deliveryId)->value('sales_order_id');
            $salesOrder = $salesOrderId ? SalesOrder::query()->lockForUpdate()->find($salesOrderId) : null;
            $locked = Delivery::query()->lockForUpdate()->find($deliveryId);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }
            if ((int) $locked->sales_order_id !== (int) $salesOrderId || ($salesOrderId && ! $salesOrder)) {
                throw new BusinessRuleException('The delivery order changed. Reload before reconciling its truck return.');
            }
            $outcome = DeliveryAttemptOutcome::query()->where('delivery_id', $locked->id)->lockForUpdate()->first();
            if (! $outcome) {
                throw new BusinessRuleException('This delivery has no driver-reported exception to reconcile.');
            }

            $replay = DeliveryAttemptOutcome::query()->where('receipt_request_key', $key)->lockForUpdate()->first();
            if ($replay) {
                if ((int) $replay->id !== (int) $outcome->id || ! hash_equals((string) $replay->receipt_payload_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This depot receipt request key was already used with different quantities or options.');
                }
                return $this->deliveries->show($locked);
            }
            if ($outcome->reconciled_at !== null || $locked->status !== DeliveryStatus::ReturnPending) {
                throw new BusinessRuleException('The depot return for this delivery has already been reconciled.');
            }

            $outcomeLines = $outcome->items()->with('deliveryItem')->lockForUpdate()->get()->keyBy('delivery_item_id');
            if (count($normalized['lines']) !== $outcomeLines->count()) {
                throw ValidationException::withMessages(['lines' => 'Include one depot count for every delivery line.']);
            }

            $totalReceived = '0.000';
            $requiresVarianceReason = false;
            foreach ($outcomeLines as $lineId => $outcomeLine) {
                if (! array_key_exists((int) $lineId, $normalized['lines'])) {
                    throw ValidationException::withMessages(['lines' => 'Include one depot count for every delivery line.']);
                }
                $received = $normalized['lines'][(int) $lineId];
                $physicalMaximum = bcsub((string) $outcomeLine->shipped_quantity, (string) $outcomeLine->customer_received_quantity, 3);
                if (bccomp($received, '0', 3) < 0 || bccomp($received, $physicalMaximum, 3) > 0) {
                    throw ValidationException::withMessages([
                        'lines' => "Depot count for {$outcomeLine->deliveryItem->hash_id} must be from zero to {$physicalMaximum}.",
                    ]);
                }
                if (bccomp($received, (string) $outcomeLine->truck_return_quantity, 3) !== 0
                    || bccomp((string) $outcomeLine->declared_unaccounted_quantity, '0', 3) > 0) {
                    $requiresVarianceReason = true;
                }
                $outcomeLine->forceFill([
                    'warehouse_received_quantity' => $received,
                    'unaccounted_quantity' => bcsub($physicalMaximum, $received, 3),
                ])->save();
                $totalReceived = bcadd($totalReceived, $received, 3);

                $remaining = $received;
                foreach ($outcomeLine->movements()->with('stockMovement')->lockForUpdate()->get() as $source) {
                    $quantity = bccomp($remaining, '0', 3) > 0
                        ? (bccomp($remaining, (string) $source->stockMovement->quantity, 3) < 0
                            ? $remaining : (string) $source->stockMovement->quantity)
                        : '0.000';
                    $source->forceFill(['received_quantity' => $quantity])->save();
                    $remaining = bcsub($remaining, $quantity, 3);
                }
                if (bccomp($remaining, '0', 3) !== 0) {
                    throw new BusinessRuleException('Depot receipt exceeds the delivery issue movements available for traceable return.');
                }
                $acceptedRemaining = (string) $outcomeLine->customer_received_quantity;
                foreach ($outcomeLine->movements()->with('stockMovement')->lockForUpdate()->get() as $source) {
                    $capacity = bcsub((string) $source->stockMovement->quantity, (string) $source->received_quantity, 3);
                    $accepted = bccomp($acceptedRemaining, $capacity, 3) < 0 ? $acceptedRemaining : $capacity;
                    $source->forceFill(['customer_received_quantity' => $accepted])->save();
                    $acceptedRemaining = bcsub($acceptedRemaining, $accepted, 3);
                }
                if (bccomp($acceptedRemaining, '0', 3) !== 0) {
                    throw new BusinessRuleException('Customer custody exceeds the original source quantities after the depot count.');
                }
            }
            if ($requiresVarianceReason && trim((string) ($data['variance_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['variance_reason' => 'Explain the depot variance or the unaccounted shipment quantity before final reconciliation.']);
            }

            $locationId = null;
            if (bccomp($totalReceived, '0', 3) > 0) {
                $locationId = HashIdFilter::decode((string) ($data['quarantine_location_id'] ?? ''), WarehouseLocation::class);
                if (! $locationId) {
                    throw ValidationException::withMessages(['quarantine_location_id' => 'Choose an active quarantine location for physically returned goods.']);
                }
                $location = WarehouseLocation::query()->with('zone.warehouse')->find($locationId);
                $zoneType = $location?->zone?->zone_type;
                $zoneType = $zoneType instanceof WarehouseZoneType ? $zoneType : WarehouseZoneType::tryFrom((string) $zoneType);
                if (! $location || ! $location->is_active || $location->is_blocked
                    || ! $location->zone?->warehouse?->is_active || $zoneType !== WarehouseZoneType::Quarantine) {
                    throw ValidationException::withMessages(['quarantine_location_id' => 'Choose an active, unblocked quarantine location.']);
                }
            }

            $receiptFingerprint = $fingerprint;
            $outcome->forceFill([
                'receipt_request_key' => $key,
                'receipt_payload_fingerprint' => $receiptFingerprint,
                'received_by' => $by->id,
                'quarantine_location_id' => $locationId,
                'variance_reason' => trim((string) ($data['variance_reason'] ?? '')) ?: null,
                'reconciled_at' => now(),
            ])->save();

            $returnRequest = null;
            if (bccomp($totalReceived, '0', 3) > 0) {
                $returnRequest = app(ReturnRequestService::class)->createTruckReturnFromAttemptOutcome(
                    $outcome->fresh()->load(['delivery.salesOrder', 'items.deliveryItem.salesOrderItem.product', 'items.movements.stockMovement']),
                    $by,
                    (int) $locationId,
                    $key,
                    $receiptFingerprint,
                    (string) ($data['variance_reason'] ?? ''),
                );
                $outcome->forceFill(['return_request_id' => $returnRequest->id])->save();
            }

            app(DeliveryCostRecognitionService::class)->reconcileUnaccounted($outcome->fresh(), $key, $by);

            $acceptedTotal = (string) $outcomeLines->reduce(
                fn (string $total, DeliveryAttemptOutcomeItem $line): string => bcadd($total, (string) $line->customer_received_quantity, 3),
                '0.000',
            );
            $next = bccomp($acceptedTotal, '0', 3) > 0 ? DeliveryStatus::Delivered : DeliveryStatus::Returned;
            $locked->forceFill([
                'status' => $next->value,
                'delivered_at' => $next === DeliveryStatus::Delivered ? ($locked->delivered_at ?? $outcome->reported_at) : $locked->delivered_at,
            ])->save();

            if ($salesOrder) {
                $this->deliveries->syncDeliveredQuantities($salesOrder);
            }

            if ($locked->vehicle_id) {
                $vehicle = Vehicle::query()->lockForUpdate()->find($locked->vehicle_id);
                if ($vehicle) {
                    $otherActive = Delivery::query()->where('vehicle_id', $vehicle->id)
                        ->whereKeyNot($locked->id)
                        ->whereIn('status', [DeliveryStatus::Loading->value, DeliveryStatus::InTransit->value, DeliveryStatus::ReturnPending->value])
                        ->exists();
                    $vehicle->update(['status' => $otherActive ? 'in_use' : 'available']);
                }
            }

            return $this->deliveries->show($locked);
        }, 3);
    }

    /** Correct a driver report before physical reconciliation; retain both snapshots. */
    public function amend(Delivery $delivery, User $by, array $data, bool $driverOnly = false): Delivery
    {
        $normalized = $this->normalizeReport((int) $delivery->id, $data);
        $reason = trim((string) ($data['correction_reason'] ?? ''));
        $version = (int) ($data['expected_version'] ?? 0);
        $key = strtolower((string) $data['request_key']);
        $fingerprint = hash('sha256', json_encode([$normalized, $reason, $version], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($delivery, $by, $normalized, $reason, $version, $key, $fingerprint, $driverOnly): Delivery {
            [$locked, $outcome] = $this->lockOutcome($delivery);
            abort_if($driverOnly && (int) $locked->driver_id !== (int) $by->id, 404);
            if ($this->replayRevision($outcome, $key, $fingerprint)) {
                return $this->deliveries->show($locked);
            }
            if ($outcome->reconciled_at || $locked->status !== DeliveryStatus::ReturnPending) {
                throw new BusinessRuleException('The depot count is already final. Record late-found goods separately; customer receipt or billing corrections require a reviewed problem report.');
            }
            if ($outcome->version !== $version) {
                throw new BusinessRuleException('This report changed while you were editing. Reload and review the latest quantities.');
            }
            if ($reason === '') {
                throw ValidationException::withMessages(['correction_reason' => 'Explain what was incorrect in the earlier report.']);
            }
            $before = $this->snapshot($outcome);
            $lines = $this->lockDeliveryLines($locked, $normalized['lines']);
            foreach ($lines as $line) {
                $row = $normalized['lines'][(int) $line->id];
                $accepted = $row['customer_received_quantity'];
                $truck = $row['truck_return_quantity'];
                if (bccomp(bcadd(bcadd($accepted, $truck, 3), $row['unaccounted_quantity'], 3), (string) $line->quantity, 3) !== 0
                    || bccomp($row['customer_received_damaged_quantity'], $accepted, 3) > 0
                    || bccomp($row['truck_return_damaged_quantity'], $truck, 3) > 0
                    || ! $this->hasAtMostTwoDecimals($accepted)) {
                    throw ValidationException::withMessages(['lines' => 'All goods must be accounted for; damage is included in received or truck quantities. Customer quantities support two decimal places.']);
                }
                $outcomeLine = $outcome->items()->where('delivery_item_id', $line->id)->lockForUpdate()->firstOrFail();
                $outcomeLine->forceFill([
                    'customer_received_quantity' => $accepted,
                    'customer_received_damaged_quantity' => $row['customer_received_damaged_quantity'],
                    'truck_return_quantity' => $truck,
                    'truck_return_damaged_quantity' => $row['truck_return_damaged_quantity'],
                    'declared_unaccounted_quantity' => $row['unaccounted_quantity'],
                ])->save();
                $remaining = $truck;
                foreach ($outcomeLine->movements()->with('stockMovement')->lockForUpdate()->get() as $source) {
                    $quantity = bccomp($remaining, (string) $source->stockMovement->quantity, 3) < 0 ? $remaining : (string) $source->stockMovement->quantity;
                    $source->forceFill(['declared_quantity' => $quantity])->save();
                    $remaining = bcsub($remaining, $quantity, 3);
                }
                $line->forceFill(['customer_received_quantity' => $accepted])->save();
            }
            $outcome->forceFill(['reason_code' => $normalized['reason_code'], 'notes' => $normalized['notes'], 'version' => $version + 1])->save();
            app(ReturnCaseService::class)->createDeliveryAttemptDamageCase($locked, $outcome, $by, true);
            $this->recordRevision($outcome, $by, $key, $fingerprint, 'report_correction', $reason, $before);
            return $this->deliveries->show($locked);
        }, 3);
    }

    /** An additional physical recovery never reopens earlier QC or overwrites a receipt. */
    public function receiveLate(Delivery $delivery, User $by, array $data): Delivery
    {
        $normalized = $this->normalizeReceipt((int) $delivery->id, $data);
        $key = strtolower((string) $data['request_key']);
        $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($delivery, $by, $normalized, $key, $fingerprint): Delivery {
            [$locked, $outcome] = $this->lockOutcome($delivery);
            if ($this->replayRevision($outcome, $key, $fingerprint)) {
                return $this->deliveries->show($locked);
            }
            if (! $outcome->reconciled_at || ! in_array($locked->status, [DeliveryStatus::Returned, DeliveryStatus::Delivered, DeliveryStatus::Confirmed], true)) {
                throw new BusinessRuleException('Complete the first depot count before recording late-found goods.');
            }
            if (! $normalized['variance_reason']) {
                throw ValidationException::withMessages(['variance_reason' => 'Explain where and when these goods were found.']);
            }
            $before = $this->snapshot($outcome);
            $lines = $outcome->items()->lockForUpdate()->get()->keyBy('delivery_item_id');
            $total = '0.000';
            foreach ($normalized['lines'] as $lineId => $quantity) {
                $line = $lines->get($lineId);
                if (! $line || bccomp($quantity, (string) $line->unaccounted_quantity, 3) > 0) {
                    throw ValidationException::withMessages(['lines' => 'A recovery must belong to this shipment and cannot exceed its still-unaccounted quantity.']);
                }
                $line->forceFill([
                    'warehouse_received_quantity' => bcadd((string) $line->warehouse_received_quantity, $quantity, 3),
                    'unaccounted_quantity' => bcsub((string) $line->unaccounted_quantity, $quantity, 3),
                ])->save();
                $remaining = $quantity;
                foreach ($line->movements()->with('stockMovement')->lockForUpdate()->get() as $source) {
                    $capacity = bcsub(bcsub((string) $source->stockMovement->quantity, (string) ($source->received_quantity ?? '0'), 3), (string) ($source->customer_received_quantity ?? '0'), 3);
                    $part = bccomp($remaining, $capacity, 3) < 0 ? $remaining : $capacity;
                    $source->forceFill(['received_quantity' => bcadd((string) ($source->received_quantity ?? '0'), $part, 3)])->save();
                    $remaining = bcsub($remaining, $part, 3);
                }
                if (bccomp($remaining, '0', 3) !== 0) {
                    throw new BusinessRuleException('The original shipment does not have enough traceable stock for this recovery.');
                }
                $total = bcadd($total, $quantity, 3);
            }
            if (bccomp($total, '0', 3) <= 0) {
                throw ValidationException::withMessages(['lines' => 'Enter at least one physically recovered unit.']);
            }
            $locationId = HashIdFilter::decode((string) $normalized['quarantine_location_id'], WarehouseLocation::class);
            if (! $locationId) {
                throw ValidationException::withMessages(['quarantine_location_id' => 'Choose an active quarantine location.']);
            }
            $rma = app(ReturnRequestService::class)->createTruckReturnFromAttemptOutcome(
                $outcome, $by, $locationId, $key, $fingerprint, $normalized['variance_reason'],
            );
            $outcome->forceFill(['version' => $outcome->version + 1, 'return_request_id' => $outcome->return_request_id ?? $rma->id])->save();
            $this->recordRevision($outcome, $by, $key, $fingerprint, 'late_recovery', $normalized['variance_reason'], $before, (int) $rma->id);
            app(DeliveryCostRecognitionService::class)->reconcileUnaccounted($outcome->fresh(), $key, $by);
            return $this->deliveries->show($locked);
        }, 3);
    }

    /** Always lock order → delivery → outcome, matching dispatch and normal receipt. */
    private function lockOutcome(Delivery $delivery): array
    {
        $orderId = Delivery::query()->whereKey($delivery->id)->value('sales_order_id');
        if ($orderId) {
            SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
        }
        $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
        if ((int) $locked->sales_order_id !== (int) $orderId) {
            throw new BusinessRuleException('The source order changed. Reload this delivery.');
        }
        $outcome = DeliveryAttemptOutcome::query()->where('delivery_id', $locked->id)->lockForUpdate()->first();
        if (! $outcome) {
            throw new BusinessRuleException('Report the delivery attempt before recording a correction or recovery.');
        }
        return [$locked, $outcome];
    }

    private function snapshot(DeliveryAttemptOutcome $outcome): array
    {
        return [
            'version' => $outcome->version,
            'reason_code' => $outcome->reason_code->value,
            'notes' => $outcome->notes,
            'lines' => $outcome->items()->with('deliveryItem')->get()->map(static fn ($line): array => [
                'delivery_item_id' => $line->deliveryItem->hash_id,
                'customer_received_quantity' => $line->customer_received_quantity,
                'customer_received_damaged_quantity' => $line->customer_received_damaged_quantity,
                'truck_return_quantity' => $line->truck_return_quantity,
                'truck_return_damaged_quantity' => $line->truck_return_damaged_quantity,
                'declared_unaccounted_quantity' => $line->declared_unaccounted_quantity,
                'warehouse_received_quantity' => $line->warehouse_received_quantity,
                'unaccounted_quantity' => $line->unaccounted_quantity,
            ])->all(),
        ];
    }

    private function replayRevision(DeliveryAttemptOutcome $outcome, string $key, string $fingerprint): bool
    {
        $revision = \App\Modules\SupplyChain\Models\DeliveryAttemptRevision::query()->where('request_key', $key)->first();
        if (! $revision) {
            return false;
        }
        if ((int) $revision->delivery_attempt_outcome_id !== (int) $outcome->id || ! hash_equals($revision->payload_fingerprint, $fingerprint)) {
            throw new BusinessRuleException('This correction request key was already used for a different action.');
        }
        return true;
    }

    private function recordRevision(DeliveryAttemptOutcome $outcome, User $by, string $key, string $fingerprint, string $kind, string $reason, array $before, ?int $rmaId = null): void
    {
        \App\Modules\SupplyChain\Models\DeliveryAttemptRevision::create([
            'delivery_attempt_outcome_id' => $outcome->id,
            'request_key' => $key, 'payload_fingerprint' => $fingerprint, 'kind' => $kind,
            'reason' => $reason, 'before_snapshot' => $before, 'after_snapshot' => $this->snapshot($outcome),
            'created_by' => $by->id, 'return_request_id' => $rmaId,
        ]);
    }

    /** @param array<string, mixed> $data @return array{reason_code:string,notes:?string,lines:array<int,array<string,string>>} */
    private function normalizeReport(int $deliveryId, array $data): array
    {
        $lines = [];
        foreach ((array) ($data['lines'] ?? []) as $index => $row) {
            $id = HashIdFilter::decode((string) ($row['delivery_item_id'] ?? ''), DeliveryItem::class);
            if (! $id || isset($lines[$id])) {
                throw ValidationException::withMessages(["lines.$index.delivery_item_id" => 'Choose a valid, unique line from this delivery.']);
            }
            $lines[$id] = [
                'customer_received_quantity' => $this->quantity($row['customer_received_quantity'] ?? null, "lines.$index.customer_received_quantity"),
                'customer_received_damaged_quantity' => $this->quantity($row['customer_received_damaged_quantity'] ?? null, "lines.$index.customer_received_damaged_quantity"),
                'truck_return_quantity' => $this->quantity($row['truck_return_quantity'] ?? null, "lines.$index.truck_return_quantity"),
                'truck_return_damaged_quantity' => $this->quantity($row['truck_return_damaged_quantity'] ?? null, "lines.$index.truck_return_damaged_quantity"),
                'unaccounted_quantity' => $this->quantity($row['unaccounted_quantity'] ?? null, "lines.$index.unaccounted_quantity"),
            ];
        }
        ksort($lines);

        return [
            'delivery_id' => $deliveryId,
            'reason_code' => DeliveryAttemptReason::from((string) $data['reason_code'])->value,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'lines' => $lines,
        ];
    }

    /** @param array<string, mixed> $data @return array{delivery_id:int,quarantine_location_id:?string,variance_reason:?string,lines:array<int,string>} */
    private function normalizeReceipt(int $deliveryId, array $data): array
    {
        $lines = [];
        foreach ((array) ($data['lines'] ?? []) as $index => $row) {
            $id = HashIdFilter::decode((string) ($row['delivery_item_id'] ?? ''), DeliveryItem::class);
            if (! $id || isset($lines[$id])) {
                throw ValidationException::withMessages(["lines.$index.delivery_item_id" => 'Choose a valid, unique line from this delivery.']);
            }
            $lines[$id] = $this->quantity($row['received_quantity'] ?? null, "lines.$index.received_quantity");
        }
        ksort($lines);
        $total = array_reduce($lines, static fn (string $sum, string $quantity): string => bcadd($sum, $quantity, 3), '0.000');

        return [
            'delivery_id' => $deliveryId,
            'quarantine_location_id' => bccomp($total, '0', 3) > 0 && ! empty($data['quarantine_location_id'])
                ? (string) $data['quarantine_location_id'] : null,
            'variance_reason' => trim((string) ($data['variance_reason'] ?? '')) ?: null,
            'lines' => $lines,
        ];
    }

    private function quantity(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw ValidationException::withMessages([$field => 'Enter a non-negative quantity with at most three decimal places.']);
        }
        $raw = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,3})?$/D', $raw)) {
            throw ValidationException::withMessages([$field => 'Enter a non-negative quantity with at most three decimal places.']);
        }
        return bcadd($raw, '0', 3);
    }

    private function hasAtMostTwoDecimals(string $quantity): bool
    {
        return bccomp($quantity, bcadd($quantity, '0', 2), 3) === 0;
    }

    private function notifyWarehouseOfAttempt(Delivery $delivery, DeliveryAttemptOutcome $outcome): void
    {
        $recipients = User::query()->where('is_active', true)
            ->whereHas('role.permissions', static fn ($query) => $query->where('slug', 'return_management.receive'))
            ->get();
        if ($recipients->isEmpty()) {
            return;
        }

        $this->notifications->sendInApp($recipients, 'chain.delivery_attempt_reported', [
            'title' => 'Delivery exception needs a depot count',
            'message' => "Delivery {$delivery->delivery_number} has an unresolved truck return. Record the physical count and variance.",
            'link_to' => '/supply-chain/deliveries/'.$delivery->hash_id,
            'entity_type' => 'delivery',
            'entity_id' => $delivery->hash_id,
        ], 'delivery-attempt-outcome:'.$outcome->id.':reported');
    }

    /** @param array<int,array<string,string>> $rows @return array<int,DeliveryItem> */
    private function lockDeliveryLines(Delivery $delivery, array $rows): array
    {
        $lines = DeliveryItem::query()->where('delivery_id', $delivery->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (count($rows) !== $lines->count()) {
            throw ValidationException::withMessages(['lines' => 'Include one outcome for every delivery line.']);
        }
        foreach ($lines as $id => $line) {
            if (! isset($rows[(int) $id])) {
                throw ValidationException::withMessages(['lines' => 'Include one outcome for every delivery line.']);
            }
        }
        return $lines->all();
    }

    /** @return list<StockMovement> */
    private function originalIssues(DeliveryItem $line, string $shipped): array
    {
        $issues = StockMovement::query()
            ->where('movement_type', 'delivery')
            ->where('reference_type', 'delivery_item')
            ->where('reference_id', $line->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $total = $issues->reduce(fn (string $sum, StockMovement $movement): string => bcadd($sum, (string) $movement->quantity, 3), '0.000');
        if ($issues->isEmpty() || bccomp($total, $shipped, 3) !== 0) {
            throw new BusinessRuleException('This delivery has no complete, traceable stock issue for every shipped unit; reconcile its inventory history before recording an exception.');
        }
        return $issues->all();
    }
}
