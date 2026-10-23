<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderStockAllocation;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\CRM\Services\SalesOrderSupplyService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\CoCService;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\SupplyChain\Enums\DeliveryCocHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryCostingMode;
use App\Modules\SupplyChain\Enums\DeliveryInvoiceHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryProofType;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Enums\DeliveryStockReservationStatus;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Exceptions\DeliveryInvoiceHandoffException;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\DeliveryReschedule;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sprint 7 — Task 66. Outbound delivery lifecycle.
 *
 *   create()              — opens delivery only for items that passed outgoing QC
 *   updateStatus()        — enforces forward-only transitions, stamps timestamps
 *   uploadReceiptPhoto()  — stores driver's receipt photo on `delivered`
 *   confirm()             — CRM officer marks confirmed; auto-creates draft invoice
 */
class DeliveryService
{
    public const INVOICE_HANDOFF_MANUAL_MESSAGE =
        'Automatic invoice creation needs Accounting review. Fix the accounting setup, then replay this handoff or create the invoice manually.';

    public const COC_HANDOFF_MANUAL_MESSAGE =
        'The Certificate of Conformance could not be attached automatically. Fix the Quality evidence or document storage, then retry the CoC handoff.';

    /** Deliveries in these states reserve the SO line quantity. */
    private const QUANTITY_RESERVING_STATUSES = [
        'scheduled',
        'loading',
        'in_transit',
        'return_pending',
        'delivered',
        'confirmed',
    ];

    /** Deliveries in these states count toward the physical delivered total. */
    private const QUANTITY_DELIVERED_STATUSES = [
        'delivered',
        'confirmed',
    ];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly AccountingAccountPolicyService $accountPolicies,
        private readonly NotificationService $notifications,
        private readonly CoCService $coc,
        private readonly TaxPolicyService $taxPolicy,
        private readonly DeliveryStockService $stock,
        private readonly ShipmentLotService $shipmentLots,
        private readonly DeliveryStockReservationService $stockReservations,
        private readonly DeliveryCostRecognitionService $costRecognition,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Delivery::query()->with([
            'salesOrder:id,so_number,customer_id',
            // DeliveryResource resolves the ship-to from the customer when the
            // delivery carries no snapshot; eager-load it so the list stays two
            // queries instead of one per row.
            'salesOrder.customer:id,name,address,contact_person,phone,delivery_address,delivery_contact_person,delivery_contact_phone',
            'vehicle:id,plate_number,name',
            'driver:id,name,role_id',
        ]);

        TrashedFilter::apply($q, $filters);

        foreach (['status', 'invoice_handoff_status'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['sales_order_id'])) {
            // DeliveryController::index() forwards the raw query bag. A (int) cast
            // on a hash yields 0, so the list came back empty instead of filtered.
            $q->where('sales_order_id', HashIdFilter::decode($filters['sales_order_id'], SalesOrder::class) ?? 0);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b->where('delivery_number', SearchOperator::like(), $term));
        }

        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    /** Narrow, searchable order choices for dispatchers without CRM access. */
    public function formOptions(array $filters): array
    {
        $query = SalesOrder::query()
            ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
            ->with('customer:id,name');
        if (! empty($filters['search'])) {
            $query->where('so_number', SearchOperator::like(), '%'.trim($filters['search']).'%');
        }
        $orders = (clone $query)->orderByDesc('id')->paginate(50);
        $mapOrder = static fn (SalesOrder $order): array => [
            'id' => $order->hash_id,
            'so_number' => $order->so_number,
            'customer' => $order->customer ? ['name' => $order->customer->name] : null,
        ];
        $selected = ! empty($filters['sales_order_id'])
            ? SalesOrder::query()->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
                ->with(['customer:id,name', 'items.product:id,part_number,name,unit_of_measure'])
                ->find($filters['sales_order_id']) : null;
        $selectedData = null;
        if ($selected) {
            $reserved = $this->deliveryQuantitiesByItem((int) $selected->id, self::QUANTITY_RESERVING_STATUSES);
            $selectedData = $mapOrder($selected) + [
                'items' => $selected->items->map(static fn (SalesOrderItem $line): array => [
                    'id' => $line->hash_id,
                    'quantity' => (string) $line->quantity,
                    'remaining_quantity' => bcsub((string) $line->quantity, $reserved[$line->id] ?? '0', 2),
                    'product' => $line->product ? [
                        'part_number' => $line->product->part_number,
                        'name' => $line->product->name,
                        'unit_of_measure' => $line->product->unit_of_measure,
                    ] : null,
                ])->all(),
            ];
        }

        return [
            'sales_orders' => $orders->getCollection()->map($mapOrder)->all(),
            'selected_sales_order' => $selectedData,
            'has_more' => $orders->hasMorePages(),
        ];
    }

    /**
     * Return passed outgoing inspections that can authorize a manual delivery
     * for the selected sales order. The remaining capacity is calculated from
     * the same reservation statuses used by create(), so the form cannot offer
     * an inspection that has already been fully reserved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inspectionOptions(int $salesOrderId): array
    {
        $inspections = Inspection::query()
            ->outgoingReleaseCandidates()
            ->whereHas('workOrderOutput.workOrder', fn (Builder $q) => $q
                ->where('sales_order_id', $salesOrderId)
                ->whereNotNull('sales_order_item_id'))
            ->with([
                'ncr',
                'product:id,part_number,name',
                'resultAuthors',
                'workOrderOutput',
                'workOrderOutput.workOrder',
                'workOrderOutput.workOrder.salesOrderItem:id,sales_order_id,product_id',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        if ($inspections->isEmpty()) {
            return [];
        }

        $reservedByInspection = collect($this->inspectionReservedByInspection($inspections->pluck('id')->all()));

        return $inspections
            ->map(function (Inspection $inspection) use ($reservedByInspection): ?array {
                if (! $inspection->canReleaseOutgoing()) {
                    return null;
                }
                $reserved = $reservedByInspection->get($inspection->id, '0.000');
                $remaining = bcsub((string) $inspection->accepted_quantity, $reserved, 2);

                if (bccomp($remaining, '0.00', 2) <= 0) {
                    return null;
                }

                $workOrder = $inspection->workOrderOutput?->workOrder;
                $salesOrderItem = $workOrder?->salesOrderItem;

                return [
                    'id' => $inspection->hash_id,
                    'inspection_number' => $inspection->inspection_number,
                    'sales_order_item_id' => $salesOrderItem?->hash_id,
                    'work_order_number' => $workOrder?->wo_number,
                    'product' => $inspection->product ? [
                        'part_number' => $inspection->product->part_number,
                        'name' => $inspection->product->name,
                    ] : null,
                    'accepted_quantity' => (int) $inspection->accepted_quantity,
                    'remaining_quantity' => $remaining,
                    'completed_at' => optional($inspection->completed_at)->toISOString(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function show(Delivery $d): Delivery
    {
        $d = $d->load([
            'salesOrder:id,so_number,customer_id',
            // Chain 1 — the driver's destination is resolved from these columns
            // (Delivery::resolvedDeliveryAddress), so they must be here rather
            // than lazy-loaded once per row.
            'salesOrder.customer:id,name,address,contact_person,phone,delivery_address,delivery_contact_person,delivery_contact_phone',
            'vehicle:id,plate_number,name,vehicle_type',
            'driver:id,name,role_id',
            'confirmer:id,name,role_id',
            'creator:id,name,role_id',
            'invoice:id,invoice_number,total_amount,status',
            'items.salesOrderItem:id,sales_order_id,product_id,quantity,unit_price',
            'items.salesOrderItem.product:id,part_number,name,unit_of_measure',
            'items.stockMovement',
            'items.stockMovements.fromLocation.zone.warehouse',
            'items.inspection.ncr',
            'items.inspection.resultAuthors',
            'items.inspection.workOrderOutput.workOrder',
            'items.inspection.workOrderOutput.productionReceiptMovement',
            // ADV3 — surface the shipment lot for the detail page.
            'shipmentLot.product:id,part_number,name',
            'shipmentLot.customer:id,name',
            // ADV7 — Proof of Delivery files for the detail page.
            'proofs' => fn ($q) => $q->withTrashed()->orderByDesc('created_at'),
            'proofs.uploader:id,name',
            // Reschedule history for the detail page.
            'reschedules' => fn ($q) => $q->orderByDesc('created_at'),
            'reschedules.rescheduledBy:id,name',
            'quantityDiscrepancy.resolver:id,name',
            'blockingReturnCase',
            'attemptOutcome.reporter:id,name',
            'attemptOutcome.receiver:id,name',
            'attemptOutcome.quarantineLocation.zone.warehouse',
            'attemptOutcome.returnRequest:id,rma_number,status',
            'attemptOutcome.items.movements.stockMovement',
            'attemptOutcome.items.deliveryItem',
            'stockReservationBatch.reservations.location.zone.warehouse',
            'stockReservationBatch.reservations.item',
            'stockReservationBatch.reservations.deliveryItem',
            'costHandoffs',
        ]);

        if (in_array($d->status, [DeliveryStatus::Scheduled, DeliveryStatus::Loading], true)) {
            $d->setRelation('preparation', collect($this->stock->preparation($d)));
        }

        return $d;
    }

    /**
     * Create a delivery for selected SO items, all of which must have a
     * passed outgoing-QC inspection in our books.
     *
     * @param array{
     *   sales_order_id: int,
     *   vehicle_id?: int|null,
     *   driver_id?: int|null,
     *   scheduled_date: string,
     *   notes?: string|null,
     *   items: array<int, array{
     *     sales_order_item_id: int,
     *     quantity: float|string,
     *     inspection_id?: int|null
     *   }>
     * } $data
     */
    public function create(array $data, User $by, ?string $idempotencyKey = null): Delivery
    {
        if (empty($data['items'])) {
            throw new BusinessRuleException('At least one delivery item is required.');
        }

        $salesOrderId = (int) $data['sales_order_id'];
        $idempotencyKey = $this->normaliseIdempotencyKey($idempotencyKey);
        $fingerprint = $idempotencyKey !== null
            ? $this->deliveryFingerprint($salesOrderId, $data, $by->id)
            : null;

        try {
            return DB::transaction(function () use ($salesOrderId, $data, $by, $idempotencyKey, $fingerprint) {
                if ($idempotencyKey !== null) {
                    $existing = Delivery::withTrashed()
                        ->where('created_by', $by->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        if ($existing->trashed()) {
                            throw new BusinessRuleException('This idempotency key belongs to an archived delivery. Use a new key.');
                        }
                        if (! hash_equals((string) $existing->idempotency_fingerprint, (string) $fingerprint)) {
                            throw new BusinessRuleException('The idempotency key was already used for a different delivery payload.');
                        }

                        return $this->show($existing);
                    }
                }

                // The SO is the serialization point for all delivery reservations.
                // This prevents two dispatch requests from both observing the same
                // remaining quantity and creating an over-delivery.
                $so = SalesOrder::query()->lockForUpdate()->find($salesOrderId);
                if (! $so) {
                    throw new BusinessRuleException('Sales order not found.');
                }

                $requestedByItem = $this->normaliseRequestedQuantities($data['items']);
                $this->assertDeliveryQuantitiesAvailable($so, $requestedByItem);

                $this->assertDispatchAssignmentAvailable(
                    isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
                    isset($data['driver_id']) ? (int) $data['driver_id'] : null,
                    (string) $data['scheduled_date'],
                );

                $delivery = Delivery::create([
                    'delivery_number' => $this->sequences->generate('delivery'),
                    'sales_order_id' => $so->id,
                    'vehicle_id' => $data['vehicle_id'] ?? null,
                    'driver_id' => $data['driver_id'] ?? null,
                    'status' => DeliveryStatus::Scheduled->value,
                    'cost_recognition_mode' => DeliveryCostingMode::Transit->value,
                    'scheduled_date' => $data['scheduled_date'],
                    'automatic_supply_key' => $data['automatic_supply_key'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $by->id,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $fingerprint,
                ]);

                $delivery = $this->snapshotShipTo($delivery, $so);

                foreach ($data['items'] as $row) {
                    $soItem = SalesOrderItem::query()
                        ->where('id', (int) $row['sales_order_item_id'])
                        ->where('sales_order_id', $so->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $allocation = ! empty($row['sales_order_stock_allocation_id'])
                        ? SalesOrderStockAllocation::query()->lockForUpdate()->findOrFail($row['sales_order_stock_allocation_id']) : null;
                    if ($allocation) {
                        $inspection = Inspection::query()->lockForUpdate()->findOrFail($row['inspection_id']);
                        app(SalesOrderSupplyService::class)->assertAllocationForDelivery($allocation, $soItem, $inspection, (string) $row['quantity']);
                        $inspectionId = $inspection->id;
                    } else {
                        $inspectionId = $this->resolveAndValidateInspection(
                            productId: (int) $soItem->product_id,
                            salesOrderId: (int) $so->id,
                            salesOrderItemId: (int) $soItem->id,
                            quantity: (string) $row['quantity'],
                            suppliedInspectionId: $row['inspection_id'] ?? null,
                        );
                    }

                    DeliveryItem::create([
                        'delivery_id' => $delivery->id,
                        'sales_order_item_id' => $soItem->id,
                        'inspection_id' => $inspectionId,
                        'sales_order_stock_allocation_id' => $allocation?->id,
                        'quantity' => (string) $row['quantity'],
                        'unit_price' => (string) $soItem->unit_price,
                    ]);
                }

                $reservationKey = Str::uuid()->toString();
                $this->stockReservations->reserveForDelivery($delivery, $by, $reservationKey);

                return $this->show($delivery);
            });
        } catch (QueryException $e) {
            if ($idempotencyKey === null
                || $e->getCode() !== '23505'
                || ! str_contains($e->getMessage(), 'deliveries_creator_idempotency_unique')) {
                throw $e;
            }

            $existing = Delivery::query()
                ->where('created_by', $by->id)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
            if (! hash_equals((string) $existing->idempotency_fingerprint, (string) $fingerprint)) {
                throw new BusinessRuleException('The idempotency key was already used for a different delivery payload.');
            }

            return $this->show($existing);
        }
    }

    /** Identify automatic drafts which have no operational or financial commitment. */
    public function isUntouchedAutomaticSupply(Delivery $delivery): bool
    {
        return $delivery->automatic_supply_key === 'order:'.$delivery->sales_order_id
            && $delivery->status === DeliveryStatus::Scheduled && ! $delivery->driver_id && ! $delivery->vehicle_id
            && ! $delivery->departed_at && ! $delivery->delivered_at && ! $delivery->confirmed_at
            && ! $delivery->invoice()->exists() && ! $delivery->items()->whereNotNull('stock_movement_id')->exists();
    }

    public function reconcileAutomaticSupply(SalesOrder $order, User $actor): array
    {
        return DB::transaction(function () use ($order): array {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $retired = [];
            $blockers = [];
            $supply = app(SalesOrderSupplyService::class);
            foreach (Delivery::where('sales_order_id', $order->id)->whereIn('status', ['scheduled', 'loading'])->lockForUpdate()->get() as $delivery) {
                $invalid = false;
                foreach ($delivery->items()->with(['stockAllocation', 'inspection.ncr', 'inspection.resultAuthors', 'inspection.workOrderOutput.workOrder'])->get() as $line) {
                    if ($line->stockAllocation ? ! $supply->validAllocation($line->stockAllocation) : ! $line->inspection?->canReleaseOutgoing()) {
                        $invalid = true;
                    }
                }
                if (! $invalid) {
                    continue;
                }
                if (! $this->isUntouchedAutomaticSupply($delivery)) {
                    $blockers[] = "ImpEx and Quality must review invalid source stock on {$delivery->delivery_number}.";
                    $audience = User::where('is_active', true)->whereHas('role.permissions', fn ($q) => $q->whereIn('slug', ['mrp.plans.run', 'quality.inspections.review', 'supply_chain.deliveries.create']))->get();
                    $this->notifications->sendInApp($audience, 'mrp_run_completed', [
                        'title' => "Shipment source needs review: {$delivery->delivery_number}", 'message' => end($blockers),
                        'link_to' => "/supply-chain/deliveries/{$delivery->hash_id}", 'entity_type' => 'delivery', 'entity_id' => $delivery->hash_id,
                    ], 'invalid-shipment-source-'.$delivery->id.'-'.$delivery->updated_at->getTimestamp());

                    continue;
                }
                $this->updateStatus($delivery, DeliveryStatus::Cancelled);
                $retired[] = $delivery->id;
            }
            foreach ($order->items()->get() as $line) {
                foreach ($line->stockAllocations()->get() as $allocation) {
                    if (bccomp($allocation->untransferredQuantity(), '0', 3) > 0 && ! $supply->validAllocation($allocation)) {
                        app(StockMovementService::class)->release($allocation->item_id, $allocation->location_id, $allocation->untransferredQuantity());
                        $allocation->forceFill(['released_quantity' => bcadd((string) $allocation->released_quantity, $allocation->untransferredQuantity(), 3), 'status' => 'released'])->save();
                    }
                }
            }

            return ['retired_delivery_ids' => $retired, 'blockers' => $blockers];
        }, 3);
    }

    /** Draft one complete shipment from independently approved production and stock allocations. */
    public function draftOrderSupply(SalesOrder $order, User $actor): ?Delivery
    {
        return DB::transaction(function () use ($order, $actor): ?Delivery {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $supply = app(SalesOrderSupplyService::class);
            $lines = $order->items()->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $coverage = $supply->lineCoverage($line);
                if (bccomp($coverage['ready_quantity'], (string) $line->quantity, 3) < 0) {
                    return null;
                }
            }
            $rows = [];
            foreach ($lines as $line) {
                $reservedLine = $this->deliveryQuantitiesByItem($order->id, self::QUANTITY_RESERVING_STATUSES)[$line->id] ?? '0';
                $remaining = bcsub((string) $line->quantity, $reservedLine, 3);
                foreach ($line->stockAllocations()->orderBy('id')->lockForUpdate()->get() as $allocation) {
                    if (! $supply->validAllocation($allocation)) {
                        continue;
                    }
                    $quantity = bccomp($remaining, $allocation->untransferredQuantity(), 3) < 0 ? $remaining : $allocation->untransferredQuantity();
                    if (bccomp($quantity, '0', 3) <= 0) {
                        continue;
                    }
                    $rows[] = ['sales_order_item_id' => $line->id, 'inspection_id' => $allocation->inspection_id,
                        'sales_order_stock_allocation_id' => $allocation->id, 'quantity' => bcadd($quantity, '0', 2)];
                    $remaining = bcsub($remaining, $quantity, 3);
                }
                $inspections = Inspection::query()->where('stage', 'outgoing')->where('status', '!=', 'cancelled')
                    ->whereHas('workOrderOutput.workOrder', fn ($q) => $q->where('sales_order_item_id', $line->id)->whereNull('parent_wo_id'))
                    ->with(['ncr', 'resultAuthors', 'workOrderOutput.workOrder'])->orderByDesc('id')->lockForUpdate()->get()->unique('work_order_output_id');
                $used = $this->inspectionReservedByInspection($inspections->pluck('id')->all());
                foreach ($inspections as $inspection) {
                    if (! $inspection->canReleaseOutgoing()) {
                        continue;
                    }
                    $free = bcsub((string) $inspection->accepted_quantity, $used[$inspection->id] ?? '0', 3);
                    $quantity = bccomp($free, $remaining, 3) < 0 ? $free : $remaining;
                    if (bccomp($quantity, '0', 3) <= 0) {
                        continue;
                    }
                    $rows[] = ['sales_order_item_id' => $line->id, 'inspection_id' => $inspection->id, 'quantity' => bcadd($quantity, '0', 2)];
                    $remaining = bcsub($remaining, $quantity, 3);
                }
                if (bccomp($remaining, '0', 3) > 0) {
                    return null;
                }
            }
            if ($rows === []) {
                return null;
            }

            return $this->create(['sales_order_id' => $order->id,
                'scheduled_date' => $lines->min(fn ($line) => $line->delivery_date?->toDateString()) ?? today()->toDateString(),
                'automatic_supply_key' => 'order:'.$order->id,
                'notes' => "Auto-drafted from approved supply for {$order->so_number}.", 'items' => $rows], $actor);
        }, 3);
    }

    /** Reconcile released output lots into an unassigned draft without changing dispatched shipments. */
    public function draftReleasedWorkOrder(WorkOrder $workOrder): ?Delivery
    {
        if ($workOrder->sales_order_id && SalesOrderItem::where('sales_order_id', $workOrder->sales_order_id)->where('fulfillment_policy', 'stock_first')->exists()) {
            return $this->draftOrderSupply(SalesOrder::findOrFail($workOrder->sales_order_id), User::findOrFail($workOrder->created_by));
        }

        return DB::transaction(function () use ($workOrder): ?Delivery {
            $so = SalesOrder::query()->lockForUpdate()->find($workOrder->sales_order_id);
            $wo = WorkOrder::query()->lockForUpdate()->find($workOrder->id);
            if (! $so || ! $wo || (int) $wo->sales_order_id !== (int) $so->id) {
                return null;
            }
            $soItem = SalesOrderItem::query()->whereKey($wo->sales_order_item_id)
                ->where('sales_order_id', $so->id)->lockForUpdate()->first();
            if (! $soItem) {
                throw new BusinessRuleException("Work order {$wo->wo_number} has no valid sales-order line.");
            }
            $inspections = Inspection::query()->outgoingReleaseCandidates()
                ->whereHas('workOrderOutput', fn (Builder $q) => $q->where('work_order_id', $wo->id))
                ->with(['ncr', 'resultAuthors', 'workOrderOutput.workOrder'])->orderBy('id')->lockForUpdate()->get();
            $reserved = $this->inspectionReservedByInspection($inspections->pluck('id')->all());
            $rows = [];
            foreach ($inspections as $inspection) {
                if (! $inspection->canReleaseOutgoing()) {
                    continue;
                }
                $quantity = bcsub((string) $inspection->accepted_quantity, $reserved[$inspection->id] ?? '0', 2);
                if (bccomp($quantity, '0', 3) <= 0) {
                    continue;
                }
                $rows[] = ['sales_order_item_id' => $soItem->id, 'inspection_id' => $inspection->id, 'quantity' => $quantity];
            }
            $draft = Delivery::query()->where('sales_order_id', $so->id)->where('status', DeliveryStatus::Scheduled->value)
                ->whereNull('vehicle_id')->whereNull('driver_id')
                ->where('notes', 'like', "Auto-drafted from WO {$wo->wo_number} %")
                ->whereDoesntHave('items', fn (Builder $q) => $q->whereNotNull('stock_movement_id'))
                ->orderBy('id')->lockForUpdate()->first();
            if ($rows === []) {
                if (! $draft || $draft->stockReservationBatch()->exists()) {
                    return null;
                }
                $this->stockReservations->ensureForDelivery($draft, User::findOrFail($wo->created_by));

                return $draft;
            }
            $by = User::findOrFail($wo->created_by);
            if (! $draft) {
                return $this->create([
                    'sales_order_id' => $so->id, 'scheduled_date' => Carbon::now()->addDay()->toDateString(),
                    'notes' => "Auto-drafted from WO {$wo->wo_number} on outgoing QC release.", 'items' => $rows,
                ], $by);
            }
            $this->assertDeliveryQuantitiesAvailable($so, $this->normaliseRequestedQuantities($rows));
            foreach ($rows as $row) {
                $this->lockAndValidateInspectionForDelivery((int) $row['inspection_id'], (int) $so->id,
                    (int) $soItem->id, (int) $soItem->product_id, $row['quantity']);
                DeliveryItem::create($row + ['delivery_id' => $draft->id, 'unit_price' => (string) $soItem->unit_price]);
            }
            $this->stockReservations->ensureForDelivery($draft, $by);

            return $this->show($draft);
        }, 3);
    }

    /**
     * Assign the operational driver and vehicle after a delivery draft exists.
     *
     * The delivery, vehicle, and driver are locked in one transaction so a
     * repeated operator request cannot race a dispatch transition or book a
     * vehicle that became unavailable while the form was open.
     *
     * @param  array{vehicle_id:int,driver_id:int,reason:string}  $data
     */
    public function saveShipmentPlan(Delivery $delivery, array $data, User $by, ?UploadedFile $photo = null): Delivery
    {
        $path = $photo?->store("deliveries/{$delivery->id}/proofs", 'local');
        if ($path === false) {
            throw new BusinessRuleException('Unable to store loading photo.');
        }
        try {
            return DB::transaction(function () use ($delivery, $data, $by, $photo, $path): Delivery {
                $salesOrderId = Delivery::query()->whereKey($delivery->id)->value('sales_order_id');
                if ($salesOrderId) {
                    SalesOrder::query()->lockForUpdate()->find($salesOrderId);
                }
                $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
                if (! $locked) {
                    throw new BusinessRuleException('Delivery not found.');
                }
                if (! in_array($locked->status, [DeliveryStatus::Scheduled, DeliveryStatus::Loading], true)) {
                    throw new BusinessRuleException('Shipment details cannot change after dispatch.');
                }
                $reason = trim((string) $data['reason']);
                $newDate = Carbon::parse($data['scheduled_date'])->toDateString();
                if ($locked->status === DeliveryStatus::Loading) {
                    if ((int) $locked->vehicle_id !== (int) $data['vehicle_id'] || (int) $locked->driver_id !== (int) $data['driver_id']) {
                        throw new BusinessRuleException('A shipment already loading must retain its assigned driver and vehicle.');
                    }
                } else {
                    // Validate the new assignment on the new date, not an obsolete booking.
                    $this->assertDispatchAssignmentAvailable((int) $data['vehicle_id'], (int) $data['driver_id'], $newDate, $locked->id);
                }
                if ($newDate !== $locked->scheduled_date?->toDateString()) {
                    DeliveryReschedule::create([
                        'delivery_id' => $locked->id, 'from_date' => $locked->scheduled_date?->toDateString(),
                        'to_date' => $newDate, 'reason' => $reason, 'rescheduled_by' => $by->id,
                    ]);
                    $locked->forceFill([
                        'original_scheduled_date' => $locked->original_scheduled_date ?? $locked->scheduled_date,
                        'reschedule_count' => (int) $locked->reschedule_count + 1,
                    ]);
                }
                $locked->forceFill([
                    'scheduled_date' => $newDate, 'expected_delivery_date' => $data['expected_delivery_date'],
                    'carrier' => trim((string) $data['carrier']),
                ])->save();
                // Still pre-dispatch, so a corrected ship-to in the customer master
                // must reach the run sheet before the truck leaves.
                $locked = $this->snapshotShipTo($locked);
                if ($locked->status === DeliveryStatus::Scheduled) {
                    $locked = $this->assign($locked, $data, $by);
                }
                if ($photo && $path) {
                    $this->registerProof($locked, $photo, $path, DeliveryProofType::LoadingPhoto, $by, 'Goods loaded before dispatch');
                }
                if ((bool) $data['dispatch']) {
                    $this->assertShipmentReady($locked);
                    if ($locked->status === DeliveryStatus::Scheduled) {
                        $locked = $this->updateStatus($locked, DeliveryStatus::Loading);
                    }

                    return $this->updateStatus($locked, DeliveryStatus::InTransit);
                }

                return $this->show($locked);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Pin the customer's ship-to onto this run sheet.
     *
     * A run sheet is an instruction that was issued, not a live view of master
     * data, so the address is copied onto the delivery instead of being read
     * through the customer at print time. Two rules keep that honest:
     *
     *  - only fields the customer actually carries are copied, so an empty
     *    master value never blanks a snapshot dispatch already relied on;
     *  - it is refreshed while the shipment plan is still editable (draft,
     *    assignment, plan save) and never afterwards, so editing a customer's
     *    address next month cannot rewrite where last month's truck was sent.
     */
    private function snapshotShipTo(Delivery $delivery, ?SalesOrder $salesOrder = null): Delivery
    {
        // Load explicitly rather than relying on an implicit lazy load: the
        // assignment paths arrive here with a freshly locked row that carries no
        // relations, and `Model::preventLazyLoading()` is on outside production.
        $salesOrder ??= $delivery->relationLoaded('salesOrder')
            ? $delivery->salesOrder
            : SalesOrder::query()->find($delivery->sales_order_id);
        $salesOrder?->loadMissing('customer');

        $customer = $salesOrder?->customer;
        if (! $customer) {
            return $delivery;
        }

        $patch = [];
        $candidates = [
            'delivery_address' => $customer->delivery_address,
            'delivery_contact_person' => $customer->delivery_contact_person,
            'delivery_contact_phone' => $customer->delivery_contact_phone,
        ];

        foreach ($candidates as $column => $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '' && trim((string) ($delivery->{$column} ?? '')) !== $value) {
                $patch[$column] = $value;
            }
        }

        if ($patch) {
            $delivery->forceFill($patch)->save();
        }

        return $delivery;
    }

    private function assertShipmentReady(Delivery $delivery): void
    {
        $errors = [];
        if (! $delivery->vehicle_id) {
            $errors['vehicle_id'] = ['Assign a vehicle before dispatch.'];
        }
        if (! $delivery->driver_id) {
            $errors['driver_id'] = ['Assign a driver before dispatch.'];
        }
        if (! trim((string) $delivery->carrier)) {
            $errors['carrier'] = ['Enter the carrier before dispatch.'];
        }
        if (! $delivery->expected_delivery_date) {
            $errors['expected_delivery_date'] = ['Enter the expected arrival date.'];
        }
        if (! $delivery->scheduled_date || $delivery->scheduled_date->isAfter(today())) {
            $errors['scheduled_date'] = ['Dispatch is available on the shipment date. Save the plan for a future shipment.'];
        }
        if ($delivery->expected_delivery_date && $delivery->scheduled_date && $delivery->expected_delivery_date->isBefore($delivery->scheduled_date)) {
            $errors['expected_delivery_date'] = ['Expected arrival cannot precede the shipment date.'];
        }
        $photo = $delivery->proofs()->where('proof_type', DeliveryProofType::LoadingPhoto->value)->get();
        if (! $photo->contains(fn ($proof) => Storage::disk('local')->exists($proof->file_path))) {
            $errors['loading_photo'] = ['Attach a loading photo before dispatch.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function assign(Delivery $delivery, array $data, User $by): Delivery
    {
        return DB::transaction(function () use ($delivery, $data, $by): Delivery {
            $salesOrderId = Delivery::query()->whereKey($delivery->id)->value('sales_order_id');
            if ($salesOrderId) {
                SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            }
            $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }

            $status = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);
            if ($status !== DeliveryStatus::Scheduled) {
                throw new BusinessRuleException('Only scheduled deliveries can be assigned or reassigned.');
            }
            if ((int) $locked->sales_order_id !== (int) $salesOrderId) {
                throw new BusinessRuleException('The delivery order changed. Reload before assigning the shipment.');
            }

            $this->assertDispatchAssignmentAvailable(
                (int) $data['vehicle_id'],
                (int) $data['driver_id'],
                $locked->scheduled_date->toDateString(),
                $locked->id,
            );

            $reason = trim((string) $data['reason']);
            $assignmentNote = sprintf(
                '[Assignment %s] Driver and vehicle assigned. Reason: %s',
                now()->toIso8601String(),
                $reason,
            );

            $locked->forceFill([
                'vehicle_id' => (int) $data['vehicle_id'],
                'driver_id' => (int) $data['driver_id'],
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').$assignmentNote),
            ])->save();

            // Assignment is an act of arranging the run, so the ship-to is pinned
            // here too (this route is reachable without the shipment-plan form).
            $locked = $this->snapshotShipTo($locked);

            $this->stockReservations->ensureForDelivery($locked, $by);

            return $this->show($locked);
        });
    }

    /**
     * Move a scheduled delivery to a new date, recording who moved it and why.
     *
     * The delivery row is locked for the duration so a reschedule cannot race a
     * status transition (which would otherwise let a loading/in_transit
     * delivery be moved after dispatch). The original commitment is pinned on
     * the first move only and never overwritten afterwards.
     */
    public function reschedule(Delivery $delivery, string $scheduledDate, string $reason, User $by): Delivery
    {
        return DB::transaction(function () use ($delivery, $scheduledDate, $reason, $by): Delivery {
            $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }

            $status = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);
            if ($status !== DeliveryStatus::Scheduled) {
                throw new BusinessRuleException('Only scheduled deliveries can be rescheduled.');
            }

            $currentDate = $locked->scheduled_date?->toDateString();
            $newDate = Carbon::parse($scheduledDate)->toDateString();
            if ($currentDate === $newDate) {
                throw new BusinessRuleException('The new scheduled date must differ from the current scheduled date.');
            }
            $this->assertDispatchAssignmentAvailable(
                $locked->vehicle_id,
                $locked->driver_id,
                $newDate,
                $locked->id,
            );
            $reason = trim($reason);
            $note = sprintf('[Reschedule %s] %s', now()->toIso8601String(), $reason);

            $locked->forceFill([
                'scheduled_date' => $newDate,
                // Pin the original commitment on the first move only.
                'original_scheduled_date' => $locked->original_scheduled_date?->toDateString() ?? $currentDate,
                'reschedule_count' => ((int) $locked->reschedule_count) + 1,
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').$note),
            ])->save();

            DeliveryReschedule::create([
                'delivery_id' => $locked->id,
                'from_date' => $currentDate,
                'to_date' => $newDate,
                'reason' => $reason,
                'rescheduled_by' => $by->id,
            ]);

            $delivery = $this->show($locked);

            // Series C — Task C4. Stage real-time chain progress with the move;
            // the status is unchanged but the chain event carries the new date.
            app(ChainBroadcaster::class)
                ->broadcastFor($delivery, $status->value, $by);

            return $delivery;
        });
    }

    /**
     * Validate the optional direct-create assignment and the required
     * post-draft assignment. The caller must already be inside a transaction.
     */
    private function assertDispatchAssignmentAvailable(
        ?int $vehicleId,
        ?int $driverId,
        string $scheduledDate,
        ?int $deliveryId = null,
    ): void {
        if ($vehicleId !== null) {
            $vehicle = Vehicle::query()->lockForUpdate()->find($vehicleId);
            if (! $vehicle) {
                throw new BusinessRuleException('Assigned vehicle not found.');
            }
            if ($vehicle->status !== 'available') {
                throw new BusinessRuleException("Vehicle {$vehicle->plate_number} is not available for assignment.");
            }

            $hasActiveDelivery = Delivery::query()
                ->where('vehicle_id', $vehicle->id)
                ->when($deliveryId !== null, fn (Builder $query) => $query->whereKeyNot($deliveryId))
                ->whereIn('status', [
                    DeliveryStatus::Loading->value,
                    DeliveryStatus::InTransit->value,
                    DeliveryStatus::ReturnPending->value,
                ])
                ->exists();
            if ($hasActiveDelivery) {
                throw new BusinessRuleException(
                    "Vehicle {$vehicle->plate_number} is already assigned to another active delivery."
                );
            }
            $hasScheduledDelivery = Delivery::query()
                ->where('vehicle_id', $vehicle->id)
                ->when($deliveryId !== null, fn (Builder $query) => $query->whereKeyNot($deliveryId))
                ->where('status', DeliveryStatus::Scheduled->value)
                ->whereDate('scheduled_date', $scheduledDate)
                ->exists();
            if ($hasScheduledDelivery) {
                throw new BusinessRuleException(
                    "Vehicle {$vehicle->plate_number} is already assigned to another scheduled delivery on that date."
                );
            }
        }

        if ($driverId !== null) {
            $driver = User::query()
                ->lockForUpdate()
                ->whereKey($driverId)
                ->where('is_active', true)
                ->whereHas('role', static fn (Builder $query) => $query->where('slug', 'driver'))
                ->first();
            if (! $driver) {
                throw new BusinessRuleException('Assigned user is not an active driver.');
            }

            $this->assertDriverAvailable($driver->id, $deliveryId);
            $hasScheduledDelivery = Delivery::query()
                ->where('driver_id', $driver->id)
                ->when($deliveryId !== null, fn (Builder $query) => $query->whereKeyNot($deliveryId))
                ->where('status', DeliveryStatus::Scheduled->value)
                ->whereDate('scheduled_date', $scheduledDate)
                ->exists();
            if ($hasScheduledDelivery) {
                throw new BusinessRuleException('Driver is already assigned to another scheduled delivery on that date.');
            }
        }
    }

    private function assertDriverAvailable(int $driverId, ?int $deliveryId = null): void
    {
        $hasActiveDelivery = Delivery::query()
            ->where('driver_id', $driverId)
            ->when($deliveryId !== null, fn (Builder $query) => $query->whereKeyNot($deliveryId))
            ->whereIn('status', [
                DeliveryStatus::Loading->value,
                DeliveryStatus::InTransit->value,
                DeliveryStatus::ReturnPending->value,
            ])
            ->exists();

        if ($hasActiveDelivery) {
            throw new BusinessRuleException('Driver is already assigned to another active delivery.');
        }
    }

    /**
     * For each new delivery line an explicit output-bound passed outgoing
     * inspection is required. Legacy product/WO-only inspections remain
     * readable, but cannot authorize a new delivery.
     */
    private function resolveAndValidateInspection(
        int $productId,
        int $salesOrderId,
        int $salesOrderItemId,
        string $quantity,
        mixed $suppliedInspectionId,
    ): int {
        if (! $suppliedInspectionId) {
            throw new BusinessRuleException('A new delivery requires an explicit output-bound passed outgoing inspection.');
        }

        $inspection = $this->lockAndValidateInspectionForDelivery(
            inspectionId: (int) $suppliedInspectionId,
            salesOrderId: $salesOrderId,
            salesOrderItemId: $salesOrderItemId,
            productId: $productId,
            quantity: $quantity,
        );

        return (int) $inspection->id;
    }

    /**
     * Lock and validate one output-bound inspection, then reserve its accepted
     * quantity against this delivery transaction. The inspection row is the
     * serialization point for competing partial deliveries.
     */
    public function lockAndValidateInspectionForDelivery(
        int $inspectionId,
        int $salesOrderId,
        int $salesOrderItemId,
        int $productId,
        string $quantity,
    ): Inspection {
        $inspection = Inspection::query()->lockForUpdate()->find($inspectionId);
        if (! $inspection) {
            throw new BusinessRuleException('The selected inspection has no approved outgoing release quantity.');
        }
        if (! $inspection->isMakerChecked()) {
            throw new BusinessRuleException('The selected outgoing inspection has not been checked.');
        }
        if (! $inspection->work_order_output_id) {
            throw new BusinessRuleException('Legacy product/WO-only inspections cannot authorize a new delivery.');
        }
        if (! $inspection->canReleaseOutgoing()) {
            throw new BusinessRuleException('The selected inspection has no approved outgoing release quantity.');
        }

        $output = WorkOrderOutput::query()->lockForUpdate()->find($inspection->work_order_output_id);
        $workOrder = $output?->workOrder;
        $workOrder?->assertProcessQualityReleased();
        if (! $output || ! $workOrder
            || (int) $output->work_order_id !== (int) $inspection->entity_id
            || (int) $workOrder->product_id !== $productId
            || (int) $output->good_count !== (int) $inspection->batch_quantity
            || (int) $inspection->product_id !== $productId
            || (int) $workOrder->sales_order_id !== $salesOrderId
            || (int) $workOrder->sales_order_item_id !== $salesOrderItemId) {
            throw new BusinessRuleException('The selected outgoing inspection is not provenance-linked to this sales-order line.');
        }

        $requested = $this->normaliseDeliveryQuantity($quantity);
        $reserved = (string) ($this->inspectionReservedByInspection([(int) $inspection->id])[(int) $inspection->id] ?? '0.000');
        $available = bcsub((string) $inspection->accepted_quantity, $reserved, 2);
        if (bccomp($available, '0', 2) < 0) {
            $available = '0.00';
        }
        if (bccomp($requested, $available, 2) > 0) {
            throw new BusinessRuleException(
                "Delivery quantity for outgoing inspection #{$inspection->inspection_number} exceeds the remaining accepted quantity ({$available})."
            );
        }

        return $inspection;
    }

    public function updateStatus(Delivery $d, DeliveryStatus $next, ?string $note = null, ?User $driver = null): Delivery
    {
        return DB::transaction(function () use ($d, $next, $note, $driver) {
            // Match confirmation and customer intake: SO before delivery.
            $salesOrderId = Delivery::query()->whereKey($d->id)->value('sales_order_id');
            $so = $salesOrderId ? SalesOrder::query()->lockForUpdate()->find($salesOrderId) : null;
            $locked = Delivery::query()->lockForUpdate()->find($d->id);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }

            if ($driver !== null) {
                if ((int) $locked->driver_id !== (int) $driver->id) {
                    abort(404);
                }
                if ($next === DeliveryStatus::Loading && $locked->scheduled_date?->isAfter(today())) {
                    throw ValidationException::withMessages([
                        'status' => ['A future delivery cannot enter loading before its scheduled date.'],
                    ]);
                }
            }

            $current = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);
            if ($next === DeliveryStatus::Cancelled && $current === DeliveryStatus::Delivered) {
                throw new BusinessRuleException('A delivered shipment cannot be cancelled; process a customer return instead.');
            }
            if ($next === DeliveryStatus::Cancelled && $current === DeliveryStatus::InTransit) {
                throw new BusinessRuleException('A dispatched shipment cannot be cancelled while in transit; reconcile its delivery or physical return first.');
            }
            if (in_array($next, [DeliveryStatus::ReturnPending, DeliveryStatus::Returned], true)
                || $current === DeliveryStatus::ReturnPending) {
                throw new BusinessRuleException('Delivery exceptions and depot receipt must use the audited delivery attempt workflow.');
            }
            if (! $current->canTransitionTo($next)) {
                throw new BusinessRuleException("Cannot transition delivery {$locked->delivery_number} from {$current->value} to {$next->value}.");
            }
            if ($next === DeliveryStatus::Confirmed) {
                throw new BusinessRuleException('Use the delivery confirmation action so proof, invoicing, and SO reconciliation are applied together.');
            }

            if ($next === DeliveryStatus::InTransit) {
                $this->assertShipmentReady($locked);
                $this->stockReservations->assertReadyForDeparture($locked);
            }

            if ($next === DeliveryStatus::Delivered && ! $locked->proofs()->whereIn('proof_type', ['photo', 'signed_dr', 'customer_po_confirmation'])->exists()) {
                throw new BusinessRuleException('Upload customer receipt evidence to record delivery.');
            }

            if ($next === DeliveryStatus::Cancelled) {
                $this->stockReservations->releaseForDelivery($locked);
            }

            // Serialize cancellation/delivery changes with new reservations and
            // keep the SO quantity ledger derived from delivered deliveries.
            if ((int) $locked->sales_order_id !== (int) $salesOrderId) {
                throw new BusinessRuleException('The delivery order changed. Reload before updating its status.');
            }
            if ($locked->sales_order_id && ! $so) {
                throw new BusinessRuleException('Sales order not found.');
            }

            // A vehicle is a shared cross-delivery resource. Serialize the
            // activation/release decision on the vehicle row itself; locking
            // only the delivery lets two scheduled shipments both enter
            // loading and then whichever request commits last wins the
            // vehicle status.
            $vehicle = null;
            $activeOtherDelivery = false;
            if ($next === DeliveryStatus::Loading && ! $locked->vehicle_id) {
                throw new BusinessRuleException('A dispatchable vehicle must be assigned before loading.');
            }
            if ($locked->vehicle_id) {
                $vehicle = Vehicle::query()
                    ->lockForUpdate()
                    ->find($locked->vehicle_id);

                if (! $vehicle) {
                    throw new BusinessRuleException('Assigned vehicle not found.');
                }

                $activeOtherDelivery = Delivery::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->whereKeyNot($locked->id)
                    ->whereIn('status', [
                        DeliveryStatus::Loading->value,
                        DeliveryStatus::InTransit->value,
                        DeliveryStatus::ReturnPending->value,
                    ])
                    ->exists();

                if (in_array($next, [DeliveryStatus::Loading, DeliveryStatus::InTransit], true)) {
                    if ($activeOtherDelivery) {
                        throw new BusinessRuleException(
                            "Vehicle {$vehicle->plate_number} is already assigned to another active delivery."
                        );
                    }

                    // Loading is the first active assignment. A transition
                    // from loading to in-transit may retain in_use; a stale
                    // non-available vehicle state is otherwise a manual
                    // reconciliation issue and must not be silently reused.
                    if ($next === DeliveryStatus::Loading && $vehicle->status !== 'available') {
                        throw new BusinessRuleException(
                            "Vehicle {$vehicle->plate_number} is not available for loading."
                        );
                    }
                }
            }

            if ($locked->driver_id && in_array($next, [DeliveryStatus::Loading, DeliveryStatus::InTransit], true)) {
                $driver = User::query()
                    ->lockForUpdate()
                    ->whereKey($locked->driver_id)
                    ->where('is_active', true)
                    ->whereHas('role', static fn (Builder $query) => $query->where('slug', 'driver'))
                    ->first();
                if (! $driver) {
                    throw new BusinessRuleException('Assigned user is not an active driver.');
                }
                $this->assertDriverAvailable($driver->id, $locked->id);
            }

            $patch = ['status' => $next->value];
            $now = now();
            if ($next === DeliveryStatus::InTransit && ! $locked->departed_at) {
                $patch['departed_at'] = $now;
            }
            if ($next === DeliveryStatus::Delivered && ! $locked->delivered_at) {
                $patch['delivered_at'] = $now;
            }
            if ($note) {
                $patch['notes'] = trim(($locked->notes ? $locked->notes."\n" : '').'['.$next->value.'] '.$note);
            }
            $locked->forceFill($patch)->save();

            if ($next === DeliveryStatus::Delivered) {
                $locked->items()->whereNull('customer_received_quantity')->update([
                    'customer_received_quantity' => DB::raw('quantity'),
                ]);
            }

            if ($next === DeliveryStatus::Delivered && $so) {
                $this->syncDeliveredQuantities($so);
            }

            if ($next === DeliveryStatus::InTransit) {
                $this->issueFinishedGoods($locked);
            }

            // Mark the vehicle in-use / available based on transition.
            if ($vehicle) {
                $vehicleStatus = match ($next) {
                    DeliveryStatus::Loading, DeliveryStatus::InTransit => 'in_use',
                    DeliveryStatus::Delivered, DeliveryStatus::Confirmed, DeliveryStatus::Cancelled => 'available',
                    default => null,
                };
                if ($vehicleStatus === 'available' && $activeOtherDelivery) {
                    // A terminal transition on this delivery must not release
                    // a vehicle that is still carrying another active load.
                    $vehicleStatus = 'in_use';
                }
                if ($vehicleStatus) {
                    $vehicle->update(['status' => $vehicleStatus]);
                }
            }

            $delivery = $this->show($locked);

            // Series C — Task C4. Stage real-time chain progress with the
            // lifecycle write; outbox delivery still waits for commit.
            app(ChainBroadcaster::class)
                ->broadcastFor($delivery, $next->value, auth()->user());
            if ($so && in_array($next, [DeliveryStatus::InTransit, DeliveryStatus::Delivered, DeliveryStatus::Cancelled], true)) {
                app(NotificationService::class)->sendPortalInApp(CustomerPortalUser::where('customer_id', $so->customer_id)->where('is_active', true)->get(), 'delivery.'.$next->value, [
                    'title' => $delivery->delivery_number.': '.$next->label(),
                    'message' => match ($next) {
                        DeliveryStatus::InTransit => 'Your order is on its way. Check the delivery details for its schedule.',
                        DeliveryStatus::Delivered => 'Delivery was reported complete. Review received quantities and confirm receipt in your portal.',
                        default => 'This delivery was cancelled. Contact Sales to review the replacement schedule.',
                    },
                    'link_to' => '/portal/customer/deliveries/'.$delivery->hash_id, 'entity_type' => 'delivery', 'entity_id' => $delivery->hash_id,
                ], 'delivery:'.$delivery->id.':'.$next->value);
            }

            return $delivery;
        });
    }

    private function normaliseIdempotencyKey(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/D', $key)) {
            throw new BusinessRuleException('The idempotency key is invalid.');
        }

        return $key;
    }

    private function deliveryFingerprint(int $salesOrderId, array $data, int $actorId): string
    {
        $items = collect($data['items'] ?? [])
            ->map(fn (array $row): array => [
                'sales_order_item_id' => (int) ($row['sales_order_item_id'] ?? 0),
                'quantity' => $this->normaliseDeliveryQuantity($row['quantity'] ?? null),
                'inspection_id' => isset($row['inspection_id']) ? (int) $row['inspection_id'] : null,
            ])
            ->sortBy(fn (array $row): string => sprintf(
                '%010d:%010d:%s',
                $row['sales_order_item_id'],
                (int) ($row['inspection_id'] ?? 0),
                $row['quantity'],
            ))
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'actor_id' => $actorId,
            'sales_order_id' => $salesOrderId,
            'vehicle_id' => isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            'driver_id' => isset($data['driver_id']) ? (int) $data['driver_id'] : null,
            'scheduled_date' => Carbon::parse((string) $data['scheduled_date'])->toDateString(),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Validate and combine requested quantities by SO line.
     *
     * SO quantities and the authoritative quantity_delivered ledger are stored
     * to two decimal places. Rejecting finer-grained delivery quantities here
     * prevents a delivery_items value from being silently rounded when the
     * cross-module ledger is synchronized.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, string>
     */
    private function normaliseRequestedQuantities(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new BusinessRuleException('Each delivery item must be an object.');
            }

            $itemId = (int) ($row['sales_order_item_id'] ?? 0);
            if ($itemId <= 0) {
                throw new BusinessRuleException('Each delivery item must reference a sales-order line.');
            }

            $quantity = $this->normaliseDeliveryQuantity($row['quantity'] ?? null);
            $totals[$itemId] = bcadd($totals[$itemId] ?? '0.00', $quantity, 2);
        }

        return $totals;
    }

    /**
     * Shared reservation gate for the API delivery flow and the outgoing-QC
     * auto-draft listener. The caller must already hold the SO lock; the line
     * locks are acquired here in a deterministic query so duplicate requests
     * cannot race on the same sales-order line.
     *
     * @param  array<int|string, mixed>  $requestedByItem
     */
    public function assertDeliveryQuantitiesAvailable(SalesOrder $so, array $requestedByItem): void
    {
        if ($requestedByItem === []) {
            throw new BusinessRuleException('At least one delivery item is required.');
        }

        // M044 — create() and the outgoing-QC auto-draft listener both lock the
        // sales order and both land here, but neither ever read its STATUS, so a
        // cancelled order still accepted new deliveries: the quantity ledger only
        // asks whether the ordered quantity is still uncommitted, and cancelling
        // an order does not release it. A shipment against a cancelled order
        // reaches confirm(), which raises a customer invoice — goods and a bill
        // for an order the customer already withdrew. This is the one seam both
        // creation paths share, which is why the gate belongs here and not in
        // create() alone.
        $soStatus = $so->status instanceof SalesOrderStatus
            ? $so->status
            : SalesOrderStatus::tryFrom((string) $so->status);
        if ($soStatus === SalesOrderStatus::Cancelled) {
            throw new BusinessRuleException(
                "Sales order {$so->so_number} is cancelled; no further deliveries can be scheduled against it."
            );
        }

        $itemIds = array_values(array_unique(array_map('intval', array_keys($requestedByItem))));
        $items = SalesOrderItem::query()
            ->where('sales_order_id', $so->id)
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $reservedByItem = $this->deliveryQuantitiesByItem(
            (int) $so->id,
            self::QUANTITY_RESERVING_STATUSES,
        );

        foreach ($requestedByItem as $rawItemId => $rawQuantity) {
            $itemId = (int) $rawItemId;
            $item = $items->get($itemId);
            if (! $item) {
                throw new BusinessRuleException("Sales-order line {$itemId} does not belong to sales order {$so->so_number}.");
            }

            $requested = $this->normaliseDeliveryQuantity($rawQuantity);
            $reserved = (string) ($reservedByItem[$itemId] ?? '0.00');
            $available = bcsub((string) $item->quantity, $reserved, 2);
            if (bccomp($available, '0', 2) < 0) {
                $available = '0.00';
            }

            if (bccomp($requested, $available, 2) > 0) {
                throw new BusinessRuleException(
                    "Delivery quantity for sales-order line {$itemId} exceeds the remaining quantity ({$available})."
                );
            }
        }
    }

    /**
     * Rebuild quantity_delivered from non-cancelled deliveries that reached
     * the physical-delivery state. This keeps CRM's SO resource, remaining
     * quantity, and the Supply Chain delivery rows on one source of truth.
     *
     * The SO row must be locked by the caller before this method is invoked.
     */
    public function syncDeliveredQuantities(SalesOrder $so): void
    {
        $items = SalesOrderItem::query()
            ->where('sales_order_id', $so->id)
            ->lockForUpdate()
            ->get();
        $deliveredByItem = $this->deliveryQuantitiesByItem(
            (int) $so->id,
            self::QUANTITY_DELIVERED_STATUSES,
        );

        foreach ($items as $item) {
            $delivered = (string) ($deliveredByItem[(int) $item->id] ?? '0.00');
            $deliveredAtSoPrecision = bcadd($delivered, '0', 2);

            // Do not hide historical or manually-created over-deliveries by
            // clamping them. The transaction must fail until the source rows
            // are corrected through an explicit return/adjustment process.
            if (bccomp($delivered, $deliveredAtSoPrecision, 3) !== 0) {
                throw new BusinessRuleException(
                    "Delivered quantity for sales-order line {$item->id} has more precision than the SO ledger supports."
                );
            }
            if (bccomp($deliveredAtSoPrecision, (string) $item->quantity, 2) > 0) {
                throw new BusinessRuleException(
                    "Delivered quantity for sales-order line {$item->id} exceeds the ordered quantity ({$item->quantity})."
                );
            }

            if (bccomp((string) $item->quantity_delivered, $deliveredAtSoPrecision, 2) !== 0) {
                $item->forceFill(['quantity_delivered' => $deliveredAtSoPrecision])->save();
            }
        }
    }

    private function issueFinishedGoods(Delivery $delivery): void
    {
        $this->stock->issue($delivery);
    }

    private function normaliseDeliveryQuantity(mixed $raw): string
    {
        $value = trim((string) $raw);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) || bccomp($value, '0', 2) <= 0) {
            throw new BusinessRuleException('Delivery quantities must be positive values with at most two decimal places.');
        }

        return bcadd($value, '0', 2);
    }

    /**
     * @param  list<string>  $statuses
     * @return array<int, string>
     */
    private function deliveryQuantitiesByItem(int $salesOrderId, array $statuses): array
    {
        return DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->where('d.sales_order_id', $salesOrderId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', $statuses)
            ->selectRaw("di.sales_order_item_id AS sales_order_item_id, SUM(CASE WHEN d.status IN ('delivered', 'confirmed') THEN COALESCE(di.customer_received_quantity, di.quantity) ELSE di.quantity END) AS quantity")
            ->groupBy('di.sales_order_item_id')
            ->pluck('quantity', 'sales_order_item_id')
            ->mapWithKeys(static fn ($quantity, $itemId): array => [(int) $itemId => (string) $quantity])
            ->all();
    }

    /**
     * Inspection acceptance remains consumed by dispatched goods until a
     * warehouse has physically returned them and the RMA workflow has released
     * them from quarantine back to good stock. A driver declaration or a depot
     * count alone never restores outgoing-QC capacity.
     *
     * @param  list<int>  $inspectionIds
     * @return array<int, string>
     */
    private function inspectionReservedByInspection(array $inspectionIds): array
    {
        if ($inspectionIds === []) {
            return [];
        }

        $issued = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->whereIn('di.inspection_id', $inspectionIds)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', [
                DeliveryStatus::Scheduled->value,
                DeliveryStatus::Loading->value,
                DeliveryStatus::InTransit->value,
                DeliveryStatus::ReturnPending->value,
                DeliveryStatus::Delivered->value,
                DeliveryStatus::Confirmed->value,
                DeliveryStatus::Returned->value,
            ])
            ->select('di.inspection_id')
            ->selectRaw('COALESCE(SUM(di.quantity), 0) AS quantity')
            ->groupBy('di.inspection_id')
            ->pluck('quantity', 'inspection_id');

        $restocked = DB::table('return_request_items as rri')
            ->join('delivery_items as di', 'di.id', '=', 'rri.source_delivery_item_id')
            ->join('return_requests as rr', 'rr.id', '=', 'rri.return_request_id')
            ->join('stock_movements as release_movement', 'release_movement.id', '=', 'rri.quarantine_release_movement_id')
            ->whereIn('di.inspection_id', $inspectionIds)
            ->where('rr.type', 'customer_return')
            ->whereNotNull('rr.delivery_attempt_outcome_id')
            ->where('rr.disposition_status', 'disposed')
            ->whereIn('rr.status', ['inspected', 'completed'])
            ->where('rri.disposition', 'restock')
            ->where('rri.quarantine_status', 'released')
            ->whereNotNull('rri.quarantine_release_movement_id')
            ->where('release_movement.movement_type', 'transfer')
            ->where(fn ($query) => $query
                ->where(fn ($legacy) => $legacy
                    ->where('release_movement.reference_type', 'return_request')
                    ->whereColumn('release_movement.reference_id', 'rr.id'))
                ->orWhere(fn ($mrbRelease) => $mrbRelease
                    ->where('release_movement.reference_type', 'material_review_record')
                    ->whereExists(fn ($mrb) => $mrb->selectRaw('1')
                        ->from('material_review_records as mrb')
                        ->whereColumn('mrb.id', 'release_movement.reference_id')
                        ->whereColumn('mrb.return_request_item_id', 'rri.id')
                        ->whereColumn('mrb.release_movement_id', 'release_movement.id')
                        ->whereIn('mrb.disposition', ['rework', 'use_as_is'])
                        ->where('mrb.status', 'released'))))
            ->whereColumn('release_movement.item_id', 'rri.item_id')
            ->whereColumn('release_movement.from_location_id', 'rri.quarantine_location_id')
            ->whereNotNull('release_movement.to_location_id')
            ->whereColumn('release_movement.quantity', 'rri.stock_movement_quantity')
            ->where('release_movement.quantity', '>', 0)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('inspections as return_inspection')
                    ->whereColumn('return_inspection.entity_id', 'rr.id')
                    ->whereColumn('return_inspection.product_id', 'rri.product_id')
                    ->where('return_inspection.entity_type', 'return_request')
                    ->where('return_inspection.stage', 'customer_return')
                    ->where('return_inspection.status', 'passed')
                    ->whereNotNull('return_inspection.inspector_id')
                    ->whereNotNull('return_inspection.reviewed_by')
                    ->whereNotNull('return_inspection.reviewed_at')
                    ->whereColumn('return_inspection.inspector_id', '<>', 'return_inspection.reviewed_by')
                    ->whereNotExists(function ($authors): void {
                        $authors->selectRaw('1')
                            ->from('inspection_result_authors as result_author')
                            ->whereColumn('result_author.inspection_id', 'return_inspection.id')
                            ->whereColumn('result_author.user_id', 'return_inspection.reviewed_by');
                    });
            })
            ->select('di.inspection_id')
            ->selectRaw('COALESCE(SUM(release_movement.quantity), 0) AS quantity')
            ->groupBy('di.inspection_id')
            ->pluck('quantity', 'inspection_id');

        $reserved = [];
        foreach ($inspectionIds as $id) {
            $quantity = bcsub((string) ($issued[$id] ?? '0'), (string) ($restocked[$id] ?? '0'), 3);
            $reserved[(int) $id] = bccomp($quantity, '0', 3) > 0 ? $quantity : '0.000';
        }

        return $reserved;
    }

    /**
     * Quick receipt photo upload — sets the legacy receipt_photo_path AND
     * registers a DeliveryProof row so it counts toward the ADV7 proof
     * requirement for confirmation.
     */
    public function uploadReceiptPhoto(Delivery $d, UploadedFile $file, ?User $by = null): Delivery
    {
        $this->uploadProof($d, $file, DeliveryProofType::Photo, $by, 'Customer receipt photo');

        return $this->show($d->fresh());
    }

    public function uploadProof(Delivery $delivery, UploadedFile $file, DeliveryProofType $type, ?User $by, ?string $notes = null): DeliveryProof
    {
        $path = $file->store("deliveries/{$delivery->id}/proofs", 'local');
        if ($path === false) {
            throw new BusinessRuleException('Unable to store delivery proof.');
        }
        try {
            return DB::transaction(function () use ($delivery, $file, $type, $by, $notes, $path): DeliveryProof {
                $salesOrderId = Delivery::query()->whereKey($delivery->id)->value('sales_order_id');
                if ($salesOrderId) {
                    SalesOrder::query()->lockForUpdate()->find($salesOrderId);
                }
                $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
                if (! $locked) {
                    throw new BusinessRuleException('Delivery not found.');
                }
                if (in_array($type, [DeliveryProofType::Photo, DeliveryProofType::SignedDeliveryReceipt, DeliveryProofType::CustomerPoConfirmation], true)
                    && (! $by || (int) $locked->driver_id !== (int) $by->id || ! $by->is_active || $by->role?->slug !== 'driver')) {
                    abort(403, 'Only the assigned driver can upload customer receipt evidence.');
                }
                $allowed = $type === DeliveryProofType::LoadingPhoto
                    ? [DeliveryStatus::Scheduled, DeliveryStatus::Loading]
                    : [DeliveryStatus::InTransit, DeliveryStatus::Delivered, DeliveryStatus::Confirmed];
                if (! in_array($locked->status, $allowed, true)) {
                    throw new BusinessRuleException($type === DeliveryProofType::LoadingPhoto
                        ? 'Loading photos must be captured before dispatch.' : 'Customer receipt evidence can only be uploaded after dispatch.');
                }
                if ($locked->status === DeliveryStatus::Confirmed && $type === DeliveryProofType::CertificateOfConformance) {
                    throw new BusinessRuleException('A Certificate of Conformance cannot be replaced after delivery confirmation.');
                }
                $proof = $this->registerProof($locked, $file, $path, $type, $by, $notes);
                if ($type === DeliveryProofType::Photo) {
                    $locked->forceFill(['receipt_photo_path' => $path])->save();
                }
                if ($locked->status === DeliveryStatus::InTransit && in_array($type, [DeliveryProofType::Photo, DeliveryProofType::SignedDeliveryReceipt, DeliveryProofType::CustomerPoConfirmation], true)) {
                    $this->updateStatus($locked, DeliveryStatus::Delivered);
                }

                return $proof->load('uploader')->setRelation('delivery', $locked);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    private function registerProof(Delivery $delivery, UploadedFile $file, string $path, DeliveryProofType $type, ?User $by, ?string $notes): DeliveryProof
    {
        return DeliveryProof::create([
            'delivery_id' => $delivery->id, 'proof_type' => $type->value,
            'file_name' => $file->getClientOriginalName(), 'file_path' => $path,
            'file_size' => $file->getSize(), 'mime_type' => $file->getMimeType(),
            'uploaded_by' => $by?->id ?? $delivery->created_by, 'notes' => $notes,
        ]);
    }

    /**
     * CRM officer confirms delivery → auto-create draft invoice for the SO.
     * Idempotent: if an invoice is already linked, returns it untouched.
     *
     * ADV7 — Proof of Delivery is mandatory: the delivery must have at least
     * one proof file uploaded before it can be confirmed. Optional receiver
     * capture fields (receiver_name, receiver_position, delivery_remarks) may
     * be supplied here to stamp the delivery in a single round-trip.
     *
     * @param array{
     *   receiver_name?: string|null,
     *   receiver_position?: string|null,
     *   delivery_remarks?: string|null,
     * } $receiverData
     */
    public function confirm(Delivery $d, User $by, array $receiverData = [], ?int $acknowledgedByPortalUserId = null): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if ($current !== DeliveryStatus::Delivered && $current !== DeliveryStatus::Confirmed) {
            throw new BusinessRuleException('Only delivered deliveries can be confirmed.');
        }

        return DB::transaction(function () use ($d, $by, $receiverData) {
            // Customer problem intake locks SO → delivery. Use that same
            // order so an operator confirmation cannot deadlock with a report
            // arriving at the billing boundary.
            $salesOrderId = Delivery::query()->whereKey($d->id)->value('sales_order_id');
            $so = $salesOrderId ? SalesOrder::query()->lockForUpdate()->find($salesOrderId) : null;
            // P3.1 — Re-read the delivery under an exclusive row lock so that
            // two concurrent confirm() calls cannot both pass the status check
            // and both write a confirmed state / draft invoice.
            $locked = Delivery::whereKey($d->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }

            $lockedStatus = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);

            // Already confirmed by a concurrent request — no-op.
            if ($lockedStatus === DeliveryStatus::Confirmed) {
                return $this->show($locked);
            }

            if ($lockedStatus !== DeliveryStatus::Delivered) {
                throw new BusinessRuleException('Only delivered deliveries can be confirmed.');
            }

            $this->assertInvoiceQuantityClear($locked);

            // ADV7 — Block confirmation without proof. This is the legally
            // defensible record for any future customer dispute.
            if ($locked->proofs()->whereIn('proof_type', ['photo', 'signed_dr', 'customer_po_confirmation'])->count() === 0) {
                throw new BusinessRuleException('At least one proof of delivery (signed DR or photo) must be uploaded before confirming.');
            }

            if ((int) $locked->sales_order_id !== (int) $salesOrderId) {
                throw new BusinessRuleException('The delivery order changed. Reload before confirming.');
            }
            if ($locked->sales_order_id && ! $so) {
                throw new BusinessRuleException('Sales order not found.');
            }

            $patch = [
                'status' => DeliveryStatus::Confirmed->value,
                'confirmed_at' => now(),
                'confirmed_by' => $by->id,
            ];
            if (! empty($receiverData['receiver_name'])) {
                $patch['receiver_name'] = $receiverData['receiver_name'];
            }
            if (! empty($receiverData['receiver_position'])) {
                $patch['receiver_position'] = $receiverData['receiver_position'];
            }
            if (! empty($receiverData['delivery_remarks'])) {
                $patch['delivery_remarks'] = $receiverData['delivery_remarks'];
            }
            if (! $locked->received_at) {
                $patch['received_at'] = now();
            }
            $locked->forceFill($patch)->save();
            // Keep $d in sync with the locked copy so callers see the new state.
            $d->forceFill($patch);

            if ($so) {
                $this->syncDeliveredQuantities($so);
            }

            $this->costRecognition->recognizeCustomerReceipt($locked, $by);

            // M-20 — Persist the lot before attaching CoC evidence. Confirmation
            // remains valid if storage or evidence fails, but the failure is a
            // durable retryable handoff rather than a log-only warning.
            try {
                $this->shipmentLots->ensureForDelivery($locked, $by);
                $attached = $this->attachCertificatesOfConformance($locked, $by);
                $locked->forceFill([
                    'coc_handoff_status' => $attached > 0
                        ? DeliveryCocHandoffStatus::Generated->value
                        : DeliveryCocHandoffStatus::NotRequired->value,
                    'coc_handoff_message' => null,
                    'coc_handoff_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                $locked->forceFill([
                    'coc_handoff_status' => DeliveryCocHandoffStatus::ManualRequired->value,
                    'coc_handoff_message' => self::COC_HANDOFF_MANUAL_MESSAGE,
                    'coc_handoff_at' => now(),
                ])->save();
                Log::error('CoC handoff failed on delivery confirm', [
                    'delivery_id' => $locked->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // C-2 — Promote the parent SO based on delivered coverage. The
            // locked delivery has just been status-flipped inside this txn,
            // so Postgres MVCC sees the in-txn write when we aggregate below.
            if ($locked->sales_order_id) {
                $coverage = $this->computeSalesOrderDeliveryCoverage((int) $locked->sales_order_id);
                $soService = app(SalesOrderService::class);
                if ($coverage === 'full') {
                    $soService->markDelivered((int) $locked->sales_order_id);
                } elseif ($coverage === 'partial') {
                    $soService->markPartiallyDelivered((int) $locked->sales_order_id);
                }
            }

            // Auto-create draft invoice (best-effort — Accounting may be disabled).
            // The delivery remains a valid confirmed business result, but the
            // invoice handoff is now persisted and emitted as a narrow,
            // replayable recovery event when it does not complete.
            $invoiceId = null;
            $invoiceHandoffNeedsRecovery = false;
            try {
                $noCharge = $this->isNoChargeReplacement($locked);
                $invoiceId = $noCharge ? null : $this->createDraftInvoice($locked, $by);
                if ($noCharge) {
                    $locked->forceFill([
                        'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::NotRequired->value,
                        'invoice_handoff_message' => 'Approved no-charge replacement; no customer invoice is due.',
                        'invoice_handoff_at' => now(),
                    ])->save();
                } elseif ($invoiceId) {
                    $locked->forceFill([
                        'invoice_id' => $invoiceId,
                        'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::Generated->value,
                        'invoice_handoff_message' => null,
                        'invoice_handoff_at' => now(),
                    ])->save();
                } else {
                    $invoiceHandoffNeedsRecovery = true;
                    $locked->forceFill([
                        'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::ManualRequired->value,
                        'invoice_handoff_message' => self::INVOICE_HANDOFF_MANUAL_MESSAGE,
                        'invoice_handoff_at' => now(),
                    ])->save();
                }
            } catch (\Throwable $e) {
                // Draft-invoice creation is best-effort (Accounting may be disabled
                // or misconfigured) — never block the delivery confirm. Persist
                // the failure state so it remains visible after the log rotates.
                Log::error('Draft invoice creation failed on delivery confirm', [
                    'delivery_id' => $locked->id,
                    'error' => $e->getMessage(),
                ]);
                $invoiceHandoffNeedsRecovery = true;
                $locked->forceFill([
                    'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::ManualRequired->value,
                    'invoice_handoff_message' => self::INVOICE_HANDOFF_MANUAL_MESSAGE,
                    'invoice_handoff_at' => now(),
                ])->save();
            }

            // The final delivery may create a new invoice after all previous
            // invoices were paid. Reconcile only after that handoff exists;
            // otherwise the SO could close before its final invoice is staged.
            if (! $invoiceHandoffNeedsRecovery && $locked->sales_order_id) {
                app(SalesOrderService::class)
                    ->synchronizeCompletionState((int) $locked->sales_order_id);
            }

            if ($invoiceHandoffNeedsRecovery) {
                // C-1 — surface the failure to AR clerks immediately. The
                // durable request below is the actual retry/recovery path.
                $deliveryForNotify = $locked;
                DB::afterCommit(function () use ($deliveryForNotify): void {
                    try {
                        $this->notifyAutoInvoiceFailure($deliveryForNotify);
                    } catch (\Throwable $notifyError) {
                        Log::warning('Auto-invoice failure notification dispatch failed', [
                            'delivery_id' => $deliveryForNotify->id,
                            'error' => $notifyError->getMessage(),
                        ]);
                    }
                });
            }

            // Task A4 — fan out a DeliveryConfirmed event after commit so
            // listeners (Finance notification, dashboard refresh) only see
            // the persisted state.
            $delivery = $this->show($locked);
            app(OutboxService::class)->recordForChain(
                new DeliveryConfirmed($delivery, $invoiceId),
                $delivery,
                'o2c',
                'delivery',
                DeliveryStatus::Confirmed->value,
            );
            if ($invoiceHandoffNeedsRecovery) {
                app(OutboxService::class)->recordForChain(
                    new DeliveryInvoiceRequested($delivery),
                    $delivery,
                    'o2c',
                    'delivery',
                    'invoice_handoff',
                    'delivery-invoice-request:'.$delivery->id,
                );
            }
            // Series C — Task C4. Stage real-time chain progress atomically
            // with the confirmation and its invoice/SO reconciliation.
            app(ChainBroadcaster::class)
                ->broadcastFor($delivery, DeliveryStatus::Confirmed->value, $by);

            return $delivery;
        });
    }

    /**
     * Retry only the delivery → draft-invoice handoff. The delivery status is
     * already confirmed, so this method never re-runs shipment confirmation,
     * SO quantity reconciliation, or confirmation notifications.
     */
    public function retryInvoiceHandoff(Delivery $d, User $by): Delivery
    {
        try {
            return DB::transaction(function () use ($d, $by): Delivery {
                $locked = Delivery::query()->whereKey($d->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw new BusinessRuleException('Delivery not found.');
                }
                if ($locked->status !== DeliveryStatus::Confirmed) {
                    throw new BusinessRuleException('Only confirmed deliveries can retry the customer invoice handoff.');
                }

                $this->assertInvoiceQuantityClear($locked);
                if ($this->isNoChargeReplacement($locked)) {
                    $locked->forceFill(['invoice_handoff_status' => DeliveryInvoiceHandoffStatus::NotRequired->value,
                        'invoice_handoff_message' => 'Approved no-charge replacement; no customer invoice is due.',
                        'invoice_handoff_at' => now()])->save();

                    return $this->show($locked);
                }

                if ($locked->invoice_id !== null) {
                    $locked->forceFill([
                        'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::Generated->value,
                        'invoice_handoff_message' => null,
                        'invoice_handoff_at' => $locked->invoice_handoff_at ?? now(),
                    ])->save();

                    return $this->show($locked);
                }

                // Recover a rare legacy/crash window where the invoice row was
                // committed before the delivery link was written. Never create
                // a second draft when the delivery already has one by reverse
                // reference.
                $existing = Invoice::query()
                    ->where('delivery_id', $locked->id)
                    ->where('status', '<>', InvoiceStatus::Cancelled->value)
                    ->latest('id')
                    ->first();
                if ($existing) {
                    $locked->forceFill([
                        'invoice_id' => $existing->id,
                        'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::Generated->value,
                        'invoice_handoff_message' => null,
                        'invoice_handoff_at' => now(),
                    ])->save();

                    return $this->show($locked);
                }

                $invoiceId = $this->createDraftInvoice($locked, $by);
                if ($invoiceId === null) {
                    throw new DeliveryInvoiceHandoffException(
                        'The delivery has no customer/accounting data required to create its invoice.',
                    );
                }

                $locked->forceFill([
                    'invoice_id' => $invoiceId,
                    'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::Generated->value,
                    'invoice_handoff_message' => null,
                    'invoice_handoff_at' => now(),
                ])->save();

                return $this->show($locked);
            });
        } catch (DeliveryInvoiceHandoffException|BusinessRuleException $e) {
            // Expected data/configuration failures remain durable and
            // actionable; unexpected infrastructure failures are rethrown
            // untouched so the queue can retry them.
            $this->markInvoiceHandoffManual($d->id);
            throw $e;
        }
    }

    /** Persist the safe operator-facing state for a failed invoice handoff. */
    public function markInvoiceHandoffManual(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== DeliveryStatus::Confirmed || $delivery->invoice_id !== null) {
                return;
            }

            // Repeated failures are the same handoff state. Touching the
            // delivery here invalidates the outbox's published model version
            // and prevents replay after Accounting fixes its configuration.
            if ($this->isNoChargeReplacement($delivery)
                || ($delivery->invoice_handoff_status === DeliveryInvoiceHandoffStatus::ManualRequired
                    && $delivery->invoice_handoff_message === self::INVOICE_HANDOFF_MANUAL_MESSAGE
                    && $delivery->invoice_handoff_at !== null)) {
                return;
            }

            $delivery->forceFill([
                'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::ManualRequired->value,
                'invoice_handoff_message' => self::INVOICE_HANDOFF_MANUAL_MESSAGE,
                'invoice_handoff_at' => now(),
            ])->save();
        });
    }

    /**
     * M-20 — Auto-attach a CoC for each passed outgoing Inspection referenced
     * by this delivery's items. Idempotent: skips inspections that already
     * have a CoC attached to this delivery.
     */
    private function attachCertificatesOfConformance(Delivery $delivery, User $by): int
    {
        $delivery->loadMissing('items');
        $attachedCount = 0;

        $inspectionIds = $delivery->items
            ->filter(static fn ($item): bool => bccomp(
                (string) ($item->customer_received_quantity ?? $item->quantity),
                '0',
                3,
            ) > 0)
            ->pluck('inspection_id')
            ->filter()
            ->unique()
            ->values();

        if ($inspectionIds->isEmpty()) {
            return 0;
        }

        $inspections = Inspection::query()
            ->whereIn('id', $inspectionIds->all())
            ->get()
            ->keyBy('id');

        foreach ($inspectionIds as $inspectionId) {
            $inspection = $inspections->get($inspectionId);
            if (! $inspection) {
                continue;
            }

            $stage = $inspection->stage instanceof InspectionStage
                ? $inspection->stage
                : InspectionStage::from((string) $inspection->stage);
            $status = $inspection->status instanceof InspectionStatus
                ? $inspection->status
                : InspectionStatus::from((string) $inspection->status);

            if ($stage !== InspectionStage::Outgoing || $status !== InspectionStatus::Passed) {
                continue;
            }

            $built = $this->coc->buildBinaryForInspection($inspection, $delivery->delivery_number);
            $cocNumber = $built['coc_number'];

            // Idempotency — file_name is deterministic from coc_number.
            $alreadyAttached = DeliveryProof::query()
                ->where('delivery_id', $delivery->id)
                ->where('proof_type', 'coc')
                ->where('file_name', $built['file_name'])
                ->exists();
            if ($alreadyAttached) {
                $attachedCount++;

                continue;
            }

            $path = "deliveries/{$delivery->id}/proofs/coc-{$cocNumber}.pdf";
            if (! Storage::disk('local')->put($path, $built['contents'])) {
                // Storage fault, not a business rule — deliberately NOT a
                // DeliveryInvoiceHandoffException/BusinessRuleException, both of
                // which this module's catch arms treat as "expected, degrade to
                // manual". A failed write must stay retryable.
                throw new RuntimeException('Unable to store the generated certificate of conformance.');
            }

            try {
                DeliveryProof::create([
                    'delivery_id' => $delivery->id,
                    'proof_type' => 'coc',
                    'file_name' => $built['file_name'],
                    'file_path' => $path,
                    'file_size' => strlen($built['contents']),
                    'mime_type' => 'application/pdf',
                    'uploaded_by' => $by->id,
                    'notes' => "Auto-generated from inspection #{$inspection->inspection_number}",
                ]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
            $attachedCount++;
        }

        return $attachedCount;
    }

    /** Retry only the delivery -> shipment lot -> CoC handoff. */
    public function retryCocHandoff(Delivery $delivery, User $by): Delivery
    {
        try {
            return DB::transaction(function () use ($delivery, $by): Delivery {
                $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
                if (! $locked) {
                    throw new BusinessRuleException('Delivery not found.');
                }
                if ($locked->status !== DeliveryStatus::Confirmed) {
                    throw new BusinessRuleException('Only confirmed deliveries can retry the CoC handoff.');
                }

                $this->shipmentLots->ensureForDelivery($locked, $by);
                $attached = $this->attachCertificatesOfConformance($locked, $by);
                $locked->forceFill([
                    'coc_handoff_status' => $attached > 0
                        ? DeliveryCocHandoffStatus::Generated->value
                        : DeliveryCocHandoffStatus::NotRequired->value,
                    'coc_handoff_message' => null,
                    'coc_handoff_at' => now(),
                ])->save();

                return $this->show($locked);
            });
        } catch (\Throwable $e) {
            $this->markCocHandoffManual($delivery->id);
            throw $e;
        }
    }

    public function markCocHandoffManual(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== DeliveryStatus::Confirmed) {
                return;
            }

            $delivery->forceFill([
                'coc_handoff_status' => DeliveryCocHandoffStatus::ManualRequired->value,
                'coc_handoff_message' => self::COC_HANDOFF_MANUAL_MESSAGE,
                'coc_handoff_at' => now(),
            ])->save();
        });
    }

    private function createDraftInvoice(Delivery $d, User $by): ?int
    {
        $this->assertInvoiceQuantityClear($d);
        $svc = app(InvoiceService::class);
        $d->loadMissing(['salesOrder.customer', 'items.salesOrderItem.product']);
        if (! $d->salesOrder?->customer) {
            throw new DeliveryInvoiceHandoffException(
                'The confirmed delivery has no linked customer.',
            );
        }

        try {
            $defaultAccountId = $this->accountPolicies
                ->controlAccountIdForSetting('accounting.default_sales_revenue_account_code');
        } catch (\Throwable $e) {
            throw new DeliveryInvoiceHandoffException(
                'The default sales revenue account is not configured.',
                0,
                $e,
            );
        }

        $customerHashId = app('hashids')->encode($d->salesOrder->customer_id);
        $hashids = app('hashids');

        $items = $d->items->filter(fn (DeliveryItem $item): bool => bccomp(
            (string) ($item->customer_received_quantity ?? $item->quantity), '0', 3,
        ) > 0)->map(function (DeliveryItem $i) use ($defaultAccountId, $hashids) {
            $revenueId = $i->salesOrderItem?->product?->revenue_account_id
                ?? $defaultAccountId;

            if (! $revenueId) {
                throw new DeliveryInvoiceHandoffException('Default revenue account not configured.');
            }

            return [
                'revenue_account_id' => $hashids->encode((int) $revenueId),
                'source_delivery_item_id' => $i->id,
                'description' => $i->salesOrderItem?->product?->name ?? 'Delivery line',
                'quantity' => (string) ($i->customer_received_quantity ?? $i->quantity),
                'unit_price' => (string) $i->unit_price,
            ];
        })->all();

        $invoice = $svc->create([
            'customer_id' => $customerHashId,
            'date' => now()->toDateString(),
            'is_vatable' => $this->taxPolicy->isVatRegistered(),
            'items' => $items,
            'remarks' => "Auto-generated from delivery {$d->delivery_number}",
            // C-2 — link the invoice back to the parent SO + this delivery so
            // InvoiceService::finalize can promote the SO to 'invoiced'.
            'sales_order_id' => $d->sales_order_id ? app('hashids')->encode((int) $d->sales_order_id) : null,
            'delivery_id' => app('hashids')->encode((int) $d->id),
        ], $by);

        return (int) $invoice->id;
    }

    private function isNoChargeReplacement(Delivery $delivery): bool
    {
        return $delivery->sales_order_id !== null && SalesOrder::query()
            ->whereKey($delivery->sales_order_id)->whereNotNull('return_case_id')->exists();
    }

    private function assertInvoiceQuantityClear(Delivery $delivery): void
    {
        app(ReturnCaseService::class)->assertBillingClear((int) $delivery->id);
    }

    /**
     * C-1 — Notify AR clerks (anyone with accounting.invoices.create) that an
     * auto-invoice attempt failed so they can triage manual invoicing. The
     * exception detail is in Log::error already; the user-visible body is a
     * generic, non-leaky message.
     */
    private function notifyAutoInvoiceFailure(Delivery $d): void
    {
        $recipients = User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'accounting.invoices.create'))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notifications->send($recipients, 'invoice.auto_failed', [
            'title' => "Auto-invoice failed for delivery {$d->delivery_number}",
            'message' => 'Auto-invoice could not be created automatically. Please create the invoice manually.',
            'link_to' => "/supply-chain/deliveries/{$d->hash_id}",
            'entity_type' => 'delivery',
            'entity_id' => $d->hash_id,
        ]);
    }

    public function delete(Delivery $d): void
    {
        DB::transaction(function () use ($d) {
            // Deletion is a reservation-changing operation. Lock the delivery
            // before checking its invoice/status so a stale request cannot
            // delete a row that has just been confirmed or invoiced.
            $locked = Delivery::query()->lockForUpdate()->find($d->id);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }
            if ($locked->invoice_id !== null) {
                throw new BusinessRuleException('Cannot delete a delivery with a linked invoice. Cancel the invoice first.');
            }

            $current = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);
            if ($current === DeliveryStatus::Confirmed) {
                throw new BusinessRuleException('Cannot delete a confirmed delivery (an invoice may be attached).');
            }
            if ($current === DeliveryStatus::Delivered) {
                throw new BusinessRuleException('Cannot delete a delivered shipment; process a customer return instead.');
            }
            if (! in_array($current, [DeliveryStatus::Scheduled, DeliveryStatus::Cancelled], true)) {
                throw new BusinessRuleException('Cannot delete an active delivery after loading; cancel it instead.');
            }

            if ($locked->proofs()->withTrashed()->exists() || $locked->receipt_photo_path) {
                throw new BusinessRuleException('Cannot archive a delivery with proof documents; preserve its evidence.');
            }
            if ($current === DeliveryStatus::Scheduled && $locked->stockReservationBatch()->exists()) {
                throw new BusinessRuleException('Cannot archive a scheduled delivery with stock reservation history; cancel it instead.');
            }

            $this->stockReservations->releaseForDelivery($locked);
            $locked->delete();
        });
    }

    public function restore(Delivery $delivery): void
    {
        DB::transaction(function () use ($delivery): void {
            $locked = Delivery::withTrashed()->lockForUpdate()->find($delivery->id);
            if (! $locked) {
                throw new BusinessRuleException('Archived delivery not found.');
            }
            if (! $locked->trashed()) {
                throw new BusinessRuleException('Only archived deliveries can be restored.');
            }

            $proofPaths = DeliveryProof::withTrashed()
                ->where('delivery_id', $locked->id)
                ->pluck('file_path');
            if ($locked->receipt_photo_path) {
                $proofPaths->push($locked->receipt_photo_path);
            }
            foreach ($proofPaths->filter()->unique() as $path) {
                if (! Storage::disk('local')->exists($path)) {
                    throw new BusinessRuleException('Cannot restore a delivery while one or more proof files are missing.');
                }
            }

            $status = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);
            $reservation = $locked->stockReservationBatch;
            if ($status === DeliveryStatus::Scheduled
                && $reservation
                && $reservation->status !== DeliveryStockReservationStatus::Reserved) {
                throw new BusinessRuleException('Cannot restore this scheduled delivery because its stock reservation was released; create a new delivery.');
            }

            $locked->restore();
        });
    }

    /**
     * C-2 — Compute whether an SO is fully, partially, or not-yet covered by
     * confirmed (or about-to-be-confirmed) deliveries.
     *
     * Returns 'full' | 'partial' | 'none'.
     *
     * The currently-locked delivery is included because we run mid-transaction
     * after its status has been flipped to Confirmed but before commit.
     */
    private function computeSalesOrderDeliveryCoverage(int $salesOrderId): string
    {
        $deliveredByItem = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->where('d.sales_order_id', $salesOrderId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', [
                DeliveryStatus::Confirmed->value,
                DeliveryStatus::Delivered->value,
            ])
            ->selectRaw('di.sales_order_item_id, SUM(COALESCE(di.customer_received_quantity, di.quantity)) AS qty')
            ->groupBy('di.sales_order_item_id')
            ->pluck('qty', 'sales_order_item_id');

        $orderedByItem = DB::table('sales_order_items')
            ->where('sales_order_id', $salesOrderId)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'id');

        if ($orderedByItem->isEmpty()) {
            return 'none';
        }

        $allCovered = true;
        $anyCovered = false;
        foreach ($orderedByItem as $itemId => $orderedQty) {
            $deliveredQty = (string) ($deliveredByItem[$itemId] ?? 0);
            if (bccomp($deliveredQty, '0', 4) > 0) {
                $anyCovered = true;
            }
            if (bccomp($deliveredQty, (string) $orderedQty, 4) < 0) {
                $allCovered = false;
            }
        }

        return $allCovered ? 'full' : ($anyCovered ? 'partial' : 'none');
    }
}
