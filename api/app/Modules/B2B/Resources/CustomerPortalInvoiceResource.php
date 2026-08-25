<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-safe invoice representation. Internal invoice resources deliberately
 * include ledger, BIR, account, and operational fields that do not belong in
 * the portal contract.
 */
class CustomerPortalInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'invoice_number' => $this->invoice_number,
            'date'           => optional($this->date)->toDateString(),
            'due_date'       => optional($this->due_date)->toDateString(),
            'subtotal'       => (string) $this->subtotal,
            'vat_amount'     => (string) $this->vat_amount,
            'total_amount'   => (string) $this->total_amount,
            'amount_paid'    => (string) $this->amount_paid,
            'balance'        => (string) $this->balance,
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'is_overdue'     => $this->isOverdue(),
            'sales_order'    => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'items'          => $this->whenLoaded('items', fn () => $this->items->map(static fn ($item): array => [
                'id'          => $item->hash_id,
                'description' => $item->description,
                'quantity'    => (string) $item->quantity,
                'unit'        => $item->unit,
                'unit_price'  => (string) $item->unit_price,
                'total'       => (string) $item->total,
            ])->values()),
            'collections'    => $this->whenLoaded('collections', fn () => $this->collections->map(static fn ($collection): array => [
                'id'               => $collection->hash_id,
                'collection_date'  => optional($collection->collection_date)->toDateString(),
                'amount'           => (string) $collection->amount,
                'payment_method'   => $collection->payment_method?->value,
                'payment_method_label' => $collection->payment_method?->label(),
                'reference_number' => $collection->reference_number,
            ])->values()),
        ];
    }
}
