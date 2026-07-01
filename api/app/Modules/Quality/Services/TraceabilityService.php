<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;

/**
 * ADV3 — IATF 16949 traceability search.
 *
 * Given a search term (batch_number, lot_number, or material_lot_number),
 * returns the entire production trace for that part:
 *
 *   Supplier → GRN → Material Lot
 *           → Work Order (Batch)
 *           → QC Inspections
 *           → Shipment Lot
 *           → Delivery → Customer
 *
 * The shape of the response is the *same* regardless of which leg you
 * search on, so the SPA can render one tree component.
 */
class TraceabilityService
{
    public function simulateRecall(string $lotNumber): array
    {
        $lotNumber = trim($lotNumber);
        if ($lotNumber === '') {
            return ['found' => false, 'lot_number' => $lotNumber, 'affected_customers' => [], 'affected_deliveries' => [], 'total_affected_qty' => 0];
        }

        $woIds = collect();

        $wo = WorkOrder::where('batch_number', $lotNumber)->first();
        if ($wo) {
            $woIds->push($wo->id);
        }

        $lot = ShipmentLot::with(['delivery', 'customer:id,name'])
            ->where('lot_number', $lotNumber)
            ->first();
        if ($lot) {
            $woIds = $woIds->merge($lot->work_order_ids ?? []);
        }

        $grnItem = GrnItem::where('material_lot_number', $lotNumber)->first();
        if ($grnItem) {
            $consumingWoIds = WorkOrder::query()
                ->whereJsonContains('material_lot_references', ['material_lot_number' => $lotNumber])
                ->pluck('id');
            $woIds = $woIds->merge($consumingWoIds);
        }

        if ($woIds->isEmpty() && !$lot) {
            return ['found' => false, 'lot_number' => $lotNumber, 'affected_customers' => [], 'affected_deliveries' => [], 'total_affected_qty' => 0];
        }

        $affectedLots = ShipmentLot::with(['delivery', 'customer:id,name'])
            ->where(function ($q) use ($woIds, $lot) {
                foreach ($woIds->unique()->all() as $woId) {
                    $q->orWhereJsonContains('work_order_ids', (int) $woId);
                }
                if ($lot) {
                    $q->orWhere('id', $lot->id);
                }
            })
            ->get();

        $deliveries = [];
        $customers = [];
        $totalQty = 0;

        foreach ($affectedLots as $sl) {
            $totalQty += (int) $sl->quantity;

            if ($sl->delivery) {
                $deliveries[$sl->delivery->id] = [
                    'id' => $sl->delivery->hash_id,
                    'delivery_number' => $sl->delivery->delivery_number,
                    'status' => (string) ($sl->delivery->status?->value ?? $sl->delivery->status),
                    'delivered_at' => optional($sl->delivery->delivered_at)->toIso8601String(),
                    'lot_number' => $sl->lot_number,
                    'quantity' => (int) $sl->quantity,
                ];
            }
            if ($sl->customer) {
                $customers[$sl->customer->id] = [
                    'id' => $sl->customer->hash_id,
                    'name' => $sl->customer->name,
                ];
            }
        }

        return [
            'found' => true,
            'lot_number' => $lotNumber,
            'affected_customers' => array_values($customers),
            'affected_deliveries' => array_values($deliveries),
            'total_affected_qty' => $totalQty,
        ];
    }

    /**
     * @return array{found: bool, term: string, type: string|null, trace: array}
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['found' => false, 'term' => $term, 'type' => null, 'trace' => []];
        }

        // Try batch_number (WO).
        $wo = WorkOrder::query()
            ->with(['product:id,part_number,name', 'machine:id,machine_code,name', 'mold:id,mold_code,name'])
            ->where('batch_number', $term)
            ->first();
        if ($wo) {
            return ['found' => true, 'term' => $term, 'type' => 'batch', 'trace' => $this->traceFromWorkOrder($wo)];
        }

        // Try lot_number (ShipmentLot).
        $lot = ShipmentLot::query()
            ->with(['delivery', 'customer:id,name', 'product:id,part_number,name'])
            ->where('lot_number', $term)
            ->first();
        if ($lot) {
            return ['found' => true, 'term' => $term, 'type' => 'lot', 'trace' => $this->traceFromLot($lot)];
        }

        // Try material_lot_number (GRN item).
        $grnItem = GrnItem::query()
            ->with(['grn', 'item:id,code,name'])
            ->where('material_lot_number', $term)
            ->latest('id')
            ->first();
        if ($grnItem) {
            return ['found' => true, 'term' => $term, 'type' => 'material_lot', 'trace' => $this->traceFromMaterialLot($grnItem)];
        }

        return ['found' => false, 'term' => $term, 'type' => null, 'trace' => []];
    }

    /** Build the trace tree starting from a WO (batch). */
    private function traceFromWorkOrder(WorkOrder $wo): array
    {
        return [
            'work_order' => $this->workOrderRow($wo),
            'backward'   => [
                'materials' => $this->materialsForWorkOrder($wo),
            ],
            'forward'    => [
                'inspections' => $this->inspectionsForWorkOrder($wo),
                'lots'        => $this->lotsContainingWorkOrder($wo),
            ],
        ];
    }

    /** Build the trace tree starting from a Shipment Lot. */
    private function traceFromLot(ShipmentLot $lot): array
    {
        $woIds = $lot->work_order_ids ?? [];
        $workOrders = WorkOrder::query()
            ->with(['product:id,part_number,name', 'machine:id,machine_code,name', 'mold:id,mold_code,name'])
            ->whereIn('id', $woIds)
            ->get();

        // Batch-load all inspections for all WOs in one query, grouped by entity_id.
        $inspectionsByWo = $this->batchInspectionsForWorkOrders($woIds);

        return [
            'lot' => $this->lotRow($lot),
            'backward' => [
                'work_orders' => $workOrders->map(fn (WorkOrder $wo) => [
                    'work_order' => $this->workOrderRow($wo),
                    'materials'  => $this->materialsForWorkOrder($wo),
                    'inspections'=> $inspectionsByWo[$wo->id] ?? [],
                ])->all(),
            ],
            'forward' => [
                'delivery' => $lot->delivery ? [
                    'id'              => $lot->delivery->hash_id,
                    'delivery_number' => $lot->delivery->delivery_number,
                    'status'          => (string) ($lot->delivery->status?->value ?? $lot->delivery->status),
                    'delivered_at'    => optional($lot->delivery->delivered_at)->toIso8601String(),
                    'confirmed_at'    => optional($lot->delivery->confirmed_at)->toIso8601String(),
                ] : null,
                'customer' => $lot->customer ? [
                    'id'   => $lot->customer->hash_id,
                    'name' => $lot->customer->name ?? null,
                ] : null,
            ],
        ];
    }

    /** Build the trace tree starting from a material lot (incoming GRN line). */
    private function traceFromMaterialLot(GrnItem $grnItem): array
    {
        $grn = $grnItem->grn;
        $itemId = (int) $grnItem->item_id;

        // Forward: WOs whose material_lot_references mention this lot.
        $consumingWos = WorkOrder::query()
            ->whereJsonContains('material_lot_references', ['material_lot_number' => $grnItem->material_lot_number])
            ->with(['product:id,part_number,name', 'machine:id,machine_code,name', 'mold:id,mold_code,name'])
            ->get();

        return [
            'material_lot' => [
                'grn_number'              => $grn?->grn_number,
                'received_date'           => optional($grn?->received_date)->toDateString(),
                'item_id'                 => $grnItem->item ? $grnItem->item->hash_id : null,
                'item_code'               => $grnItem->item->code ?? null,
                'item_name'               => $grnItem->item->name ?? null,
                'material_lot_number'     => $grnItem->material_lot_number,
                'supplier_lot_reference'  => $grnItem->supplier_lot_reference,
                'quantity_received'       => (string) $grnItem->quantity_received,
                'quantity_accepted'       => (string) $grnItem->quantity_accepted,
            ],
            'backward' => [
                'grn'      => $grn ? [
                    'id'            => $grn->hash_id,
                    'grn_number'    => $grn->grn_number,
                    'received_date' => optional($grn->received_date)->toDateString(),
                ] : null,
            ],
            'forward' => [
                'work_orders' => $consumingWos->map(fn (WorkOrder $wo) => $this->workOrderRow($wo))->all(),
            ],
        ];
    }

    private function workOrderRow(WorkOrder $wo): array
    {
        return [
            'id'                => $wo->hash_id,
            'wo_number'         => $wo->wo_number,
            'batch_number'      => $wo->batch_number,
            'product'           => $wo->product ? [
                'id'          => $wo->product->hash_id,
                'part_number' => $wo->product->part_number,
                'name'        => $wo->product->name,
            ] : null,
            'machine'           => $wo->machine ? [
                'id'           => $wo->machine->hash_id,
                'machine_code' => $wo->machine->machine_code,
                'name'         => $wo->machine->name,
            ] : null,
            'mold'              => $wo->mold ? [
                'id'        => $wo->mold->hash_id,
                'mold_code' => $wo->mold->mold_code,
                'name'      => $wo->mold->name,
            ] : null,
            'quantity_good'     => (int) $wo->quantity_good,
            'quantity_rejected' => (int) $wo->quantity_rejected,
            'actual_start'      => optional($wo->actual_start)->toIso8601String(),
            'actual_end'        => optional($wo->actual_end)->toIso8601String(),
            'status'            => (string) ($wo->status?->value ?? $wo->status),
        ];
    }

    private function lotRow(ShipmentLot $lot): array
    {
        return [
            'id'         => $lot->hash_id,
            'lot_number' => $lot->lot_number,
            'product'    => $lot->product ? [
                'id'          => $lot->product->hash_id,
                'part_number' => $lot->product->part_number ?? null,
                'name'        => $lot->product->name ?? null,
            ] : null,
            'quantity'   => (int) $lot->quantity,
            'lot_date'   => optional($lot->lot_date)->toDateString(),
        ];
    }

    /** @return array<int, array> */
    private function materialsForWorkOrder(WorkOrder $wo): array
    {
        return (array) ($wo->material_lot_references ?? []);
    }

    /** @return array<int, array> */
    private function inspectionsForWorkOrder(WorkOrder $wo): array
    {
        return Inspection::query()
            ->where('entity_type', 'work_order')
            ->where('entity_id', $wo->id)
            ->get(['id', 'inspection_number', 'stage', 'status', 'completed_at'])
            ->map(fn (Inspection $i) => [
                'id'                => $i->hash_id,
                'inspection_number' => $i->inspection_number,
                'stage'             => $i->stage instanceof \BackedEnum ? $i->stage->value : $i->stage,
                'status'            => $i->status instanceof \BackedEnum ? $i->status->value : $i->status,
                'completed_at'      => optional($i->completed_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Batch-load inspections for multiple WO ids in one query.
     * Returns array keyed by work_order integer id.
     *
     * @param  array<int, int>  $woIds
     * @return array<int, array<int, array>>
     */
    private function batchInspectionsForWorkOrders(array $woIds): array
    {
        if (empty($woIds)) {
            return [];
        }
        $inspections = Inspection::query()
            ->where('entity_type', 'work_order')
            ->whereIn('entity_id', $woIds)
            ->get(['id', 'inspection_number', 'stage', 'status', 'completed_at', 'entity_id']);

        $grouped = [];
        foreach ($inspections as $i) {
            $key = (int) $i->entity_id;
            $grouped[$key][] = [
                'id'                => $i->hash_id,
                'inspection_number' => $i->inspection_number,
                'stage'             => $i->stage instanceof \BackedEnum ? $i->stage->value : $i->stage,
                'status'            => $i->status instanceof \BackedEnum ? $i->status->value : $i->status,
                'completed_at'      => optional($i->completed_at)->toIso8601String(),
            ];
        }
        // Ensure every WO id has at least an empty array.
        foreach ($woIds as $id) {
            $grouped[(int) $id] ??= [];
        }
        return $grouped;
    }

    /** @return array<int, array> */
    private function lotsContainingWorkOrder(WorkOrder $wo): array
    {
        return ShipmentLot::query()
            ->whereJsonContains('work_order_ids', $wo->id)
            ->with(['delivery', 'customer:id,name'])
            ->get()
            ->map(fn (ShipmentLot $lot) => [
                'id'         => $lot->hash_id,
                'lot_number' => $lot->lot_number,
                'lot_date'   => optional($lot->lot_date)->toDateString(),
                'delivery'   => $lot->delivery ? [
                    'id'              => $lot->delivery->hash_id,
                    'delivery_number' => $lot->delivery->delivery_number,
                ] : null,
                'customer'   => $lot->customer ? [
                    'id'   => $lot->customer->hash_id,
                    'name' => $lot->customer->name ?? null,
                ] : null,
            ])
            ->all();
    }
}
