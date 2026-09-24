<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
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
        // One action matrix, shared with the service guards.
        $capabilities = SupplierPoCapabilities::forPurchaseOrder($this->resource);

        // Detail-only figures (goodsReceiptNotes is loaded only by the detail
        // endpoint). Each costs a query, which on the list page would be one
        // per row.
        $isDetail = $this->relationLoaded('goodsReceiptNotes');
        $schedulable = $isDetail && $this->relationLoaded('items')
            ? SupplierPoCapabilities::schedulableQuantities($this->resource)
            : null;
        $invoiceableGrnIds = $isDetail && $capabilities['can_submit_invoice']
            ? SupplierPoCapabilities::invoiceableReceipts($this->resource)->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : [];

        return [
            'id' => $this->hash_id,
            'po_number' => $this->po_number,
            'date' => optional($this->date)->toDateString(),
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'confirmed_delivery_date' => optional($this->confirmed_delivery_date)->toDateString(),
            'total_amount' => (string) $this->total_amount,
            'status' => (string) $this->status?->value,
            'status_label' => $this->status?->label() ?? (string) $this->status,
            'incoterm' => $this->incoterm?->value,
            'sent_to_supplier_at' => optional($this->sent_to_supplier_at)->toIso8601String(),
            'latest_response' => $this->latestResponseBlock(),
            // The API owns action availability. The SPA must not infer a
            // mutation policy from a stale status label or from hidden fields.
            'capabilities' => $capabilities,
            'shipment' => $this->whenLoaded('supplierShipment', fn () => $this->supplierShipment ? [
                'id' => $this->supplierShipment->hash_id,
                'shipped_date' => optional($this->supplierShipment->shipped_date)->toDateString(),
                'carrier' => $this->supplierShipment->carrier,
                'tracking_number' => $this->supplierShipment->tracking_number,
                'estimated_arrival' => optional($this->supplierShipment->estimated_arrival)->toDateString(),
                'notes' => $this->supplierShipment->notes,
                'updated_at' => optional($this->supplierShipment->updated_at)->toIso8601String(),
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) use ($schedulable): array {
                $ordered = (string) $item->quantity;
                $received = (string) $item->quantity_received;
                $remaining = Money::clampMin(Money::sub($ordered, $received), '0');

                return [
                    'id' => $item->hash_id,
                    'part_number' => $item->item?->code ?? '—',
                    'name' => $item->item?->name ?? $item->description,
                    'quantity_ordered' => $ordered,
                    'quantity_received' => $received,
                    'quantity_accepted' => (string) $item->quantity_accepted,
                    'quantity_remaining' => $remaining,
                    'quantity_schedulable' => $schedulable === null ? null : ($schedulable[(int) $item->id] ?? '0.00'),
                    'unit_price' => (string) $item->unit_price,
                    'total_price' => (string) $item->total,
                ];
            })->values()->all()),
            'goods_receipt_notes' => $this->whenLoaded('goodsReceiptNotes', fn () => $this->goodsReceiptNotes->map(static function ($grn) use ($invoiceableGrnIds): array {
                // The supplier invoice this receipt was billed under, if any.
                $supplierInvoiceNumber = $grn->relationLoaded('bills')
                    ? $grn->bills->first(static fn ($bill): bool => $bill->status !== BillStatus::Cancelled
                        && $bill->supplier_invoice_number !== null)?->supplier_invoice_number
                    : null;

                return [
                    'id' => $grn->hash_id,
                    'grn_number' => $grn->grn_number,
                    'received_date' => optional($grn->received_date)->toDateString(),
                    'status' => (string) $grn->status?->value,
                    'status_label' => $grn->status?->label() ?? (string) $grn->status,
                    'supplier_invoice_number' => $supplierInvoiceNumber,
                    'can_invoice' => in_array((int) $grn->id, $invoiceableGrnIds, true),
                ];
            })->values()->all()),
            'shipments' => $this->whenLoaded('supplierShipments', fn () => $this->supplierShipments->map(static fn ($shipment): array => [
                'id' => $shipment->hash_id,
                'shipped_date' => optional($shipment->shipped_date)->toDateString(),
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'estimated_arrival' => optional($shipment->estimated_arrival)->toDateString(),
                'notes' => $shipment->notes,
                'updated_at' => optional($shipment->updated_at)->toIso8601String(),
            ])->values()->all()),
            'bills' => $this->whenLoaded('bills', fn () => $this->bills->map(static fn ($bill): array => [
                'id' => $bill->hash_id,
                'bill_number' => $bill->bill_number,
                'total_amount' => (string) $bill->total_amount,
                'paid_amount' => (string) $bill->amount_paid,
                'balance' => (string) $bill->balance,
                'status' => (string) $bill->status?->value,
                'status_label' => $bill->status?->label() ?? (string) $bill->status,
                'supplier_invoice_number' => $bill->supplier_invoice_number,
                'due_date' => optional($bill->due_date)->toDateString(),
            ])->values()->all()),
        ];
    }

    /**
     * The supplier's most recent reply. Shared shape with the internal
     * PurchaseOrderResponseResource (minus the resolver/purchase_order
     * fields, which are not the supplier's business).
     *
     * @return array<string, mixed>|null
     */
    private function latestResponseBlock(): ?array
    {
        if (! $this->relationLoaded('latestResponse') || ! $this->latestResponse) {
            return null;
        }

        $response = $this->latestResponse;

        return [
            'id' => $response->hash_id,
            'type' => $response->response_type?->value ?? (string) $response->response_type,
            'status' => $response->status?->value ?? (string) $response->status,
            'proposed_delivery_date' => optional($response->proposed_delivery_date)->toDateString(),
            'notes' => $response->notes,
            'responded_at' => optional($response->responded_at)->toIso8601String(),
            'resolved_at' => optional($response->resolved_at)->toIso8601String(),
            'resolution_notes' => $response->resolution_notes,
            'items' => $response->relationLoaded('items')
                ? $response->items->map(static fn ($item): array => [
                    'purchase_order_item_id' => $item->purchase_order_item_id !== null
                        ? app('hashids')->encode((int) $item->purchase_order_item_id)
                        : null,
                    'proposed_quantity' => $item->proposed_quantity !== null ? (string) $item->proposed_quantity : null,
                    'proposed_unit_price' => $item->proposed_unit_price !== null ? (string) $item->proposed_unit_price : null,
                    'reason' => $item->reason,
                ])->values()->all()
                : [],
        ];
    }
}
