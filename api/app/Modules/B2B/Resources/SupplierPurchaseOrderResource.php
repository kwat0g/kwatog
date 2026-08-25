<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier-facing purchase-order contract.
 *
 * The internal PurchaseOrderResource intentionally exposes internal approval,
 * budget, and dispatch evidence. The supplier portal gets a smaller, stable
 * DTO whose line names match the portal's display contract.
 */
class SupplierPurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'po_number'              => $this->po_number,
            'date'                   => optional($this->date)->toDateString(),
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'total_amount'           => (string) $this->total_amount,
            'status'                 => (string) $this->status?->value,
            'status_label'           => $this->status?->label() ?? (string) $this->status,
            'incoterm'               => $this->incoterm?->value,
            'sent_to_supplier_at'    => optional($this->sent_to_supplier_at)->toIso8601String(),
            // The API owns action availability. The SPA must not infer a
            // mutation policy from a stale status label or from hidden fields.
            'capabilities'           => [
                'can_acknowledge' => $this->status?->value === 'approved',
                'can_update_shipment' => in_array($this->status?->value, ['sent', 'partially_received'], true),
                'can_upload_document' => in_array($this->status?->value, ['sent', 'partially_received'], true),
                'can_submit_invoice' => in_array($this->status?->value, ['sent', 'partially_received', 'received'], true),
            ],
            'shipment'               => $this->whenLoaded('supplierShipment', fn () => $this->supplierShipment ? [
                'id' => $this->supplierShipment->hash_id,
                'shipped_date' => optional($this->supplierShipment->shipped_date)->toDateString(),
                'carrier' => $this->supplierShipment->carrier,
                'tracking_number' => $this->supplierShipment->tracking_number,
                'estimated_arrival' => optional($this->supplierShipment->estimated_arrival)->toDateString(),
                'notes' => $this->supplierShipment->notes,
                'updated_at' => optional($this->supplierShipment->updated_at)->toIso8601String(),
            ] : null),
            'items'                  => $this->whenLoaded('items', fn () => $this->items->map(static fn ($item): array => [
                'id'               => $item->hash_id,
                'part_number'      => $item->item?->code ?? '—',
                'name'             => $item->item?->name ?? $item->description,
                'quantity_ordered' => (string) $item->quantity,
                'quantity_received'=> (string) $item->quantity_received,
                'unit_price'       => (string) $item->unit_price,
                'total_price'      => (string) $item->total,
            ])->values()->all()),
            'goods_receipt_notes'    => $this->whenLoaded('goodsReceiptNotes', fn () => $this->goodsReceiptNotes->map(static fn ($grn): array => [
                'id'            => $grn->hash_id,
                'grn_number'    => $grn->grn_number,
                'received_date' => optional($grn->received_date)->toDateString(),
            ])->values()->all()),
            'bills'                  => $this->whenLoaded('bills', fn () => $this->bills->map(static fn ($bill): array => [
                'id'           => $bill->hash_id,
                'bill_number'  => $bill->bill_number,
                'total_amount' => (string) $bill->total_amount,
                'paid_amount'  => (string) $bill->amount_paid,
                'balance'      => (string) $bill->balance,
                'status'       => (string) $bill->status?->value,
                'status_label' => $bill->status?->label() ?? (string) $bill->status,
                'due_date'     => optional($bill->due_date)->toDateString(),
            ])->values()->all()),
        ];
    }

}
