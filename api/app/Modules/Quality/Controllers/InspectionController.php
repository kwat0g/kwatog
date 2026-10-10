<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\QualityPlanSamplingMethod;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Requests\CreateInspectionRequest;
use App\Modules\Quality\Requests\RecordInspectionBatchResultRequest;
use App\Modules\Quality\Requests\RecordInspectionItemResultsRequest;
use App\Modules\Quality\Requests\RecordLotResultRequest;
use App\Modules\Quality\Requests\RecordMeasurementsRequest;
use App\Modules\Quality\Requests\ReplaceInspectionChecklistRequest;
use App\Modules\Quality\Requests\ReviewInspectionRequest;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\AqlSampleSizeService;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class InspectionController
{
    public function __construct(
        private readonly InspectionService $service,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @OA\Get(
     *     path="/quality/inspections",
     *     tags={"Inspections"},
     *     summary="List inspections",
     *     description="Returns a paginated list of quality inspections. Filterable by stage, status, and date range.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="stage", in="query", required=false, @OA\Schema(type="string", enum={"incoming","in_process","outgoing","supplier_return","customer_return"})),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","in_progress","passed","failed","cancelled"})),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Paginated inspection list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return InspectionResource::collection($this->service->list($request->query()))
            ->additional(['meta' => ['status_counts' => $this->service->statusCounts($request->query())]]);
    }

    public function options(): JsonResponse
    {
        $checklist = (bool) config('inspection.checklist_capture_enabled');

        return response()->json(['data' => [
            'stages' => array_map(
                static fn (InspectionStage $stage): array => ['value' => $stage->value, 'label' => $stage->label()],
                InspectionStage::cases(),
            ),
            'entity_types' => array_map(
                static fn (InspectionEntityType $type): array => ['value' => $type->value, 'label' => str_replace('_', ' ', ucfirst($type->value))],
                InspectionEntityType::cases(),
            ),
            'statuses' => array_map(
                static fn (InspectionStatus $status): array => ['value' => $status->value, 'label' => str_replace('_', ' ', ucfirst($status->value))],
                InspectionStatus::cases(),
            ),
            'measurement_results' => [
                ['value' => 'pass', 'label' => 'Pass'],
                ['value' => 'fail', 'label' => 'Fail'],
            ],
            'sampling_methods' => [
                ['stage' => InspectionStage::Incoming->value, 'value' => QualityPlanSamplingMethod::Full->value, 'label' => $checklist ? 'Inspect this lot or all pieces' : QualityPlanSamplingMethod::Full->label()],
                ['stage' => InspectionStage::InProcess->value, 'value' => 'sample', 'label' => 'Planned sample'],
                ['stage' => InspectionStage::Outgoing->value, 'value' => QualityPlanSamplingMethod::Aql->value, 'label' => 'AQL '.ucwords(str_replace('_', ' ', $this->settings->requiredString('quality.aql.default_level')))],
            ],
        ]]);
    }

    public function workOrderOutputs(Request $request): JsonResponse
    {
        $productId = HashIdFilter::decode($request->query('product_id'), Product::class);
        if ($productId === null) {
            return response()->json(['data' => []]);
        }

        $outputs = WorkOrderOutput::query()
            ->with('workOrder:id,wo_number,product_id')
            ->where('good_count', '>', 0)
            ->whereHas('workOrder', fn ($q) => $q->where('product_id', $productId))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $outputs->map(static fn (WorkOrderOutput $output): array => [
            'id' => $output->hash_id,
            'batch_code' => $output->batch_code,
            'good_count' => (int) $output->good_count,
            'recorded_at' => optional($output->recorded_at)?->toISOString(),
            'work_order' => $output->workOrder ? [
                'id' => $output->workOrder->hash_id,
                'wo_number' => $output->workOrder->wo_number,
            ] : null,
        ])->values()]);
    }

    /**
     * @OA\Get(
     *     path="/quality/inspections/{id}",
     *     tags={"Inspections"},
     *     summary="Show inspection detail",
     *     description="Returns full inspection details including spec, measurements, and linked NCRs.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Inspection hash ID"),
     *
     *     @OA\Response(response=200, description="Inspection detail"),
     *     @OA\Response(response=404, description="Inspection not found")
     * )
     */
    public function show(Inspection $inspection, CoCService $coc): InspectionResource
    {
        $inspection = $this->service->show($inspection);
        $inspection->setAttribute('coc_unavailable_reason', $coc->unavailableReason($inspection));

        return new InspectionResource($inspection);
    }

    public function chain(Inspection $inspection): JsonResponse
    {
        $status = $inspection->status instanceof \BackedEnum
            ? $inspection->status->value
            : (string) $inspection->status;
        $created = optional($inspection->created_at)?->toISOString();
        $completed = optional($inspection->completed_at)?->toISOString();
        $isTerminal = in_array($status, [InspectionStatus::Passed->value, InspectionStatus::Failed->value, InspectionStatus::Cancelled->value], true);

        return response()->json(['data' => array_values(array_filter([
            ['key' => 'opened', 'label' => 'Opened', 'state' => 'done', 'date' => $created],
            ['key' => 'in_progress', 'label' => 'In progress', 'state' => $isTerminal || $status === InspectionStatus::AwaitingReview->value ? 'done' : ($status === InspectionStatus::InProgress->value ? 'active' : 'pending'), 'date' => null],
            ['key' => 'review', 'label' => 'Checker review', 'state' => ! $inspection->requiresMakerChecker() ? 'skipped' : ($status === InspectionStatus::AwaitingReview->value ? 'active' : ($isTerminal && $inspection->reviewed_at ? 'done' : 'pending')), 'date' => optional($inspection->reviewed_at)?->toISOString()],
            ['key' => 'completed', 'label' => match ($status) {
                'passed' => 'Passed', 'failed' => 'Failed', default => 'Completed'
            }, 'state' => in_array($status, [InspectionStatus::Passed->value, InspectionStatus::Failed->value], true) ? 'done' : 'pending', 'date' => $completed],
            ['key' => 'cancelled', 'label' => 'Cancelled', 'state' => $status === InspectionStatus::Cancelled->value ? 'done' : 'pending', 'date' => $status === InspectionStatus::Cancelled->value ? ($completed ?? optional($inspection->updated_at)?->toISOString()) : null],
        ], fn (array $step): bool => $status === 'cancelled' ? $step['key'] !== 'completed' : $step['key'] !== 'cancelled'))]);
    }

    /**
     * @OA\Post(
     *     path="/quality/inspections",
     *     tags={"Inspections"},
     *     summary="Create a new inspection",
     *     description="Creates an inspection record with auto-generated number (QC-YYYYMM-NNNN). Links to inspection spec for tolerance evaluation.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"stage", "product_id", "batch_quantity"},
     *
     *         @OA\Property(property="stage", type="string", enum={"incoming","in_process","outgoing","supplier_return","customer_return"}),
     *         @OA\Property(property="product_id", type="string", description="Product hash ID"),
     *         @OA\Property(property="batch_quantity", type="integer", minimum=1),
     *         @OA\Property(property="entity_type", type="string", enum={"grn","work_order","delivery","return_request"}),
     *         @OA\Property(property="entity_id", type="string", description="Source entity hash ID"),
     *         @OA\Property(property="work_order_output_id", type="string", description="Required for outgoing inspections"),
     *         @OA\Property(property="notes", type="string")
     *     )),
     *
     *     @OA\Response(response=200, description="Inspection created"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(CreateInspectionRequest $request): InspectionResource
    {
        $insp = $this->service->create($request->validated(), $request->user());

        return new InspectionResource($insp);
    }

    /**
     * @OA\Patch(
     *     path="/quality/inspections/{id}/measurements",
     *     tags={"Inspections"},
     *     summary="Record inspection measurements",
     *     description="Records actual measurements for inspection parameters. Auto-evaluates pass/fail against spec tolerances.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"measurements"},
     *
     *         @OA\Property(property="measurements", type="array", @OA\Items(type="object",
     *             required={"id"},
     *             @OA\Property(property="id", type="string", description="Measurement hash ID"),
     *             @OA\Property(property="measured_value", type="number", nullable=true),
     *             @OA\Property(property="is_pass", type="boolean", nullable=true),
     *             @OA\Property(property="notes", type="string", nullable=true)
     *         ))
     *     )),
     *
     *     @OA\Response(response=200, description="Measurements recorded"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function recordMeasurements(
        RecordMeasurementsRequest $request,
        Inspection $inspection
    ): InspectionResource {
        $rows = $request->decodedRows();
        $insp = $this->service->recordMeasurements($inspection, $rows, $request->user());

        return new InspectionResource($insp);
    }

    public function recordLotResult(
        RecordLotResultRequest $request,
        Inspection $inspection
    ): InspectionResource {
        $data = $request->decoded();
        $insp = $this->service->recordLotResult($inspection, $data, $request->user());

        return new InspectionResource($insp);
    }

    public function recordBatchResult(RecordInspectionBatchResultRequest $request, Inspection $inspection): InspectionResource
    {
        if ($inspection->counted_capture) {
            return new InspectionResource($this->service->recordBatchResult($inspection, array_replace($request->validated(), [
                'coverage_confirmed' => $request->boolean('coverage_confirmed'),
            ]), $request->user()));
        }
        if ($inspection->usesChecklistCapture()) {
            return new InspectionResource($this->service->recordBatchResult($inspection, array_replace($request->validated(), [
                'expected_result_version' => $request->integer('expected_result_version'),
                'coverage_confirmed' => $request->boolean('coverage_confirmed'),
                ...($request->has('inspected_quantity') ? ['inspected_quantity' => $request->integer('inspected_quantity')] : []),
                ...($request->has('sample_defect_count') ? ['sample_defect_count' => $request->integer('sample_defect_count')] : []),
                ...($request->has('physical_defects_confirmed') ? ['physical_defects_confirmed' => $request->boolean('physical_defects_confirmed')] : []),
            ]), $request->user()));
        }

        return new InspectionResource($this->service->recordBatchResult($inspection, array_replace($request->validated(), [
            'inspected_quantity' => $request->integer('inspected_quantity'),
            'sample_defect_count' => $request->integer('sample_defect_count'),
            'critical_defect' => $request->boolean('critical_defect'),
            'defects_removed_confirmed' => $request->boolean('defects_removed_confirmed'),
            'coverage_confirmed' => $request->boolean('coverage_confirmed'),
        ]), $request->user()));
    }

    public function recordItemResults(RecordInspectionItemResultsRequest $request, Inspection $inspection): InspectionResource
    {
        return new InspectionResource($this->service->recordItemResults($inspection, array_replace($request->validated(), $inspection->usesChecklistCapture()
            ? ['expected_result_version' => $request->integer('expected_result_version')] : []), $request->user()));
    }

    public function replaceChecklist(ReplaceInspectionChecklistRequest $request, Inspection $inspection): InspectionResource
    {
        return new InspectionResource($this->service->replaceWithChecklist($inspection, $request->validated('reason'), $request->integer('expected_result_version'), $request->user()));
    }

    /**
     * @OA\Post(
     *     path="/quality/inspections/{id}/complete",
     *     tags={"Inspections"},
     *     summary="Complete an inspection",
     *     description="Finalizes the inspection. Sets overall pass/fail based on recorded measurements.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Inspection completed"),
     *     @OA\Response(response=422, description="Cannot complete — measurements incomplete or invalid state")
     * )
     */
    public function complete(Request $request, Inspection $inspection): InspectionResource
    {
        $insp = $this->service->complete($inspection, $request->user());

        return new InspectionResource($insp);
    }

    public function review(ReviewInspectionRequest $request, Inspection $inspection): InspectionResource
    {
        return new InspectionResource($this->service->review(
            $inspection,
            (string) $request->validated('decision'),
            $request->validated('remarks'),
            $request->user(),
            $request->has('expected_result_version') ? $request->integer('expected_result_version') : null,
        ));
    }

    public function cancel(Request $request, Inspection $inspection): InspectionResource
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'expected_result_version' => [$inspection->usesChecklistCapture() ? 'required' : 'sometimes', 'integer', 'min:0'],
        ]);
        $insp = $this->service->cancel($inspection, $data['reason'] ?? null, $request->user(), $request->has('expected_result_version') ? $request->integer('expected_result_version') : null);

        return new InspectionResource($insp);
    }

    /**
     * Sprint 7 Task 62 — Certificate of Conformance.
     *
     * Streams a PDF for a passed outgoing inspection. Task 66 will also
     * call CoCService directly when a delivery is created from a passed
     * batch and persist the rendered PDF to the delivery record.
     */
    public function coc(Request $request, Inspection $inspection, CoCService $coc): Response
    {
        return $coc->generateForInspection($inspection);
    }

    public function aqlPreview(Request $request): JsonResponse
    {
        $request->validate(['batch_quantity' => ['required', 'integer', 'min:1']]);
        $plan = AqlSampleSizeService::forBatch((int) $request->query('batch_quantity'));

        return response()->json(['data' => $plan]);
    }
}
