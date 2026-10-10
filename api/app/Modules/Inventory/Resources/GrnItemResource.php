<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $returned = ReturnRequestItem::query()->where('source_grn_item_id', $this->id)
            ->where('reversal_already_applied', false)->where('disposition', 'return_to_supplier')
            ->whereHas('returnRequest', fn ($query) => $query->where('disposition_status', 'disposed')->where('status', '!=', 'cancelled'))
            ->get()->reduce(fn ($sum, $line) => bcadd($sum, (string) ($line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0 ? $line->returned_quantity : $line->quantity), 3), '0.000');
        $lateDefects = NonConformanceReport::query()->where('is_late_incoming', true)->whereIn('status', ['open', 'in_progress'])
            ->whereHas('inspection', fn ($query) => $query->where('grn_item_id', $this->id))->get();

        return [
            'active_quality_holds' => $lateDefects->map(fn ($ncr) => ['id' => $ncr->hash_id, 'ncr_number' => $ncr->ncr_number, 'quantity' => $ncr->late_hold_quantity])->values(),
            'original_received_quantity' => bcadd((string) $this->quantity_received, $returned, 3),
            'returned_quantity' => $returned,
            'net_received_quantity' => (string) $this->quantity_received,
            'id' => $this->hash_id,
            'purchase_order_item_id' => $this->purchase_order_item_id ? app('hashids')->encode((int) $this->purchase_order_item_id) : null,
            'purchase_order_unit' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem?->unit),
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
                'quality_plan_ready' => (bool) ($this->item->has_active_quality_plan ?? false),
                'stored_bins' => $this->item->relationLoaded('stockLevels')
                    ? $this->item->stockLevels
                        ->filter(static function ($level): bool {
                            $location = $level->location;
                            $zone = $location?->zone;

                            return $location?->is_active && ! $location->is_blocked
                                && $zone?->warehouse?->is_active
                                && ! in_array($zone->zone_type, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true);
                        })
                        ->map(static fn ($level): array => [
                            'id' => $level->location->hash_id,
                            'full_code' => $level->location->full_code,
                            'quantity' => (string) $level->quantity,
                        ])->values()->all()
                    : [],
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->hash_id,
                'code' => $this->location->code,
                'full_code' => $this->location->full_code,
            ]),
            'quantity_received' => (string) $this->quantity_received,
            'quantity_received_in_uom' => $this->quantity_received_in_uom !== null
                ? (string) $this->quantity_received_in_uom
                : null,
            'received_uom_conversion_factor' => $this->received_uom_conversion_factor !== null
                ? (string) $this->received_uom_conversion_factor
                : null,
            'quantity_accepted' => (string) $this->quantity_accepted,
            'quantity_remaining' => (string) bcsub((string) $this->quantity_received, (string) $this->quantity_accepted, 3),
            // Still due on the PO line, so a draft receipt shows what to expect.
            'quantity_outstanding' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem
                ? bcsub($this->purchaseOrderItem->baseOrderedQuantity(), (string) $this->purchaseOrderItem->quantity_received, 3)
                : null),
            'unit_cost' => (string) $this->unit_cost,
            'landed_cost_unit' => $this->landed_cost_unit !== null ? (string) $this->landed_cost_unit : null,
            'landed_cost_total' => $this->landed_cost_total !== null ? (string) $this->landed_cost_total : null,
            'received_uom_code' => $this->received_uom_code,
            'lot_number' => $this->material_lot_number,
            'supplier_lot_reference' => $this->supplier_lot_reference,
            'expiry_date' => optional($this->expiry_date)->toDateString(),
            'moisture_percentage' => $this->moisture_percentage !== null ? (string) $this->moisture_percentage : null,
            'coa_document_path' => $this->coa_document_path,
            'coa_verified' => (bool) $this->coa_verified,
            'remarks' => $this->remarks,
            // Incoming QC runs per line; the GRN header only anchors the first.
            'inspection' => $this->whenLoaded('inspection', fn () => $this->inspection ? [
                'id' => $this->inspection->hash_id,
                'inspection_number' => $this->inspection->inspection_number,
                'status' => $this->inspection->status?->value,
                'status_label' => $this->inspection->status?->label(),
            ] : null),
        ];
    }
}
