<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\ShipmentLot;

/** shortcut: follows explicit lot/work-order/shipment links only; upgrade after genealogy completeness is validated. */
final class SuspectMaterialLotTraceDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'dss.quality_suspect_material_lot';
    }

    public function domain(): string
    {
        return 'quality';
    }

    public function ownerPermissions(): array
    {
        return ['quality.inspections.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, min(200, (int) ($context['limit'] ?? 200)));
        $items = GrnItem::query()
            ->whereNotNull('material_lot_number')
            ->where('material_lot_number', '!=', '')
            ->whereHas('inspection', fn ($query) => $query
                ->where('stage', InspectionStage::Incoming->value)
                ->where('status', InspectionStatus::Failed->value))
            ->with(['inspection' => fn ($query) => $query->select([
                'inspections.id', 'inspections.grn_item_id', 'inspections.inspection_number',
                'inspections.stage', 'inspections.status', 'inspections.completed_at', 'inspections.updated_at',
            ])])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'material_lot_number']);

        $complete = $items->count() <= $limit;
        $emissions = [];
        $workOrdersScanned = 0;

        foreach ($items->take($limit) as $item) {
            $inspection = $item->inspection;
            if (! $inspection || $inspection->stage !== InspectionStage::Incoming
                || $inspection->status !== InspectionStatus::Failed) {
                continue;
            }

            $remaining = $limit - $workOrdersScanned;
            if ($remaining === 0) {
                $hasMoreWorkOrders = WorkOrder::query()
                    ->whereJsonContains('material_lot_references', [['material_lot_number' => $item->material_lot_number]])
                    ->exists();
                if ($hasMoreWorkOrders) {
                    $complete = false;
                    break;
                }

                continue;
            }

            $workOrders = WorkOrder::query()
                ->whereJsonContains('material_lot_references', [['material_lot_number' => $item->material_lot_number]])
                ->orderBy('id')
                ->limit($remaining + 1)
                ->get(['id', 'wo_number']);
            $workOrdersScanned += min($workOrders->count(), $remaining);
            $workOrdersTruncated = $workOrders->count() > $remaining;
            if ($workOrdersTruncated) {
                $complete = false;
                $workOrders = $workOrders->take($remaining);
            }
            if ($workOrders->isEmpty()) {
                continue;
            }

            $workOrderIds = $workOrders->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $shipments = ShipmentLot::query()
                ->where(function ($query) use ($workOrderIds): void {
                    foreach ($workOrderIds as $workOrderId) {
                        $query->orWhereJsonContains('work_order_ids', $workOrderId);
                    }
                })
                ->orderBy('id')
                ->limit($limit + 1)
                ->get(['id', 'lot_number']);
            $shipmentsTruncated = $shipments->count() > $limit;
            if ($shipmentsTruncated) {
                $complete = false;
                $shipments = $shipments->take($limit);
            }
            if ($shipments->isEmpty()) {
                continue;
            }

            $lotNumber = (string) $item->material_lot_number;
            $traceUrl = '/quality/traceability?term='.rawurlencode($lotNumber);
            $workOrderSamples = $workOrders->take(5)->pluck('wo_number')->values()->all();
            $shipmentSamples = $shipments->take(5)->pluck('lot_number')->values()->all();

            $emissions[] = new DetectorEmission(
                type: 'suspect_material_lot_trace',
                severity: 'warning',
                dedupeKey: 'suspect-material-lot:inspection:'.$inspection->id,
                rationale: [
                    'rule' => 'failed incoming inspection has explicit work-order and shipment-lot links',
                    'inspection_number' => (string) $inspection->inspection_number,
                    'inspection_status' => $inspection->status->value,
                    'material_lot_number' => $lotNumber,
                    'linked_work_order_count' => $workOrdersTruncated
                        ? ($remaining + 1).'+' : $workOrders->count(),
                    'linked_shipment_lot_count' => $shipmentsTruncated
                        ? ($limit + 1).'+' : $shipments->count(),
                    'work_order_samples' => $workOrderSamples,
                    'shipment_lot_samples' => $shipmentSamples,
                    'evidence_scope' => 'Explicit material-lot, work-order, and shipment-lot links found. Genealogy is not complete; this is not an exhaustive impact assessment.',
                ],
                subjectType: Inspection::class,
                subjectId: (int) $inspection->id,
                dataSufficiency: 'partial',
                action: ['route' => $traceUrl, 'label' => 'Review material lot trace'],
                ownerPermissions: ['quality.inspections.view'],
                ownerRoleSlugs: ['qc_inspector'],
                decisionCode: 'suspect_material_lot_trace',
                policyVersion: $this->version(),
                sourceReferences: [[
                    'source_kind' => 'material_lot',
                    'public_id' => (string) $item->hash_id,
                    'display_reference' => $lotNumber,
                    'destination' => '/quality/traceability',
                ]],
                sourceObservedAt: $inspection->completed_at ?? $inspection->updated_at,
            );
        }

        return new DetectorScanResult($emissions, $items->take($limit)->count(), $complete);
    }
}
