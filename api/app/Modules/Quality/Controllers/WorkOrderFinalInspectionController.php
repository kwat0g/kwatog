<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Requests\ReplaceOutgoingEvidenceMethodRequest;
use App\Modules\Quality\Requests\SaveWorkOrderFinalInspectionEvidenceRequest;
use App\Modules\Quality\Requests\SubmitWorkOrderFinalInspectionRequest;
use App\Modules\Quality\Services\WorkOrderFinalInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderFinalInspectionController
{
    public function __construct(private readonly WorkOrderFinalInspectionService $service) {}

    public function replaceMethod(ReplaceOutgoingEvidenceMethodRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validated();
        return response()->json(['data' => $this->service->replaceUntouchedWithConfirmedChecks($workOrder, $data['manifest'], $data['reason'], $request->user())]);
    }

    public function show(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $page = $request->validate(['inspection_id' => ['sometimes', 'string'], 'page' => ['sometimes', 'integer', 'min:1'],
            'defect_page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        return response()->json(['data' => $this->service->show($workOrder, $request->user(), $page)]);
    }

    public function saveEvidence(SaveWorkOrderFinalInspectionEvidenceRequest $request, WorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => $this->service->saveEvidence($workOrder, $request->validated(), $request->user())]);
    }

    public function submit(SubmitWorkOrderFinalInspectionRequest $request, WorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => $this->service->submit($workOrder, $request->validated(), $request->user())]);
    }

    public function review(SubmitWorkOrderFinalInspectionRequest $request, WorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => $this->service->review($workOrder, $request->validated(), $request->user())]);
    }
}
