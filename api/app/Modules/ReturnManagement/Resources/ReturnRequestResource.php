<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->hash_id,
            'rma_number'           => $this->rma_number,
            'type'                 => $this->type?->value,
            'type_label'           => $this->type?->label(),
            'status'               => $this->status?->value,
            'status_label'         => $this->status?->label(),
            'is_editable'          => $this->is_editable,

            'reason_code'          => $this->reason_code,
            'reason_description'   => $this->reason_description,
            'customer_notes'       => $this->customer_notes,
            'internal_notes'       => $this->internal_notes,
            'resolution'           => $this->resolution,
            'refund_amount'        => $this->refund_amount ? (string) $this->refund_amount : null,
            'return_date'          => optional($this->return_date)->toDateString(),

            'source_label'         => $this->source_label,

            'sales_order'          => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'        => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),

            'invoice'              => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->hash_id,
                'invoice_number' => $this->invoice->invoice_number,
            ] : null),

            'purchase_order'       => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),

            'bill'                 => $this->whenLoaded('bill', fn () => $this->bill ? [
                'id'          => $this->bill->hash_id,
                'bill_number' => $this->bill->bill_number,
            ] : null),

            'customer'             => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),

            'vendor'               => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ] : null),

            'credit_note'          => $this->whenLoaded('creditNote', fn () => $this->creditNote ? [
                'id'             => $this->creditNote->hash_id,
                'invoice_number' => $this->creditNote->invoice_number,
            ] : null),

            'debit_note'           => $this->whenLoaded('debitNote', fn () => $this->debitNote ? [
                'id'          => $this->debitNote->hash_id,
                'bill_number' => $this->debitNote->bill_number,
            ] : null),

            'items'                => $this->whenLoaded('items', fn () =>
                ReturnRequestItemResource::collection($this->items)
            ),

            'item_count'           => (int) ($this->items_count ?? $this->items?->count() ?? 0),

            'creator'              => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),

            'approved_by'          => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id'   => $this->approver->hash_id,
                'name' => $this->approver->name,
            ] : null),

            'approved_at'          => optional($this->approved_at)->toIso8601String(),
            'received_at'          => optional($this->received_at)->toIso8601String(),
            'inspected_at'         => optional($this->inspected_at)->toIso8601String(),
            'completed_at'         => optional($this->completed_at)->toIso8601String(),
            'rejected_at'          => optional($this->rejected_at)->toIso8601String(),
            'cancelled_at'         => optional($this->cancelled_at)->toIso8601String(),

            'created_at'           => optional($this->created_at)->toIso8601String(),
            'updated_at'           => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
