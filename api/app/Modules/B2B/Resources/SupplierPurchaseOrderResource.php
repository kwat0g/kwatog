<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Inventory\Enums\GrnStatus;
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
        $rejectedDispatchAllowance = $isDetail && $this->relationLoaded('items')
            ? SupplierPoCapabilities::rejectedDispatchAllowance($this->resource)
            : [];
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
            'shipment' => $this->whenLoaded('supplierShipment', fn () => $this->supplierShipment ? (new SupplierShipmentResource($this->supplierShipment))->resolve($request) : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) use ($schedulable, $rejectedDispatchAllowance): array {
                $ordered = (string) $item->quantity;
                $received = $item->purchaseQuantity((string) $item->quantity_received);
                $remaining = Money::clampMin(Money::sub($ordered, $received), '0');

                return [
                    'id' => $item->hash_id,
                    'part_number' => $item->item?->code ?? '—',
                    'name' => $item->item?->name ?? $item->description,
                    'quantity_ordered' => $ordered,
                    'quantity_dispatch_limit' => Money::add($ordered, $rejectedDispatchAllowance[(int) $item->id] ?? '0'),
                    'unit' => $item->unit,
                    'quantity_received' => $received,
                    'quantity_accepted' => $item->purchaseQuantity((string) $item->quantity_accepted),
                    'quantity_remaining' => $remaining,
                    'quantity_schedulable' => $schedulable === null ? null : ($schedulable[(int) $item->id] ?? '0.00'),
                    'unit_price' => (string) $item->unit_price,
                    'total_price' => (string) $item->total,
                ];
            })->values()->all()),
            'goods_receipt_notes' => $this->whenLoaded('goodsReceiptNotes', fn () => $this->goodsReceiptNotes->map(static function ($grn) use ($invoiceableGrnIds): array {
                // The supplier invoice this receipt was billed under, if any.
                $invoicedBill = $grn->relationLoaded('bills')
                    ? $grn->bills->first(static fn ($bill): bool => $bill->status !== BillStatus::Cancelled
                        && ($bill->supplierInvoice !== null || $bill->supplier_invoice_number !== null))
                    : null;
                $supplierInvoiceNumber = $invoicedBill?->supplierInvoice?->number ?? $invoicedBill?->supplier_invoice_number;

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
            'supplier_returns' => $this->whenLoaded('supplierReturns', fn () => $this->supplierReturns->map(static fn ($return): array => [
                'id' => $return->hash_id,
                'rma_number' => $return->rma_number,
                'status' => $return->status?->value,
                'status_label' => $return->status?->label(),
                'problem' => $return->reason_description,
                'replacement_due_date' => optional($return->replacement_due_date)->toDateString(),
                'replacement_pending' => SupplierPoCapabilities::qcReplacementPending($return),
                'replacement_purchase_order' => $return->replacementPurchaseOrder ? ['po_number' => $return->replacementPurchaseOrder->po_number] : null,
                'defect_photo_name' => $return->defect_photo_path ? $return->defect_photo_name : null,
                'items' => $return->items->map(static fn ($line): array => [
                    'part_number' => $line->item?->code,
                    'quantity' => (string) $line->quantity,
                    'unit' => $line->item?->unit_of_measure,
                ])->values()->all(),
            ])->values()->all()),
            'invoiceable_bill_portions' => $this->whenLoaded('goodsReceiptNotes', fn () => $this->goodsReceiptNotes
                ->filter(static fn ($grn): bool => in_array($grn->status, GrnStatus::billable(), true))
                ->flatMap(static fn ($grn) => $grn->bills
                    ->filter(static fn ($bill): bool => $bill->provenance_type === 'stock'
                        && ($bill->status === BillStatus::Draft
                            || ($bill->status === BillStatus::Unpaid && (int) $bill->payments_count === 0))
                        && $bill->supplier_invoice_id === null
                        && $bill->supplier_invoice_number === null)
                    ->map(static fn ($bill): array => [
                        'id' => $bill->hash_id,
                        'grn_id' => $grn->hash_id,
                        'grn_number' => $grn->grn_number,
                        'quantity' => $bill->items->reduce(
                            static fn (string $sum, $item): string => Money::add($sum, (string) $item->quantity),
                            Money::zero(),
                        ),
                        'gross_total' => (string) $bill->total_amount,
                    ]))->values()->all()),
            'shipments' => $this->whenLoaded('supplierShipments', fn () => $this->supplierShipments->map(static fn ($shipment): array => (new SupplierShipmentResource($shipment))->resolve($request))->values()->all()),
            'bills' => $this->whenLoaded('bills', fn () => $this->bills->map(static fn ($bill): array => [
                'id' => $bill->hash_id,
                'bill_number' => $bill->bill_number,
                'total_amount' => (string) $bill->total_amount,
                'paid_amount' => (string) $bill->amount_paid,
                'balance' => (string) $bill->balance,
                'status' => (string) $bill->status?->value,
                'status_label' => $bill->status?->label() ?? (string) $bill->status,
                'supplier_invoice_number' => $bill->supplierInvoice?->number ?? $bill->supplier_invoice_number,
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
