<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier-facing bill representation. Internal provenance evidence, match
 * exceptions, GL accounts, and payment journals stay on BillResource.
 */
class SupplierBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'bill_number' => $this->bill_number,
            'date' => optional($this->date)->toDateString(),
            'due_date' => optional($this->due_date)->toDateString(),
            'is_vatable' => (bool) $this->is_vatable,
            'subtotal' => (string) $this->subtotal,
            'vat_amount' => (string) $this->vat_amount,
            'total_amount' => (string) $this->total_amount,
            'amount_paid' => (string) $this->amount_paid,
            'balance' => (string) $this->balance,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_overdue' => $this->isOverdue(),
            'aging_bucket' => $this->agingBucket(),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ] : null),
            'items' => SupplierBillItemResource::collection($this->whenLoaded('items')),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(static fn ($payment): array => [
                'id' => $payment->hash_id,
                'payment_date' => optional($payment->payment_date)->toDateString(),
                'amount' => (string) $payment->amount,
                'payment_method' => $payment->payment_method?->value,
                'payment_method_label' => $payment->payment_method?->label(),
                'reference_number' => $payment->reference_number,
                'status' => $payment->status?->value,
                'status_label' => $payment->status?->label(),
            ])->values()->all()),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
