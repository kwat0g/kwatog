<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use App\Modules\SupplyChain\Enums\ShipmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof \BackedEnum ? $this->status : ShipmentStatus::from((string) $this->status);
        $statusValue = $status->value;
        $user = $request->user();
        $canManage = $user !== null && method_exists($user, 'hasPermission')
            && $user->hasPermission('supply_chain.shipments.manage');
        $evidenceFrozen = in_array($statusValue, ['cleared', 'received', 'cancelled'], true);
        $archiveBlocked = in_array($statusValue, ['customs', 'cleared', 'received', 'cancelled'], true);
        $acceptedReceipt = $this->relationLoaded('goodsReceiptNote')
            && in_array($this->goodsReceiptNote?->status?->value, ['accepted', 'partial_accepted'], true);

        return [
            'id' => $this->hash_id,
            'shipment_number' => $this->shipment_number,
            'status' => $statusValue,
            'status_label' => Str::headline($statusValue),
            'version' => $this->updated_at?->toISOString() ?? $this->created_at?->toISOString(),
            'permissions' => ['manage' => $canManage],
            'available_actions' => [
                'status_transitions' => $canManage
                    ? array_values(array_map(
                        static fn (ShipmentStatus $next): string => $next->value,
                        array_filter(ShipmentStatus::cases(), static fn (ShipmentStatus $next): bool => $status->canTransitionTo($next)),
                    ))
                    : [],
                'can_manage_documents' => $canManage && ! $evidenceFrozen,
                'can_manage_containers' => $canManage && ! $evidenceFrozen,
                'can_update_metadata' => $canManage && ! $evidenceFrozen,
                'can_edit_landed_costs' => $canManage && ! $acceptedReceipt,
                'can_archive' => $canManage && ! $archiveBlocked && ! $this->trashed(),
                'can_restore' => $canManage && $this->trashed() && ! $archiveBlocked,
            ],
            'carrier' => $this->carrier,
            'vessel' => $this->vessel,
            'container_number' => $this->whenLoaded('containers', function () {
                $active = $this->containers->first(fn ($container) => $container->deleted_at === null);

                return $active?->container_number ?? ($this->containers->isEmpty() ? $this->container_number : null);
            }),
            'bl_number' => $this->bl_number,
            'incoterm' => $this->incoterm?->value,
            'etd' => optional($this->etd)?->toDateString(),
            'atd' => optional($this->atd)?->toDateString(),
            'eta' => optional($this->eta)?->toDateString(),
            'ata' => optional($this->ata)?->toDateString(),
            'customs_clearance_date' => optional($this->customs_clearance_date)?->toDateString(),
            // OGAMI-104 — landed cost fields.
            'freight_cost' => $this->freight_cost,
            'insurance_cost' => $this->insurance_cost,
            'duties_amount' => $this->duties_amount,
            'brokerage_fee' => $this->brokerage_fee,
            'other_charges' => $this->other_charges,
            'landed_cost_total' => $this->landed_cost_total,
            'allocation_method' => $this->allocation_method,
            'landed_cost_calculated_at' => optional($this->landed_cost_calculated_at)?->toISOString(),
            'landed_costs' => $this->whenLoaded('landedCosts', fn () => ShipmentLandedCostResource::collection($this->landedCosts)->resolve()),
            'notes' => $this->notes,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'goods_receipt_note' => $this->whenLoaded('goodsReceiptNote', fn () => $this->goodsReceiptNote ? [
                'id' => $this->goodsReceiptNote->hash_id,
                'grn_number' => $this->goodsReceiptNote->grn_number,
                'status' => $this->goodsReceiptNote->status?->value,
                'received_date' => optional($this->goodsReceiptNote->received_date)?->toDateString(),
            ] : null),
            'documents' => $this->whenLoaded('documents', fn () => ShipmentDocumentResource::collection($this->documents)->resolve()),
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
