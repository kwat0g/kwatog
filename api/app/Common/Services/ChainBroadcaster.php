<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Support\ChainDefinitions;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Models\Delivery;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C4. Central helper for broadcasting chain record changes.
 *
 * Domain services call broadcastFor() inside the transaction that owns a
 * chain record's lifecycle or status write. The action distinguishes create,
 * update, deletion/restoration, and workflow status transitions. The mapping
 * between Eloquent class and chain entity-type slug lives here so individual
 * services don't need to know the slug. OutboxService defers queue publication
 * until the outermost commit.
 *
 * Durable staging failures are logged and rethrown: a status mutation must
 * not commit without its canonical chain evidence. Queue/Reverb outages do
 * not reach this boundary — OutboxService only attempts queue publication
 * after commit and leaves the row recoverable when delivery is unavailable.
 */
class ChainBroadcaster
{
    /** @var array<class-string<Model>, string> */
    private const CLASS_TO_TYPE = [
        SalesOrder::class => 'sales_order',
        WorkOrder::class => 'work_order',
        PurchaseRequest::class => 'purchase_request',
        PurchaseOrder::class => 'purchase_order',
        Delivery::class => 'delivery',
        GoodsReceiptNote::class => 'grn',
        Bill::class => 'bill',
        Invoice::class => 'invoice',
    ];

    /** @var array<class-string<Model>, string> */
    private const DOC_NUMBER_FIELD = [
        SalesOrder::class => 'so_number',
        WorkOrder::class => 'wo_number',
        PurchaseRequest::class => 'pr_number',
        PurchaseOrder::class => 'po_number',
        Delivery::class => 'delivery_number',
        GoodsReceiptNote::class => 'grn_number',
        Bill::class => 'bill_number',
        Invoice::class => 'invoice_number',
    ];

    /** @return class-string<Model>|null */
    public static function entityClassFor(string $entityType): ?string
    {
        $class = array_search($entityType, self::CLASS_TO_TYPE, true);

        return $class === false ? null : $class;
    }

    /**
     * Stage a content-free ChainStepAdvanced event for a chain record change.
     * $newStatus remains the authoritative workflow status; $action describes
     * the write that triggered this refresh. Call from the owning transaction
     * so the outbox row is committed atomically and published later.
     *
     * Returns true when the durable row is staged. Unsupported model classes
     * and outbox/database failures throw so the owning transaction can roll
     * back rather than commit an untracked chain change.
     */
    public function broadcastFor(
        Model $entity,
        string $newStatus,
        ?User $actor = null,
        array $additionalDriverIds = [],
        string $action = 'status_changed',
    ): bool
    {
        try {
            $cls = $entity::class;
            $type = self::CLASS_TO_TYPE[$cls] ?? null;
            if ($type === null) {
                throw new \InvalidArgumentException(
                    "ChainBroadcaster does not support model class {$cls}."
                );
            }

            $hashId = method_exists($entity, 'getHashIdAttribute')
                ? (string) $entity->hash_id
                : (string) $entity->getKey();

            $docField = self::DOC_NUMBER_FIELD[$cls] ?? null;
            $docNumber = $docField !== null
                ? (string) ($entity->{$docField} ?? '')
                : (string) $entity->getKey();

            [$active, $completed] = ChainDefinitions::resolveStrict($type, $newStatus);
            $driverIds = $type === 'delivery'
                ? array_values(array_unique(array_filter([
                    (int) ($entity->getAttribute('driver_id') ?? 0),
                    ...array_map('intval', $additionalDriverIds),
                ])))
                : [];
            $driverHashIds = array_map(
                static fn (int $driverId): string => (string) app('hashids')->encode($driverId),
                $driverIds,
            );
            $customerId = match ($type) {
                'sales_order', 'invoice' => $entity->getAttribute('customer_id'),
                'delivery' => $this->deliveryCustomerId($entity),
                default => null,
            };
            $customerHashId = $customerId !== null
                ? (string) app('hashids')->encode((int) $customerId)
                : null;
            $supplierVendorId = match (true) {
                $entity instanceof PurchaseOrder, $entity instanceof Bill => $entity->getAttribute('vendor_id'),
                $entity instanceof GoodsReceiptNote => $entity->getAttribute('vendor_id')
                    ?? $entity->purchaseOrder()->value('vendor_id'),
                default => null,
            };

            $event = new ChainStepAdvanced(
                entityType: $type,
                entityHashId: $hashId,
                docNumber: $docNumber,
                newStatus: $newStatus,
                activeStep: $active,
                completedSteps: $completed,
                actorName: $actor?->name,
                driverHashIds: $driverHashIds,
                customerHashId: $customerHashId,
                supplierVendorHashId: $supplierVendorId !== null
                    ? (string) app('hashids')->encode((int) $supplierVendorId)
                    : null,
                relatedReturnCaseHashIds: $entity instanceof SalesOrder
                    ? $this->relatedCustomerReturnCaseHashIds($entity)
                    : [],
                relatedPurchaseOrderHashIds: $entity instanceof Bill || $entity instanceof GoodsReceiptNote
                    ? $this->relatedPurchaseOrderHashIds($entity)
                    : [],
                relatedPurchaseRequestHashIds: $entity instanceof PurchaseOrder || $entity instanceof Bill || $entity instanceof GoodsReceiptNote
                    ? $this->relatedPurchaseRequestHashIds($entity)
                    : [],
                relatedGrnHashIds: $entity instanceof Bill && $entity->getAttribute('goods_receipt_note_id') !== null
                    ? [(string) app('hashids')->encode((int) $entity->getAttribute('goods_receipt_note_id'))]
                    : [],
                action: $action,
                actorHashId: $this->actorHashId($actor),
                version: $this->versionFor($entity),
            );

            $chain = in_array($type, ['purchase_request', 'purchase_order', 'grn', 'bill'], true)
                ? 'p2p'
                : 'o2c';
            // Database timestamps can share second-level precision across
            // distinct writes. A refresh ping is idempotent for consumers, so
            // dedupe by its unique event ID rather than collapsing rapid writes
            // such as draft edit → delete into one signal.
            $dedupeKey = 'chain-step:'.$event->eventId;

            // Realtime delivery is now durable too. If Reverb is unavailable,
            // the outbox worker retries the broadcast without affecting the
            // already-committed business transition.
            app(OutboxService::class)->recordForChain(
                $event,
                $entity,
                $chain,
                $type,
                $active,
                $dedupeKey,
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('ChainBroadcaster durable staging failed', [
                'class' => $entity::class,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stage a content-free SalesOrder refresh without recording a synthetic
     * chain-step transition. Use when a related module changes fields embedded
     * by the SalesOrder API while leaving its business status unchanged.
     */
    public function refreshSalesOrder(SalesOrder $order): void
    {
        try {
            $status = $order->getAttribute('status');
            $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
            [$active, $completed] = ChainDefinitions::resolveStrict('sales_order', $status);
            $customerId = $order->getAttribute('customer_id');
            $customerHashId = $customerId === null
                ? null
                : (string) app('hashids')->encode((int) $customerId);
            $event = new ChainStepAdvanced(
                entityType: 'sales_order',
                entityHashId: (string) $order->hash_id,
                docNumber: (string) $order->so_number,
                newStatus: $status,
                activeStep: $active,
                completedSteps: $completed,
                customerHashId: $customerHashId,
                relatedReturnCaseHashIds: $this->relatedCustomerReturnCaseHashIds($order),
                action: 'updated',
                version: $this->versionFor($order),
            );

            // This is a refresh notification, not a new chain transition, so
            // deliberately omit recordForChain() and its step-run ledger row.
            app(OutboxService::class)->record($event, 'chain-refresh:'.$event->eventId);
        } catch (\Throwable $e) {
            Log::error('SalesOrder related-record refresh staging failed', [
                'sales_order_id' => $order->getKey(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stage a content-free work-order refresh when related resource state
     * changes without advancing the work-order chain status. No chain-step
     * ledger row is written because the workflow state itself is unchanged.
     */
    public function refreshWorkOrder(WorkOrder $workOrder): void
    {
        try {
            $status = $workOrder->getAttribute('status');
            $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
            [$active, $completed] = ChainDefinitions::resolveStrict('work_order', $status);
            $event = new ChainStepAdvanced(
                entityType: 'work_order',
                entityHashId: (string) $workOrder->hash_id,
                docNumber: (string) $workOrder->wo_number,
                newStatus: $status,
                activeStep: $active,
                completedSteps: $completed,
                action: 'updated',
                version: $this->versionFor($workOrder),
            );

            app(OutboxService::class)->record($event, 'chain-refresh:'.$event->eventId);
        } catch (\Throwable $e) {
            Log::error('WorkOrder related-record refresh staging failed', [
                'work_order_id' => $workOrder->getKey(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stage a content-free Purchase Order refresh when linked data changes
     * without advancing the PO workflow status. No chain-step ledger row is
     * written because the PO business status itself is unchanged.
     */
    public function refreshPurchaseOrder(PurchaseOrder $purchaseOrder, bool $coalesceForTransaction = false): void
    {
        try {
            $status = $purchaseOrder->getAttribute('status');
            $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
            [$active, $completed] = ChainDefinitions::resolveStrict('purchase_order', $status);
            $vendorId = $purchaseOrder->getAttribute('vendor_id');
            $event = new ChainStepAdvanced(
                entityType: 'purchase_order',
                entityHashId: (string) $purchaseOrder->hash_id,
                docNumber: (string) $purchaseOrder->po_number,
                newStatus: $status,
                activeStep: $active,
                completedSteps: $completed,
                supplierVendorHashId: $vendorId === null
                    ? null
                    : (string) app('hashids')->encode((int) $vendorId),
                relatedPurchaseRequestHashIds: $this->relatedPurchaseRequestHashIds($purchaseOrder),
                action: 'updated',
                version: $this->versionFor($purchaseOrder),
            );

            if ($coalesceForTransaction
                && DB::transactionLevel() > 0
                && DB::connection()->getDriverName() === 'pgsql') {
                $dedupeKey = 'chain-refresh:purchase-order:'.$purchaseOrder->getKey().':'.DB::scalar('select txid_current()');
                app(OutboxService::class)->recordCoalesced($event, $dedupeKey);
            } else {
                app(OutboxService::class)->record($event, 'chain-refresh:'.$event->eventId);
            }
        } catch (\Throwable $e) {
            Log::error('PurchaseOrder related-record refresh staging failed', [
                'purchase_order_id' => $purchaseOrder->getKey(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function actorHashId(?User $actor): ?string
    {
        return $actor?->getKey() === null
            ? null
            : (string) app('hashids')->encode((int) $actor->getKey());
    }

    private function versionFor(Model $entity): string
    {
        $updatedAt = $entity->getAttribute('updated_at');

        if ($updatedAt instanceof DateTimeInterface || (is_string($updatedAt) && trim($updatedAt) !== '')) {
            return Carbon::parse($updatedAt)->utc()->toIso8601String();
        }

        return now()->toISOString();
    }

    private function deliveryCustomerId(Delivery $delivery): ?int
    {
        $salesOrder = $delivery->relationLoaded('salesOrder')
            ? $delivery->getRelation('salesOrder')
            : $delivery->salesOrder()->first(['id', 'customer_id']);

        return $salesOrder?->customer_id === null ? null : (int) $salesOrder->customer_id;
    }

    /** @return list<string> */
    private function relatedCustomerReturnCaseHashIds(SalesOrder $order): array
    {
        return ReturnCase::query()
            ->where('type', 'customer')
            ->whereHas('delivery', static fn ($query) => $query->where('sales_order_id', $order->getKey()))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) app('hashids')->encode((int) $id))
            ->all();
    }

    /**
     * Purchase Request details embed linked PO, GRN, and Bill status.
     * Hash IDs are used only to target those already-authorized detail channels;
     * they are omitted from the broadcast payload.
     *
     * @return list<string>
     */
    private function relatedPurchaseOrderHashIds(Bill|GoodsReceiptNote $entity): array
    {
        $purchaseOrderId = $entity->getAttribute('purchase_order_id');

        return $purchaseOrderId === null
            ? []
            : [(string) app('hashids')->encode((int) $purchaseOrderId)];
    }

    /**
     * @return list<string>
     */
    private function relatedPurchaseRequestHashIds(PurchaseOrder|Bill|GoodsReceiptNote $entity): array
    {
        if ($entity instanceof PurchaseOrder) {
            $purchaseRequestId = $entity->getAttribute('purchase_request_id');
        } else {
            $purchaseOrderId = $entity->getAttribute('purchase_order_id');
            $purchaseOrder = $entity->relationLoaded('purchaseOrder')
                ? $entity->getRelation('purchaseOrder')
                : null;
            $purchaseRequestId = $purchaseOrder?->getAttribute('purchase_request_id');
            if ($purchaseRequestId === null && $purchaseOrderId !== null) {
                $purchaseRequestId = PurchaseOrder::query()
                    ->whereKey($purchaseOrderId)
                    ->value('purchase_request_id');
            }
        }

        return $purchaseRequestId === null
            ? []
            : [(string) app('hashids')->encode((int) $purchaseRequestId)];
    }
}
