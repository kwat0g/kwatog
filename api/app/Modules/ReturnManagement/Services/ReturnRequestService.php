<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReturnRequestService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly \App\Modules\Inventory\Services\StockMovementService $stockMovements,
        private readonly ApprovalService $approvals,
        private readonly InspectionService $inspections,
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
                    ReturnRequestItem::create([
                        'return_request_id'         => $rma->id,
                        'product_id'                => $item['product_id'] ?? null,
                        'item_id'                   => $item['item_id'] ?? null,
                        'quantity'                  => $item['quantity'] ?? 0,
                        'unit_price'                => $item['unit_price'] ?? 0,
                        'total'                     => $item['total'] ?? 0,
                        'reason'                    => $item['reason'] ?? null,
                        'condition'                 => $item['condition'] ?? null,
                        'source_sales_order_item_id' => $item['source_sales_order_item_id'] ?? null,
                        'source_invoice_item_id'    => $item['source_invoice_item_id'] ?? null,
                        'source_po_item_id'         => $item['source_po_item_id'] ?? null,
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
                // L-37 — Best-effort: if the return_request workflow is
                // missing (deploy not seeded yet), don't block submission.
                \Illuminate\Support\Facades\Log::warning('return_request approval submit failed', [
                    'rma_id' => $rma->id,
                    'error'  => $e->getMessage(),
                ]);
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
                // If approval-records aren't wired (legacy data path), fall
                // through to the direct flip below.
                \Illuminate\Support\Facades\Log::warning('return_request approval approve failed', [
                    'rma_id' => $rma->id,
                    'error'  => $e->getMessage(),
                ]);
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
     */
    public function receive(ReturnRequest $rma, array $receivedQtys = []): ReturnRequest
    {
        $this->ensureStatus($rma, ReturnRequestStatus::Approved);
        $rma->update([
            'status'      => ReturnRequestStatus::Received,
            'received_at' => now(),
        ]);

        // Update per-item returned quantities
        if (! empty($receivedQtys)) {
            foreach ($rma->items as $item) {
                if (isset($receivedQtys[$item->id])) {
                    $item->update(['returned_quantity' => $receivedQtys[$item->id]]);
                }
            }
        }

        return $rma->fresh()->load('items');
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
    public function dispose(ReturnRequest $rma, array $dispositions, User $by): ReturnRequest
    {
        $this->ensureStatus($rma, ReturnRequestStatus::Inspected);

        if ($rma->disposition_status === 'disposed') {
            throw new \RuntimeException('RMA has already been disposed.');
        }

        return DB::transaction(function () use ($rma, $dispositions, $by) {
            $rma->load('items');

            foreach ($rma->items as $item) {
                $disp = collect($dispositions)->firstWhere('item_id', $item->hash_id);
                if (! $disp) {
                    continue;
                }

                $item->update([
                    'disposition'       => $disp['disposition'],
                    'disposition_notes' => $disp['notes'] ?? null,
                ]);

                // Auto-NCR for quality issues (scrap or rework with a product, skip if already linked)
                if (in_array($disp['disposition'], ['scrap', 'rework'], true) && $item->product_id && !$item->ncr_id) {
                    $ncrService = app(NcrService::class);
                    $ncr = $ncrService->create([
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

            $rma->update(['disposition_status' => 'disposed']);

            // Auto credit memo for customer returns
            if ($rma->type === ReturnRequestType::CustomerReturn && $rma->invoice_id) {
                $creditTotal = $rma->items->sum(fn ($i) => (float) $i->total);
                if ($creditTotal > 0) {
                    $creditMemo = $this->createCreditMemo($rma, $creditTotal, $by);
                    $rma->update(['credit_memo_id' => $creditMemo->id]);
                }
            }

            return $rma->fresh()->load('items');
        });
    }

    /**
     * Create a credit memo (negative invoice) for a customer return.
     */
    private function createCreditMemo(ReturnRequest $rma, float $amount, User $by): \App\Modules\Accounting\Models\Invoice
    {
        return \App\Modules\Accounting\Models\Invoice::create([
            'invoice_number' => $this->sequences->generate('invoice'),
            'customer_id'    => $rma->customer_id,
            'status'         => 'finalized',
            'subtotal'       => -abs($amount),
            'vat_amount'     => -abs(round($amount * 0.12, 2)),
            'total_amount'   => -abs(round($amount * 1.12, 2)),
            'balance'        => -abs(round($amount * 1.12, 2)),
            'date'           => now()->toDateString(),
            'due_date'       => now()->toDateString(),
            'remarks'        => "Credit memo for RMA {$rma->rma_number}",
            'created_by'     => $by->id,
        ]);
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
            throw new \RuntimeException('A warehouse location is required to complete a return.');
        }

        DB::transaction(function () use ($rma, $by, $locationId) {
            $rma->update([
                'status'       => ReturnRequestStatus::Completed,
                'completed_by' => $by->id,
                'completed_at' => now(),
            ]);

            if ($rma->items->isNotEmpty()) {
                $totalMovedQty = '0';

                foreach ($rma->items as $line) {
                    $itemId = $line->item_id ?? $line->product?->items()->first()?->id;
                    if (! $itemId) continue;

                    $qty = $line->returned_quantity > 0 ? (string) $line->returned_quantity : (string) $line->quantity;

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
     * Reject (any active status → rejected).
     */
    public function reject(ReturnRequest $rma, ?string $reason = null): ReturnRequest
    {
        if (! $rma->status->isActive()) {
            throw new \RuntimeException("Cannot reject a {$rma->status->value} RMA.");
        }
        $update = [
            'status'      => ReturnRequestStatus::Rejected,
            'rejected_at' => now(),
        ];
        if ($reason) {
            $update['internal_notes'] = $reason;
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
            throw new \RuntimeException("Only draft or pending_approval RMA can be cancelled.");
        }
        $update = [
            'status'        => ReturnRequestStatus::Cancelled,
            'cancelled_at'  => now(),
        ];
        if ($reason) {
            $update['internal_notes'] = $reason;
        }
        $rma->update($update);
        return $rma->fresh();
    }

    private function ensureStatus(ReturnRequest $rma, ReturnRequestStatus $expected): void
    {
        if ($rma->status !== $expected) {
            throw new \RuntimeException(
                "Expected status {$expected->value}, got {$rma->status->value}."
            );
        }
    }
}
