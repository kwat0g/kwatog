<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Requests\CreateInspectionRequest;
use App\Modules\Quality\Requests\RecordMeasurementsRequest;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InspectionController
{
    public function __construct(private readonly InspectionService $service) {}

    /**
     * @OA\Get(
     *     path="/quality/inspections",
     *     tags={"Inspections"},
     *     summary="List inspections",
     *     description="Returns a paginated list of quality inspections. Filterable by type (incoming, in_process, outgoing), status, and date range.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string", enum={"incoming","in_process","outgoing"})),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"pending","in_progress","passed","failed","cancelled"})),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Paginated inspection list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return InspectionResource::collection($this->service->list($request->query()));
    }

    /**
     * @OA\Get(
     *     path="/quality/inspections/{id}",
     *     tags={"Inspections"},
     *     summary="Show inspection detail",
     *     description="Returns full inspection details including spec, measurements, and linked NCRs.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string"), description="Inspection hash ID"),
     *     @OA\Response(response=200, description="Inspection detail"),
     *     @OA\Response(response=404, description="Inspection not found")
     * )
     */
    public function show(Inspection $inspection): InspectionResource
    {
        return new InspectionResource($this->service->show($inspection));
    }

    /**
     * @OA\Post(
     *     path="/quality/inspections",
     *     tags={"Inspections"},
     *     summary="Create a new inspection",
     *     description="Creates an inspection record with auto-generated number (QC-YYYYMM-NNNN). Links to inspection spec for tolerance evaluation.",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"type", "inspection_spec_id", "batch_quantity"},
     *         @OA\Property(property="type", type="string", enum={"incoming","in_process","outgoing"}),
     *         @OA\Property(property="inspection_spec_id", type="string", description="Inspection spec hash ID"),
     *         @OA\Property(property="batch_quantity", type="integer", minimum=1),
     *         @OA\Property(property="work_order_id", type="string", description="Work order hash ID (for in-process/outgoing)"),
     *         @OA\Property(property="grn_id", type="string", description="GRN hash ID (for incoming)")
     *     )),
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
     * @OA\Post(
     *     path="/quality/inspections/{id}/measurements",
     *     tags={"Inspections"},
     *     summary="Record inspection measurements",
     *     description="Records actual measurements for inspection parameters. Auto-evaluates pass/fail against spec tolerances.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"rows"},
     *         @OA\Property(property="rows", type="array", @OA\Items(type="object",
     *             @OA\Property(property="parameter_id", type="string"),
     *             @OA\Property(property="actual_value", type="number"),
     *             @OA\Property(property="result", type="string", enum={"pass","fail"})
     *         ))
     *     )),
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

    /**
     * @OA\Post(
     *     path="/quality/inspections/{id}/complete",
     *     tags={"Inspections"},
     *     summary="Complete an inspection",
     *     description="Finalizes the inspection. Sets overall pass/fail based on recorded measurements.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Inspection completed"),
     *     @OA\Response(response=422, description="Cannot complete — measurements incomplete or invalid state")
     * )
     */
    public function complete(Request $request, Inspection $inspection): InspectionResource
    {
        $insp = $this->service->complete($inspection, $request->user());
        return new InspectionResource($insp);
    }

    public function cancel(Request $request, Inspection $inspection): InspectionResource
    {
        $insp = $this->service->cancel($inspection, (string) $request->input('reason'), $request->user());
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
        $plan = \App\Modules\Quality\Services\AqlSampleSizeService::forBatch((int) $request->query('batch_quantity'));
        return response()->json(['data' => $plan]);
    }
}
