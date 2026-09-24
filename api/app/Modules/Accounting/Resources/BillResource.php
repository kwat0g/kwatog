<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->hash_id,
            'bill_number'             => $this->bill_number,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'supplier_invoice_date'   => optional($this->supplier_invoice_date)->toDateString(),
            'supplier_invoice_submitted_at' => optional($this->supplier_invoice_submitted_at)->toIso8601String(),
            'date'                    => optional($this->date)->toDateString(),
            'due_date'                => optional($this->due_date)->toDateString(),
            'is_vatable'     => (bool) $this->is_vatable,
            'withholding_tax_type' => $this->withholding_tax_type?->value,
            'ewt_rate'       => (string) ($this->ewt_rate ?? '0.0000'),
            'ewt_amount'     => (string) ($this->ewt_amount ?? '0.00'),
            'subtotal'       => (string) $this->subtotal,
            'vat_amount'     => (string) $this->vat_amount,
            'total_amount'   => (string) $this->total_amount,
            'amount_paid'    => (string) $this->amount_paid,
            'balance'        => (string) $this->balance,
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'cancelled_at'   => optional($this->cancelled_at)->toIso8601String(),
            // 2026-08-08 — source receipt for auto-created draft bills.
            'goods_receipt_note_id' => $this->whenLoaded('goodsReceiptNote', fn () => $this->goodsReceiptNote ? $this->goodsReceiptNote->hash_id : null),
            'landed_cost_shipment' => $this->whenLoaded('landedCostShipment', fn () => $this->landedCostShipment ? [
                'id' => $this->landedCostShipment->hash_id,
                'shipment_number' => $this->landedCostShipment->shipment_number,
            ] : null),
            'is_overdue'     => $this->isOverdue(),
            'aging_bucket'   => $this->agingBucket(),
            'remarks'        => $this->remarks,
            'provenance_type' => $this->provenance_type,
            'exception_evidence' => $this->exception_evidence,
            'exception_owner' => $this->whenLoaded('exceptionOwner', fn () => $this->exceptionOwner ? [
                'id' => $this->exceptionOwner->hash_id,
                'name' => $this->exceptionOwner->name,
            ] : null),
            'exception_approved_by' => $this->whenLoaded('exceptionApprover', fn () => $this->exceptionApprover ? [
                'id' => $this->exceptionApprover->hash_id,
                'name' => $this->exceptionApprover->name,
            ] : null),
            'exception_approved_at' => optional($this->exception_approved_at)->toIso8601String(),
            'has_variances'  => (bool) $this->has_variances,
            'three_way_overridden' => (bool) $this->three_way_overridden,
            'three_way_override_reason' => $this->three_way_override_reason,
            'three_way_overridden_by' => $this->whenLoaded('threeWayOverrider', fn () => $this->threeWayOverrider ? [
                'id' => $this->threeWayOverrider->hash_id,
                'name' => $this->threeWayOverrider->name,
            ] : null),
            'three_way_overridden_at' => optional($this->three_way_overridden_at)->toIso8601String(),
            'three_way_review_status' => $this->threeWayReviewStatus(),
            'three_way_match_url' => $this->purchase_order_id ? '/api/v1/purchasing/three-way-match/'.$this->hash_id : null,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
                // 2026-08-08 — P2P stepper: the PR behind this PO.
                'purchase_request' => $this->purchaseOrder->relationLoaded('purchaseRequest')
                    && $this->purchaseOrder->purchaseRequest
                    ? [
                        'id'        => $this->purchaseOrder->purchaseRequest->hash_id,
                        'pr_number' => $this->purchaseOrder->purchaseRequest->pr_number,
                    ]
                    : null,
            ] : null),
            // 2026-08-08 — P2P stepper: the source receipt(s) this bill came from.
            'goods_receipt_notes' => $this->whenLoaded('goodsReceiptNote', fn () => $this->goodsReceiptNote ? [
                [
                    'id'         => $this->goodsReceiptNote->hash_id,
                    'grn_number' => $this->goodsReceiptNote->grn_number,
                    'status'     => (string) $this->goodsReceiptNote->status?->value,
                ],
            ] : []),
            'vendor'         => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->hash_id, 'name' => $this->vendor->name,
            ] : null),
            'items'          => BillItemResource::collection($this->whenLoaded('items')),
            // Supplier-submitted invoice attachment.
            'supplier_invoice_attachment' => $this->whenLoaded('portalShippingDocuments', function (): ?array {
                $document = $this->portalShippingDocuments->firstWhere('document_type', 'supplier_invoice');

                return $document ? ['id' => $document->hash_id, 'original_filename' => $document->original_filename] : null;
            }),
            // Each payment needs its bill to project a pending payment's EWT.
            'payments'       => BillPaymentResource::collection($this->whenLoaded('payments', fn () => $this->payments->each(
                fn ($payment) => $payment->setRelation('bill', $this->resource),
            ))),
            'journal_entry'  => $this->whenLoaded('journalEntry', fn () => $this->journalEntry ? [
                'id'           => $this->journalEntry->hash_id,
                'entry_number' => $this->journalEntry->entry_number,
                'status'       => $this->journalEntry->status?->value,
                'status_label' => $this->journalEntry->status?->label(),
            ] : null),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
