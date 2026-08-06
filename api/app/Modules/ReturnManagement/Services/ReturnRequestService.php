<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\TaxPolicyService;
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
                    $quantity = (string) $item['quantity'];
                    $unitPrice = (string) $item['unit_price'];
                    ReturnRequestItem::create([
                        'return_request_id'         => $rma->id,
                        'product_id'                => $item['product_id'] ?? null,
                        'item_id'                   => $item['item_id'] ?? null,
                        'quantity'                  => $quantity,
                        'unit_price'                => $unitPrice,
                        'total'                     => bcmul($quantity, $unitPrice, 2),
                        'reason'                    => $item['reason'] ?? null,
                        'condition'                 => $item['condition'] ?? null,
                        'source_sales_order_item_id' => $item['source_sales_order_item_id'] ?? null,
                        'source_invoice_item_id'    => $item['source_invoice_item_id'] ?? null,
                        'source_po_item_id'         => $item['source_po_item_id'] ?? null,
                        'source_grn_item_id'        => $item['source_grn_item_id'] ?? null,
                        'source_bill_item_id'       => $item['source_bill_item_id'] ?? null,
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
        $this->ensureStatus($rma, ReturnRequestStatus::Draft);

        return DB::transaction(function () use ($rma) {
            $rma->update(['status' => ReturnRequestStatus::PendingApproval]);
            try {
                $this->approvals->submit($rma, 'return_request');
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
            return $rma->fresh();
        });
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
        $this->ensureStatus($rma, ReturnRequestStatus::PendingApproval);

        return DB::transaction(function () use ($rma, $by, $remarks) {
            try {
                $this->approvals->approve($rma, $by, $remarks);
            } catch (\Throwable $e) {
                // Swallowing this returned 200 with an unchanged RMA, so the SPA
                // showed "RMA approved." for an approval that never happened.
                Log::warning('return_request approval approve failed', [
                    'rma_id' => $rma->id,
                    'error'  => $e->getMessage(),
                ]);

                throw $e instanceof BusinessRuleException
                    ? $e
                    : new BusinessRuleException($e->getMessage() ?: 'You cannot approve this return request.');
            }

            if ($this->approvals->isFullyApproved($rma)) {
                $rma->update([
                    'status'      => ReturnRequestStatus::Approved,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                ]);
            }

            return $rma->fresh();
        });
    }

    /**
     * Record receipt of returned goods (approved → received).
     *
     * @param array<int, numeric-string> $receivedQtys keyed by return_request_items.id
     */
    public function receive(ReturnRequest $rma, array $receivedQtys = []): ReturnRequest
    {
        $this->ensureStatus($rma, ReturnRequestStatus::Approved);

        return DB::transaction(function () use ($rma, $receivedQtys) {
            $rma->update([
                'status'      => ReturnRequestStatus::Received,
                'received_at' => now(),
            ]);

            foreach ($rma->items as $item) {
                // Default to the requested quantity so a receipt recorded without
                // per-line counts still yields a usable returned_quantity — every
                // downstream step (credit note, restock) reads that column, and it
                // previously stayed at zero because the caller keyed the map by
                // hash_id while this loop looked for the raw integer PK.
                $item->update([
                    'returned_quantity' => (string) ($receivedQtys[$item->id] ?? $item->quantity),
                ]);
            }

            return $rma->fresh()->load('items');
        });
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
        $this->ensureStatus($rma, ReturnRequestStatus::Received);

        $rma->loadMissing('items.product');

        $stage = $rma->type === ReturnRequestType::SupplierReturn
            ? InspectionStage::SupplierReturn
            : InspectionStage::CustomerReturn;

        return DB::transaction(function () use ($rma, $internalNotes, $by, $stage) {
            $rma->update([
                'status'        => ReturnRequestStatus::Inspected,
                'inspected_at'  => now(),
            ]);

            if ($internalNotes !== null) {
                $rma->update(['internal_notes' => $internalNotes]);
            }

            // Group items by product_id, then create one Inspection per product.
            $itemsByProduct = $rma->items->groupBy(fn ($i) => $i->product_id);

            $createdInspectionIds = [];

            foreach ($itemsByProduct as $productId => $productItems) {
                if (! $productId) {
                    continue; // skip items without a product — no inspection spec available
                }

                $batchQty = $productItems->sum(fn ($i) => (int) ($i->returned_quantity > 0 ? $i->returned_quantity : $i->quantity));

                try {
                    $insp = $this->inspections->create([
                        'stage'          => $stage->value,
                        'product_id'     => $productId,
                        'batch_quantity' => $batchQty,
                        'entity_type'    => InspectionEntityType::ReturnRequest->value,
                        'entity_id'      => $rma->id,
                        'notes'          => $internalNotes ?: 'Auto-created from RMA ' . $rma->rma_number,
                    ], $by ?? User::query()->find($rma->created_by));

                    $createdInspectionIds[] = $insp->id;
                } catch (\Throwable $e) {
                    Log::warning('ReturnRequestService: failed to create inspection for RMA item', [
                        'rma_id'     => $rma->id,
                        'product_id' => $productId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            // Link the first inspection to the RMA root.
            if (! empty($createdInspectionIds)) {
                $rma->update(['inspection_id' => $createdInspectionIds[0]]);
            }

            return $rma->fresh();
        });
    }

    /**
     * Dispose items on an inspected RMA (inspected → disposition_status=disposed).
     *
     * For each item, sets a disposition (scrap/rework/restock/return_to_supplier).
     * Auto-creates NCRs for scrap/rework items with a product. Auto-creates
     * a credit memo for customer returns with positive item totals.
     */
    public function dispose(
        ReturnRequest $rma,
        array $dispositions,
        User $by,
        bool $createReplacementPo = false,
    ): ReturnRequest {
        return DB::transaction(function () use ($rma, $dispositions, $by, $createReplacementPo) {
            $rma = ReturnRequest::query()->lockForUpdate()->findOrFail($rma->id);
            $this->ensureStatus($rma, ReturnRequestStatus::Inspected);
            if ($rma->disposition_status === 'disposed') {
                throw new BusinessRuleException('RMA has already been disposed.');
            }

            $rma->load(['items', 'bill.items', 'purchaseOrder.items']);

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
                // Credit only what the customer actually sent back, and only the
                // lines we kept. Summing $item->total credited the *requested*
                // quantity, and included lines routed onward to the supplier —
                // both over-credit the customer against the original invoice.
                $creditTotal = $rma->items
                    ->filter(fn ($item) => $item->disposition !== null
                        && $item->disposition !== DispositionType::ReturnToSupplier->value)
                    ->reduce(
                        fn (string $carry, $item) => bcadd($carry, $this->creditableAmount($item), 2),
                        '0',
                    );

                if (bccomp($creditTotal, '0', 2) > 0) {
                    $creditNote = $this->createCreditNote($rma, (float) $creditTotal, $by);
                    $rma->update(['credit_note_id' => $creditNote->id]);
                }
            }

            if ($rma->type === ReturnRequestType::SupplierReturn) {
                $this->processSupplierDisposition($rma, $by, $createReplacementPo);
            }

            $rma->update(['disposition_status' => 'disposed']);

            return $rma->fresh()->load(['items', 'creditNote', 'replacementPurchaseOrder']);
        });
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

        $this->recalculatePurchaseOrderReceiptStatus($rma);

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
            ], $by);
            $rma->update(['replacement_purchase_order_id' => $replacement->id]);
        }
    }

    private function recalculatePurchaseOrderReceiptStatus(ReturnRequest $rma): void
    {
        $po = $rma->purchaseOrder()->lockForUpdate()->firstOrFail();
        $ordered = (float) $po->items()->sum('quantity');
        $received = (float) $po->items()->sum('quantity_received');
        $status = $received <= 0
            ? PurchaseOrderStatus::Approved
            : ($received < $ordered ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Received);
        $po->forceFill(['status' => $status])->save();
    }

    /**
     * REC-13 — create + finalize a real customer credit note for a customer
     * return. Posts a VAT-reversing journal entry (DR Sales Revenue + VAT
     * Output, CR AR) so the subledger and GL stay reconciled. The credited
     * amount is the returned line total (VAT is added on top by the service).
     */
    private function createCreditNote(ReturnRequest $rma, float $amount, User $by): \App\Modules\Accounting\Models\CreditNote
    {
        $revenueAccountId = \App\Modules\Accounting\Models\Account::query()
            ->where('code', $this->accountPolicies->revenue())->value('id');

        $cn = $this->creditNotes->create([
            'type'              => 'customer',
            'customer_id'       => $rma->customer_id,
            'invoice_id'        => $rma->invoice_id,
            'return_request_id' => $rma->id,
            'date'              => now()->toDateString(),
            'is_vatable'        => $this->taxPolicy->isVatRegistered(),
            'reason'            => "Customer return — RMA {$rma->rma_number}",
            'lines'             => [[
                'account_id'  => $revenueAccountId,
                'description' => "Returned goods — RMA {$rma->rma_number}",
                'amount'      => number_format($amount, 2, '.', ''),
            ]],
        ], $by);

        return $this->creditNotes->finalize($cn, $by);
    }

    /**
     * Complete the RMA (inspected → completed).
     * For customer returns: adds stock back to inventory.
     * For supplier returns: removes stock (return_to_vendor movement).
     */
    public function complete(ReturnRequest $rma, User $by, ?int $locationId = null): ReturnRequest
    {
        $this->ensureStatus($rma, ReturnRequestStatus::Inspected);

        // M-36 — refuse the arbitrary first-location fallback. The caller
        // must declare which warehouse the stock movement lands in.
        if (! $locationId) {
            throw new BusinessRuleException('A warehouse location is required to complete a return.');
        }

        // Completing straight from Inspected skipped dispose() entirely, so the
        // RMA closed with no credit note, no NCR and every line restocked
        // regardless of condition — a defective batch silently re-entered
        // sellable stock and the customer was never credited.
        if ($rma->disposition_status !== 'disposed') {
            throw new BusinessRuleException(
                'Record a disposition for every returned line before completing this RMA.'
            );
        }

        DB::transaction(function () use ($rma, $by, $locationId) {
            $rma->update([
                'status'       => ReturnRequestStatus::Completed,
                'completed_by' => $by->id,
                'completed_at' => now(),
            ]);

            $rma->load('items');

            if ($rma->items->isNotEmpty()) {
                $totalMovedQty = '0';

                foreach ($rma->items as $line) {
                    // Only certain dispositions trigger inventory movement.
                    // Customer returns: restock/rework add inventory back.
                    // Supplier returns: return_to_supplier ships goods out.
                    if (! $this->shouldMove($line, $rma)) {
                        continue;
                    }

                    $itemId = $line->item_id ?? $line->product?->items()->first()?->id;
                    if (! $itemId) continue;

                    $qty = $this->settledQuantity($line);

                    if ($rma->type === ReturnRequestType::CustomerReturn) {
                        // Customer return → add stock back
                        $movement = $this->stockMovements->move(new StockMovementInput(
                            type: StockMovementType::AdjustmentIn,
                            itemId: (int) $itemId,
                            toLocationId: $locationId,
                            quantity: $qty,
                            referenceType: 'return_request',
                            referenceId: $rma->id,
                            remarks: "RMA {$rma->rma_number}: Customer return",
                            createdBy: $by->id,
                        ));
                    } else {
                        // Supplier return → remove stock
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
                    $totalMovedQty = bcadd($totalMovedQty, $qty, 3);
                }

                // Link the last stock movement to RMA root (informational)
                if (isset($movement)) {
                    $rma->update(['stock_movement_id' => $movement->id]);
                }
            }
        });

        return $rma->fresh()->load('items');
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
        if (! $rma->status->isActive()) {
            throw new BusinessRuleException("Cannot reject a {$rma->status->value} RMA.");
        }

        // Rejecting after dispose() already shipped units back to the supplier,
        // raised a debit memo and/or issued a customer credit note left those
        // documents live against a dead RMA with no reversal path.
        if ($rma->disposition_status === 'disposed') {
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
            $update['internal_notes'] = $this->appendNote($rma, "Rejected: {$reason}");
        }
        $rma->update($update);
        return $rma->fresh();
    }

    /**
     * Cancel (draft/pending_approval → cancelled).
     */
    public function cancel(ReturnRequest $rma, ?string $reason = null): ReturnRequest
    {
        if (! in_array($rma->status, [ReturnRequestStatus::Draft, ReturnRequestStatus::PendingApproval], true)) {
            throw new BusinessRuleException("Only draft or pending_approval RMA can be cancelled.");
        }
        $update = [
            'status'        => ReturnRequestStatus::Cancelled,
            'cancelled_at'  => now(),
        ];
        if ($reason) {
            $update['internal_notes'] = $this->appendNote($rma, "Cancelled: {$reason}");
        }
        $rma->update($update);
        return $rma->fresh();
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
}
