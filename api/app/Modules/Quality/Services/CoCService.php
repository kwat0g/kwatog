<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Enums\DocumentType;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Exceptions\InspectionCertificateException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Support\LotDefectCounter;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function unavailableReason(Inspection $inspection): ?string
    {
        try {
            $this->assertEligible($inspection);

            return null;
        } catch (InspectionCertificateException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Generate a CoC PDF for the given inspection. Optionally accepts a
     * delivery_number string to print on the certificate (Task 66 wiring).
     */
    public function generateForInspection(Inspection $inspection, ?string $deliveryNumber = null): StreamedResponse
    {
        $this->assertEligible($inspection);

        // M056 — the CoC number is derived from the inspection, so re-issuing
        // is the SAME certificate, not a new one. Storing fresh bytes on every
        // request left the vault holding several documents that all claim one
        // certificate number with different checksums (the payload embeds
        // `issued_at` and the requesting user), so no reader could tell which
        // copy the customer holds. Stream the certificate already on file
        // instead; that also keeps this GET endpoint free of a write.
        $existing = $this->vault->listForEntity($inspection)
            ->firstWhere('document_type', DocumentType::Coc);
        if ($existing !== null) {
            return $this->vault->streamInline($existing);
        }

        [$cocNumber, $payload] = $this->buildPayload($inspection, $deliveryNumber);

        $bytes = $this->renderer->render('pdf.coc', $payload, [
            'title' => DocumentType::Coc->label(),
        ]);
        $actor = auth()->user();
        $user = $actor instanceof User ? $actor : null;
        $document = $this->vault->store($bytes, DocumentType::Coc, $inspection, $user);

        return $this->vault->streamInline($document);
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

        $bytes = $this->renderer->render('pdf.coc', $payload, [
            'title' => DocumentType::Coc->label(),
        ]);

        return [
            'file_name' => "CoC-{$cocNumber}.pdf",
            'contents' => $bytes,
            'coc_number' => $cocNumber,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildPayload(Inspection $inspection, ?string $deliveryNumber): array
    {
        $inspection->loadMissing(['product', 'inspector', 'reviewer', 'specRevision', 'measurements']);

        // ADV3 — IATF 16949 traceability: pull batch / lot / material lot refs.
        [$batchNumber, $materialLotRefs] = $this->traceFromInspection($inspection);
        $lotNumber = $this->lotNumberForDelivery($deliveryNumber);

        // OGAMI-008 — real inspection result (no longer hard-coded "PASSED" in
        // the blade) + the critical-dimension measurement table automotive
        // customers (PPAP) expect on a CoC. We summarise per critical parameter:
        // nominal, tolerance window, the actual measured value(s), and pass/fail.
        $status = $inspection->status instanceof InspectionStatus
            ? $inspection->status
            : InspectionStatus::from((string) $inspection->status);
        $resultLabel = strtoupper($status->value);

        $criticalMeasurements = $inspection->measurements
            ->where('is_critical', true)
            ->groupBy('parameter_name')
            ->map(function ($rows) {
                $first = $rows->first();
                $values = $rows->pluck('measured_value')->filter(fn ($v) => $v !== null);
                $allPass = $rows->every(fn ($m) => $m->is_pass !== false);

                return [
                    'parameter' => $first->parameter_name,
                    'unit' => $first->unit_of_measure,
                    'nominal' => $first->nominal_value,
                    'tol_min' => $first->tolerance_min,
                    'tol_max' => $first->tolerance_max,
                    'min_actual' => $values->min(),
                    'max_actual' => $values->max(),
                    'sample_n' => $rows->count(),
                    'pass' => $allPass,
                ];
            })
            ->values()
            ->all();

        $confirmedChecks = $inspection->usesConfirmedChecks();
        $confirmedRequirements = $confirmedChecks ? $inspection->measurements->map(fn ($row) => [
            'parameter' => $row->parameter_name,
            'criterion' => $row->acceptance_criterion,
            'nominal' => $row->nominal_value,
            'minimum' => $row->tolerance_min,
            'maximum' => $row->tolerance_max,
            'unit' => $row->unit_of_measure,
            'critical' => (bool) $row->is_critical,
        ])->all() : [];

        if ($confirmedChecks) {
            $criticalMeasurements = [];
        } elseif ($inspection->usesFullPieceCapture()) {
            $actual = InspectionPieceReading::query()->where('inspection_id', $inspection->id)
                ->selectRaw('requirement_id, MIN(measured_value) AS minimum_value, MAX(measured_value) AS maximum_value, COUNT(*) AS reading_count')
                ->groupBy('requirement_id')->get()->keyBy('requirement_id');
            $criticalMeasurements = $inspection->measurements->where('is_critical', true)
                ->filter(fn ($row) => $row->requiresSizeReading())
                ->map(fn ($row) => [
                    'parameter' => $row->parameter_name, 'unit' => $row->unit_of_measure,
                    'nominal' => $row->nominal_value, 'tol_min' => $row->tolerance_min, 'tol_max' => $row->tolerance_max,
                    'min_actual' => $actual->get($row->id)?->minimum_value,
                    'max_actual' => $actual->get($row->id)?->maximum_value,
                    'sample_n' => (int) $actual->get($row->id)?->reading_count,
                    'pass' => true,
                ])->values()->all();
        }

        $cocNumber = $this->cocNumber($inspection);
        $payload = [
            'counted_capture' => $inspection->counted_capture,
            'evidence_method' => $confirmedChecks ? 'confirmed_checks' : null,
            'measurement_disclosure' => $confirmedChecks ? 'Numeric measurements were not recorded for this inspection.' : null,
            'confirmed_requirements' => $confirmedRequirements,
            'coverage_confirmed_at' => $inspection->coverage_confirmed_at?->toISOString(),
            'capture_method' => $inspection->capture_method ?? 'measurement',
            'checklist_results' => $inspection->usesChecklistCapture() && ! $inspection->counted_capture ? $inspection->measurements->map(fn ($row) => ['name' => $row->parameter_name, 'criterion' => $row->acceptance_criterion, 'nominal' => $row->nominal_value, 'minimum' => $row->tolerance_min, 'maximum' => $row->tolerance_max, 'unit' => $row->unit_of_measure, 'measured' => $row->measured_value, 'decision' => strtoupper($row->item_result)])->all() : [],
            'source_quantity' => $inspection->source_quantity, 'source_uom_code' => $inspection->source_uom_code,
            'inspected_quantity' => $inspection->inspected_quantity, 'reviewer_name' => $inspection->reviewer?->name,
            'checklist_revision' => $inspection->specRevision?->version,
            'company' => $this->companyInfo(),
            'user' => optional(request()?->user())->name,
            'coc_number' => $cocNumber,
            'issued_at' => now()->format('M d, Y g:i A'),
            'inspection_number' => $inspection->inspection_number,
            'stage' => $inspection->stage instanceof InspectionStage ? $inspection->stage->value : $inspection->stage,
            'product_part_number' => $inspection->product?->part_number ?? '—',
            'product_name' => $inspection->product?->name ?? '—',
            'batch_quantity' => (int) $inspection->batch_quantity,
            'sample_size' => $confirmedChecks ? null : (int) $inspection->sample_size,
            'aql_code' => $confirmedChecks ? null : $inspection->aql_code,
            'defect_count' => $confirmedChecks ? (int) ($inspection->sample_defect_count ?? $inspection->defect_count) : (int) $inspection->defect_count,
            'accept_count' => (int) $inspection->accept_count,
            'inspector_name' => $inspection->inspector?->name,
            'delivery_number' => $deliveryNumber,
            // OGAMI-008 — data-driven result + measurement table.
            'inspection_result' => $resultLabel,
            'inspection_passed' => $status === InspectionStatus::Passed,
            'critical_measurements' => $confirmedChecks || ($inspection->usesChecklistCapture() && ! $inspection->counted_capture) ? [] : $criticalMeasurements,
            // ADV3 traceability fields (rendered conditionally by the blade).
            'batch_number' => $batchNumber,
            'lot_number' => $lotNumber,
            'material_lot_references' => $materialLotRefs,
            'quality_standard' => (string) $this->settings->get('landing.quality_policy.standard', ''),
            'quality_certification' => (string) $this->settings->get('landing.quality_policy.certification_title', ''),
            'aql_level' => $confirmedChecks || $inspection->usesChecklistCapture() ? '' : (string) $this->settings->get('quality.aql.default_level', ''),
            'quality_statement' => (string) $this->settings->get('landing.quality_policy.certification_body', ''),
            'quality_manager_name' => (string) $this->settings->get('quality.coc.manager_name', ''),
            'quality_manager_role' => (string) $this->settings->get('quality.coc.manager_role', ''),
        ];

        return [$cocNumber, $payload];
    }

    private function assertEligible(Inspection $inspection): void
    {
        $stage = $inspection->stage instanceof InspectionStage ? $inspection->stage : InspectionStage::from((string) $inspection->stage);
        $status = $inspection->status instanceof InspectionStatus ? $inspection->status : InspectionStatus::from((string) $inspection->status);

        if ($stage !== InspectionStage::Outgoing) {
            throw new InspectionCertificateException(
                'CoC is only issued for outgoing-stage inspections.',
                'COC_STAGE_INVALID',
            );
        }
        if ($status !== InspectionStatus::Passed) {
            throw new InspectionCertificateException(
                "CoC requires a passed inspection (current: {$status->value}).",
                'COC_INSPECTION_NOT_PASSED',
            );
        }
        if (! $inspection->isMakerChecked()) {
            throw new InspectionCertificateException(
                'CoC requires a checked outgoing inspection.',
                'COC_INSPECTION_NOT_CHECKED',
            );
        }

        $this->assertEvidenceSupportsCertificate($inspection);
        if ($inspection->work_order_output_id && ! $inspection->canReleaseOutgoing()) {
            throw new InspectionCertificateException('CoC is unavailable while the output batch is on QC hold.', 'COC_BATCH_HELD');
        }

    }

    /**
     * M056 — a Certificate of Conformance is an external, IATF-auditable
     * declaration, so `status = passed` alone is not sufficient authority to
     * issue one. The stored measurement rows are the evidence the certificate
     * asserts, and they are reachable outside the inspection state machine
     * (imports, console tasks, direct SQL, a cascade that removed rows). Any
     * of the following means the certificate would misstate its own evidence:
     *
     *   - no measurement rows at all                 → nothing was measured
     *   - an unresolved measurement or attribute row → the lot is part-inspected
     *   - a critical row with is_pass = false        → evidence contradicts the verdict
     *   - defects above the acceptance number        → ditto
     *   - fewer sampled units than `sample_size`     → per-unit mode only; a
     *     lot-checklist sample is counted, not enumerated
     *   - no reported sample_defect_count (lot-checklist only), or a toleranced
     *     critical characteristic with no reading (any mode) → the certificate's
     *     own sample claim has no evidence behind it
     *
     * Verified rather than trusted: this method re-reads the rows instead of
     * relying on `defect_count`, which is a snapshot taken at completion and
     * does not track later edits to the evidence. The defect count comes from
     * LotDefectCounter, the same implementation the completion verdict uses, so
     * a lot cannot pass on one formula and be refused a certificate on another.
     */
    private function assertEvidenceSupportsCertificate(Inspection $inspection): void
    {
        if ($inspection->usesFullPieceCapture()) {
            try {
                $evidence = app(InspectionService::class)->assertFullPieceEvidence($inspection);
            } catch (BusinessRuleException $exception) {
                throw new InspectionCertificateException($exception->getMessage(), 'COC_EVIDENCE_INCOMPLETE');
            }
            if ($evidence['defects']->isNotEmpty() || $evidence['failed_piece_numbers'] !== []
                || ($evidence['critical_defect'] ?? false) || $inspection->critical_defect
                || (int) ($inspection->sample_defect_count ?? $inspection->defect_count) !== 0) {
                $message = $inspection->usesConfirmedChecks()
                    ? 'CoC requires full confirmed coverage and zero defective pieces.'
                    : 'CoC requires zero defective pieces and all required passing size readings.';
                throw new InspectionCertificateException($message, 'COC_EVIDENCE_CONTRADICTS_VERDICT');
            }

            return;
        }
        if ($inspection->counted_capture) {
            $rows = $inspection->measurements()->get();
            if (($inspection->counted_capture['version'] ?? null) !== 1
                || ($inspection->counted_capture['result_policy'] ?? null) !== 'zero_defects'
                || ($inspection->counted_capture['required_quantity'] ?? null) !== $inspection->batch_quantity
                || ! $inspection->coverage_confirmed_at || $inspection->sample_defect_count !== 0 || $inspection->critical_defect
                || $inspection->inspected_quantity !== ($inspection->counted_capture['required_quantity'] ?? null)
                || $rows->isEmpty() || $rows->contains(fn ($row) => trim((string) $row->acceptance_criterion) === ''
                    || ($row->requiresSizeReading() && ($row->measured_value === null || $row->evaluate() !== true)))) {
                throw new InspectionCertificateException('CoC requires complete counted coverage, zero defective pieces and all required passing size readings.', 'COC_EVIDENCE_INCOMPLETE');
            }

            return;
        }
        if ($inspection->usesChecklistCapture()) {
            $checks = $inspection->measurements()->get();
            if ($checks->isEmpty()) {
                throw new InspectionCertificateException(
                    'CoC requires recorded inspection checks; this inspection has none.',
                    'COC_NO_MEASUREMENT_EVIDENCE',
                );
            }
            if ($checks->contains(fn ($row) => $row->item_result === 'failed'
                || $row->is_pass === false || $row->evaluate() === false)) {
                throw new InspectionCertificateException(
                    'CoC cannot be issued: saved checklist evidence contradicts the passed result.',
                    'COC_EVIDENCE_CONTRADICTS_VERDICT',
                );
            }
            if ($checks->contains(fn ($row) => $row->item_result !== 'passed' || trim((string) $row->acceptance_criterion) === ''
                || ($row->requiresSizeReading() && $row->measured_value === null))
                || $inspection->inspected_quantity === null || $inspection->sample_defect_count === null
                || $inspection->inspected_quantity < $inspection->sample_size || $inspection->inspected_quantity > $inspection->batch_quantity
                || (int) $inspection->sample_defect_count !== 0 || $inspection->critical_defect) {
                throw new InspectionCertificateException('CoC requires complete passing checks and attested sample coverage.', 'COC_EVIDENCE_INCOMPLETE');
            }

            return;
        }
        $rows = InspectionMeasurement::query()
            ->where('inspection_id', $inspection->getKey())
            ->get();

        if ($rows->isEmpty()) {
            throw new InspectionCertificateException(
                'CoC requires recorded inspection measurements; this inspection has none.',
                'COC_NO_MEASUREMENT_EVIDENCE',
            );
        }

        $groups = $rows->groupBy(static fn (InspectionMeasurement $row): string => $row->checklistKey());

        if ($rows->contains(static fn (InspectionMeasurement $row): bool => $row->item_result === 'failed')) {
            throw new InspectionCertificateException(
                'CoC cannot be issued: a checklist requirement was recorded as failed.',
                'COC_EVIDENCE_CONTRADICTS_VERDICT',
            );
        }

        $unresolved = $rows->filter(static function (InspectionMeasurement $row) use ($groups): bool {
            if ($row->is_pass !== null) {
                return false;
            }

            $itemResult = $groups->get($row->checklistKey())?->first()?->item_result;

            return $row->hasTolerance() || $itemResult !== 'passed';
        })->count();
        if ($unresolved > 0) {
            throw new InspectionCertificateException(
                "CoC requires every sampled measurement to be resolved; {$unresolved} have no pass/fail recorded.",
                'COC_EVIDENCE_INCOMPLETE',
            );
        }

        $counted = LotDefectCounter::for($inspection, $rows);

        if ($counted['criticalFail']) {
            throw new InspectionCertificateException(
                'CoC cannot be issued: a critical characteristic was recorded as failed.',
                'COC_EVIDENCE_CONTRADICTS_VERDICT',
            );
        }

        if ($counted['defects'] > (int) $inspection->accept_count) {
            throw new InspectionCertificateException(
                "CoC cannot be issued: the recorded evidence ({$counted['defects']} defect(s)) "
                ."exceeds the acceptance number (Ac {$inspection->accept_count}).",
                'COC_EVIDENCE_CONTRADICTS_VERDICT',
            );
        }

        if ($inspection->inspection_mode === InspectionMode::LotChecklist
            && $inspection->sample_defect_count === null) {
            throw new InspectionCertificateException(
                'CoC requires the number of defective pieces found in the sample to be recorded.',
                'COC_EVIDENCE_INCOMPLETE',
            );
        }

        // A toleranced critical characteristic is a CTQ, and IATF wants variable
        // data on it: a toleranced critical characteristic with no number is an
        // unbacked claim. The rule spans every mode — a per-unit row asserts
        // exactly what a counted-lot row asserts, and a guard whose own docblock
        // exists to catch records that did not go through the service cannot
        // depend on the service having been used. An *untoleranced* critical row
        // is a legitimate attribute record (a visual pass/fail check with no
        // number), and a non-critical characteristic may be recorded as
        // conforming by attribute — which is what the capture panel does — so
        // the rule keeps its criticality condition and counts toleranced rows.
        $unmeasured = $rows
            ->filter(static fn (InspectionMeasurement $row): bool => $row->hasTolerance() && $row->is_critical)
            ->filter(static fn (InspectionMeasurement $row): bool => $row->measured_value === null)
            ->count();

        if ($unmeasured > 0) {
            throw new InspectionCertificateException(
                "CoC requires every critical characteristic to carry a reading; {$unmeasured} row(s) have none.",
                'COC_EVIDENCE_INCOMPLETE',
            );
        }

        // Everything above is mode-independent except the counted-sample check;
        // the rules that follow judge an enumerated per-unit sample.
        if ($inspection->inspection_mode === InspectionMode::LotChecklist) {
            return;
        }

        $sampledUnits = $rows->pluck('sample_index')->unique()->count();
        $declaredSample = (int) $inspection->sample_size;
        if ($declaredSample > 0 && $sampledUnits < $declaredSample) {
            throw new InspectionCertificateException(
                "CoC declares a sample of {$declaredSample} unit(s) but only {$sampledUnits} were measured.",
                'COC_EVIDENCE_SHORT_OF_SAMPLE',
            );
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

    private function companyInfo(): array
    {
        return [
            'name' => $this->setting('company.legal_name'),
            'address' => $this->setting('company.address'),
            'phone' => $this->setting('company.phone'),
            'email' => $this->setting('company.email'),
            'tin' => $this->setting('company.tin'),
            'vat_status' => $this->setting('company.vat_status'),
            'logo_path' => $this->setting('company.logo_path'),
            'public_url' => $this->setting('company.public_url'),
            'certification' => $this->setting('company.certification'),
            'disclaimer' => $this->setting('pdf.footer_disclaimer'),
        ];
    }

    private function setting(string $key): string
    {
        try {
            $val = $this->settings->get($key);

            return is_string($val) && trim($val) !== '' ? $val : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
