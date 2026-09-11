<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Modules\ReturnManagement\Enums\ReturnInspectionHandoffStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\ReturnManagement\Events\ReturnRequestUpdated;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Models\ReturnRequestSourceAllocation;
use App\Modules\ReturnManagement\Support\ReturnRequestStateMachine;
use App\Common\Services\ApprovalService;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use App\Common\Services\SystemUserResolver;
use App\Common\Support\HashId;
use App\Common\Support\Money;
use App\Common\Models\ApprovalRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReturnRequestService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly \App\Modules\Inventory\Services\StockMovementService $stockMovements,
        private readonly ApprovalService $approvals,
        private readonly InspectionService $inspections,
        private readonly \App\Modules\Accounting\Services\CreditNoteService $creditNotes,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly \App\Modules\Accounting\Services\AccountingAccountPolicyService $accountPolicies,
        private readonly TaxPolicyService $taxPolicy,
        private readonly NotificationService $notifications,
        private readonly ReturnRequestStateMachine $states,
    ) {}

    /**
     * Generate the next RMA number.
     */
    public function nextRmaNumber(): string
    {
        return $this->sequences->generate('return_request');
    }

    /**
     * Create a new RMA request.
     */
    public function create(array $data, User $by): ReturnRequest
    {
        return DB::transaction(function () use ($data, $by) {
            if (($data['type'] ?? null) === ReturnRequestType::SupplierReturn->value
                && (bool) ($data['finance_only'] ?? false)) {
                throw new BusinessRuleException('Supplier returns must use purchase and receipt lineage; finance-only is a customer-credit policy.');
            }

            $rma = ReturnRequest::create([
                'rma_number'         => $this->nextRmaNumber(),
                'type'               => $data['type'],
                'status'             => ReturnRequestStatus::Draft,
                'finance_only'       => (bool) ($data['finance_only'] ?? false),
                'finance_only_reason'=> $data['finance_only_reason'] ?? null,
                'sales_order_id'     => $data['sales_order_id'] ?? null,
                'invoice_id'         => $data['invoice_id'] ?? null,
                'purchase_order_id'  => $data['purchase_order_id'] ?? null,
                'bill_id'            => $data['bill_id'] ?? null,
                'customer_id'        => $data['customer_id'] ?? null,
                'vendor_id'          => $data['vendor_id'] ?? null,
                'reason_code'        => $data['reason_code'] ?? null,
                'reason_description' => $data['reason_description'] ?? null,
                'customer_notes'     => $data['customer_notes'] ?? null,
                'internal_notes'     => $data['internal_notes'] ?? null,
                'resolution'         => $data['resolution'] ?? null,
                'return_date'        => $data['return_date'] ?? now(),
                'created_by'         => $by->id,
            ]);

            $this->persistItems($rma, (array) ($data['items'] ?? []), true);

            $rma->load('items');
            return $rma;
        });
    }

    /**
     * Customer-scoped returnable source lines for the B2B portal.
     *
     * Unlike the internal `source-options` fetch this resolves a server-side
     * `item_id` for every product line — finished goods are matched
     * `items.code == products.part_number` AND `item_type = finished_good`,
     * the same mapping `WorkOrderOutputService` uses to receive production
     * output. The customer never picks an inventory item, and a product line
     * with no matching finished-goods item is surfaced with `item_id = null`
     * so the SPA can disable it instead of failing later at submit.
     *
     * @return array{customer: array{invoices: mixed, salesOrders: mixed, deliveries: mixed}}
     */
    public function sourceOptionsForCustomer(int $customerId, array $filters = []): array
    {
        $invoiceModels = Invoice::query()
            ->where('customer_id', $customerId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('items')
            ->latest('date')
            ->limit(100)
            ->get();

        $salesOrderModels = SalesOrder::query()
            ->where('customer_id', $customerId)
            ->where('status', '<>', 'cancelled')
            ->with('items')
            ->latest('date')
            ->limit(100)
            ->get();

        $deliveryModels = Delivery::query()
            ->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $customerId))
            ->whereNotIn('status', ['cancelled'])
            ->with(['salesOrder:id,so_number', 'items.salesOrderItem'])
            ->latest('delivered_at')
            ->limit(100)
            ->get();

        $productIds = collect()
            ->merge($invoiceModels->flatMap(fn (Invoice $invoice) => $invoice->items->pluck('product_id')))
            ->merge($salesOrderModels->flatMap(fn (SalesOrder $order) => $order->items->pluck('product_id')))
            ->merge($deliveryModels->flatMap(
                fn (Delivery $delivery) => $delivery->items->map(fn ($line) => $line->salesOrderItem?->product_id),
            ))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $itemMap = $this->finishedGoodItemIdMap($productIds);

        $invoiceReserved = $this->activeAllocationsBySource(
            'invoice_item',
            $this->lineIdsFrom($invoiceModels->flatMap(fn (Invoice $invoice) => $invoice->items)),
        );
        $salesOrderReserved = $this->activeAllocationsBySource(
            'sales_order_item',
            $this->lineIdsFrom($salesOrderModels->flatMap(fn (SalesOrder $order) => $order->items)),
        );
        $deliveryReserved = $this->activeAllocationsBySource(
            'delivery_item',
            $this->lineIdsFrom($deliveryModels->flatMap(fn (Delivery $delivery) => $delivery->items)),
        );

        $invoices = $invoiceModels
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->hash_id,
                'label' => $invoice->invoice_number,
                'sales_order_id' => $invoice->sales_order_id ? HashId::encode((int) $invoice->sales_order_id) : null,
                'lines' => $invoice->items->map(fn ($line): array => [
                    'id' => $line->hash_id,
                    'product_id' => $line->product_id ? HashId::encode((int) $line->product_id) : null,
                    'item_id' => isset($itemMap[(int) $line->product_id]) ? HashId::encode($itemMap[(int) $line->product_id]) : null,
                    'quantity' => (string) $line->quantity,
                    'remaining_quantity' => $this->remainingOnLine((string) $line->quantity, $invoiceReserved[(int) $line->id] ?? '0'),
                    'unit_price' => (string) $line->unit_price,
                    'label' => (string) ($line->description ?: 'Invoice line '.$line->id),
                ])->values(),
            ])->values();

        $salesOrders = $salesOrderModels
            ->map(fn (SalesOrder $order): array => [
                'id' => $order->hash_id,
                'label' => $order->so_number,
                'lines' => $order->items->map(fn ($line): array => [
                    'id' => $line->hash_id,
                    'product_id' => $line->product_id ? HashId::encode((int) $line->product_id) : null,
                    'item_id' => isset($itemMap[(int) $line->product_id]) ? HashId::encode($itemMap[(int) $line->product_id]) : null,
                    'quantity' => (string) $line->quantity_delivered,
                    'remaining_quantity' => $this->remainingOnLine((string) $line->quantity_delivered, $salesOrderReserved[(int) $line->id] ?? '0'),
                    'unit_price' => (string) $line->unit_price,
                    'label' => 'SO line '.$line->id,
                ])->values(),
            ])->values();

        $deliveries = $deliveryModels
            ->map(fn (Delivery $delivery): array => [
                'id' => $delivery->hash_id,
                'label' => $delivery->delivery_number,
                'sales_order_id' => $delivery->sales_order_id ? HashId::encode((int) $delivery->sales_order_id) : null,
                'lines' => $delivery->items->map(function ($line) use ($itemMap, $deliveryReserved): array {
                    $productId = $line->salesOrderItem?->product_id;

                    return [
                        'id' => $line->hash_id,
                        'product_id' => $productId ? HashId::encode((int) $productId) : null,
                        'item_id' => $productId && isset($itemMap[(int) $productId]) ? HashId::encode($itemMap[(int) $productId]) : null,
                        'quantity' => (string) $line->quantity,
                        'remaining_quantity' => $this->remainingOnLine((string) $line->quantity, $deliveryReserved[(int) $line->id] ?? '0'),
                        'unit_price' => (string) $line->unit_price,
                        'label' => 'Delivery line '.$line->id,
                    ];
                })->values(),
            ])->values();

        return [
            'customer' => [
                'invoices' => $invoices,
                'salesOrders' => $salesOrders,
                'deliveries' => $deliveries,
            ],
        ];
    }

    /**
     * Create a draft customer-return RMA from the B2B portal.
     *
     * The portal never selects an inventory item or a unit price: this method
     * resolves the source line's product, finished-goods item and price
     * server-side, pins `type = customer_return`, `customer_id` and
     * `finance_only = false`, and delegates to `create()` so the same source
     * validation and reservation path as the internal form runs. The RMA stays
     * draft — the portal cannot approve its own return.
     *
     * @param  array{items: array<int, array{quantity?: string, reason?: string|null, condition?: string|null, source_invoice_item_id?: int|string|null, source_sales_order_item_id?: int|string|null, source_delivery_item_id?: int|string|null}>, reason_code?: string|null, reason_description?: string|null, customer_notes?: string|null, return_date?: string|null}  $data
     */
    public function createCustomerReturnFromPortal(int $customerId, array $data, ?User $by = null): ReturnRequest
    {
        $by ??= app(SystemUserResolver::class)->user();

        $resolved = [];
        foreach ((array) ($data['items'] ?? []) as $index => $item) {
            $resolved[] = $this->resolvePortalReturnLine($customerId, (int) $index, $item);
        }

        if ($resolved === []) {
            throw new BusinessRuleException('A return needs at least one line item.');
        }

        $salesOrderIds = collect($resolved)->pluck('sales_order_id')->filter()->unique()->values();
        $invoiceIds = collect($resolved)->pluck('invoice_id')->filter()->unique()->values();
        if ($salesOrderIds->count() > 1) {
            throw new BusinessRuleException('Return lines must all come from the same sales order.');
        }
        if ($invoiceIds->count() > 1) {
            throw new BusinessRuleException('Return lines must all come from the same invoice.');
        }

        $rma = $this->create([
            'type'                => ReturnRequestType::CustomerReturn->value,
            'customer_id'         => $customerId,
            'finance_only'        => false,
            'sales_order_id'      => $salesOrderIds->first(),
            'invoice_id'          => $invoiceIds->first(),
            'reason_code'         => $data['reason_code'] ?? null,
            'reason_description'  => $data['reason_description'] ?? null,
            'customer_notes'      => $data['customer_notes'] ?? null,
            'return_date'         => $data['return_date'] ?? now(),
            'items'               => array_map(static fn (array $line): array => [
                'product_id'                  => $line['product_id'],
                'item_id'                     => $line['item_id'],
                'quantity'                    => $line['quantity'],
                'reason'                      => $line['reason'],
                'condition'                   => $line['condition'],
                'source_invoice_item_id'      => $line['source_invoice_item_id'],
                'source_sales_order_item_id'  => $line['source_sales_order_item_id'],
                'source_delivery_item_id'     => $line['source_delivery_item_id'],
            ], $resolved),
        ], $by);

        $this->notifyCustomerReturnCreated($rma);

        return $rma;
    }

    /**
     * Resolve one portal return line's provenance, product, item and price.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resolvePortalReturnLine(int $customerId, int $index, array $item): array
    {
        $quantity = (string) ($item['quantity'] ?? '0');
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new BusinessRuleException("items.{$index}.quantity must be greater than zero.");
        }

        $sources = array_filter([
            'invoice_item' => $item['source_invoice_item_id'] ?? null,
            'sales_order_item' => $item['source_sales_order_item_id'] ?? null,
            'delivery_item' => $item['source_delivery_item_id'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
        if (count($sources) !== 1) {
            throw new BusinessRuleException("items.{$index} must reference exactly one invoice, sales-order, or delivery line.");
        }

        $kind = (string) array_key_first($sources);
        $id = (int) $sources[$kind];

        $line = [
            'product_id'                 => null,
            'item_id'                    => null,
            'quantity'                   => $quantity,
            'reason'                     => $item['reason'] ?? null,
            'condition'                  => $item['condition'] ?? null,
            'source_invoice_item_id'     => null,
            'source_sales_order_item_id' => null,
            'source_delivery_item_id'    => null,
            'sales_order_id'             => null,
            'invoice_id'                 => null,
        ];

        if ($kind === 'invoice_item') {
            $invoiceItem = InvoiceItem::query()->with('invoice')->findOrFail($id);
            $invoice = $invoiceItem->invoice;
            if (! $invoice || (int) $invoice->customer_id !== $customerId) {
                throw new BusinessRuleException("items.{$index} does not belong to this customer.");
            }
            $line['source_invoice_item_id'] = (int) $invoiceItem->id;
            $line['invoice_id'] = (int) $invoice->id;
            $line['sales_order_id'] = $invoice->sales_order_id ? (int) $invoice->sales_order_id : null;
            $line['product_id'] = $invoiceItem->product_id ? (int) $invoiceItem->product_id : null;
        } elseif ($kind === 'sales_order_item') {
            $salesOrderItem = SalesOrderItem::query()->with('salesOrder')->findOrFail($id);
            $order = $salesOrderItem->salesOrder;
            if (! $order || (int) $order->customer_id !== $customerId) {
                throw new BusinessRuleException("items.{$index} does not belong to this customer.");
            }
            $line['source_sales_order_item_id'] = (int) $salesOrderItem->id;
            $line['sales_order_id'] = (int) $order->id;
            $line['product_id'] = $salesOrderItem->product_id ? (int) $salesOrderItem->product_id : null;
        } else {
            $deliveryItem = DeliveryItem::query()->with('salesOrderItem.salesOrder')->findOrFail($id);
            $salesOrderItem = $deliveryItem->salesOrderItem;
            $order = $salesOrderItem?->salesOrder;
            if (! $order || (int) $order->customer_id !== $customerId) {
                throw new BusinessRuleException("items.{$index} does not belong to this customer.");
            }
            $line['source_delivery_item_id'] = (int) $deliveryItem->id;
            $line['sales_order_id'] = (int) $order->id;
            $line['product_id'] = $salesOrderItem?->product_id ? (int) $salesOrderItem->product_id : null;
        }

        if ($line['product_id'] === null) {
            throw new BusinessRuleException("items.{$index} has no product provenance for Quality inspection.");
        }

        $line['item_id'] = $this->finishedGoodItemId((int) $line['product_id']);
        if ($line['item_id'] === null) {
            throw new BusinessRuleException("items.{$index} has no finished-goods inventory item and cannot be returned.");
        }

        return $line;
    }

    /**
     * Map product IDs to the finished-goods inventory item with the matching
     * `items.code == products.part_number`. One product query + one item query,
     * never per line.
     *
     * @param  array<int, mixed>  $productIds
     * @return array<int, int>
     */
    private function finishedGoodItemIdMap(array $productIds): array
    {
        $productIds = array_values(array_filter(array_unique(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $partNumbers = Product::query()->whereIn('id', $productIds)->pluck('part_number', 'id');
        $codes = $partNumbers
            ->filter(static fn ($code): bool => is_string($code) && trim($code) !== '')
            ->values()
            ->all();
        if ($codes === []) {
            return [];
        }

        $itemByCode = Item::query()
            ->whereIn('code', $codes)
            ->where('item_type', ItemType::FinishedGood->value)
            ->pluck('id', 'code');

        $map = [];
        foreach ($partNumbers as $productId => $code) {
            if (is_string($code) && isset($itemByCode[$code])) {
                $map[(int) $productId] = (int) $itemByCode[$code];
            }
        }

        return $map;
    }

    private function finishedGoodItemId(int $productId): ?int
    {
        $map = $this->finishedGoodItemIdMap([$productId]);

        return $map[$productId] ?? null;
    }

    /** @param iterable<object> $lines */
    private function lineIdsFrom(iterable $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = (int) $line->id;
        }

        return array_values(array_unique($ids));
    }

    private function remainingOnLine(string $documentQuantity, string $reserved): string
    {
        $left = bcsub(bcadd($documentQuantity, '0', 3), $reserved, 3);

        return bccomp($left, '0', 3) > 0 ? $left : '0.000';
    }

    private function notifyCustomerReturnCreated(ReturnRequest $rma): void
    {
        try {
            $audience = User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'return_management.manage'))
                ->get();
            if ($audience->isEmpty()) {
                return;
            }

            $this->notifications->send($audience, 'customer.rma_created', [
                'title'       => "RMA {$rma->rma_number} submitted by a customer",
                'message'     => 'A customer return was submitted through the customer portal and is awaiting review.',
                'link_to'     => '/return-management/'.$rma->hash_id,
                'entity_type' => 'return_request',
                'entity_id'   => $rma->hash_id,
                'rma_number'  => $rma->rma_number,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ReturnRequestService::notifyCustomerReturnCreated failed', [
                'rma_id' => $rma->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Open (or return) the single supplier-return RMA for goods whose receipt
     * has already been dealt with physically by the caller.
     *
     * This is the ONE entry point for the three system paths that previously
     * produced no supplier credit at all: an incoming-QC rejected GRN, an NCR
     * closed with `return_to_supplier`, and an MRB released with
     * `return_to_supplier`. The RMA is intentionally draft — finance still
     * walks it through approval — but its lines carry the full PO/GRN lineage
     * so `processSupplierDisposition()` can raise the supplier credit note
     * exactly once when it is disposed.
     *
     * Idempotency is anchored on `source_key` (a unique, caller-supplied key):
     * a redelivered event, a listener retry or an operator double-click returns
     * the existing non-cancelled RMA instead of opening a second one. Because
     * the caller has already removed the goods from the ledger — a rejected
     * receipt never entered stock, an MRB release shipped it out — every line
     * is stamped with its `stock_movement_quantity` so the RMA can never move
     * the same goods a second time.
     *
     * @param array<int, array{
     *     grn_item_id?: int|null,
     *     purchase_order_item_id?: int|null,
     *     item_id: int,
     *     quantity: string,
     *     unit_price: string,
     *     reason?: string|null,
     *     lot_number?: string|null,
     *     reversal_already_applied?: bool|null
     * }> $lines
     */
    public function openSupplierReturnForReversedGoods(
        int $vendorId,
        ?int $purchaseOrderId,
        ?int $goodsReceiptNoteId,
        array $lines,
        ?User $by,
        string $reason,
        string $dedupeKey,
    ): ReturnRequest {
        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            throw new BusinessRuleException('A supplier-return RMA requires a deduplication key.');
        }
        if ($lines === []) {
            throw new BusinessRuleException('A supplier-return RMA requires at least one line.');
        }

        if ($purchaseOrderId === null && $goodsReceiptNoteId !== null) {
            $purchaseOrderId = GoodsReceiptNote::query()
                ->whereKey($goodsReceiptNoteId)
                ->value('purchase_order_id');
            $purchaseOrderId = $purchaseOrderId !== null ? (int) $purchaseOrderId : null;
        }

        // A caller that reversed the receipt (rejected incoming GRN) must not
        // have it reversed again. An MRB release did NOT touch the receipt, so
        // it passes false and the receipt is reconciled once here.
        $items = [];
        $allReversed = true;
        foreach ($lines as $line) {
            $reversed = array_key_exists('reversal_already_applied', $line)
                ? (bool) $line['reversal_already_applied']
                : true;
            $allReversed = $allReversed && $reversed;
            $items[] = [
                'item_id'                    => (int) $line['item_id'],
                'quantity'                   => (string) $line['quantity'],
                'unit_price'                 => (string) $line['unit_price'],
                'reason'                     => $line['reason'] ?? $reason,
                'lot_number'                 => $line['lot_number'] ?? null,
                'source_grn_item_id'         => $line['grn_item_id'] ?? null,
                'source_po_item_id'          => $line['purchase_order_item_id'] ?? null,
                'reversal_already_applied'   => $reversed,
            ];
        }

        try {
            $rma = DB::transaction(function () use (
                $vendorId,
                $purchaseOrderId,
                $goodsReceiptNoteId,
                $items,
                $by,
                $reason,
                $dedupeKey,
                $allReversed,
            ): ReturnRequest {
                $existing = ReturnRequest::query()
                    ->where('source_key', $dedupeKey)
                    ->where('status', '<>', ReturnRequestStatus::Cancelled->value)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing->load('items');
                }

                $billId = $this->openBillForReturn($goodsReceiptNoteId, $purchaseOrderId);

                $rma = ReturnRequest::create([
                    'rma_number'             => $this->nextRmaNumber(),
                    'source_key'             => $dedupeKey,
                    'type'                   => ReturnRequestType::SupplierReturn,
                    'status'                 => ReturnRequestStatus::Draft,
                    'finance_only'           => false,
                    'vendor_id'              => $vendorId,
                    'purchase_order_id'      => $purchaseOrderId,
                    'goods_receipt_note_id'  => $goodsReceiptNoteId,
                    'bill_id'                => $billId,
                    'reversal_already_applied' => $allReversed,
                    'reason_code'            => 'quality_issue',
                    'reason_description'     => $reason,
                    'internal_notes'         => $reason,
                    'return_date'            => now(),
                    'created_by'             => $by?->id,
                ]);

                // Reuse the authoritative line contract (source validation +
                // reservation) then stamp the "physical goods already handled"
                // markers the create path cannot carry.
                $this->persistItems($rma, $items, true);
                $rma->load('items');

                foreach ($rma->items->values() as $index => $item) {
                    $this->stampReversedLine($item, $items[$index]);
                }

                return $rma->fresh()->load('items');
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateSourceKey($e)) {
                throw $e;
            }
            // A concurrent writer (or a voided prior RMA) owns this key; return
            // whatever row holds it rather than throwing or double-opening.
            $existing = ReturnRequest::query()
                ->where('source_key', $dedupeKey)
                ->first();
            if (! $existing) {
                throw $e;
            }
            $rma = $existing->load('items');
        }

        return $rma;
    }

    /**
     * Mark a factory line's goods as already moved/never-stocked and record the
     * receipt-reversal state read by `processSupplierDisposition()`.
     *
     * `stock_movement_quantity` is deliberately used rather than a new column:
     * `moveLine()` already treats a positive value as "this line has moved,
     * never move it again", which is exactly the idempotency the three
     * system-open paths need. A rejected receipt's goods never entered stock,
     * so stamping the quantity is what stops disposal from issuing a
     * ReturnToVendor movement the ledger cannot back.
     *
     * @param array<string, mixed> $source
     */
    private function stampReversedLine(ReturnRequestItem $line, array $source): void
    {
        $line->update([
            'reversal_already_applied' => (bool) ($source['reversal_already_applied'] ?? true),
            'stock_movement_quantity'  => $line->quantity,
            'receipt_recorded'         => true,
            'returned_quantity'        => (string) $line->returned_quantity === '0.000'
                ? $line->quantity
                : $line->returned_quantity,
        ]);
    }

    /**
     * The first open bill for the receipt/PO, if any, so a later supplier
     * credit has a document to apply against. A rejected receipt has none.
     */
    private function openBillForReturn(?int $goodsReceiptNoteId, ?int $purchaseOrderId): ?int
    {
        $openStatuses = [BillStatus::Unpaid->value, BillStatus::Partial->value];

        if ($goodsReceiptNoteId !== null) {
            $billId = Bill::query()
                ->where('goods_receipt_note_id', $goodsReceiptNoteId)
                ->whereIn('status', $openStatuses)
                ->orderByDesc('id')
                ->value('id');
            if ($billId) {
                return (int) $billId;
            }
        }

        if ($purchaseOrderId !== null) {
            $billId = Bill::query()
                ->where('purchase_order_id', $purchaseOrderId)
                ->whereIn('status', $openStatuses)
                ->orderByDesc('id')
                ->value('id');
            if ($billId) {
                return (int) $billId;
            }
        }

        return null;
    }

    private function isDuplicateSourceKey(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'return_requests_source_key_unique')
            || str_contains($message, 'return_requests.source_key');
    }

    /**
     * Correct an owned draft without opening a second RMA. Source allocations
     * are released before the line contract is replaced, and the same create
     * preparation path is reused so finance/source semantics cannot drift.
     */
    public function update(ReturnRequest $rma, array $data, User $by): ReturnRequest
    {
        return DB::transaction(function () use ($rma, $data, $by): ReturnRequest {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($locked, ReturnRequestStatus::Draft);
            if ((int) $locked->created_by !== (int) $by->id && ! $by->hasPermission('admin.roles.manage')) {
                throw new BusinessRuleException('Only the draft creator or a system administrator can edit this return request.');
            }
            if (($data['type'] ?? null) === ReturnRequestType::SupplierReturn->value
                && (bool) ($data['finance_only'] ?? false)) {
                throw new BusinessRuleException('Supplier returns must use purchase and receipt lineage; finance-only is a customer-credit policy.');
            }

            $this->releaseSourceAllocations($locked);
            $locked->update([
                'type'                => $data['type'],
                'finance_only'        => (bool) ($data['finance_only'] ?? false),
                'finance_only_reason' => $data['finance_only_reason'] ?? null,
                'sales_order_id'      => $data['sales_order_id'] ?? null,
                'invoice_id'          => $data['invoice_id'] ?? null,
                'purchase_order_id'   => $data['purchase_order_id'] ?? null,
                'bill_id'             => $data['bill_id'] ?? null,
                'customer_id'         => $data['customer_id'] ?? null,
                'vendor_id'           => $data['vendor_id'] ?? null,
                'reason_code'         => $data['reason_code'] ?? null,
                'reason_description'  => $data['reason_description'] ?? null,
                'customer_notes'      => $data['customer_notes'] ?? null,
                'internal_notes'      => $data['internal_notes'] ?? null,
                'resolution'          => $data['resolution'] ?? null,
                'return_date'         => $data['return_date'] ?? $locked->return_date,
            ]);

            $locked->items()->delete();
            $this->persistItems($locked, (array) ($data['items'] ?? []), true);

            return $locked->fresh()->load('items');
        });
    }

    /**
     * Persist an RMA's line contract and reserve any source quantity.
     * Supplier drafts may be created before their PO/GRN selectors are filled;
     * submit() calls this again with strict source requirements.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function persistItems(ReturnRequest $rma, array $items, bool $allowIncompleteSupplier): void
    {
        foreach ($items as $item) {
            $line = $this->prepareLine($rma, $item, $allowIncompleteSupplier);
            $source = $line['source'];
            unset($line['source']);

            $saved = ReturnRequestItem::create($line);
            // An already-reversed line (rejected receipt) has nothing left to
            // reserve against — its GRN accepted quantity is no longer backing
            // live stock. Storing the lineage is enough; reserving would fail
            // on a zero accepted quantity and would later re-reserve on submit.
            if ($source !== null && ! $rma->finance_only && ! ($item['reversal_already_applied'] ?? false)) {
                $this->reserveSource($saved, $source['kind'], $source['id'], $line['quantity'], $source['unit_price']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function prepareLine(ReturnRequest $rma, array $item, bool $allowIncompleteSupplier): array
    {
        $quantity = (string) ($item['quantity'] ?? '0');
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new BusinessRuleException('Return quantities must be greater than zero.');
        }

        $isStockable = ! empty($item['item_id']);
        if ($rma->type === ReturnRequestType::CustomerReturn && ! $isStockable && ! $rma->finance_only) {
            throw new BusinessRuleException('Product-only returns must be explicitly classified as finance-only.');
        }
        if ($rma->finance_only && trim((string) $rma->finance_only_reason) === '') {
            throw new BusinessRuleException('Finance-only returns require an explicit non-stock reason.');
        }

        $source = $this->resolveSource($rma, $item, $allowIncompleteSupplier);
        $unitPrice = $source['unit_price'] ?? (string) ($item['unit_price'] ?? '');
        if ($unitPrice === '') {
            throw new BusinessRuleException('Each return line needs a source price or a finance-only unit price.');
        }

        if ($rma->type === ReturnRequestType::CustomerReturn
            && $isStockable && ! $rma->finance_only && $source === null) {
            throw new BusinessRuleException('Stockable returns require exactly one invoice, sales-order, or delivery line.');
        }

        return [
            'return_request_id'           => $rma->id,
            'product_id'                  => $item['product_id'] ?? null,
            'item_id'                     => $item['item_id'] ?? null,
            'quantity'                    => $quantity,
            'unit_price'                  => Money::round2($unitPrice),
            'original_unit_price'         => Money::round2($unitPrice),
            'total'                       => Money::mul($quantity, $unitPrice),
            'reason'                      => $item['reason'] ?? null,
            'condition'                   => $item['condition'] ?? null,
            'source_sales_order_item_id' => $item['source_sales_order_item_id'] ?? null,
            'source_invoice_item_id'      => $item['source_invoice_item_id'] ?? null,
            'source_delivery_item_id'    => $item['source_delivery_item_id'] ?? null,
            'source_po_item_id'          => $item['source_po_item_id'] ?? null,
            'source_grn_item_id'         => $item['source_grn_item_id'] ?? null,
            'source_bill_item_id'        => $item['source_bill_item_id'] ?? null,
            'lot_number'                 => $item['lot_number'] ?? null,
            'serial_number'              => $item['serial_number'] ?? null,
            'source'                     => $source,
        ];
    }

    /**
     * Resolve one authoritative source line and price.
     *
     * @return array{kind:string,id:int,unit_price:string,limit:string}|null
     */
    private function resolveSource(ReturnRequest $rma, array $item, bool $allowIncompleteSupplier): ?array
    {
        if ($rma->finance_only) {
            return null;
        }

        if ($rma->type === ReturnRequestType::CustomerReturn) {
            $sources = array_filter([
                'invoice_item' => $item['source_invoice_item_id'] ?? null,
                'sales_order_item' => $item['source_sales_order_item_id'] ?? null,
                'delivery_item' => $item['source_delivery_item_id'] ?? null,
            ], static fn ($id): bool => $id !== null && $id !== '');

            if (count($sources) !== 1) {
                throw new BusinessRuleException('Choose exactly one invoice, sales-order, or delivery line for each stockable customer return.');
            }

            $kind = (string) array_key_first($sources);
            $id = (int) $sources[$kind];
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            if ($productId === null) {
                throw new BusinessRuleException('Source-backed customer returns require product provenance for Quality inspection.');
            }

            if ($kind === 'invoice_item') {
                $source = InvoiceItem::query()->with(['invoice.salesOrder'])->lockForUpdate()->findOrFail($id);
                if (! $rma->invoice_id || (int) $source->invoice_id !== (int) $rma->invoice_id) {
                    throw new BusinessRuleException('Return invoice-line provenance must belong to the RMA invoice.');
                }
                if ((int) $source->invoice->customer_id !== (int) $rma->customer_id) {
                    throw new BusinessRuleException('Return invoice-line provenance belongs to another customer.');
                }
                if ($rma->sales_order_id && (int) $source->invoice->sales_order_id !== (int) $rma->sales_order_id) {
                    throw new BusinessRuleException('Return invoice-line provenance does not match the RMA order.');
                }
                if ($productId !== null && (int) $source->product_id !== $productId) {
                    throw new BusinessRuleException('Return invoice-line provenance does not match the returned product.');
                }

                return [
                    'kind' => $kind,
                    'id' => $id,
                    'unit_price' => (string) $source->unit_price,
                    'limit' => (string) $source->quantity,
                ];
            }

            if ($kind === 'sales_order_item') {
                $source = SalesOrderItem::query()->with('salesOrder')->lockForUpdate()->findOrFail($id);
                if (! $rma->sales_order_id || (int) $source->sales_order_id !== (int) $rma->sales_order_id) {
                    throw new BusinessRuleException('Return sales-order-line provenance must belong to the RMA order.');
                }
                if ((int) $source->salesOrder->customer_id !== (int) $rma->customer_id) {
                    throw new BusinessRuleException('Return sales-order-line provenance belongs to another customer.');
                }
                if ($productId !== null && (int) $source->product_id !== $productId) {
                    throw new BusinessRuleException('Return sales-order-line provenance does not match the returned product.');
                }

                return [
                    'kind' => $kind,
                    'id' => $id,
                    'unit_price' => (string) $source->unit_price,
                    'limit' => (string) $source->quantity_delivered,
                ];
            }

            $source = DeliveryItem::query()->with('salesOrderItem.salesOrder')->lockForUpdate()->findOrFail($id);
            if (! $rma->sales_order_id || (int) $source->salesOrderItem->sales_order_id !== (int) $rma->sales_order_id) {
                throw new BusinessRuleException('Return delivery-line provenance must belong to the RMA order.');
            }
            if ((int) $source->salesOrderItem->salesOrder->customer_id !== (int) $rma->customer_id) {
                throw new BusinessRuleException('Return delivery-line provenance belongs to another customer.');
            }
            if ($productId !== null && (int) $source->salesOrderItem->product_id !== $productId) {
                throw new BusinessRuleException('Return delivery-line provenance does not match the returned product.');
            }

            return [
                'kind' => $kind,
                'id' => $id,
                'unit_price' => (string) $source->unit_price,
                'limit' => (string) $source->quantity,
            ];
        }

        $hasPo = ! empty($item['source_po_item_id']);
        $hasGrn = ! empty($item['source_grn_item_id']);
        $hasBill = ! empty($item['source_bill_item_id']);
        if (! $hasPo && ! $hasGrn && ! $hasBill && $allowIncompleteSupplier) {
            return null;
        }
        if (! $hasPo || ! $hasGrn) {
            throw new BusinessRuleException('Supplier-return lines require both source PO and GRN lines before submission.');
        }
        if (! $rma->purchase_order_id || ! $rma->vendor_id) {
            throw new BusinessRuleException('Supplier returns require a vendor and source purchase order.');
        }

        $poItem = PurchaseOrderItem::query()->with('purchaseOrder')->lockForUpdate()->findOrFail((int) $item['source_po_item_id']);
        $grnItem = GrnItem::query()->with('grn')->lockForUpdate()->findOrFail((int) $item['source_grn_item_id']);
        if ((int) $poItem->purchase_order_id !== (int) $rma->purchase_order_id
            || (int) $poItem->item_id !== (int) $item['item_id']
            || (int) $grnItem->purchase_order_item_id !== (int) $poItem->id
            || (int) $grnItem->item_id !== (int) $item['item_id']
            || (int) $grnItem->grn->vendor_id !== (int) $rma->vendor_id) {
            throw new BusinessRuleException('Supplier-return source documents do not match the RMA vendor, PO, or item.');
        }
        if ($grnItem->material_lot_number && trim((string) ($item['lot_number'] ?? '')) === '') {
            throw new BusinessRuleException('Controlled returned stock requires lot provenance from the source receipt.');
        }

        $sourcePrice = (string) $poItem->unit_price;
        if ($hasBill) {
            if (! $rma->bill_id) {
                throw new BusinessRuleException('A source bill line requires the RMA bill.');
            }
            $billItem = BillItem::query()->lockForUpdate()->findOrFail((int) $item['source_bill_item_id']);
            if ((int) $billItem->bill_id !== (int) $rma->bill_id || (int) $billItem->item_id !== (int) $item['item_id']) {
                throw new BusinessRuleException('Supplier-return bill-line provenance does not match the RMA bill or item.');
            }
            $sourcePrice = (string) $billItem->unit_price;
        }

        return [
            'kind' => 'grn_item',
            'id' => (int) $grnItem->id,
            'unit_price' => $sourcePrice,
            'limit' => (string) $grnItem->quantity_accepted,
        ];
    }

    /**
     * Active reserved quantity per source line, keyed by source id.
     *
     * RMA-010 — `sourceOptions()` used to advertise the raw document quantity as
     * "available", so two operators could both see 10 units remaining, one would
     * get a late `reserveSource()` rejection at submit, and neither could see the
     * reservation that caused it. This is the batched read that lets the option
     * list show the actually-reservable amount. One query per kind, never per
     * line.
     *
     * @param  list<int> $sourceIds
     * @return array<int, string> source id => reserved quantity (3 dp string)
     */
    public function activeAllocationsBySource(string $kind, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        return ReturnRequestSourceAllocation::query()
            ->where('source_kind', $kind)
            ->whereIn('source_id', $sourceIds)
            ->whereNull('released_at')
            ->whereHas('returnRequestItem.returnRequest', function ($query): void {
                $query->whereNotIn('status', [
                    ReturnRequestStatus::Rejected->value,
                    ReturnRequestStatus::Cancelled->value,
                ]);
            })
            ->selectRaw('source_id, SUM(quantity) AS reserved')
            ->groupBy('source_id')
            ->pluck('reserved', 'source_id')
            ->map(static fn ($reserved): string => bcadd((string) $reserved, '0', 3))
            ->all();
    }

    /**
     * Quantity still reservable on a source line.
     *
     * `$excludeAllocationIds` lets a caller re-check an allocation that already
     * exists without counting itself as competition.
     *
     * @param list<int> $excludeAllocationIds
     */
    private function remainingSourceQuantity(string $kind, int $sourceId, array $excludeAllocationIds = []): string
    {
        $limit = $this->sourceLimit($kind, $sourceId);
        $allocated = (string) ReturnRequestSourceAllocation::query()
            ->where('source_kind', $kind)
            ->where('source_id', $sourceId)
            ->whereNull('released_at')
            ->when($excludeAllocationIds !== [], fn ($query) => $query->whereNotIn('id', $excludeAllocationIds))
            ->whereHas('returnRequestItem.returnRequest', function ($query): void {
                $query->whereNotIn('status', [
                    ReturnRequestStatus::Rejected->value,
                    ReturnRequestStatus::Cancelled->value,
                ]);
            })
            ->sum('quantity');

        return bcsub($limit, $allocated, 3);
    }

    private function reserveSource(
        ReturnRequestItem $line,
        string $kind,
        int $sourceId,
        string $quantity,
        string $unitPrice,
    ): void {
        $available = $this->remainingSourceQuantity($kind, $sourceId);
        if (bccomp($quantity, $available, 3) > 0) {
            throw new BusinessRuleException("Return quantity exceeds the remaining quantity on the {$kind} source line.");
        }

        ReturnRequestSourceAllocation::create([
            'return_request_item_id' => $line->id,
            'source_kind'             => $kind,
            'source_id'               => $sourceId,
            'quantity'                => $quantity,
            'unit_price'              => Money::round2($unitPrice),
        ]);
    }

    /**
     * Re-check an EXISTING reservation against the source line as it stands now.
     *
     * `reserveSource()` only validates at the moment it creates an allocation, and
     * submit used to skip the check entirely whenever an active allocation was
     * already present. A draft could therefore be created against 10 available
     * units, the source document reduced to 4, and the stale 10-unit reservation
     * would still sail through submit — reserving more than the source can back.
     * A changed source selection re-reserves; a shrunk source is refused.
     */
    private function revalidateSourceAllocation(ReturnRequestItem $line, array $source): void
    {
        $allocation = $line->sourceAllocations()->whereNull('released_at')->latest('id')->first();
        if (! $allocation) {
            return;
        }

        if ((string) $allocation->source_kind !== (string) $source['kind']
            || (int) $allocation->source_id !== (int) $source['id']) {
            $allocation->update(['released_at' => now()]);
            $this->reserveSource($line, $source['kind'], (int) $source['id'], (string) $line->quantity, $source['unit_price']);
            return;
        }

        $available = $this->remainingSourceQuantity(
            (string) $source['kind'],
            (int) $source['id'],
            [(int) $allocation->id],
        );
        if (bccomp((string) $line->quantity, $available, 3) > 0) {
            throw new BusinessRuleException(
                "Return quantity exceeds the remaining quantity on the {$source['kind']} source line."
            );
        }

        if (bccomp((string) $allocation->quantity, (string) $line->quantity, 3) !== 0) {
            $allocation->update(['quantity' => (string) $line->quantity]);
        }
    }

    private function sourceLimit(string $kind, int $sourceId): string
    {
        return match ($kind) {
            'invoice_item' => (string) InvoiceItem::query()->findOrFail($sourceId)->quantity,
            'sales_order_item' => (string) SalesOrderItem::query()->findOrFail($sourceId)->quantity_delivered,
            'delivery_item' => (string) DeliveryItem::query()->findOrFail($sourceId)->quantity,
            'grn_item' => (string) GrnItem::query()->findOrFail($sourceId)->quantity_accepted,
            default => throw new BusinessRuleException('Unsupported return source line.'),
        };
    }

    private function syncSourceAllocationQuantity(ReturnRequestItem $line, string $quantity): void
    {
        $allocation = $line->sourceAllocations()->whereNull('released_at')->latest('id')->first();
        if (! $allocation) {
            return;
        }
        if (bccomp($quantity, '0', 3) <= 0) {
            $allocation->update(['quantity' => '0.000', 'released_at' => now()]);
            return;
        }
        $allocation->update(['quantity' => $quantity]);
    }

    private function assertLocationUsable(
        WarehouseLocation $location,
        ?WarehouseZoneType $requiredZone,
        string $message,
    ): void {
        $location->loadMissing('zone.warehouse');
        $zoneType = $location->zone?->zone_type;
        $zoneType = $zoneType instanceof WarehouseZoneType
            ? $zoneType
            : WarehouseZoneType::tryFrom((string) $zoneType);

        $zoneAllowed = $requiredZone !== null
            ? $zoneType === $requiredZone
            : $zoneType !== null
                && ! in_array($zoneType, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true);

        if (! $location->is_active || ! $location->zone?->warehouse?->is_active || ! $zoneAllowed) {
            throw new BusinessRuleException($message);
        }
    }

    private function refreshAuthoritativeLineContract(ReturnRequest $rma): void
    {
        foreach ($rma->items as $line) {
            $source = $this->resolveSource($rma, $line->getAttributes(), false);
            if ($source === null && ! $rma->finance_only) {
                throw new BusinessRuleException('Every submitted return line needs complete source lineage.');
            }

            $unitPrice = $source['unit_price'] ?? (string) $line->unit_price;
            if ($unitPrice === '') {
                throw new BusinessRuleException('Every submitted return line needs an authoritative unit price.');
            }
            $line->update([
                'unit_price' => Money::round2($unitPrice),
                'original_unit_price' => Money::round2($unitPrice),
                'total' => Money::mul((string) $line->quantity, $unitPrice),
            ]);

            if ($source !== null && ! $line->reversal_already_applied) {
                if ($line->sourceAllocations()->whereNull('released_at')->exists()) {
                    // RMA-005 — an allocation made at draft time is not evidence
                    // that the source can still back it.
                    $this->revalidateSourceAllocation($line, $source);
                } else {
                    $this->reserveSource($line, $source['kind'], $source['id'], (string) $line->quantity, $source['unit_price']);
                }
            }
        }
    }

    /**
     * Submit for approval (draft → pending_approval).
     *
     * L-37 — Also opens an approval-records chain via ApprovalService so
     * the Admin / approval-board UIs can show the same review structure
     * used by PR / Leave / OT.
     */
    public function submit(ReturnRequest $rma): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($locked, ReturnRequestStatus::Draft);
            $locked->load('items');
            $this->refreshAuthoritativeLineContract($locked);
            $this->states->transition($locked, ReturnRequestStatus::PendingApproval);

            $locked->update(['status' => ReturnRequestStatus::PendingApproval]);
            try {
                $this->approvals->submit($locked, 'return_request');
            } catch (\Throwable $e) {
                // A swallowed failure here used to strand the RMA: the status
                // flipped to pending_approval while no approval records existed,
                // and isFullyApproved() is false for an empty chain — so it could
                // never be approved again, only rejected. Fail the submission
                // instead and leave the RMA editable in draft.
                Log::warning('return_request approval submit failed', [
                    'rma_id' => $rma->id,
                    'error'  => $e->getMessage(),
                ]);

                throw new BusinessRuleException(
                    'The return approval workflow is not configured, so this RMA cannot be submitted. '
                    . 'Ask an administrator to set up the "Return Request Approval" workflow.'
                );
            }
            return $locked->fresh();
        });

        event(new ReturnRequestUpdated($updated, 'submitted for approval'));

        return $updated;
    }

    /**
     * Approve (pending_approval → approved).
     *
     * L-37 — Records each approver step on the approval-records ledger.
     * Status flips to Approved only when all chain steps are complete;
     * partial approval keeps the row at PendingApproval.
     */
    public function approve(ReturnRequest $rma, User $by, ?string $remarks = null): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma, $by, $remarks) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($locked, ReturnRequestStatus::PendingApproval);

            try {
                $this->approvals->approve($locked, $by, $remarks);
            } catch (\Throwable $e) {
                // Swallowing this returned 200 with an unchanged RMA, so the SPA
                // showed "RMA approved." for an approval that never happened.
                Log::warning('return_request approval approve failed', [
                    'rma_id' => $locked->id,
                    'error'  => $e->getMessage(),
                ]);

                throw $e instanceof BusinessRuleException
                    ? $e
                    : new BusinessRuleException($e->getMessage() ?: 'You cannot approve this return request.');
            }

            if ($this->approvals->isFullyApproved($locked)) {
                $this->states->transition($locked, ReturnRequestStatus::Approved);
                $locked->update([
                    'status'      => ReturnRequestStatus::Approved,
                    'approved_by' => $by->id,
                    'finance_only_approved_by' => $locked->finance_only ? $by->id : null,
                    'approved_at' => now(),
                ]);
            }

            return $locked->fresh();
        });

        event(new ReturnRequestUpdated($updated, 'approved'));

        return $updated;
    }

    /**
     * Record receipt of returned goods (approved → received).
     *
     * @param array<int, numeric-string> $receivedQtys keyed by return_request_items.id
     */
    public function receive(ReturnRequest $rma, array $receivedQtys = [], ?int $quarantineLocationId = null, ?User $by = null): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma, $receivedQtys, $quarantineLocationId, $by) {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($locked, ReturnRequestStatus::Approved);
            $locked->load('items');
            $this->states->transition($locked, ReturnRequestStatus::Received);

            $locked->update([
                'status'      => ReturnRequestStatus::Received,
                'received_at' => now(),
            ]);

            foreach ($locked->items as $item) {
                // A missing map entry means the operator accepted the documented
                // full-quantity default. An explicit zero is different: it is a
                // recorded no-return and must never fall back to the request.
                $hasRecordedQuantity = array_key_exists($item->id, $receivedQtys);
                $qty = (string) ($hasRecordedQuantity ? $receivedQtys[$item->id] : $item->quantity);
                $updates = [
                    'returned_quantity' => $qty,
                    'receipt_recorded' => true,
                ];
                if ($locked->type === ReturnRequestType::CustomerReturn && $item->item_id && bccomp($qty, '0', 3) > 0) {
                    if ($item->quarantine_movement_id) {
                        throw new BusinessRuleException("Return line {$item->id} has already entered quarantine.");
                    }
                    $location = $quarantineLocationId
                        ? WarehouseLocation::query()->with('zone')->findOrFail($quarantineLocationId)
                        : $this->defaultReturnQuarantineLocation();
                    $this->assertLocationUsable($location, WarehouseZoneType::Quarantine, 'Returned stock must be received into an active quarantine-zone location.');
                    $movement = $this->stockMovements->move(new StockMovementInput(
                        type: StockMovementType::AdjustmentIn,
                        itemId: (int) $item->item_id,
                        toLocationId: (int) $location->id,
                        quantity: $qty,
                        referenceType: 'return_request',
                        referenceId: $locked->id,
                        remarks: "RMA {$locked->rma_number} line {$item->id}: quarantine receipt",
                        createdBy: $by?->id ?? $rma->created_by,
                    ));
                    if ($item->lot_number) {
                        $this->stockMovements->stampLot($movement, $item->lot_number);
                    }
                    $updates['quarantine_location_id'] = $location->id;
                    $updates['quarantine_movement_id'] = $movement->id;
                    $updates['quarantine_status'] = 'held';
                }
                $item->update($updates);
                $this->syncSourceAllocationQuantity($item, $qty);
            }

            return $locked->fresh()->load('items');
        });

        event(new ReturnRequestUpdated($updated, 'received'));

        return $updated;
    }

    /**
     * Complete inspection (received → inspected).
     *
     * Also creates a Quality Inspection for each distinct product on the
     * return items. The first product's inspection is linked back to the
     * ReturnRequest via inspection_id. Items without a product_id are
     * skipped (the inspection is free-text only in that case).
     */
    public function inspect(ReturnRequest $rma, ?string $internalNotes = null, ?User $by = null): ReturnRequest
    {
        if (! $by) {
            throw new BusinessRuleException('An active user is required to stage the Quality inspection.');
        }

        $updated = DB::transaction(function () use ($rma, $internalNotes, $by) {
            $rma = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($rma, ReturnRequestStatus::Received);
            $rma->load('items.product');

            $stage = $rma->type === ReturnRequestType::SupplierReturn
                ? InspectionStage::SupplierReturn
                : InspectionStage::CustomerReturn;

            if ($internalNotes !== null) {
                $rma->update(['internal_notes' => $internalNotes]);
            }

            $result = $this->stageReturnInspections($rma, $stage, $by, $internalNotes);

            if ($result['failures'] !== []) {
                $rma->update([
                    // The RMA remains physically received until every required
                    // product-linked inspection has been staged. This prevents
                    // dispose/complete from bypassing a failed Quality handoff.
                    'status' => ReturnRequestStatus::Received,
                    'inspected_at' => null,
                    'inspection_id' => $result['inspection_ids'][0] ?? $rma->inspection_id,
                    'inspection_handoff_status' => ReturnInspectionHandoffStatus::ManualRequired,
                    'inspection_handoff_message' => $this->inspectionHandoffFailureMessage($result['failures']),
                    'inspection_handoff_at' => now(),
                ]);
                $this->recordReturnInspectionRequest($rma);

                return $rma->fresh();
            }

            $this->states->transition($rma, ReturnRequestStatus::Inspected);
            $rma->update([
                'status' => ReturnRequestStatus::Inspected,
                'inspected_at' => $rma->inspected_at ?? now(),
                'inspection_id' => $result['inspection_ids'][0] ?? $rma->inspection_id,
                'inspection_handoff_status' => $result['inspection_ids'] !== []
                    ? ReturnInspectionHandoffStatus::Generated
                    : ReturnInspectionHandoffStatus::NotRequired,
                'inspection_handoff_message' => null,
                'inspection_handoff_at' => now(),
            ]);

            return $rma->fresh();
        });

        event(new ReturnRequestUpdated($updated, 'inspection completed'));

        return $updated;
    }

    /**
     * Retry a previously failed RMA → Quality handoff.
     *
     * The RMA row is locked and an existing non-cancelled inspection is reused
     * per (RMA, stage, product), so a worker retry or operator double-click can
     * never create duplicate inspection shells for the same returned product.
     */
    public function retryInspectionHandoff(ReturnRequest $rma, User $by): ReturnRequest
    {
        return DB::transaction(function () use ($rma, $by): ReturnRequest {
            $rma = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            if (! in_array($rma->status, [ReturnRequestStatus::Received, ReturnRequestStatus::Inspected], true)) {
                throw new BusinessRuleException(
                    "Only received or previously inspected RMAs can retry Quality inspection staging; got {$rma->status->value}."
                );
            }

            $rma->load('items.product');
            $stage = $rma->type === ReturnRequestType::SupplierReturn
                ? InspectionStage::SupplierReturn
                : InspectionStage::CustomerReturn;
            $result = $this->stageReturnInspections($rma, $stage, $by, null);

            if ($result['failures'] !== []) {
                if ($rma->status === ReturnRequestStatus::Inspected) {
                    $this->states->transition($rma, ReturnRequestStatus::Received);
                }
                $rma->update([
                    'status' => ReturnRequestStatus::Received,
                    'inspected_at' => null,
                    'inspection_id' => $result['inspection_ids'][0] ?? $rma->inspection_id,
                    'inspection_handoff_status' => ReturnInspectionHandoffStatus::ManualRequired,
                    'inspection_handoff_message' => $this->inspectionHandoffFailureMessage($result['failures']),
                    'inspection_handoff_at' => now(),
                ]);

                return $rma->fresh();
            }

            $this->states->transition($rma, ReturnRequestStatus::Inspected);
            $rma->update([
                'status' => ReturnRequestStatus::Inspected,
                'inspected_at' => $rma->inspected_at ?? now(),
                'inspection_id' => $result['inspection_ids'][0] ?? $rma->inspection_id,
                'inspection_handoff_status' => $result['inspection_ids'] !== []
                    ? ReturnInspectionHandoffStatus::Generated
                    : ReturnInspectionHandoffStatus::NotRequired,
                'inspection_handoff_message' => null,
                'inspection_handoff_at' => now(),
            ]);

            return $rma->fresh();
        });
    }

    /** Mark an RMA handoff as operator-actionable when the worker has no actor. */
    public function markInspectionHandoffManual(int $rmaId, ?string $message = null): void
    {
        ReturnRequest::query()->whereKey($rmaId)->update([
            'inspection_handoff_status' => ReturnInspectionHandoffStatus::ManualRequired,
            'inspection_handoff_message' => $message ?: 'Quality inspection staging requires manual action.',
            'inspection_handoff_at' => now(),
        ]);
    }

    /**
     * @return array{inspection_ids: list<int>, failures: list<string>}
     */
    private function stageReturnInspections(
        ReturnRequest $rma,
        InspectionStage $stage,
        User $by,
        ?string $internalNotes,
    ): array {
        $inspectionIds = [];
        $failures = [];

        foreach ($rma->items->groupBy(fn (ReturnRequestItem $item) => $item->product_id) as $productId => $productItems) {
            if (! $productId) {
                // Item-only/free-text lines have no Product inspection spec.
                continue;
            }

            $existing = Inspection::query()
                ->where('entity_type', InspectionEntityType::ReturnRequest->value)
                ->where('entity_id', $rma->id)
                ->where('stage', $stage->value)
                ->where('product_id', (int) $productId)
                ->where('status', '<>', 'cancelled')
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $inspectionIds[] = (int) $existing->id;
                continue;
            }

            // Decimal-safe: quantities are decimal(12,3), so summing them through
            // float and then ceil()ing can land on the wrong sample size at the
            // boundary (0.1 + 0.2 style drift makes 3.000 read as 3.0000000004,
            // which ceils to 4 and inflates the AQL batch). Sum with bcadd, then
            // round up only once, on an exact decimal string.
            $batchDecimal = '0';
            foreach ($productItems as $productItem) {
                $batchDecimal = bcadd($batchDecimal, $this->settledQuantity($productItem), 3);
            }
            $batchQty = $this->wholeUnits($batchDecimal);

            try {
                $inspection = $this->inspections->create([
                    'stage' => $stage->value,
                    'product_id' => (int) $productId,
                    'batch_quantity' => max(1, $batchQty),
                    'entity_type' => InspectionEntityType::ReturnRequest->value,
                    'entity_id' => $rma->id,
                    'notes' => $internalNotes ?: 'Auto-created from RMA ' . $rma->rma_number,
                ], $by);
                $inspectionIds[] = (int) $inspection->id;
            } catch (BusinessRuleException|ModelNotFoundException $e) {
                $productLabel = $productItems->first()?->product?->part_number ?: "product {$productId}";
                $failures[] = "{$productLabel}: {$e->getMessage()}";
                Log::warning('ReturnRequestService: inspection handoff requires manual action', [
                    'rma_id' => $rma->id,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'inspection_ids' => array_values(array_unique($inspectionIds)),
            'failures' => $failures,
        ];
    }

    /** @param list<string> $failures */
    private function inspectionHandoffFailureMessage(array $failures): string
    {
        return 'Quality inspection staging requires manual action: ' . implode(' | ', $failures);
    }

    private function recordReturnInspectionRequest(ReturnRequest $rma): void
    {
        app(OutboxService::class)->recordForChain(
            new ReturnInspectionRequested($rma),
            $rma,
            'returns',
            'return_request',
            'inspection_handoff',
            'return-inspection-request:' . $rma->id,
        );
    }

    /**
     * Dispose items on an inspected RMA (inspected → disposition_status=disposed).
     *
     * For each item, sets a disposition (scrap/rework/restock/return_to_supplier).
     * Auto-creates NCRs for scrap/rework items with a product. Auto-creates
     * a credit memo for customer returns with positive item totals. When a
     * location is supplied, restock/rework (customer) and return_to_supplier
     * (supplier) lines move in/out of stock immediately (moveAtDispose).
     */
    public function dispose(
        ReturnRequest $rma,
        array $dispositions,
        User $by,
        bool $createReplacementPo = false,
        ?int $locationId = null,
    ): ReturnRequest {
        $updated = DB::transaction(function () use ($rma, $dispositions, $by, $createReplacementPo, $locationId) {
            $rma = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($rma, ReturnRequestStatus::Inspected);
            $this->ensureInspectionHandoffReady($rma);
            if ($rma->disposition_status === 'disposed') {
                throw new BusinessRuleException('RMA has already been disposed.');
            }

            $rma->load(['items', 'bill.items', 'purchaseOrder.items']);
            $this->ensureReturnInspectionsReady($rma, $dispositions);
            $this->assertDispositionMatrix($rma, $dispositions);

            // Fail fast: movement lines (restock/rework for customer,
            // return_to_supplier for supplier) need a warehouse location BEFORE
            // the credit-note / replacement-PO work runs — never spend the
            // effort and roll it all back over a missing location. Evaluated
            // against the REQUESTED dispositions (stored ones are still null).
            // A system-opened RMA carries lines whose goods the caller already
            // dealt with (a rejected receipt never entered stock; an MRB
            // release already shipped them). Those lines are stamped with
            // stock_movement_quantity and must not demand a warehouse location
            // for a movement that will never happen.
            $requestsMovement = collect($dispositions)->contains(
                function (array $row) use ($rma): bool {
                    $line = $rma->items->firstWhere('hash_id', $row['item_id'] ?? null);
                    if ($line && bccomp((string) $line->stock_movement_quantity, '0', 3) > 0) {
                        return false;
                    }

                    return $rma->type === ReturnRequestType::SupplierReturn
                        ? ($row['disposition'] ?? null) === DispositionType::ReturnToSupplier->value
                        : in_array(
                            $row['disposition'] ?? null,
                            [DispositionType::Restock->value, DispositionType::Rework->value],
                            true,
                        );
                }
            );
            if ($requestsMovement && ! $locationId) {
                throw new BusinessRuleException(
                    $rma->type === ReturnRequestType::CustomerReturn
                        ? 'Select the warehouse location returned restock lines are received back into.'
                        : 'Select the warehouse location the returned goods ship out from.'
                );
            }

            foreach ($rma->items as $item) {
                $disp = collect($dispositions)->firstWhere('item_id', $item->hash_id);
                if (! $disp) {
                    continue;
                }

                $item->update([
                    'disposition'       => $disp['disposition'],
                    'disposition_notes' => $disp['notes'] ?? null,
                ]);

                if (in_array($disp['disposition'], ['scrap', 'rework'], true) && $item->product_id && ! $item->ncr_id) {
                    $ncr = app(NcrService::class)->create([
                        'source'             => 'customer_complaint',
                        'severity'           => 'medium',
                        'product_id'         => $item->product_id,
                        'defect_description' => "Auto-created from RMA {$rma->rma_number}. "
                            . "Disposition: {$disp['disposition']}. "
                            . ($disp['notes'] ?? ''),
                        // Same settled-quantity rule as every other consumer,
                        // rounded UP: an NCR covering 8.4 units affects 9, and
                        // truncating understated the defect on the Pareto data.
                        'affected_quantity'  => $this->wholeUnits($this->settledQuantity($item)),
                        'is_auto_generated'  => true,
                    ], $by);
                    $item->update(['ncr_id' => $ncr->id]);
                }
            }

            $rma->load('items');
            if ($rma->type === ReturnRequestType::CustomerReturn) {
                // 2026-08-08 — draft customer credit note, one line per returned
                // item (only what was actually sent back and kept — lines routed
                // onward to the supplier or scrapped are excluded). The credit
                // stays DRAFT until finance finalizes it (GL untouched), mirroring
                // the auto-bill / auto-invoice review-then-post pattern.
                // BUG 1: the old `&& $rma->invoice_id` gate silently dropped the
                // whole credit for stockable returns whose only provenance is a
                // sales-order or delivery line. createCreditNote() owns the
                // provenance/finance-only guards, so let it decide per line.
                $creditNote = $this->createCreditNote($rma, $by);
                if ($creditNote) {
                    $rma->update(['credit_note_id' => $creditNote->id]);
                }
            }

            if ($rma->type === ReturnRequestType::SupplierReturn) {
                $this->processSupplierDisposition($rma, $by, $createReplacementPo);
            }

            // 2026-08-08 — dispose-time stock movement on BOTH sides. Customer
            // restock/rework lines are received back into inventory immediately
            // (AdjustmentIn — the O2C twin of GRN acceptance); supplier
            // return_to_supplier lines ship out right away (ReturnToVendor).
            // complete() skips every line already stamped with
            // stock_movement_quantity (idempotent).
            $this->moveAtDispose($rma, $locationId, $by);

            $rma->update(['disposition_status' => 'disposed']);

            return $rma->fresh()->load([
                'items', 'creditNote', 'replacementPurchaseOrder', 'inspections.product',
                'stockMovement.toLocation', 'stockMovement.fromLocation',
            ]);
        });

        event(new ReturnRequestUpdated($updated, 'disposition recorded'));

        return $updated;
    }

    /**
     * The quantity that physically came back on a line, falling back to the
     * requested quantity for RMAs received before per-line counts existed.
     *
     * `receipt_recorded` is authoritative WHEN SET, because receive() writes it
     * in the same statement as the count: that is what keeps an explicit zero a
     * recorded no-return instead of silently re-reading the requested quantity.
     *
     * A positive `returned_quantity` with the flag unset is still a physical
     * receipt. Migration 2026_08_25_190000, which introduced the flag, states
     * that rule and backfills exactly it ("Existing positive counts were
     * explicit physical receipts in the pre-flag schema"); only zero stays
     * ambiguous, because the old default cannot distinguish "not counted" from
     * "none returned". Gating on the flag alone contradicted that invariant and
     * was a money-and-stock defect, not a cosmetic one: the units sitting in
     * quarantine are `returned_quantity`, so falling through to `quantity`
     * asked the ledger to move goods that never arrived (InsufficientStock on
     * the restock leg) and credited the customer for them (₱200 over-credit on
     * a 10-requested / 8-returned line at ₱100).
     */
    private function settledQuantity(ReturnRequestItem $item): string
    {
        if ((bool) $item->receipt_recorded) {
            return (string) $item->returned_quantity;
        }

        if (bccomp((string) $item->returned_quantity, '0', 3) > 0) {
            return (string) $item->returned_quantity;
        }

        return (string) $item->quantity;
    }

    private function creditableAmount(ReturnRequestItem $item): string
    {
        return Money::mul($this->settledQuantity($item), (string) $item->unit_price);
    }

    /**
     * A decimal quantity as whole units, rounded UP, without ever touching a
     * float. `(int) ceil((float) $decimal)` is the form this replaces: the float
     * conversion can nudge an exact 3.000 to 3.0000000004 and ceil it to 4, and
     * a plain `(int)` cast truncates a real fraction away instead. Integer
     * consumers (inspection batch size, NCR affected quantity) need the ceiling,
     * because a partial unit is still a whole unit to inspect or report.
     */
    private function wholeUnits(string $quantity): int
    {
        $truncated = (int) bcdiv($quantity, '1', 0);

        return bccomp($quantity, (string) $truncated, 3) > 0
            ? $truncated + 1
            : $truncated;
    }

    /**
     * Every disposition rule that must hold before `dispose()` causes any
     * side effect: the legality matrix, AND a one-to-one map onto the RMA's
     * lines.
     *
     * The completeness half is duplicated from `DisposeReturnRequest` on
     * purpose. Disposition is one-shot and irreversible — it issues the credit
     * note, reverses GRN receipts and moves stock — and the loop in `dispose()`
     * silently `continue`s past any line it has no entry for, then marks the RMA
     * `disposed`. That leaves undecided lines that can never be revisited. Only
     * the HTTP validator enforced it, so a queued workflow, a console command or
     * a future controller could terminalise an RMA through the service and
     * strand those lines. An invariant this destructive belongs on the service,
     * not only on one of its callers.
     *
     * @param array<int, array<string, mixed>> $dispositions
     */
    private function assertDispositionMatrix(ReturnRequest $rma, array $dispositions): void
    {
        $allowed = array_map(
            static fn (DispositionType $disposition): string => $disposition->value,
            DispositionType::allowedFor($rma->type, (bool) $rma->finance_only),
        );
        $lines = $rma->items->keyBy(fn (ReturnRequestItem $line): string => $line->hash_id);
        $seen  = [];

        foreach ($dispositions as $row) {
            $disposition = (string) ($row['disposition'] ?? '');
            if (! in_array($disposition, $allowed, true)) {
                throw new BusinessRuleException("The {$disposition} disposition is not valid for this RMA type.");
            }
            $key  = (string) ($row['item_id'] ?? '');
            $line = $lines->get($key);
            if (! $line) {
                throw new BusinessRuleException('Every disposition must reference a line on this RMA.');
            }
            if (in_array($key, $seen, true)) {
                throw new BusinessRuleException('Each return line may take only one disposition.');
            }
            $seen[] = $key;
            if ($disposition === DispositionType::NoReturn->value
                && ! $rma->finance_only
                && bccomp($this->settledQuantity($line), '0', 3) > 0) {
                throw new BusinessRuleException('No-goods-return is only valid when the recorded receipt quantity is zero.');
            }
            if ($rma->finance_only && $disposition !== DispositionType::NoReturn->value) {
                throw new BusinessRuleException('Finance-only RMAs cannot create stock dispositions.');
            }
        }

        $undecided = $lines->keys()->diff($seen);
        if ($undecided->isNotEmpty()) {
            throw new BusinessRuleException(
                "Every return line needs a disposition — {$undecided->count()} line(s) are undecided."
            );
        }
    }

    private function processSupplierDisposition(ReturnRequest $rma, User $by, bool $createReplacementPo): void
    {
        $returnedItems = $rma->items->where('disposition', 'return_to_supplier');
        if ($returnedItems->isEmpty()) {
            return;
        }
        if (! $rma->vendor_id || ! $rma->purchase_order_id) {
            throw new BusinessRuleException('Supplier returns require a vendor and source purchase order.');
        }

        // A system-opened RMA may have been created before the payable existed
        // (or while it was still a draft). Attach the first open bill for the
        // linked receipt/PO now so the supplier credit can be applied instead
        // of floating unapplied.
        if (! $rma->bill_id) {
            $billId = $this->openBillForReturn(
                $rma->goods_receipt_note_id ? (int) $rma->goods_receipt_note_id : null,
                (int) $rma->purchase_order_id,
            );
            if ($billId) {
                $rma->forceFill(['bill_id' => $billId])->save();
                $rma->load('bill');
            }
        }

        $creditLines = [];
        $replacementLines = [];
        foreach ($returnedItems->sortBy('source_grn_item_id') as $item) {
            if (! $item->source_grn_item_id || ! $item->source_po_item_id) {
                throw new BusinessRuleException('Each supplier-return line requires source GRN and PO lines.');
            }

            $quantity = $this->settledQuantity($item);
            $grnItem = GrnItem::query()->with('grn')->lockForUpdate()->findOrFail($item->source_grn_item_id);
            $poItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($item->source_po_item_id);

            if ((int) $grnItem->purchase_order_item_id !== (int) $poItem->id
                || (int) $poItem->purchase_order_id !== (int) $rma->purchase_order_id
                || (int) $grnItem->item_id !== (int) $item->item_id
                || (int) $grnItem->grn->vendor_id !== (int) $rma->vendor_id) {
                throw new BusinessRuleException('Supplier-return source documents do not match the RMA.');
            }
            if (bccomp($quantity, '0', 3) <= 0) {
                throw new BusinessRuleException('Supplier-return quantity must be greater than zero.');
            }

            // A line whose receipt was already reversed (an incoming-QC
            // rejection ran GrnService::reversePoReceipt()) must NOT be reduced
            // again: the PO received quantity already sits below this line's
            // receipt, and the GRN running totals were left untouched. The
            // quantity bounds are skipped with the reduction for the same
            // reason — they describe a receipt that no longer exists. The
            // supplier credit below still runs, so the caller gets paid the
            // refund exactly once.
            if (! (bool) $item->reversal_already_applied) {
                if (bccomp($quantity, (string) $grnItem->quantity_received, 3) > 0
                    || bccomp($quantity, (string) $grnItem->quantity_accepted, 3) > 0
                    || bccomp($quantity, (string) $poItem->quantity_received, 3) > 0) {
                    throw new BusinessRuleException('Supplier-return quantity exceeds the accepted receipt quantity.');
                }

                $grnItem->update([
                    'quantity_received' => bcsub((string) $grnItem->quantity_received, $quantity, 3),
                    'quantity_accepted' => bcsub((string) $grnItem->quantity_accepted, $quantity, 3),
                ]);
                $poItem->update([
                    'quantity_received' => bcsub((string) $poItem->quantity_received, $quantity, 3),
                    'quantity_accepted' => bcsub((string) $poItem->quantity_accepted, $quantity, 3),
                ]);
            }
            // The source GRN/PO quantities are now reduced authoritatively (or
            // were already reduced by the caller); keeping the old reservation
            // active would subtract the shipped quantity a second time from
            // future availability.
            $this->releaseSourceAllocation($item);

            // Prefer the credited bill line's own expense account — it is what
            // the payable debited. A system-opened line has no bill-item link
            // even when the bill exists, so match one by item; only then fall
            // back to the dedicated purchase-return expense account. (A supplier
            // credit note is posted against an EXPENSE account; the old fallback
            // named the raw materials ASSET account, which the credit-note
            // service rejects.) A bill-less supplier return has no bill item at
            // all, so this fallback is the only account that will resolve.
            $billItem = $item->source_bill_item_id
                ? BillItem::query()->where('bill_id', $rma->bill_id)->find($item->source_bill_item_id)
                : null;
            if (! $billItem && $rma->bill_id) {
                $billItem = BillItem::query()
                    ->where('bill_id', $rma->bill_id)
                    ->where('item_id', $item->item_id)
                    ->orderByDesc('id')
                    ->first();
            }
            $settings = app(\App\Common\Services\SettingsService::class);
            $accountId = $billItem?->expense_account_id
                ?? Account::query()->where('code', $settings->requiredString('accounting.accounts.purchase_return_expense_code'))->value('id')
                ?? Account::query()->where('code', $settings->requiredString('accounting.default_expense_account_code'))->value('id');
            if (! $accountId) {
                throw new BusinessRuleException('No accounting account is available for the supplier credit.');
            }
            $amount = Money::mul($quantity, (string) $item->unit_price);
            if (bccomp($amount, '0', 2) > 0) {
                $creditLines[$accountId] = bcadd($creditLines[$accountId] ?? '0', $amount, 2);
            }
            $replacementLines[] = [
                'item_id'    => $item->item_id,
                'description' => $poItem->description,
                'quantity'   => $quantity,
                'unit'       => $poItem->unit,
                'unit_price' => (string) $poItem->unit_price,
            ];
        }

        $this->recalculatePurchaseOrderReceiptStatus($rma, $by);

        if ($creditLines !== []) {
            $creditNote = $this->creditNotes->create([
                'type'              => 'supplier',
                'vendor_id'         => $rma->vendor_id,
                'bill_id'           => $rma->bill_id,
                'return_request_id' => $rma->id,
                'date'              => now()->toDateString(),
                'is_vatable'        => (bool) ($rma->bill?->is_vatable ?? $this->taxPolicy->isVatRegistered()),
                'reason'            => "Supplier return — RMA {$rma->rma_number}",
                'lines'             => collect($creditLines)->map(
                    fn ($amount, $accountId) => [
                        'account_id'  => (int) $accountId,
                        'description' => "Returned goods — RMA {$rma->rma_number}",
                        'amount'      => $amount,
                    ]
                )->values()->all(),
            ], $by);
            $creditNote = $this->creditNotes->finalize($creditNote, $by);

            if ($rma->bill_id && Money::gt((string) $rma->bill->balance, Money::zero())) {
                $applyAmount = Money::lt((string) $creditNote->total_amount, (string) $rma->bill->balance)
                    ? Money::round2((string) $creditNote->total_amount)
                    : Money::round2((string) $rma->bill->balance);
                $this->creditNotes->apply($creditNote, [
                    'bill_id' => $rma->bill_id,
                    'amount'  => $applyAmount,
                ], $by);
            }
            $rma->update(['credit_note_id' => $creditNote->id]);
        }

        if ($createReplacementPo) {
            $replacement = $this->purchaseOrders->create([
                'vendor_id'           => $rma->vendor_id,
                'date'                => now()->toDateString(),
                'is_vatable'          => $this->taxPolicy->isVatRegistered(),
                'remarks'             => "Replacement for supplier RMA {$rma->rma_number}",
                'items'               => $replacementLines,
            ], $by, true);
            $rma->update(['replacement_purchase_order_id' => $replacement->id]);
        }
    }

    private function recalculatePurchaseOrderReceiptStatus(ReturnRequest $rma, User $by): void
    {
        $po = $rma->purchaseOrder()->lockForUpdate()->firstOrFail();
        // Decimal-safe: PO quantities are decimal, and a float comparison can
        // classify a fully-received fractional PO as partially received (or the
        // reverse) at the boundary. bccomp at 3 dp is the column's precision.
        $ordered  = (string) $po->items()->sum('quantity');
        $accepted = (string) $po->items()->sum('quantity_accepted');
        $zeroStatus = $po->sent_to_supplier_at
            ? PurchaseOrderStatus::Sent
            : PurchaseOrderStatus::Approved;
        $status = bccomp($accepted, '0', 3) <= 0
            ? $zeroStatus
            : (bccomp($accepted, $ordered, 3) < 0
                ? PurchaseOrderStatus::PartiallyReceived
                : PurchaseOrderStatus::Received);

        if ($po->status === $status) {
            return;
        }

        $po->forceFill(['status' => $status])->save();
        app(ChainBroadcaster::class)->broadcastFor($po->fresh(), $status->value, $by);
    }

    /**
     * REC-13 — stage a DRAFT customer credit note for a customer return, one
     * line per returned item (sourced from the original invoice lines via
     * ReturnRequestItem::source_invoice_item_id). The GL is untouched until
     * finance finalizes the draft — same review-then-post pattern as the
     * auto-bill / auto-invoice chains. Credited amount per line is the returned
     * quantity × unit price; VAT is added on top by CreditNoteService.
     *
     * Returns null when nothing is creditable (all lines scrapped or routed
     * onward to the supplier).
     */
    private function createCreditNote(ReturnRequest $rma, User $by): ?\App\Modules\Accounting\Models\CreditNote
    {
        if ($rma->finance_only && ! $rma->finance_only_approved_by) {
            throw new BusinessRuleException('Finance-only returns require explicit approval before credit.');
        }
        $rma->loadMissing(['items.product']);
        $defaultRevenueId = Account::query()
            ->where('code', $this->accountPolicies->revenue())->value('id');
        $hashids = app('hashids');

        $lines = [];
        foreach ($rma->items as $item) {
            // A line with no creditable provenance is only an error when the
            // RMA is invoice-backed (there is a receivable to reverse). An
            // uninvoiced product-only line has no AR document at all, so it is
            // skipped rather than blocking a stock disposition that has nothing
            // to do with credit. Sales-order / delivery provenance is a valid
            // credit source even without an invoice.
            if (! $rma->finance_only && ! $item->item_id && $item->product_id) {
                if (! $rma->invoice_id) {
                    continue;
                }
                throw new BusinessRuleException('Product-only returns require explicit finance-only classification before credit.');
            }
            if (! $rma->finance_only && $item->item_id
                && ! $item->source_invoice_item_id && ! $item->source_sales_order_item_id
                && ! $item->source_delivery_item_id) {
                if (! $rma->invoice_id) {
                    continue;
                }
                throw new BusinessRuleException('A stockable return credit requires invoice, delivery, or sales-order line provenance.');
            }
            if ($item->disposition === null
                || $item->disposition === DispositionType::ReturnToSupplier->value) {
                continue;
            }
            $amount = Money::mul($this->settledQuantity($item), (string) ($item->original_unit_price ?? $item->unit_price));
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }
            $revenueId = $item->product?->revenue_account_id ?? $defaultRevenueId;
            if (! $revenueId) {
                // Configuration, same class as "Required account 1010 not found
                // in COA": the fallback comes from
                // `accounting.default_sales_revenue_account_code`. The clerk
                // settling an RMA cannot set it, so left unmapped.
                throw new \RuntimeException('Default revenue account not configured.');
            }
            $lines[] = [
                'account_id'  => $hashids->encode((int) $revenueId),
                'description' => ($item->product?->name ?? 'Returned goods')
                    ." — RMA {$rma->rma_number}",
                'amount'      => Money::round2($amount),
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->creditNotes->create([
            'type'              => 'customer',
            'customer_id'       => $rma->customer_id,
            'invoice_id'        => $rma->invoice_id,
            'return_request_id' => $rma->id,
            'date'              => now()->toDateString(),
            'is_vatable'        => $this->taxPolicy->isVatRegistered(),
            'reason'            => "Customer return — RMA {$rma->rma_number}",
            'lines'             => $lines,
        ], $by);
    }

    private function defaultReturnQuarantineLocation(): WarehouseLocation
    {
        $location = WarehouseLocation::query()
            ->with('zone')
            ->where('is_active', true)
            ->whereHas('zone', fn ($q) => $q
                ->where('zone_type', WarehouseZoneType::Quarantine->value)
                ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('is_active', true)))
            ->orderBy('id')
            ->first();
        if (! $location) {
            throw new BusinessRuleException('No active quarantine location is configured for returned stock.');
        }
        return $location;
    }

    /**
     * Move every line whose disposition triggers inventory movement the moment
     * the disposition is recorded — no waiting for a separate completion step.
     * Customer restock/rework lines come back into stock (AdjustmentIn);
     * supplier return_to_supplier lines ship out (ReturnToVendor). The caller
     * must name the warehouse location; lines already stamped with
     * stock_movement_quantity are skipped, so a later complete() can never
     * move them twice.
     */
    private function moveAtDispose(ReturnRequest $rma, ?int $locationId, User $by): void
    {
        // A line already stamped with stock_movement_quantity was handled by the
        // caller (system-opened RMA) and needs no movement, so it must not force
        // the operator to name a warehouse location for goods that are gone.
        $movable = $rma->items->filter(fn (ReturnRequestItem $line) => $this->shouldMove($line, $rma)
            && bccomp((string) $line->stock_movement_quantity, '0', 3) <= 0);
        if ($movable->isEmpty()) {
            return;
        }
        $needsGoodLocation = $rma->type !== ReturnRequestType::CustomerReturn
            || $movable->contains(fn (ReturnRequestItem $line) => $line->disposition !== DispositionType::Scrap->value);
        if (! $locationId && $needsGoodLocation) {
            // Backstop — the same rule already fired at the top of dispose().
            throw new BusinessRuleException(
                $rma->type === ReturnRequestType::CustomerReturn
                    ? 'Select the warehouse location returned restock lines are received back into.'
                    : 'Select the warehouse location the returned goods ship out from.'
            );
        }

        $last = null;
        $movedQty = '0';
        $restockedQty = '0';
        foreach ($rma->items as $line) {
            $movement = $this->moveLine($line, $rma, $locationId, $by);
            if ($movement) {
                $last = $movement;
                $movedQty = bcadd($movedQty, (string) $line->stock_movement_quantity, 3);
                if ($rma->type === ReturnRequestType::CustomerReturn
                    && in_array($line->disposition, [
                        DispositionType::Restock->value,
                        DispositionType::Rework->value,
                    ], true)) {
                    $restockedQty = bcadd($restockedQty, (string) $line->stock_movement_quantity, 3);
                }
            }
        }
        if ($last) {
            $rma->update(['stock_movement_id' => $last->id]);
        }

        // 2026-08-08 — tell the right team the moment the goods physically
        // move. Customer restocks land back on the shelf (warehouse alert);
        // supplier returns ship out to the vendor (purchasing alert).
        // Scrap is a real ledger movement out of quarantine, but it is not a
        // restock and must never notify the warehouse as if stock returned to
        // sellable inventory.
        $notificationQty = $rma->type === ReturnRequestType::CustomerReturn
            ? $restockedQty
            : $movedQty;
        if (bccomp($notificationQty, '0', 3) <= 0) {
            return;
        }
        if ($rma->type === ReturnRequestType::CustomerReturn) {
            $this->notifyRestock($rma, $notificationQty);
        } else {
            $this->notifySupplierShip($rma, $notificationQty);
        }
    }

    /**
     * Best-effort alert to everyone with inventory access that returned goods
     * are back in sellable stock. Never fails the dispose — a notification
     * problem must not roll back a stock movement.
     */
    private function notifyRestock(ReturnRequest $rma, string $quantity): void
    {
        $this->sendMovementAlert(
            $rma,
            $quantity,
            'return.restocked',
            'Returned goods restocked',
            'were moved back into sellable stock. Shelf and verify them.',
            'inventory.view',
        );
    }

    /**
     * 2026-08-08 — alert purchasing the moment supplier-returned goods ship
     * back out (ReturnToVendor), so the shipment is tracked and the vendor
     * credit is followed up. Best-effort, like the restock alert.
     */
    private function notifySupplierShip(ReturnRequest $rma, string $quantity): void
    {
        $this->sendMovementAlert(
            $rma,
            $quantity,
            'return.shipped_to_vendor',
            'Returned goods shipped to vendor',
            'were shipped back to the vendor. Track the shipment and follow up on the credit.',
            'purchasing.po.view',
        );
    }

    /**
     * Shared best-effort envelope for the dispose-time movement alerts.
     * Never fails the dispose — a notification problem must not roll back a
     * stock movement.
     */
    private function sendMovementAlert(
        ReturnRequest $rma,
        string $quantity,
        string $type,
        string $title,
        string $messageSuffix,
        string $permissionSlug,
    ): void
    {
        try {
            // {permissionSlug} holders (inventory.view for restock,
            // purchasing.po.view for ship-out), plus wildcard admins —
            // system_admin holds a '*' permission rather than the explicit
            // slug, so a plain whereHas would silently drop the very person
            // performing the disposition.
            $recipients = User::query()
                // NotificationService only needs the identity envelope. User's
                // model-wide role eager load is useful for authorization, but
                // it is unnecessary here and made every disposition fetch a
                // wide user row plus a second role query.
                ->without('role')
                ->select(['id', 'name', 'email'])
                ->where(function ($q) use ($permissionSlug) {
                    $q->whereHas('role', fn ($role) => $role->where('slug', 'system_admin'))
                        ->orWhereHas('role.permissions', fn ($perm) => $perm->where('slug', $permissionSlug));
                })
                ->where('is_active', true)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $label = rtrim(rtrim($quantity, '0'), '.');

            $this->notifications->send($recipients, $type, [
                'title'       => $title,
                'message'     => "RMA {$rma->rma_number}: {$label} unit(s) {$messageSuffix}",
                'link_to'     => '/return-management/'.$rma->hash_id,
                'entity_type' => 'return_request',
                'entity_id'   => $rma->hash_id,
                'rma_number'  => $rma->rma_number,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ReturnRequestService: movement notification failed', [
                'rma_id' => $rma->id,
                'type'   => $type,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Complete the RMA (inspected → completed).
     *
     * Customer-return restock/rework lines moved at dispose() already and are
     * skipped here (idempotent). Supplier-return return_to_supplier lines still
     * ship out on completion (ReturnToVendor). M-36 — the location is only
     * required when a line actually still needs to move; never fall back to an
     * arbitrary first location.
     */
    public function complete(ReturnRequest $rma, User $by, ?int $locationId = null): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma, $by, $locationId): ReturnRequest {
            // Completion is a cross-module terminal transition. Re-read and
            // lock the RMA before checking status or movement stamps so two
            // stale requests cannot both issue stock and close the same RMA.
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($locked, ReturnRequestStatus::Inspected);
            $this->ensureInspectionHandoffReady($locked);
            $locked->load('items.product');

            if (! $locationId && $this->hasPendingMovement($locked)) {
                throw new BusinessRuleException('A warehouse location is required to complete a return.');
            }

            // Completing straight from Inspected skipped dispose() entirely, so the
            // RMA closed with no credit note, no NCR and every line restocked
            // regardless of condition — a defective batch silently re-entered
            // sellable stock and the customer was never credited.
            if ($locked->disposition_status !== 'disposed') {
                throw new BusinessRuleException(
                    'Record a disposition for every returned line before completing this RMA.'
                );
            }

            $this->states->transition($locked, ReturnRequestStatus::Completed);
            $locked->update([
                'status'       => ReturnRequestStatus::Completed,
                'completed_by' => $by->id,
                'completed_at' => now(),
            ]);

            if ($locked->items->isNotEmpty() && $locationId) {
                $last = null;

                foreach ($locked->items as $line) {
                    $movement = $this->moveLine($line, $locked, $locationId, $by);
                    if ($movement) {
                        $last = $movement;
                    }
                }

                // Link the last stock movement to the RMA root (informational).
                if ($last) {
                    $locked->update(['stock_movement_id' => $last->id]);
                }
            }

            return $locked->fresh()->load(['items', 'stockMovement.toLocation', 'stockMovement.fromLocation']);
        });

        event(new ReturnRequestUpdated($updated, 'completed'));

        return $updated;
    }

    /**
     * Move one disposed line's goods, unless they already moved (restocked at
     * dispose time). Customer returns: restock/rework → AdjustmentIn into the
     * destination. Supplier returns: return_to_supplier → ReturnToVendor out of
     * the source. Stamps the moved quantity on the line for idempotency.
     */
    private function moveLine(ReturnRequestItem $line, ReturnRequest $rma, ?int $locationId, User $by): ?StockMovement
    {
        if (bccomp((string) $line->stock_movement_quantity, '0', 3) > 0) {
            return null; // already restocked / shipped — never move twice
        }
        if (! $this->shouldMove($line, $rma)) {
            return null;
        }

        $itemId = $this->resolvableItemId($line);
        if (! $itemId) {
            Log::warning('ReturnRequestService: kept line has no inventory item to move', [
                'rma_id'        => $rma->id,
                'line_id'       => $line->id,
                'disposition'   => $line->disposition,
                'product_id'    => $line->product_id,
            ]);
            return null;
        }

        $qty = $this->settledQuantity($line);
        if (bccomp($qty, '0', 3) <= 0) {
            return null;
        }

        if ($rma->type === ReturnRequestType::CustomerReturn) {
            if (! $line->quarantine_movement_id || ! $line->quarantine_location_id) {
                throw new BusinessRuleException('A stockable customer return must be quarantined before disposition.');
            }
            $quarantine = WarehouseLocation::query()->with('zone.warehouse')->findOrFail((int) $line->quarantine_location_id);
            $this->assertLocationUsable(
                $quarantine,
                WarehouseZoneType::Quarantine,
                'Returned stock must remain in an active quarantine location until disposition.',
            );
            if ($line->disposition !== DispositionType::Scrap->value && ! $locationId) {
                throw new BusinessRuleException('A good warehouse location is required to release returned stock.');
            }
            if ($line->disposition !== DispositionType::Scrap->value && $locationId) {
                $destination = WarehouseLocation::query()->with('zone.warehouse')->findOrFail($locationId);
                $this->assertLocationUsable(
                    $destination,
                    null,
                    'Returned stock must be released into an active good-stock warehouse location.',
                );
            }
            $type = $line->disposition === DispositionType::Scrap->value
                ? StockMovementType::Scrap
                : StockMovementType::Transfer;
            $movement = $this->stockMovements->move(new StockMovementInput(
                type: $type,
                itemId: (int) $itemId,
                fromLocationId: (int) $line->quarantine_location_id,
                toLocationId: $type === StockMovementType::Scrap ? null : $locationId,
                quantity: $qty,
                referenceType: 'return_request',
                referenceId: $rma->id,
                remarks: "RMA {$rma->rma_number}: Customer return",
                createdBy: $by->id,
            ));
            if ($line->lot_number) {
                $this->stockMovements->stampLot($movement, $line->lot_number);
            }
            $line->update([
                'quarantine_release_movement_id' => $movement->id,
                'quarantine_status' => $type === StockMovementType::Scrap ? 'scrapped' : 'released',
            ]);
        } else {
            // Supplier return → remove stock.
            $sourceLocation = WarehouseLocation::query()->with('zone.warehouse')->findOrFail((int) $locationId);
            $this->assertLocationUsable(
                $sourceLocation,
                null,
                'Supplier-return stock must ship from an active good-stock warehouse location.',
            );
            $movement = $this->stockMovements->move(new StockMovementInput(
                type: StockMovementType::ReturnToVendor,
                itemId: (int) $itemId,
                fromLocationId: $locationId,
                quantity: $qty,
                referenceType: 'return_request',
                referenceId: $rma->id,
                remarks: "RMA {$rma->rma_number}: Supplier return",
                createdBy: $by->id,
            ));
        }

        $line->update(['stock_movement_quantity' => $qty]);

        return $movement;
    }

    /**
     * Whether any disposed line still needs a stock movement at completion.
     * Lines already restocked/shipped (stock_movement_quantity set) and lines
     * whose disposition triggers no movement are excluded.
     */
    private function hasPendingMovement(ReturnRequest $rma): bool
    {
        foreach ($rma->items as $line) {
            if (bccomp((string) $line->stock_movement_quantity, '0', 3) > 0) {
                continue;
            }
            if (! $this->shouldMove($line, $rma)) {
                continue;
            }
            if ($this->resolvableItemId($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inventory item a line's goods move against.
     *
     * Returns the line's item_id when set. Products (CRM finished goods) have
     * NO inventory-item mapping in this system — a line raised against a
     * product alone cannot re-enter the item ledger, so it resolves to null
     * and is skipped (the pre-change code called a nonexistent Product::items()
     * relation and crashed with a 500 on exactly that path).
     */
    private function resolvableItemId(ReturnRequestItem $line): ?int
    {
        if ($line->item_id) {
            return (int) $line->item_id;
        }

        return null;
    }

    /**
     * Whether a disposed line triggers inventory movement at completion.
     *
     * Customer returns: only restock/rework dispositions add inventory back.
     * Supplier returns: only return_to_supplier disposition ships goods out.
     * A line with no disposition is treated as restockable (the pre-disposition flow).
     */
    private function shouldMove(ReturnRequestItem $item, ReturnRequest $rma): bool
    {
        if ($rma->type === ReturnRequestType::CustomerReturn) {
            // Customer return: units with restock or rework dispositions go
            // back into inventory. Scrap is destroyed; return_to_supplier
            // doesn't apply (they came from the customer, not from us).
            return in_array($item->disposition, [
                DispositionType::Restock->value,
                DispositionType::Rework->value,
                DispositionType::Scrap->value,
                null, // no disposition recorded = pre-disposition flow, treat as restockable
            ], true);
        }

        // Supplier return: only return_to_supplier disposition ships goods out.
        // Scrap/rework don't apply (those are customer-return concepts).
        return $item->disposition === DispositionType::ReturnToSupplier->value;
    }

    /**
     * Reject a pending approval through the shared approval ledger.
     * Physical receipt and Quality handoff are deliberately not rejectable:
     * those states need an explicit compensating workflow rather than a
     * status-only mutation that strands stock or inspections.
     */
    public function reject(ReturnRequest $rma, User $by, string $reason): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma, $by, $reason): ReturnRequest {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            if ($locked->status !== ReturnRequestStatus::PendingApproval) {
                throw new BusinessRuleException(
                    'Only a pending-approval RMA can be rejected. Physical receipt and Quality handoff require a compensating workflow.'
                );
            }

            // ApprovalService locks the current step, enforces the submitter
            // and role checks, and skips later steps. Do not create a second
            // rejection path that can disagree with approval_records.
            $this->approvals->reject($locked, $by, $reason);
            $this->states->transition($locked, ReturnRequestStatus::Rejected);
            $update = [
                'status'      => ReturnRequestStatus::Rejected,
                'rejected_at' => now(),
                'rejected_by' => $by->id,
            ];
            $update['internal_notes'] = $this->appendNote($locked, "Rejected: {$reason}");
            $this->releaseSourceAllocations($locked);
            $locked->update($update);
            return $locked->fresh();
        });

        event(new ReturnRequestUpdated($updated, 'rejected'));

        return $updated;
    }

    /**
     * Cancel (draft/pending_approval → cancelled).
     */
    public function cancel(ReturnRequest $rma, ?string $reason = null): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma, $reason): ReturnRequest {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            if (! in_array($locked->status, [ReturnRequestStatus::Draft, ReturnRequestStatus::PendingApproval], true)) {
                throw new BusinessRuleException("Only draft or pending_approval RMA can be cancelled.");
            }
            if ($locked->status === ReturnRequestStatus::PendingApproval) {
                ApprovalRecord::query()
                    ->where('approvable_type', $locked->getMorphClass())
                    ->where('approvable_id', $locked->getKey())
                    ->where('is_current', true)
                    ->whereIn('action', ['pending', 'skipped'])
                    ->update(['action' => 'superseded', 'is_current' => false]);
            }
            $this->states->transition($locked, ReturnRequestStatus::Cancelled);
            $this->releaseSourceAllocations($locked);
            $update = [
                'status'        => ReturnRequestStatus::Cancelled,
                'cancelled_at'  => now(),
            ];
            if ($reason) {
                $update['internal_notes'] = $this->appendNote($locked, "Cancelled: {$reason}");
            }
            $locked->update($update);
            return $locked->fresh();
        });

        event(new ReturnRequestUpdated($updated, 'cancelled'));

        return $updated;
    }

    private function releaseSourceAllocations(ReturnRequest $rma): void
    {
        $rma->sourceAllocations()
            ->whereNull('released_at')
            ->update(['released_at' => now(), 'quantity' => '0.000']);
    }

    private function releaseSourceAllocation(ReturnRequestItem $line): void
    {
        $line->sourceAllocations()
            ->whereNull('released_at')
            ->update(['released_at' => now(), 'quantity' => '0.000']);
    }

    /**
     * Reject / cancel reasons used to overwrite internal_notes wholesale,
     * destroying the inspection findings recorded by inspect().
     */
    private function appendNote(ReturnRequest $rma, string $note): string
    {
        $existing = trim((string) $rma->internal_notes);

        return $existing === '' ? $note : "{$existing}\n\n{$note}";
    }

    private function ensureStatus(ReturnRequest $rma, ReturnRequestStatus $expected): void
    {
        if ($rma->status !== $expected) {
            throw new BusinessRuleException(
                "Expected status {$expected->value}, got {$rma->status->value}."
            );
        }
    }

    private function ensureInspectionHandoffReady(ReturnRequest $rma): void
    {
        if ($rma->inspection_handoff_status === ReturnInspectionHandoffStatus::ManualRequired) {
            throw new BusinessRuleException(
                'Quality inspection staging is incomplete. Fix the Quality setup and retry the handoff before disposing or completing this RMA.'
            );
        }
    }

    /**
     * Require Quality's authoritative return-stage verdict to be COMPLETE for
     * every product represented by the RMA before any disposition side effect
     * runs.
     *
     * Passing/failing is disposition-dependent: returning goods to sellable
     * stock (`restock`) needs a `passed` verdict, but a genuinely defective
     * unit that FAILED QC must still be disposable as `scrap` or `rework` —
     * otherwise the failure itself dead-ends the RMA. For those dispositions
     * either terminal verdict is acceptable.
     *
     * Item-only lines have no product inspection specification and retain the
     * existing item-only lifecycle. Cancelled inspection rows are not active
     * evidence; if no replacement active row exists, the product is treated as
     * missing and remains blocked.
     *
     * @param array<int, array<string, mixed>> $dispositions
     */
    private function ensureReturnInspectionsReady(ReturnRequest $rma, array $dispositions): void
    {
        $requiredProductIds = $rma->items
            ->pluck('product_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($requiredProductIds->isEmpty()) {
            return;
        }

        // Which products are headed back to sellable stock and therefore need a
        // POSITIVE verdict, versus merely a completed one.
        $restockProductIds = [];
        foreach ($dispositions as $row) {
            $line = $rma->items->firstWhere('hash_id', $row['item_id'] ?? null);
            if (! $line || ! $line->product_id) {
                continue;
            }
            if (($row['disposition'] ?? null) === DispositionType::Restock->value) {
                $restockProductIds[(int) $line->product_id] = true;
            }
        }

        $stage = $rma->type === ReturnRequestType::SupplierReturn
            ? InspectionStage::SupplierReturn
            : InspectionStage::CustomerReturn;

        $activeInspections = Inspection::query()
            ->where('entity_type', InspectionEntityType::ReturnRequest->value)
            ->where('entity_id', $rma->id)
            ->where('stage', $stage->value)
            ->whereIn('product_id', $requiredProductIds->all())
            ->where('status', '<>', InspectionStatus::Cancelled->value)
            ->get(['product_id', 'status']);

        $missingProductIds = [];
        $unresolvedProductIds = [];

        foreach ($requiredProductIds as $productId) {
            $productInspections = $activeInspections->where('product_id', $productId);

            if ($productInspections->isEmpty()) {
                $missingProductIds[] = $productId;
                continue;
            }

            $acceptedStatuses = isset($restockProductIds[$productId])
                ? [InspectionStatus::Passed->value]
                : [InspectionStatus::Passed->value, InspectionStatus::Failed->value];

            $complete = $productInspections->every(function (Inspection $inspection) use ($acceptedStatuses): bool {
                $status = $inspection->status instanceof InspectionStatus
                    ? $inspection->status->value
                    : (string) $inspection->status;

                return in_array($status, $acceptedStatuses, true);
            });

            if (! $complete) {
                $unresolvedProductIds[] = $productId;
            }
        }

        if ($missingProductIds !== []) {
            throw new BusinessRuleException(
                'Every product-linked return inspection must be completed (passed or failed) before disposition; '
                .'no active inspection exists for product IDs: '.implode(', ', $missingProductIds).'.'
            );
        }

        if ($unresolvedProductIds !== []) {
            throw new BusinessRuleException(
                'Every product-linked return inspection must be completed (passed or failed) before disposition. '
                .'A restock line requires a passed inspection. Unresolved product IDs: '
                .implode(', ', $unresolvedProductIds).'.'
            );
        }
    }
}
