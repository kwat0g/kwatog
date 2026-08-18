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
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\CoCService;
use App\Modules\SupplyChain\Enums\DeliveryInvoiceHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Exceptions\DeliveryInvoiceHandoffException;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /** Deliveries in these states reserve the SO line quantity. */
    private const QUANTITY_RESERVING_STATUSES = [
        'scheduled',
        'loading',
        'in_transit',
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
        private readonly NotificationService $notifications,
        private readonly CoCService $coc,
        private readonly TaxPolicyService $taxPolicy,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Delivery::query()->with([
            'salesOrder:id,so_number,customer_id',
            'vehicle:id,plate_number,name',
            'driver:id,name,role_id',
        ]);

        TrashedFilter::apply($q, $filters);

        foreach (['status'] as $f) {
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

    public function show(Delivery $d): Delivery
    {
        $d = $d->load([
            'salesOrder:id,so_number,customer_id',
            'salesOrder.customer:id,name',
            'vehicle:id,plate_number,name,vehicle_type',
            'driver:id,name,role_id',
            'confirmer:id,name,role_id',
            'creator:id,name,role_id',
            'invoice:id,invoice_number,total_amount,status',
            'items.salesOrderItem:id,sales_order_id,product_id,quantity,unit_price',
            'items.inspection:id,inspection_number,stage,status',
            // ADV3 — surface the shipment lot for the detail page.
            'shipmentLot.product:id,part_number,name',
            'shipmentLot.customer:id,name',
            // ADV7 — Proof of Delivery files for the detail page.
            'proofs' => fn ($q) => $q->orderByDesc('created_at'),
            'proofs.uploader:id,name',
        ]);

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
    public function create(array $data, User $by): Delivery
    {
        if (empty($data['items'])) {
            throw new BusinessRuleException('At least one delivery item is required.');
        }

        $salesOrderId = (int) $data['sales_order_id'];

        return DB::transaction(function () use ($salesOrderId, $data, $by) {
            // The SO is the serialization point for all delivery reservations.
            // This prevents two dispatch requests from both observing the same
            // remaining quantity and creating an over-delivery.
            $so = SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            if (! $so) {
                throw new BusinessRuleException('Sales order not found.');
            }

            $requestedByItem = $this->normaliseRequestedQuantities($data['items']);
            $this->assertDeliveryQuantitiesAvailable($so, $requestedByItem);

            $delivery = Delivery::create([
                'delivery_number' => $this->sequences->generate('delivery'),
                'sales_order_id' => $so->id,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'status' => DeliveryStatus::Scheduled->value,
                'scheduled_date' => $data['scheduled_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);

            foreach ($data['items'] as $row) {
                $soItem = SalesOrderItem::query()
                    ->where('id', (int) $row['sales_order_item_id'])
                    ->where('sales_order_id', $so->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $inspectionId = $this->resolveAndValidateInspection(
                    productId: (int) $soItem->product_id,
                    salesOrderId: (int) $so->id,
                    salesOrderItemId: (int) $soItem->id,
                    quantity: (string) $row['quantity'],
                    suppliedInspectionId: $row['inspection_id'] ?? null,
                );

                DeliveryItem::create([
                    'delivery_id' => $delivery->id,
                    'sales_order_item_id' => $soItem->id,
                    'inspection_id' => $inspectionId,
                    'quantity' => (string) $row['quantity'],
                    'unit_price' => (string) $soItem->unit_price,
                ]);
            }

            return $this->show($delivery);
        });
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
        $stage = $inspection?->stage instanceof InspectionStage
            ? $inspection->stage
            : ($inspection ? InspectionStage::tryFrom((string) $inspection->stage) : null);
        $status = $inspection?->status instanceof InspectionStatus
            ? $inspection->status
            : ($inspection ? InspectionStatus::tryFrom((string) $inspection->status) : null);

        if (! $inspection || $stage !== InspectionStage::Outgoing || $status !== InspectionStatus::Passed) {
            throw new BusinessRuleException('The selected inspection is not a passed outgoing inspection.');
        }
        if (! $inspection->work_order_output_id) {
            throw new BusinessRuleException('Legacy product/WO-only inspections cannot authorize a new delivery.');
        }

        $output = WorkOrderOutput::query()->lockForUpdate()->find($inspection->work_order_output_id);
        $workOrder = $output?->workOrder;
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
        $reserved = (string) (DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->where('di.inspection_id', $inspection->id)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', self::QUANTITY_RESERVING_STATUSES)
            ->sum('di.quantity') ?: '0.00');
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

    public function updateStatus(Delivery $d, DeliveryStatus $next, ?string $note = null): Delivery
    {
        return DB::transaction(function () use ($d, $next, $note) {
            $locked = Delivery::query()->lockForUpdate()->find($d->id);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }

            $current = $locked->status instanceof DeliveryStatus
                ? $locked->status
                : DeliveryStatus::from((string) $locked->status);
            if (! $current->canTransitionTo($next)) {
                throw new BusinessRuleException("Cannot transition delivery {$locked->delivery_number} from {$current->value} to {$next->value}.");
            }
            if ($next === DeliveryStatus::Confirmed) {
                throw new BusinessRuleException('Use the delivery confirmation action so proof, invoicing, and SO reconciliation are applied together.');
            }
            if ($next === DeliveryStatus::Cancelled && $current === DeliveryStatus::Delivered) {
                throw new BusinessRuleException('A delivered shipment cannot be cancelled; process a customer return instead.');
            }

            // Serialize cancellation/delivery changes with new reservations and
            // keep the SO quantity ledger derived from delivered deliveries.
            $so = $locked->sales_order_id
                ? SalesOrder::query()->lockForUpdate()->find($locked->sales_order_id)
                : null;
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

            if ($next === DeliveryStatus::Delivered && $so) {
                $this->syncDeliveredQuantities($so);
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

            return $delivery;
        });
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
            ->selectRaw('di.sales_order_item_id AS sales_order_item_id, SUM(di.quantity) AS quantity')
            ->groupBy('di.sales_order_item_id')
            ->pluck('quantity', 'sales_order_item_id')
            ->mapWithKeys(static fn ($quantity, $itemId): array => [(int) $itemId => (string) $quantity])
            ->all();
    }

    /**
     * Quick receipt photo upload — sets the legacy receipt_photo_path AND
     * registers a DeliveryProof row so it counts toward the ADV7 proof
     * requirement for confirmation.
     */
    public function uploadReceiptPhoto(Delivery $d, UploadedFile $file, ?User $by = null): Delivery
    {
        // P3.2 — Store the file BEFORE opening the transaction so that a DB
        // rollback cannot orphan a file that was written inside the transaction.
        // If the transaction fails we delete the file and re-throw.
        $path = $file->store("deliveries/{$d->id}", 'local');
        if ($path === false) {
            throw new BusinessRuleException('Unable to store receipt photo.');
        }

        try {
            return DB::transaction(function () use ($d, $file, $path, $by) {
                // Re-read the delivery under the same lock used by status
                // transitions. The route-bound model may be stale (for example,
                // the shipment could have been cancelled while the upload was
                // being prepared).
                $locked = Delivery::query()->lockForUpdate()->find($d->id);
                if (! $locked) {
                    throw new BusinessRuleException('Delivery not found.');
                }

                $current = $locked->status instanceof DeliveryStatus
                    ? $locked->status
                    : DeliveryStatus::from((string) $locked->status);
                if (! in_array($current, [DeliveryStatus::Delivered, DeliveryStatus::Confirmed], true)) {
                    throw new BusinessRuleException('Receipt photo can only be uploaded after delivery is marked delivered.');
                }

                $locked->forceFill(['receipt_photo_path' => $path])->save();

                // ADV7 — also register the legacy upload as a DeliveryProof so the
                // confirmation guard sees it. Falls back to the delivery creator
                // if no user is supplied.
                DeliveryProof::create([
                    'delivery_id' => $locked->id,
                    'proof_type' => 'photo',
                    'file_name' => $file->getClientOriginalName() ?: basename($path),
                    'file_path' => $path,
                    'file_size' => $file->getSize() ?: null,
                    'mime_type' => $file->getMimeType(),
                    'uploaded_by' => $by?->id ?? $locked->created_by,
                    'notes' => 'Quick receipt photo',
                ]);

                return $this->show($locked);
            });
        } catch (\Throwable $e) {
            // Clean up the already-stored file so we don't leave orphans.
            Storage::disk('local')->delete($path);
            throw $e;
        }
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
    public function confirm(Delivery $d, User $by, array $receiverData = []): Delivery
    {
        $current = $d->status instanceof DeliveryStatus ? $d->status : DeliveryStatus::from((string) $d->status);
        if ($current !== DeliveryStatus::Delivered && $current !== DeliveryStatus::Confirmed) {
            throw new BusinessRuleException('Only delivered deliveries can be confirmed.');
        }

        return DB::transaction(function () use ($d, $by, $receiverData) {
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

            // ADV7 — Block confirmation without proof. This is the legally
            // defensible record for any future customer dispute.
            if ($locked->proofs()->count() === 0) {
                throw new BusinessRuleException('At least one proof of delivery (signed DR or photo) must be uploaded before confirming.');
            }

            $so = $locked->sales_order_id
                ? SalesOrder::query()->lockForUpdate()->find($locked->sales_order_id)
                : null;
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

            // M-20 — Auto-attach CoC for each passed outgoing inspection linked
            // to this delivery. Best-effort; never blocks confirm.
            try {
                $this->attachCertificatesOfConformance($locked, $by);
            } catch (\Throwable $e) {
                Log::warning('CoC auto-attach failed on delivery confirm', [
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
                $invoiceId = $this->createDraftInvoice($locked, $by);
                if ($invoiceId) {
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
    private function attachCertificatesOfConformance(Delivery $delivery, User $by): void
    {
        $delivery->loadMissing('items');

        $inspectionIds = $delivery->items
            ->pluck('inspection_id')
            ->filter()
            ->unique()
            ->values();

        if ($inspectionIds->isEmpty()) {
            return;
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
        }
    }

    private function createDraftInvoice(Delivery $d, User $by): ?int
    {
        $svc = app(InvoiceService::class);
        $d->loadMissing(['salesOrder.customer', 'items.salesOrderItem.product']);
        if (! $d->salesOrder?->customer) {
            throw new DeliveryInvoiceHandoffException(
                'The confirmed delivery has no linked customer.',
            );
        }

        try {
            $defaultCode = $this->settings->requiredString('accounting.default_sales_revenue_account_code');
        } catch (\Throwable $e) {
            throw new DeliveryInvoiceHandoffException(
                'The default sales revenue account is not configured.',
                0,
                $e,
            );
        }
        $defaultAccountId = Account::query()->where('code', $defaultCode)->value('id');

        $customerHashId = app('hashids')->encode($d->salesOrder->customer_id);
        $hashids = app('hashids');

        $items = $d->items->map(function (DeliveryItem $i) use ($defaultAccountId, $hashids) {
            $revenueId = $i->salesOrderItem?->product?->revenue_account_id
                ?? $defaultAccountId;

            if (! $revenueId) {
                throw new DeliveryInvoiceHandoffException('Default revenue account not configured.');
            }

            return [
                'revenue_account_id' => $hashids->encode((int) $revenueId),
                'source_delivery_item_id' => $i->id,
                'description' => $i->salesOrderItem?->product?->name ?? 'Delivery line',
                'quantity' => (string) $i->quantity,
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

            $paths = DeliveryProof::query()
                ->where('delivery_id', $locked->id)
                ->pluck('file_path')
                ->push($locked->receipt_photo_path)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $locked->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
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
            ->selectRaw('di.sales_order_item_id, SUM(di.quantity) AS qty')
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
