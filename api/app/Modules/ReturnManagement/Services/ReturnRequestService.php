<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\GrnItem;
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
use App\Common\Services\ApprovalService;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

            if (! empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (! array_key_exists('unit_price', $item) || $item['unit_price'] === null || $item['unit_price'] === '') {
                        throw new BusinessRuleException('Each return line requires an authoritative unit price.');
                    }
                    $isStockable = !empty($item['item_id']);
                    $financeOnly = (bool) ($data['finance_only'] ?? false);
                    if (!$isStockable && !$financeOnly) {
                        throw new BusinessRuleException('Product-only returns must be explicitly classified as finance-only.');
                    }
                    if ($financeOnly && trim((string) ($data['finance_only_reason'] ?? '')) === '') {
                        throw new BusinessRuleException('Finance-only returns require an explicit non-stock reason.');
                    }
                    if ($rma->type === ReturnRequestType::CustomerReturn && $isStockable && !$financeOnly
                        && empty($item['source_invoice_item_id']) && empty($item['source_sales_order_item_id'])
                        && empty($item['source_delivery_item_id'])) {
                        throw new BusinessRuleException('Stockable returns require invoice or sales-order line provenance.');
                    }
                    if (!empty($item['source_grn_item_id'])) {
                        $grn = GrnItem::query()->findOrFail((int) $item['source_grn_item_id']);
                        if ($grn->material_lot_number && trim((string) ($item['lot_number'] ?? '')) === '') {
                            throw new BusinessRuleException('Controlled returned stock requires lot provenance from the source receipt.');
                        }
                    }
                    $sourcePrice = null;
                    if (!empty($item['source_invoice_item_id'])) {
                        $source = InvoiceItem::query()->findOrFail((int) $item['source_invoice_item_id']);
                        if ($rma->invoice_id && (int) $source->invoice_id !== (int) $rma->invoice_id) {
                            throw new BusinessRuleException('Return invoice-line provenance does not match the RMA invoice.');
                        }
                        $sourcePrice = (string) $source->unit_price;
                    } elseif (!empty($item['source_sales_order_item_id'])) {
                        $source = SalesOrderItem::query()->findOrFail((int) $item['source_sales_order_item_id']);
                        if ($rma->sales_order_id && (int) $source->sales_order_id !== (int) $rma->sales_order_id) {
                            throw new BusinessRuleException('Return sales-order-line provenance does not match the RMA order.');
                        }
                        $sourcePrice = (string) $source->unit_price;
                    } elseif (!empty($item['source_delivery_item_id'])) {
                        $source = DeliveryItem::query()->with('salesOrderItem')->findOrFail((int) $item['source_delivery_item_id']);
                        if ($rma->sales_order_id && (int) $source->salesOrderItem->sales_order_id !== (int) $rma->sales_order_id) {
                            throw new BusinessRuleException('Return delivery-line provenance does not match the RMA order.');
                        }
                        if (!empty($item['product_id']) && (int) $source->salesOrderItem->product_id !== (int) $item['product_id']) {
                            throw new BusinessRuleException('Return delivery-line provenance does not match the returned product.');
                        }
                        $sourcePrice = (string) $source->unit_price;
                    }
                    $quantity = (string) $item['quantity'];
                    $unitPrice = $sourcePrice ?? (string) $item['unit_price'];
                    ReturnRequestItem::create([
                        'return_request_id'         => $rma->id,
                        'product_id'                => $item['product_id'] ?? null,
                        'item_id'                   => $item['item_id'] ?? null,
                        'quantity'                  => $quantity,
                        'unit_price'                => $unitPrice,
                        'original_unit_price'       => $unitPrice,
                        'total'                     => bcmul($quantity, $unitPrice, 2),
                        'reason'                    => $item['reason'] ?? null,
                        'condition'                 => $item['condition'] ?? null,
                        'source_sales_order_item_id' => $item['source_sales_order_item_id'] ?? null,
                        'source_invoice_item_id'    => $item['source_invoice_item_id'] ?? null,
                        'source_delivery_item_id'   => $item['source_delivery_item_id'] ?? null,
                        'source_po_item_id'         => $item['source_po_item_id'] ?? null,
                        'source_grn_item_id'        => $item['source_grn_item_id'] ?? null,
                        'source_bill_item_id'       => $item['source_bill_item_id'] ?? null,
                        'lot_number'                => $item['lot_number'] ?? null,
                        'serial_number'             => $item['serial_number'] ?? null,
                    ]);
                }
            }

            $rma->load('items');
            return $rma;
        });
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

            $locked->update([
                'status'      => ReturnRequestStatus::Received,
                'received_at' => now(),
            ]);

            foreach ($locked->items as $item) {
                // Default to the requested quantity so a receipt recorded without
                // per-line counts still yields a usable returned_quantity — every
                // downstream step (credit note, restock) reads that column, and it
                // previously stayed at zero because the caller keyed the map by
                // hash_id while this loop looked for the raw integer PK.
                $qty = (string) ($receivedQtys[$item->id] ?? $item->quantity);
                $updates = ['returned_quantity' => $qty];
                if ($locked->type === ReturnRequestType::CustomerReturn && $item->item_id && bccomp($qty, '0', 3) > 0) {
                    if ($item->quarantine_movement_id) {
                        throw new BusinessRuleException("Return line {$item->id} has already entered quarantine.");
                    }
                    $location = $quarantineLocationId
                        ? WarehouseLocation::query()->with('zone')->findOrFail($quarantineLocationId)
                        : $this->defaultReturnQuarantineLocation();
                    $zoneType = $location->zone?->zone_type;
                    $zoneType = $zoneType instanceof WarehouseZoneType ? $zoneType : WarehouseZoneType::tryFrom((string) $zoneType);
                    if ($zoneType !== WarehouseZoneType::Quarantine) {
                        throw new BusinessRuleException('Returned stock must be received into a quarantine-zone location.');
                    }
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

            $batchQty = (int) ceil((float) $productItems->sum(
                fn (ReturnRequestItem $item): float => (float) ($item->returned_quantity > 0
                    ? $item->returned_quantity
                    : $item->quantity)
            ));

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
            $this->ensureReturnInspectionsPassed($rma);

            // Fail fast: movement lines (restock/rework for customer,
            // return_to_supplier for supplier) need a warehouse location BEFORE
            // the credit-note / replacement-PO work runs — never spend the
            // effort and roll it all back over a missing location. Evaluated
            // against the REQUESTED dispositions (stored ones are still null).
            $requestsMovement = collect($dispositions)->contains(
                fn (array $row) => $rma->type === ReturnRequestType::SupplierReturn
                    ? ($row['disposition'] ?? null) === DispositionType::ReturnToSupplier->value
                    : in_array(
                        $row['disposition'] ?? null,
                        [DispositionType::Restock->value, DispositionType::Rework->value],
                        true,
                    )
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
                        'affected_quantity'  => (int) ($item->returned_quantity > 0
                            ? $item->returned_quantity
                            : $item->quantity),
                        'is_auto_generated'  => true,
                    ], $by);
                    $item->update(['ncr_id' => $ncr->id]);
                }
            }

            $rma->load('items');
            if ($rma->type === ReturnRequestType::CustomerReturn && $rma->invoice_id) {
                // 2026-08-08 — draft customer credit note, one line per returned
                // item (only what was actually sent back and kept — lines routed
                // onward to the supplier or scrapped are excluded). The credit
                // stays DRAFT until finance finalizes it (GL untouched), mirroring
                // the auto-bill / auto-invoice review-then-post pattern.
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
                'items', 'creditNote', 'replacementPurchaseOrder',
                'stockMovement.toLocation', 'stockMovement.fromLocation',
            ]);
        });

        event(new ReturnRequestUpdated($updated, 'disposition recorded'));

        return $updated;
    }

    /**
     * The quantity that physically came back on a line, falling back to the
     * requested quantity for RMAs received before per-line counts existed.
     */
    private function settledQuantity(ReturnRequestItem $item): string
    {
        return bccomp((string) $item->returned_quantity, '0', 3) > 0
            ? (string) $item->returned_quantity
            : (string) $item->quantity;
    }

    private function creditableAmount(ReturnRequestItem $item): string
    {
        return bcmul($this->settledQuantity($item), (string) $item->unit_price, 2);
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

        $creditLines = [];
        $replacementLines = [];
        foreach ($returnedItems->sortBy('source_grn_item_id') as $item) {
            if (! $item->source_grn_item_id || ! $item->source_po_item_id) {
                throw new BusinessRuleException('Each supplier-return line requires source GRN and PO lines.');
            }

            $quantity = (string) ((float) $item->returned_quantity > 0 ? $item->returned_quantity : $item->quantity);
            $grnItem = GrnItem::query()->with('grn')->lockForUpdate()->findOrFail($item->source_grn_item_id);
            $poItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($item->source_po_item_id);

            if ((int) $grnItem->purchase_order_item_id !== (int) $poItem->id
                || (int) $poItem->purchase_order_id !== (int) $rma->purchase_order_id
                || (int) $grnItem->item_id !== (int) $item->item_id
                || (int) $grnItem->grn->vendor_id !== (int) $rma->vendor_id) {
                throw new BusinessRuleException('Supplier-return source documents do not match the RMA.');
            }
            if (bccomp($quantity, '0', 3) <= 0
                || bccomp($quantity, (string) $grnItem->quantity_received, 3) > 0
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

            $billItem = $item->source_bill_item_id
                ? BillItem::query()->where('bill_id', $rma->bill_id)->find($item->source_bill_item_id)
                : null;
            $accountId = $billItem?->expense_account_id
                ?? Account::query()->where('code', app(\App\Common\Services\SettingsService::class)
                    ->requiredString('accounting.accounts.inventory_raw_material_code'))->value('id');
            if (! $accountId) {
                throw new BusinessRuleException('No accounting account is available for the supplier credit.');
            }
            $amount = bcmul($quantity, (string) $item->unit_price, 2);
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

            if ($rma->bill_id && bccomp((string) $rma->bill->balance, '0', 2) > 0) {
                $applyAmount = min((float) $creditNote->total_amount, (float) $rma->bill->balance);
                $this->creditNotes->apply($creditNote, [
                    'bill_id' => $rma->bill_id,
                    'amount'  => number_format($applyAmount, 2, '.', ''),
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
        $ordered = (float) $po->items()->sum('quantity');
        $accepted = (float) $po->items()->sum('quantity_accepted');
        $status = $accepted <= 0
            ? PurchaseOrderStatus::Approved
            : ($accepted < $ordered ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Received);

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
            if (! $rma->finance_only && ! $item->item_id && $item->product_id) {
                throw new BusinessRuleException('Product-only returns require explicit finance-only classification before credit.');
            }
            if (! $rma->finance_only && $item->item_id
                && ! $item->source_invoice_item_id && ! $item->source_sales_order_item_id
                && ! $item->source_delivery_item_id) {
                throw new BusinessRuleException('A stockable return credit requires invoice, delivery, or sales-order line provenance.');
            }
            if ($item->disposition === null
                || $item->disposition === DispositionType::ReturnToSupplier->value) {
                continue;
            }
            $amount = bcmul($this->settledQuantity($item), (string) ($item->original_unit_price ?? $item->unit_price), 2);
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }
            $revenueId = $item->product?->revenue_account_id ?? $defaultRevenueId;
            if (! $revenueId) {
                throw new \RuntimeException('Default revenue account not configured.');
            }
            $lines[] = [
                'account_id'  => $hashids->encode((int) $revenueId),
                'description' => ($item->product?->name ?? 'Returned goods')
                    ." — RMA {$rma->rma_number}",
                'amount'      => number_format((float) $amount, 2, '.', ''),
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
            ->whereHas('zone', fn ($q) => $q->where('zone_type', WarehouseZoneType::Quarantine->value))
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
        $movable = $rma->items->filter(fn (ReturnRequestItem $line) => $this->shouldMove($line, $rma));
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

        if ($rma->type === ReturnRequestType::CustomerReturn) {
            if (! $line->quarantine_movement_id || ! $line->quarantine_location_id) {
                throw new BusinessRuleException('A stockable customer return must be quarantined before disposition.');
            }
            if ($line->disposition !== DispositionType::Scrap->value && ! $locationId) {
                throw new BusinessRuleException('A good warehouse location is required to release returned stock.');
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
     * Reject (any active status → rejected).
     */
    public function reject(ReturnRequest $rma, ?string $reason = null): ReturnRequest
    {
        $updated = DB::transaction(function () use ($rma, $reason): ReturnRequest {
            $locked = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            if (! $locked->status->isActive()) {
                throw new BusinessRuleException("Cannot reject a {$locked->status->value} RMA.");
            }

            // Rejecting after dispose() already shipped units back to the
            // supplier, raised a debit memo and/or issued a customer credit
            // note would leave live documents against a dead RMA.
            if ($locked->disposition_status === 'disposed') {
                throw new BusinessRuleException(
                    'This RMA has already been disposed and cannot be rejected. '
                    . 'Reverse the linked credit or debit memo instead.'
                );
            }
            $update = [
                'status'      => ReturnRequestStatus::Rejected,
                'rejected_at' => now(),
            ];
            if ($reason) {
                $update['internal_notes'] = $this->appendNote($locked, "Rejected: {$reason}");
            }
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
     * Require Quality's authoritative return-stage verdict for every product
     * represented by the RMA before any disposition side effect runs.
     *
     * Item-only lines have no product inspection specification and retain the
     * existing item-only lifecycle. Cancelled inspection rows are not active
     * evidence; if no replacement active row exists, the product is treated as
     * missing and remains blocked.
     */
    private function ensureReturnInspectionsPassed(ReturnRequest $rma): void
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

        $unresolvedProductIds = $requiredProductIds->filter(function (int $productId) use ($activeInspections): bool {
            $productInspections = $activeInspections->where('product_id', $productId);

            return $productInspections->isEmpty()
                || $productInspections->contains(function (Inspection $inspection): bool {
                    $status = $inspection->status instanceof InspectionStatus
                        ? $inspection->status->value
                        : (string) $inspection->status;

                    return $status !== InspectionStatus::Passed->value;
                });
        });

        if ($unresolvedProductIds->isNotEmpty()) {
            throw new BusinessRuleException(
                'Every product-linked return inspection must be passed before disposition. '
                .'Unresolved product IDs: '.implode(', ', $unresolvedProductIds->all()).'.'
            );
        }
    }
}
