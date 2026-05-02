<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Requests\CreateInspectionRequest;
use App\Modules\Quality\Requests\RecordMeasurementsRequest;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InspectionController
{
    public function __construct(private readonly InspectionService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InspectionResource::collection($this->service->list($request->query()));
    }

    public function show(Inspection $inspection): InspectionResource
    {
        return new InspectionResource($this->service->show($inspection));
    }

    public function store(CreateInspectionRequest $request): InspectionResource
    {
        $insp = $this->service->create($request->validated(), $request->user());
        return new InspectionResource($insp);
    }

    public function recordMeasurements(
        RecordMeasurementsRequest $request,
        Inspection $inspection
    ): InspectionResource {
        $rows = $request->decodedRows();
        $insp = $this->service->recordMeasurements($inspection, $rows, $request->user());
        return new InspectionResource($insp);
    }

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

    public function aqlPreview(Request $request): JsonResponse
    {
        $request->validate(['batch_quantity' => ['required', 'integer', 'min:1']]);
        $plan = \App\Modules\Quality\Services\AqlSampleSizeService::forBatch((int) $request->query('batch_quantity'));
        return response()->json(['data' => $plan]);
    }
}
