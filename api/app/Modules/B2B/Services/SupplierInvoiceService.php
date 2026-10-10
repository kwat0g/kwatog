<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SystemUserResolver;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\SupplierInvoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supplier invoice submission against an accepted goods receipt.
 *
 * The supplier provides their own invoice number and date. The service resolves
 * the GRN to invoice (explicit or oldest invoiceable), and either attaches to
 * an existing draft bill (auto-created by AutoCreateBillOnGrnAccepted) or
 * creates a new one via BillService::createDraftForGrn, which correctly
 * computes due_date from vendor payment terms and is_vatable from the PO.
 */
class SupplierInvoiceService
{
    public function __construct(
        private readonly BillService $bills,
        private readonly SystemUserResolver $systemUser,
        private readonly SupplierPortalAuditRecorder $audit,
    ) {}

    /* ─── Invoice Submission ─────────────────────────────────────── */

    /**
     * Supplier submits their invoice against an accepted goods receipt.
     *
     * @return array{bill: Bill, message: string}
     */
    public function submitInvoice(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        array $data,
        ?UploadedFile $file = null,
    ): array {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        if (isset($data['bill_ids'])) {
            return $this->submitGroupedInvoice($vendorId, $portalUserId, $purchaseOrder, $data, $file);
        }

        $storedPath = null;
        try {
            $result = DB::transaction(function () use ($purchaseOrder, $data, $file, $portalUserId, $vendorId, &$storedPath) {
                // Lock PO and vendor for idempotency. A retry with the same
                // supplier invoice number must remain safe even if configuration changed.
                $lockedPurchaseOrder = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->with(['vendor:id,name,payment_terms_days'])
                    ->findOrFail($purchaseOrder->id);
                abort_if((int) $lockedPurchaseOrder->vendor_id !== $vendorId, 403);
                Vendor::query()->lockForUpdate()->findOrFail($vendorId);

                $existingInvoice = SupplierInvoice::query()
                    ->where('vendor_id', $vendorId)
                    ->where('number', $data['bill_number'])
                    ->whereNull('voided_at')
                    ->lockForUpdate()->first();
                if ($existingInvoice) {
                    $singleBill = $existingInvoice->bills()->first();
                    $sameReceipt = empty($data['goods_receipt_note_id'])
                        || (int) $singleBill?->goods_receipt_note_id === HashIdFilter::decode($data['goods_receipt_note_id'], GoodsReceiptNote::class);
                    if (! $singleBill
                        || (int) $existingInvoice->purchase_order_id !== (int) $lockedPurchaseOrder->id
                        || $existingInvoice->date?->toDateString() !== $data['date']
                        || ! $sameReceipt) {
                        throw new BusinessRuleException('This supplier invoice number already covers other receipt bills.');
                    }

                    return [
                        'bill' => $singleBill,
                        'invoice' => $existingInvoice,
                        'message' => 'Invoice was already submitted. The existing bill remains in its current review state.',
                    ];
                }

                // Idempotency check: a live bill bearing this supplier invoice
                // number for the same vendor. If so, it must be for the same PO
                // (and when a GRN was specified, the same GRN).
                $existingBill = Bill::query()
                    ->where('vendor_id', $vendorId)
                    ->where('supplier_invoice_number', $data['bill_number'])
                    ->where('status', '<>', BillStatus::Cancelled->value)
                    ->lockForUpdate()
                    ->first();
                if ($existingBill) {
                    $matches = (int) $existingBill->purchase_order_id === (int) $lockedPurchaseOrder->id;
                    if (! empty($data['goods_receipt_note_id'])) {
                        $grnId = HashIdFilter::decode($data['goods_receipt_note_id'], GoodsReceiptNote::class);
                        if ($grnId) {
                            $matches = $matches && (int) $existingBill->goods_receipt_note_id === $grnId;
                        }
                    }

                    if (! $matches) {
                        throw new BusinessRuleException(
                            "Invoice number '{$data['bill_number']}' is already used for another purchase order or receipt."
                        );
                    }

                    return [
                        'bill' => $existingBill->refresh(),
                        'message' => 'Invoice was already submitted. The existing bill remains in its current review state.',
                    ];
                }

                // Sanity: PO must support invoice submission.
                SupplierPoCapabilities::assert($lockedPurchaseOrder, 'invoice');

                // Resolve the GRN to invoice. If a hash_id was provided, decode
                // and validate it. Otherwise default to the OLDEST invoiceable receipt.
                $grn = null;
                if (! empty($data['goods_receipt_note_id'])) {
                    $grnId = HashIdFilter::decode($data['goods_receipt_note_id'], GoodsReceiptNote::class);
                    if (! $grnId) {
                        throw new BusinessRuleException('Invalid goods receipt note identifier.');
                    }
                    $grn = SupplierPoCapabilities::invoiceableReceipts($lockedPurchaseOrder)
                        ->lockForUpdate()
                        ->find($grnId);
                    if (! $grn) {
                        throw new BusinessRuleException('That receipt cannot be invoiced: it has no accepted goods left to bill.');
                    }
                } else {
                    // Default to the oldest invoiceable GRN.
                    $grn = SupplierPoCapabilities::invoiceableReceipts($lockedPurchaseOrder)
                        ->lockForUpdate()
                        ->orderBy('id')
                        ->first();
                    if (! $grn) {
                        throw new BusinessRuleException('This purchase order has no accepted goods left to bill.');
                    }
                }

                // QC acceptance already staged a bill for this receipt in most
                // cases (AutoCreateBillOnGrnAccepted). The supplier's invoice
                // belongs on THAT bill — draft or already posted — not on a
                // second one. Prefer the draft, then the newest live bill.
                $existingBill = Bill::query()
                    ->where('goods_receipt_note_id', $grn->id)
                    ->where('status', '<>', BillStatus::Cancelled->value)
                    ->whereNull('supplier_invoice_number')
                    ->whereNull('supplier_invoice_id')
                    ->where(static fn ($query) => $query
                        ->where('status', BillStatus::Draft->value)
                        ->orWhere(static fn ($posted) => $posted
                            ->where('status', BillStatus::Unpaid->value)
                            ->whereDoesntHave('payments')))
                    ->lockForUpdate()
                    ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [BillStatus::Draft->value])
                    ->orderByDesc('id')
                    ->first();

                $attached = $existingBill !== null;
                if ($attached) {
                    $bill = $existingBill;
                } else {
                    // Only when nothing was staged: createDraftForGrn rebuilds an
                    // existing draft, which would clobber AP's edits to it.
                    $bill = $this->systemUser->impersonate(
                        fn () => $this->bills->createDraftForGrn($grn, User::find($this->systemUser->id()))
                    );
                    if (! $bill) {
                        throw new BusinessRuleException('This receipt has no accepted goods left to bill.');
                    }
                }

                // Stamp the supplier invoice metadata.
                $bill->supplier_invoice_number = $data['bill_number'];
                $bill->supplier_invoice_date = $data['date'];
                $bill->supplier_invoice_submitted_at = now();
                $bill->supplier_invoice_portal_user_id = $portalUserId;

                // Append supplier remarks to the bill remarks.
                if (! empty($data['remarks'])) {
                    $bill->remarks = ($bill->remarks ?? '') . "\n\nSupplier remarks: " . $data['remarks'];
                }

                try {
                    $bill->save();
                } catch (UniqueConstraintViolationException $e) {
                    // The unique index on (vendor_id, supplier_invoice_number)
                    // fired after we confirmed the idempotency — means another
                    // concurrent request won the race.
                    if (str_contains($e->getMessage(), 'bills_vendor_supplier_invoice_unique')) {
                        throw new BusinessRuleException(
                            "Invoice number '{$data['bill_number']}' was submitted concurrently. Please retry."
                        );
                    }
                    throw $e;
                }

                $invoice = SupplierInvoice::create([
                    'vendor_id' => $vendorId,
                    'purchase_order_id' => $lockedPurchaseOrder->id,
                    'number' => $data['bill_number'],
                    'date' => $data['date'],
                    'gross_total' => (string) $bill->total_amount,
                    'submitted_at' => now(),
                    'portal_user_id' => $portalUserId,
                ]);
                $bill->supplier_invoice_id = $invoice->id;
                $bill->save();

                // Store the supplier's invoice file if provided.
                if ($file) {
                    $folder = "portal/supplier-invoices/{$bill->id}";
                    $storedPath = $file->store($folder, 'local');
                    if ($storedPath === false) {
                        throw new \RuntimeException('Unable to store the supplier invoice.');
                    }

                    $contentHash = hash_file('sha256', (string) $file->getRealPath());
                    if (! is_string($contentHash) || $contentHash === '') {
                        throw new \RuntimeException('Unable to calculate the supplier invoice fingerprint.');
                    }

                    PortalShippingDocument::create([
                        'purchase_order_id' => $lockedPurchaseOrder->id,
                        'bill_id' => $bill->id,
                        'supplier_invoice_id' => $invoice->id,
                        'document_type' => 'supplier_invoice',
                        'file_path' => $storedPath,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size_bytes' => $file->getSize(),
                        'content_sha256' => $contentHash,
                        'mime_type' => $file->getMimeType(),
                        'notes' => 'Supplier-submitted invoice for bill '.$bill->bill_number,
                        'uploaded_by' => $portalUserId,
                        'uploaded_at' => now(),
                    ]);
                }

                return [
                    'bill' => $bill,
                    'message' => $attached
                        ? "Invoice submitted successfully and attached to bill {$bill->bill_number} for goods receipt {$grn->grn_number}."
                        : 'Invoice submitted successfully. A draft bill is waiting for Accounts Payable review.',
                ];
            });
        } catch (\Throwable $e) {
            // The attachment is provisional only until the transaction commits.
            // After commit, the persisted document row owns the path; a later
            // event or audit failure must not leave that row pointing nowhere.
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }

        // Fire event and record audit only on new submissions, not idempotent retries.
        if (str_starts_with((string) $result['message'], 'Invoice submitted successfully')) {
            event(new SupplierInvoiceSubmitted($result['bill']));
            $this->audit->record('supplier_inv.submit', $result['bill'], $portalUserId, $vendorId);
        }

        return $result;
    }

    /** @return array{bill: Bill, invoice: SupplierInvoice, message: string} */
    private function submitGroupedInvoice(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        array $data,
        ?UploadedFile $file,
    ): array {
        $storedPath = null;
        try {
            $result = DB::transaction(function () use ($vendorId, $portalUserId, $purchaseOrder, $data, $file, &$storedPath): array {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                abort_if((int) $po->vendor_id !== $vendorId, 403);
                Vendor::query()->lockForUpdate()->findOrFail($vendorId);

                $ids = array_map(static fn (string $hash): ?int => HashIdFilter::decode($hash, Bill::class), $data['bill_ids']);
                if (in_array(null, $ids, true) || count(array_unique($ids)) !== count($ids)) {
                    throw new BusinessRuleException('Select distinct valid receipt bills.');
                }
                sort($ids);
                $declaredGross = Money::round2((string) $data['gross_total']);

                $existing = SupplierInvoice::query()
                    ->where('vendor_id', $vendorId)
                    ->where('number', $data['bill_number'])
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $existingIds = $existing->bills()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                    if ((int) $existing->purchase_order_id !== (int) $po->id
                        || $existing->date?->toDateString() !== $data['date']
                        || Money::cmp((string) $existing->gross_total, $declaredGross) !== 0
                        || $existingIds !== $ids) {
                        throw new BusinessRuleException('This supplier invoice number is already used for different contents.');
                    }

                    return [
                        'bill' => $existing->bills()->orderBy('id')->firstOrFail(),
                        'invoice' => $existing,
                        'message' => 'Invoice was already submitted. The existing bills remain in their current review state.',
                    ];
                }

                SupplierPoCapabilities::assert($po, 'invoice');
                $bills = Bill::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($bills->count() !== count($ids)) {
                    throw new BusinessRuleException('One or more selected receipt bills no longer exist.');
                }
                foreach ($bills as $bill) {
                    if ((int) $bill->vendor_id !== $vendorId
                        || (int) $bill->purchase_order_id !== (int) $po->id
                        || $bill->goods_receipt_note_id === null
                        || $bill->provenance_type !== 'stock'
                        || ! ($bill->status === BillStatus::Draft
                            || ($bill->status === BillStatus::Unpaid && ! $bill->payments()->exists()))
                        || $bill->supplier_invoice_id !== null
                        || $bill->supplier_invoice_number !== null
                        || ! GoodsReceiptNote::query()->whereKey($bill->goods_receipt_note_id)
                            ->where('purchase_order_id', $po->id)
                            ->whereIn('status', GrnStatus::billableValues())->exists()) {
                        throw new BusinessRuleException('A selected receipt bill is unavailable for invoicing. Refresh the purchase order.');
                    }
                }

                $expectedGross = $bills->reduce(
                    static fn (string $sum, Bill $bill): string => Money::add($sum, (string) $bill->total_amount),
                    Money::zero(),
                );
                if (Money::cmp($expectedGross, $declaredGross) !== 0) {
                    throw new BusinessRuleException("Invoice total {$declaredGross} does not match selected accepted receipts {$expectedGross}.");
                }

                $invoice = SupplierInvoice::create([
                    'vendor_id' => $vendorId,
                    'purchase_order_id' => $po->id,
                    'number' => $data['bill_number'],
                    'date' => $data['date'],
                    'gross_total' => $declaredGross,
                    'remarks' => $data['remarks'] ?? null,
                    'submitted_at' => now(),
                    'portal_user_id' => $portalUserId,
                ]);
                foreach ($bills as $bill) {
                    $bill->supplier_invoice_id = $invoice->id;
                    $bill->save();
                }

                if ($file) {
                    $storedPath = $file->store("portal/supplier-invoices/{$invoice->id}", 'local');
                    if ($storedPath === false) {
                        throw new \RuntimeException('Unable to store the supplier invoice.');
                    }
                    $contentHash = hash_file('sha256', (string) $file->getRealPath());
                    if (! is_string($contentHash) || $contentHash === '') {
                        throw new \RuntimeException('Unable to calculate the supplier invoice fingerprint.');
                    }
                    PortalShippingDocument::create([
                        'purchase_order_id' => $po->id,
                        'supplier_invoice_id' => $invoice->id,
                        'document_type' => 'supplier_invoice',
                        'file_path' => $storedPath,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size_bytes' => $file->getSize(),
                        'content_sha256' => $contentHash,
                        'mime_type' => $file->getMimeType(),
                        'notes' => 'Supplier invoice '.$invoice->number,
                        'uploaded_by' => $portalUserId,
                        'uploaded_at' => now(),
                    ]);
                }

                return [
                    'bill' => $bills->first(),
                    'invoice' => $invoice,
                    'message' => 'Invoice submitted successfully for '.count($ids).' accepted receipt bill(s).',
                ];
            });
        } catch (\Throwable $e) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }

        if (str_starts_with($result['message'], 'Invoice submitted successfully')) {
            event(new SupplierInvoiceSubmitted($result['bill']));
            $this->audit->record('supplier_inv.submit', $result['invoice'], $portalUserId, $vendorId);
        }

        return $result;
    }
}
