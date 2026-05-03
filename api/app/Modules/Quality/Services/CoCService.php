<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\SettingsService;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
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
        $inspection->loadMissing(['product', 'inspector']);

        $cocNumber = $this->cocNumber($inspection);
        $payload = [
            'company'             => $this->company(),
            'user'                => optional(request()?->user())->name,
            'coc_number'          => $cocNumber,
            'issued_at'           => now()->format('M d, Y H:i'),
            'inspection_number'   => $inspection->inspection_number,
            'stage'               => $inspection->stage instanceof InspectionStage ? $inspection->stage->value : $inspection->stage,
            'product_part_number' => $inspection->product?->part_number ?? '—',
            'product_name'        => $inspection->product?->name ?? '—',
            'batch_quantity'      => (int) $inspection->batch_quantity,
            'sample_size'         => (int) $inspection->sample_size,
            'aql_code'            => $inspection->aql_code,
            'defect_count'        => (int) $inspection->defect_count,
            'accept_count'        => (int) $inspection->accept_count,
            'inspector_name'      => $inspection->inspector?->name,
            'delivery_number'     => $deliveryNumber,
        ];

        return Pdf::loadView('pdf.coc', $payload)
            ->setPaper('a4')
            ->stream("CoC-{$cocNumber}.pdf");
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

    private function company(): array
    {
        return [
            'name'    => (string) $this->settings->get('company.name', 'Philippine Ogami Corporation'),
            'address' => (string) $this->settings->get('company.address', 'FCIE, Dasmariñas, Cavite, Philippines'),
            'tin'     => $this->settings->get('company.tin'),
        ];
    }
}
