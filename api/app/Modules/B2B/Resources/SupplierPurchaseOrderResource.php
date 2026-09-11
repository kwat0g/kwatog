<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

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
        $status = $this->status?->value;

        // A billable goods receipt (accepted OR partially accepted) is the real
        // precondition for invoicing. Deriving the capability from status alone
        // advertised an enabled button whose click returned 422.
        $hasAcceptedReceipt = (bool) ($this->has_accepted_receipt ?? false)
            || ($this->relationLoaded('goodsReceiptNotes')
                && $this->goodsReceiptNotes->contains(
                    static fn ($grn): bool => $grn->status instanceof GrnStatus
                        ? $grn->status->isBillable()
                        : in_array((string) $grn->status, GrnStatus::billableValues(), true),
                ));
        $in = static fn (array $statuses): bool => in_array($status, $statuses, true);
        $active = ['sent', 'acknowledged', 'supplier_proposed', 'partially_received'];

        return [
            'id'                     => $this->hash_id,
            'po_number'              => $this->po_number,
            'date'                   => optional($this->date)->toDateString(),
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'confirmed_delivery_date'=> optional($this->confirmed_delivery_date)->toDateString(),
            'total_amount'           => (string) $this->total_amount,
            'status'                 => (string) $this->status?->value,
            'status_label'           => $this->status?->label() ?? (string) $this->status,
            'incoterm'               => $this->incoterm?->value,
            'sent_to_supplier_at'    => optional($this->sent_to_supplier_at)->toIso8601String(),
            'latest_response'        => $this->latestResponseBlock(),
            // The API owns action availability. The SPA must not infer a
            // mutation policy from a stale status label or from hidden fields.
            'capabilities'           => [
                // Acknowledge only a PO OGAMI has actually sent.
                'can_acknowledge' => $status === 'sent',
                // Reply (accept/propose/decline) to any PO that is still open
                // to negotiation — including after a prior response while
                // purchasing has not yet resolved it.
                'can_respond' => $in(['sent', 'acknowledged', 'supplier_proposed', 'supplier_declined']),
                'can_update_shipment' => $in($active),
                'can_upload_document' => $in($active),
                // Invoice requires an accepted receipt, matching the service guard.
                'can_submit_invoice' => $in(['sent', 'acknowledged', 'supplier_proposed', 'partially_received', 'received'])
                    && $hasAcceptedReceipt,
                'can_schedule_delivery' => $in($active),
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
            'id'                     => $response->hash_id,
            'type'                   => $response->response_type?->value ?? (string) $response->response_type,
            'status'                 => $response->status?->value ?? (string) $response->status,
            'proposed_delivery_date' => optional($response->proposed_delivery_date)->toDateString(),
            'notes'                  => $response->notes,
            'responded_at'           => optional($response->responded_at)->toIso8601String(),
            'resolved_at'            => optional($response->resolved_at)->toIso8601String(),
            'resolution_notes'       => $response->resolution_notes,
            'items'                  => $response->relationLoaded('items')
                ? $response->items->map(static fn ($item): array => [
                    'purchase_order_item_id' => $item->purchase_order_item_id !== null
                        ? app('hashids')->encode((int) $item->purchase_order_item_id)
                        : null,
                    'proposed_quantity'      => $item->proposed_quantity !== null ? (string) $item->proposed_quantity : null,
                    'proposed_unit_price'    => $item->proposed_unit_price !== null ? (string) $item->proposed_unit_price : null,
                    'reason'                 => $item->reason,
                ])->values()->all()
                : [],
        ];
    }

}
