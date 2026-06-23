<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\VatClassification;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use Illuminate\Support\Facades\DB;

class ReturnRequestService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly \App\Modules\Inventory\Services\StockMovementService $stockMovements,
        private readonly ApprovalService $approvals,
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
     */
    public function inspect(ReturnRequest $rma, ?string $internalNotes = null): ReturnRequest
    {
        $this->ensureStatus($rma, ReturnRequestStatus::Received);
        $update = [
            'status'        => ReturnRequestStatus::Inspected,
            'inspected_at'  => now(),
        ];
        if ($internalNotes !== null) {
            $update['internal_notes'] = $internalNotes;
        }
        $rma->update($update);
        return $rma->fresh();
    }

    /**
     * Complete the RMA (inspected → completed).
     *
     * For customer returns: adds stock back to inventory + creates a credit
     * note (draft Invoice with negative total referencing the original invoice).
     * For supplier returns: removes stock (return_to_vendor movement) + creates
     * a debit memo (unpaid Bill with negative total referencing the original bill).
     *
     * Idempotent: skips credit/debit note creation when the note already exists
     * (credit_note_id / debit_note_id is already populated).
     */
    public function complete(ReturnRequest $rma, User $by, ?int $locationId = null): ReturnRequest
    {
        $this->ensureStatus($rma, ReturnRequestStatus::Inspected);

        // M-36 — refuse the arbitrary first-location fallback. The caller
        // must declare which warehouse the stock movement lands in.
        if (! $locationId) {
            throw new \RuntimeException('A warehouse location is required to complete a return.');
        }

        $rma->loadMissing('items');

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

            // ── Credit note / debit memo creation ─────────────────────
            if ($rma->type === ReturnRequestType::CustomerReturn && ! $rma->credit_note_id) {
                $creditNote = $this->createCreditNote($rma, $by);
                $rma->update(['credit_note_id' => $creditNote->id]);
            } elseif ($rma->type === ReturnRequestType::SupplierReturn && ! $rma->debit_note_id) {
                $debitNote = $this->createDebitMemo($rma, $by);
                $rma->update(['debit_note_id' => $debitNote->id]);
            }
        });

        return $rma->fresh()->load('items');
    }

    /**
     * Create a credit note (draft Invoice with negative total) for a customer
     * return. Each returned item becomes an InvoiceItem line with its product's
     * revenue account; totals are negative to reduce AR.
     */
    private function createCreditNote(ReturnRequest $rma, User $by): Invoice
    {
        $items = $rma->items;
        if ($items->isEmpty()) {
            throw new \RuntimeException('Cannot create credit note for an RMA with no items.');
        }

        $customerId = $rma->customer_id;
        if (! $customerId) {
            throw new \RuntimeException('Cannot create credit note: RMA has no customer.');
        }

        $invoiceLines = [];
        $subtotalTotal = Money::zero();
        $vatTotal = Money::zero();

        foreach ($items as $line) {
            $qty = $line->returned_quantity > 0 ? (string) $line->returned_quantity : (string) $line->quantity;

            // Determine revenue account: product's revenue_account_id, or default Sales Revenue (4010).
            $revenueAccountId = null;
            if ($line->product_id) {
                $product = Product::query()->find($line->product_id);
                $revenueAccountId = $product?->revenue_account_id;
            }
            if (! $revenueAccountId) {
                $revenueAccountId = $this->accountId('4010');
            }

            $unitPrice = (string) ($line->unit_price > 0 ? $line->unit_price : '0');
            $lineTotal = Money::round2(bcmul($qty, $unitPrice, 4));

            $invoiceLines[] = [
                'revenue_account_id' => $revenueAccountId,
                'description'        => 'Credit note — RMA ' . $rma->rma_number
                    . ($line->reason ? ' (' . $line->reason . ')' : ''),
                'quantity'           => $qty,
                'unit_price'         => $unitPrice,
                'total'              => $lineTotal,
            ];
            $subtotalTotal = Money::add($subtotalTotal, $lineTotal);
        }

        // Credit note: negate subtotal + vat + total to reduce AR.
        $negSubtotal = Money::negate($subtotalTotal);
        $negVat      = Money::negate($vatTotal);
        $negTotal    = Money::negate(Money::add($subtotalTotal, $vatTotal));

        $invoice = Invoice::create([
            'invoice_number'     => null,  // draft — number assigned at finalize
            'customer_id'        => $customerId,
            'sales_order_id'     => $rma->sales_order_id ?? $rma->invoice?->sales_order_id ?? null,
            'date'               => now()->toDateString(),
            'due_date'           => now()->toDateString(),
            'is_vatable'         => false,
            'vat_classification' => VatClassification::VatExempt,
            'subtotal'           => $negSubtotal,
            'vat_amount'         => $negVat,
            'senior_pwd_discount'=> '0.00',
            'total_amount'       => $negTotal,
            'amount_paid'        => '0.00',
            'balance'            => $negTotal,
            'status'             => InvoiceStatus::Draft,
            'created_by'         => $by->id,
            'remarks'            => "Auto-generated credit note for RMA {$rma->rma_number}",
        ]);

        foreach ($invoiceLines as $row) {
            InvoiceItem::create([
                'invoice_id'         => $invoice->id,
                'revenue_account_id' => $row['revenue_account_id'],
                'description'        => $row['description'],
                'quantity'           => $row['quantity'],
                'unit_price'         => $row['unit_price'],
                'total'              => $row['total'],
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * Create a debit memo (Bill with negative total) for a supplier return.
     * Each returned item becomes a BillItem line; totals are negative to reduce AP.
     */
    private function createDebitMemo(ReturnRequest $rma, User $by): Bill
    {
        $items = $rma->items;
        if ($items->isEmpty()) {
            throw new \RuntimeException('Cannot create debit memo for an RMA with no items.');
        }

        $vendorId = $rma->vendor_id;
        if (! $vendorId) {
            throw new \RuntimeException('Cannot create debit memo: RMA has no vendor.');
        }

        $billLines = [];
        $subtotalTotal = Money::zero();
        $vatTotal = Money::zero();

        foreach ($items as $line) {
            $qty = $line->returned_quantity > 0 ? (string) $line->returned_quantity : (string) $line->quantity;

            // Default expense account: Direct Materials (5010) for raw items,
            // or Cost of Goods Sold (5000) as fallback.
            $expenseAccountId = $this->accountId('5000');

            $unitPrice = (string) ($line->unit_price > 0 ? $line->unit_price : '0');
            $lineTotal = Money::round2(bcmul($qty, $unitPrice, 4));

            $billLines[] = [
                'expense_account_id' => $expenseAccountId,
                'item_id'            => $line->item_id,
                'description'        => 'Debit memo — RMA ' . $rma->rma_number
                    . ($line->reason ? ' (' . $line->reason . ')' : ''),
                'quantity'           => $qty,
                'unit_price'         => $unitPrice,
                'total'              => $lineTotal,
            ];
            $subtotalTotal = Money::add($subtotalTotal, $lineTotal);
        }

        // Debit memo: negate subtotal + vat + total to reduce AP.
        $negSubtotal = Money::negate($subtotalTotal);
        $negVat      = Money::negate($vatTotal);
        $negTotal    = Money::negate(Money::add($subtotalTotal, $vatTotal));

        $bill = Bill::create([
            'bill_number'        => 'DM-' . $rma->rma_number,
            'vendor_id'          => $vendorId,
            'purchase_order_id'  => $rma->purchase_order_id,
            'date'               => now()->toDateString(),
            'due_date'           => now()->toDateString(),
            'is_vatable'         => false,
            'subtotal'           => $negSubtotal,
            'vat_amount'         => $negVat,
            'total_amount'       => $negTotal,
            'amount_paid'        => '0.00',
            'balance'            => $negTotal,
            'status'             => BillStatus::Unpaid,
            'created_by'         => $by->id,
            'remarks'            => "Auto-generated debit memo for RMA {$rma->rma_number}",
        ]);

        foreach ($billLines as $row) {
            BillItem::create([
                'bill_id'            => $bill->id,
                'expense_account_id' => $row['expense_account_id'],
                'item_id'            => $row['item_id'],
                'description'        => $row['description'],
                'quantity'           => $row['quantity'],
                'unit_price'         => $row['unit_price'],
                'total'              => $row['total'],
            ]);
        }

        return $bill->fresh();
    }

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');
        if (! $id) {
            throw new \RuntimeException("Required account {$code} not found in COA.");
        }
        return (int) $id;
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
