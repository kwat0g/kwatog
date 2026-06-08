<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\SettingsService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Sprint 7 — Task 62. Certificate of Conformance generator.
 *
 * Auto-generated for any outgoing-QC inspection that ended in `passed`.
 * The CoC is rendered through the standard `_layout.blade.php` so it
 * inherits Ogami letterhead / signatures styling. Task 66 will call
 * this service when a delivery is created from a passed batch and
 * attach the resulting PDF to the delivery record.
 */
class CoCService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Generate a CoC PDF for the given inspection. Optionally accepts a
     * delivery_number string to print on the certificate (Task 66 wiring).
     */
    public function generateForInspection(Inspection $inspection, ?string $deliveryNumber = null): Response
    {
        $this->assertEligible($inspection);
        [$cocNumber, $payload] = $this->buildPayload($inspection, $deliveryNumber);

        return Pdf::loadView('pdf.coc', $payload)
            ->setPaper('a4')
            ->stream("CoC-{$cocNumber}.pdf");
    }

    /**
     * M-20 — Like generateForInspection but returns the raw PDF bytes + a
     * deterministic filename, so the caller can persist the PDF (e.g. attach
     * to a Delivery as a DeliveryProof row).
     *
     * @return array{file_name: string, contents: string, coc_number: string}
     */
    public function buildBinaryForInspection(Inspection $inspection, ?string $deliveryNumber = null): array
    {
        $this->assertEligible($inspection);
        [$cocNumber, $payload] = $this->buildPayload($inspection, $deliveryNumber);

        $pdf = Pdf::loadView('pdf.coc', $payload)->setPaper('a4');
        return [
            'file_name'  => "CoC-{$cocNumber}.pdf",
            'contents'   => $pdf->output(),
            'coc_number' => $cocNumber,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildPayload(Inspection $inspection, ?string $deliveryNumber): array
    {
        $inspection->loadMissing(['product', 'inspector']);

        // ADV3 — IATF 16949 traceability: pull batch / lot / material lot refs.
        [$batchNumber, $materialLotRefs] = $this->traceFromInspection($inspection);
        $lotNumber = $this->lotNumberForDelivery($deliveryNumber);

        $cocNumber = $this->cocNumber($inspection);
        $payload = [
            'company'                 => $this->company(),
            'user'                    => optional(request()?->user())->name,
            'coc_number'              => $cocNumber,
            'issued_at'               => now()->format('M d, Y H:i'),
            'inspection_number'       => $inspection->inspection_number,
            'stage'                   => $inspection->stage instanceof InspectionStage ? $inspection->stage->value : $inspection->stage,
            'product_part_number'     => $inspection->product?->part_number ?? '—',
            'product_name'            => $inspection->product?->name ?? '—',
            'batch_quantity'          => (int) $inspection->batch_quantity,
            'sample_size'             => (int) $inspection->sample_size,
            'aql_code'                => $inspection->aql_code,
            'defect_count'            => (int) $inspection->defect_count,
            'accept_count'            => (int) $inspection->accept_count,
            'inspector_name'          => $inspection->inspector?->name,
            'delivery_number'         => $deliveryNumber,
            // ADV3 traceability fields (rendered conditionally by the blade).
            'batch_number'            => $batchNumber,
            'lot_number'              => $lotNumber,
            'material_lot_references' => $materialLotRefs,
        ];

        return [$cocNumber, $payload];
    }

    private function assertEligible(Inspection $inspection): void
    {
        $stage  = $inspection->stage instanceof InspectionStage ? $inspection->stage : InspectionStage::from((string) $inspection->stage);
        $status = $inspection->status instanceof InspectionStatus ? $inspection->status : InspectionStatus::from((string) $inspection->status);

        if ($stage !== InspectionStage::Outgoing) {
            throw new RuntimeException('CoC is only issued for outgoing-stage inspections.');
        }
        if ($status !== InspectionStatus::Passed) {
            throw new RuntimeException("CoC requires a passed inspection (current: {$status->value}).");
        }
    }

    /** Deterministic CoC number derived from the inspection. */
    private function cocNumber(Inspection $inspection): string
    {
        return 'COC-'.str_replace('QC-', '', $inspection->inspection_number);
    }

    /**
     * ADV3 — derive batch_number + material_lot_references for the inspection.
     * Outgoing-QC inspections are linked to a work_order via entity_type/entity_id.
     *
     * @return array{0: string|null, 1: array<int, array<string, mixed>>}
     */
    private function traceFromInspection(Inspection $inspection): array
    {
        if ($inspection->entity_type !== 'work_order' || ! $inspection->entity_id) {
            return [null, []];
        }
        $wo = WorkOrder::query()->find($inspection->entity_id);
        if (! $wo) {
            return [null, []];
        }
        return [
            $wo->batch_number,
            (array) ($wo->material_lot_references ?? []),
        ];
    }

    /** ADV3 — look up the most recent shipment lot for a delivery number, if any (single join). */
    private function lotNumberForDelivery(?string $deliveryNumber): ?string
    {
        if (! $deliveryNumber) {
            return null;
        }
        return ShipmentLot::query()
            ->join('deliveries', 'shipment_lots.delivery_id', '=', 'deliveries.id')
            ->where('deliveries.delivery_number', $deliveryNumber)
            ->latest('shipment_lots.id')
            ->value('shipment_lots.lot_number');
    }

    private function company(): array
    {
        return [
            'name'    => (string) $this->settings->get('company.name', 'Philippine Ogami Corporation'),
            'address' => (string) $this->settings->get('company.address', 'FCIE, Dasmariñas, Cavite, Philippines'),
            'tin'     => $this->settings->get('company.tin'),
        ];
    }
}
